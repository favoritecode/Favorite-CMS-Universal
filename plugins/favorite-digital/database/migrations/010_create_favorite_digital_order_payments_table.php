<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Digital — Migration 010: Order Payments Table
 *
 * Links order settlements to Favorite Pay transactions or Customer Wallet debits.
 */
class CreateFavoriteDigitalOrderPaymentsTable
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
            CREATE TABLE IF NOT EXISTS `favorite_digital_order_payments` (
                `id`                 {$pkBigint},
                `order_id`           BIGINT         NOT NULL,
                `payment_method`     VARCHAR(32)    NOT NULL,
                `favorite_pay_tx_id` VARCHAR(64)    NULL,
                `wallet_tx_id`       VARCHAR(64)    NULL,
                `amount_paid`        DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
                `currency`           VARCHAR(3)     NOT NULL DEFAULT 'BDT',
                `status`             VARCHAR(32)    NOT NULL DEFAULT 'pending',
                `created_at`         TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
                `updated_at`         {$updatedAt}
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_digital_order_payments', 'idx_fd_opay_ord', '`order_id`');
        $this->createIndexIfNotExists('favorite_digital_order_payments', 'idx_fd_opay_fpay_tx', '`favorite_pay_tx_id`');
        $this->createIndexIfNotExists('favorite_digital_order_payments', 'idx_fd_opay_status', '`status`');
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `favorite_digital_order_payments`");
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

