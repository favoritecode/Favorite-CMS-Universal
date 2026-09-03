<?php

use FavoriteCMS\Core\Database;

class CreatePostsTable
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `posts` (
                `id`               BIGINT       AUTO_INCREMENT PRIMARY KEY,
                `title`            VARCHAR(500) NOT NULL,
                `slug`             VARCHAR(500) NOT NULL UNIQUE,
                `content`          LONGTEXT     NULL,
                `excerpt`          TEXT         NULL,
                `status`           ENUM(\'draft\',\'published\',\'scheduled\',\'archived\') DEFAULT \'draft\',
                `type`             VARCHAR(50)  DEFAULT \'post\',
                `author_id`        BIGINT       NOT NULL,
                `featured_image_id` BIGINT      NULL,
                `published_at`     DATETIME     NULL,
                `created_at`       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at`       DATETIME     NULL,
                INDEX `idx_posts_slug`         (`slug`(191)),
                INDEX `idx_posts_status`       (`status`),
                INDEX `idx_posts_published_at` (`published_at`),
                INDEX `idx_posts_author_id`    (`author_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `posts`');
    }
}
