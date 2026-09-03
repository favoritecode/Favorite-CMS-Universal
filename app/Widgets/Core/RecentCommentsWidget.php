<?php

declare(strict_types=1);

namespace FavoriteCMS\Widgets\Core;

use FavoriteCMS\Models\Comment;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Widgets\AbstractWidget;

class RecentCommentsWidget extends AbstractWidget
{
    protected string $id = 'recent_comments';
    protected string $name = 'Recent Comments';
    protected string $description = 'Displays your site’s most recent approved reader comments.';
    protected string $category = 'Community';
    protected string $icon = '💬';

    public function getSchema(): array
    {
        return [
            'title' => [
                'type'    => 'text',
                'label'   => 'Title',
                'default' => 'Recent Comments',
            ],
            'limit' => [
                'type'    => 'number',
                'label'   => 'Number of Comments',
                'default' => 5,
            ],
        ];
    }

    public function render(array $settings = [], array $args = []): string
    {
        $settings = $this->resolveSettings($settings);
        $limit    = max(1, min(20, (int)($settings['limit'] ?? 5)));

        try {
            $comments = Comment::all(['status' => 'approved'], 'created_at DESC', $limit);
        } catch (\Throwable) {
            $comments = [];
        }

        if (empty($comments)) {
            return '';
        }

        $html = '<ul class="widget-list widget-recent-comments">';
        foreach ($comments as $c) {
            $author = htmlspecialchars($c->author_name ?: 'Reader', ENT_QUOTES, 'UTF-8');
            $post = Post::find($c->post_id);
            $postTitle = $post ? htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8') : 'an article';
            $postUrl = $post ? '/post/' . htmlspecialchars($post->slug, ENT_QUOTES, 'UTF-8') . '#comment-' . $c->id : '#';

            $html .= '<li style="margin-bottom: 8px; font-size: 13px; line-height: 1.4;">' .
                     '<strong>' . $author . '</strong> on ' .
                     '<a href="' . $postUrl . '" style="text-decoration: none; color: inherit; font-weight: 500;">' . $postTitle . '</a>' .
                     '</li>';
        }
        $html .= '</ul>';

        return $this->wrapOutput($html, $settings, $args);
    }
}

