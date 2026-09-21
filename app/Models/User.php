<?php

declare(strict_types=1);

namespace FavoriteCMS\Models;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Container;

class User extends BaseModel
{
    protected static string $table = 'users';

    public static function findByEmail(string $email): ?self
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $result = $db->selectOne("SELECT * FROM users WHERE email = ?", [$email]);
        return $result ? new static((array)$result) : null;
    }

    public static function findByUsername(string $username): ?self
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $result = $db->selectOne("SELECT * FROM users WHERE username = ?", [$username]);
        return $result ? new static((array)$result) : null;
    }

    public static function findByLogin(string $login): ?self
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $result = $db->selectOne("SELECT * FROM users WHERE email = ? OR username = ?", [$login, $login]);
        return $result ? new static((array)$result) : null;
    }

    public function verifyPassword(string $password): bool
    {
        return \FavoriteCMS\Services\PasswordHasher::verify($password, (string)($this->password ?? ''));
    }

    public function setPassword(string $plain): void
    {
        $this->password = \FavoriteCMS\Services\PasswordHasher::hash($plain);
    }

    public function toArray(): array
    {
        $array = parent::toArray();
        unset($array['password']);
        return $array;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super-admin');
    }

    public static function getActiveSuperAdminCount(?Database $db = null, bool $forUpdate = false): int
    {
        $db = $db ?? Container::getInstance()->get(Database::class);
        if ($forUpdate) {
            try {
                $db->select("SELECT `id` FROM `roles` WHERE `slug` IN ('super-admin', 'super_admin') OR `name` = 'Super Admin' FOR UPDATE");
            } catch (\Throwable) {
                // Ignore if DB driver does not support FOR UPDATE
            }
        }
        $row = $db->selectOne(
            "SELECT COUNT(DISTINCT u.`id`) as cnt
             FROM `users` u
             JOIN `user_roles` ur ON u.`id` = ur.`user_id`
             JOIN `roles` r ON ur.`role_id` = r.`id`
             WHERE (r.`slug` IN ('super-admin', 'super_admin') OR r.`name` = 'Super Admin') AND u.`status` = 'active'"
        );
        return (int)($row->cnt ?? 0);
    }

    public function getRoles(): array
    {
        $roles = [];
        if (!empty($this->id)) {
            $sql = "SELECT r.* FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?";
            $roles = $this->db->select($sql, [$this->id]);
        }
        if (empty($roles) && !empty($this->role_id)) {
            $r = $this->db->selectOne("SELECT * FROM roles WHERE id = ?", [(int)$this->role_id]);
            if ($r) {
                $roles = [$r];
            }
        }
        return $roles;
    }

    public function hasRole(string $roleSlug): bool
    {
        $target = strtolower(trim($roleSlug));
        $cleanTarget = str_replace(['_', ' '], '-', $target);
        $compactTarget = str_replace(['_', ' ', '-'], '', $target);

        $roles = $this->getRoles();
        foreach ($roles as $role) {
            $slug = strtolower(trim((string)($role->slug ?? '')));
            $cleanSlug = str_replace(['_', ' '], '-', $slug);
            $compactSlug = str_replace(['_', ' ', '-'], '', $slug);

            if ($slug === $target || $cleanSlug === $cleanTarget || $compactSlug === $compactTarget) {
                return true;
            }

            $name = strtolower(trim((string)($role->name ?? '')));
            $cleanName = str_replace(['_', ' '], '-', $name);
            $compactName = str_replace(['_', ' ', '-'], '', $name);
            if ($name === $target || $cleanName === $cleanTarget || $compactName === $compactTarget) {
                return true;
            }

            // Normalization for administrator / admin
            if (($cleanTarget === 'administrator' || $compactTarget === 'administrator') && ($cleanSlug === 'admin' || $cleanSlug === 'administrator')) {
                return true;
            }
            if (($cleanTarget === 'admin' || $compactTarget === 'admin') && ($cleanSlug === 'admin' || $cleanSlug === 'administrator')) {
                return true;
            }

            // Normalization for super-admin / superadmin / super_admin
            if (($cleanTarget === 'super-admin' || $compactTarget === 'superadmin') && ($cleanSlug === 'super-admin' || $compactSlug === 'superadmin')) {
                return true;
            }
        }
        return false;
    }

    public function getPermissions(): array
    {
        $sql = "SELECT p.* FROM permissions p 
                JOIN role_permissions rp ON p.id = rp.permission_id 
                JOIN user_roles ur ON rp.role_id = ur.role_id 
                WHERE ur.user_id = ?";
        return $this->db->select($sql, [$this->id]);
    }

    public static function getDefaultRolePermissions(string $roleSlug): array
    {
        $target = strtolower(trim(str_replace(['_', ' '], '-', $roleSlug)));
        return match ($target) {
            'super-admin', 'superadmin' => [
                'view_admin', 'manage_posts', 'edit_others_posts', 'publish_posts', 'approve_posts',
                'moderate_comments', 'manage_pages', 'publish_pages', 'manage_media', 'upload_large_media',
                'upload_moderator_media', 'manage_menus', 'manage_taxonomy', 'manage_users', 'manage_roles',
                'manage_themes', 'manage_plugins', 'manage_settings', 'unfiltered_html', 'manage_seo',
                'manage_options',
            ],
            'admin', 'administrator' => [
                'view_admin', 'manage_posts', 'edit_others_posts', 'publish_posts', 'approve_posts',
                'moderate_comments', 'manage_pages', 'publish_pages', 'manage_media', 'upload_large_media',
                'upload_moderator_media', 'manage_menus', 'manage_taxonomy', 'manage_users', 'manage_roles',
                'manage_themes', 'manage_plugins', 'manage_settings', 'unfiltered_html', 'manage_seo',
                'manage_options',
            ],
            'editor' => [
                'view_admin', 'manage_posts', 'edit_others_posts', 'publish_posts',
                'moderate_comments', 'manage_pages', 'publish_pages', 'manage_media',
                'upload_moderator_media', 'manage_menus', 'manage_taxonomy', 'manage_seo',
            ],
            'moderator' => [
                'view_admin', 'manage_posts', 'edit_others_posts',
                'moderate_comments', 'upload_moderator_media',
            ],
            'author' => [
                'view_admin', 'manage_posts', 'upload_moderator_media',
            ],
            'subscriber' => [
                'view_admin',
            ],
            default => [],
        };
    }

    public function hasPermission(string $permissionSlug): bool
    {
        if ($this->hasRole('super-admin') || $this->isSuperAdmin()) {
            return true;
        }

        $cleanSlug = strtolower(trim(str_replace(['_', ' '], '-', $permissionSlug)));

        // Built-in role capability resolution
        $roleSlug = $this->getPrimaryRoleSlug();
        $builtInPermissions = self::getDefaultRolePermissions($roleSlug);
        if (in_array($permissionSlug, $builtInPermissions, true) || in_array($cleanSlug, $builtInPermissions, true)) {
            return true;
        }

        $permissions = $this->getPermissions();
        foreach ($permissions as $permission) {
            $pSlug = strtolower(trim((string)($permission->slug ?? '')));
            $cleanPSlug = str_replace(['_', ' '], '-', $pSlug);
            if ($pSlug === $permissionSlug || $cleanPSlug === $cleanSlug) {
                return true;
            }
        }
        return false;
    }

    public function assignRole(int $roleId): void
    {
        $this->db->insert('user_roles', [
            'user_id' => $this->id,
            'role_id' => $roleId
        ]);
    }

    public function removeRole(int $roleId): void
    {
        $this->db->delete('user_roles', [
            'user_id' => $this->id,
            'role_id' => $roleId
        ]);
    }

    public function isBanned(): bool
    {
        return ($this->status ?? 'active') === 'banned';
    }

    public function isSuspended(): bool
    {
        return ($this->status ?? 'active') === 'suspended';
    }

    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }

    public function isEmailVerified(): bool
    {
        return !empty($this->email_verified_at);
    }

    public function markEmailAsVerified(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->update([
            'email_verified_at' => $now,
            'updated_at'        => $now,
        ]);
    }

    public function canSelfDelete(): bool
    {
        // Only active accounts may self-delete. Suspended or banned accounts cannot.
        if (!$this->isActive()) {
            return false;
        }

        // Prevent deleting the last remaining active Super Admin
        if ($this->hasRole('super-admin')) {
            if (static::getActiveSuperAdminCount($this->db) <= 1) {
                return false;
            }
        }

        // Prevent deleting the last remaining active site administrator
        if ($this->hasRole('super-admin') || $this->hasRole('admin')) {
            $adminCount = $this->db->selectOne(
                "SELECT COUNT(DISTINCT u.id) as cnt FROM `users` u
                 JOIN `user_roles` ur ON u.id = ur.user_id
                 JOIN `roles` r ON ur.role_id = r.id
                 WHERE r.slug IN ('admin', 'super-admin') AND u.status = 'active'"
            );
            if ((int)($adminCount->cnt ?? 0) <= 1) {
                return false;
            }
        }

        return true;
    }

    public function deleteAccount(int $fallbackAdminId): void
    {
        $action = function() use ($fallbackAdminId) {
            // Lock roles row and enforce the invariant that at least one Super Admin must remain
            if ($this->hasRole('super-admin') && static::getActiveSuperAdminCount($this->db, true) <= 1) {
                throw new \RuntimeException('Cannot delete the last remaining active Super Admin account.');
            }

            if (!$this->canSelfDelete()) {
                throw new \RuntimeException('This account is not eligible for self-deletion.');
            }

            // 1. Delete local avatar file if present
            if (!empty($this->avatar)) {
                $avatarService = new \FavoriteCMS\Services\AvatarService();
                $avatarService->deleteLocalAvatarFile($this->avatar);
            }

            // 2. Reassign authored posts to fallback admin to preserve site content
            $this->db->execute(
                "UPDATE `posts` SET `author_id` = ? WHERE `author_id` = ?",
                [$fallbackAdminId, $this->id]
            );

            // 3. Reassign authored pages to fallback admin
            $this->db->execute(
                "UPDATE `pages` SET `author_id` = ? WHERE `author_id` = ?",
                [$fallbackAdminId, $this->id]
            );

            // 4. Preserve media records by reassigning uploader_id
            $this->db->execute(
                "UPDATE `media` SET `uploader_id` = ? WHERE `uploader_id` = ?",
                [$fallbackAdminId, $this->id]
            );

            // 5. Delete user roles
            $this->db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [$this->id]);

            // 6. Delete email verification records
            $this->db->execute("DELETE FROM `email_verifications` WHERE `user_id` = ?", [$this->id]);

            // 7. Delete sessions
            $this->db->execute("DELETE FROM `sessions` WHERE `user_id` = ?", [$this->id]);

            // 8. Delete user record
            $this->delete();
        };

        if ($this->db->getPdo()->inTransaction()) {
            $action();
        } else {
            $this->db->transaction($action);
        }
    }

    public function isEligibleForSuperAdminRecovery(): bool
    {
        // 1. Invariant check: Must have ZERO active Super Admins system-wide
        if (static::getActiveSuperAdminCount($this->db) > 0) {
            return false;
        }

        // 2. User ID must be 1 (original primary site administrator account)
        if ((int)$this->id !== 1) {
            return false;
        }

        // 3. Account must be active (suspended or banned accounts cannot recover)
        if (!$this->isActive()) {
            return false;
        }

        // 4. Email must match site admin_email setting if configured
        $adminEmail = (string)\FavoriteCMS\Models\Setting::get('general', 'admin_email', '');
        if ($adminEmail !== '' && strcasecmp(trim((string)$this->email), trim($adminEmail)) !== 0) {
            return false;
        }

        // 5. If storage/installed.lock exists and defines installed_by, verify match
        $lockPath = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/installed.lock';
        if (is_file($lockPath)) {
            $lockContent = (string)file_get_contents($lockPath);
            if (preg_match('/^installed_by=(.+)$/m', $lockContent, $m)) {
                $installedBy = trim($m[1]);
                if ($installedBy !== '' && !in_array($installedBy, [(string)$this->username, (string)$this->email], true)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function recoverSuperAdmin(string $password): bool
    {
        $action = function() use ($password) {
            // Lock roles row to guarantee exclusive recovery execution
            try {
                $this->db->select("SELECT `id` FROM `roles` WHERE `slug` IN ('super-admin', 'super_admin') OR `name` = 'Super Admin' FOR UPDATE");
            } catch (\Throwable) {
            }

            // Re-verify eligibility inside the transaction lock
            if (!$this->isEligibleForSuperAdminRecovery()) {
                return false;
            }

            // Verify password
            if (!$this->verifyPassword($password)) {
                return false;
            }

            $role = $this->db->selectOne("SELECT `id` FROM `roles` WHERE `slug` = 'super-admin' LIMIT 1");
            if (!$role) {
                $now = date('Y-m-d H:i:s');
                $roleId = $this->db->insert('roles', [
                    'name'        => 'Super Admin',
                    'slug'        => 'super-admin',
                    'description' => 'Full system access',
                    'is_system'   => 1,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            } else {
                $roleId = (int)$role->id;
            }

            // Assign super-admin role
            $this->db->execute("DELETE FROM `user_roles` WHERE `user_id` = ?", [$this->id]);
            $this->db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$this->id, $roleId]);

            // Synchronize active session role
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['auth_user_role'] = 'super-admin';
            }

            // Security audit log entry
            $logDir = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $logEntry = sprintf(
                "[%s] SECURITY AUDIT: Super Admin role recovered for user ID %d (%s, %s) by emergency recovery flow from IP %s\n",
                date('Y-m-d H:i:s'),
                $this->id,
                $this->username,
                $this->email,
                $ip
            );
            @file_put_contents($logDir . '/security.log', $logEntry, FILE_APPEND | LOCK_EX);

            return true;
        };

        if ($this->db->getPdo()->inTransaction()) {
            return (bool)$action();
        }

        return (bool)$this->db->transaction($action);
    }

    public function canCreatePosts(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->hasRole('subscriber')) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('editor')
            || $this->hasRole('moderator')
            || $this->hasRole('author')
            || $this->hasPermission('create_posts');
    }

    public function canUpdatePosts(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->hasRole('subscriber')) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('editor')
            || $this->hasRole('moderator')
            || $this->hasRole('author')
            || $this->hasPermission('manage_posts')
            || $this->hasPermission('edit_posts');
    }

    public function canUploadMedia(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('editor')
            || $this->hasRole('moderator')
            || $this->hasRole('author')
            || $this->hasPermission('upload_media')
            || $this->hasPermission('upload_files')
            || $this->hasPermission('upload_moderator_media')
            || $this->hasPermission('manage_media');
    }

    public function canManageMedia(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('editor')
            || $this->hasPermission('manage_media');
    }

    public function canEditOwnPosts(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('editor')
            || $this->hasRole('moderator')
            || $this->hasRole('author')
            || $this->hasPermission('edit_posts')
            || $this->hasPermission('manage_posts');
    }

    public function canEditOtherPosts(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('editor')
            || $this->hasRole('moderator')
            || $this->hasPermission('edit_others_posts');
    }

    public function canEditPost(mixed $post): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        $authorId = is_object($post) ? (int)($post->author_id ?? 0) : (int)$post;
        if ($authorId <= 0) {
            return false;
        }

        if ($authorId === (int)$this->id) {
            return $this->canEditOwnPosts();
        }

        return $this->canEditOtherPosts();
    }

    public function canDeleteOwnPosts(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('editor')
            || $this->hasRole('moderator')
            || $this->hasRole('author')
            || $this->hasPermission('delete_posts');
    }

    public function canDeleteOtherPosts(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasPermission('delete_others_posts');
    }

    public function canDeletePost(mixed $post): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        $authorId = is_object($post) ? (int)($post->author_id ?? 0) : (int)$post;
        if ($authorId <= 0) {
            return false;
        }

        if ($authorId === (int)$this->id) {
            return $this->canDeleteOwnPosts();
        }

        return $this->canDeleteOtherPosts();
    }

    public function canRestoreOwnPosts(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('editor')
            || $this->hasRole('moderator')
            || $this->hasRole('author')
            || $this->hasPermission('delete_posts');
    }

    public function canRestorePost(mixed $post): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        $authorId = is_object($post) ? (int)($post->author_id ?? 0) : (int)$post;
        if ($authorId <= 0) {
            return false;
        }

        if ($authorId === (int)$this->id) {
            return $this->canRestoreOwnPosts();
        }

        return $this->canDeleteOtherPosts();
    }

    public function canEditOwnContentSeo(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('editor')
            || $this->hasRole('moderator')
            || $this->hasRole('author');
    }

    public function canEditOtherContentSeo(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('editor')
            || $this->hasPermission('manage_seo');
    }

    public function canEditContentSeo(mixed $content): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        $authorId = is_object($content) ? (int)($content->author_id ?? 0) : (int)$content;
        if ($authorId <= 0) {
            return false;
        }

        if ($authorId === (int)$this->id) {
            return $this->canEditOwnContentSeo();
        }

        return $this->canEditOtherContentSeo();
    }

    public function canSubmitComments(): bool
    {
        return $this->isActive();
    }

    public function getAvatarUrl(): ?string
    {
        if (empty($this->avatar)) {
            return null;
        }
        $url = (string)$this->avatar;
        return \FavoriteCMS\Core\AccountMenu::isValidUrl($url) ? $url : null;
    }

    public function canDirectPublish(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('moderator')
            || $this->hasRole('editor')
            || $this->hasPermission('publish_direct')
            || $this->hasPermission('publish_posts');
    }

    public function canPublishPost($post = null): bool
    {
        return $this->canDirectPublish();
    }

    public function canPublishPosts(): bool
    {
        return $this->canDirectPublish();
    }

    public function canModeratePosts(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->hasRole('subscriber') || $this->hasRole('author')) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('moderator')
            || $this->hasPermission('approve_posts');
    }

    public function canModerateComments(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->hasRole('subscriber') || $this->hasRole('author')) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('moderator')
            || $this->hasRole('editor')
            || $this->hasPermission('moderate_comments');
    }

    public function canManageUsers(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->hasRole('subscriber') || $this->hasRole('author') || $this->hasRole('moderator') || $this->hasRole('editor')) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasPermission('manage_users');
    }

    public function canManagePages(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->hasRole('subscriber') || $this->hasRole('author') || $this->hasRole('moderator')) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('editor')
            || $this->hasPermission('manage_pages');
    }

    public function canManageTaxonomies(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->hasRole('subscriber') || $this->hasRole('author') || $this->hasRole('moderator')) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('editor')
            || $this->hasPermission('manage_taxonomy');
    }

    public function canManageMenus(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->hasRole('subscriber') || $this->hasRole('author') || $this->hasRole('moderator')) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('editor')
            || $this->hasPermission('manage_menus');
    }

    public function canManagePlugins(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->hasRole('subscriber') || $this->hasRole('author') || $this->hasRole('moderator') || $this->hasRole('editor')) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasPermission('manage_plugins');
    }

    public function canManageSettings(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->hasRole('subscriber') || $this->hasRole('author') || $this->hasRole('moderator') || $this->hasRole('editor')) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->isSuperAdmin()
            || $this->hasPermission('manage_settings');
    }

    public function getPostCount(): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as cnt FROM `posts` WHERE `author_id` = ? AND `type` = 'post'",
            [$this->id]
        );
        return (int)($row->cnt ?? 0);
    }

    public function getPrimaryRoleSlug(): string
    {
        $roles = $this->getRoles();
        if (empty($roles)) {
            return 'subscriber';
        }
        foreach ($roles as $role) {
            $cleanSlug = strtolower(trim(str_replace(['_', ' '], '-', (string)($role->slug ?? ''))));
            if ($cleanSlug === 'super-admin') {
                return 'super-admin';
            }
        }
        return strtolower(trim((string)($roles[0]->slug ?? 'subscriber')));
    }

    public function getPrimaryRoleName(): string
    {
        $roles = $this->getRoles();
        if (empty($roles)) {
            return 'Subscriber';
        }
        foreach ($roles as $role) {
            $cleanSlug = strtolower(trim(str_replace(['_', ' '], '-', (string)($role->slug ?? ''))));
            if ($cleanSlug === 'super-admin') {
                return (string)($role->name ?? 'Super Admin');
            }
        }
        return $roles[0]->name ?? 'Subscriber';
    }
}

