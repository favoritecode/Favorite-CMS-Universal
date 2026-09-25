<?php

use FavoriteCMS\Core\Database;

class UpdateRolePermissions
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
            ['moderate_comments', 'Moderate Comments',      'Approve, unapprove, spam, and trash comments',       'content'],
            ['edit_others_posts', 'Edit Other Users Posts', 'Edit posts authored by other users',                 'content'],
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

        // 3. Define target permission sets
        $rolePerms = [
            'admin' => [
                'view_admin', 'manage_posts', 'edit_others_posts', 'publish_posts', 'approve_posts',
                'moderate_comments', 'manage_pages', 'publish_pages', 'manage_media', 'upload_large_media',
                'upload_moderator_media', 'manage_menus', 'manage_taxonomy', 'manage_users', 'manage_roles',
                'manage_themes', 'manage_plugins', 'manage_settings', 'unfiltered_html', 'manage_seo',
            ],
            'editor' => [
                'view_admin', 'manage_posts', 'edit_others_posts', 'publish_posts', 'approve_posts',
                'moderate_comments', 'manage_pages', 'publish_pages', 'manage_media',
                'upload_moderator_media', 'manage_menus', 'manage_taxonomy', 'manage_seo',
            ],
            'moderator' => [
                'view_admin', 'manage_posts', 'edit_others_posts', 'publish_posts', 'approve_posts',
                'moderate_comments', 'manage_media', 'upload_moderator_media',
            ],
            'author' => [
                'view_admin', 'manage_posts', 'manage_media',
            ],
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
    }

    public function down(): void
    {
        // Non-destructive down: remove newly seeded role_permissions associations
        $perms = $this->db->select("SELECT `id` FROM `permissions` WHERE `slug` IN ('moderate_comments', 'edit_others_posts')");
        foreach ($perms as $p) {
            $this->db->execute("DELETE FROM `role_permissions` WHERE `permission_id` = ?", [$p->id]);
            $this->db->execute("DELETE FROM `permissions` WHERE `id` = ?", [$p->id]);
        }
    }
}
