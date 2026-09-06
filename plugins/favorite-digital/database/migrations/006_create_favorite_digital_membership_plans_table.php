<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Digital — Migration 006: Membership Plans Table
 *
 * Membership tiers and plan policies (Weekly, Monthly duration and grace rules).
 */
class CreateFavoriteDigitalMembershipPlansTable
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
            CREATE TABLE IF NOT EXISTS `favorite_digital_membership_plans` (
                `id`                  {$pkBigint},
                `product_id`          BIGINT      NOT NULL,
                `plan_type`           VARCHAR(32) NOT NULL,
                `duration_count`      INT         NOT NULL DEFAULT 1,
                `duration_unit`       VARCHAR(16) NOT NULL DEFAULT 'day',
                `grace_period_days`   INT         NOT NULL DEFAULT 1,
                `allows_auto_renewal` TINYINT(1)  NOT NULL DEFAULT 0,
                `created_at`          TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
                `updated_at`          {$updatedAt}
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_digital_membership_plans', 'idx_fd_mplans_prod', '`product_id`');
        $this->createIndexIfNotExists('favorite_digital_membership_plans', 'idx_fd_mplans_type', '`plan_type`');
        $this->createIndexIfNotExists('favorite_digital_membership_plans', 'idx_fd_mplans_unit', '`duration_unit`');
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `favorite_digital_membership_plans`");
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

