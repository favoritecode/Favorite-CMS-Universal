<?php

declare(strict_types=1);

namespace FavoriteCMS\Widgets\Core;

use FavoriteCMS\Models\Media;
use FavoriteCMS\Widgets\AbstractWidget;

class ImageWidget extends AbstractWidget
{
    protected string $id = 'image';
    protected string $name = 'Image';
    protected string $description = 'Display a photo, banner, or brand graphic.';
    protected string $category = 'Media';
    protected string $icon = '🖼️';

    public function getSchema(): array
    {
        return [
            'title' => [
                'type'    => 'text',
                'label'   => 'Title',
                'default' => '',
            ],
            'image_url' => [
                'type'    => 'text',
                'label'   => 'Image URL',
                'default' => '',
            ],
            'alt_text' => [
                'type'    => 'text',
                'label'   => 'Alt Text',
                'default' => '',
            ],
            'link_url' => [
                'type'    => 'text',
                'label'   => 'Link URL (Optional)',
                'default' => '',
            ],
            'caption' => [
                'type'    => 'text',
                'label'   => 'Caption (Optional)',
                'default' => '',
            ],
        ];
    }

    public function render(array $settings = [], array $args = []): string
    {
        $settings = $this->resolveSettings($settings);
        $imageUrl = trim((string)($settings['image_url'] ?? ''));

        if ($imageUrl === '') {
            return '';
        }

        $alt     = htmlspecialchars((string)($settings['alt_text'] ?? ''), ENT_QUOTES, 'UTF-8');
        $caption = htmlspecialchars((string)($settings['caption'] ?? ''), ENT_QUOTES, 'UTF-8');
        $link    = trim((string)($settings['link_url'] ?? ''));

        $imgHtml = '<img src="' . htmlspecialchars(site_path($imageUrl), ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" class="widget-image" loading="lazy" decoding="async">';

        if ($link !== '') {
            $imgHtml = '<a href="' . htmlspecialchars(site_path($link), ENT_QUOTES, 'UTF-8') . '" class="widget-image-link">' . $imgHtml . '</a>';
        }

        if ($caption !== '') {
            $imgHtml = '<figure class="widget-image-figure">' . $imgHtml . '<figcaption class="widget-image-caption">' . $caption . '</figcaption></figure>';
        }

        return $this->wrapOutput($imgHtml, $settings, $args);
    }
}
