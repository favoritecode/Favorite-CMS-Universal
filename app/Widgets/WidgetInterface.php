<?php

declare(strict_types=1);

namespace FavoriteCMS\Widgets;

interface WidgetInterface
{
    /**
     * Unique identifier for the widget type (e.g. 'search', 'recent_posts').
     */
    public function getId(): string;

    /**
     * Human-readable display name.
     */
    public function getName(): string;

    /**
     * Short description of what the widget does.
     */
    public function getDescription(): string;

    /**
     * Category for grouping in admin UI (e.g. 'Standard', 'Media', 'Commerce').
     */
    public function getCategory(): string;

    /**
     * Icon or emoji representation (e.g. '🔍', '📝').
     */
    public function getIcon(): string;

    /**
     * Schema describing the customizable settings for this widget.
     * Each field array: ['type' => 'text'|'number'|'select'|'checkbox'|'textarea', 'label' => '...', 'options' => [...], 'default' => '...']
     */
    public function getSchema(): array;

    /**
     * Default settings values for a new instance.
     */
    public function getDefaultSettings(): array;

    /**
     * Render the widget's frontend HTML output for a given instance.
     *
     * @param array $settings Instance-specific settings.
     * @param array $args Region container arguments (e.g. 'before_widget', 'after_widget', 'before_title', 'after_title').
     * @return string Rendered HTML.
     */
    public function render(array $settings = [], array $args = []): string;
}

