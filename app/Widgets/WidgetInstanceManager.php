<?php

declare(strict_types=1);

namespace FavoriteCMS\Widgets;

use FavoriteCMS\Core\Hook;
use FavoriteCMS\Models\Setting;

class WidgetInstanceManager
{
    protected WidgetRegistry $registry;

    public function __construct(?WidgetRegistry $registry = null)
    {
        $this->registry = $registry ?? WidgetRegistry::getInstance();
    }

    /**
     * Resolve active theme identifier.
     */
    public function getActiveThemeId(): string
    {
        try {
            $active = Setting::get('theme', 'active_theme', 'default');
            return is_string($active) && $active !== '' ? $active : 'default';
        } catch (\Throwable) {
            return 'default';
        }
    }

    /**
     * Get the settings group name for instances of a specific theme.
     */
    protected function getThemeInstanceGroup(?string $themeId = null): string
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        return "widget_{$tid}";
    }

    /**
     * Get the settings group name for region lists of a specific theme.
     */
    protected function getThemeRegionGroup(?string $themeId = null): string
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        return "widget_regions_{$tid}";
    }

    /**
     * Retrieve a specific widget instance configuration by ID.
     */
    public function getInstance(string $instanceId, ?string $themeId = null): ?array
    {
        $group = $this->getThemeInstanceGroup($themeId);
        $data = Setting::get($group, $instanceId);

        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Get all ordered instance IDs in a given region.
     *
     * @return array<string> List of instance IDs.
     */
    public function getRegionInstanceIds(string $regionId, ?string $themeId = null): array
    {
        $group = $this->getThemeRegionGroup($themeId);
        $list = Setting::get($group, $regionId, []);

        if (is_string($list)) {
            $list = json_decode($list, true);
        }

        return is_array($list) ? array_values($list) : [];
    }

    /**
     * Get all full instance objects for a region in configured order.
     */
    public function getRegionInstances(string $regionId, ?string $themeId = null): array
    {
        $instanceIds = $this->getRegionInstanceIds($regionId, $themeId);
        $instances = [];

        foreach ($instanceIds as $id) {
            $inst = $this->getInstance($id, $themeId);
            if ($inst) {
                $instances[] = $inst;
            }
        }

        return $instances;
    }

    /**
     * Create and place a new widget instance into a region.
     */
    public function createInstance(string $widgetId, string $regionId, array $settings = [], ?string $themeId = null): string
    {
        $widget = $this->registry->get($widgetId);
        if (!$widget) {
            throw new \InvalidArgumentException("Cannot create instance: Widget '{$widgetId}' is not registered.");
        }

        $themeId = $themeId ?: $this->getActiveThemeId();
        $baseSlug = str_replace('_', '-', $widgetId);
        $instanceId = $baseSlug . '-' . bin2hex(random_bytes(3));

        $mergedSettings = array_merge($widget->getDefaultSettings(), $settings);

        $instanceData = [
            'id'         => $instanceId,
            'widget_id'  => $widgetId,
            'region_id'  => $regionId,
            'settings'   => $mergedSettings,
            'visibility' => [
                'show_on' => 'all', // 'all', 'home', 'posts', 'pages'
            ],
            'created_at' => date('Y-m-d H:i:s'),
        ];

        // 1. Save instance data
        Setting::set($this->getThemeInstanceGroup($themeId), $instanceId, $instanceData, 'json');

        // 2. Append to region order list
        $currentIds = $this->getRegionInstanceIds($regionId, $themeId);
        if (!in_array($instanceId, $currentIds, true)) {
            $currentIds[] = $instanceId;
            Setting::set($this->getThemeRegionGroup($themeId), $regionId, $currentIds, 'json');
        }

        return $instanceId;
    }

    /**
     * Update settings and visibility for an existing widget instance.
     */
    public function updateInstance(string $instanceId, array $settings, array $visibility = [], ?string $themeId = null): bool
    {
        $instance = $this->getInstance($instanceId, $themeId);
        if (!$instance) {
            return false;
        }

        $instance['settings'] = array_merge($instance['settings'] ?? [], $settings);
        if (!empty($visibility)) {
            $instance['visibility'] = array_merge($instance['visibility'] ?? [], $visibility);
        }
        $instance['updated_at'] = date('Y-m-d H:i:s');

        Setting::set($this->getThemeInstanceGroup($themeId), $instanceId, $instance, 'json');
        return true;
    }

    /**
     * Delete an instance and remove it from its region.
     */
    public function deleteInstance(string $instanceId, ?string $themeId = null): bool
    {
        $instance = $this->getInstance($instanceId, $themeId);
        if (!$instance) {
            return false;
        }

        $regionId = $instance['region_id'] ?? '';
        $themeId = $themeId ?: $this->getActiveThemeId();

        // 1. Remove from region list
        if ($regionId !== '') {
            $ids = $this->getRegionInstanceIds($regionId, $themeId);
            $ids = array_values(array_filter($ids, fn($id) => $id !== $instanceId));
            Setting::set($this->getThemeRegionGroup($themeId), $regionId, $ids, 'json');
        }

        // 2. Delete instance record
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $db->delete('settings', [
            'group_name'  => $this->getThemeInstanceGroup($themeId),
            'setting_key' => $instanceId,
        ]);
        Setting::clearCache();

        return true;
    }

    /**
     * Reorder instances in a region.
     */
    public function reorderRegion(string $regionId, array $orderedInstanceIds, ?string $themeId = null): bool
    {
        $themeId = $themeId ?: $this->getActiveThemeId();
        $cleanIds = array_values(array_unique(array_filter($orderedInstanceIds, fn($id) => is_string($id) && trim($id) !== '')));
        Setting::set($this->getThemeRegionGroup($themeId), $regionId, $cleanIds, 'json');
        return true;
    }

    /**
     * Move a widget instance from its current region to another region.
     */
    public function moveInstance(string $instanceId, string $targetRegionId, int $targetIndex = -1, ?string $themeId = null): bool
    {
        $instance = $this->getInstance($instanceId, $themeId);
        if (!$instance) {
            return false;
        }

        $sourceRegionId = $instance['region_id'] ?? '';
        $themeId = $themeId ?: $this->getActiveThemeId();

        // 1. Remove from source region
        if ($sourceRegionId !== '') {
            $sourceIds = $this->getRegionInstanceIds($sourceRegionId, $themeId);
            $sourceIds = array_values(array_filter($sourceIds, fn($id) => $id !== $instanceId));
            Setting::set($this->getThemeRegionGroup($themeId), $sourceRegionId, $sourceIds, 'json');
        }

        // 2. Insert into target region at specified index
        $targetIds = $this->getRegionInstanceIds($targetRegionId, $themeId);
        $targetIds = array_values(array_filter($targetIds, fn($id) => $id !== $instanceId));

        if ($targetIndex >= 0 && $targetIndex < count($targetIds)) {
            array_splice($targetIds, $targetIndex, 0, [$instanceId]);
        } else {
            $targetIds[] = $instanceId;
        }

        Setting::set($this->getThemeRegionGroup($themeId), $targetRegionId, $targetIds, 'json');

        // 3. Update instance's region_id
        $instance['region_id'] = $targetRegionId;
        Setting::set($this->getThemeInstanceGroup($themeId), $instanceId, $instance, 'json');

        return true;
    }

    /**
     * Duplicate a widget instance within the same region.
     */
    public function duplicateInstance(string $instanceId, ?string $themeId = null): ?string
    {
        $instance = $this->getInstance($instanceId, $themeId);
        if (!$instance) {
            return null;
        }

        $settings = $instance['settings'] ?? [];
        if (isset($settings['title'])) {
            $settings['title'] .= ' (Copy)';
        }

        return $this->createInstance(
            $instance['widget_id'],
            $instance['region_id'],
            $settings,
            $themeId
        );
    }

    /**
     * Evaluate whether a widget instance should be visible based on its visibility rules.
     */
    public function isInstanceVisible(array $instance): bool
    {
        $rule = $instance['visibility']['show_on'] ?? 'all';
        if ($rule === 'all') {
            return true;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        if ($rule === 'home') {
            return $path === '/' || $path === '';
        }

        if ($rule === 'posts') {
            return str_starts_with($path, '/post/');
        }

        if ($rule === 'pages') {
            return str_starts_with($path, '/page/');
        }

        return true;
    }

    /**
     * Render an individual widget instance HTML.
     */
    public function renderInstance(string $instanceId, array $args = [], ?string $themeId = null): string
    {
        $instance = $this->getInstance($instanceId, $themeId);
        if (!$instance) {
            return '';
        }

        if (!$this->isInstanceVisible($instance)) {
            return '';
        }

        $widget = $this->registry->get($instance['widget_id']);
        if (!$widget) {
            return '';
        }

        $settings = $instance['settings'] ?? [];
        $rendered = $widget->render($settings, $args);

        return Hook::applyFilters('widget_output', $rendered, $widget, $instance, $args);
    }

    /**
     * Render all widgets in a region.
     */
    public function renderRegion(string $regionId, array $args = [], ?string $themeId = null): string
    {
        $instances = $this->getRegionInstances($regionId, $themeId);
        $output = '';

        foreach ($instances as $inst) {
            $output .= $this->renderInstance($inst['id'], $args, $themeId) . "\n";
        }

        return Hook::applyFilters('region_output', $output, $regionId, $instances, $args);
    }

    /**
     * Check if a region has any configured and visible widgets.
     */
    public function hasRegionWidgets(string $regionId, ?string $themeId = null): bool
    {
        $instances = $this->getRegionInstances($regionId, $themeId);
        foreach ($instances as $inst) {
            if ($this->isInstanceVisible($inst)) {
                return true;
            }
        }
        return false;
    }
}

