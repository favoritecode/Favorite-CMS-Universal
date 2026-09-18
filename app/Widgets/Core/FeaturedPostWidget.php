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

        $url     = htmlspecialchars(site_path('/post/' . $post->slug), ENT_QUOTES, 'UTF-8');
        $title   = htmlspecialchars((string)$post->title, ENT_QUOTES, 'UTF-8');
        $featImg = $post->getFeaturedImage();

        $html = '<article class="featured-post-card">';

        if ($featImg && !empty($featImg->url)) {
            $width  = (int)($featImg->width ?? 0);
            $height = (int)($featImg->height ?? 0);
            $dimensions = ($width > 0 && $height > 0) ? ' width="' . $width . '" height="' . $height . '"' : '';

            $html .= '<a href="' . $url . '" class="featured-post-card__media" tabindex="-1" aria-hidden="true">' .
                     '<img src="' . htmlspecialchars(site_path((string)$featImg->url), ENT_QUOTES, 'UTF-8') . '" alt=""' . $dimensions . ' loading="lazy" decoding="async">' .
                     '</a>';
        }

        $html .= '<h4 class="featured-post-card__title"><a href="' . $url . '">' . $title . '</a></h4>';

        if (!empty($settings['show_excerpt']) && !empty($post->excerpt)) {
            $html .= '<p class="featured-post-card__excerpt">' . htmlspecialchars((string)$post->excerpt, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $html .= '<a href="' . $url . '" class="featured-post-card__link">Read Story &rarr;</a>';
        $html .= '</article>';

        return $this->wrapOutput($html, $settings, $args);
    }
}
