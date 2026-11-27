<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
use FavoriteCMS\Pay\Contracts\WithdrawalServiceInterface;
use FavoriteCMS\Pay\Contracts\NotificationServiceInterface;
use FavoriteCMS\Pay\Contracts\AuditLogServiceInterface;
use FavoriteCMS\Pay\Services\NotificationService;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\Withdrawal;
use FavoriteCMS\Pay\Domain\WithdrawalStatus;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Support\DecimalFormatter;
use FavoriteCMS\Pay\Support\SafeLogger;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class WithdrawalService implements WithdrawalServiceInterface
{
    public const SETTING_GROUP = 'favorite_pay_withdrawals';

    public const DEFAULT_SETTINGS = [
        'enabled'           => false,
        'min_amount'        => 500.0,
        'max_monthly_count' => 5,
        'allowed_methods'   => ['bkash', 'nagad', 'rocket', 'bank_transfer'],
    ];

    private WalletServiceInterface $walletService;
    private CurrencyServiceInterface $currencyService;
    private ?Database $db;
    private ?NotificationServiceInterface $notificationService;
    private ?AuditLogServiceInterface $auditService;

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
        ?Database $db = null,
        ?NotificationServiceInterface $notificationService = null,
        ?AuditLogServiceInterface $auditService = null
    ) {
        $this->walletService = $walletService;
        $this->currencyService = $currencyService;
        $this->db = $db;
        $this->notificationService = $notificationService ?? new NotificationService($db);
        $this->auditService = $auditService;
    }

    public function getAuditService(): ?AuditLogServiceInterface
    {
        return $this->auditService;
    }

    public function setAuditService(AuditLogServiceInterface $auditService): void
    {
        $this->auditService = $auditService;
    }

    public function getNotificationService(): ?NotificationServiceInterface
    {
        return $this->notificationService;
    }

    public function setNotificationService(NotificationServiceInterface $notificationService): void
    {
        $this->notificationService = $notificationService;
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
                    if (isset($saved['max_monthly_count'])) {
                        $settings['max_monthly_count'] = (int)$saved['max_monthly_count'];
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
                if (isset($newSettings['max_monthly_count'])) {
                    Setting::set(self::SETTING_GROUP, 'max_monthly_count', (int)$newSettings['max_monthly_count'], 'int');
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
        $minMinor = (int)round(((float)($settings['min_amount'] ?? 500.0)) * 100);

        if ($minMinor > 0 && $amount->getAmount() < $minMinor) {
            $minMajor = number_format($minMinor / 100, 2);
            throw new InvalidArgumentException("Minimum withdrawal amount is {$minMajor} {$primaryCurrency}.");
        }

        // 3. Monthly withdrawal COUNT limit (Calendar Month)
        $maxMonthlyCount = (int)($settings['max_monthly_count'] ?? 5);
        if ($maxMonthlyCount > 0) {
            $currentMonthCount = $this->getMonthlyWithdrawalCount($userId);
            if ($currentMonthCount >= $maxMonthlyCount) {
                throw new InvalidArgumentException("Monthly withdrawal limit of {$maxMonthlyCount} requests reached for this calendar month.");
            }
        }

        // 4. Method validation
        $cleanMethod = strtolower(trim($method));
        $allowed = $settings['allowed_methods'] ?? self::DEFAULT_SETTINGS['allowed_methods'];
        if (!in_array($cleanMethod, $allowed, true)) {
            throw new InvalidArgumentException("Withdrawal method '{$cleanMethod}' is not supported.");
        }

        // 5. Destination validation
        if (empty($destinationData)) {
            throw new InvalidArgumentException("Destination account details are required.");
        }

        $accountNum = trim((string)($destinationData['account_number'] ?? $destinationData['phone'] ?? $destinationData['account'] ?? $destinationData['destination'] ?? ''));
        if ($accountNum === '') {
            throw new InvalidArgumentException("Destination account number or phone number is required.");
        }

        // 6. Idempotency check
        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $existing = $this->findByIdempotencyKey(trim($idempotencyKey));
            if ($existing) {
                return $existing;
            }
        }

        // Concurrency protection: Atomic check on monthly count limit when using DB
        if ($this->db !== null && $this->db->tableExists('favorite_pay_withdrawals') && $maxMonthlyCount > 0) {
            $currentYm = date('Y-m');
            $startOfMonth = $currentYm . '-01 00:00:00';
            $endOfMonth = date('Y-m-t 23:59:59', strtotime($startOfMonth));
            $activeStatuses = "'" . implode("','", [
                WithdrawalStatus::PENDING->value,
                WithdrawalStatus::APPROVED->value,
                WithdrawalStatus::PROCESSING->value,
                WithdrawalStatus::PAID->value,
            ]) . "'";

            $countRow = $this->db->selectOne(
                "SELECT COUNT(*) as cnt FROM favorite_pay_withdrawals 
                 WHERE user_id = ? 
                   AND created_at >= ? 
                   AND created_at <= ? 
                   AND status IN ({$activeStatuses})",
                [$userId, $startOfMonth, $endOfMonth]
            );
            $dbCount = $countRow ? (int)$countRow->cnt : 0;
            if ($dbCount >= $maxMonthlyCount) {
                throw new InvalidArgumentException("Monthly withdrawal limit of {$maxMonthlyCount} requests reached for this calendar month.");
            }
        }

        $canonicalId = 'wd_' . bin2hex(random_bytes(10));
        $holdReference = 'hold:' . $canonicalId;

        // 7. Authoritative wallet hold (reserves balance without debiting permanently)
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

        $initialAudit = [
            'action'      => 'created',
            'actor_id'    => $userId,
            'prev_status' => null,
            'new_status'  => WithdrawalStatus::PENDING->value,
            'timestamp'   => date('Y-m-d H:i:s'),
            'metadata'    => [
                'amount'   => $amount->getAmount(),
                'currency' => $primaryCurrency,
                'method'   => $cleanMethod,
            ],
        ];

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
            null,
            [$initialAudit]
        );

        $this->withdrawals[$canonicalId] = $withdrawal;

        // Persist to database if available
        if ($this->db !== null && $this->db->tableExists('favorite_pay_withdrawals')) {
            $insertData = [
                'withdrawal_id'         => $canonicalId,
                'user_id'               => $userId,
                'wallet_id'             => null,
                'amount'                => $amount->getAmount(),
                'currency'              => $primaryCurrency,
                'fee'                   => $fee->getAmount(),
                'net_amount'            => $netAmount->getAmount(),
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
            ];

            if ($this->hasAuditTrailColumn()) {
                $insertData['audit_trail'] = json_encode([$initialAudit]);
            }

            try {
                $this->db->insert('favorite_pay_withdrawals', $insertData);
            } catch (\Throwable $dbEx) {
                // If DB insert fails (e.g. unique constraint violation, disk full, DB lock), release the hold to prevent trapped funds
                try {
                    $this->walletService->releaseHold($userId, $amount, $holdReference);
                } catch (\Throwable $releaseEx) {
                    SafeLogger::error("Failed to release hold after withdrawal insertion failure", [
                        'user_id' => $userId,
                        'amount' => $amount->getAmount(),
                        'hold_reference' => $holdReference,
                        'error' => $releaseEx->getMessage(),
                    ]);
                }
                unset($this->withdrawals[$canonicalId]);
                throw $dbEx;
            }

            // Concurrency guard: Verify that concurrent requests did not exceed monthly count limit
            if ($maxMonthlyCount > 0) {
                $currentYm = date('Y-m');
                $startOfMonth = $currentYm . '-01 00:00:00';
                $endOfMonth = date('Y-m-t 23:59:59', strtotime($startOfMonth));
                $activeStatuses = "'" . implode("','", [
                    WithdrawalStatus::PENDING->value,
                    WithdrawalStatus::APPROVED->value,
                    WithdrawalStatus::PROCESSING->value,
                    WithdrawalStatus::PAID->value,
                ]) . "'";

                $countRow = $this->db->selectOne(
                    "SELECT COUNT(*) as cnt FROM favorite_pay_withdrawals 
                     WHERE user_id = ? 
                       AND created_at >= ? 
                       AND created_at <= ? 
                       AND status IN ({$activeStatuses})",
                    [$userId, $startOfMonth, $endOfMonth]
                );
                $postCount = $countRow ? (int)$countRow->cnt : 0;
                if ($postCount > $maxMonthlyCount) {
                    // Revert this insertion and release the hold
                    try {
                        $this->db->delete('favorite_pay_withdrawals', ['withdrawal_id' => $canonicalId]);
                    } catch (\Throwable $delEx) {
                        SafeLogger::error("Failed to delete excess withdrawal after monthly limit collision", [
                            'withdrawal_id' => $canonicalId,
                            'error' => $delEx->getMessage(),
                        ]);
                    }
                    try {
                        $this->walletService->releaseHold($userId, $amount, $holdReference);
                    } catch (\Throwable $releaseEx) {
                        SafeLogger::error("Failed to release hold after monthly limit collision", [
                            'user_id' => $userId,
                            'amount' => $amount->getAmount(),
                            'hold_reference' => $holdReference,
                            'error' => $releaseEx->getMessage(),
                        ]);
                    }
                    unset($this->withdrawals[$canonicalId]);
                    throw new InvalidArgumentException("Monthly withdrawal limit of {$maxMonthlyCount} requests reached for this calendar month.");
                }
            }
        }

        // Trigger customer in-app notification for created withdrawal
        $this->notifyCustomerOfWithdrawalEvent(
            $withdrawal,
            NotificationServiceInterface::TYPE_WITHDRAWAL_CREATED,
            'Withdrawal Request Submitted',
            "Your withdrawal request ({$withdrawal->getId()}) for {$withdrawal->getAmount()->format()} via " . strtoupper($withdrawal->getMethod()) . " has been submitted."
        );

        SafeLogger::info("Withdrawal request created", [
            'withdrawal_id' => $canonicalId,
            'user_id'       => $userId,
            'amount'        => $amount->getAmount(),
            'currency'      => $primaryCurrency,
            'method'        => $cleanMethod,
        ]);

        $this->logAudit(
            action: 'withdrawal.requested',
            subjectType: 'withdrawal',
            subjectId: $canonicalId,
            targetUserId: $userId,
            metadata: [
                'amount'      => $amount->getAmount(),
                'fee'         => $fee->getAmount(),
                'net_amount'  => $netAmount->getAmount(),
                'currency'    => $primaryCurrency,
                'method'      => $cleanMethod,
                'destination' => $withdrawal->getDestinationMasked(),
            ],
            description: "Customer requested withdrawal of {$amount->format()} via " . strtoupper($cleanMethod),
            actorUserId: $userId,
            actorType: 'customer',
            withdrawalId: $canonicalId
        );

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

    /**
     * Build parameterized SQL WHERE clause and bindings for withdrawal filters.
     *
     * @param array $filters
     * @return array{0: string, 1: array}
     */
    private function buildFilterConditions(array $filters): array
    {
        $where = [];
        $bindings = [];

        // 1. Status Filter (validated against authoritative enum)
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $rawStatus = (string)$filters['status'];
            $statusEnum = WithdrawalStatus::tryFrom($rawStatus);
            if ($statusEnum !== null) {
                $where[] = "status = ?";
                $bindings[] = $statusEnum->value;
            } else {
                $where[] = "1 = 0";
            }
        }

        // 2. Method Filter (supports aliases & specific types)
        if (!empty($filters['method']) && $filters['method'] !== 'all') {
            $m = (string)$filters['method'];
            if ($m === 'bkash_personal' || $m === 'bkash') {
                $where[] = "(method = 'bkash_personal' OR method = 'bkash')";
            } elseif ($m === 'nagad_personal' || $m === 'nagad') {
                $where[] = "(method = 'nagad_personal' OR method = 'nagad')";
            } elseif ($m === 'rocket_personal' || $m === 'rocket') {
                $where[] = "(method = 'rocket_personal' OR method = 'rocket')";
            } else {
                $where[] = "method = ?";
                $bindings[] = $m;
            }
        }

        // 3. User ID filter
        if (!empty($filters['user_id'])) {
            $where[] = "user_id = ?";
            $bindings[] = (int)$filters['user_id'];
        }

        // 4. Date Range: date_from
        if (!empty($filters['date_from'])) {
            $dateFrom = trim((string)$filters['date_from']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
                $where[] = "created_at >= ?";
                $bindings[] = $dateFrom . ' 00:00:00';
            }
        }

        // 5. Date Range: date_to
        if (!empty($filters['date_to'])) {
            $dateTo = trim((string)$filters['date_to']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
                $where[] = "created_at <= ?";
                $bindings[] = $dateTo . ' 23:59:59';
            }
        }

        // 6. Search / Customer search
        $search = trim((string)($filters['search'] ?? $filters['customer'] ?? ''));
        if ($search !== '') {
            $sWild = '%' . $search . '%';
            $orClauses = [
                "withdrawal_id LIKE ?",
                "destination_masked LIKE ?",
                "transaction_reference LIKE ?",
            ];
            $bindings[] = $sWild;
            $bindings[] = $sWild;
            $bindings[] = $sWild;

            if (is_numeric($search)) {
                $orClauses[] = "user_id = ?";
                $bindings[] = (int)$search;
            }

            // Customer search: username or email lookup
            if ($this->db !== null && $this->db->tableExists('users')) {
                $userMatches = $this->db->select("SELECT id FROM users WHERE username LIKE ? OR email LIKE ? LIMIT 50", [$sWild, $sWild]);
                if (!empty($userMatches)) {
                    $uids = array_map(fn($r) => (int)$r->id, $userMatches);
                    $placeholders = implode(',', array_fill(0, count($uids), '?'));
                    $orClauses[] = "user_id IN ({$placeholders})";
                    foreach ($uids as $uid) {
                        $bindings[] = $uid;
                    }
                }
            }

            $where[] = "(" . implode(" OR ", $orClauses) . ")";
        }

        $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        return [$whereSql, $bindings];
    }

    /**
     * Filter in-memory withdrawals array for unit tests / database-less runs.
     *
     * @param array $filters
     * @return array<int, Withdrawal>
     */
    private function filterInMemoryWithdrawals(array $filters): array
    {
        $list = array_values($this->withdrawals);

        // 1. Status Filter
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $rawStatus = (string)$filters['status'];
            $statusEnum = WithdrawalStatus::tryFrom($rawStatus);
            if ($statusEnum !== null) {
                $list = array_filter($list, fn($w) => $w->getStatus() === $statusEnum);
            } else {
                $list = [];
            }
        }

        // 2. Method Filter
        if (!empty($filters['method']) && $filters['method'] !== 'all') {
            $m = (string)$filters['method'];
            if ($m === 'bkash_personal' || $m === 'bkash') {
                $list = array_filter($list, fn($w) => in_array($w->getMethod(), ['bkash_personal', 'bkash'], true));
            } elseif ($m === 'nagad_personal' || $m === 'nagad') {
                $list = array_filter($list, fn($w) => in_array($w->getMethod(), ['nagad_personal', 'nagad'], true));
            } elseif ($m === 'rocket_personal' || $m === 'rocket') {
                $list = array_filter($list, fn($w) => in_array($w->getMethod(), ['rocket_personal', 'rocket'], true));
            } else {
                $list = array_filter($list, fn($w) => $w->getMethod() === $m);
            }
        }

        // 3. User ID filter
        if (!empty($filters['user_id'])) {
            $list = array_filter($list, fn($w) => $w->getUserId() === (int)$filters['user_id']);
        }

        // 4. Date Range: date_from
        if (!empty($filters['date_from'])) {
            $from = trim((string)$filters['date_from']) . ' 00:00:00';
            $list = array_filter($list, fn($w) => $w->getCreatedAt() >= $from);
        }

        // 5. Date Range: date_to
        if (!empty($filters['date_to'])) {
            $to = trim((string)$filters['date_to']) . ' 23:59:59';
            $list = array_filter($list, fn($w) => $w->getCreatedAt() <= $to);
        }

        // 6. Search / Customer search
        $search = strtolower(trim((string)($filters['search'] ?? $filters['customer'] ?? '')));
        if ($search !== '') {
            $list = array_filter($list, function($w) use ($search) {
                if (str_contains(strtolower($w->getId()), $search)) {
                    return true;
                }
                if (str_contains(strtolower($w->getDestinationMasked()), $search)) {
                    return true;
                }
                if ($w->getTransactionReference() && str_contains(strtolower($w->getTransactionReference()), $search)) {
                    return true;
                }
                if ((string)$w->getUserId() === $search) {
                    return true;
                }
                $user = $this->resolveCustomerDetails($w->getUserId());
                if (!empty($user['username']) && str_contains(strtolower($user['username']), $search)) {
                    return true;
                }
                if (!empty($user['email']) && str_contains(strtolower($user['email']), $search)) {
                    return true;
                }
                return false;
            });
        }

        return array_values($list);
    }

    public function listWithdrawals(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        if ($this->db !== null && $this->db->tableExists('favorite_pay_withdrawals')) {
            [$whereSql, $bindings] = $this->buildFilterConditions($filters);

            $countRow = $this->db->selectOne("SELECT COUNT(*) as cnt FROM favorite_pay_withdrawals {$whereSql}", $bindings);
            $total = $countRow ? (int)$countRow->cnt : 0;

            $rows = $this->db->select("SELECT * FROM favorite_pay_withdrawals {$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}", $bindings);
            $items = array_map([$this, 'hydrateRow'], $rows);

            // Status counts (respecting current date/method/search filters if any, or global)
            $counts = [];
            $cntRows = $this->db->select("SELECT status, COUNT(*) as cnt FROM favorite_pay_withdrawals GROUP BY status");
            foreach ($cntRows as $cr) {
                $counts[$cr->status] = (int)$cr->cnt;
            }

            return ['items' => $items, 'total' => $total, 'counts' => $counts];
        }

        // In-memory fallback
        $list = $this->filterInMemoryWithdrawals($filters);
        $total = count($list);

        // Global status counts
        $counts = [];
        foreach ($this->withdrawals as $w) {
            $st = $w->getStatus()->value;
            $counts[$st] = ($counts[$st] ?? 0) + 1;
        }

        $sliced = array_slice(array_reverse($list), $offset, $limit);
        return ['items' => $sliced, 'total' => $total, 'counts' => $counts];
    }

    /**
     * Get summary statistics (request counts and monetary totals) for withdrawals matching filters.
     *
     * @param array $filters Filtering options
     * @return array{
     *     counts: array<string, int>,
     *     totals: array{gross_cents: int, fee_cents: int, net_cents: int, paid_gross_cents: int, paid_net_cents: int, currency: string}
     * }
     */
    public function getSummary(array $filters = []): array
    {
        $primaryCurrency = method_exists($this->walletService, 'getPrimaryCurrency') ? $this->walletService->getPrimaryCurrency() : 'BDT';

        if ($this->db !== null && $this->db->tableExists('favorite_pay_withdrawals')) {
            [$whereSql, $bindings] = $this->buildFilterConditions($filters);

            $sql = "SELECT
                COUNT(*) as total_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as count_pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as count_approved,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as count_processing,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as count_paid,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as count_rejected,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as count_failed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as count_cancelled,
                COALESCE(SUM(amount), 0) as total_gross,
                COALESCE(SUM(fee), 0) as total_fee,
                COALESCE(SUM(net_amount), 0) as total_net,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as total_paid_gross,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN net_amount ELSE 0 END), 0) as total_paid_net
            FROM favorite_pay_withdrawals {$whereSql}";

            $row = $this->db->selectOne($sql, $bindings);

            return [
                'counts' => [
                    'total'      => (int)($row->total_count ?? 0),
                    'pending'    => (int)($row->count_pending ?? 0),
                    'approved'   => (int)($row->count_approved ?? 0),
                    'processing' => (int)($row->count_processing ?? 0),
                    'paid'       => (int)($row->count_paid ?? 0),
                    'rejected'   => (int)($row->count_rejected ?? 0),
                    'failed'     => (int)($row->count_failed ?? 0),
                    'cancelled'  => (int)($row->count_cancelled ?? 0),
                ],
                'totals' => [
                    'gross_cents'      => (int)($row->total_gross ?? 0),
                    'fee_cents'        => (int)($row->total_fee ?? 0),
                    'net_cents'        => (int)($row->total_net ?? 0),
                    'paid_gross_cents' => (int)($row->total_paid_gross ?? 0),
                    'paid_net_cents'   => (int)($row->total_paid_net ?? 0),
                    'currency'         => $primaryCurrency,
                ],
            ];
        }

        // In-memory fallback
        $list = $this->filterInMemoryWithdrawals($filters);

        $counts = [
            'total'      => count($list),
            'pending'    => 0,
            'approved'   => 0,
            'processing' => 0,
            'paid'       => 0,
            'rejected'   => 0,
            'failed'     => 0,
            'cancelled'  => 0,
        ];

        $totalGross = 0;
        $totalFee   = 0;
        $totalNet   = 0;
        $paidGross  = 0;
        $paidNet    = 0;

        foreach ($list as $w) {
            $stVal = $w->getStatus()->value;
            if (isset($counts[$stVal])) {
                $counts[$stVal]++;
            }

            $gross = $w->getAmount()->getAmount();
            $fee   = $w->getFee()->getAmount();
            $net   = $w->getNetAmount()->getAmount();

            $totalGross += $gross;
            $totalFee   += $fee;
            $totalNet   += $net;

            if ($w->getStatus() === WithdrawalStatus::PAID) {
                $paidGross += $gross;
                $paidNet   += $net;
            }
        }

        return [
            'counts' => $counts,
            'totals' => [
                'gross_cents'      => $totalGross,
                'fee_cents'        => $totalFee,
                'net_cents'        => $totalNet,
                'paid_gross_cents' => $paidGross,
                'paid_net_cents'   => $paidNet,
                'currency'         => $primaryCurrency,
            ],
        ];
    }

    /**
     * Sanitize values to protect against CSV/spreadsheet formula injection.
     * Values beginning with =, +, -, @, \t, \r are prefixed with a single quote unless purely numeric.
     *
     * @param mixed $value
     * @param bool $isNumeric
     * @return string
     */
    public static function sanitizeCsvValue(mixed $value, bool $isNumeric = false): string
    {
        if ($value === null) {
            return '';
        }
        $str = (string)$value;
        if ($isNumeric) {
            return $str;
        }

        if (preg_match('/^[\=\+\-\0\@\t\r]/', $str)) {
            return "'" . $str;
        }

        return $str;
    }

    /**
     * Resolve customer details (id, username, email) with safety fallbacks.
     *
     * @param int $userId
     * @return array{id: int, username: string, email: string}
     */
    public function resolveCustomerDetails(int $userId): array
    {
        if ($userId <= 0) {
            return ['id' => 0, 'username' => 'Unknown', 'email' => ''];
        }

        if ($this->db !== null && $this->db->tableExists('users')) {
            $userRow = $this->db->selectOne("SELECT id, username, email FROM users WHERE id = ?", [$userId]);
            if ($userRow) {
                return [
                    'id'       => (int)$userRow->id,
                    'username' => (string)($userRow->username ?? "User #{$userId}"),
                    'email'    => (string)($userRow->email ?? ''),
                ];
            }
        }

        if (class_exists(User::class)) {
            try {
                $user = User::find($userId);
                if ($user) {
                    return [
                        'id'       => (int)$user->id,
                        'username' => (string)($user->username ?? "User #{$userId}"),
                        'email'    => (string)($user->email ?? ''),
                    ];
                }
            } catch (Throwable) {
            }
        }

        if (isset($GLOBALS['_test_current_user']) && (int)($GLOBALS['_test_current_user']->id ?? 0) === $userId) {
            $u = $GLOBALS['_test_current_user'];
            return [
                'id'       => $userId,
                'username' => (string)($u->username ?? "User #{$userId}"),
                'email'    => (string)($u->email ?? ''),
            ];
        }

        return [
            'id'       => $userId,
            'username' => "User #{$userId}",
            'email'    => '',
        ];
    }

    /**
     * Export withdrawals matching filters to customer-safe, formula-sanitized CSV string.
     * Chunked reading ensures low memory consumption suitable for shared hosting.
     *
     * @param array $filters
     * @return string
     */
    public function exportWithdrawalsCsv(array $filters = []): string
    {
        $handle = fopen('php://temp', 'r+');
        // Prepend UTF-8 BOM for spreadsheet encoding compatibility
        fwrite($handle, "\xEF\xBB\xBF");

        $headers = [
            'Withdrawal ID',
            'User ID',
            'Username',
            'Email',
            'Gross Amount',
            'Fee',
            'Net Amount',
            'Currency',
            'Method',
            'Masked Destination',
            'Status',
            'Payout Reference',
            'Created At',
            'Updated At',
            'Paid At',
        ];
        fputcsv($handle, $headers);

        $chunkSize = 250;
        $offset = 0;
        $userCache = [];

        do {
            $chunk = $this->listWithdrawals($filters, $chunkSize, $offset);
            $items = $chunk['items'];
            if (empty($items)) {
                break;
            }

            foreach ($items as $w) {
                /** @var Withdrawal $w */
                $userId = $w->getUserId();
                if (!isset($userCache[$userId])) {
                    $userCache[$userId] = $this->resolveCustomerDetails($userId);
                }
                $user = $userCache[$userId];

                $grossDec = DecimalFormatter::minorUnitToDecimal($w->getAmount()->getAmount(), 2);
                $feeDec   = DecimalFormatter::minorUnitToDecimal($w->getFee()->getAmount(), 2);
                $netDec   = DecimalFormatter::minorUnitToDecimal($w->getNetAmount()->getAmount(), 2);

                $paidAt = '';
                if ($w->getStatus() === WithdrawalStatus::PAID) {
                    $paidAt = $w->getProcessedAt() ?? $w->getUpdatedAt() ?? '';
                    foreach ($w->getAuditTrail() as $entry) {
                        if (($entry['action'] ?? '') === 'paid') {
                            $paidAt = (string)($entry['timestamp'] ?? $paidAt);
                            break;
                        }
                    }
                }

                $methodLabel = ucwords(str_replace('_', ' ', $w->getMethod()));

                $row = [
                    self::sanitizeCsvValue($w->getId()),
                    self::sanitizeCsvValue($userId, true),
                    self::sanitizeCsvValue($user['username'] ?? ''),
                    self::sanitizeCsvValue($user['email'] ?? ''),
                    self::sanitizeCsvValue($grossDec, true),
                    self::sanitizeCsvValue($feeDec, true),
                    self::sanitizeCsvValue($netDec, true),
                    self::sanitizeCsvValue($w->getCurrency()),
                    self::sanitizeCsvValue($methodLabel),
                    self::sanitizeCsvValue($w->getDestinationMasked()),
                    self::sanitizeCsvValue($w->getStatus()->label()),
                    self::sanitizeCsvValue($w->getTransactionReference() ?? ''),
                    self::sanitizeCsvValue($w->getCreatedAt()),
                    self::sanitizeCsvValue($w->getUpdatedAt() ?? ''),
                    self::sanitizeCsvValue($paidAt),
                ];

                fputcsv($handle, $row);
            }

            $offset += count($items);
        } while (count($items) === $chunkSize);

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function getUserWithdrawals(int $userId, int $limit = 25, int $offset = 0): array
    {
        $res = $this->listWithdrawals(['user_id' => $userId], $limit, $offset);
        return $res['items'];
    }

    public function approve(string $id, int $adminUserId, ?string $notes = null): Withdrawal
    {
        $updated = $this->transitionStatus(
            id: $id,
            targetStatus: WithdrawalStatus::APPROVED,
            actorId: $adminUserId,
            action: 'approved',
            metadata: $notes !== null ? ['notes' => $notes] : [],
            notes: $notes
        );

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
        $updated = $this->transitionStatus(
            id: $id,
            targetStatus: WithdrawalStatus::PROCESSING,
            actorId: $adminUserId,
            action: 'processing_started',
            metadata: $notes !== null ? ['notes' => $notes] : [],
            notes: $notes
        );

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
        $meta = [];
        if ($transactionReference !== null && trim($transactionReference) !== '') {
            $meta['transaction_reference'] = trim($transactionReference);
        }
        if ($notes !== null && trim($notes) !== '') {
            $meta['notes'] = trim($notes);
        }

        $updated = $this->transitionStatus(
            id: $id,
            targetStatus: WithdrawalStatus::PAID,
            actorId: $adminUserId,
            action: 'paid',
            metadata: $meta,
            notes: $notes,
            txRef: $transactionReference,
            onTransitionSuccess: function (Withdrawal $prev, Withdrawal $curr) {
                // Finalize hold: records permanent debit ledger entry (once only)
                $holdRef = $prev->getHoldReference() ?? ('hold:' . $prev->getId());
                $this->walletService->finalizeHold(
                    $prev->getUserId(),
                    $prev->getAmount(),
                    $prev->getId(),
                    "Withdrawal payout completed ({$prev->getMethod()})"
                );
            }
        );

        if (function_exists('do_action')) {
            do_action('favorite.pay.withdrawal.paid', [
                'withdrawal_id'         => $id,
                'user_id'               => $updated->getUserId(),
                'amount'                => $updated->getAmount()->getAmount(),
                'currency'              => $updated->getCurrency(),
                'transaction_reference' => $transactionReference,
                'admin_user_id'         => $adminUserId,
            ]);
        }

        return $updated;
    }

    public function reject(string $id, int $adminUserId, string $reason): Withdrawal
    {
        $updated = $this->transitionStatus(
            id: $id,
            targetStatus: WithdrawalStatus::REJECTED,
            actorId: $adminUserId,
            action: 'rejected',
            metadata: ['reason' => $reason],
            notes: $reason,
            onTransitionSuccess: function (Withdrawal $prev, Withdrawal $curr) {
                // Release hold back to customer's available balance (once only)
                $holdRef = $prev->getHoldReference() ?? ('hold:' . $prev->getId());
                $this->walletService->releaseHold($prev->getUserId(), $prev->getAmount(), $holdRef);
            }
        );

        if (function_exists('do_action')) {
            do_action('favorite.pay.withdrawal.rejected', [
                'withdrawal_id' => $id,
                'user_id'       => $updated->getUserId(),
                'admin_user_id' => $adminUserId,
                'reason'        => $reason,
            ]);
        }

        return $updated;
    }

    public function markFailed(string $id, int $adminUserId, string $reason): Withdrawal
    {
        $updated = $this->transitionStatus(
            id: $id,
            targetStatus: WithdrawalStatus::FAILED,
            actorId: $adminUserId,
            action: 'failed',
            metadata: ['reason' => $reason],
            notes: $reason,
            onTransitionSuccess: function (Withdrawal $prev, Withdrawal $curr) {
                // Release hold back to customer's available balance (once only)
                $holdRef = $prev->getHoldReference() ?? ('hold:' . $prev->getId());
                $this->walletService->releaseHold($prev->getUserId(), $prev->getAmount(), $holdRef);
            }
        );

        if (function_exists('do_action')) {
            do_action('favorite.pay.withdrawal.failed', [
                'withdrawal_id' => $id,
                'user_id'       => $updated->getUserId(),
                'admin_user_id' => $adminUserId,
                'reason'        => $reason,
            ]);
        }

        return $updated;
    }

    public function cancel(string $id, int $userId, string $reason = 'Cancelled by customer', bool $isAdmin = false): Withdrawal
    {
        $withdrawal = $this->getWithdrawal($id);
        if (!$withdrawal) {
            throw new RuntimeException("Withdrawal not found: {$id}");
        }

        // Security / Ownership check and customer restriction
        if (!$isAdmin) {
            if ($withdrawal->getUserId() !== $userId) {
                throw new RuntimeException("Access denied: You do not have permission to cancel this withdrawal.");
            }
            if ($withdrawal->getStatus() !== WithdrawalStatus::PENDING) {
                throw new RuntimeException("Cannot cancel withdrawal in status '{$withdrawal->getStatus()->value}': Only pending requests can be cancelled by customer.");
            }
        }

        $actorId = $userId;
        $updated = $this->transitionStatus(
            id: $id,
            targetStatus: WithdrawalStatus::CANCELLED,
            actorId: $actorId,
            action: 'cancelled',
            metadata: ['reason' => $reason, 'cancelled_by' => $isAdmin ? 'admin' : 'customer'],
            notes: $reason,
            onTransitionSuccess: function (Withdrawal $prev, Withdrawal $curr) {
                // Release hold back to customer's available balance (once only)
                $holdRef = $prev->getHoldReference() ?? ('hold:' . $prev->getId());
                $this->walletService->releaseHold($prev->getUserId(), $prev->getAmount(), $holdRef);
            }
        );

        if (function_exists('do_action')) {
            do_action('favorite.pay.withdrawal.cancelled', [
                'withdrawal_id' => $id,
                'user_id'       => $withdrawal->getUserId(),
                'reason'        => $reason,
            ]);
        }

        return $updated;
    }

    public function updateProcessingNotes(string $id, int $adminUserId, string $notes): Withdrawal
    {
        $withdrawal = $this->getWithdrawal($id);
        if (!$withdrawal) {
            throw new RuntimeException("Withdrawal not found: {$id}");
        }

        $trimmed = trim($notes);
        $auditEntry = [
            'action'      => 'note_updated',
            'actor_id'    => $adminUserId,
            'prev_status' => $withdrawal->getStatus()->value,
            'new_status'  => $withdrawal->getStatus()->value,
            'timestamp'   => date('Y-m-d H:i:s'),
            'metadata'    => ['operator_notes' => $trimmed],
        ];

        $updated = $withdrawal->withNotes($trimmed, $adminUserId, $auditEntry);
        $this->persistUpdatedWithdrawal($updated);

        SafeLogger::info("Withdrawal processing notes updated", [
            'withdrawal_id' => $id,
            'admin_user_id' => $adminUserId,
        ]);

        $this->logAudit(
            action: 'withdrawal.note_added',
            subjectType: 'withdrawal',
            subjectId: $id,
            targetUserId: $updated->getUserId(),
            metadata: ['operator_notes' => $trimmed],
            description: "Internal note updated for withdrawal #{$id}",
            actorUserId: $adminUserId,
            withdrawalId: $id
        );

        if (function_exists('do_action')) {
            do_action('favorite.pay.withdrawal.notes_updated', [
                'withdrawal_id' => $id,
                'admin_user_id' => $adminUserId,
                'notes'         => $trimmed,
            ]);
        }

        return $updated;
    }

    public function updateTransactionReference(string $id, int $adminUserId, string $transactionReference): Withdrawal
    {
        $withdrawal = $this->getWithdrawal($id);
        if (!$withdrawal) {
            throw new RuntimeException("Withdrawal not found: {$id}");
        }

        $trimmed = trim($transactionReference);
        if (strlen($trimmed) > 190) {
            throw new InvalidArgumentException("Transaction reference exceeds maximum length of 190 characters.");
        }

        $auditEntry = [
            'action'      => 'reference_updated',
            'actor_id'    => $adminUserId,
            'prev_status' => $withdrawal->getStatus()->value,
            'new_status'  => $withdrawal->getStatus()->value,
            'timestamp'   => date('Y-m-d H:i:s'),
            'metadata'    => ['transaction_reference' => $trimmed],
        ];

        $updated = $withdrawal->withTransactionReference($trimmed, $adminUserId, $auditEntry);
        $this->persistUpdatedWithdrawal($updated);

        SafeLogger::info("Withdrawal transaction reference updated", [
            'withdrawal_id'         => $id,
            'admin_user_id'         => $adminUserId,
            'transaction_reference' => $trimmed,
        ]);

        $this->logAudit(
            action: 'withdrawal.reference_updated',
            subjectType: 'withdrawal',
            subjectId: $id,
            targetUserId: $updated->getUserId(),
            metadata: ['transaction_reference' => $trimmed],
            description: "Transaction reference updated for withdrawal #{$id}",
            actorUserId: $adminUserId,
            withdrawalId: $id
        );

        if (function_exists('do_action')) {
            do_action('favorite.pay.withdrawal.reference_updated', [
                'withdrawal_id'         => $id,
                'admin_user_id'         => $adminUserId,
                'transaction_reference' => $trimmed,
            ]);
        }

        return $updated;
    }

    public function getAuditTrail(string $id): array
    {
        $withdrawal = $this->getWithdrawal($id);
        return $withdrawal ? $withdrawal->getAuditTrail() : [];
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


    private ?bool $hasAuditColumnCache = null;

    private function hasAuditTrailColumn(): bool
    {
        if ($this->hasAuditColumnCache !== null) {
            return $this->hasAuditColumnCache;
        }

        if ($this->db === null || !$this->db->tableExists('favorite_pay_withdrawals')) {
            return $this->hasAuditColumnCache = false;
        }

        try {
            $cols = $this->db->select("SHOW COLUMNS FROM `favorite_pay_withdrawals` LIKE 'audit_trail'");
            return $this->hasAuditColumnCache = !empty($cols);
        } catch (\Throwable) {
            try {
                $cols = $this->db->select("PRAGMA table_info('favorite_pay_withdrawals')");
                foreach ($cols as $col) {
                    $name = is_array($col) ? ($col['name'] ?? '') : ($col->name ?? '');
                    if (strtolower((string)$name) === 'audit_trail') {
                        return $this->hasAuditColumnCache = true;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $this->hasAuditColumnCache = false;
    }

    /**
     * Atomically transition withdrawal status with FSM validation, concurrency protection,
     * append-only audit trail recording, and single-execution accounting callback.
     */
    protected function transitionStatus(
        string $id,
        WithdrawalStatus $targetStatus,
        ?int $actorId,
        string $action,
        array $metadata = [],
        ?string $notes = null,
        ?string $txRef = null,
        ?\Closure $onTransitionSuccess = null
    ): Withdrawal {
        $withdrawal = $this->getWithdrawal($id);
        if (!$withdrawal) {
            throw new RuntimeException("Withdrawal not found: {$id}");
        }

        // Fast idempotency: already in target status
        if ($withdrawal->getStatus() === $targetStatus) {
            return $withdrawal;
        }

        if (!$withdrawal->getStatus()->canTransitionTo($targetStatus)) {
            throw new RuntimeException(
                "Cannot transition withdrawal #{$id} from status '{$withdrawal->getStatus()->value}' to '{$targetStatus->value}'."
            );
        }

        $prevStatus = $withdrawal->getStatus();
        $auditEntry = [
            'action'      => $action,
            'actor_id'    => $actorId,
            'prev_status' => $prevStatus->value,
            'new_status'  => $targetStatus->value,
            'timestamp'   => date('Y-m-d H:i:s'),
            'metadata'    => SafeLogger::sanitize($metadata),
        ];

        $updated = $withdrawal->withStatus($targetStatus, $actorId, $notes, $txRef, $auditEntry);

        // Atomic conditional update in database to prevent double actions and concurrent races
        if ($this->db !== null && $this->db->tableExists('favorite_pay_withdrawals')) {
            $updateData = [
                'status'                => $targetStatus->value,
                'admin_user_id'         => $updated->getAdminUserId(),
                'operator_notes'        => $updated->getOperatorNotes(),
                'transaction_reference' => $updated->getTransactionReference(),
                'updated_at'            => $updated->getUpdatedAt(),
                'processed_at'          => $updated->getProcessedAt(),
            ];

            if ($this->hasAuditTrailColumn()) {
                $updateData['audit_trail'] = json_encode($updated->getAuditTrail());
            }

            $affected = $this->db->update(
                'favorite_pay_withdrawals',
                $updateData,
                [
                    'withdrawal_id' => $id,
                    'status'        => $prevStatus->value,
                ]
            );

            if ($affected === 0) {
                // Check if another concurrent request already transitioned to the target status
                $recheck = $this->db->selectOne("SELECT status FROM favorite_pay_withdrawals WHERE withdrawal_id = ?", [$id]);
                if ($recheck && (string)$recheck->status === $targetStatus->value) {
                    return $this->getWithdrawal($id) ?? $updated;
                }
                throw new RuntimeException("Concurrent conflict: Withdrawal #{$id} status has been modified by another process.");
            }
        }

        // Update in-memory storage
        $this->withdrawals[$id] = $updated;

        // Accounting invariant execution: only called once after atomic transition succeeds
        if ($onTransitionSuccess !== null) {
            $onTransitionSuccess($withdrawal, $updated);
        }

        // Trigger customer in-app notification for status transition
        $this->notifyCustomerOfStatusTransition($updated, $targetStatus);

        SafeLogger::info("Withdrawal status transitioned", [
            'withdrawal_id' => $id,
            'action'        => $action,
            'prev_status'   => $prevStatus->value,
            'new_status'    => $targetStatus->value,
            'actor_id'      => $actorId,
        ]);

        // Record operational audit log (strictly non-blocking)
        $canonicalAction = match ($targetStatus) {
            WithdrawalStatus::APPROVED   => 'withdrawal.approved',
            WithdrawalStatus::PROCESSING => 'withdrawal.processing',
            WithdrawalStatus::PAID       => 'withdrawal.paid',
            WithdrawalStatus::REJECTED   => 'withdrawal.rejected',
            WithdrawalStatus::FAILED     => 'withdrawal.failed',
            WithdrawalStatus::CANCELLED  => 'withdrawal.cancelled',
            default                      => "withdrawal.{$action}",
        };

        $this->logAudit(
            action: $canonicalAction,
            subjectType: 'withdrawal',
            subjectId: $id,
            targetUserId: $updated->getUserId(),
            metadata: array_merge($metadata, [
                'prev_status' => $prevStatus->value,
                'new_status'  => $targetStatus->value,
            ]),
            description: "Withdrawal #{$id} transitioned to " . strtoupper($targetStatus->value) . " by " . ($actorId > 0 ? "user #{$actorId}" : 'system'),
            actorUserId: $actorId,
            withdrawalId: $id
        );

        return $updated;
    }

    private function persistUpdatedWithdrawal(Withdrawal $withdrawal): void
    {
        $this->withdrawals[$withdrawal->getId()] = $withdrawal;

        if ($this->db !== null && $this->db->tableExists('favorite_pay_withdrawals')) {
            $data = [
                'status'                => $withdrawal->getStatus()->value,
                'admin_user_id'         => $withdrawal->getAdminUserId(),
                'operator_notes'        => $withdrawal->getOperatorNotes(),
                'transaction_reference' => $withdrawal->getTransactionReference(),
                'updated_at'            => $withdrawal->getUpdatedAt(),
                'processed_at'          => $withdrawal->getProcessedAt(),
            ];

            if ($this->hasAuditTrailColumn()) {
                $data['audit_trail'] = json_encode($withdrawal->getAuditTrail());
            }

            $this->db->update('favorite_pay_withdrawals', $data, ['withdrawal_id' => $withdrawal->getId()]);
        }
    }

    private function hydrateRow(object $row): Withdrawal
    {
        $destData = [];
        if (!empty($row->destination_data)) {
            $destData = is_array($row->destination_data) ? $row->destination_data : json_decode((string)$row->destination_data, true);
        }

        $currency = (string)($row->currency ?? 'BDT');

        $auditTrail = [];
        if (!empty($row->audit_trail)) {
            $auditTrail = is_array($row->audit_trail) ? $row->audit_trail : json_decode((string)$row->audit_trail, true);
        }

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
            isset($row->processed_at) ? (string)$row->processed_at : null,
            $auditTrail ?? []
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

    /**
     * Get count of successful/active withdrawals for a customer in a given calendar month.
     * Default month is current calendar month (Y-m).
     *
     * Counting policy:
     * - PENDING, APPROVED, PROCESSING, PAID count against the monthly limit.
     * - REJECTED, FAILED, CANCELLED do NOT count against the monthly limit (slot is released).
     */
    public function getMonthlyWithdrawalCount(int $userId, ?string $yearMonth = null): int
    {
        $ym = $yearMonth ?: date('Y-m');

        // 1. Check database if available
        if ($this->db !== null && $this->db->tableExists('favorite_pay_withdrawals')) {
            $startOfMonth = $ym . '-01 00:00:00';
            $endOfMonth = date('Y-m-t 23:59:59', strtotime($startOfMonth));
            $activeStatuses = "'" . implode("','", [
                WithdrawalStatus::PENDING->value,
                WithdrawalStatus::APPROVED->value,
                WithdrawalStatus::PROCESSING->value,
                WithdrawalStatus::PAID->value,
            ]) . "'";

            $countRow = $this->db->selectOne(
                "SELECT COUNT(*) as cnt FROM favorite_pay_withdrawals 
                 WHERE user_id = ? 
                   AND created_at >= ? 
                   AND created_at <= ? 
                   AND status IN ({$activeStatuses})",
                [$userId, $startOfMonth, $endOfMonth]
            );

            return $countRow ? (int)$countRow->cnt : 0;
        }

        // 2. In-memory counting fallback
        $count = 0;
        $activeStatuses = [
            WithdrawalStatus::PENDING,
            WithdrawalStatus::APPROVED,
            WithdrawalStatus::PROCESSING,
            WithdrawalStatus::PAID,
        ];

        foreach ($this->withdrawals as $w) {
            if ($w->getUserId() !== $userId) {
                continue;
            }
            if (!in_array($w->getStatus(), $activeStatuses, true)) {
                continue;
            }
            $createdAt = $w->getCreatedAt();
            $itemYm = substr($createdAt, 0, 7);
            if ($itemYm === $ym) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get remaining allowed withdrawals for a customer in the calendar month.
     */
    public function getRemainingMonthlyWithdrawals(int $userId, ?string $yearMonth = null): int
    {
        $settings = $this->getSettings();
        $maxCount = (int)($settings['max_monthly_count'] ?? 5);
        if ($maxCount <= 0) {
            return PHP_INT_MAX;
        }

        $used = $this->getMonthlyWithdrawalCount($userId, $yearMonth);
        return max(0, $maxCount - $used);
    }
protected function notifyCustomerOfStatusTransition(Withdrawal $withdrawal, WithdrawalStatus $targetStatus): void
    {
        $id = $withdrawal->getId();
        $formattedAmount = $withdrawal->getAmount()->format();
        $formattedNetAmount = $withdrawal->getNetAmount()->format();
        $method = strtoupper($withdrawal->getMethod());

        switch ($targetStatus) {
            case WithdrawalStatus::APPROVED:
                $this->notifyCustomerOfWithdrawalEvent(
                    $withdrawal,
                    NotificationServiceInterface::TYPE_WITHDRAWAL_APPROVED,
                    'Withdrawal Request Approved',
                    "Your withdrawal request ({$id}) for {$formattedAmount} has been approved."
                );
                break;

            case WithdrawalStatus::PROCESSING:
                $this->notifyCustomerOfWithdrawalEvent(
                    $withdrawal,
                    NotificationServiceInterface::TYPE_WITHDRAWAL_PROCESSING,
                    'Withdrawal Processing',
                    "Your withdrawal request ({$id}) for {$formattedAmount} via {$method} is now being processed."
                );
                break;

            case WithdrawalStatus::PAID:
                $this->notifyCustomerOfWithdrawalEvent(
                    $withdrawal,
                    NotificationServiceInterface::TYPE_WITHDRAWAL_PAID,
                    'Withdrawal Paid Successfully',
                    "Your withdrawal ({$id}) of {$formattedNetAmount} has been paid successfully."
                );
                break;

            case WithdrawalStatus::REJECTED:
                $this->notifyCustomerOfWithdrawalEvent(
                    $withdrawal,
                    NotificationServiceInterface::TYPE_WITHDRAWAL_REJECTED,
                    'Withdrawal Request Rejected',
                    "Your withdrawal request ({$id}) was rejected."
                );
                break;

            case WithdrawalStatus::CANCELLED:
                $this->notifyCustomerOfWithdrawalEvent(
                    $withdrawal,
                    NotificationServiceInterface::TYPE_WITHDRAWAL_CANCELLED,
                    'Withdrawal Request Cancelled',
                    "Your withdrawal request ({$id}) was cancelled."
                );
                break;

            case WithdrawalStatus::FAILED:
                $this->notifyCustomerOfWithdrawalEvent(
                    $withdrawal,
                    NotificationServiceInterface::TYPE_WITHDRAWAL_FAILED,
                    'Withdrawal Could Not Be Completed',
                    "Your withdrawal ({$id}) could not be completed."
                );
                break;
        }
    }

    protected function notifyCustomerOfWithdrawalEvent(
        Withdrawal $withdrawal,
        string $type,
        string $title,
        string $message
    ): void {
        if ($this->notificationService === null) {
            return;
        }

        try {
            $this->notificationService->notify(
                userId: $withdrawal->getUserId(),
                type: $type,
                title: $title,
                message: $message,
                withdrawalId: $withdrawal->getId(),
                data: [
                    'withdrawal_id' => $withdrawal->getId(),
                    'gross_amount'  => $withdrawal->getAmount()->getAmount(),
                    'fee'           => $withdrawal->getFee()->getAmount(),
                    'net_amount'    => $withdrawal->getNetAmount()->getAmount(),
                    'currency'      => $withdrawal->getCurrency(),
                    'method'        => $withdrawal->getMethod(),
                    'status'        => $withdrawal->getStatus()->value,
                ]
            );
        } catch (Throwable $e) {
            // Safe fallback: never break financial accounting or core operations if notification storage fails
            SafeLogger::error("Failed to record withdrawal customer notification: " . $e->getMessage(), [
                'withdrawal_id' => $withdrawal->getId(),
                'type'          => $type,
            ]);
        }
    }

    /**
     * Record operational audit log entry safely. Non-blocking.
     */
    private function logAudit(
        string $action,
        string $subjectType,
        ?string $subjectId,
        ?int $targetUserId,
        array $metadata,
        ?string $description = null,
        ?int $actorUserId = null,
        ?string $actorType = null,
        ?string $withdrawalId = null
    ): void {
        if ($this->auditService === null) {
            return;
        }

        try {
            $this->auditService->log(
                action: $action,
                subjectType: $subjectType,
                subjectId: $subjectId,
                targetUserId: $targetUserId,
                metadata: $metadata,
                description: $description,
                actorUserId: $actorUserId,
                actorType: $actorType,
                withdrawalId: $withdrawalId ?? $subjectId
            );
        } catch (\Throwable $e) {
            SafeLogger::error("Audit log failed in WithdrawalService: " . $e->getMessage(), [
                'action'        => $action,
                'withdrawal_id' => $subjectId,
            ]);
        }
    }
}
