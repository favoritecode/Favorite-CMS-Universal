<?php

declare(strict_types=1);

namespace FavoriteCMS\Models;

class Permission extends BaseModel
{
    protected static string $table = 'permissions';

    public static function findBySlug(string $slug): ?self
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $result = $db->selectOne("SELECT * FROM permissions WHERE slug = ?", [$slug]);
        return $result ? new static((array)$result) : null;
    }

    public static function getByGroup(string $group): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $results = $db->select("SELECT * FROM permissions WHERE `group` = ?", [$group]);
        return array_map(fn($row) => new static((array)$row), $results);
    }
}

