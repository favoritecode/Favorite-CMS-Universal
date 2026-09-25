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
        if (empty($items)) {
            return [];
        }

        // Sanitize parent_ids so orphaned records without a valid parent in this menu default to top-level (0)
        $validIds = array_flip(array_map(fn($item) => (int)$item->id, $items));
        foreach ($items as $item) {
            $pid = (int)($item->parent_id ?? 0);
            if ($pid > 0 && (!isset($validIds[$pid]) || $pid === (int)$item->id)) {
                $item->parent_id = 0;
            }
        }

        return $this->buildTree($items, 0);
    }

    /**
     * Returns menu items flattened in sequential depth-first order with an explicit 'depth' property (0, 1, 2...).
     *
     * @return array<int, object>
     */
    public function getFlatTree(): array
    {
        $tree = $this->getItems();
        $flat = [];

        $walk = function (array $items, int $depth = 0) use (&$walk, &$flat): void {
            foreach ($items as $item) {
                $item->depth = $depth;
                $flat[] = $item;
                if (!empty($item->children) && is_array($item->children)) {
                    $walk($item->children, $depth + 1);
                }
            }
        };

        $walk($tree, 0);
        return $flat;
    }

    public function buildTree(array $elements, int $parentId = 0, array $visited = []): array
    {
        $branch = [];
        foreach ($elements as $element) {
            $id = (int)$element->id;
            $pid = (int)($element->parent_id ?? 0);

            if ($pid === $parentId && !isset($visited[$id])) {
                $subVisited = $visited;
                $subVisited[$id] = true;
                $children = $this->buildTree($elements, $id, $subVisited);
                if ($children) {
                    $element->children = $children;
                } else {
                    $element->children = [];
                }
                $branch[] = $element;
            }
        }
        return $branch;
    }
}

