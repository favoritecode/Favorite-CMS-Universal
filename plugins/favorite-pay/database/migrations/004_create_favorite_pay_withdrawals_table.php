<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Pay — Migration 004: Create favorite_pay_withdrawals table
 * Supports customer withdrawal requests, hold referencing, and admin audit lifecycle.
 */
class CreateFavoritePayWithdrawalsTable
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $isSqlite = $this->isSqlite();
        $engine = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pkBigint = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT AUTO_INCREMENT PRIMARY KEY';
        $updatedAt = $isSqlite ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `favorite_pay_withdrawals` (
                `id`                    {$pkBigint},
                `withdrawal_id`         VARCHAR(64)  NOT NULL UNIQUE,
                `user_id`               BIGINT       NOT NULL,
                `wallet_id`             BIGINT       NULL,
                `amount`                BIGINT       NOT NULL,
                `currency`              VARCHAR(3)   NOT NULL DEFAULT 'BDT',
                `fee`                   BIGINT       NOT NULL DEFAULT 0,
                `net_amount`            BIGINT       NOT NULL,
                `method`                VARCHAR(32)  NOT NULL,
                `destination_data`      TEXT         NOT NULL,
                `destination_masked`    VARCHAR(191) NOT NULL,
                `status`                VARCHAR(32)  NOT NULL DEFAULT 'pending',
                `hold_reference`        VARCHAR(191) NULL,
                `transaction_reference` VARCHAR(191) NULL,
                `idempotency_key`       VARCHAR(191) NULL UNIQUE,
                `admin_user_id`         BIGINT       NULL,
                `operator_notes`        TEXT         NULL,
                `audit_trail`           TEXT         NULL,
                `created_at`            TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`            {$updatedAt},
                `processed_at`          TIMESTAMP    NULL
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_pay_withdrawals', 'idx_fpay_wd_user', '`user_id`');
        $this->createIndexIfNotExists('favorite_pay_withdrawals', 'idx_fpay_wd_status', '`status`');
        $this->createIndexIfNotExists('favorite_pay_withdrawals', 'idx_fpay_wd_method', '`method`');
        $this->createIndexIfNotExists('favorite_pay_withdrawals', 'idx_fpay_wd_created', '`created_at`');
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `favorite_pay_withdrawals`");
    }

    protected function isSqlite(): bool
    {
        try {
            $pdo = method_exists($this->db, 'getPdo') ? $this->db->getPdo() : $this->db->getConnection();
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            return strtolower((string)$driver) === 'sqlite';
        } catch (\Throwable) {
            return false;
        }
    }

    protected function createIndexIfNotExists(string $table, string $indexName, string $columns, bool $unique = false): void
    {
        if ($this->isSqlite()) {
            $uniqueClause = $unique ? 'UNIQUE' : '';
            try {
                $this->db->execute("CREATE {$uniqueClause} INDEX IF NOT EXISTS `{$indexName}` ON `{$table}` ({$columns})");
            } catch (\Throwable) {
            }
            return;
        }

        try {
            $existing = $this->db->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
            if (empty($existing)) {
                $uniqueClause = $unique ? 'UNIQUE' : '';
                $this->db->execute("ALTER TABLE `{$table}` ADD {$uniqueClause} INDEX `{$indexName}` ({$columns})");
            }
        } catch (\Throwable) {
        }
    }
}
