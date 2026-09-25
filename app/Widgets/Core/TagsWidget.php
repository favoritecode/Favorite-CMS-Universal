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
            // Bounded query: most used tags first, never the whole taxonomy table
            $tags = Taxonomy::getPopular('tag', $limit);
        } catch (\Throwable) {
            $tags = [];
        }

        if (empty($tags)) {
            return '';
        }

        $html = '<div class="widget-tag-cloud">';
        foreach ($tags as $tag) {
            $href  = site_path('/tag/' . $tag->slug);
            $name  = htmlspecialchars((string)$tag->name, ENT_QUOTES, 'UTF-8');
            $count = (int)($tag->published_count ?? 0);
            $current = is_current_url($href) ? ' aria-current="page"' : '';
            $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="tag-cloud-item" data-count="' . $count . '"' . $current . '>#' . $name . '</a>';
        }
        $html .= '</div>';

        return $this->wrapOutput($html, $settings, $args);
    }
}
