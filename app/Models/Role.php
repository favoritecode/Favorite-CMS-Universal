<?php

declare(strict_types=1);

namespace FavoriteCMS\Models;

class Role extends BaseModel
{
    protected static string $table = 'roles';

    public static function findBySlug(string $slug): ?self
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $result = $db->selectOne("SELECT * FROM roles WHERE slug = ?", [$slug]);
        return $result ? new static((array)$result) : null;
    }

    public function getPermissions(): array
    {
        $sql = "SELECT p.* FROM permissions p JOIN role_permissions rp ON p.id = rp.permission_id WHERE rp.role_id = ?";
        return $this->db->select($sql, [$this->id]);
    }

    public function givePermission(int $permissionId): void
    {
        $this->db->insert('role_permissions', [
            'role_id' => $this->id,
            'permission_id' => $permissionId
        ]);
    }

    public function revokePermission(int $permissionId): void
    {
        $this->db->delete('role_permissions', [
            'role_id' => $this->id,
            'permission_id' => $permissionId
        ]);
    }

    public function hasPermission(string $permissionSlug): bool
    {
        $permissions = $this->getPermissions();
        foreach ($permissions as $permission) {
            if ($permission->slug === $permissionSlug) {
                return true;
            }
        }
        return false;
    }
}

