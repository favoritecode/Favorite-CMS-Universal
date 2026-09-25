<?php

use FavoriteCMS\Core\Database;

class CreateCmsMigrationsTable
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `cms_migrations` (
                `id`         INT AUTO_INCREMENT PRIMARY KEY,
                `migration`  VARCHAR(255) NOT NULL,
                `batch`      INT          NOT NULL,
                `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `cms_migrations`');
    }
}
