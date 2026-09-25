<?php

declare(strict_types=1);

namespace FavoriteCMS\Widgets;

use FavoriteCMS\Core\Hook;
use FavoriteCMS\Widgets\Core\CategoriesWidget;
use FavoriteCMS\Widgets\Core\FeaturedPostWidget;
use FavoriteCMS\Widgets\Core\HtmlWidget;
use FavoriteCMS\Widgets\Core\ImageWidget;
use FavoriteCMS\Widgets\Core\NavMenuWidget;
use FavoriteCMS\Widgets\Core\PagesWidget;
use FavoriteCMS\Widgets\Core\RecentCommentsWidget;
use FavoriteCMS\Widgets\Core\RecentPostsWidget;
use FavoriteCMS\Widgets\Core\SearchWidget;
use FavoriteCMS\Widgets\Core\TagsWidget;

class WidgetRegistry
{
    protected static ?self $instance = null;

    /**
     * @var array<string, WidgetInterface>
     */
    protected array $widgets = [];

    protected bool $booted = false;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Register a widget type with the system.
     */
    public function register(WidgetInterface|string $widget): void
    {
        if (is_string($widget)) {
            if (!class_exists($widget)) {
                throw new \InvalidArgumentException("Widget class not found: {$widget}");
            }
            $widget = new $widget();
        }

        if (!$widget instanceof WidgetInterface) {
            throw new \InvalidArgumentException("Widget must implement WidgetInterface");
        }

        $this->widgets[$widget->getId()] = $widget;
    }

    /**
     * Check if a widget type exists.
     */
    public function has(string $id): bool
    {
        $this->ensureBooted();
        return isset($this->widgets[$id]);
    }

    /**
     * Get a registered widget type by ID.
     */
    public function get(string $id): ?WidgetInterface
    {
        $this->ensureBooted();
        return $this->widgets[$id] ?? null;
    }

    /**
     * Get all registered widget types.
     *
     * @return array<string, WidgetInterface>
     */
    public function all(): array
    {
        $this->ensureBooted();
        return $this->widgets;
    }

    /**
     * Get all registered widget types grouped by category.
     */
    public function getByCategory(): array
    {
        $this->ensureBooted();
        $grouped = [];
        foreach ($this->widgets as $widget) {
            $cat = $widget->getCategory() ?: 'Standard';
            $grouped[$cat][] = $widget;
        }
        ksort($grouped);
        return $grouped;
    }

    /**
     * Ensure core widgets and plugin widgets are booted.
     */
    public function ensureBooted(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        $this->bootCoreWidgets();

        // Allow plugins and themes to register widgets via widgets_init hook
        Hook::doAction('widgets_init', $this);
    }

    /**
     * Register standard built-in core widgets.
     */
    protected function bootCoreWidgets(): void
    {
        $core = [
            SearchWidget::class,
            RecentPostsWidget::class,
            CategoriesWidget::class,
            TagsWidget::class,
            NavMenuWidget::class,
            PagesWidget::class,
            HtmlWidget::class,
            ImageWidget::class,
            FeaturedPostWidget::class,
            RecentCommentsWidget::class,
        ];

        foreach ($core as $class) {
            $this->register(new $class());
        }
    }
}

