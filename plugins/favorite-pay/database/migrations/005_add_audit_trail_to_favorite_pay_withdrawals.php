<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Pay — Migration 005: Add audit_trail to favorite_pay_withdrawals
 * Supports append-only administrative audit lifecycle and compliance.
 */
class AddAuditTrailToFavoritePayWithdrawals
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        if (!$this->db->tableExists('favorite_pay_withdrawals')) {
            return;
        }

        $isSqlite = $this->isSqlite();

        if ($isSqlite) {
            $tableName = method_exists($this->db, 'table') ? $this->db->table('favorite_pay_withdrawals') : 'favorite_pay_withdrawals';
            $cols = $this->db->select("PRAGMA table_info('{$tableName}')");
            $existing = array_map(fn($c) => strtolower(((array)$c)['name'] ?? ''), $cols);

            if (!in_array('audit_trail', $existing, true)) {
                try {
                    $this->db->execute("ALTER TABLE `favorite_pay_withdrawals` ADD COLUMN `audit_trail` TEXT NULL");
                } catch (\Throwable) {
                }
            }
        } else {
            $auditCols = $this->db->select("SHOW COLUMNS FROM `favorite_pay_withdrawals` LIKE 'audit_trail'");
            if (empty($auditCols)) {
                $this->db->execute("ALTER TABLE `favorite_pay_withdrawals` ADD COLUMN `audit_trail` LONGTEXT NULL AFTER `operator_notes`");
            }
        }
    }

    public function down(): void
    {
        // Non-destructive rollback to preserve audit history
    }

    private function isSqlite(): bool
    {
        try {
            $driver = $this->db->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            return strtolower((string)$driver) === 'sqlite';
        } catch (\Throwable) {
            return false;
        }
    }
}
