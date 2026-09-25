<?php

declare(strict_types=1);

namespace FavoriteCMS\Widgets;

abstract class AbstractWidget implements WidgetInterface
{
    protected string $id;
    protected string $name;
    protected string $description = '';
    protected string $category = 'Standard';
    protected string $icon = '🧩';

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function getSchema(): array
    {
        return [
            'title' => [
                'type'    => 'text',
                'label'   => 'Title',
                'default' => $this->name,
            ],
        ];
    }

    public function getDefaultSettings(): array
    {
        $defaults = [];
        foreach ($this->getSchema() as $key => $field) {
            $defaults[$key] = $field['default'] ?? null;
        }
        return $defaults;
    }

    /**
     * Merge instance settings with defaults.
     */
    protected function resolveSettings(array $settings): array
    {
        return array_merge($this->getDefaultSettings(), $settings);
    }

    /**
     * Render the standard widget wrapper with title.
     */
    protected function wrapOutput(string $content, array $settings, array $args): string
    {
        $beforeWidget = $args['before_widget'] ?? '<section class="widget widget_' . htmlspecialchars($this->getId()) . '">';
        $afterWidget  = $args['after_widget'] ?? '</section>';
        $beforeTitle  = $args['before_title'] ?? '<h3 class="widget-title">';
        $afterTitle   = $args['after_title'] ?? '</h3>';

        $titleHtml = '';
        if (!empty($settings['title'])) {
            $titleHtml = $beforeTitle . htmlspecialchars((string)$settings['title'], ENT_QUOTES, 'UTF-8') . $afterTitle . "\n";
        }

        return $beforeWidget . "\n" . $titleHtml . $content . "\n" . $afterWidget;
    }
}

