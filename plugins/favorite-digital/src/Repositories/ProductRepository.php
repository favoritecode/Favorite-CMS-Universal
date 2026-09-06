<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Repositories;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;

class ProductRepository
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
        // Defensive registration of prefixable tables
        if (method_exists($this->db, 'registerPrefixableTables')) {
            $this->db->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);
        }
    }

    public function createProduct(array $data): int
    {
        return (int)$this->db->insert('favorite_digital_products', $data);
    }

    public function updateProduct(int $id, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->update('favorite_digital_products', $data, ['id' => $id]) >= 0;
    }

    public function findProduct(int $id): ?object
    {
        return $this->db->selectOne(
            "SELECT * FROM `favorite_digital_products` WHERE `id` = ? LIMIT 1",
            [$id]
        );
    }

    public function findProductBySlug(string $slug, ?int $excludeId = null): ?object
    {
        if ($excludeId !== null && $excludeId > 0) {
            return $this->db->selectOne(
                "SELECT * FROM `favorite_digital_products` WHERE `slug` = ? AND `id` != ? LIMIT 1",
                [$slug, $excludeId]
            );
        }

        return $this->db->selectOne(
            "SELECT * FROM `favorite_digital_products` WHERE `slug` = ? LIMIT 1",
            [$slug]
        );
    }

    public function updateStatus(int $id, string $status): bool
    {
        return $this->updateProduct($id, [
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * List products with pagination, search, and type/status filters.
     */
    public function listProducts(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $bindings = [];

        if (!empty($filters['product_type'])) {
            $conditions[] = "`product_type` = ?";
            $bindings[] = $filters['product_type'];
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $conditions[] = "`status` = ?";
            $bindings[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $term = '%' . trim((string)$filters['search']) . '%';
            $conditions[] = "(`title` LIKE ? OR `slug` LIKE ? OR `description` LIKE ?)";
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Total count
        $countSql = "SELECT COUNT(*) as total FROM `favorite_digital_products` {$whereClause}";
        $totalRow = $this->db->selectOne($countSql, $bindings);
        $total = $totalRow ? (int)$totalRow->total : 0;
        $totalPages = max(1, (int)ceil($total / $perPage));

        // Status counts for tabs
        $typeCondition = !empty($filters['product_type']) ? "WHERE `product_type` = ?" : "";
        $typeBindings = !empty($filters['product_type']) ? [$filters['product_type']] : [];
        $countsRows = $this->db->select(
            "SELECT `status`, COUNT(*) as cnt FROM `favorite_digital_products` {$typeCondition} GROUP BY `status`",
            $typeBindings
        );

        $counts = [
            'all'       => 0,
            'published' => 0,
            'draft'     => 0,
            'archived'  => 0,
        ];
        foreach ($countsRows as $r) {
            $cnt = (int)$r->cnt;
            $counts['all'] += $cnt;
            if (isset($counts[$r->status])) {
                $counts[$r->status] = $cnt;
            }
        }

        // Fetch paginated items
        $dataSql = "SELECT * FROM `favorite_digital_products` {$whereClause} ORDER BY `id` DESC LIMIT {$perPage} OFFSET {$offset}";
        $items = $this->db->select($dataSql, $bindings);

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => $totalPages,
            'counts'     => $counts,
        ];
    }

    // -------------------------------------------------------------------------
    // Digital Product Details
    // -------------------------------------------------------------------------

    public function findProductDetails(int $productId): ?object
    {
        return $this->db->selectOne(
            "SELECT * FROM `favorite_digital_product_details` WHERE `product_id` = ? LIMIT 1",
            [$productId]
        );
    }

    public function saveProductDetails(int $productId, array $details): bool
    {
        $existing = $this->findProductDetails($productId);
        $details['updated_at'] = date('Y-m-d H:i:s');

        if ($existing) {
            return $this->db->update('favorite_digital_product_details', $details, ['product_id' => $productId]) >= 0;
        }

        $details['product_id'] = $productId;
        $details['created_at'] = date('Y-m-d H:i:s');
        return $this->db->insert('favorite_digital_product_details', $details) > 0;
    }

    // -------------------------------------------------------------------------
    // Service Details
    // -------------------------------------------------------------------------

    public function findServiceDetails(int $productId): ?object
    {
        return $this->db->selectOne(
            "SELECT * FROM `favorite_digital_service_details` WHERE `product_id` = ? LIMIT 1",
            [$productId]
        );
    }

    public function saveServiceDetails(int $productId, array $details): bool
    {
        $existing = $this->findServiceDetails($productId);
        $details['updated_at'] = date('Y-m-d H:i:s');

        if ($existing) {
            return $this->db->update('favorite_digital_service_details', $details, ['product_id' => $productId]) >= 0;
        }

        $details['product_id'] = $productId;
        $details['created_at'] = date('Y-m-d H:i:s');
        return $this->db->insert('favorite_digital_service_details', $details) > 0;
    }

    // -------------------------------------------------------------------------
    // Package & Bundle Management
    // -------------------------------------------------------------------------

    public function findPackage(int $packageId): ?object
    {
        return $this->db->selectOne(
            "SELECT * FROM `favorite_digital_packages` WHERE `id` = ? LIMIT 1",
            [$packageId]
        );
    }

    public function findPackageByProductId(int $productId): ?object
    {
        return $this->db->selectOne(
            "SELECT * FROM `favorite_digital_packages` WHERE `product_id` = ? LIMIT 1",
            [$productId]
        );
    }

    public function createPackage(int $productId, string $packageType = 'bundle'): int
    {
        $now = date('Y-m-d H:i:s');
        return $this->db->insert('favorite_digital_packages', [
            'product_id'        => $productId,
            'package_type'      => $packageType,
            'total_items_count' => 0,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
    }

    public function updatePackage(int $packageId, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->update('favorite_digital_packages', $data, ['id' => $packageId]) >= 0;
    }

    public function syncPackageItemsCount(int $packageId): int
    {
        $countRow = $this->db->selectOne(
            "SELECT COUNT(*) AS `cnt` FROM `favorite_digital_package_items` WHERE `package_id` = ?",
            [$packageId]
        );
        $count = (int)($countRow->cnt ?? 0);
        $this->updatePackage($packageId, ['total_items_count' => $count]);
        return $count;
    }

    public function getPackageItems(int $packageId): array
    {
        return $this->db->select(
            "SELECT * FROM `favorite_digital_package_items` WHERE `package_id` = ? ORDER BY `sort_order` ASC, `id` ASC",
            [$packageId]
        );
    }

    public function getPackageItemsWithProducts(int $packageId): array
    {
        return $this->db->select(
            "SELECT pi.id AS item_id, pi.package_id, pi.included_product_id, pi.sort_order, pi.created_at AS added_at,
                    p.id, p.title, p.slug, p.description, p.product_type, p.status,
                    p.original_price, p.discount_percent, p.final_price, p.currency, p.is_free
             FROM `favorite_digital_package_items` pi
             INNER JOIN `favorite_digital_products` p ON pi.included_product_id = p.id
             WHERE pi.package_id = ?
             ORDER BY pi.sort_order ASC, pi.id ASC",
            [$packageId]
        );
    }

    public function addPackageItem(int $packageId, int $includedProductId, int $sortOrder = 0): int
    {
        $id = $this->db->insert('favorite_digital_package_items', [
            'package_id'          => $packageId,
            'included_product_id' => $includedProductId,
            'sort_order'          => $sortOrder,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);

        $this->syncPackageItemsCount($packageId);
        return $id;
    }

    public function removePackageItem(int $packageId, int $includedProductId): bool
    {
        $deleted = $this->db->delete('favorite_digital_package_items', [
            'package_id'          => $packageId,
            'included_product_id' => $includedProductId,
        ]) > 0;

        if ($deleted) {
            $this->syncPackageItemsCount($packageId);
        }
        return $deleted;
    }

    public function updatePackageItemSortOrder(int $packageId, int $includedProductId, int $sortOrder): bool
    {
        return $this->db->update(
            'favorite_digital_package_items',
            ['sort_order' => $sortOrder],
            ['package_id' => $packageId, 'included_product_id' => $includedProductId]
        ) >= 0;
    }

    public function setPackageItems(int $packageId, array $includedProductIds): void
    {
        $this->db->delete('favorite_digital_package_items', ['package_id' => $packageId]);

        $order = 1;
        foreach ($includedProductIds as $pid) {
            $productId = (int)$pid;
            if ($productId <= 0) {
                continue;
            }
            $this->db->insert('favorite_digital_package_items', [
                'package_id'          => $packageId,
                'included_product_id' => $productId,
                'sort_order'          => $order++,
                'created_at'          => date('Y-m-d H:i:s'),
            ]);
        }

        $this->syncPackageItemsCount($packageId);
    }

    public function getAvailableProductsForPackage(int $excludePackageProductId = 0): array
    {
        $sql = "SELECT `id`, `title`, `slug`, `product_type`, `original_price`, `discount_percent`, `final_price`, `status`, `is_free`
                FROM `favorite_digital_products`
                WHERE `product_type` IN ('digital', 'service')
                  AND `status` != 'archived'";
        $bindings = [];

        if ($excludePackageProductId > 0) {
            $sql .= " AND `id` != ? AND `id` NOT IN (
                SELECT `included_product_id` FROM `favorite_digital_package_items`
                WHERE `package_id` IN (SELECT `id` FROM `favorite_digital_packages` WHERE `product_id` = ?)
            )";
            $bindings[] = $excludePackageProductId;
            $bindings[] = $excludePackageProductId;
        }

        $sql .= " ORDER BY `product_type` ASC, `title` ASC";

        return $this->db->select($sql, $bindings);
    }

    // -------------------------------------------------------------------------
    // Membership Plans & Customer Memberships
    // -------------------------------------------------------------------------

    public function createMembershipPlan(array $data): int
    {
        return (int)$this->db->insert('favorite_digital_membership_plans', $data);
    }

    public function updateMembershipPlan(int $planId, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->update('favorite_digital_membership_plans', $data, ['id' => $planId]) >= 0;
    }

    public function findMembershipPlan(int $planId): ?object
    {
        return $this->db->selectOne(
            "SELECT * FROM `favorite_digital_membership_plans` WHERE `id` = ? LIMIT 1",
            [$planId]
        );
    }

    public function findMembershipPlanByProductId(int $productId): ?object
    {
        return $this->db->selectOne(
            "SELECT * FROM `favorite_digital_membership_plans` WHERE `product_id` = ? LIMIT 1",
            [$productId]
        );
    }

    public function listMembershipPlans(): array
    {
        return $this->db->select(
            "SELECT mp.*, p.`title`, p.`slug`, p.`description`, p.`original_price`, p.`discount_percent`, p.`final_price`, p.`status`, p.`is_free`
             FROM `favorite_digital_membership_plans` mp
             JOIN `favorite_digital_products` p ON mp.`product_id` = p.`id`
             ORDER BY mp.`id` ASC"
        );
    }

    public function createMembership(array $data): int
    {
        return (int)$this->db->insert('favorite_digital_memberships', $data);
    }

    public function updateMembership(int $id, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->update('favorite_digital_memberships', $data, ['id' => $id]) >= 0;
    }

    public function findMembership(int $id): ?object
    {
        return $this->db->selectOne(
            "SELECT * FROM `favorite_digital_memberships` WHERE `id` = ? LIMIT 1",
            [$id]
        );
    }

    public function findActiveMembershipForUser(int $userId, ?string $nowStr = null): ?object
    {
        $nowStr = $nowStr ?? date('Y-m-d H:i:s');

        return $this->db->selectOne(
            "SELECT * FROM `favorite_digital_memberships`
             WHERE `user_id` = ?
               AND (
                   (`status` = 'active' AND `expires_at` > ?)
                   OR (`status` = 'grace' AND `grace_expires_at` IS NOT NULL AND `grace_expires_at` > ?)
                   OR (`status` = 'cancelled' AND `expires_at` > ?)
               )
             ORDER BY `expires_at` DESC
             LIMIT 1",
            [$userId, $nowStr, $nowStr, $nowStr]
        );
    }

    public function listMembershipsForUser(int $userId): array
    {
        return $this->db->select(
            "SELECT m.*, mp.`plan_type`, mp.`duration_count`, mp.`duration_unit`, mp.`grace_period_days`, p.`title` AS `plan_title`
             FROM `favorite_digital_memberships` m
             JOIN `favorite_digital_membership_plans` mp ON m.`plan_id` = mp.`id`
             JOIN `favorite_digital_products` p ON mp.`product_id` = p.`id`
             WHERE m.`user_id` = ?
             ORDER BY m.`id` DESC",
            [$userId]
        );
    }

    public function listMemberships(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = [];
        $bindings = [];

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $where[] = "m.`status` = ?";
            $bindings[] = $filters['status'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = "m.`user_id` = ?";
            $bindings[] = (int)$filters['user_id'];
        }

        if (!empty($filters['plan_id'])) {
            $where[] = "m.`plan_id` = ?";
            $bindings[] = (int)$filters['plan_id'];
        }

        $whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        $countRow = $this->db->selectOne(
            "SELECT COUNT(*) AS cnt FROM `favorite_digital_memberships` m {$whereSql}",
            $bindings
        );
        $total = (int)($countRow->cnt ?? 0);

        $limit = max(1, $perPage);
        $offset = max(0, ($page - 1) * $limit);

        $items = $this->db->select(
            "SELECT m.*, mp.`plan_type`, mp.`duration_count`, mp.`duration_unit`, mp.`grace_period_days`, p.`title` AS `plan_title`
             FROM `favorite_digital_memberships` m
             JOIN `favorite_digital_membership_plans` mp ON m.`plan_id` = mp.`id`
             JOIN `favorite_digital_products` p ON mp.`product_id` = p.`id`
             {$whereSql}
             ORDER BY m.`id` DESC
             LIMIT {$limit} OFFSET {$offset}",
            $bindings
        );

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ];
    }

    public function getMembershipWithPlanAndUser(int $id): ?object
    {
        return $this->db->selectOne(
            "SELECT m.*, mp.`plan_type`, mp.`duration_count`, mp.`duration_unit`, mp.`grace_period_days`, mp.`allows_auto_renewal`,
                    p.`title` AS `plan_title`, p.`slug` AS `plan_slug`, p.`final_price` AS `plan_price`
             FROM `favorite_digital_memberships` m
             JOIN `favorite_digital_membership_plans` mp ON m.`plan_id` = mp.`id`
             JOIN `favorite_digital_products` p ON mp.`product_id` = p.`id`
             WHERE m.`id` = ?
             LIMIT 1",
            [$id]
        );
    }

    public function getDb(): Database
    {
        return $this->db;
    }

    public function findExpiredCandidates(string $nowStr): array
    {
        return $this->db->select(
            "SELECT id, status, expires_at, grace_expires_at FROM `favorite_digital_memberships`
             WHERE `status` IN ('active', 'grace', 'cancelled')
               AND (
                   (`status` = 'grace' AND `grace_expires_at` IS NOT NULL AND `grace_expires_at` <= ?)
                   OR (`status` = 'cancelled' AND `expires_at` <= ?)
                   OR (`status` = 'active' AND `expires_at` <= ? AND `auto_renew` = 0)
               )",
            [$nowStr, $nowStr, $nowStr]
        );
    }
}

