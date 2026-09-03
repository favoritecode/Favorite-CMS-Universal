<?php

declare(strict_types=1);

namespace FavoriteCMS\Widgets\Core;

use FavoriteCMS\Services\ContentSanitizer;
use FavoriteCMS\Widgets\AbstractWidget;

class HtmlWidget extends AbstractWidget
{
    protected string $id = 'custom_html';
    protected string $name = 'Custom HTML';
    protected string $description = 'Add arbitrary HTML, text, or embed code to your layout.';
    protected string $category = 'Advanced';
    protected string $icon = '💻';

    public function getSchema(): array
    {
        return [
            'title' => [
                'type'    => 'text',
                'label'   => 'Title',
                'default' => '',
            ],
            'content' => [
                'type'    => 'textarea',
                'label'   => 'HTML / Text Content',
                'default' => '',
            ],
        ];
    }

    public function render(array $settings = [], array $args = []): string
    {
        $settings = $this->resolveSettings($settings);
        $rawContent = (string)($settings['content'] ?? '');

        if (trim($rawContent) === '') {
            return '';
        }

        // Apply content sanitization based on session author/permissions
        $cleanContent = ContentSanitizer::clean($rawContent);

        $html = '<div class="custom-html-widget-content">' . $cleanContent . '</div>';

        return $this->wrapOutput($html, $settings, $args);
    }
}

