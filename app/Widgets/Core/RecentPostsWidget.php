<?php

declare(strict_types=1);

namespace FavoriteCMS\Widgets\Core;

use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Taxonomy;
use FavoriteCMS\Widgets\AbstractWidget;

class RecentPostsWidget extends AbstractWidget
{
    protected string $id = 'recent_posts';
    protected string $name = 'Recent Posts';
    protected string $description = 'Displays a list of your most recently published articles.';
    protected string $category = 'Content';
    protected string $icon = '📝';

    public function getSchema(): array
    {
        $catOptions = ['0' => '— All Categories —'];
        try {
            $categories = Taxonomy::getByTaxonomy('category');
            foreach ($categories as $cat) {
                $catOptions[(string)$cat->id] = $cat->name;
            }
        } catch (\Throwable) {}

        return [
            'title' => [
                'type'    => 'text',
                'label'   => 'Title',
                'default' => 'Recent Articles',
            ],
            'number' => [
                'type'    => 'number',
                'label'   => 'Number of Posts',
                'default' => 5,
            ],
            'show_date' => [
                'type'    => 'checkbox',
                'label'   => 'Display Post Date',
                'default' => true,
            ],
            'show_thumb' => [
                'type'    => 'checkbox',
                'label'   => 'Display Thumbnail',
                'default' => false,
            ],
            'category_id' => [
                'type'    => 'select',
                'label'   => 'Filter by Category',
                'options' => $catOptions,
                'default' => '0',
            ],
        ];
    }

    public function render(array $settings = [], array $args = []): string
    {
        $settings = $this->resolveSettings($settings);
        $number   = max(1, min(20, (int)($settings['number'] ?? 5)));
        $showDate = !empty($settings['show_date']);
        $showThumb= !empty($settings['show_thumb']);
        $catId    = (int)($settings['category_id'] ?? 0);

        try {
            if ($catId > 0) {
                $posts = Taxonomy::find($catId)?->getPosts('published', $number) ?? [];
            } else {
                $posts = Post::published($number);
            }
        } catch (\Throwable) {
            $posts = [];
        }

        if (empty($posts)) {
            return '';
        }

        $html = '<ul class="widget-list widget-recent-posts">';
        foreach ($posts as $post) {
            $url   = '/post/' . htmlspecialchars($post->slug, ENT_QUOTES, 'UTF-8');
            $title = htmlspecialchars($post->title, ENT_QUOTES, 'UTF-8');
            $date  = date('M j, Y', strtotime($post->published_at ?? $post->created_at));

            $html .= '<li style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">';

            if ($showThumb) {
                $featImg = $post->getFeaturedImage();
                if ($featImg && !empty($featImg->url)) {
                    $html .= '<a href="' . $url . '" style="flex-shrink: 0; width: 44px; height: 44px; border-radius: 4px; overflow: hidden; display: block;">' .
                             '<img src="' . htmlspecialchars($featImg->url, ENT_QUOTES, 'UTF-8') . '" alt="' . $title . '" style="width: 100%; height: 100%; object-fit: cover;">' .
                             '</a>';
                }
            }

            $html .= '<div style="flex: 1; min-width: 0;">';
            $html .= '<a href="' . $url . '" class="recent-post-link" style="display: block; font-weight: 500; text-decoration: none; color: inherit; line-height: 1.3;">' . $title . '</a>';
            if ($showDate) {
                $html .= '<div class="recent-post-meta" style="font-size: 11px; color: var(--color-muted, #64748b); margin-top: 2px;">' . $date . '</div>';
            }
            $html .= '</div></li>';
        }
        $html .= '</ul>';

        return $this->wrapOutput($html, $settings, $args);
    }
}

