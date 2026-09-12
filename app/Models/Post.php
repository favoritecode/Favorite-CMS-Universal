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

    /**
     * Relations loaded in bulk by preloadListData() for listing pages.
     *
     * @var array<string, mixed>
     */
    protected array $preloaded = [];

    /**
     * Load featured images, authors and categories for a list of posts using one query per relation,
     * instead of several queries for every post rendered in a listing.
     *
     * @param array<int, mixed> $posts
     */
    public static function preloadListData(array $posts): void
    {
        $posts = array_values(array_filter($posts, static fn($post) => $post instanceof self && !empty($post->id)));
        if ($posts === []) {
            return;
        }

        $db = Container::getInstance()->get(Database::class);

        $mediaIds = array_values(array_unique(array_filter(array_map(static fn(self $post) => (int)$post->featured_image_id, $posts))));
        $media = [];
        if ($mediaIds !== []) {
            foreach ($db->select('SELECT * FROM `media` WHERE `id` IN (' . self::placeholders($mediaIds) . ')', $mediaIds) as $row) {
                $media[(int)$row->id] = new Media((array)$row);
            }
        }

        $authorIds = array_values(array_unique(array_filter(array_map(static fn(self $post) => (int)$post->author_id, $posts))));
        $authors = [];
        if ($authorIds !== []) {
            foreach ($db->select('SELECT * FROM `users` WHERE `id` IN (' . self::placeholders($authorIds) . ')', $authorIds) as $row) {
                $authors[(int)$row->id] = new User((array)$row);
            }
        }

        $postIds = array_map(static fn(self $post) => (int)$post->id, $posts);
        $categories = [];
        $rows = $db->select(
            "SELECT t.*, pt.post_id AS preload_post_id FROM `taxonomies` t
             JOIN `post_taxonomies` pt ON t.id = pt.taxonomy_id
             WHERE t.taxonomy = 'category' AND pt.post_id IN (" . self::placeholders($postIds) . ')',
            $postIds
        );
        foreach ($rows as $row) {
            $data = (array)$row;
            $postId = (int)$data['preload_post_id'];
            unset($data['preload_post_id']);
            $categories[$postId][] = new Taxonomy($data);
        }

        foreach ($posts as $post) {
            $featuredId = (int)$post->featured_image_id;
            $authorId = (int)$post->author_id;
            $post->preloaded['featured_image'] = $featuredId > 0 ? ($media[$featuredId] ?? null) : null;
            $post->preloaded['author'] = $authorId > 0 ? ($authors[$authorId] ?? null) : null;
            $post->preloaded['taxonomies:category'] = $categories[(int)$post->id] ?? [];
        }
    }

    /**
     * Published posts whose title or content contains the search query (bounded page).
     */
    public static function searchPublished(string $query, int $limit = 10, int $offset = 0): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $db = Container::getInstance()->get(Database::class);
        $pattern = self::likePattern($query);
        $rows = $db->select(
            "SELECT * FROM `posts` WHERE `status` = 'published' AND `type` = 'post' AND (`title` LIKE ? OR `content` LIKE ?)
             ORDER BY `published_at` DESC, `id` DESC LIMIT ? OFFSET ?",
            [$pattern, $pattern, max(1, $limit), max(0, $offset)]
        );
        return array_map(fn($row) => new static((array)$row), $rows);
    }

    /**
     * Accurate total of published posts matching a search query.
     */
    public static function countSearchPublished(string $query): int
    {
        $query = trim($query);
        if ($query === '') {
            return 0;
        }

        $db = Container::getInstance()->get(Database::class);
        $pattern = self::likePattern($query);
        $row = $db->selectOne(
            "SELECT COUNT(*) AS cnt FROM `posts` WHERE `status` = 'published' AND `type` = 'post' AND (`title` LIKE ? OR `content` LIKE ?)",
            [$pattern, $pattern]
        );
        return (int)($row->cnt ?? 0);
    }

    protected static function likePattern(string $query): string
    {
        return '%' . addcslashes($query, '\\%_') . '%';
    }

    protected static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    public static function countByStatus(): array
    {
        $db = Container::getInstance()->get(Database::class);
        $rows = $db->select("SELECT `status`, COUNT(*) as cnt FROM `posts` WHERE `type` = 'post' GROUP BY `status`");
        $counts = [
            'all'       => 0,
            'published' => 0,
            'draft'     => 0,
            'pending'   => 0,
            'rejected'  => 0,
            'trash'     => 0,
            'scheduled' => 0
        ];
        foreach ($rows as $row) {
            $counts[$row->status] = (int)$row->cnt;
            if ($row->status !== 'trash') {
                $counts['all'] += (int)$row->cnt;
            }
        }
        return $counts;
    }

    public function isPending(): bool
    {
        return ($this->status ?? 'draft') === 'pending';
    }

    public function isPublished(): bool
    {
        return ($this->status ?? 'draft') === 'published';
    }

    public function isRejected(): bool
    {
        return ($this->status ?? 'draft') === 'rejected';
    }

    public function isDraft(): bool
    {
        return ($this->status ?? 'draft') === 'draft';
    }

    public function approve(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->update([
            'status'       => 'published',
            'published_at' => $now,
            'updated_at'   => $now,
        ]);
        $this->status = 'published';
        $this->published_at = $now;
    }

    public function reject(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->update([
            'status'     => 'rejected',
            'updated_at' => $now,
        ]);
        $this->status = 'rejected';
    }

    public function getAuthor(): ?User
    {
        if (empty($this->author_id)) {
            return null;
        }
        if (array_key_exists('author', $this->preloaded)) {
            return $this->preloaded['author'];
        }
        return User::find((int)$this->author_id);
    }

    public function getTaxonomies(string $taxonomy = 'category'): array
    {
        if (array_key_exists('taxonomies:' . $taxonomy, $this->preloaded)) {
            return $this->preloaded['taxonomies:' . $taxonomy];
        }

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
        if (array_key_exists('featured_image', $this->preloaded)) {
            return $this->preloaded['featured_image'];
        }
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

    public function url(): string
    {
        return '/post/' . $this->slug;
    }

    public function permalink(): string
    {
        return $this->url();
    }
}
