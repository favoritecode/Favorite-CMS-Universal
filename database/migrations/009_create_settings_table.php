<?php

use FavoriteCMS\Core\Database;

class CreateSettingsTable
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $isSqlite = $this->isSqlite();
        $engine = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pk = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT AUTO_INCREMENT PRIMARY KEY';
        $updatedAt = $isSqlite ? 'DATETIME DEFAULT CURRENT_TIMESTAMP' : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
        $insertIgnore = $isSqlite ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `settings` (
                `id`          {$pk},
                `group_name`  VARCHAR(100)  NOT NULL,
                `setting_key` VARCHAR(100)  NOT NULL,
                `value`       TEXT          NULL,
                `type`        VARCHAR(50)   DEFAULT 'string',
                `label`       VARCHAR(255)  NULL,
                `description` TEXT          NULL,
                `is_public`   BOOLEAN       DEFAULT 0,
                `created_at`  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  {$updatedAt},
                UNIQUE (`group_name`, `setting_key`)
            ){$engine};
        ");

        // Seed default settings
        $defaults = [
            ['general', 'site_name',        'Favorite CMS',     'string', 'Site Name',        1],
            ['general', 'site_description', '',                  'string', 'Site Description',  1],
            ['general', 'admin_email',      'admin@example.com', 'string', 'Admin Email',       0],
            ['general', 'timezone',         'UTC',               'string', 'Timezone',          0],
            ['general', 'primary_currency', 'BDT',               'string', 'Primary Currency',  1],
            ['general', 'date_format',      'Y-m-d',             'string', 'Date Format',       0],
            ['general', 'time_format',        'H:i',               'string', 'Time Format',       0],
            ['general', 'allow_registration', '1',                 'bool',   'Allow Registration',0],
            ['reading','posts_per_page',    '10',                'int',    'Posts Per Page',    0],
            ['reading','front_page_display','posts',             'string', 'Front Page Displays',0],
            ['writing','default_category',  '1',                 'int',    'Default Category',  0],
            ['media',  'uploads_path',              'uploads',           'string', 'Uploads Path',              0],
            ['media',  'max_upload_size_admin',     '7516192768',        'int',    'Admin Max Upload Size',     0],
            ['media',  'max_upload_size_moderator', '524288000',         'int',    'Moderator Max Upload Size', 0],
            ['media',  'max_upload_size_user',      '209715200',         'int',    'User Max Upload Size',      0],
            ['media',  'allowed_categories',        'images,videos,audio,documents,archives', 'string', 'Allowed Categories', 0],
            ['seo',    'meta_title_sep',        ' | ',               'string', 'Title Separator',   0],
            ['seo',    'robots_txt',            "User-agent: *\nAllow: /", 'text', 'Robots.txt',   0],
        ];

        foreach ($defaults as [$group, $key, $val, $type, $label, $public]) {
            $this->db->execute(
                "{$insertIgnore} `settings` (`group_name`, `setting_key`, `value`, `type`, `label`, `is_public`)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$group, $key, $val, $type, $label, $public]
            );
        }
    }

    protected function isSqlite(): bool
    {
        try {
            $driver = $this->db->getConnection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            return strtolower((string)$driver) === 'sqlite';
        } catch (\Throwable) {
            return false;
        }
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `settings`');
    }
}
