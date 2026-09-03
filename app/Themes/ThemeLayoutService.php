<?php

declare(strict_types=1);

namespace FavoriteCMS\Themes;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Hook;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Widgets\WidgetInstanceManager;
use FavoriteCMS\Widgets\WidgetRegistry;

class ThemeLayoutService
{
    protected Application $app;
    protected WidgetInstanceManager $instanceManager;
    protected string $themesPath;

    public function __construct(Application $app, ?WidgetInstanceManager $instanceManager = null)
    {
        $this->app = $app;
        $this->instanceManager = $instanceManager ?? new WidgetInstanceManager();
        $this->themesPath = APP_ROOT . '/themes';
    }

    public function getActiveThemeId(): string
    {
        return $this->instanceManager->getActiveThemeId();
    }

    /**
     * Read and parse theme manifest (theme.json).
     */
    public function getThemeManifest(?string $themeId = null): array
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $manifestPath = $this->themesPath . '/' . $tid . '/theme.json';

        if (!file_exists($manifestPath)) {
            return [
                'id'          => $tid,
                'name'        => ucfirst($tid),
                'regions'     => $this->getDefaultFallbackRegions(),
                'sections'    => $this->getDefaultFallbackSections(),
                'settings'    => [],
            ];
        }

        $json = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($json)) {
            $json = [];
        }

        $json['id'] = $tid;
        if (empty($json['regions']) || !is_array($json['regions'])) {
            $json['regions'] = $this->getDefaultFallbackRegions();
        }
        if (empty($json['sections']) || !is_array($json['sections'])) {
            $json['sections'] = $this->getDefaultFallbackSections();
        }

        return $json;
    }

    /**
     * Default regions if theme manifest has none defined.
     */
    protected function getDefaultFallbackRegions(): array
    {
        return [
            [
                'id'          => 'sidebar-primary',
                'name'        => 'Primary Sidebar',
                'description' => 'Main sidebar displayed beside post and page content.',
            ],
            [
                'id'          => 'footer-1',
                'name'        => 'Footer Column 1',
                'description' => 'First column in site footer area.',
            ],
            [
                'id'          => 'footer-2',
                'name'        => 'Footer Column 2',
                'description' => 'Second column in site footer area.',
            ],
            [
                'id'          => 'footer-3',
                'name'        => 'Footer Column 3',
                'description' => 'Third column in site footer area.',
            ],
            [
                'id'          => 'header-right',
                'name'        => 'Header Right',
                'description' => 'Optional widget area located in site header navigation bar.',
            ],
        ];
    }

    /**
     * Default homepage sections if theme manifest has none defined.
     */
    protected function getDefaultFallbackSections(): array
    {
        return [
            [
                'id'          => 'hero',
                'name'        => 'Welcome Hero Banner',
                'description' => 'Top introductory welcome headline and tagline.',
                'enabled'     => true,
            ],
            [
                'id'          => 'featured-posts',
                'name'        => 'Featured Articles Showcase',
                'description' => 'Highlights sticky or selected featured articles.',
                'enabled'     => true,
            ],
            [
                'id'          => 'latest-posts',
                'name'        => 'Latest Posts Feed',
                'description' => 'Standard blog article stream with pagination.',
                'enabled'     => true,
            ],
        ];
    }

    /**
     * Get theme modification setting value.
     */
    public function getThemeMod(string $name, mixed $default = null, ?string $themeId = null): mixed
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_mods_{$tid}";
        return Setting::get($group, $name, $default);
    }

    /**
     * Set theme modification setting value.
     */
    public function setThemeMod(string $name, mixed $value, ?string $themeId = null): void
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_mods_{$tid}";
        $type = is_int($value) ? 'int' : (is_bool($value) ? 'bool' : (is_array($value) ? 'json' : 'string'));
        Setting::set($group, $name, $value, $type);
    }

    /**
     * Get all theme modification settings as an associative array.
     */
    public function getAllThemeMods(?string $themeId = null): array
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_mods_{$tid}";
        return Setting::getGroup($group);
    }

    /**
     * Get ordered homepage layout sections.
     */
    public function getSections(?string $themeId = null): array
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $manifest = $this->getThemeManifest($tid);
        $manifestSections = $manifest['sections'] ?? [];

        $group = "theme_sections_{$tid}";
        $savedOrder = Setting::get($group, '_section_order', []);
        if (is_string($savedOrder)) {
            $savedOrder = json_decode($savedOrder, true);
        }

        $sectionsById = [];
        foreach ($manifestSections as $s) {
            $sid = $s['id'];
            $savedConfig = Setting::get($group, $sid, null);
            if (is_string($savedConfig)) {
                $savedConfig = json_decode($savedConfig, true);
            }

            $sectionsById[$sid] = array_merge($s, is_array($savedConfig) ? $savedConfig : []);
            if (!isset($sectionsById[$sid]['enabled'])) {
                $sectionsById[$sid]['enabled'] = $s['enabled'] ?? true;
            }
        }

        // Order according to saved order
        $ordered = [];
        if (is_array($savedOrder) && !empty($savedOrder)) {
            foreach ($savedOrder as $sid) {
                if (isset($sectionsById[$sid])) {
                    $ordered[] = $sectionsById[$sid];
                    unset($sectionsById[$sid]);
                }
            }
        }

        foreach ($sectionsById as $s) {
            $ordered[] = $s;
        }

        return $ordered;
    }

    /**
     * Update section configuration (e.g. toggle enabled state or custom settings).
     */
    public function updateSection(string $sectionId, array $data, ?string $themeId = null): bool
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_sections_{$tid}";

        $current = Setting::get($group, $sectionId, []);
        if (is_string($current)) {
            $current = json_decode($current, true);
        }
        $updated = array_merge(is_array($current) ? $current : [], $data);

        Setting::set($group, $sectionId, $updated, 'json');
        return true;
    }

    /**
     * Reorder homepage sections.
     */
    public function reorderSections(array $orderedSectionIds, ?string $themeId = null): bool
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_sections_{$tid}";
        Setting::set($group, '_section_order', array_values($orderedSectionIds), 'json');
        return true;
    }

    /**
     * Ensure sensible default widget layout is seeded for a theme on first activation.
     */
    public function ensureDefaultLayout(?string $themeId = null): void
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $isSeeded = Setting::get("widget_seeded_{$tid}", 'is_seeded', false);

        if ($isSeeded) {
            return;
        }

        $manifest = $this->getThemeManifest($tid);
        $defaults = $manifest['default_widgets'] ?? null;

        // Standard sensible default widget placement if theme didn't specify
        if (empty($defaults) || !is_array($defaults)) {
            $defaults = [
                'sidebar-primary' => [
                    ['widget' => 'search', 'settings' => ['title' => 'Search Articles']],
                    ['widget' => 'recent_posts', 'settings' => ['title' => 'Recent Articles', 'number' => 5, 'show_date' => true]],
                    ['widget' => 'categories', 'settings' => ['title' => 'Categories', 'show_count' => true]],
                    ['widget' => 'tags', 'settings' => ['title' => 'Popular Tags', 'limit' => 15]],
                ],
                'footer-1' => [
                    ['widget' => 'nav_menu', 'settings' => ['title' => 'Navigation']],
                ],
                'footer-2' => [
                    ['widget' => 'recent_posts', 'settings' => ['title' => 'Latest Stories', 'number' => 3]],
                ],
                'footer-3' => [
                    ['widget' => 'custom_html', 'settings' => ['title' => 'About Site', 'content' => '<p>A fast, modern website powered by Favorite CMS.</p>']],
                ],
            ];
        }

        foreach ($defaults as $regionId => $widgetConfigs) {
            if (!is_array($widgetConfigs)) continue;
            foreach ($widgetConfigs as $cfg) {
                $wId = $cfg['widget'] ?? '';
                $settings = $cfg['settings'] ?? [];
                if ($wId !== '' && $this->instanceManager->getActiveThemeId() !== '') {
                    try {
                        $this->instanceManager->createInstance($wId, $regionId, $settings, $tid);
                    } catch (\Throwable) {
                        // Ignore if specific widget type is missing during seeding
                    }
                }
            }
        }

        Setting::set("widget_seeded_{$tid}", 'is_seeded', true, 'bool');
    }

    /**
     * Reset theme layout to defaults.
     */
    public function resetThemeLayout(?string $themeId = null): void
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);

        // Delete all instances and regions for this theme
        $db->delete('settings', ['group_name' => "widget_{$tid}"]);
        $db->delete('settings', ['group_name' => "widget_regions_{$tid}"]);
        $db->delete('settings', ['group_name' => "theme_sections_{$tid}"]);
        $db->delete('settings', ['group_name' => "theme_mods_{$tid}"]);
        $db->delete('settings', ['group_name' => "widget_seeded_{$tid}"]);

        Setting::clearCache();

        // Re-seed defaults
        $this->ensureDefaultLayout($tid);
    }
}

