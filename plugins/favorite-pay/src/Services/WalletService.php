<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Domain\WalletLedgerEntry;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Wallet Service
 *
 * Implements Configurable Primary Accounting Currency:
 * 1. Wallet ledger denominated in the site's Primary Accounting Currency (default: BDT).
 * 2. Foreign-currency deposits are converted to the target wallet currency and locked at deposit time.
 * 3. Strict overdraft prevention (balance >= debit).
 * 4. Idempotent payment settlement: exactly one wallet credit per successful payment in primary currency.
 * 5. Historical safety: existing wallets and financial records remain in their originally recorded currency.
 */
class WalletService implements WalletServiceInterface
{
    private CurrencyServiceInterface $currencyService;
    private ?PaymentServiceInterface $paymentService;
    private ?Database $db;

    /** @var array<int, int> User ID => balance in minor units */
    private array $balances = [];

    /** @var array<int, string> User ID => wallet currency code */
    private array $walletCurrencies = [];

    /** @var array<int, WalletLedgerEntry[]> User ID => list of ledger entries */
    private array $ledgers = [];

    /** @var array<int, int> User ID => held balance in minor units */
    private array $heldBalances = [];

    /** @var array<string, WalletLedgerEntry> Transaction ID => settlement ledger entry */
    private array $settledTransactions = [];

    public function __construct(
        CurrencyServiceInterface $currencyService,
        ?PaymentServiceInterface $paymentService = null,
        ?Database $db = null
    ) {
        $this->currencyService = $currencyService;
        $this->paymentService = $paymentService;
        $this->db = $db;
    }

    public function setPaymentService(PaymentServiceInterface $paymentService): void
    {
        $this->paymentService = $paymentService;
    }

    public function setDatabase(?Database $db): void
    {
        $this->db = $db;
    }

    /**
     * Get the site's authoritative Primary Accounting Currency.
     */
    public function getPrimaryCurrency(): string
    {
        return $this->currencyService->getBaseCurrency();
    }

    /**
     * Get the specific currency code for a customer's wallet.
     * Preserves existing wallet denomination even if the site primary currency changes.
     */
    public function getWalletCurrency(int $userId): string
    {
        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallets')) {
            $row = $this->db->selectOne(
                "SELECT currency FROM favorite_pay_wallets WHERE user_id = ?",
                [$userId]
            );
            if ($row && !empty($row->currency)) {
                return (string)$row->currency;
            }
        }

