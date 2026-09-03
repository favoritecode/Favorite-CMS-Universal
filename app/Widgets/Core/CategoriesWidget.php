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
        } catch (\Throwable) {
            $categories = [];
        }

        if (empty($categories)) {
            return '';
        }

        $html = '<ul class="widget-list widget-categories">';
        foreach ($categories as $cat) {
            $count = (int)($cat->count ?? 0);
            if ($hideEmpty && $count === 0) {
                continue;
            }

            $url  = '/category/' . htmlspecialchars($cat->slug, ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($cat->name, ENT_QUOTES, 'UTF-8');

            $html .= '<li><a href="' . $url . '" class="category-row" style="display: flex; justify-content: space-between; align-items: center; padding: 4px 0; text-decoration: none; color: inherit;">';
            $html .= '<span>' . $name . '</span>';
            if ($showCount) {
                $html .= '<span class="category-badge-count" style="background: var(--color-border, #e2e8f0); color: var(--color-text, #334155); font-size: 11px; padding: 2px 7px; border-radius: 999px; font-weight: 600;">' . $count . '</span>';
            }
            $html .= '</a></li>';
        }
        $html .= '</ul>';

        return $this->wrapOutput($html, $settings, $args);
    }
}

