<?php

declare(strict_types=1);

namespace FavoriteCMS\Widgets\Core;

use FavoriteCMS\Models\Comment;
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
            // One bounded query that already includes the parent post slug and title
            $comments = Comment::recentApproved($limit);
        } catch (\Throwable) {
            $comments = [];
        }

        if (empty($comments)) {
            return '';
        }

        $html = '<ul class="widget-list widget-recent-comments">';
        foreach ($comments as $c) {
            $author    = htmlspecialchars((string)($c->author_name ?: 'Reader'), ENT_QUOTES, 'UTF-8');
            $postTitle = htmlspecialchars((string)($c->post_title ?: 'an article'), ENT_QUOTES, 'UTF-8');
            $postUrl   = htmlspecialchars(site_path('/post/' . $c->post_slug) . '#comment-' . (int)$c->id, ENT_QUOTES, 'UTF-8');

            $html .= '<li class="widget-recent-comments__item">' .
                     '<strong class="widget-recent-comments__author">' . $author . '</strong> on ' .
                     '<a href="' . $postUrl . '" class="widget-recent-comments__link">' . $postTitle . '</a>' .
                     '</li>';
        }
        $html .= '</ul>';

        return $this->wrapOutput($html, $settings, $args);
    }
}
