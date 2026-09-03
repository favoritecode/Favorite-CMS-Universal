<?php

use FavoriteCMS\Core\Database;

class CreateCommentsTable
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `comments` (
                `id`           BIGINT       AUTO_INCREMENT PRIMARY KEY,
                `post_id`      BIGINT       NOT NULL,
                `user_id`      BIGINT       NULL,
                `author_name`  VARCHAR(191) NOT NULL,
                `author_email` VARCHAR(191) NOT NULL,
                `author_url`   VARCHAR(255) NULL,
                `author_ip`    VARCHAR(45)  NULL,
                `content`      TEXT         NOT NULL,
                `status`       ENUM(\'pending\', \'approved\', \'spam\', \'trash\') DEFAULT \'pending\',
                `parent_id`    BIGINT       NULL,
                `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_comments_post_id`    (`post_id`),
                INDEX `idx_comments_status`     (`status`),
                INDEX `idx_comments_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `comments`');
    }
}

