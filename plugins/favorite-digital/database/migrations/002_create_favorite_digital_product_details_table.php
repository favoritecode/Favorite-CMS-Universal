<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Digital — Migration 002: Product Details Table
 *
 * File metadata and access controls for downloadable products.
 */
class CreateFavoriteDigitalProductDetailsTable
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
            CREATE TABLE IF NOT EXISTS `favorite_digital_product_details` (
                `id`                     {$pkBigint},
                `product_id`             BIGINT       NOT NULL,
                `version`                VARCHAR(32)  NOT NULL DEFAULT '1.0.0',
                `file_path`              VARCHAR(500) NULL,
                `file_name`              VARCHAR(255) NULL,
                `file_hash`              VARCHAR(64)  NULL,
                `file_size`              BIGINT       NOT NULL DEFAULT 0,
                `mime_type`              VARCHAR(128) NULL,
                `max_downloads`          INT          NOT NULL DEFAULT 0,
                `download_expiry_days`   INT          NOT NULL DEFAULT 0,
                `is_membership_eligible` TINYINT(1)   NOT NULL DEFAULT 1,
                `created_at`             TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`             {$updatedAt}
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_digital_product_details', 'idx_fd_pdet_prod', '`product_id`');
        $this->createIndexIfNotExists('favorite_digital_product_details', 'idx_fd_pdet_ver', '`product_id`, `version`');
        $this->createIndexIfNotExists('favorite_digital_product_details', 'idx_fd_pdet_mem', '`is_membership_eligible`');
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `favorite_digital_product_details`");
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

