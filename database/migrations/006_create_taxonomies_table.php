<?php

use FavoriteCMS\Core\Database;

class CreateTaxonomiesTable
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `taxonomies` (
                `id`          BIGINT       AUTO_INCREMENT PRIMARY KEY,
                `name`        VARCHAR(191) NOT NULL,
                `slug`        VARCHAR(191) NOT NULL UNIQUE,
                `taxonomy`    VARCHAR(50)  NOT NULL DEFAULT \'category\',
                `description` TEXT         NULL,
                `parent_id`   BIGINT       NULL,
                `post_count`  INT          DEFAULT 0,
                `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_taxonomies_taxonomy` (`taxonomy`),
                INDEX `idx_taxonomies_parent`   (`parent_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `post_taxonomies` (
                `post_id`     BIGINT NOT NULL,
                `taxonomy_id` BIGINT NOT NULL,
                PRIMARY KEY (`post_id`, `taxonomy_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Seed default category
        $this->db->execute(
            'INSERT IGNORE INTO `taxonomies` (`name`, `slug`, `taxonomy`, `description`) VALUES (?, ?, ?, ?)',
            ['Uncategorized', 'uncategorized', 'category', 'Default category']
        );
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `post_taxonomies`');
        $this->db->execute('DROP TABLE IF EXISTS `taxonomies`');
    }
}
