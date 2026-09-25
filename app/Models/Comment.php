<?php

declare(strict_types=1);

namespace FavoriteCMS\Models;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;

class Comment extends BaseModel
{
    protected static string $table = 'comments';

    public static function forPost(int $postId, string $status = 'approved'): array
    {
        $db = Container::getInstance()->get(Database::class);
        $rows = $db->select(
            "SELECT * FROM `comments` WHERE `post_id` = ? AND `status` = ? ORDER BY `created_at` ASC",
            [$postId, $status]
        );
        return array_map(fn($row) => new static((array)$row), $rows);
    }

    /**
     * Most recent approved comments on published posts, bounded by $limit.
     * Each row includes `post_slug` and `post_title` so callers need no per-comment post queries.
     */
    public static function recentApproved(int $limit = 5): array
    {
        $db = Container::getInstance()->get(Database::class);
        $rows = $db->select(
            "SELECT c.*, p.slug AS post_slug, p.title AS post_title FROM `comments` c
             JOIN `posts` p ON p.id = c.post_id
             WHERE c.status = 'approved' AND p.status = 'published' AND p.type = 'post'
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT ?",
            [max(1, $limit)]
        );
        return array_map(fn($row) => new static((array)$row), $rows);
    }

    public static function countByStatus(): array
    {
        $db = Container::getInstance()->get(Database::class);
        $rows = $db->select("SELECT `status`, COUNT(*) as cnt FROM `comments` GROUP BY `status`");
        $counts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'spam' => 0, 'trash' => 0];
        foreach ($rows as $row) {
            $counts[$row->status] = (int)$row->cnt;
            if ($row->status !== 'trash') {
                $counts['all'] += (int)$row->cnt;
            }
        }
        return $counts;
    }

    public function getPost(): ?Post
    {
        if (empty($this->post_id)) {
            return null;
        }
        return Post::find((int)$this->post_id);
    }
}

