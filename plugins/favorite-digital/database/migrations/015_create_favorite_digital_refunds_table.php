<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Digital — Migration 015: Refunds Table
 *
 * Refund requests and execution logs with customer wallet credit destination.
 */
class CreateFavoriteDigitalRefundsTable
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

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `favorite_digital_refunds` (
                `id`                    {$pkBigint},
                `order_id`              BIGINT         NOT NULL,
                `order_item_id`         BIGINT         NULL,
                `user_id`               BIGINT         NOT NULL,
                `refund_amount`         DECIMAL(14, 2) NOT NULL,
                `currency`              VARCHAR(3)     NOT NULL DEFAULT 'BDT',
                `destination`           VARCHAR(32)    NOT NULL DEFAULT 'wallet',
                `wallet_transaction_id` BIGINT         NULL,
                `reason`                TEXT           NULL,
                `status`                VARCHAR(32)    NOT NULL DEFAULT 'completed',
                `processed_at`          DATETIME       NOT NULL,
                `created_at`            TIMESTAMP      DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_digital_refunds', 'idx_fd_ref_order', '`order_id`');
        $this->createIndexIfNotExists('favorite_digital_refunds', 'idx_fd_ref_user', '`user_id`');
        $this->createIndexIfNotExists('favorite_digital_refunds', 'idx_fd_ref_status', '`status`');
        $this->createIndexIfNotExists('favorite_digital_refunds', 'idx_fd_ref_created', '`created_at`');
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `favorite_digital_refunds`");
    }

    protected function isSqlite(): bool
    {
        try {
            $driver = $this->db->getConnection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            return strtolower((string)$driver) === 'sqlite';
        } catch (\Throwable) {
            return false;
        }
    }

    protected function createIndexIfNotExists(string $table, string $indexName, string $columns, bool $unique = false): void
    {
        if ($this->isSqlite()) {
            $uniqueClause = $unique ? 'UNIQUE' : '';
            $this->db->execute("CREATE {$uniqueClause} INDEX IF NOT EXISTS `{$indexName}` ON `{$table}` ({$columns})");
            return;
        }

        try {
            $existing = $this->db->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
            if (empty($existing)) {
                $uniqueClause = $unique ? 'UNIQUE' : '';
                $this->db->execute("ALTER TABLE `{$table}` ADD {$uniqueClause} INDEX `{$indexName}` ({$columns})");
            }
        } catch (\Throwable) {
            try {
                $uniqueClause = $unique ? 'UNIQUE' : '';
                $this->db->execute("ALTER TABLE `{$table}` ADD {$uniqueClause} INDEX `{$indexName}` ({$columns})");
            } catch (\Throwable) {
            }
        }
    }
}

