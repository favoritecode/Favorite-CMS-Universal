<?php

declare(strict_types=1);

namespace FavoriteCMS\Widgets\Core;

use FavoriteCMS\Models\Post;
use FavoriteCMS\Widgets\AbstractWidget;

class FeaturedPostWidget extends AbstractWidget
{
    protected string $id = 'featured_post';
    protected string $name = 'Featured Post';
    protected string $description = 'Highlight a specific article in a styled callout card.';
    protected string $category = 'Content';
    protected string $icon = '⭐';

    public function getSchema(): array
    {
        $postOptions = ['0' => '— Select a Post —'];
        try {
            $posts = Post::published(25);
            foreach ($posts as $p) {
                $postOptions[(string)$p->id] = $p->title;
            }
        } catch (\Throwable) {}

        return [
            'title' => [
                'type'    => 'text',
                'label'   => 'Widget Heading',
                'default' => 'Featured Story',
            ],
            'post_id' => [
                'type'    => 'select',
                'label'   => 'Select Article',
                'options' => $postOptions,
                'default' => '0',
            ],
            'show_excerpt' => [
                'type'    => 'checkbox',
                'label'   => 'Display Excerpt',
                'default' => true,
            ],
        ];
    }

    public function render(array $settings = [], array $args = []): string
    {
        $settings = $this->resolveSettings($settings);
        $postId   = (int)($settings['post_id'] ?? 0);

        if ($postId <= 0) {
            return '';
        }

        try {
            $post = Post::find($postId);
        } catch (\Throwable) {
            $post = null;
        }

        if (!$post || $post->status !== 'published') {
            return '';
        }

        $url     = '/post/' . htmlspecialchars($post->slug, ENT_QUOTES, 'UTF-8');
        $title   = htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8');
        $featImg = $post->getFeaturedImage();

        $html = '<div class="featured-post-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; padding: 12px;">';

        if ($featImg && !empty($featImg->url)) {
            $html .= '<a href="' . $url . '" style="display: block; margin-bottom: 8px; border-radius: 4px; overflow: hidden;">' .
                     '<img src="' . htmlspecialchars($featImg->url, ENT_QUOTES, 'UTF-8') . '" alt="' . $title . '" style="width: 100%; max-height: 140px; object-fit: cover; display: block;" loading="lazy">' .
                     '</a>';
        }

        $html .= '<h4 style="font-size: 14px; font-weight: 700; margin-bottom: 6px; line-height: 1.3;">' .
                 '<a href="' . $url . '" style="text-decoration: none; color: inherit;">' . $title . '</a>' .
                 '</h4>';

        if (!empty($settings['show_excerpt']) && !empty($post->excerpt)) {
            $html .= '<p style="font-size: 12px; color: #64748b; line-height: 1.4; margin-bottom: 8px;">' . htmlspecialchars($post->excerpt, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $html .= '<a href="' . $url . '" style="font-size: 12px; font-weight: 600; color: var(--wp-blue, #0284c7); text-decoration: none;">Read Story &rarr;</a>';
        $html .= '</div>';

        return $this->wrapOutput($html, $settings, $args);
    }
}

