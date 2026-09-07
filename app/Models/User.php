<?php

declare(strict_types=1);

namespace FavoriteCMS\Models;

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
        return password_verify($password, $this->password ?? '');
    }

    public function setPassword(string $plain): void
    {
        $this->password = password_hash($plain, PASSWORD_DEFAULT);
    }

    public function toArray(): array
    {
        $array = parent::toArray();
        unset($array['password']);
        return $array;
    }

    public function getRoles(): array
    {
        $sql = "SELECT r.* FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?";
        return $this->db->select($sql, [$this->id]);
    }

    public function hasRole(string $roleSlug): bool
    {
        $roles = $this->getRoles();
        foreach ($roles as $role) {
            if ($role->slug === $roleSlug) {
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

    public function hasPermission(string $permissionSlug): bool
    {
        if ($this->hasRole('super-admin')) {
            return true;
        }

        if (($this->hasRole('admin') || $this->hasRole('administrator')) && in_array($permissionSlug, [
            'manage_options',
            'view_admin',
            'manage_users',
            'manage_plugins',
            'manage_themes',
            'manage_settings',
        ], true)) {
            return true;
        }

        $permissions = $this->getPermissions();
        foreach ($permissions as $permission) {
            if ($permission->slug === $permissionSlug) {
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
    }

    public function canCreatePosts(): bool
    {
        return $this->isActive();
    }

    public function canUpdatePosts(): bool
    {
        return $this->isActive();
    }

    public function canUploadMedia(): bool
    {
        return $this->isActive();
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

    public function canModeratePosts(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('moderator')
            || $this->hasRole('editor')
            || $this->hasPermission('approve_posts');
    }

    public function canModerateComments(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('moderator')
            || $this->hasRole('editor')
            || $this->hasPermission('moderate_comments')
            || $this->hasPermission('approve_posts');
    }

    public function canManageUsers(): bool
    {
        if (!$this->isActive()) {
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

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasRole('editor')
            || $this->hasPermission('manage_pages');
    }

    public function canManagePlugins(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        return $this->hasRole('super-admin')
            || $this->hasRole('admin')
            || $this->hasPermission('manage_plugins');
    }

    public function getPostCount(): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) as cnt FROM `posts` WHERE `author_id` = ? AND `type` = 'post'",
            [$this->id]
        );
        return (int)($row->cnt ?? 0);
    }

    public function getPrimaryRoleName(): string
    {
        $roles = $this->getRoles();
        if (!empty($roles)) {
            return $roles[0]->name ?? 'Subscriber';
        }
        return 'Subscriber';
    }
}

