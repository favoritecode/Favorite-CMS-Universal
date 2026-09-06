<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Repositories;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;

class OrderRepository
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

    public function createOrder(array $data): int
    {
        return (int)$this->db->insert('favorite_digital_orders', $data);
    }

    public function createOrderItem(array $data): int
    {
        return (int)$this->db->insert('favorite_digital_order_items', $data);
    }

    public function createOrderPayment(array $data): int
    {
        return (int)$this->db->insert('favorite_digital_order_payments', $data);
    }

    public function findOrder(int $id): ?object
    {
        $order = $this->db->selectOne(
            "SELECT * FROM `favorite_digital_orders` WHERE `id` = ? LIMIT 1",
            [$id]
        );
        return $this->formatOrder($order);
    }

    public function findOrderByOrderNumber(string $orderNumber): ?object
    {
        $order = $this->db->selectOne(
            "SELECT * FROM `favorite_digital_orders` WHERE `order_number` = ? LIMIT 1",
            [$orderNumber]
        );
        return $this->formatOrder($order);
    }

    public function findOrderWithItems(int $id): ?object
    {
        $order = $this->findOrder($id);
        if (!$order) {
            return null;
        }

        $order->items = $this->getOrderItems($id);
        $order->payments = $this->getOrderPayments($id);
        return $order;
    }

    public function findOrderWithItemsByOrderNumber(string $orderNumber): ?object
    {
        $order = $this->findOrderByOrderNumber($orderNumber);
        if (!$order) {
            return null;
        }

        $order->items = $this->getOrderItems((int)$order->id);
        $order->payments = $this->getOrderPayments((int)$order->id);
        return $order;
    }

    public function getOrderItems(int $orderId): array
    {
        $items = $this->db->select(
            "SELECT * FROM `favorite_digital_order_items` WHERE `order_id` = ? ORDER BY `id` ASC",
            [$orderId]
        );

        foreach ($items as $item) {
            $item->unit_price       = number_format((float)$item->unit_price, 2, '.', '');
            $item->discount_percent = number_format((float)$item->discount_percent, 2, '.', '');
            $item->final_price      = number_format((float)$item->final_price, 2, '.', '');

            if (isset($item->snapshot_data) && is_string($item->snapshot_data)) {
                $item->snapshot = json_decode($item->snapshot_data, true) ?: [];
            } else {
                $item->snapshot = [];
            }
        }

        return $items;
    }

    protected function formatOrder(?object $order): ?object
    {
        if (!$order) {
            return null;
        }

        $order->subtotal_amount = number_format((float)$order->subtotal_amount, 2, '.', '');
        $order->discount_amount = number_format((float)$order->discount_amount, 2, '.', '');
        $order->total_amount    = number_format((float)$order->total_amount, 2, '.', '');

        return $order;
    }

    public function getOrderPayments(int $orderId): array
    {
        $payments = $this->db->select(
            "SELECT * FROM `favorite_digital_order_payments` WHERE `order_id` = ? ORDER BY `id` ASC",
            [$orderId]
        );

        foreach ($payments as &$payment) {
            $payment = $this->formatPayment($payment);
        }
        unset($payment);

        return $payments;
    }

    public function findPaymentById(int $id): ?object
    {
        $p = $this->db->selectOne(
            "SELECT * FROM `favorite_digital_order_payments` WHERE `id` = ? LIMIT 1",
            [$id]
        );
        return $this->formatPayment($p);
    }

    public function findPaymentByTxId(string $favoritePayTxId): ?object
    {
        $p = $this->db->selectOne(
            "SELECT * FROM `favorite_digital_order_payments` WHERE `favorite_pay_tx_id` = ? LIMIT 1",
            [$favoritePayTxId]
        );
        return $this->formatPayment($p);
    }

    public function findPaymentByWalletTxId(string $walletTxId): ?object
    {
        $p = $this->db->selectOne(
            "SELECT * FROM `favorite_digital_order_payments` WHERE `wallet_tx_id` = ? LIMIT 1",
            [$walletTxId]
        );
        return $this->formatPayment($p);
    }

    public function updatePayment(int $id, array $data): bool
    {
        if (isset($data['amount_paid'])) {
            $data['amount_paid'] = number_format((float)$data['amount_paid'], 2, '.', '');
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->update('favorite_digital_order_payments', $data, ['id' => $id]) >= 0;
    }

    public function formatPayment(?object $payment): ?object
    {
        if (!$payment) {
            return null;
        }

        $payment->amount_paid = number_format((float)$payment->amount_paid, 2, '.', '');
        return $payment;
    }

    public function updateOrder(int $id, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->update('favorite_digital_orders', $data, ['id' => $id]) >= 0;
    }

    public function updateOrderStatus(int $id, string $status): bool
    {
        return $this->updateOrder($id, ['status' => $status]);
    }

    public function updatePaymentStatus(int $id, string $paymentStatus): bool
    {
        return $this->updateOrder($id, ['payment_status' => $paymentStatus]);
    }

    public function updateFulfillmentStatus(int $id, string $fulfillmentStatus): bool
    {
        return $this->updateOrder($id, ['fulfillment_status' => $fulfillmentStatus]);
    }

    public function isOrderNumberExists(string $orderNumber): bool
    {
        $row = $this->db->selectOne(
            "SELECT `id` FROM `favorite_digital_orders` WHERE `order_number` = ? LIMIT 1",
            [$orderNumber]
        );
        return $row !== null;
    }

    public function listOrders(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $conditions = [];
        $bindings = [];

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $conditions[] = "`status` = ?";
            $bindings[] = $filters['status'];
        }

        if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
            $conditions[] = "`payment_status` = ?";
            $bindings[] = $filters['payment_status'];
        }

        if (!empty($filters['fulfillment_status']) && $filters['fulfillment_status'] !== 'all') {
            $conditions[] = "`fulfillment_status` = ?";
            $bindings[] = $filters['fulfillment_status'];
        }

        if (!empty($filters['user_id'])) {
            $conditions[] = "`user_id` = ?";
            $bindings[] = (int)$filters['user_id'];
        }

        if (!empty($filters['search'])) {
            $term = '%' . trim((string)$filters['search']) . '%';
            $conditions[] = "(`order_number` LIKE ? OR `notes` LIKE ?)";
            $bindings[] = $term;
            $bindings[] = $term;
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countSql = "SELECT COUNT(*) as total FROM `favorite_digital_orders` {$whereClause}";
        $totalRow = $this->db->selectOne($countSql, $bindings);
        $total = $totalRow ? (int)$totalRow->total : 0;

        $sql = "SELECT * FROM `favorite_digital_orders` {$whereClause} ORDER BY `id` DESC LIMIT {$perPage} OFFSET {$offset}";
        $orders = $this->db->select($sql, $bindings);

        foreach ($orders as &$ord) {
            $ord = $this->formatOrder($ord);
        }
        unset($ord);

        return [
            'data'        => $orders,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    }

    public function listOrdersForUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        return $this->listOrders(['user_id' => $userId], $page, $perPage);
    }
}
