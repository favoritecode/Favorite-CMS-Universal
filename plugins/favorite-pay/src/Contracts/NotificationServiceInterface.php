<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

/**
 * Customer In-App Notification Service Contract
 *
 * Lightweight, shared-hosting friendly customer notification storage and retrieval.
 */
interface NotificationServiceInterface
{
    public const TYPE_WITHDRAWAL_CREATED    = 'withdrawal_created';
    public const TYPE_WITHDRAWAL_APPROVED   = 'withdrawal_approved';
    public const TYPE_WITHDRAWAL_PROCESSING = 'withdrawal_processing';
    public const TYPE_WITHDRAWAL_PAID       = 'withdrawal_paid';
    public const TYPE_WITHDRAWAL_REJECTED   = 'withdrawal_rejected';
    public const TYPE_WITHDRAWAL_CANCELLED  = 'withdrawal_cancelled';
    public const TYPE_WITHDRAWAL_FAILED     = 'withdrawal_failed';

    /**
     * Create an idempotent customer notification.
     *
     * If a notification with the same withdrawalId and type already exists,
     * it returns the existing notification without creating a duplicate.
     *
     * @param int $userId Customer receiving the notification
     * @param string $type Notification type constant
     * @param string $title Customer-safe title
     * @param string $message Customer-safe descriptive message
     * @param string|null $withdrawalId Linked withdrawal canonical ID if applicable
     * @param array $data Additional metadata (gross amount, net amount, currency, method)
     * @return array Created or existing notification record
     */
    public function notify(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $withdrawalId = null,
        array $data = []
    ): array;

    /**
     * List notifications for a customer with optional unread filter and pagination.
     *
     * @return array{items: array, total: int, unread_count: int}
     */
    public function listUserNotifications(int $userId, int $limit = 25, int $offset = 0, bool $unreadOnly = false): array;

    /**
     * Get count of unread notifications for a customer.
     */
    public function getUnreadCount(int $userId): int;

    /**
     * Retrieve a specific notification by ID with customer ownership validation.
     */
    public function getNotification(string $id, int $userId): ?array;

    /**
     * Mark a single notification as read with customer ownership validation.
     */
    public function markAsRead(string $id, int $userId): bool;

    /**
     * Mark all notifications as read for a customer.
     */
    public function markAllAsRead(int $userId): int;

    /**
     * Get customer notifications linked to a specific withdrawal.
     */
    public function getNotificationsForWithdrawal(string $withdrawalId, int $userId = 0): array;
}
