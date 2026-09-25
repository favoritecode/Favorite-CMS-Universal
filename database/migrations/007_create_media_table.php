<?php

use FavoriteCMS\Core\Database;

class CreateMediaTable
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `media` (
                `id`               BIGINT       AUTO_INCREMENT PRIMARY KEY,
                `filename`         VARCHAR(500) NOT NULL,
                `stored_filename`  VARCHAR(500) NOT NULL,
                `path`             VARCHAR(1000) NOT NULL,
                `url`              VARCHAR(1000) NOT NULL,
                `mime_type`        VARCHAR(100) NOT NULL,
                `size`             BIGINT       NOT NULL DEFAULT 0,
                `width`            INT          NULL,
                `height`           INT          NULL,
                `alt_text`         VARCHAR(500) NULL,
                `title`            VARCHAR(500) NULL,
                `description`      TEXT         NULL,
                `uploader_id`      BIGINT       NULL,
                `disk`             VARCHAR(50)  DEFAULT \'local\',
                `created_at`       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_media_mime_type`   (`mime_type`),
                INDEX `idx_media_uploader_id` (`uploader_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `media`');
    }
}
