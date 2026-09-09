<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Pay\Contracts\NotificationServiceInterface;
use FavoriteCMS\Pay\Support\SafeLogger;
use InvalidArgumentException;
use Throwable;

class NotificationService implements NotificationServiceInterface
{
    private ?Database $db;

    /**
     * In-memory storage for test/isolation mode.
     * @var array<string, array>
     */
    private array $notifications = [];

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    /**
     * Set in-memory storage directly (for testing).
     */
    public function setInMemoryStorage(array $notifications): void
    {
        $this->notifications = $notifications;
    }

    public function notify(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $withdrawalId = null,
        array $data = []
    ): array {
        if ($userId <= 0) {
            throw new InvalidArgumentException("User ID must be positive.");
        }

        $trimmedType = trim($type);
        if ($trimmedType === '') {
            throw new InvalidArgumentException("Notification type cannot be empty.");
        }

        // 1. Idempotency check: if linked to a withdrawal, check if notification already exists
        if ($withdrawalId !== null && trim($withdrawalId) !== '') {
            $cleanWdId = trim($withdrawalId);
            $existing = $this->findExistingByWithdrawalAndType($cleanWdId, $trimmedType);
            if ($existing !== null) {
                return $existing;
            }
        } else {
            $cleanWdId = null;
        }

        $now = date('Y-m-d H:i:s');
        $id = 'notif_' . bin2hex(random_bytes(10));
        $cleanData = SafeLogger::sanitize($data);
        $encodedData = !empty($cleanData) ? json_encode($cleanData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        $record = [
            'id'            => $id,
            'user_id'       => $userId,
            'type'          => $trimmedType,
            'title'         => trim($title),
            'message'       => trim($message),
            'withdrawal_id' => $cleanWdId,
            'data'          => $encodedData,
            'is_read'       => 0,
            'read_at'       => null,
            'created_at'    => $now,
        ];

        // 2. Persist to database if available
        if ($this->db !== null && $this->db->tableExists('favorite_pay_notifications')) {
            try {
                $this->db->insert('favorite_pay_notifications', $record);
            } catch (Throwable $e) {
                // If unique key collision occurred due to concurrent execution, fetch existing
                if ($cleanWdId !== null) {
                    $existing = $this->findExistingByWithdrawalAndType($cleanWdId, $trimmedType);
                    if ($existing !== null) {
                        return $existing;
                    }
                }
                SafeLogger::error("Failed to insert notification: " . $e->getMessage(), ['user_id' => $userId, 'type' => $trimmedType]);
            }
        }

        // Store in-memory
        $record['parsed_data'] = $cleanData;
        $this->notifications[$id] = $record;

        if (function_exists('do_action')) {
            do_action('favorite.pay.notification.created', [
                'notification_id' => $id,
                'user_id'         => $userId,
                'type'            => $trimmedType,
                'withdrawal_id'   => $cleanWdId,
            ]);
        }

        return $record;
    }

    public function listUserNotifications(int $userId, int $limit = 25, int $offset = 0, bool $unreadOnly = false): array
    {
        if ($userId <= 0) {
            return ['items' => [], 'total' => 0, 'unread_count' => 0];
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        if ($this->db !== null && $this->db->tableExists('favorite_pay_notifications')) {
            $where = "user_id = ?";
            $params = [$userId];

            if ($unreadOnly) {
                $where .= " AND is_read = 0";
            }

            $totalRow = $this->db->selectOne("SELECT COUNT(*) as cnt FROM favorite_pay_notifications WHERE {$where}", $params);
            $total = (int)($totalRow->cnt ?? 0);
            $unreadRow = $this->db->selectOne("SELECT COUNT(*) as cnt FROM favorite_pay_notifications WHERE user_id = ? AND is_read = 0", [$userId]);
            $unreadCount = (int)($unreadRow->cnt ?? 0);

            $rows = $this->db->select(
                "SELECT * FROM favorite_pay_notifications WHERE {$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}",
                $params
            );

            $items = array_map(fn($row) => $this->hydrateRow((array)$row), $rows);

            return [
                'items'        => $items,
                'total'        => $total,
                'unread_count' => $unreadCount,
            ];
        }

        // In-memory fallback
        $userNotifs = array_filter($this->notifications, fn($n) => (int)$n['user_id'] === $userId);
        if ($unreadOnly) {
            $userNotifs = array_filter($userNotifs, fn($n) => empty($n['is_read']));
        }

        // Sort descending by created_at
        usort($userNotifs, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        $total = count($userNotifs);
        $unreadCount = count(array_filter($this->notifications, fn($n) => (int)$n['user_id'] === $userId && empty($n['is_read'])));
        $sliced = array_slice($userNotifs, $offset, $limit);

        return [
            'items'        => array_values($sliced),
            'total'        => $total,
            'unread_count' => $unreadCount,
        ];
    }

    public function getUnreadCount(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        if ($this->db !== null && $this->db->tableExists('favorite_pay_notifications')) {
            $row = $this->db->selectOne(
                "SELECT COUNT(*) as cnt FROM favorite_pay_notifications WHERE user_id = ? AND is_read = 0",
                [$userId]
            );
            return $row ? (int)($row->cnt ?? 0) : 0;
        }

        return count(array_filter(
            $this->notifications,
            fn($n) => (int)$n['user_id'] === $userId && empty($n['is_read'])
        ));
    }

    public function getNotification(string $id, int $userId): ?array
    {
        $cleanId = trim($id);
        if ($cleanId === '' || $userId <= 0) {
            return null;
        }

        if ($this->db !== null && $this->db->tableExists('favorite_pay_notifications')) {
            $row = $this->db->selectOne(
                "SELECT * FROM favorite_pay_notifications WHERE id = ? AND user_id = ?",
                [$cleanId, $userId]
            );
            return $row ? $this->hydrateRow((array)$row) : null;
        }

        if (isset($this->notifications[$cleanId])) {
            $notif = $this->notifications[$cleanId];
            if ((int)$notif['user_id'] === $userId) {
                return $notif;
            }
        }

        return null;
    }

    public function markAsRead(string $id, int $userId): bool
    {
        $cleanId = trim($id);
        if ($cleanId === '' || $userId <= 0) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        if ($this->db !== null && $this->db->tableExists('favorite_pay_notifications')) {
            $affected = $this->db->update(
                'favorite_pay_notifications',
                ['is_read' => 1, 'read_at' => $now],
                ['id' => $cleanId, 'user_id' => $userId]
            );

            if (isset($this->notifications[$cleanId])) {
                $this->notifications[$cleanId]['is_read'] = 1;
                $this->notifications[$cleanId]['read_at'] = $now;
            }

            return $affected > 0;
        }

        if (isset($this->notifications[$cleanId]) && (int)$this->notifications[$cleanId]['user_id'] === $userId) {
            $this->notifications[$cleanId]['is_read'] = 1;
            $this->notifications[$cleanId]['read_at'] = $now;
            return true;
        }

        return false;
    }

    public function markAllAsRead(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');

        if ($this->db !== null && $this->db->tableExists('favorite_pay_notifications')) {
            $affected = $this->db->update(
                'favorite_pay_notifications',
                ['is_read' => 1, 'read_at' => $now],
                ['user_id' => $userId, 'is_read' => 0]
            );

            foreach ($this->notifications as $id => $notif) {
                if ((int)$notif['user_id'] === $userId) {
                    $this->notifications[$id]['is_read'] = 1;
                    $this->notifications[$id]['read_at'] = $now;
                }
            }

            return $affected;
        }

        $count = 0;
        foreach ($this->notifications as $id => $notif) {
            if ((int)$notif['user_id'] === $userId && empty($notif['is_read'])) {
                $this->notifications[$id]['is_read'] = 1;
                $this->notifications[$id]['read_at'] = $now;
                $count++;
            }
        }

        return $count;
    }

    public function getNotificationsForWithdrawal(string $withdrawalId, int $userId = 0): array
    {
        $cleanWdId = trim($withdrawalId);
        if ($cleanWdId === '') {
            return [];
        }

        if ($this->db !== null && $this->db->tableExists('favorite_pay_notifications')) {
            if ($userId > 0) {
                $rows = $this->db->select(
                    "SELECT * FROM favorite_pay_notifications WHERE withdrawal_id = ? AND user_id = ? ORDER BY created_at ASC",
                    [$cleanWdId, $userId]
                );
            } else {
                $rows = $this->db->select(
                    "SELECT * FROM favorite_pay_notifications WHERE withdrawal_id = ? ORDER BY created_at ASC",
                    [$cleanWdId]
                );
            }
            return array_map(fn($row) => $this->hydrateRow((array)$row), $rows);
        }

        $matched = array_filter(
            $this->notifications,
            function ($n) use ($cleanWdId, $userId) {
                if (($n['withdrawal_id'] ?? null) !== $cleanWdId) {
                    return false;
                }
                return $userId > 0 ? (int)$n['user_id'] === $userId : true;
            }
        );

        usort($matched, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
        return array_values($matched);
    }

    private function findExistingByWithdrawalAndType(string $withdrawalId, string $type): ?array
    {
        if ($this->db !== null && $this->db->tableExists('favorite_pay_notifications')) {
            $row = $this->db->selectOne(
                "SELECT * FROM favorite_pay_notifications WHERE withdrawal_id = ? AND type = ?",
                [$withdrawalId, $type]
            );
            if ($row) {
                return $this->hydrateRow((array)$row);
            }
        }

        foreach ($this->notifications as $notif) {
            if (($notif['withdrawal_id'] ?? null) === $withdrawalId && ($notif['type'] ?? null) === $type) {
                return $notif;
            }
        }

        return null;
    }

    private function hydrateRow(array $row): array
    {
        $data = [];
        if (!empty($row['data'])) {
            try {
                $data = json_decode((string)$row['data'], true) ?: [];
            } catch (Throwable) {
                $data = [];
            }
        }

        $row['parsed_data'] = $data;
        $row['is_read'] = !empty($row['is_read']);
        return $row;
    }
}
