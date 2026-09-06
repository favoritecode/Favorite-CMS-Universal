<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Digital — Migration 001: Products Table
 *
 * Primary catalog entity for all digital goods, services, packages/bundles, and memberships.
 */
class CreateFavoriteDigitalProductsTable
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
            CREATE TABLE IF NOT EXISTS `favorite_digital_products` (
                `id`               {$pkBigint},
                `title`            VARCHAR(255)   NOT NULL,
                `slug`             VARCHAR(191)   NOT NULL UNIQUE,
                `description`      TEXT           NULL,
                `product_type`     VARCHAR(32)    NOT NULL,
                `status`           VARCHAR(32)    NOT NULL DEFAULT 'draft',
                `original_price`   DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
                `discount_percent` DECIMAL(5, 2)  NOT NULL DEFAULT 0.00,
                `final_price`      DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
                `currency`         VARCHAR(3)     NOT NULL DEFAULT 'BDT',
                `is_free`          TINYINT(1)     NOT NULL DEFAULT 0,
                `created_at`       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       {$updatedAt}
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_digital_products', 'idx_fd_prod_slug', '`slug`', true);
        $this->createIndexIfNotExists('favorite_digital_products', 'idx_fd_prod_type', '`product_type`');
        $this->createIndexIfNotExists('favorite_digital_products', 'idx_fd_prod_status', '`status`');
        $this->createIndexIfNotExists('favorite_digital_products', 'idx_fd_prod_free', '`is_free`');
        $this->createIndexIfNotExists('favorite_digital_products', 'idx_fd_prod_final_price', '`final_price`');
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `favorite_digital_products`");
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

