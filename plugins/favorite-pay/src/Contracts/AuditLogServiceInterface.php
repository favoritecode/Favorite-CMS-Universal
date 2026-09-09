<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

/**
 * Interface AuditLogServiceInterface
 *
 * Authoritative interface for Favorite Pay administrative and operational audit logging.
 * Non-blocking: Logging operations must never interrupt or roll back financial transactions.
 */
interface AuditLogServiceInterface
{
    /**
     * Record an audit log entry.
     * Guaranteed non-blocking: must never throw an unhandled exception to caller.
     *
     * @param string $action Canonical action identifier (e.g. 'withdrawal.approved', 'settings.updated')
     * @param string $subjectType Type of entity ('withdrawal', 'recharge', 'settings', 'payment')
     * @param string|null $subjectId Identifier of entity
     * @param int|null $targetUserId Target customer user ID
     * @param array $metadata Arbitrary context (automatically sanitized of sensitive keys)
     * @param string|null $description Human-readable event summary
     * @param int|null $actorUserId Explicit actor user ID (defaults to current authenticated user)
     * @param string|null $actorType Explicit actor type ('admin', 'customer', 'system')
     * @param string|null $actorName Explicit actor name / username
     * @param string|null $ipAddress Client IP address
     * @param string|null $userAgent Client User-Agent string
     * @param string|null $withdrawalId Associated withdrawal ID if applicable
     * @param string|null $paymentId Associated payment/intent ID if applicable
     * @return int|null Inserted log ID, or null on failure / non-fatal error
     */
    public function log(
        string $action,
        string $subjectType,
        ?string $subjectId = null,
        ?int $targetUserId = null,
        array $metadata = [],
        ?string $description = null,
        ?int $actorUserId = null,
        ?string $actorType = null,
        ?string $actorName = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $withdrawalId = null,
        ?string $paymentId = null
    ): ?int;

    /**
     * Retrieve paginated audit logs matching filters.
     *
     * @param array $filters [action, actor_user_id, target_user_id, subject_type, withdrawal_id, payment_id, date_from, date_to, search]
     * @param int $limit Maximum results (bounded, e.g. 20, max 100)
     * @param int $offset Offset
     * @return array{items: array, total: int, page: int, limit: int, totalPages: int}
     */
    public function listLogs(array $filters = [], int $limit = 20, int $offset = 0): array;

    /**
     * Retrieve a single audit log entry by ID.
     *
     * @param int $id
     * @return array|null
     */
    public function getLog(int $id): ?array;

    /**
     * Retrieve audit entries for a specific withdrawal.
     *
     * @param string $withdrawalId
     * @param int $limit
     * @return array
     */
    public function getWithdrawalLogs(string $withdrawalId, int $limit = 50): array;
}
