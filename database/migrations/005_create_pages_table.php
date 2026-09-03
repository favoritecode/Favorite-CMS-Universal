<?php

use FavoriteCMS\Core\Database;

class CreatePagesTable
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `pages` (
                `id`               BIGINT       AUTO_INCREMENT PRIMARY KEY,
                `title`            VARCHAR(500) NOT NULL,
                `slug`             VARCHAR(500) NOT NULL UNIQUE,
                `content`          LONGTEXT     NULL,
                `excerpt`          TEXT         NULL,
                `status`           ENUM(\'draft\',\'published\') DEFAULT \'draft\',
                `parent_id`        BIGINT       NULL,
                `template`         VARCHAR(100) NULL,
                `author_id`        BIGINT       NOT NULL,
                `featured_image_id` BIGINT      NULL,
                `menu_order`       INT          DEFAULT 0,
                `created_at`       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at`       DATETIME     NULL,
                INDEX `idx_pages_slug`      (`slug`(191)),
                INDEX `idx_pages_status`    (`status`),
                INDEX `idx_pages_parent_id` (`parent_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `pages`');
    }
}
