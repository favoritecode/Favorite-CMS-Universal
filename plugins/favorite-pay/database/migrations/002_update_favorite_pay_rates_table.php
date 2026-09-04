<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Pay — Migration 002: Update favorite_pay_rates table
 * Expands currency column length for crypto assets and adds expiration timestamp.
 */
class UpdateFavoritePayRatesTable
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

        if (!$isSqlite) {
            // Expand currency columns for 4+ char crypto codes (USDT, USDC)
            $this->db->execute("ALTER TABLE `favorite_pay_rates` MODIFY `base_currency` VARCHAR(16) NOT NULL DEFAULT 'BDT'");
            $this->db->execute("ALTER TABLE `favorite_pay_rates` MODIFY `quote_currency` VARCHAR(16) NOT NULL");

            // Add expires_at column if not exists
            $columns = $this->db->select("SHOW COLUMNS FROM `favorite_pay_rates` LIKE 'expires_at'");
            if (empty($columns)) {
                $this->db->execute("ALTER TABLE `favorite_pay_rates` ADD COLUMN `expires_at` DATETIME NULL AFTER `effective_at`");
                $this->db->execute("CREATE INDEX `idx_fpay_rates_expires` ON `favorite_pay_rates` (`expires_at`)");
            }
        }
    }

    public function down(): void
    {
        // Non-destructive rollback
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
