<?php

declare(strict_types=1);

namespace FavoriteCMS\Widgets\Core;

use FavoriteCMS\Models\Page;
use FavoriteCMS\Widgets\AbstractWidget;

class PagesWidget extends AbstractWidget
{
    protected string $id = 'pages';
    protected string $name = 'Pages';
    protected string $description = 'A list of your site’s published pages.';
    protected string $category = 'Navigation';
    protected string $icon = '📄';

    public function getSchema(): array
    {
        return [
            'title' => [
                'type'    => 'text',
                'label'   => 'Title',
                'default' => 'Pages',
            ],
            'sortby' => [
                'type'    => 'select',
                'label'   => 'Sort By',
                'options' => [
                    'menu_order' => 'Page Order',
                    'title'      => 'Page Title',
                    'created_at' => 'Date Created',
                ],
                'default' => 'menu_order',
            ],
        ];
    }

    public function render(array $settings = [], array $args = []): string
    {
        $settings = $this->resolveSettings($settings);

        try {
            $pages = Page::published();
        } catch (\Throwable) {
            $pages = [];
        }

        if (empty($pages)) {
            return '';
        }

        $sortBy = $settings['sortby'] ?? 'menu_order';
        if ($sortBy === 'title') {
            usort($pages, fn($a, $b) => strcmp($a->title, $b->title));
        } elseif ($sortBy === 'created_at') {
            usort($pages, fn($a, $b) => strcmp($b->created_at, $a->created_at));
        }

        $currentUri = $_SERVER['REQUEST_URI'] ?? '/';

        $html = '<ul class="widget-list widget-pages">';
        foreach ($pages as $page) {
            $url    = '/page/' . htmlspecialchars($page->slug, ENT_QUOTES, 'UTF-8');
            $title  = htmlspecialchars($page->title, ENT_QUOTES, 'UTF-8');
            $active = ($currentUri === $url) ? ' class="active"' : '';

            $html .= '<li><a href="' . $url . '"' . $active . '>' . $title . '</a></li>';
        }
        $html .= '</ul>';

        return $this->wrapOutput($html, $settings, $args);
    }
}

