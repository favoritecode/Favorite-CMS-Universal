<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

class CreatePasswordResetsTable
{
    public function __construct(private Database $db) {}

    public function up(): void
    {
        $this->db->execute('CREATE TABLE IF NOT EXISTS `password_resets` (
            `user_id` BIGINT NOT NULL PRIMARY KEY,
            `email` VARCHAR(191) NOT NULL,
            `token_hash` CHAR(64) NOT NULL UNIQUE,
            `expires_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        if (!$this->db->select("SHOW COLUMNS FROM `users` LIKE 'auth_version'")) {
            $this->db->execute('ALTER TABLE `users` ADD COLUMN `auth_version` INT UNSIGNED NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `password_resets`');
    }
}
