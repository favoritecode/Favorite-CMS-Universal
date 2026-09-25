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

    /**
     * Accurate number of published posts assigned to this term (for archive pagination).
     */
    public function countPublishedPosts(): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(DISTINCT p.id) AS cnt FROM `posts` p
             JOIN `post_taxonomies` pt ON p.id = pt.post_id
             WHERE pt.taxonomy_id = ? AND p.status = 'published' AND p.type = 'post'",
            [$this->id]
        );
        return (int)($row->cnt ?? 0);
    }

    /**
     * Published post counts for every term of a taxonomy, keyed by term ID, in a single query.
     *
     * @return array<int, int>
     */
    public static function publishedPostCounts(string $taxonomy): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $rows = $db->select(
            "SELECT pt.taxonomy_id, COUNT(DISTINCT p.id) AS cnt FROM `post_taxonomies` pt
             JOIN `posts` p ON p.id = pt.post_id
             JOIN `taxonomies` t ON t.id = pt.taxonomy_id
             WHERE t.taxonomy = ? AND p.status = 'published' AND p.type = 'post'
             GROUP BY pt.taxonomy_id",
            [$taxonomy]
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row->taxonomy_id] = (int)$row->cnt;
        }
        return $counts;
    }

    /**
     * Most used terms of a taxonomy (by published post count), bounded by $limit.
     * Each returned term carries a `published_count` attribute.
     */
    public static function getPopular(string $taxonomy, int $limit = 20): array
    {
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $rows = $db->select(
            "SELECT t.*, COALESCE(c.cnt, 0) AS published_count FROM `taxonomies` t
             LEFT JOIN (
                 SELECT pt.taxonomy_id, COUNT(DISTINCT p.id) AS cnt FROM `post_taxonomies` pt
                 JOIN `posts` p ON p.id = pt.post_id
                 WHERE p.status = 'published' AND p.type = 'post'
                 GROUP BY pt.taxonomy_id
             ) c ON c.taxonomy_id = t.id
             WHERE t.taxonomy = ?
             ORDER BY published_count DESC, t.name ASC
             LIMIT ?",
            [$taxonomy, max(1, $limit)]
        );
        return array_map(fn($row) => new static((array)$row), $rows);
    }
}

