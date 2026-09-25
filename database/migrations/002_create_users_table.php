<?php

use FavoriteCMS\Core\Database;

class CreateUsersTable
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `users` (
                `id`                BIGINT       AUTO_INCREMENT PRIMARY KEY,
                `username`          VARCHAR(191) NULL UNIQUE,
                `name`              VARCHAR(191) NOT NULL,
                `email`             VARCHAR(191) NOT NULL UNIQUE,
                `password`          VARCHAR(255) NOT NULL,
                `email_verified_at` DATETIME     NULL,
                `remember_token`    VARCHAR(100) NULL,
                `status`            ENUM(\'active\',\'inactive\',\'suspended\',\'banned\') DEFAULT \'active\',
                `avatar`            VARCHAR(500) NULL,
                `bio`               TEXT         NULL,
                `last_login_at`     DATETIME     NULL,
                `created_at`        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `users`');
    }
}
