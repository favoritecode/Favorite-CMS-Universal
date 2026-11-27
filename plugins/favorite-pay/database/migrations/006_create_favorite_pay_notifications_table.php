<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Pay — Migration 006: Create favorite_pay_notifications table
 * Lightweight, shared-hosting friendly customer in-app notifications.
 */
class CreateFavoritePayNotificationsTable
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

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `favorite_pay_notifications` (
                `id`            VARCHAR(64) PRIMARY KEY,
                `user_id`       BIGINT       NOT NULL,
                `type`          VARCHAR(50)  NOT NULL,
                `title`         VARCHAR(255) NOT NULL,
                `message`       TEXT         NOT NULL,
                `withdrawal_id` VARCHAR(64)  NULL,
                `data`          TEXT         NULL,
                `is_read`       TINYINT(1)   NOT NULL DEFAULT 0,
                `read_at`       TIMESTAMP    NULL,
                `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_pay_notifications', 'idx_fpay_notif_user_read', '`user_id`, `is_read`');
        $this->createIndexIfNotExists('favorite_pay_notifications', 'idx_fpay_notif_created', '`user_id`, `created_at`');
        $this->createIndexIfNotExists('favorite_pay_notifications', 'idx_fpay_notif_wd', '`withdrawal_id`');
        $this->createIndexIfNotExists('favorite_pay_notifications', 'uniq_fpay_notif_wd_type', '`withdrawal_id`, `type`', true);
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `favorite_pay_notifications`");
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
