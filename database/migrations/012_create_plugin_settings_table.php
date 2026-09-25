<?php

use FavoriteCMS\Core\Database;

class CreatePluginSettingsTable
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `plugin_settings` (
                `id`          BIGINT       AUTO_INCREMENT PRIMARY KEY,
                `plugin_id`   VARCHAR(191) NOT NULL,
                `setting_key` VARCHAR(191) NOT NULL,
                `value`       TEXT         NULL,
                UNIQUE KEY `unique_plugin_key` (`plugin_id`, `setting_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `plugin_settings`');
    }
}
