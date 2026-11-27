<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Pay — Migration 007: Create favorite_pay_audit_logs table
 *
 * Lightweight, shared-hosting friendly administrative and operational audit log.
 * Provides complete operational traceability for Favorite Pay without duplicating
 * financial ledgers or altering accounting invariants.
 */
class CreateFavoritePayAuditLogsTable
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
            CREATE TABLE IF NOT EXISTS `favorite_pay_audit_logs` (
                `id`             {$pkBigint},
                `actor_user_id`  BIGINT       NULL,
                `actor_type`     VARCHAR(32)  NULL DEFAULT 'system',
                `actor_name`     VARCHAR(128) NULL,
                `action`         VARCHAR(64)  NOT NULL,
                `subject_type`   VARCHAR(64)  NOT NULL,
                `subject_id`     VARCHAR(64)  NULL,
                `withdrawal_id`  VARCHAR(64)  NULL,
                `payment_id`     VARCHAR(64)  NULL,
                `target_user_id` BIGINT       NULL,
                `description`    VARCHAR(255) NOT NULL,
                `metadata`       LONGTEXT     NULL,
                `ip_address`     VARCHAR(45)  NULL,
                `user_agent`     VARCHAR(255) NULL,
                `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_pay_audit_logs', 'idx_fpay_audit_created', '`created_at`');
        $this->createIndexIfNotExists('favorite_pay_audit_logs', 'idx_fpay_audit_action', '`action`');
        $this->createIndexIfNotExists('favorite_pay_audit_logs', 'idx_fpay_audit_actor', '`actor_user_id`');
        $this->createIndexIfNotExists('favorite_pay_audit_logs', 'idx_fpay_audit_target', '`target_user_id`');
        $this->createIndexIfNotExists('favorite_pay_audit_logs', 'idx_fpay_audit_wd', '`withdrawal_id`');
        $this->createIndexIfNotExists('favorite_pay_audit_logs', 'idx_fpay_audit_payment', '`payment_id`');
        $this->createIndexIfNotExists('favorite_pay_audit_logs', 'idx_fpay_audit_subject', '`subject_type`, `subject_id`');
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `favorite_pay_audit_logs`");
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
