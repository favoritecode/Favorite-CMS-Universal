<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Repositories;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;

class WalletRepository
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

    public function findWalletByUserId(int $userId): ?object
    {
        $wallet = $this->db->selectOne(
            "SELECT * FROM `favorite_digital_wallets` WHERE `user_id` = ? LIMIT 1",
            [$userId]
        );
        return $this->formatWallet($wallet);
    }

    public function getOrCreateWallet(int $userId, string $currency = 'BDT'): object
    {
        $wallet = $this->findWalletByUserId($userId);
        if ($wallet !== null) {
            return $wallet;
        }

        $now = date('Y-m-d H:i:s');
        try {
            $this->db->insert('favorite_digital_wallets', [
                'user_id'        => $userId,
                'balance_amount' => '0.00',
                'currency'       => strtoupper(trim($currency)),
                'status'         => 'active',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        } catch (\Throwable) {
            // Already created concurrently, fallback to lookup
        }

        return $this->findWalletByUserId($userId);
    }

    public function lockWalletForUpdate(int $userId): ?object
    {
        $isMysql = false;
        try {
            $driver = $this->db->getConnection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $isMysql = in_array(strtolower((string)$driver), ['mysql', 'mariadb'], true);
        } catch (\Throwable) {
        }

        $sql = "SELECT * FROM `favorite_digital_wallets` WHERE `user_id` = ? LIMIT 1";
        if ($isMysql) {
            $sql .= " FOR UPDATE";
        }

        $wallet = $this->db->selectOne($sql, [$userId]);
        return $this->formatWallet($wallet);
    }

    public function updateBalance(int $walletId, string $newBalance): bool
    {
        $formatted = number_format((float)$newBalance, 2, '.', '');
        return $this->db->update('favorite_digital_wallets', [
            'balance_amount' => $formatted,
            'updated_at'     => date('Y-m-d H:i:s'),
        ], ['id' => $walletId]) >= 0;
    }

    public function createTransaction(array $data): int
    {
        $data['amount'] = number_format((float)($data['amount'] ?? 0), 2, '.', '');
        $data['balance_after'] = number_format((float)($data['balance_after'] ?? 0), 2, '.', '');
        if (empty($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        return (int)$this->db->insert('favorite_digital_wallet_transactions', $data);
    }

    public function getTransactions(int $walletId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $rows = $this->db->select(
            "SELECT * FROM `favorite_digital_wallet_transactions` WHERE `wallet_id` = ? ORDER BY `id` DESC LIMIT {$limit} OFFSET {$offset}",
            [$walletId]
        );

        foreach ($rows as &$tx) {
            $tx = $this->formatTransaction($tx);
        }
        unset($tx);

        return $rows;
    }

    public function getTransactionByReference(string $referenceId): ?object
    {
        $tx = $this->db->selectOne(
            "SELECT * FROM `favorite_digital_wallet_transactions` WHERE `reference_id` = ? LIMIT 1",
            [$referenceId]
        );
        return $this->formatTransaction($tx);
    }

    public function getTransactionsByOrderId(int $orderId): array
    {
        $rows = $this->db->select(
            "SELECT * FROM `favorite_digital_wallet_transactions` WHERE `order_id` = ? ORDER BY `id` ASC",
            [$orderId]
        );

        foreach ($rows as &$tx) {
            $tx = $this->formatTransaction($tx);
        }
        unset($tx);

        return $rows;
    }

    protected function formatWallet(?object $wallet): ?object
    {
        if (!$wallet) {
            return null;
        }

        $wallet->balance_amount = number_format((float)$wallet->balance_amount, 2, '.', '');
        return $wallet;
    }

    protected function formatTransaction(?object $tx): ?object
    {
        if (!$tx) {
            return null;
        }

        $tx->amount = number_format((float)$tx->amount, 2, '.', '');
        $tx->balance_after = number_format((float)$tx->balance_after, 2, '.', '');
        return $tx;
    }
}

