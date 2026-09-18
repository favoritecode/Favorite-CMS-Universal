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
                $category = Taxonomy::find($catId);
                $posts = $category ? $category->getPosts($number) : [];
            } else {
                $posts = Post::published($number);
            }
            if ($showThumb && $posts !== []) {
                Post::preloadListData($posts);
            }
        } catch (\Throwable) {
            $posts = [];
        }

        if (empty($posts)) {
            return '';
        }

        $html = '<ul class="widget-list widget-recent-posts">';
        foreach ($posts as $post) {
            $url   = htmlspecialchars(site_path('/post/' . $post->slug), ENT_QUOTES, 'UTF-8');
            $title = htmlspecialchars((string)$post->title, ENT_QUOTES, 'UTF-8');
            $rawDate = $post->published_at ?? $post->created_at;

            $thumbHtml = '';
            if ($showThumb) {
                $featImg = $post->getFeaturedImage();
                if ($featImg && !empty($featImg->url)) {
                    $thumbHtml = '<a href="' . $url . '" class="widget-recent-posts__thumb" tabindex="-1" aria-hidden="true">' .
                                 '<img src="' . htmlspecialchars(site_path((string)$featImg->url), ENT_QUOTES, 'UTF-8') . '" alt="" width="64" height="64" loading="lazy" decoding="async">' .
                                 '</a>';
                }
            }

            $html .= '<li class="widget-recent-posts__item' . ($thumbHtml !== '' ? ' has-thumb' : '') . '">' . $thumbHtml;
            $html .= '<div class="widget-recent-posts__body">';
            $html .= '<a href="' . $url . '" class="recent-post-link">' . $title . '</a>';
            if ($showDate) {
                $html .= '<time class="recent-post-meta" datetime="' . htmlspecialchars(format_date($rawDate, 'c'), ENT_QUOTES, 'UTF-8') . '">' .
                         htmlspecialchars(format_date($rawDate, 'M j, Y'), ENT_QUOTES, 'UTF-8') . '</time>';
            }
            $html .= '</div></li>';
        }
        $html .= '</ul>';

        return $this->wrapOutput($html, $settings, $args);
    }
}
