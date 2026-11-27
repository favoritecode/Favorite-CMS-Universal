<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\WalletLedgerEntry;

interface WalletServiceInterface
{
    public function getBalance(int $userId): Money;

    public function getAvailableBalance(int $userId): Money;

    /**
     * Deposits funds into customer wallet.
     * Foreign-currency deposits are converted to BDT and locked at deposit time.
     */
    public function deposit(
        int $userId,
        Money $amount,
        string $referenceId,
        string $description = ''
    ): WalletLedgerEntry;

    public function debit(
        int $userId,
        Money $amount,
        string $referenceId,
        string $description = ''
    ): WalletLedgerEntry;

    public function hold(
        int $userId,
        Money $amount,
        string $referenceId
    ): WalletLedgerEntry;

    public function releaseHold(
        int $userId,
        Money $amount,
        string $referenceId
    ): WalletLedgerEntry;

    /**
     * Finalizes an active hold into a permanent debit ledger entry.
     * Idempotent: repeated calls for the same reference ID do not double-debit.
     */
    public function finalizeHold(
        int $userId,
        Money $amount,
        string $referenceId,
        string $description = 'Withdrawal payout completed'
    ): WalletLedgerEntry;

    /**
     * Settles a verified successful payment transaction into the customer's BDT wallet.
     * Idempotent: repeated calls for the same transaction ID do not double-credit.
     *
     * @param string $transactionId The authoritative Favorite Pay transaction ID.
     * @return WalletLedgerEntry The authoritative ledger entry for this settlement.
     */
    public function settleSuccessfulPayment(string $transactionId): WalletLedgerEntry;

    /**
     * @return WalletLedgerEntry[]
     */
    public function getLedgerHistory(int $userId, int $limit = 50, int $offset = 0): array;

    /**
     * Get the total held/reserved balance for a user (e.g. pending withdrawals).
     */
    public function getHeldBalance(int $userId): Money;

    /**
     * Get the total wallet balance for a user (available + held).
     */
    public function getTotalBalance(int $userId): Money;

    /**
     * Filter and paginate transaction ledger history server-side.
     *
     * @param array $filters [type, direction, date_from, date_to, search, status]
     * @return WalletLedgerEntry[]
     */
    public function getFilteredLedgerHistory(int $userId, array $filters = [], int $limit = 20, int $offset = 0): array;

    /**
     * Count total entries matching filters for pagination.
     *
     * @param array $filters [type, direction, date_from, date_to, search, status]
     */
    public function getFilteredLedgerCount(int $userId, array $filters = []): int;

    /**
     * Retrieve a specific ledger entry scoped to user ID for IDOR protection.
     */
    public function getLedgerEntry(string $entryId, ?int $userId = null): ?WalletLedgerEntry;

    /**
     * Get aggregate global wallet metrics across all customers.
     *
     * @return array{
     *     total_wallets: int,
     *     total_balance: Money,
     *     available_balance: Money,
     *     held_balance: Money,
     *     currency: string
     * }
     */
    public function getGlobalWalletOverview(): array;

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
    public function searchCustomerWallets(string $query, int $limit = 20): array;

    /**
     * Get latest bounded financial ledger movements across all customers.
     *
     * @return array<int, array{
     *     entry: WalletLedgerEntry,
     *     username: string,
     *     email: string
     * }>
     */
    public function getGlobalRecentActivity(int $limit = 15): array;
}
