<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Repositories;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;

class RefundRepository
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

    public function createRefund(array $data): int
    {
        $data['refund_amount'] = number_format((float)($data['refund_amount'] ?? 0), 2, '.', '');
        if (empty($data['destination'])) {
            $data['destination'] = 'wallet';
        }
        if (empty($data['currency'])) {
            $data['currency'] = 'BDT';
        }
        if (empty($data['status'])) {
            $data['status'] = 'completed';
        }
        if (empty($data['processed_at'])) {
            $data['processed_at'] = date('Y-m-d H:i:s');
        }
        if (empty($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        return (int)$this->db->insert('favorite_digital_refunds', $data);
    }

    public function findRefund(int $id): ?object
    {
        $row = $this->db->selectOne(
            "SELECT * FROM `favorite_digital_refunds` WHERE `id` = ? LIMIT 1",
            [$id]
        );
        return $this->formatRefund($row);
    }

    public function findRefundByOrderId(int $orderId): ?object
    {
        $row = $this->db->selectOne(
            "SELECT * FROM `favorite_digital_refunds` WHERE `order_id` = ? ORDER BY `id` DESC LIMIT 1",
            [$orderId]
        );
        return $this->formatRefund($row);
    }

    public function findRefundsByOrderId(int $orderId): array
    {
        $rows = $this->db->select(
            "SELECT * FROM `favorite_digital_refunds` WHERE `order_id` = ? ORDER BY `id` ASC",
            [$orderId]
        );

        foreach ($rows as &$row) {
            $row = $this->formatRefund($row);
        }
        unset($row);

        return $rows;
    }

    public function findRefundByWalletTxId(int $walletTxId): ?object
    {
        $row = $this->db->selectOne(
            "SELECT * FROM `favorite_digital_refunds` WHERE `wallet_transaction_id` = ? LIMIT 1",
            [$walletTxId]
        );
        return $this->formatRefund($row);
    }

    public function updateRefund(int $id, array $data): bool
    {
        if (isset($data['refund_amount'])) {
            $data['refund_amount'] = number_format((float)$data['refund_amount'], 2, '.', '');
        }
        return $this->db->update('favorite_digital_refunds', $data, ['id' => $id]) >= 0;
    }

    public function listRefunds(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $bindings = [];

        if (!empty($filters['order_id'])) {
            $conditions[] = "`order_id` = ?";
            $bindings[] = (int)$filters['order_id'];
        }

        if (!empty($filters['user_id'])) {
            $conditions[] = "`user_id` = ?";
            $bindings[] = (int)$filters['user_id'];
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $conditions[] = "`status` = ?";
            $bindings[] = $filters['status'];
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countSql = "SELECT COUNT(*) as total FROM `favorite_digital_refunds` {$whereClause}";
        $totalRow = $this->db->selectOne($countSql, $bindings);
        $total = $totalRow ? (int)$totalRow->total : 0;

        $sql = "SELECT * FROM `favorite_digital_refunds` {$whereClause} ORDER BY `id` DESC LIMIT {$perPage} OFFSET {$offset}";
        $refunds = $this->db->select($sql, $bindings);

        foreach ($refunds as &$ref) {
            $ref = $this->formatRefund($ref);
        }
        unset($ref);

        return [
            'data'        => $refunds,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    }

    public function listRefundsForUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        return $this->listRefunds(['user_id' => $userId], $page, $perPage);
    }

    protected function formatRefund(?object $refund): ?object
    {
        if (!$refund) {
            return null;
        }

        $refund->refund_amount = number_format((float)$refund->refund_amount, 2, '.', '');
        return $refund;
    }
}
