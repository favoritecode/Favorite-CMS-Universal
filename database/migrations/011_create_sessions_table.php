<?php

use FavoriteCMS\Core\Database;

class CreateSessionsTable
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `sessions` (
                `id`            VARCHAR(255) NOT NULL PRIMARY KEY,
                `user_id`       BIGINT       NULL,
                `ip_address`    VARCHAR(45)  NULL,
                `user_agent`    TEXT         NULL,
                `payload`       LONGTEXT     NOT NULL,
                `last_activity` INT          NOT NULL,
                INDEX `idx_sessions_user_id`       (`user_id`),
                INDEX `idx_sessions_last_activity` (`last_activity`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `sessions`');
    }
}
