<?php

declare(strict_types=1);

namespace FavoriteCMS\Widgets\Core;

use FavoriteCMS\Models\Taxonomy;
use FavoriteCMS\Widgets\AbstractWidget;

class CategoriesWidget extends AbstractWidget
{
    protected string $id = 'categories';
    protected string $name = 'Categories';
    protected string $description = 'A list or dropdown of categories.';
    protected string $category = 'Taxonomy';
    protected string $icon = '📁';

    public function getSchema(): array
    {
        return [
            'title' => [
                'type'    => 'text',
                'label'   => 'Title',
                'default' => 'Categories',
            ],
            'show_count' => [
                'type'    => 'checkbox',
                'label'   => 'Show Post Counts',
                'default' => true,
            ],
            'hide_empty' => [
                'type'    => 'checkbox',
                'label'   => 'Hide Empty Categories',
                'default' => false,
            ],
        ];
    }

    public function render(array $settings = [], array $args = []): string
    {
        $settings  = $this->resolveSettings($settings);
        $showCount = !empty($settings['show_count']);
        $hideEmpty = !empty($settings['hide_empty']);

        try {
            $categories = Taxonomy::getByTaxonomy('category');
            // One grouped query for accurate published-post counts of every category
            $counts = ($showCount || $hideEmpty) ? Taxonomy::publishedPostCounts('category') : [];
        } catch (\Throwable) {
            $categories = [];
            $counts = [];
        }

        if (empty($categories)) {
            return '';
        }

        $items = '';
        foreach ($categories as $cat) {
            $count = $counts[(int)$cat->id] ?? 0;
            if ($hideEmpty && $count === 0) {
                continue;
            }

            $href = site_path('/category/' . $cat->slug);
            $name = htmlspecialchars((string)$cat->name, ENT_QUOTES, 'UTF-8');
            $current = is_current_url($href) ? ' aria-current="page"' : '';

            $items .= '<li class="widget-categories__item"><a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="category-row"' . $current . '>';
            $items .= '<span class="category-row__name">' . $name . '</span>';
            if ($showCount) {
                $items .= '<span class="category-badge-count">' . $count . '</span>';
            }
            $items .= '</a></li>';
        }

        if ($items === '') {
            return '';
        }

        return $this->wrapOutput('<ul class="widget-list widget-categories">' . $items . '</ul>', $settings, $args);
    }
}
