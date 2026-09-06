<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Digital — Migration 011: Entitlements Table
 *
 * Customer access passes granting ownership and access rights to digital products and services.
 */
class CreateFavoriteDigitalEntitlementsTable
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
            CREATE TABLE IF NOT EXISTS `favorite_digital_entitlements` (
                `id`          {$pkBigint},
                `user_id`     BIGINT      NOT NULL,
                `product_id`  BIGINT      NOT NULL,
                `source_type` VARCHAR(32) NOT NULL,
                `source_id`   BIGINT      NULL,
                `status`      VARCHAR(32) NOT NULL DEFAULT 'active',
                `granted_at`  DATETIME    NOT NULL,
                `expires_at`  DATETIME    NULL,
                `created_at`  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  {$updatedAt}
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_digital_entitlements', 'idx_fd_ent_user_prod', '`user_id`, `product_id`');
        $this->createIndexIfNotExists('favorite_digital_entitlements', 'idx_fd_ent_status', '`status`');
        $this->createIndexIfNotExists('favorite_digital_entitlements', 'idx_fd_ent_expires', '`expires_at`');
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `favorite_digital_entitlements`");
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

