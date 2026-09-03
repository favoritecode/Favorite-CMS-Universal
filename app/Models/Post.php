<?php

declare(strict_types=1);

namespace FavoriteCMS\Models;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;

class Post extends BaseModel
{
    protected static string $table = 'posts';

    public static function findBySlug(string $slug): ?self
    {
        $db = Container::getInstance()->get(Database::class);
        $result = $db->selectOne("SELECT * FROM `posts` WHERE `slug` = ? AND `type` = 'post'", [$slug]);
        return $result ? new static((array)$result) : null;
    }

    public static function published(int $limit = 20, int $offset = 0): array
    {
        $db = Container::getInstance()->get(Database::class);
        $results = $db->select(
            "SELECT * FROM `posts` WHERE `status` = 'published' AND `type` = 'post' ORDER BY `published_at` DESC, `id` DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
        return array_map(fn($row) => new static((array)$row), $results);
    }

    public static function countByStatus(): array
    {
        $db = Container::getInstance()->get(Database::class);
        $rows = $db->select("SELECT `status`, COUNT(*) as cnt FROM `posts` WHERE `type` = 'post' GROUP BY `status`");
        $counts = ['all' => 0, 'published' => 0, 'draft' => 0, 'trash' => 0, 'scheduled' => 0];
        foreach ($rows as $row) {
            $counts[$row->status] = (int)$row->cnt;
            if ($row->status !== 'trash') {
                $counts['all'] += (int)$row->cnt;
            }
        }
        return $counts;
    }

    public function getAuthor(): ?User
    {
        if (empty($this->author_id)) {
            return null;
        }
        return User::find((int)$this->author_id);
    }

    public function getTaxonomies(string $taxonomy = 'category'): array
    {
        $sql = "SELECT t.* FROM `taxonomies` t 
                JOIN `post_taxonomies` pt ON t.id = pt.taxonomy_id 
                WHERE pt.post_id = ? AND t.taxonomy = ?";
        $results = $this->db->select($sql, [$this->id, $taxonomy]);
        return array_map(fn($row) => new Taxonomy((array)$row), $results);
    }

    public function syncTaxonomies(array $taxonomyIds, string $taxonomy = 'category'): void
    {
        // First delete existing taxonomy bindings for this type
        $existing = $this->getTaxonomies($taxonomy);
        foreach ($existing as $tax) {
            $this->db->execute(
                "DELETE FROM `post_taxonomies` WHERE `post_id` = ? AND `taxonomy_id` = ?",
                [$this->id, $tax->id]
            );
        }

        foreach ($taxonomyIds as $tid) {
            $tid = (int)$tid;
            if ($tid > 0) {
                $this->db->execute(
                    "INSERT IGNORE INTO `post_taxonomies` (`post_id`, `taxonomy_id`) VALUES (?, ?)",
                    [$this->id, $tid]
                );
            }
        }
    }

    public function syncTags(string $tagString): void
    {
        // Tag string e.g. "php, cms, news"
        $tags = array_filter(array_map('trim', explode(',', $tagString)));
        $tagIds = [];
        foreach ($tags as $tagName) {
            if ($tagName === '') continue;
            $slug = str_slug($tagName);
            $tax = Taxonomy::findBySlug($slug, 'tag');
            if (!$tax) {
                $taxId = $this->db->insert('taxonomies', [
                    'name'        => $tagName,
                    'slug'        => $slug,
                    'taxonomy'    => 'tag',
                    'description' => '',
                    'post_count'  => 0,
                    'created_at'  => date('Y-m-d H:i:s'),
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]);
            } else {
                $taxId = (int)$tax->id;
            }
            $tagIds[] = $taxId;
        }

        $this->syncTaxonomies($tagIds, 'tag');
    }

    public function getFeaturedImage(): ?Media
    {
        if (empty($this->featured_image_id)) return null;
        return Media::find((int)$this->featured_image_id);
    }

    public function getComments(string $status = 'approved'): array
    {
        return Comment::forPost((int)$this->id, $status);
    }

    public function getSeoMeta(): ?object
    {
        return $this->db->selectOne(
            "SELECT * FROM `seo_meta` WHERE `object_type` = 'post' AND `object_id` = ? LIMIT 1",
            [$this->id]
        );
    }

    public function saveSeoMeta(array $meta): void
    {
        $existing = $this->getSeoMeta();
        $data = [
            'meta_title'       => $meta['meta_title'] ?? null,
            'meta_description' => $meta['meta_description'] ?? null,
            'og_title'         => $meta['og_title'] ?? null,
            'og_description'   => $meta['og_description'] ?? null,
            'canonical_url'    => $meta['canonical_url'] ?? null,
            'robots'           => $meta['robots'] ?? 'index,follow',
        ];

        if ($existing) {
            $this->db->update('seo_meta', $data, [
                'object_type' => 'post',
                'object_id'   => $this->id,
            ]);
        } else {
            $data['object_type'] = 'post';
            $data['object_id']   = $this->id;
            $this->db->insert('seo_meta', $data);
        }
    }

    public function generateSlug(string $title, ?int $excludeId = null): string
    {
        $baseSlug = str_slug($title);
        if ($baseSlug === '') {
            $baseSlug = 'post';
        }
        $exclude = $excludeId ?? ($this->id ?? 0);
        $slug = $baseSlug;
        $count = 1;
        while ($this->db->selectOne("SELECT id FROM `posts` WHERE `slug` = ? AND `id` != ?", [$slug, $exclude])) {
            $slug = $baseSlug . '-' . $count;
            $count++;
        }
        return $slug;
    }

    public function getPrevious(): ?self
    {
        $publishedAt = $this->published_at ?? $this->created_at;
        $row = $this->db->selectOne(
            "SELECT * FROM `posts` WHERE `status` = 'published' AND `type` = 'post' AND (`published_at` < ? OR (`published_at` = ? AND `id` < ?)) ORDER BY `published_at` DESC, `id` DESC LIMIT 1",
            [$publishedAt, $publishedAt, $this->id ?? 0]
        );
        return $row ? new static((array)$row) : null;
    }

    public function getNext(): ?self
    {
        $publishedAt = $this->published_at ?? $this->created_at;
        $row = $this->db->selectOne(
            "SELECT * FROM `posts` WHERE `status` = 'published' AND `type` = 'post' AND (`published_at` > ? OR (`published_at` = ? AND `id` > ?)) ORDER BY `published_at` ASC, `id` ASC LIMIT 1",
            [$publishedAt, $publishedAt, $this->id ?? 0]
        );
        return $row ? new static((array)$row) : null;
    }
}
