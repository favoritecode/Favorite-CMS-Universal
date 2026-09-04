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

        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallets') && $this->db->tableExists('favorite_pay_wallet_entries')) {
            $this->persistEntryAndBalance($entry, $newBalance);
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
    }

    public function hold(int $userId, Money $amount, string $referenceId): WalletLedgerEntry
    {
        $walletCurrency = $this->getWalletCurrency($userId);

        $targetAmount = $amount->getCurrency() === $walletCurrency
            ? $amount
            : $this->currencyService->convert($amount, $walletCurrency);

        $currentBalance = $this->getBalance($userId);
        if ($currentBalance->lessThan($targetAmount)) {
            throw new RuntimeException("Insufficient balance to place hold for user {$userId}.");
        }

        $newBalance = $currentBalance->subtract($targetAmount);
        $this->balances[$userId] = $newBalance->getAmount();
        $this->walletCurrencies[$userId] = $walletCurrency;

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

        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallets') && $this->db->tableExists('favorite_pay_wallet_entries')) {
            $this->persistEntryAndBalance($entry, $newBalance);
        }

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
        $userId = $entry->getUserId();
        $wallet = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
        if (!$wallet) {
            $currency = $entry->getAmount()->getCurrency();
            $this->db->insert('favorite_pay_wallets', [
                'user_id'    => $userId,
                'balance'    => 0,
                'currency'   => $currency,
                'status'     => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $wallet = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
        }

        $this->db->insert('favorite_pay_wallet_entries', [
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

        $this->db->update('favorite_pay_wallets', [
            'balance'    => $newBalance->getAmount(),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $wallet->id]);
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
        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallet_entries')) {
            $rows = $this->db->select(
                "SELECT * FROM favorite_pay_wallet_entries 
                 WHERE user_id = ? 
                 ORDER BY id DESC 
                 LIMIT {$limit} OFFSET {$offset}",
                [$userId]
            );

            $entries = [];
            foreach ($rows as $row) {
                $entries[] = $this->hydrateLedgerEntry($row);
            }
            return $entries;
        }

        $list = $this->ledgers[$userId] ?? [];
        return array_slice(array_reverse($list), $offset, $limit);
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
}
