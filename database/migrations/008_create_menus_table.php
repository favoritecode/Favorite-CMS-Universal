<?php

use FavoriteCMS\Core\Database;

class CreateMenusTable
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `menus` (
                `id`          BIGINT       AUTO_INCREMENT PRIMARY KEY,
                `name`        VARCHAR(191) NOT NULL,
                `slug`        VARCHAR(191) NOT NULL UNIQUE,
                `description` TEXT         NULL,
                `location`    VARCHAR(100) NULL,
                `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `menu_items` (
                `id`         BIGINT       AUTO_INCREMENT PRIMARY KEY,
                `menu_id`    BIGINT       NOT NULL,
                `parent_id`  BIGINT       NULL,
                `title`      VARCHAR(255) NOT NULL,
                `url`        VARCHAR(500) NULL,
                `type`       VARCHAR(50)  NOT NULL DEFAULT \'custom\',
                `object_id`  BIGINT       NULL,
                `target`     VARCHAR(20)  DEFAULT \'_self\',
                `css_class`  VARCHAR(255) NULL,
                `sort_order` INT          DEFAULT 0,
                `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_menu_items_menu_id`   (`menu_id`),
                INDEX `idx_menu_items_parent_id` (`parent_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `menu_items`');
        $this->db->execute('DROP TABLE IF EXISTS `menus`');
    }
}
