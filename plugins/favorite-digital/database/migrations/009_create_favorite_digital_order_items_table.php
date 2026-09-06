<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Digital — Migration 009: Order Items Table
 *
 * Line items with immutable historical price snapshots.
 */
class CreateFavoriteDigitalOrderItemsTable
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
            CREATE TABLE IF NOT EXISTS `favorite_digital_order_items` (
                `id`               {$pkBigint},
                `order_id`         BIGINT         NOT NULL,
                `product_id`       BIGINT         NOT NULL,
                `product_type`     VARCHAR(32)    NOT NULL,
                `unit_price`       DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
                `discount_percent` DECIMAL(5, 2)  NOT NULL DEFAULT 0.00,
                `final_price`      DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
                `currency`         VARCHAR(3)     NOT NULL DEFAULT 'BDT',
                `snapshot_data`    TEXT           NULL,
                `created_at`       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_digital_order_items', 'idx_fd_oitems_ord', '`order_id`');
        $this->createIndexIfNotExists('favorite_digital_order_items', 'idx_fd_oitems_prod', '`product_id`');
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `favorite_digital_order_items`");
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

