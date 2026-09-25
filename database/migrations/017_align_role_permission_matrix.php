<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

class AlignRolePermissionMatrix
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // 1. Seed missing permissions idempotently
        $newPermissions = [
            ['upload_media',        'Upload Media',        'Upload media files',                   'content'],
            ['delete_posts',        'Delete Own Posts',    'Delete posts authored by own account', 'content'],
            ['delete_others_posts', 'Delete Others Posts', 'Delete posts authored by other users', 'content'],
            ['create_posts',        'Create Posts',        'Create new posts',                     'content'],
            ['edit_posts',          'Edit Posts',          'Edit posts',                           'content'],
        ];

        foreach ($newPermissions as [$slug, $name, $desc, $group]) {
            $this->db->execute(
                'INSERT IGNORE INTO `permissions` (`name`, `slug`, `description`, `group_name`) VALUES (?, ?, ?, ?)',
                [$name, $slug, $desc, $group]
            );
        }

        // 2. Fetch role and permission maps
        $roles = $this->db->select("SELECT `id`, `slug` FROM `roles`");
        $permissions = $this->db->select("SELECT `id`, `slug` FROM `permissions`");

        $roleMap = [];
        foreach ($roles as $r) {
            $roleMap[(string)$r->slug] = (int)$r->id;
        }

        $permMap = [];
        foreach ($permissions as $p) {
            $permMap[(string)$p->slug] = (int)$p->id;
        }

        // 3. Define target permission sets based on the 6-role permission matrix
        $rolePerms = [
            'admin' => [
                'view_admin', 'manage_posts', 'create_posts', 'edit_posts', 'edit_others_posts',
                'delete_posts', 'delete_others_posts', 'publish_posts', 'approve_posts',
                'moderate_comments', 'manage_pages', 'publish_pages', 'manage_media',
                'upload_media', 'upload_large_media', 'upload_moderator_media', 'manage_menus',
                'manage_taxonomy', 'manage_users', 'manage_roles', 'manage_themes',
                'manage_plugins', 'manage_settings', 'unfiltered_html', 'manage_seo',
            ],
            'editor' => [
                'view_admin', 'manage_posts', 'create_posts', 'edit_posts', 'edit_others_posts',
                'delete_posts', 'publish_posts', 'moderate_comments', 'manage_pages',
                'publish_pages', 'manage_media', 'upload_media', 'upload_moderator_media',
                'manage_menus', 'manage_taxonomy', 'manage_seo',
            ],
            'moderator' => [
                'view_admin', 'create_posts', 'edit_posts', 'manage_posts', 'edit_others_posts',
                'delete_posts', 'approve_posts', 'moderate_comments', 'upload_media',
                'upload_moderator_media',
            ],
            'author' => [
                'view_admin', 'create_posts', 'edit_posts', 'manage_posts', 'delete_posts',
                'upload_media',
            ],
            'subscriber' => [
                'view_admin',
            ],
        ];

        // Permissions to revoke for strict matrix compliance
        $revocations = [
            'editor' => ['delete_others_posts', 'approve_posts'],
            'moderator' => ['manage_media', 'delete_others_posts', 'manage_pages', 'publish_pages', 'manage_menus', 'manage_taxonomy', 'manage_seo'],
            'author' => ['manage_media', 'delete_others_posts', 'edit_others_posts', 'approve_posts', 'moderate_comments', 'manage_pages', 'publish_pages', 'manage_menus', 'manage_taxonomy', 'manage_seo'],
            'subscriber' => ['manage_media', 'upload_media', 'delete_posts', 'delete_others_posts', 'edit_others_posts', 'manage_posts', 'approve_posts', 'moderate_comments', 'manage_pages', 'publish_pages', 'manage_menus', 'manage_taxonomy', 'manage_seo'],
        ];

        foreach ($rolePerms as $roleSlug => $slugs) {
            if (!isset($roleMap[$roleSlug])) {
                continue;
            }
            $roleId = $roleMap[$roleSlug];
            foreach ($slugs as $pSlug) {
                if (isset($permMap[$pSlug])) {
                    $permId = $permMap[$pSlug];
                    $this->db->execute(
                        'INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)',
                        [$roleId, $permId]
                    );
                }
            }
        }

        foreach ($revocations as $roleSlug => $slugs) {
            if (!isset($roleMap[$roleSlug])) {
                continue;
            }
            $roleId = $roleMap[$roleSlug];
            foreach ($slugs as $pSlug) {
                if (isset($permMap[$pSlug])) {
                    $permId = $permMap[$pSlug];
                    $this->db->execute(
                        'DELETE FROM `role_permissions` WHERE `role_id` = ? AND `permission_id` = ?',
                        [$roleId, $permId]
                    );
                }
            }
        }
    }

    public function down(): void
    {
    }
}

