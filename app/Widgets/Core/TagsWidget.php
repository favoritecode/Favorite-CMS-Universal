<?php

declare(strict_types=1);

namespace FavoriteCMS\Widgets\Core;

use FavoriteCMS\Models\Taxonomy;
use FavoriteCMS\Widgets\AbstractWidget;

class TagsWidget extends AbstractWidget
{
    protected string $id = 'tags';
    protected string $name = 'Tag Cloud';
    protected string $description = 'A cloud of your most used tags.';
    protected string $category = 'Taxonomy';
    protected string $icon = '🏷️';

    public function getSchema(): array
    {
        return [
            'title' => [
                'type'    => 'text',
                'label'   => 'Title',
                'default' => 'Tags',
            ],
            'limit' => [
                'type'    => 'number',
                'label'   => 'Maximum Tags',
                'default' => 20,
            ],
        ];
    }

    public function render(array $settings = [], array $args = []): string
    {
        $settings = $this->resolveSettings($settings);
        $limit    = max(1, min(50, (int)($settings['limit'] ?? 20)));

        try {
            $tags = Taxonomy::getByTaxonomy('tag');
            $tags = array_slice($tags, 0, $limit);
        } catch (\Throwable) {
            $tags = [];
        }

        if (empty($tags)) {
            return '';
        }

        $html = '<div class="widget-tag-cloud" style="display: flex; flex-wrap: wrap; gap: 6px; padding: 4px 0;">';
        foreach ($tags as $tag) {
            $url  = '/tag/' . htmlspecialchars($tag->slug, ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($tag->name, ENT_QUOTES, 'UTF-8');
            $html .= '<a href="' . $url . '" class="tag-cloud-item" style="font-size: 12px; background: #f1f5f9; padding: 3px 8px; border-radius: 4px; text-decoration: none; color: #475569; border: 1px solid #e2e8f0;">#' . $name . '</a>';
        }
        $html .= '</div>';

        return $this->wrapOutput($html, $settings, $args);
    }
}

