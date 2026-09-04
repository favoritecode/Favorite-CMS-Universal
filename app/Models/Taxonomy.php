<?php

declare(strict_types=1);

namespace FavoriteCMS\Models;

class Taxonomy extends BaseModel
{
    protected static string $table = 'taxonomies';

    public static function findBySlug(string $slug, string $taxonomy): ?self
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $result = $db->selectOne("SELECT * FROM taxonomies WHERE slug = ? AND taxonomy = ?", [$slug, $taxonomy]);
        return $result ? new static((array)$result) : null;
    }

    public static function findOrCreate(string $name, string $taxonomy = 'tag'): ?self
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $slug = str_slug($name);
        $existing = static::findBySlug($slug, $taxonomy);
        if ($existing) {
            return $existing;
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $id = $db->insert('taxonomies', [
            'name'        => $name,
            'slug'        => $slug,
            'taxonomy'    => $taxonomy,
            'description' => '',
            'post_count'  => 0,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        return $id > 0 ? static::find((int)$id) : null;
    }

    public static function getByTaxonomy(string $taxonomy): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $results = $db->select("SELECT * FROM taxonomies WHERE taxonomy = ?", [$taxonomy]);
        return array_map(fn($row) => new static((array)$row), $results);
    }

    public static function findByType(string $taxonomy): array
    {
        return static::getByTaxonomy($taxonomy);
    }

    public function getChildren(): array
    {
        $results = $this->db->select("SELECT * FROM taxonomies WHERE parent_id = ?", [$this->id]);
        return array_map(fn($row) => new static((array)$row), $results);
    }

    public function updatePostCount(): void
    {
        $count = $this->db->selectOne("SELECT COUNT(*) as count FROM post_taxonomies WHERE taxonomy_id = ?", [$this->id])->count ?? 0;
        $this->update(['post_count' => $count]);
    }

    public function getPosts(int $limit = 20, int $offset = 0): array
    {
        $sql = "SELECT p.* FROM `posts` p 
                JOIN `post_taxonomies` pt ON p.id = pt.post_id 
                WHERE pt.taxonomy_id = ? AND p.status = 'published' AND p.type = 'post' 
                ORDER BY p.published_at DESC, p.id DESC LIMIT ? OFFSET ?";
        $rows = $this->db->select($sql, [$this->id, $limit, $offset]);
        return array_map(fn($row) => new Post((array)$row), $rows);
    }
}

