<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Digital — Migration 005: Package Items Table
 *
 * Mapping of individual digital products and services included in packages/bundles.
 */
class CreateFavoriteDigitalPackageItemsTable
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
            CREATE TABLE IF NOT EXISTS `favorite_digital_package_items` (
                `id`                  {$pkBigint},
                `package_id`          BIGINT    NOT NULL,
                `included_product_id` BIGINT    NOT NULL,
                `sort_order`          INT       NOT NULL DEFAULT 0,
                `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_digital_package_items', 'idx_fd_pkg_items_pkg', '`package_id`');
        $this->createIndexIfNotExists('favorite_digital_package_items', 'idx_fd_pkg_items_inc', '`included_product_id`');
        $this->createIndexIfNotExists('favorite_digital_package_items', 'uniq_fd_pkg_items_pkg_prod', '`package_id`, `included_product_id`', true);
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `favorite_digital_package_items`");
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

