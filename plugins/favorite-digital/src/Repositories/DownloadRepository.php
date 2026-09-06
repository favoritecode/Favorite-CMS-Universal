<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Repositories;

use DateTimeImmutable;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;

class DownloadRepository
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
        if (method_exists($this->db, 'registerPrefixableTables')) {
            $this->db->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);
        }
    }

    public function getDatabase(): Database
    {
        return $this->db;
    }

    public function createDownloadRecord(array $data): int
    {
        if (empty($data['download_count'])) {
            $data['download_count'] = 0;
        }
        $data['created_at'] = date('Y-m-d H:i:s');

        return (int)$this->db->insert('favorite_digital_downloads', $data);
    }

    public function findDownloadById(int $id): ?object
    {
        $row = $this->db->selectOne(
            "SELECT * FROM `favorite_digital_downloads` WHERE `id` = ? LIMIT 1",
            [$id]
        );
        return $row ?: null;
    }

    public function findDownloadByToken(string $token): ?object
    {
        $row = $this->db->selectOne(
            "SELECT * FROM `favorite_digital_downloads` WHERE `download_token` = ? LIMIT 1",
            [$token]
        );
        return $row ?: null;
    }

    public function findDownloadByEntitlement(int $entitlementId): ?object
    {
        $row = $this->db->selectOne(
            "SELECT * FROM `favorite_digital_downloads` WHERE `entitlement_id` = ? ORDER BY `id` DESC LIMIT 1",
            [$entitlementId]
        );
        return $row ?: null;
    }

    public function findDownloadByUserAndProduct(int $userId, int $productId, ?int $entitlementId = null): ?object
    {
        if ($entitlementId !== null && $entitlementId > 0) {
            $row = $this->db->selectOne(
                "SELECT * FROM `favorite_digital_downloads`
                 WHERE `user_id` = ? AND `product_id` = ? AND `entitlement_id` = ?
                 ORDER BY `id` DESC LIMIT 1",
                [$userId, $productId, $entitlementId]
            );
        } else {
            $row = $this->db->selectOne(
                "SELECT * FROM `favorite_digital_downloads`
                 WHERE `user_id` = ? AND `product_id` = ?
                 ORDER BY `id` DESC LIMIT 1",
                [$userId, $productId]
            );
        }
        return $row ?: null;
    }

    public function getDownloadsByUser(int $userId): array
    {
        return $this->db->select(
            "SELECT d.*, p.title AS product_title, p.slug AS product_slug, p.product_type
             FROM `favorite_digital_downloads` d
             LEFT JOIN `favorite_digital_products` p ON d.product_id = p.id
             WHERE d.user_id = ?
             ORDER BY d.id DESC",
            [$userId]
        );
    }

    /**
     * Atomically increment the download count if strictly below $maxLimit.
     * This single conditional UPDATE guarantees race condition safety across SQLite and MySQL.
     *
     * @return bool True if incremented; False if download_count was already >= $maxLimit
     */
    public function incrementDownloadCount(int $downloadId, string $ip, string $userAgent, int $maxLimit = 3): bool
    {
        $nowStr = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->db->query(
            "UPDATE `favorite_digital_downloads`
             SET `download_count` = `download_count` + 1,
                 `downloaded_at`  = ?,
                 `ip_address`     = ?,
                 `user_agent`     = ?
             WHERE `id` = ? AND `download_count` < ?",
            [$nowStr, $ip, $userAgent, $downloadId, $maxLimit]
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Atomically increment download count for unlimited downloads (e.g. membership access).
     */
    public function incrementUnlimitedDownloadCount(int $downloadId, string $ip, string $userAgent): bool
    {
        $nowStr = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->db->query(
            "UPDATE `favorite_digital_downloads`
             SET `download_count` = `download_count` + 1,
                 `downloaded_at`  = ?,
                 `ip_address`     = ?,
                 `user_agent`     = ?
             WHERE `id` = ?",
            [$nowStr, $ip, $userAgent, $downloadId]
        );

        return $stmt->rowCount() > 0;
    }

    public function updateAudit(int $id, int $count, string $ipAddress, string $userAgent): bool
    {
        $nowStr = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE `favorite_digital_downloads`
             SET `download_count` = ?,
                 `ip_address`     = ?,
                 `user_agent`     = ?,
                 `downloaded_at`  = ?
             WHERE `id` = ?",
            [$count, $ipAddress, $userAgent, $nowStr, $id]
        );
        return $stmt->rowCount() > 0;
    }

    public function getDownloadCount(int $downloadId): int
    {
        $row = $this->findDownloadById($downloadId);
        return $row ? (int)$row->download_count : 0;
    }

    public function updateToken(int $downloadId, string $newToken): bool
    {
        return $this->db->update(
            'favorite_digital_downloads',
            ['download_token' => $newToken],
            ['id' => $downloadId]
        ) >= 0;
    }
}
