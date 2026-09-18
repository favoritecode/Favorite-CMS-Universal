<?php

use FavoriteCMS\Core\Database;

class CreateSeoMetaTable
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `seo_meta` (
                `id`               BIGINT       AUTO_INCREMENT PRIMARY KEY,
                `object_type`      VARCHAR(50)  NOT NULL,
                `object_id`        BIGINT       NOT NULL,
                `meta_title`       VARCHAR(500) NULL,
                `meta_description` TEXT         NULL,
                `og_title`         VARCHAR(500) NULL,
                `og_description`   TEXT         NULL,
                `og_image_id`      BIGINT       NULL,
                `canonical_url`    VARCHAR(1000) NULL,
                `robots`           VARCHAR(100) DEFAULT \'index,follow\',
                `schema_type`      VARCHAR(100) NULL,
                `created_at`       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_object` (`object_type`, `object_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `seo_meta`');
    }
}
