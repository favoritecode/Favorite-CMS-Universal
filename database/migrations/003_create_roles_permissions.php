<?php

use FavoriteCMS\Core\Database;

class CreateRolesPermissions
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `roles` (
                `id`          BIGINT       AUTO_INCREMENT PRIMARY KEY,
                `name`        VARCHAR(191) NOT NULL,
                `slug`        VARCHAR(191) NOT NULL UNIQUE,
                `description` TEXT         NULL,
                `is_system`   BOOLEAN      DEFAULT 0,
                `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `permissions` (
                `id`          BIGINT       AUTO_INCREMENT PRIMARY KEY,
                `name`        VARCHAR(191) NOT NULL,
                `slug`        VARCHAR(191) NOT NULL UNIQUE,
                `description` TEXT         NULL,
                `group_name`  VARCHAR(100) NOT NULL,
                `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `role_permissions` (
                `role_id`       BIGINT NOT NULL,
                `permission_id` BIGINT NOT NULL,
                PRIMARY KEY (`role_id`, `permission_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `user_roles` (
                `user_id` BIGINT NOT NULL,
                `role_id` BIGINT NOT NULL,
                PRIMARY KEY (`user_id`, `role_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Seed default roles
        $roles = [
            ['super-admin', 'Super Admin', 'Full system access', 1],
            ['admin',       'Admin',       'Administrative access', 1],
            ['editor',      'Editor',      'Can manage all content', 1],
            ['author',      'Author',      'Can create and manage own content', 1],
            ['subscriber',  'Subscriber',  'Basic subscriber role', 1],
        ];
        foreach ($roles as [$slug, $name, $desc, $sys]) {
            $this->db->execute(
                'INSERT IGNORE INTO `roles` (`name`, `slug`, `description`, `is_system`) VALUES (?, ?, ?, ?)',
                [$name, $slug, $desc, $sys]
            );
        }

        // Seed default permissions
        $permissions = [
            ['manage_posts',    'Manage Posts',    'Create, edit, delete posts',     'content'],
            ['publish_posts',   'Publish Posts',   'Publish and unpublish posts',    'content'],
            ['manage_pages',    'Manage Pages',    'Create, edit, delete pages',     'content'],
            ['publish_pages',   'Publish Pages',   'Publish and unpublish pages',    'content'],
            ['manage_media',    'Manage Media',    'Upload and manage media',        'content'],
            ['manage_menus',    'Manage Menus',    'Create and edit menus',          'content'],
            ['manage_taxonomy', 'Manage Taxonomy', 'Manage categories and tags',     'content'],
            ['manage_users',    'Manage Users',    'Create and manage users',        'users'],
            ['manage_roles',    'Manage Roles',    'Create and manage roles',        'users'],
            ['manage_themes',   'Manage Themes',   'Install and activate themes',    'system'],
            ['manage_plugins',  'Manage Plugins',  'Install and manage plugins',     'system'],
            ['manage_settings', 'Manage Settings', 'Change site settings',           'system'],
            ['manage_seo',      'Manage SEO',      'Configure SEO settings',         'system'],
            ['view_admin',      'View Admin',      'Access the admin panel',         'admin'],
        ];
        foreach ($permissions as [$slug, $name, $desc, $group]) {
            $this->db->execute(
                'INSERT IGNORE INTO `permissions` (`name`, `slug`, `description`, `group_name`) VALUES (?, ?, ?, ?)',
                [$name, $slug, $desc, $group]
            );
        }
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `user_roles`');
        $this->db->execute('DROP TABLE IF EXISTS `role_permissions`');
        $this->db->execute('DROP TABLE IF EXISTS `permissions`');
        $this->db->execute('DROP TABLE IF EXISTS `roles`');
    }
}
