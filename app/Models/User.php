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
}

