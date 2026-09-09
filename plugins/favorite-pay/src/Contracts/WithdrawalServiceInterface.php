<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\Withdrawal;
use FavoriteCMS\Pay\Domain\WithdrawalStatus;

interface WithdrawalServiceInterface
{
    /**
     * Check whether customer withdrawals are enabled globally by administrator.
     */
    public function isWithdrawalEnabled(): bool;

    /**
     * Create a new withdrawal request for an authenticated customer.
     * Places authoritative wallet hold immediately upon creation.
     */
    public function createWithdrawal(
        int $userId,
        Money $amount,
        string $method,
        array $destinationData,
        ?string $idempotencyKey = null
    ): Withdrawal;

    /**
     * Retrieve a withdrawal by its canonical ID (wd_...).
     */
    public function getWithdrawal(string $id): ?Withdrawal;

    /**
     * List withdrawal requests matching optional filters.
     *
     * @param array $filters [status, user_id, method, search]
     * @return array{items: Withdrawal[], total: int, counts: array<string, int>}
     */
    public function listWithdrawals(array $filters = [], int $limit = 25, int $offset = 0): array;

    /**
     * List withdrawals belonging to a specific customer.
     *
     * @return Withdrawal[]
     */
    public function getUserWithdrawals(int $userId, int $limit = 25, int $offset = 0): array;

    /**
     * Approve a pending withdrawal.
     */
    public function approve(string $id, int $adminUserId, ?string $notes = null): Withdrawal;

    /**
     * Move an approved withdrawal to processing state.
     */
    public function startProcessing(string $id, int $adminUserId, ?string $notes = null): Withdrawal;

    /**
     * Mark a processing withdrawal as paid and permanently finalize wallet debit.
     * Idempotent: repeated calls do not duplicate wallet debits.
     */
    public function markPaid(
        string $id,
        int $adminUserId,
        ?string $transactionReference = null,
        ?string $notes = null
    ): Withdrawal;

    /**
     * Reject a pending withdrawal and release wallet hold back to customer balance.
     */
    public function reject(string $id, int $adminUserId, string $reason): Withdrawal;

    /**
     * Mark a processing withdrawal as failed and release wallet hold.
     */
    public function markFailed(string $id, int $adminUserId, string $reason): Withdrawal;

    /**
     * Cancel a withdrawal (by customer or administrator) and release wallet hold.
     */
    public function cancel(string $id, int $userId, string $reason = 'Cancelled by customer', bool $isAdmin = false): Withdrawal;

    /**
     * Update internal administrative processing notes for a withdrawal.
     */
    public function updateProcessingNotes(string $id, int $adminUserId, string $notes): Withdrawal;

    /**
     * Update external transaction / payout reference for a withdrawal.
     */
    public function updateTransactionReference(string $id, int $adminUserId, string $transactionReference): Withdrawal;

    /**
     * Retrieve the audit trail for a withdrawal.
     *
     * @return array<int, array{action: string, actor_id: int|null, prev_status: string|null, new_status: string|null, timestamp: string, metadata: array}>
     */
    public function getAuditTrail(string $id): array;

    /**
     * Retrieve withdrawal configuration settings.
     */
    public function getSettings(): array;

    /**
     * Update withdrawal configuration settings.
     */
    public function updateSettings(array $settings): void;

    /**
     * Get count of successful/active withdrawals for a customer in a given calendar month.
     * Default month is current calendar month (Y-m).
     */
    public function getMonthlyWithdrawalCount(int $userId, ?string $yearMonth = null): int;

    /**
     * Get remaining allowed withdrawals for a customer in the calendar month.
     */
    public function getRemainingMonthlyWithdrawals(int $userId, ?string $yearMonth = null): int;

    /**
     * Retrieve the notification service instance.
     */
    public function getNotificationService(): ?NotificationServiceInterface;

    /**
     * Set the notification service instance.
     */
    public function setNotificationService(NotificationServiceInterface $notificationService): void;

    /**
     * Get summary statistics (request counts and monetary totals) for withdrawals matching filters.
     *
     * @param array $filters Filtering options
     * @return array{
     *     counts: array<string, int>,
     *     totals: array{gross_cents: int, fee_cents: int, net_cents: int, paid_gross_cents: int, paid_net_cents: int, currency: string}
     * }
     */
    public function getSummary(array $filters = []): array;

    /**
     * Export withdrawals matching filters to customer-safe, formula-sanitized CSV string.
     *
     * @param array $filters Filtering options
     * @return string CSV content with UTF-8 BOM
     */
    public function exportWithdrawalsCsv(array $filters = []): string;
}
