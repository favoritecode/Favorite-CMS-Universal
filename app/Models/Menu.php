<?php

declare(strict_types=1);

namespace FavoriteCMS\Models;

class Menu extends BaseModel
{
    protected static string $table = 'menus';

    public static function findBySlug(string $slug): ?self
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $result = $db->selectOne("SELECT * FROM menus WHERE slug = ?", [$slug]);
        return $result ? new static((array)$result) : null;
    }

    public static function findByLocation(string $location): ?self
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $result = $db->selectOne("SELECT * FROM menus WHERE location = ?", [$location]);
        return $result ? new static((array)$result) : null;
    }

    public function getItems(): array
    {
        $items = $this->db->select("SELECT * FROM `menu_items` WHERE `menu_id` = ? ORDER BY `sort_order` ASC, `id` ASC", [$this->id]);
        return $this->buildTree($items);
    }

    public function buildTree(array $elements, int $parentId = 0): array
    {
        $branch = [];
        foreach ($elements as $element) {
            if ((int)$element->parent_id === $parentId) {
                $children = $this->buildTree($elements, (int)$element->id);
                if ($children) {
                    $element->children = $children;
                }
                $branch[] = $element;
            }
        }
        return $branch;
    }
}

