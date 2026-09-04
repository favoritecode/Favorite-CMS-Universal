<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Pay — Migration 003: Add status and notes to favorite_pay_rates
 * Allows active/retired/inactive state management without deleting historical audit records.
 */
class AddStatusAndNotesToFavoritePayRates
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        if (!$this->db->tableExists('favorite_pay_rates')) {
            return;
        }

        $isSqlite = $this->isSqlite();

        if ($isSqlite) {
            $cols = $this->db->select("PRAGMA table_info('favorite_pay_rates')");
            $existing = array_map(fn($c) => strtolower(((array)$c)['name'] ?? ''), $cols);

            if (!in_array('status', $existing, true)) {
                $this->db->execute("ALTER TABLE `favorite_pay_rates` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'active'");
            }
            if (!in_array('notes', $existing, true)) {
                $this->db->execute("ALTER TABLE `favorite_pay_rates` ADD COLUMN `notes` VARCHAR(255) NULL");
            }
            try {
                $this->db->execute("CREATE INDEX IF NOT EXISTS `idx_fpay_rates_status` ON `favorite_pay_rates` (`status`)");
            } catch (\Throwable) {
                // Index might already exist
            }
        } else {
            $statusCols = $this->db->select("SHOW COLUMNS FROM `favorite_pay_rates` LIKE 'status'");
            if (empty($statusCols)) {
                $this->db->execute("ALTER TABLE `favorite_pay_rates` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'active' AFTER `source`");
                $this->db->execute("CREATE INDEX `idx_fpay_rates_status` ON `favorite_pay_rates` (`status`)");
            }

            $notesCols = $this->db->select("SHOW COLUMNS FROM `favorite_pay_rates` LIKE 'notes'");
            if (empty($notesCols)) {
                $this->db->execute("ALTER TABLE `favorite_pay_rates` ADD COLUMN `notes` VARCHAR(255) NULL AFTER `operator_id`");
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
