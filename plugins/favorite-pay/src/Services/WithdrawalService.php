<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
use FavoriteCMS\Pay\Contracts\WithdrawalServiceInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\Withdrawal;
use FavoriteCMS\Pay\Domain\WithdrawalStatus;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class WithdrawalService implements WithdrawalServiceInterface
{
    public const SETTING_GROUP = 'favorite_pay_withdrawals';

    public const DEFAULT_SETTINGS = [
        'enabled'         => false,
        'min_amount'      => 100.0,
        'max_amount'      => 500000.0,
        'allowed_methods' => ['bkash', 'nagad', 'rocket', 'bank_transfer'],
    ];

    private WalletServiceInterface $walletService;
    private CurrencyServiceInterface $currencyService;
    private ?Database $db;

    /**
     * In-memory storage for test/cache isolation.
     * @var array<string, Withdrawal>
     */
    private array $withdrawals = [];

    /**
     * In-memory settings override for tests.
     */
    private ?array $settingsOverride = null;

    public function __construct(
        WalletServiceInterface $walletService,
        CurrencyServiceInterface $currencyService,
        ?Database $db = null
    ) {
        $this->walletService = $walletService;
        $this->currencyService = $currencyService;
        $this->db = $db;
    }

    public function isWithdrawalEnabled(): bool
    {
        $settings = $this->getSettings();
        return !empty($settings['enabled']);
    }

    public function getSettings(): array
    {
        if ($this->settingsOverride !== null) {
            return $this->settingsOverride;
        }

        $settings = self::DEFAULT_SETTINGS;

        if (class_exists(Setting::class)) {
            try {
                $saved = Setting::getGroup(self::SETTING_GROUP);
                if (!empty($saved)) {
                    if (isset($saved['enabled'])) {
                        $settings['enabled'] = (bool)$saved['enabled'];
                    }
                    if (isset($saved['min_amount'])) {
                        $settings['min_amount'] = (float)$saved['min_amount'];
                    }
                    if (isset($saved['max_amount'])) {
                        $settings['max_amount'] = (float)$saved['max_amount'];
                    }
                    if (isset($saved['allowed_methods'])) {
                        $methods = is_string($saved['allowed_methods']) 
                            ? json_decode($saved['allowed_methods'], true) 
                            : $saved['allowed_methods'];
                        if (is_array($methods)) {
                            $settings['allowed_methods'] = $methods;
                        }
                    }
                }
            } catch (Throwable) {
            }
        }

        return $settings;
    }

    public function setSettingsOverride(?array $settings): void
    {
        $this->settingsOverride = $settings;
    }

    public function updateSettings(array $newSettings): void
    {
        $current = $this->getSettings();
        $updated = array_merge($current, $newSettings);

        $this->settingsOverride = $updated;

        if (class_exists(Setting::class)) {
            try {
                if (isset($newSettings['enabled'])) {
                    Setting::set(self::SETTING_GROUP, 'enabled', (bool)$newSettings['enabled'] ? 1 : 0, 'bool');
                }
                if (isset($newSettings['min_amount'])) {
                    Setting::set(self::SETTING_GROUP, 'min_amount', (float)$newSettings['min_amount'], 'float');
                }
                if (isset($newSettings['max_amount'])) {
                    Setting::set(self::SETTING_GROUP, 'max_amount', (float)$newSettings['max_amount'], 'float');
                }
                if (isset($newSettings['allowed_methods']) && is_array($newSettings['allowed_methods'])) {
                    Setting::set(self::SETTING_GROUP, 'allowed_methods', json_encode($newSettings['allowed_methods']), 'json');
                }
            } catch (Throwable) {
            }
        }
    }

    public function createWithdrawal(
        int $userId,
        Money $amount,
        string $method,
        array $destinationData,
        ?string $idempotencyKey = null
    ): Withdrawal {
        // 1. Feature switch enforcement: Service-level rejection
        if (!$this->isWithdrawalEnabled()) {
            throw new RuntimeException("Withdrawals are currently disabled by administrator.");
        }

        if ($userId <= 0) {
            throw new InvalidArgumentException("Invalid user ID.");
        }

        // 2. Amount validations
        if (!$amount->isPositive()) {
            throw new InvalidArgumentException("Withdrawal amount must be strictly positive.");
        }

        $primaryCurrency = $this->walletService->getPrimaryCurrency();
        if ($amount->getCurrency() !== $primaryCurrency) {
            throw new InvalidArgumentException("Withdrawals must be denominated in {$primaryCurrency}.");
        }

        $settings = $this->getSettings();
        $minMinor = (int)round(((float)($settings['min_amount'] ?? 100.0)) * 100);
        $maxMinor = (int)round(((float)($settings['max_amount'] ?? 500000.0)) * 100);

        if ($minMinor > 0 && $amount->getAmount() < $minMinor) {
            $minMajor = number_format($minMinor / 100, 2);
            throw new InvalidArgumentException("Minimum withdrawal amount is {$minMajor} {$primaryCurrency}.");
        }

        if ($maxMinor > 0 && $amount->getAmount() > $maxMinor) {
            $maxMajor = number_format($maxMinor / 100, 2);
            throw new InvalidArgumentException("Withdrawal amount exceeds maximum limit of {$maxMajor} {$primaryCurrency}.");
        }

        // 3. Method validation
        $cleanMethod = strtolower(trim($method));
        $allowed = $settings['allowed_methods'] ?? self::DEFAULT_SETTINGS['allowed_methods'];
        if (!in_array($cleanMethod, $allowed, true)) {
            throw new InvalidArgumentException("Withdrawal method '{$cleanMethod}' is not supported.");
        }

        // 4. Destination validation
        if (empty($destinationData)) {
            throw new InvalidArgumentException("Destination account details are required.");
        }

        $accountNum = trim((string)($destinationData['account_number'] ?? $destinationData['phone'] ?? $destinationData['account'] ?? $destinationData['destination'] ?? ''));
        if ($accountNum === '') {
            throw new InvalidArgumentException("Destination account number or phone number is required.");
        }

        // 5. Idempotency check
        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $existing = $this->findByIdempotencyKey(trim($idempotencyKey));
            if ($existing) {
                return $existing;
            }
        }

        $canonicalId = 'wd_' . bin2hex(random_bytes(10));
        $holdReference = 'hold:' . $canonicalId;

        // 6. Authoritative wallet hold (reserves balance without debiting permanently)
        try {
            $this->walletService->hold($userId, $amount, $holdReference);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("Insufficient available balance for user {$userId}: " . $e->getMessage(), 0, $e);
        }

        $feeFixedMajor = (float)($settings['fee_fixed'] ?? 0.0);
        $feePct = (float)($settings['fee_pct'] ?? 0.0);
        $fixedMinor = (int)round($feeFixedMajor * 100);
        $pctMinor = (int)round(($amount->getAmount() * $feePct) / 100.0);
        $totalFeeMinor = $fixedMinor + $pctMinor;
        $fee = new Money($totalFeeMinor, $primaryCurrency);
        $netAmountMinor = max(0, $amount->getAmount() - $totalFeeMinor);
        $netAmount = new Money($netAmountMinor, $primaryCurrency);

        // 7. Create Withdrawal Entity
        $withdrawal = new Withdrawal(
            $canonicalId,
            $userId,
            $amount,
            $cleanMethod,
            $destinationData,
            WithdrawalStatus::PENDING,
            $fee,
            $netAmount,
            null,
            null,
            $holdReference,
            null,
            $idempotencyKey,
            null,
            null,
            date('Y-m-d H:i:s'),
            null,
            null
        );

        $this->withdrawals[$canonicalId] = $withdrawal;

        // Persist to database if available
        if ($this->db !== null && $this->db->tableExists('favorite_pay_withdrawals')) {
            $this->db->insert('favorite_pay_withdrawals', [
                'withdrawal_id'         => $canonicalId,
                'user_id'               => $userId,
                'wallet_id'             => null,
                'amount'                => $amount->getAmount(),
                'currency'              => $primaryCurrency,
                'fee'                   => 0,
                'net_amount'            => $amount->getAmount(),
                'method'                => $cleanMethod,
                'destination_data'      => json_encode($destinationData),
                'destination_masked'    => $withdrawal->getDestinationMasked(),
                'status'                => WithdrawalStatus::PENDING->value,
                'hold_reference'        => $holdReference,
                'transaction_reference' => null,
                'idempotency_key'       => $idempotencyKey,
                'admin_user_id'         => null,
                'operator_notes'        => null,
                'created_at'            => $withdrawal->getCreatedAt(),
            ]);
        }

        if (function_exists('do_action')) {
            do_action('favorite.pay.withdrawal.created', [
                'withdrawal_id' => $canonicalId,
                'user_id'       => $userId,
                'amount'        => $amount->getAmount(),
                'currency'      => $primaryCurrency,
                'method'        => $cleanMethod,
            ]);
        }

        return $withdrawal;
    }

    public function getWithdrawal(string $id): ?Withdrawal
    {
        $trimmedId = trim($id);
        if ($trimmedId === '') {
            return null;
        }

        if (isset($this->withdrawals[$trimmedId])) {
            return $this->withdrawals[$trimmedId];
        }

        if ($this->db !== null && $this->db->tableExists('favorite_pay_withdrawals')) {
            $row = $this->db->selectOne("SELECT * FROM favorite_pay_withdrawals WHERE withdrawal_id = ? LIMIT 1", [$trimmedId]);
            if ($row) {
                $withdrawal = $this->hydrateRow($row);
                $this->withdrawals[$trimmedId] = $withdrawal;
                return $withdrawal;
            }
        }

        return null;
    }

    public function listWithdrawals(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        if ($this->db !== null && $this->db->tableExists('favorite_pay_withdrawals')) {
            $where = [];
            $bindings = [];

            if (!empty($filters['status']) && $filters['status'] !== 'all') {
                $where[] = "status = ?";
                $bindings[] = (string)$filters['status'];
            }
            if (!empty($filters['user_id'])) {
                $where[] = "user_id = ?";
                $bindings[] = (int)$filters['user_id'];
            }
            if (!empty($filters['method']) && $filters['method'] !== 'all') {
                $where[] = "method = ?";
                $bindings[] = (string)$filters['method'];
            }
            if (!empty($filters['search'])) {
                $s = '%' . trim((string)$filters['search']) . '%';
                $where[] = "(withdrawal_id LIKE ? OR destination_masked LIKE ? OR transaction_reference LIKE ?)";
                $bindings[] = $s;
                $bindings[] = $s;
                $bindings[] = $s;
            }

            $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

            $countRow = $this->db->selectOne("SELECT COUNT(*) as cnt FROM favorite_pay_withdrawals {$whereSql}", $bindings);
            $total = $countRow ? (int)$countRow->cnt : 0;

            $rows = $this->db->select("SELECT * FROM favorite_pay_withdrawals {$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}", $bindings);
            $items = array_map([$this, 'hydrateRow'], $rows);

            // Status counts
            $counts = [];
            $cntRows = $this->db->select("SELECT status, COUNT(*) as cnt FROM favorite_pay_withdrawals GROUP BY status");
            foreach ($cntRows as $cr) {
                $counts[$cr->status] = (int)$cr->cnt;
            }

            return ['items' => $items, 'total' => $total, 'counts' => $counts];
        }

        // In-memory fallback
        $list = array_values($this->withdrawals);
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $list = array_filter($list, fn($w) => $w->getStatus()->value === $filters['status']);
        }
        if (!empty($filters['user_id'])) {
            $list = array_filter($list, fn($w) => $w->getUserId() === (int)$filters['user_id']);
        }
        if (!empty($filters['method']) && $filters['method'] !== 'all') {
            $list = array_filter($list, fn($w) => $w->getMethod() === $filters['method']);
        }
        if (!empty($filters['search'])) {
            $search = strtolower(trim((string)$filters['search']));
            $list = array_filter($list, fn($w) => str_contains(strtolower($w->getId()), $search) || str_contains(strtolower($w->getDestinationMasked()), $search));
        }

        $total = count($list);
        $sliced = array_slice(array_reverse($list), $offset, $limit);

        return ['items' => $sliced, 'total' => $total, 'counts' => []];
    }

    public function getUserWithdrawals(int $userId, int $limit = 25, int $offset = 0): array
    {
        $res = $this->listWithdrawals(['user_id' => $userId], $limit, $offset);
        return $res['items'];
    }

    public function approve(string $id, int $adminUserId, ?string $notes = null): Withdrawal
    {
        $withdrawal = $this->getWithdrawal($id);
        if (!$withdrawal) {
            throw new RuntimeException("Withdrawal not found: {$id}");
        }

        if ($withdrawal->getStatus() === WithdrawalStatus::APPROVED) {
            return $withdrawal; // Idempotent
        }

        if (!$withdrawal->getStatus()->canTransitionTo(WithdrawalStatus::APPROVED)) {
            throw new RuntimeException("Cannot approve withdrawal in status '{$withdrawal->getStatus()->value}'.");
        }

        $updated = $withdrawal->withStatus(WithdrawalStatus::APPROVED, $adminUserId, $notes);
        $this->persistUpdatedWithdrawal($updated);

        if (function_exists('do_action')) {
            do_action('favorite.pay.withdrawal.approved', [
                'withdrawal_id' => $id,
                'admin_user_id' => $adminUserId,
                'notes'         => $notes,
            ]);
        }

        return $updated;
    }

    public function startProcessing(string $id, int $adminUserId, ?string $notes = null): Withdrawal
    {
        $withdrawal = $this->getWithdrawal($id);
        if (!$withdrawal) {
            throw new RuntimeException("Withdrawal not found: {$id}");
        }

        if ($withdrawal->getStatus() === WithdrawalStatus::PROCESSING) {
            return $withdrawal; // Idempotent
        }

        if (!$withdrawal->getStatus()->canTransitionTo(WithdrawalStatus::PROCESSING)) {
            throw new RuntimeException("Cannot start processing withdrawal in status '{$withdrawal->getStatus()->value}'.");
        }

        $updated = $withdrawal->withStatus(WithdrawalStatus::PROCESSING, $adminUserId, $notes);
        $this->persistUpdatedWithdrawal($updated);

        if (function_exists('do_action')) {
            do_action('favorite.pay.withdrawal.processing', [
                'withdrawal_id' => $id,
                'admin_user_id' => $adminUserId,
                'notes'         => $notes,
            ]);
        }

        return $updated;
    }

    public function markPaid(
        string $id,
        int $adminUserId,
        ?string $transactionReference = null,
        ?string $notes = null
    ): Withdrawal {
        $withdrawal = $this->getWithdrawal($id);
        if (!$withdrawal) {
            throw new RuntimeException("Withdrawal not found: {$id}");
        }

        // Idempotency: repeated markPaid calls must be safe and never double debit
        if ($withdrawal->getStatus() === WithdrawalStatus::PAID) {
            return $withdrawal;
        }

        if (!$withdrawal->getStatus()->canTransitionTo(WithdrawalStatus::PAID)) {
            throw new RuntimeException("Cannot mark withdrawal as paid in status '{$withdrawal->getStatus()->value}'.");
        }

        // Finalize hold: records permanent debit ledger entry
        $holdRef = $withdrawal->getHoldReference() ?? ('hold:' . $withdrawal->getId());
        $this->walletService->finalizeHold(
            $withdrawal->getUserId(),
            $withdrawal->getAmount(),
            $withdrawal->getId(),
            "Withdrawal payout completed ({$withdrawal->getMethod()})"
        );

        $updated = $withdrawal->withStatus(WithdrawalStatus::PAID, $adminUserId, $notes, $transactionReference);
        $this->persistUpdatedWithdrawal($updated);

        if (function_exists('do_action')) {
            do_action('favorite.pay.withdrawal.paid', [
                'withdrawal_id'         => $id,
                'user_id'               => $withdrawal->getUserId(),
                'amount'                => $withdrawal->getAmount()->getAmount(),
                'currency'              => $withdrawal->getCurrency(),
                'transaction_reference' => $transactionReference,
                'admin_user_id'         => $adminUserId,
            ]);
        }

        return $updated;
    }

    public function reject(string $id, int $adminUserId, string $reason): Withdrawal
    {
        $withdrawal = $this->getWithdrawal($id);
        if (!$withdrawal) {
            throw new RuntimeException("Withdrawal not found: {$id}");
        }

        if ($withdrawal->getStatus() === WithdrawalStatus::REJECTED) {
            return $withdrawal; // Idempotent
        }

        if (!$withdrawal->getStatus()->canTransitionTo(WithdrawalStatus::REJECTED)) {
            throw new RuntimeException("Cannot reject withdrawal in status '{$withdrawal->getStatus()->value}'.");
        }

        // Release hold back to customer's available balance
        $holdRef = $withdrawal->getHoldReference() ?? ('hold:' . $withdrawal->getId());
        $this->walletService->releaseHold($withdrawal->getUserId(), $withdrawal->getAmount(), $holdRef);

        $updated = $withdrawal->withStatus(WithdrawalStatus::REJECTED, $adminUserId, $reason);
        $this->persistUpdatedWithdrawal($updated);

        if (function_exists('do_action')) {
            do_action('favorite.pay.withdrawal.rejected', [
                'withdrawal_id' => $id,
                'user_id'       => $withdrawal->getUserId(),
                'admin_user_id' => $adminUserId,
                'reason'        => $reason,
            ]);
        }

        return $updated;
    }

    public function markFailed(string $id, int $adminUserId, string $reason): Withdrawal
    {
        $withdrawal = $this->getWithdrawal($id);
        if (!$withdrawal) {
            throw new RuntimeException("Withdrawal not found: {$id}");
        }

        if ($withdrawal->getStatus() === WithdrawalStatus::FAILED) {
            return $withdrawal; // Idempotent
        }

        if (!$withdrawal->getStatus()->canTransitionTo(WithdrawalStatus::FAILED)) {
            throw new RuntimeException("Cannot mark withdrawal as failed in status '{$withdrawal->getStatus()->value}'.");
        }

        // Release hold back to customer's available balance
        $holdRef = $withdrawal->getHoldReference() ?? ('hold:' . $withdrawal->getId());
        $this->walletService->releaseHold($withdrawal->getUserId(), $withdrawal->getAmount(), $holdRef);

        $updated = $withdrawal->withStatus(WithdrawalStatus::FAILED, $adminUserId, $reason);
        $this->persistUpdatedWithdrawal($updated);

        if (function_exists('do_action')) {
            do_action('favorite.pay.withdrawal.failed', [
                'withdrawal_id' => $id,
                'user_id'       => $withdrawal->getUserId(),
                'admin_user_id' => $adminUserId,
                'reason'        => $reason,
            ]);
        }

        return $updated;
    }

    public function cancel(string $id, int $userId, string $reason = 'Cancelled by customer'): Withdrawal
    {
        $withdrawal = $this->getWithdrawal($id);
        if (!$withdrawal) {
            throw new RuntimeException("Withdrawal not found: {$id}");
        }

        // Security / Ownership check
        if ($withdrawal->getUserId() !== $userId) {
            throw new RuntimeException("Access denied: You do not have permission to cancel this withdrawal.");
        }

        if ($withdrawal->getStatus() === WithdrawalStatus::CANCELLED) {
            return $withdrawal; // Idempotent
        }

        if (!$withdrawal->getStatus()->canTransitionTo(WithdrawalStatus::CANCELLED)) {
            throw new RuntimeException("Cannot cancel withdrawal in status '{$withdrawal->getStatus()->value}'.");
        }

        // Release hold back to customer's available balance
        $holdRef = $withdrawal->getHoldReference() ?? ('hold:' . $withdrawal->getId());
        $this->walletService->releaseHold($withdrawal->getUserId(), $withdrawal->getAmount(), $holdRef);

        $updated = $withdrawal->withStatus(WithdrawalStatus::CANCELLED, null, $reason);
        $this->persistUpdatedWithdrawal($updated);

        if (function_exists('do_action')) {
            do_action('favorite.pay.withdrawal.cancelled', [
                'withdrawal_id' => $id,
                'user_id'       => $userId,
                'reason'        => $reason,
            ]);
        }

        return $updated;
    }

    private function findByIdempotencyKey(string $key): ?Withdrawal
    {
        foreach ($this->withdrawals as $w) {
            if ($w->getIdempotencyKey() === $key) {
                return $w;
            }
        }

        if ($this->db !== null && $this->db->tableExists('favorite_pay_withdrawals')) {
            $row = $this->db->selectOne("SELECT * FROM favorite_pay_withdrawals WHERE idempotency_key = ? LIMIT 1", [$key]);
            if ($row) {
                return $this->hydrateRow($row);
            }
        }

        return null;
    }

    private function persistUpdatedWithdrawal(Withdrawal $withdrawal): void
    {
        $this->withdrawals[$withdrawal->getId()] = $withdrawal;

        if ($this->db !== null && $this->db->tableExists('favorite_pay_withdrawals')) {
            $this->db->update('favorite_pay_withdrawals', [
                'status'                => $withdrawal->getStatus()->value,
                'admin_user_id'         => $withdrawal->getAdminUserId(),
                'operator_notes'        => $withdrawal->getOperatorNotes(),
                'transaction_reference' => $withdrawal->getTransactionReference(),
                'updated_at'            => $withdrawal->getUpdatedAt(),
                'processed_at'          => $withdrawal->getProcessedAt(),
            ], ['withdrawal_id' => $withdrawal->getId()]);
        }
    }

    private function hydrateRow(object $row): Withdrawal
    {
        $destData = [];
        if (!empty($row->destination_data)) {
            $destData = is_array($row->destination_data) ? $row->destination_data : json_decode((string)$row->destination_data, true);
        }

        $currency = (string)($row->currency ?? 'BDT');

        return new Withdrawal(
            (string)$row->withdrawal_id,
            (int)$row->user_id,
            new Money((int)$row->amount, $currency),
            (string)$row->method,
            $destData ?? [],
            WithdrawalStatus::from((string)$row->status),
            new Money((int)($row->fee ?? 0), $currency),
            new Money((int)($row->net_amount ?? $row->amount), $currency),
            (string)($row->destination_masked ?? ''),
            isset($row->wallet_id) ? (int)$row->wallet_id : null,
            (string)($row->hold_reference ?? ''),
            (string)($row->transaction_reference ?? ''),
            (string)($row->idempotency_key ?? ''),
            isset($row->admin_user_id) ? (int)$row->admin_user_id : null,
            (string)($row->operator_notes ?? ''),
            (string)($row->created_at ?? date('Y-m-d H:i:s')),
            isset($row->updated_at) ? (string)$row->updated_at : null,
            isset($row->processed_at) ? (string)$row->processed_at : null
        );
    }

    /**
     * Check if any withdrawals exist (in-memory or database).
     */
    public function hasWithdrawals(): bool
    {
        if (!empty($this->inMemoryWithdrawals)) {
            return true;
        }

        if ($this->db !== null && $this->db->tableExists('favorite_pay_withdrawals')) {
            $row = $this->db->selectOne("SELECT 1 FROM favorite_pay_withdrawals LIMIT 1");
            return $row !== null;
        }

        return false;
    }

    /**
     * Get list of supported payout methods.
     */
    public function getSupportedPayoutMethods(): array
    {
        return $this->getSettings()['allowed_methods'] ?? self::DEFAULT_SETTINGS['allowed_methods'];
    }

    /**
     * Alias for getUserWithdrawals.
     */
    public function getCustomerWithdrawals(int $userId, int $limit = 25, int $offset = 0): array
    {
        return $this->getUserWithdrawals($userId, $limit, $offset);
    }

    /**
     * Alias for cancel.
     */
    public function cancelWithdrawal(string $id, int $userId, string $reason = 'Cancelled by customer'): Withdrawal
    {
        return $this->cancel($id, $userId, $reason);
    }
}
