<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Digital — Migration 008: Orders Table
 *
 * Orders ledger for all purchases (digital items, services, packages, memberships).
 */
class CreateFavoriteDigitalOrdersTable
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
        $updatedAt = $isSqlite
            ? 'DATETIME DEFAULT CURRENT_TIMESTAMP'
            : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `favorite_digital_orders` (
                `id`                 {$pkBigint},
                `order_number`       VARCHAR(64)    NOT NULL UNIQUE,
                `user_id`            BIGINT         NOT NULL,
                `status`             VARCHAR(32)    NOT NULL DEFAULT 'pending',
                `payment_status`     VARCHAR(32)    NOT NULL DEFAULT 'pending',
                `fulfillment_status` VARCHAR(32)    NOT NULL DEFAULT 'unfulfilled',
                `subtotal_amount`    DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
                `discount_amount`    DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
                `total_amount`       DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
                `currency`           VARCHAR(3)     NOT NULL DEFAULT 'BDT',
                `notes`              TEXT           NULL,
                `created_at`         TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
                `updated_at`         {$updatedAt}
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_digital_orders', 'idx_fd_ord_num', '`order_number`', true);
        $this->createIndexIfNotExists('favorite_digital_orders', 'idx_fd_ord_user', '`user_id`');
        $this->createIndexIfNotExists('favorite_digital_orders', 'idx_fd_ord_status', '`status`');
        $this->createIndexIfNotExists('favorite_digital_orders', 'idx_fd_ord_pay_status', '`payment_status`');
        $this->createIndexIfNotExists('favorite_digital_orders', 'idx_fd_ord_ful_status', '`fulfillment_status`');
        $this->createIndexIfNotExists('favorite_digital_orders', 'idx_fd_ord_created', '`created_at`');
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `favorite_digital_orders`");
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

