<?php

declare(strict_types=1);

namespace FavoriteCMS\Widgets\Core;

use FavoriteCMS\Widgets\AbstractWidget;

class SearchWidget extends AbstractWidget
{
    protected string $id = 'search';
    protected string $name = 'Search';
    protected string $description = 'A clean keyword search form for your posts and pages.';
    protected string $category = 'Standard';
    protected string $icon = '🔍';

    public function getSchema(): array
    {
        return [
            'title' => [
                'type'    => 'text',
                'label'   => 'Title',
                'default' => 'Search',
            ],
            'placeholder' => [
                'type'    => 'text',
                'label'   => 'Placeholder Text',
                'default' => 'Search keywords...',
            ],
        ];
    }

    public function render(array $settings = [], array $args = []): string
    {
        $settings = $this->resolveSettings($settings);
        $placeholder = htmlspecialchars((string)($settings['placeholder'] ?? 'Search keywords...'), ENT_QUOTES, 'UTF-8');
        $queryVal = htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES, 'UTF-8');

        $html = '<form method="GET" action="/search" class="widget-search-form" role="search">' . "\n" .
                '    <input type="search" name="q" class="widget-search-input" placeholder="' . $placeholder . '" value="' . $queryVal . '" required>' . "\n" .
                '    <button type="submit" class="widget-search-btn" aria-label="Search">' . "\n" .
                '        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . "\n" .
                '            <circle cx="11" cy="11" r="8"></circle>' . "\n" .
                '            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>' . "\n" .
                '        </svg>' . "\n" .
                '    </button>' . "\n" .
                '</form>';

        return $this->wrapOutput($html, $settings, $args);
    }
}

