<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Pay — Migration 008: Remove Obsolete manual_bd Gateway Record
 *
 * Permanently cleans up the legacy generic 'manual_bd' ("Manual Bangladesh Payment")
 * entry from database tables and settings without affecting legitimate configured
 * gateways (manual_bkash, manual_nagad, manual_rocket, manual_bank, bkash_direct, binance_pay).
 */
class RemoveObsoleteManualBdGateway
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // 1. Remove manual_bd gateway from favorite_pay_gateways table
        if ($this->db->tableExists('favorite_pay_gateways')) {
            try {
                $this->db->execute("DELETE FROM `favorite_pay_gateways` WHERE `id` = 'manual_bd'");
            } catch (\Throwable) {
            }
        }

        // 2. Remove manual_bd legacy configuration from settings table(s)
        $settingsTables = ['settings', 'cms_settings'];
        foreach ($settingsTables as $table) {
            if ($this->db->tableExists($table)) {
                try {
                    $this->db->execute(
                        "DELETE FROM `{$table}` WHERE `group_name` = 'favorite_pay_manual_bd' OR `setting_key` = 'manual_bd' OR (`group_name` = 'favorite_pay' AND `setting_key` = 'manual_bd')"
                    );
                } catch (\Throwable) {
                }
            }
        }
    }

    public function down(): void
    {
        // Idempotent: manual_bd is obsolete and must not be restored.
    }
}