        return $this->walletCurrencies[$userId] ?? $this->getPrimaryCurrency();
    }

    public function getBalance(int $userId): Money
    {
        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallets')) {
            $row = $this->db->selectOne(
                "SELECT balance, currency FROM favorite_pay_wallets WHERE user_id = ?",
                [$userId]
            );
            if ($row) {
                $currency = !empty($row->currency) ? (string)$row->currency : $this->getPrimaryCurrency();
                return new Money((int)$row->balance, $currency);
            }
        }

        $amount = $this->balances[$userId] ?? 0;
        $currency = $this->walletCurrencies[$userId] ?? $this->getPrimaryCurrency();
        return new Money($amount, $currency);
    }

    public function getAvailableBalance(int $userId): Money
    {
        return $this->getBalance($userId);
    }

    public function getHeldBalance(int $userId): Money
    {
        $currency = $this->getWalletCurrency($userId);

        if ($this->db !== null && $this->db->tableExists('favorite_pay_withdrawals')) {
            $activeStatuses = "'" . implode("','", [
                \FavoriteCMS\Pay\Domain\WithdrawalStatus::PENDING->value,
                \FavoriteCMS\Pay\Domain\WithdrawalStatus::APPROVED->value,
                \FavoriteCMS\Pay\Domain\WithdrawalStatus::PROCESSING->value,
            ]) . "'";

            $row = $this->db->selectOne(
                "SELECT COALESCE(SUM(amount), 0) as total_held 
                 FROM favorite_pay_withdrawals 
                 WHERE user_id = ? AND status IN ({$activeStatuses})",
                [$userId]
            );
            if ($row !== null && isset($row->total_held)) {
                return new Money((int)$row->total_held, $currency);
            }
        }

        $held = $this->heldBalances[$userId] ?? 0;
        return new Money($held, $currency);
    }

    public function getTotalBalance(int $userId): Money
    {
        return $this->getAvailableBalance($userId)->add($this->getHeldBalance($userId));
    }

    public function deposit(
        int $userId,
        Money $amount,
        string $referenceId,
        string $description = ''
    ): WalletLedgerEntry {
        if (!$amount->isPositive()) {
            throw new InvalidArgumentException("Deposit amount must be strictly positive.");
        }

        $walletCurrency = $this->getWalletCurrency($userId);

        // Convert foreign currencies to wallet currency and lock at deposit time
        $targetAmount = $amount->getCurrency() === $walletCurrency
            ? $amount
            : $this->currencyService->convert($amount, $walletCurrency);

        $currentBalance = $this->getBalance($userId);
        $newBalance = $currentBalance->add($targetAmount);

        $this->balances[$userId] = $newBalance->getAmount();
        $this->walletCurrencies[$userId] = $walletCurrency;

        $entry = new WalletLedgerEntry(
            'led_' . bin2hex(random_bytes(8)),
            $userId,
            'credit',
            $targetAmount,
            $newBalance,
            'deposit',
            $referenceId,
            $description !== '' ? $description : "Deposit to wallet"
        );

        $this->ledgers[$userId][] = $entry;

        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallets') && $this->db->tableExists('favorite_pay_wallet_entries')) {
            $this->persistEntryAndBalance($entry, $newBalance);
        }

        if (function_exists('do_action')) {
            do_action('favorite.pay.wallet.credited', [
                'user_id'  => $userId,
                'amount'   => $targetAmount->getAmount(),
                'currency' => $targetAmount->getCurrency(),
                'balance'  => $newBalance->getAmount(),
            ]);
        }

        return $entry;
    }

    public function debit(
        int $userId,
        Money $amount,
        string $referenceId,
        string $description = ''
    ): WalletLedgerEntry {
        if (!$amount->isPositive()) {
            throw new InvalidArgumentException("Debit amount must be strictly positive.");
        }

        $walletCurrency = $this->getWalletCurrency($userId);

        // Must match wallet currency or convert
        $targetAmount = $amount->getCurrency() === $walletCurrency
            ? $amount
            : $this->currencyService->convert($amount, $walletCurrency);

        // Database-level concurrency and atomic balance check
        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallets')) {
            return $this->db->transaction(function (Database $db) use ($userId, $targetAmount, $walletCurrency, $referenceId, $description) {
                $wallet = $db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
                if (!$wallet || (int)$wallet->balance < $targetAmount->getAmount()) {
                    throw new RuntimeException("Insufficient wallet balance for user {$userId}.");
                }

                $stmt = $db->query(
                    "UPDATE favorite_pay_wallets SET balance = balance - ?, updated_at = ? WHERE id = ? AND balance >= ?",
                    [$targetAmount->getAmount(), date('Y-m-d H:i:s'), $wallet->id, $targetAmount->getAmount()]
                );
                $affected = $stmt->rowCount();
                if ($affected === 0) {
                    throw new RuntimeException("Insufficient wallet balance for user {$userId}.");
                }

                $updatedWallet = $db->selectOne("SELECT * FROM favorite_pay_wallets WHERE id = ?", [$wallet->id]);
                $newBalance = new Money((int)$updatedWallet->balance, $walletCurrency);
                $this->balances[$userId] = $newBalance->getAmount();
                $this->walletCurrencies[$userId] = $walletCurrency;

                $entry = new WalletLedgerEntry(
                    'led_' . bin2hex(random_bytes(8)),
                    $userId,
                    'debit',
                    $targetAmount,
                    $newBalance,
                    'purchase',
                    $referenceId,
                    $description !== '' ? $description : "Wallet debit"
                );

                $this->ledgers[$userId][] = $entry;

                if ($db->tableExists('favorite_pay_wallet_entries')) {
                    $db->insert('favorite_pay_wallet_entries', [
                        'entry_id'        => $entry->getId(),
                        'wallet_id'       => $updatedWallet->id,
                        'user_id'         => $userId,
                        'type'            => 'debit',
                        'amount'          => $targetAmount->getAmount(),
                        'balance_after'   => $newBalance->getAmount(),
                        'reference_type'  => 'purchase',
                        'reference_id'    => $referenceId,
                        'idempotency_key' => 'debit:' . $referenceId,
                        'description'     => $entry->getDescription(),
                        'metadata'        => json_encode(['currency' => $targetAmount->getCurrency()]),
                        'created_at'      => $entry->getCreatedAt(),
                    ]);
                }

                if (function_exists('do_action')) {
                    do_action('favorite.pay.wallet.debited', [
                        'user_id'  => $userId,
                        'amount'   => $targetAmount->getAmount(),
                        'currency' => $targetAmount->getCurrency(),
                        'balance'  => $newBalance->getAmount(),
                    ]);
                }

                return $entry;
            });
        }

        // In-memory fallback
        $currentBalance = $this->getBalance($userId);
        if ($currentBalance->lessThan($targetAmount)) {
            throw new RuntimeException("Insufficient wallet balance for user {$userId}.");
        }

        $newBalance = $currentBalance->subtract($targetAmount);
        $this->balances[$userId] = $newBalance->getAmount();
        $this->walletCurrencies[$userId] = $walletCurrency;

        $entry = new WalletLedgerEntry(
            'led_' . bin2hex(random_bytes(8)),
            $userId,
            'debit',
            $targetAmount,
            $newBalance,
            'purchase',
            $referenceId,
            $description !== '' ? $description : "Wallet debit"
        );

        $this->ledgers[$userId][] = $entry;
        return $entry;
    }

    public function hold(int $userId, Money $amount, string $referenceId): WalletLedgerEntry
    {
        $walletCurrency = $this->getWalletCurrency($userId);

        $targetAmount = $amount->getCurrency() === $walletCurrency
            ? $amount
            : $this->currencyService->convert($amount, $walletCurrency);

        // Database-level concurrency and atomic balance check
        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallets')) {
            return $this->db->transaction(function (Database $db) use ($userId, $targetAmount, $walletCurrency, $referenceId) {
                $wallet = $db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
                if (!$wallet) {
                    $db->insert('favorite_pay_wallets', [
                        'user_id'    => $userId,
                        'balance'    => 0,
                        'currency'   => $walletCurrency,
                        'status'     => 'active',
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $wallet = $db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
                }

                if ((int)$wallet->balance < $targetAmount->getAmount()) {
                    throw new RuntimeException("Insufficient balance to place hold for user {$userId}.");
                }

                // Atomic decrement strictly guarded by WHERE balance >= amount to prevent overdraft/races
                $stmt = $db->query(
                    "UPDATE favorite_pay_wallets SET balance = balance - ?, updated_at = ? WHERE id = ? AND balance >= ?",
                    [$targetAmount->getAmount(), date('Y-m-d H:i:s'), $wallet->id, $targetAmount->getAmount()]
                );
                $affected = $stmt->rowCount();

                if ($affected === 0) {
                    throw new RuntimeException("Insufficient balance to place hold for user {$userId}.");
                }

                $updatedWallet = $db->selectOne("SELECT * FROM favorite_pay_wallets WHERE id = ?", [$wallet->id]);
                $newBalance = new Money((int)$updatedWallet->balance, $walletCurrency);
                $this->balances[$userId] = $newBalance->getAmount();
                $this->walletCurrencies[$userId] = $walletCurrency;
                $this->heldBalances[$userId] = ($this->heldBalances[$userId] ?? 0) + $targetAmount->getAmount();

                $entry = new WalletLedgerEntry(
                    'led_' . bin2hex(random_bytes(8)),
                    $userId,
                    'hold',
                    $targetAmount,
                    $newBalance,
                    'hold',
                    $referenceId,
                    "Funds placed on hold"
                );

                $this->ledgers[$userId][] = $entry;

                if ($db->tableExists('favorite_pay_wallet_entries')) {
                    $db->insert('favorite_pay_wallet_entries', [
                        'entry_id'        => $entry->getId(),
                        'wallet_id'       => $updatedWallet->id,
                        'user_id'         => $userId,
                        'type'            => 'hold',
                        'amount'          => $targetAmount->getAmount(),
                        'balance_after'   => $newBalance->getAmount(),
                        'reference_type'  => 'hold',
                        'reference_id'    => $referenceId,
                        'idempotency_key' => 'hold:' . $referenceId,
                        'description'     => $entry->getDescription(),
                        'metadata'        => json_encode(['currency' => $targetAmount->getCurrency()]),
                        'created_at'      => $entry->getCreatedAt(),
                    ]);
                }

                return $entry;
            });
        }

        // In-memory fallback
        $currentBalance = $this->getBalance($userId);
        if ($currentBalance->lessThan($targetAmount)) {
            throw new RuntimeException("Insufficient balance to place hold for user {$userId}.");
        }

        $newBalance = $currentBalance->subtract($targetAmount);
        $this->balances[$userId] = $newBalance->getAmount();
        $this->walletCurrencies[$userId] = $walletCurrency;
        $this->heldBalances[$userId] = ($this->heldBalances[$userId] ?? 0) + $targetAmount->getAmount();

        $entry = new WalletLedgerEntry(
            'led_' . bin2hex(random_bytes(8)),
            $userId,
            'hold',
            $targetAmount,
            $newBalance,
            'hold',
            $referenceId,
            "Funds placed on hold"
        );

        $this->ledgers[$userId][] = $entry;
        return $entry;
    }

    public function releaseHold(int $userId, Money $amount, string $referenceId): WalletLedgerEntry
    {
        $walletCurrency = $this->getWalletCurrency($userId);

        $targetAmount = $amount->getCurrency() === $walletCurrency
            ? $amount
            : $this->currencyService->convert($amount, $walletCurrency);

        $currentBalance = $this->getBalance($userId);
        $newBalance = $currentBalance->add($targetAmount);
        $this->balances[$userId] = $newBalance->getAmount();
        $this->walletCurrencies[$userId] = $walletCurrency;
        $this->heldBalances[$userId] = max(0, ($this->heldBalances[$userId] ?? 0) - $targetAmount->getAmount());

        $entry = new WalletLedgerEntry(
            'led_' . bin2hex(random_bytes(8)),
            $userId,
            'release',
            $targetAmount,
            $newBalance,
            'release',
            $referenceId,
            "Hold released back to wallet"
        );

        $this->ledgers[$userId][] = $entry;

        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallets') && $this->db->tableExists('favorite_pay_wallet_entries')) {
            $this->persistEntryAndBalance($entry, $newBalance);
        }

        return $entry;
    }

    public function finalizeHold(
        int $userId,
        Money $amount,
        string $referenceId,
        string $description = 'Withdrawal payout completed'
    ): WalletLedgerEntry {
        $walletCurrency = $this->getWalletCurrency($userId);

        $targetAmount = $amount->getCurrency() === $walletCurrency
            ? $amount
            : $this->currencyService->convert($amount, $walletCurrency);

        $idempotencyKey = 'withdrawal:' . $referenceId;

        // 1. Fast in-memory idempotency check
        foreach ($this->ledgers[$userId] ?? [] as $existing) {
            if ($existing->getReferenceType() === 'withdrawal' && $existing->getReferenceId() === $referenceId) {
                return $existing;
            }
        }

        // 2. Database idempotency check
        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallet_entries')) {
            $row = $this->db->selectOne(
                "SELECT * FROM favorite_pay_wallet_entries 
                 WHERE (reference_type = 'withdrawal' AND reference_id = ?) 
                    OR idempotency_key = ? 
                 LIMIT 1",
                [$referenceId, $idempotencyKey]
            );
            if ($row) {
                return $this->hydrateLedgerEntry($row);
            }
        }

        // The hold already deducted the funds from the available balance, so balance_after is the current available balance
        $currentBalance = $this->getBalance($userId);
        $this->heldBalances[$userId] = max(0, ($this->heldBalances[$userId] ?? 0) - $targetAmount->getAmount());

        $entry = new WalletLedgerEntry(
            'led_' . bin2hex(random_bytes(8)),
            $userId,
            'debit',
            $targetAmount,
            $currentBalance,
            'withdrawal',
            $referenceId,
            $description
        );

        $this->ledgers[$userId][] = $entry;

        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallets') && $this->db->tableExists('favorite_pay_wallet_entries')) {
            $wallet = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
            if ($wallet) {
                $this->db->insert('favorite_pay_wallet_entries', [
                    'entry_id'        => $entry->getId(),
                    'wallet_id'       => $wallet->id,
                    'user_id'         => $userId,
                    'type'            => 'debit',
                    'amount'          => $targetAmount->getAmount(),
                    'balance_after'   => $currentBalance->getAmount(),
                    'reference_type'  => 'withdrawal',
                    'reference_id'    => $referenceId,
                    'idempotency_key' => $idempotencyKey,
                    'description'     => $description,
                    'metadata'        => json_encode(['currency' => $targetAmount->getCurrency()]),
                    'created_at'      => $entry->getCreatedAt(),
                ]);
            }
        }

        if (function_exists('do_action')) {
            do_action('favorite.pay.wallet.debited', [
                'user_id'        => $userId,
                'amount'         => $targetAmount->getAmount(),
                'currency'       => $targetAmount->getCurrency(),
                'balance'        => $currentBalance->getAmount(),
                'reference_type' => 'withdrawal',
                'reference_id'   => $referenceId,
            ]);
        }

        return $entry;
    }

    /**
     * Settles a verified successful payment transaction into the customer's wallet.
     * Idempotent: repeated calls for the same transaction ID do not double-credit.
     * Credits the authoritative base amount in the site's configured Primary Currency.
     */
    public function settleSuccessfulPayment(string $transactionId): WalletLedgerEntry
    {
        $trimmedId = trim($transactionId);
        if ($trimmedId === '') {
            throw new InvalidArgumentException("Transaction ID cannot be empty.");
        }

        // 1. Fast in-memory idempotency check
        if (isset($this->settledTransactions[$trimmedId])) {
            return $this->settledTransactions[$trimmedId];
        }

        // 2. Database idempotency check
        $idempotencyKey = 'settle:payment:' . $trimmedId;
        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallet_entries')) {
            $existing = $this->db->selectOne(
                "SELECT * FROM favorite_pay_wallet_entries 
                 WHERE (reference_type = 'payment' AND reference_id = ?) 
                    OR idempotency_key = ? 
                 LIMIT 1",
                [$trimmedId, $idempotencyKey]
            );

            if ($existing) {
                $entry = $this->hydrateLedgerEntry($existing);
                $this->settledTransactions[$trimmedId] = $entry;
                return $entry;
            }
        }

        // 3. Resolve authoritative transaction from PaymentService or Database
        $intent = $this->resolvePaymentIntent($trimmedId);
        if (!$intent) {
            throw new InvalidArgumentException("Payment transaction not found: {$trimmedId}");
        }

        // 4. Verification rules
        // User check
        $userId = $intent->getUserId();
        if ($userId === null || $userId <= 0) {
            throw new InvalidArgumentException("Transaction '{$trimmedId}' has no associated customer user ID for wallet settlement.");
        }

        // Status check: Must be SUCCEEDED
        if ($intent->getStatus() !== PaymentStatus::SUCCEEDED) {
            throw new RuntimeException(
                "Cannot settle payment in status '{$intent->getStatus()->value}': transaction must be succeeded."
            );
        }

        // Amount check: Authoritative base accounting amount
        $baseAmount = $intent->getBaseAmount();
        if (!$baseAmount->isPositive()) {
            throw new InvalidArgumentException("Transaction base amount must be strictly positive.");
        }

        $primaryCurrency = $this->getPrimaryCurrency();
        if ($baseAmount->getCurrency() !== $primaryCurrency) {
            throw new InvalidArgumentException(
                "Transaction base currency '{$baseAmount->getCurrency()}' does not match primary currency '{$primaryCurrency}'."
            );
        }

        // 5. Database atomic settlement
        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallets') && $this->db->tableExists('favorite_pay_wallet_entries')) {
            $entry = $this->settleInDatabase($intent, $trimmedId, $userId, $baseAmount, $idempotencyKey);
            $this->settledTransactions[$trimmedId] = $entry;
            return $entry;
        }

        // 6. In-memory settlement fallback (for unit tests without database)
        $walletCurrency = $this->walletCurrencies[$userId] ?? $baseAmount->getCurrency();
        if ($walletCurrency !== $baseAmount->getCurrency()) {
            throw new RuntimeException(
                "Cannot settle payment: wallet currency '{$walletCurrency}' does not match transaction base currency '{$baseAmount->getCurrency()}'."
            );
        }

        $this->walletCurrencies[$userId] = $walletCurrency;
        $currentBalance = $this->getBalance($userId);
        $newBalance = $currentBalance->add($baseAmount);
        $this->balances[$userId] = $newBalance->getAmount();

        $entryId = 'led_' . bin2hex(random_bytes(8));
        $entry = new WalletLedgerEntry(
            $entryId,
            $userId,
            'credit',
            $baseAmount,
            $newBalance,
            'payment',
            $trimmedId,
            "Wallet settlement for payment {$trimmedId}"
        );

        $this->ledgers[$userId][] = $entry;
        $this->settledTransactions[$trimmedId] = $entry;

        if (function_exists('do_action')) {
            do_action('favorite.pay.wallet.credited', [
                'user_id'  => $userId,
                'amount'   => $baseAmount->getAmount(),
                'currency' => $baseAmount->getCurrency(),
                'balance'  => $newBalance->getAmount(),
            ]);
        }

        return $entry;
    }

    private function settleInDatabase(
        PaymentIntent $intent,
        string $transactionId,
        int $userId,
        Money $baseAmount,
        string $idempotencyKey
    ): WalletLedgerEntry {
        return $this->db->transaction(function (Database $db) use ($intent, $transactionId, $userId, $baseAmount, $idempotencyKey) {
            // Double-check inside transaction
            $existing = $db->selectOne(
                "SELECT * FROM favorite_pay_wallet_entries 
                 WHERE (reference_type = 'payment' AND reference_id = ?) 
                    OR idempotency_key = ? 
                 LIMIT 1",
                [$transactionId, $idempotencyKey]
            );

            if ($existing) {
                return $this->hydrateLedgerEntry($existing);
            }

            // Ensure customer wallet exists
            $wallet = $db->selectOne(
                "SELECT * FROM favorite_pay_wallets WHERE user_id = ?",
                [$userId]
            );

            if (!$wallet) {
                $db->insert('favorite_pay_wallets', [
                    'user_id'    => $userId,
                    'balance'    => 0,
                    'currency'   => $baseAmount->getCurrency(),
                    'status'     => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                $wallet = $db->selectOne(
                    "SELECT * FROM favorite_pay_wallets WHERE user_id = ?",
                    [$userId]
                );
            }

            // Safety guard: Existing wallet currency must match transaction base currency
            if ($wallet->currency !== $baseAmount->getCurrency()) {
                throw new RuntimeException(
                    "Cannot settle payment: wallet currency '{$wallet->currency}' does not match transaction base currency '{$baseAmount->getCurrency()}'. Automatic wallet currency conversion is not permitted."
                );
            }

            $currentBalanceMinor = (int)$wallet->balance;
            $newBalanceMinor = $currentBalanceMinor + $baseAmount->getAmount();

            $entryId = 'led_' . bin2hex(random_bytes(8));
            $now = date('Y-m-d H:i:s');

            // Append-only ledger record
            $db->insert('favorite_pay_wallet_entries', [
                'entry_id'        => $entryId,
                'wallet_id'       => $wallet->id,
                'user_id'         => $userId,
                'type'            => 'credit',
                'amount'          => $baseAmount->getAmount(),
                'balance_after'   => $newBalanceMinor,
                'reference_type'  => 'payment',
                'reference_id'    => $transactionId,
                'idempotency_key' => $idempotencyKey,
                'description'     => "Payment settlement for transaction {$transactionId}",
                'metadata'        => json_encode([
                    'source_plugin'    => $intent->getSourcePlugin(),
                    'source_reference' => $intent->getSourceReference(),
                    'base_currency'    => $baseAmount->getCurrency(),
                    'charge_currency'  => $intent->getChargeAmount()->getCurrency(),
                    'charge_amount'    => $intent->getChargeAmount()->getAmount(),
                ]),
                'created_at'      => $now,
            ]);

            // Update wallet balance
            $db->update('favorite_pay_wallets', [
                'balance'    => $newBalanceMinor,
                'updated_at' => $now,
            ], ['id' => $wallet->id]);

            $newBalance = new Money($newBalanceMinor, $wallet->currency);

            if (function_exists('do_action')) {
                do_action('favorite.pay.wallet.credited', [
                    'user_id'  => $userId,
                    'amount'   => $baseAmount->getAmount(),
                    'currency' => $wallet->currency,
                    'balance'  => $newBalanceMinor,
                ]);
            }

            return new WalletLedgerEntry(
                $entryId,
                $userId,
                'credit',
                $baseAmount,
                $newBalance,
                'payment',
                $transactionId,
                "Payment settlement for transaction {$transactionId}",
                $now
            );
        });
    }

    private function persistEntryAndBalance(WalletLedgerEntry $entry, Money $newBalance): void
    {
        $this->db->transaction(function (Database $db) use ($entry, $newBalance) {
            $userId = $entry->getUserId();
            $wallet = $db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
            if (!$wallet) {
                $currency = $entry->getAmount()->getCurrency();
                $db->insert('favorite_pay_wallets', [
                    'user_id'    => $userId,
                    'balance'    => 0,
                    'currency'   => $currency,
                    'status'     => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $wallet = $db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
            }

            $db->insert('favorite_pay_wallet_entries', [
                'entry_id'        => $entry->getId(),
                'wallet_id'       => $wallet->id,
                'user_id'         => $userId,
                'type'            => $entry->getType(),
                'amount'          => $entry->getAmount()->getAmount(),
                'balance_after'   => $newBalance->getAmount(),
                'reference_type'  => $entry->getReferenceType(),
                'reference_id'    => $entry->getReferenceId(),
                'idempotency_key' => 'op:' . bin2hex(random_bytes(12)),
                'description'     => $entry->getDescription(),
                'metadata'        => json_encode([
                    'currency' => $entry->getAmount()->getCurrency(),
                ]),
                'created_at'      => $entry->getCreatedAt(),
            ]);

            $db->update('favorite_pay_wallets', [
                'balance'    => $newBalance->getAmount(),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $wallet->id]);
        });
    }

    private function hydrateLedgerEntry(object $row): WalletLedgerEntry
    {
        $currency = null;
        if (!empty($row->metadata)) {
            $meta = json_decode((string)$row->metadata, true);
            $currency = $meta['base_currency'] ?? $meta['currency'] ?? null;
        }
        if ($currency === null && isset($row->currency)) {
            $currency = (string)$row->currency;
        }
        if ($currency === null) {
            $currency = $this->getWalletCurrency((int)$row->user_id);
        }

        return new WalletLedgerEntry(
            (string)$row->entry_id,
            (int)$row->user_id,
            (string)$row->type,
            new Money((int)$row->amount, $currency),
            new Money((int)$row->balance_after, $currency),
            (string)$row->reference_type,
            (string)$row->reference_id,
            (string)($row->description ?? ''),
            (string)$row->created_at
        );
    }

    private function resolvePaymentIntent(string $transactionId): ?PaymentIntent
    {
        if ($this->paymentService !== null) {
            return $this->paymentService->getIntent($transactionId);
        }

        if (function_exists('app')) {
            try {
                $ps = app(PaymentServiceInterface::class);
                if ($ps instanceof PaymentServiceInterface) {
                    return $ps->getIntent($transactionId);
                }
            } catch (Throwable) {
            }
        }

        // Direct DB fallback if tables exist
        if ($this->db !== null && $this->db->tableExists('favorite_pay_transactions')) {
            $row = $this->db->selectOne(
                "SELECT * FROM favorite_pay_transactions WHERE transaction_id = ?",
                [$transactionId]
            );

            if ($row) {
                return new PaymentIntent(
                    (string)$row->transaction_id,
                    (string)$row->source_plugin,
                    (string)$row->source_reference,
                    new Money((int)$row->base_amount, (string)$row->base_currency),
                    new Money((int)$row->charge_amount, (string)$row->charge_currency),
                    PaymentStatus::from((string)$row->status),
                    !empty($row->payment_method_type) ? PaymentMethodType::from((string)$row->payment_method_type) : null,
                    $row->user_id ? (int)$row->user_id : null
                );
            }
        }

        return null;
    }

    public function getLedgerHistory(int $userId, int $limit = 50, int $offset = 0): array
    {
        return $this->getFilteredLedgerHistory($userId, [], $limit, $offset);
    }

    /**
     * Filter and paginate transaction ledger history server-side.
     *
     * @param array $filters [type, direction, date_from, date_to, search, status]
     * @return WalletLedgerEntry[]
     */
    public function getFilteredLedgerHistory(int $userId, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallet_entries')) {
            [$where, $params] = $this->buildLedgerFilterConditions($userId, $filters);
            $whereSql = implode(' AND ', $where);

            $rows = $this->db->select(
                "SELECT * FROM favorite_pay_wallet_entries 
                 WHERE {$whereSql} 
                 ORDER BY id DESC 
                 LIMIT {$limit} OFFSET {$offset}",
                $params
            );

            $entries = [];
            foreach ($rows as $row) {
                $entries[] = $this->hydrateLedgerEntry($row);
            }
            return $entries;
        }

        $entries = $this->filterInMemoryLedger($userId, $filters);
        return array_slice($entries, $offset, $limit);
    }

    /**
     * Count total entries matching filters for server-side pagination.
     *
     * @param array $filters [type, direction, date_from, date_to, search, status]
     */
    public function getFilteredLedgerCount(int $userId, array $filters = []): int
    {
        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallet_entries')) {
            [$where, $params] = $this->buildLedgerFilterConditions($userId, $filters);
            $whereSql = implode(' AND ', $where);

            $row = $this->db->selectOne(
                "SELECT COUNT(*) as cnt FROM favorite_pay_wallet_entries WHERE {$whereSql}",
                $params
            );
            return $row ? (int)$row->cnt : 0;
        }

        $entries = $this->filterInMemoryLedger($userId, $filters);
        return count($entries);
    }

    /**
     * Retrieve a specific ledger entry optionally scoped to user ID for strict IDOR protection.
     */
    public function getLedgerEntry(string $entryId, ?int $userId = null): ?WalletLedgerEntry
    {
        $cleanId = trim($entryId);
        if ($cleanId === '') {
            return null;
        }

        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallet_entries')) {
            if ($userId !== null) {
                $row = $this->db->selectOne(
                    "SELECT * FROM favorite_pay_wallet_entries 
                     WHERE (entry_id = ? OR reference_id = ?) AND user_id = ? 
                     LIMIT 1",
                    [$cleanId, $cleanId, $userId]
                );
            } else {
                $row = $this->db->selectOne(
                    "SELECT * FROM favorite_pay_wallet_entries 
                     WHERE (entry_id = ? OR reference_id = ?) 
                     LIMIT 1",
                    [$cleanId, $cleanId]
                );
            }
            if ($row) {
                return $this->hydrateLedgerEntry($row);
            }
            return null;
        }

        if ($userId !== null) {
            $userEntries = $this->ledgers[$userId] ?? [];
            foreach ($userEntries as $e) {
                if ($e->getId() === $cleanId || $e->getReferenceId() === $cleanId) {
                    return $e;
                }
            }
        } else {
            foreach ($this->ledgers as $uid => $userEntries) {
                foreach ($userEntries as $e) {
                    if ($e->getId() === $cleanId || $e->getReferenceId() === $cleanId) {
                        return $e;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Build parameterized WHERE clauses and bindings for wallet entries filtering.
     * All filters combine with strict AND semantics.
     *
     * @param array $filters
     * @return array{0: string[], 1: array}
     */
    private function buildLedgerFilterConditions(int $userId, array $filters): array
    {
        $where = ['user_id = ?'];
        $params = [$userId];

        // 1. Direction Filter (credit, debit, hold, release)
        if (!empty($filters['direction']) && $filters['direction'] !== 'all') {
            $dir = strtolower(trim((string)$filters['direction']));
            if ($dir === 'credit') {
                $where[] = "type IN ('credit', 'release')";
            } elseif ($dir === 'debit') {
                $where[] = "type IN ('debit', 'hold', 'finalize')";
            } elseif (in_array($dir, ['hold', 'release'], true)) {
                $where[] = 'type = ?';
                $params[] = $dir;
            }
        }

        // 2. Type Filter (matches either entry type or reference_type)
        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $t = strtolower(trim((string)$filters['type']));
            if (in_array($t, ['credit', 'debit', 'hold', 'release'], true)) {
                $where[] = 'type = ?';
                $params[] = $t;
            } elseif (in_array($t, ['payment', 'withdrawal', 'deposit', 'purchase', 'refund', 'adjustment'], true)) {
                $where[] = 'reference_type = ?';
                $params[] = $t;
            }
        }

        // 3. Date Range: date_from
        if (!empty($filters['date_from'])) {
            $df = trim((string)$filters['date_from']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $df)) {
                $where[] = 'created_at >= ?';
                $params[] = $df . ' 00:00:00';
            }
        }

        // 4. Date Range: date_to
        if (!empty($filters['date_to'])) {
            $dt = trim((string)$filters['date_to']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
                $where[] = 'created_at <= ?';
                $params[] = $dt . ' 23:59:59';
            }
        }

        // 5. Search / Reference
        if (!empty($filters['search'])) {
            $search = trim((string)$filters['search']);
            if ($search !== '') {
                $wild = '%' . $search . '%';
                $where[] = '(entry_id LIKE ? OR reference_id LIKE ? OR description LIKE ?)';
                $params[] = $wild;
                $params[] = $wild;
                $params[] = $wild;
            }
        }

        return [$where, $params];
    }

    /**
     * Filter in-memory ledger array for test isolation / non-database runs.
     *
     * @param array $filters
     * @return WalletLedgerEntry[]
     */
    private function filterInMemoryLedger(int $userId, array $filters): array
    {
        $entries = array_reverse($this->ledgers[$userId] ?? []);

        if (!empty($filters['direction']) && $filters['direction'] !== 'all') {
            $dir = strtolower(trim((string)$filters['direction']));
            if ($dir === 'credit') {
                $entries = array_filter($entries, fn(WalletLedgerEntry $e) => in_array(strtolower($e->getType()), ['credit', 'release'], true));
            } elseif ($dir === 'debit') {
                $entries = array_filter($entries, fn(WalletLedgerEntry $e) => in_array(strtolower($e->getType()), ['debit', 'hold', 'finalize'], true));
            } elseif (in_array($dir, ['hold', 'release'], true)) {
                $entries = array_filter($entries, fn(WalletLedgerEntry $e) => strtolower($e->getType()) === $dir);
            }
        }

        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $t = strtolower(trim((string)$filters['type']));
            if (in_array($t, ['credit', 'debit', 'hold', 'release'], true)) {
                $entries = array_filter($entries, fn(WalletLedgerEntry $e) => $e->getType() === $t);
            } elseif (in_array($t, ['payment', 'withdrawal', 'deposit', 'purchase', 'refund', 'adjustment'], true)) {
                $entries = array_filter($entries, fn(WalletLedgerEntry $e) => $e->getReferenceType() === $t);
            }
        }

        if (!empty($filters['date_from'])) {
            $df = trim((string)$filters['date_from']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $df)) {
                $start = $df . ' 00:00:00';
                $entries = array_filter($entries, fn(WalletLedgerEntry $e) => $e->getCreatedAt() >= $start);
            }
        }

        if (!empty($filters['date_to'])) {
            $dt = trim((string)$filters['date_to']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
                $end = $dt . ' 23:59:59';
                $entries = array_filter($entries, fn(WalletLedgerEntry $e) => $e->getCreatedAt() <= $end);
            }
        }

        if (!empty($filters['search'])) {
            $search = strtolower(trim((string)$filters['search']));
            if ($search !== '') {
                $entries = array_filter($entries, function (WalletLedgerEntry $e) use ($search) {
                    return str_contains(strtolower($e->getId()), $search)
                        || str_contains(strtolower($e->getReferenceId()), $search)
                        || str_contains(strtolower($e->getDescription()), $search);
                });
            }
        }

        return array_values($entries);
    }

    public function hasWallets(): bool
    {
        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallets')) {
            $row = $this->db->selectOne("SELECT 1 FROM favorite_pay_wallets LIMIT 1");
            if ($row !== null) {
                return true;
            }
        }

        return !empty($this->balances) || !empty($this->walletCurrencies);
    }

    public function hasLedgerEntries(): bool
    {
        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallet_entries')) {
            $row = $this->db->selectOne("SELECT 1 FROM favorite_pay_wallet_entries LIMIT 1");
            if ($row !== null) {
                return true;
            }
        }

        return !empty($this->ledgers) || !empty($this->settledTransactions);
    }

    public function hasActivity(): bool
    {
        return $this->hasWallets() || $this->hasLedgerEntries();
    }

    /**
     * Get aggregate global wallet metrics across all customers based on authoritative data.
     * Never sums ledger history to calculate current balance.
     *
     * @return array{
     *     total_wallets: int,
     *     total_balance: Money,
     *     available_balance: Money,
     *     held_balance: Money,
     *     currency: string
     * }
     */
    public function getGlobalWalletOverview(): array
    {
        $primaryCurrency = $this->getPrimaryCurrency();

        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallets')) {
            $walletRow = $this->db->selectOne(
                "SELECT COUNT(*) as total_wallets, COALESCE(SUM(balance), 0) as total_avail FROM favorite_pay_wallets"
            );
            $totalWallets = (int)($walletRow->total_wallets ?? 0);
            $availCents = (int)($walletRow->total_avail ?? 0);

            $heldCents = 0;
            if ($this->db->tableExists('favorite_pay_withdrawals')) {
                $heldRow = $this->db->selectOne(
                    "SELECT COALESCE(SUM(amount), 0) as total_held FROM favorite_pay_withdrawals WHERE status IN ('pending', 'approved', 'processing')"
                );
                $heldCents = (int)($heldRow->total_held ?? 0);
            }

            $availMoney = new Money($availCents, $primaryCurrency);
            $heldMoney = new Money($heldCents, $primaryCurrency);
            $totalMoney = $availMoney->add($heldMoney);

            return [
                'total_wallets'     => $totalWallets,
                'available_balance' => $availMoney,
                'held_balance'      => $heldMoney,
                'total_balance'     => $totalMoney,
                'currency'          => $primaryCurrency,
            ];
        }

        // In-memory fallback
        $totalWallets = count($this->balances);
        $availCents = array_sum($this->balances);
        $heldCents = array_sum($this->heldBalances);

        $availMoney = new Money((int)$availCents, $primaryCurrency);
        $heldMoney = new Money((int)$heldCents, $primaryCurrency);
        $totalMoney = $availMoney->add($heldMoney);

        return [
            'total_wallets'     => $totalWallets,
            'available_balance' => $availMoney,
            'held_balance'      => $heldMoney,
            'total_balance'     => $totalMoney,
            'currency'          => $primaryCurrency,
        ];
    }

    /**
     * Search customer wallets by user ID, username, or email.
     *
     * @return array<int, array{
     *     user_id: int,
     *     username: string,
     *     email: string,
     *     status: string,
     *     available_balance: Money,
     *     held_balance: Money,
     *     total_balance: Money
     * }>
     */
    public function searchCustomerWallets(string $query, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $trimmed = trim($query);
        $results = [];

        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallets')) {
            $hasUsers = $this->db->tableExists('users');
            if ($trimmed === '') {
                if ($hasUsers) {
                    $rows = $this->db->select(
                        "SELECT w.user_id, w.balance, w.currency, w.status as wallet_status, u.username, u.email, u.status as user_status
                         FROM favorite_pay_wallets w
                         LEFT JOIN users u ON u.id = w.user_id
                         ORDER BY w.id DESC LIMIT {$limit}"
                    );
                } else {
                    $rows = $this->db->select(
                        "SELECT user_id, balance, currency, status as wallet_status FROM favorite_pay_wallets ORDER BY id DESC LIMIT {$limit}"
                    );
                }
            } else {
                $params = [];
                $isNumeric = is_numeric($trimmed);
                $wild = '%' . $trimmed . '%';

                if ($hasUsers) {
                    if ($isNumeric) {
                        $sql = "SELECT w.user_id, w.balance, w.currency, w.status as wallet_status, u.username, u.email, u.status as user_status
                                FROM favorite_pay_wallets w
                                LEFT JOIN users u ON u.id = w.user_id
                                WHERE w.user_id = ? OR u.username LIKE ? OR u.email LIKE ?
                                ORDER BY w.id DESC LIMIT {$limit}";
                        $params = [(int)$trimmed, $wild, $wild];
                    } else {
                        $sql = "SELECT w.user_id, w.balance, w.currency, w.status as wallet_status, u.username, u.email, u.status as user_status
                                FROM favorite_pay_wallets w
                                LEFT JOIN users u ON u.id = w.user_id
                                WHERE u.username LIKE ? OR u.email LIKE ?
                                ORDER BY w.id DESC LIMIT {$limit}";
                        $params = [$wild, $wild];
                    }
                    $rows = $this->db->select($sql, $params);
                } else {
                    $sql = "SELECT user_id, balance, currency, status as wallet_status FROM favorite_pay_wallets WHERE user_id = ? ORDER BY id DESC LIMIT {$limit}";
                    $rows = $isNumeric ? $this->db->select($sql, [(int)$trimmed]) : [];
                }
            }

            foreach ($rows as $r) {
                $uid = (int)$r->user_id;
                $avail = $this->getAvailableBalance($uid);
                $held = $this->getHeldBalance($uid);
                $total = $this->getTotalBalance($uid);

                $results[] = [
                    'user_id'           => $uid,
                    'username'          => (string)($r->username ?? ('User #' . $uid)),
                    'email'             => (string)($r->email ?? '—'),
                    'status'            => (string)($r->user_status ?? $r->wallet_status ?? 'active'),
                    'available_balance' => $avail,
                    'held_balance'      => $held,
                    'total_balance'     => $total,
                ];
            }
            return $results;
        }

        // In-memory fallback
        foreach ($this->balances as $uid => $balanceCents) {
            $username = 'user' . $uid;
            $email = 'user' . $uid . '@example.com';
            $status = 'active';

            if (isset($GLOBALS['_test_current_user']) && (int)$GLOBALS['_test_current_user']->id === $uid) {
                $username = (string)($GLOBALS['_test_current_user']->username ?? $username);
                $email = (string)($GLOBALS['_test_current_user']->email ?? $email);
                $status = (string)($GLOBALS['_test_current_user']->status ?? $status);
            }

            if ($trimmed !== '') {
                $matchesId = is_numeric($trimmed) && (int)$trimmed === $uid;
                $matchesUser = str_contains(strtolower($username), strtolower($trimmed));
                $matchesEmail = str_contains(strtolower($email), strtolower($trimmed));
                if (!$matchesId && !$matchesUser && !$matchesEmail) {
                    continue;
                }
            }

            $avail = $this->getAvailableBalance($uid);
            $held = $this->getHeldBalance($uid);
            $total = $this->getTotalBalance($uid);

            $results[] = [
                'user_id'           => $uid,
                'username'          => $username,
                'email'             => $email,
                'status'            => $status,
                'available_balance' => $avail,
                'held_balance'      => $held,
                'total_balance'     => $total,
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Get latest bounded financial ledger movements across all customers.
     *
     * @return array<int, array{
     *     entry: WalletLedgerEntry,
     *     username: string,
     *     email: string
     * }>
     */
    public function getGlobalRecentActivity(int $limit = 15): array
    {
        $limit = max(1, min(50, $limit));

        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallet_entries')) {
            $hasUsers = $this->db->tableExists('users');
            if ($hasUsers) {
                $rows = $this->db->select(
                    "SELECT e.*, u.username, u.email 
                     FROM favorite_pay_wallet_entries e
                     LEFT JOIN users u ON u.id = e.user_id
                     ORDER BY e.id DESC 
                     LIMIT {$limit}"
                );
            } else {
                $rows = $this->db->select(
                    "SELECT * FROM favorite_pay_wallet_entries ORDER BY id DESC LIMIT {$limit}"
                );
            }

            $items = [];
            foreach ($rows as $row) {
                $entry = $this->hydrateLedgerEntry($row);
                $items[] = [
                    'entry'    => $entry,
                    'username' => (string)($row->username ?? ('User #' . $entry->getUserId())),
                    'email'    => (string)($row->email ?? '—'),
                ];
            }
            return $items;
        }

        // In-memory fallback
        $allEntries = [];
        foreach ($this->ledgers as $uid => $userEntries) {
            foreach ($userEntries as $entry) {
                $allEntries[] = $entry;
            }
        }

        usort($allEntries, fn($a, $b) => strcmp($b->getCreatedAt(), $a->getCreatedAt()) ?: strcmp($b->getId(), $a->getId()));
        $sliced = array_slice($allEntries, 0, $limit);

        $items = [];
        foreach ($sliced as $entry) {
            $uid = $entry->getUserId();
            $username = 'user' . $uid;
            $email = 'user' . $uid . '@example.com';
            if (isset($GLOBALS['_test_current_user']) && (int)$GLOBALS['_test_current_user']->id === $uid) {
                $username = (string)($GLOBALS['_test_current_user']->username ?? $username);
                $email = (string)($GLOBALS['_test_current_user']->email ?? $email);
            }
            $items[] = [
                'entry'    => $entry,
                'username' => $username,
                'email'    => $email,
            ];
        }

        return $items;
    }
}
