<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Repositories;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;
use DateTimeImmutable;

class EntitlementRepository
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

    public function createEntitlement(array $data): int
    {
        if (isset($data['source']) && !isset($data['source_type'])) {
            $data['source_type'] = $data['source'];
        }
        unset($data['source'], $data['package_id'], $data['order_id'], $data['order_item_id'], $data['download_limit'], $data['download_count']);

        if (empty($data['source_type'])) {
            $data['source_type'] = 'direct';
        }
        if (empty($data['granted_at'])) {
            $data['granted_at'] = date('Y-m-d H:i:s');
        }
        if (empty($data['status'])) {
            $data['status'] = 'active';
        }
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        return (int)$this->db->insert('favorite_digital_entitlements', $data);
    }

    public function findEntitlement(int $id): ?object
    {
        $row = $this->db->selectOne(
            "SELECT * FROM `favorite_digital_entitlements` WHERE `id` = ? LIMIT 1",
            [$id]
        );
        return $row ?: null;
    }

    public function findActiveEntitlement(int $userId, int $productId, ?string $now = null): ?object
    {
        $nowStr = $now ?? (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $row = $this->db->selectOne(
            "SELECT * FROM `favorite_digital_entitlements`
             WHERE `user_id` = ? AND `product_id` = ? AND `status` = 'active'
               AND (`expires_at` IS NULL OR `expires_at` > ?)
             ORDER BY `id` DESC LIMIT 1",
            [$userId, $productId, $nowStr]
        );

        return $row ?: null;
    }

    public function findEntitlementBySource(int $userId, int $productId, string $sourceType, ?int $sourceId): ?object
    {
        if ($sourceId === null) {
            $row = $this->db->selectOne(
                "SELECT * FROM `favorite_digital_entitlements`
                 WHERE `user_id` = ? AND `product_id` = ? AND `source_type` = ? AND `source_id` IS NULL
                 ORDER BY `id` DESC LIMIT 1",
                [$userId, $productId, $sourceType]
            );
        } else {
            $row = $this->db->selectOne(
                "SELECT * FROM `favorite_digital_entitlements`
                 WHERE `user_id` = ? AND `product_id` = ? AND `source_type` = ? AND `source_id` = ?
                 ORDER BY `id` DESC LIMIT 1",
                [$userId, $productId, $sourceType, $sourceId]
            );
        }

        return $row ?: null;
    }

    public function getEntitlementsByUser(int $userId): array
    {
        return $this->db->select(
            "SELECT e.*, p.title AS product_title, p.product_type, p.slug AS product_slug
             FROM `favorite_digital_entitlements` e
             LEFT JOIN `favorite_digital_products` p ON e.product_id = p.id
             WHERE e.user_id = ?
             ORDER BY e.id DESC",
            [$userId]
        );
    }

    public function listEntitlementsForUser(int $userId, array $filters = [], int $page = 1, int $perPage = 12): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $conditions = ["e.`user_id` = ?"];
        $bindings = [$userId];

        if (!empty($filters['product_type'])) {
            $conditions[] = "p.`product_type` = ?";
            $bindings[] = $filters['product_type'];
        }

        if (!empty($filters['status'])) {
            $conditions[] = "e.`status` = ?";
            $bindings[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $conditions[] = "p.`title` LIKE ?";
            $bindings[] = '%' . trim((string)$filters['search']) . '%';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $conditions);

        $countSql = "SELECT COUNT(*) as total
                     FROM `favorite_digital_entitlements` e
                     LEFT JOIN `favorite_digital_products` p ON e.`product_id` = p.`id`
                     {$whereClause}";
        $totalRow = $this->db->selectOne($countSql, $bindings);
        $total = $totalRow ? (int)$totalRow->total : 0;
        $totalPages = max(1, (int)ceil($total / $perPage));

        $dataSql = "SELECT e.*, p.`title` AS product_title, p.`product_type`, p.`slug` AS product_slug,
                           p.`description` AS product_description, p.`is_free`, p.`final_price`, p.`currency`
                    FROM `favorite_digital_entitlements` e
                    LEFT JOIN `favorite_digital_products` p ON e.`product_id` = p.`id`
                    {$whereClause}
                    ORDER BY e.`id` DESC
                    LIMIT {$perPage} OFFSET {$offset}";
        $items = $this->db->select($dataSql, $bindings);

        return [
            'data'        => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    public function getEntitlementsByOrder(int $orderId): array
    {
        return $this->db->select(
            "SELECT e.*, p.title AS product_title, p.product_type, p.slug AS product_slug,
                    dl.download_count, dl.downloaded_at
             FROM `favorite_digital_entitlements` e
             LEFT JOIN `favorite_digital_products` p ON e.product_id = p.id
             LEFT JOIN `favorite_digital_downloads` dl ON dl.entitlement_id = e.id
             INNER JOIN `favorite_digital_order_items` oi ON (
                 (e.source_type IN ('purchase', 'package') AND e.source_id = oi.id)
             )
             WHERE oi.order_id = ?
             ORDER BY e.id ASC",
            [$orderId]
        );
    }

    public function getEntitlementsBySource(string $sourceType, int $sourceId): array
    {
        return $this->db->select(
            "SELECT * FROM `favorite_digital_entitlements`
             WHERE `source_type` = ? AND `source_id` = ?
             ORDER BY `id` ASC",
            [$sourceType, $sourceId]
        );
    }

    public function updateEntitlement(int $id, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->update('favorite_digital_entitlements', $data, ['id' => $id]) >= 0;
    }

    public function updateStatus(int $id, string $status): bool
    {
        return $this->updateEntitlement($id, ['status' => $status]);
    }

    public function revokeEntitlement(int $id): bool
    {
        return $this->updateStatus($id, 'revoked');
    }

    public function revokeBySource(string $sourceType, int $sourceId): int
    {
        $rows = $this->getEntitlementsBySource($sourceType, $sourceId);
        $count = 0;
        foreach ($rows as $row) {
            if ($row->status !== 'revoked') {
                $this->revokeEntitlement((int)$row->id);
                $count++;
            }
        }
        return $count;
    }

    public function findExpiredCandidates(string $now): array
    {
        return $this->db->select(
            "SELECT * FROM `favorite_digital_entitlements`
             WHERE `status` = 'active' AND `expires_at` IS NOT NULL AND `expires_at` <= ?",
            [$now]
        );
    }
}
