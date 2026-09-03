<?php

declare(strict_types=1);

namespace FavoriteCMS\Models;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;

class Page extends BaseModel
{
    protected static string $table = 'pages';

    public static function findBySlug(string $slug): ?self
    {
        $db = Container::getInstance()->get(Database::class);
        $result = $db->selectOne("SELECT * FROM `pages` WHERE `slug` = ?", [$slug]);
        return $result ? new static((array)$result) : null;
    }

    public static function published(): array
    {
        $db = Container::getInstance()->get(Database::class);
        $results = $db->select("SELECT * FROM `pages` WHERE `status` = 'published' ORDER BY `menu_order` ASC, `title` ASC");
        return array_map(fn($row) => new static((array)$row), $results);
    }

    public static function countByStatus(): array
    {
        $db = Container::getInstance()->get(Database::class);
        $rows = $db->select("SELECT `status`, COUNT(*) as cnt FROM `pages` GROUP BY `status`");
        $counts = ['all' => 0, 'published' => 0, 'draft' => 0, 'trash' => 0];
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

    public function getFeaturedImage(): ?Media
    {
        if (empty($this->featured_image_id)) return null;
        return Media::find((int)$this->featured_image_id);
    }

    public function getChildren(): array
    {
        $results = $this->db->select("SELECT * FROM `pages` WHERE `parent_id` = ? ORDER BY `menu_order` ASC", [$this->id]);
        return array_map(fn($row) => new static((array)$row), $results);
    }

    public function getParent(): ?self
    {
        if (!$this->parent_id) return null;
        return static::find((int)$this->parent_id);
    }

    public function getBreadcrumb(): array
    {
        $breadcrumb = [$this];
        $parent = $this->getParent();
        while ($parent) {
            array_unshift($breadcrumb, $parent);
            $parent = $parent->getParent();
        }
        return $breadcrumb;
    }

    public function getSeoMeta(): ?object
    {
        return $this->db->selectOne(
            "SELECT * FROM `seo_meta` WHERE `object_type` = 'page' AND `object_id` = ? LIMIT 1",
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
                'object_type' => 'page',
                'object_id'   => $this->id,
            ]);
        } else {
            $data['object_type'] = 'page';
            $data['object_id']   = $this->id;
            $this->db->insert('seo_meta', $data);
        }
    }

    public function generateSlug(string $title): string
    {
        $baseSlug = str_slug($title);
        if ($baseSlug === '') {
            $baseSlug = 'page';
        }
        $slug = $baseSlug;
        $count = 1;
        while ($this->db->selectOne("SELECT id FROM `pages` WHERE `slug` = ? AND `id` != ?", [$slug, $this->id ?? 0])) {
            $slug = $baseSlug . '-' . $count;
            $count++;
        }
        return $slug;
    }
}
