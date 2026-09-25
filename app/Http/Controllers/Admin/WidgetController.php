<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Themes\ThemeLayoutService;
use FavoriteCMS\Widgets\WidgetInstanceManager;
use FavoriteCMS\Widgets\WidgetRegistry;

class WidgetController
{
    protected Application $app;
    protected WidgetRegistry $registry;
    protected WidgetInstanceManager $instanceManager;
    protected ThemeLayoutService $layoutService;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->registry = WidgetRegistry::getInstance();
        $this->instanceManager = new WidgetInstanceManager($this->registry);
        $this->layoutService = new ThemeLayoutService($app, $this->instanceManager);
    }

    public function index(Request $request): Response
    {
        $themeId = $this->layoutService->getActiveThemeId();
        
        // Ensure default layout has been seeded for active theme
        $this->layoutService->ensureDefaultLayout($themeId);

        $manifest = $this->layoutService->getThemeManifest($themeId);
        $regions = $manifest['regions'] ?? [];
        $availableWidgets = $this->registry->getByCategory();

        // Load placed widgets for each region
        $regionData = [];
        foreach ($regions as $r) {
            $rid = $r['id'];
            $instances = $this->instanceManager->getRegionInstances($rid, $themeId);
            $regionData[$rid] = [
                'meta'      => $r,
                'instances' => $instances,
            ];
        }

        $viewData = [
            'pageTitle'        => 'Widgets',
            'activeMenu'       => 'widgets',
            'themeName'        => $manifest['name'] ?? ucfirst($themeId),
            'themeId'          => $themeId,
            'regions'          => $regions,
            'regionData'       => $regionData,
            'availableWidgets' => $availableWidgets,
            'registry'         => $this->registry,
            'contentView'      => APP_ROOT . '/resources/views/admin/widgets/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function store(Request $request): Response
    {
        $widgetId = trim((string)$request->post('widget_id', ''));
        $regionId = trim((string)$request->post('region_id', ''));

        if ($widgetId === '' || $regionId === '') {
            $_SESSION['flash_error'] = 'Please select both a widget type and a theme region.';
            return Response::redirect('/admin/widgets');
        }

        try {
            $instanceId = $this->instanceManager->createInstance($widgetId, $regionId);
            $_SESSION['flash_success'] = 'Widget added to region successfully.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to add widget: ' . $e->getMessage();
        }

        return Response::redirect('/admin/widgets');
    }

    public function update(Request $request): Response
    {
        $instanceId = trim((string)$request->post('instance_id', ''));
        $settings   = (array)$request->post('settings', []);
        $visibility = (array)$request->post('visibility', []);

        if ($instanceId === '') {
            $_SESSION['flash_error'] = 'Invalid widget instance specified.';
            return Response::redirect('/admin/widgets');
        }

        $success = $this->instanceManager->updateInstance($instanceId, $settings, $visibility);
        if ($success) {
            $_SESSION['flash_success'] = 'Widget settings saved successfully.';
        } else {
            $_SESSION['flash_error'] = 'Failed to update widget settings.';
        }

        return Response::redirect('/admin/widgets');
    }

    public function delete(Request $request): Response
    {
        $instanceId = trim((string)$request->post('instance_id', ''));
        if ($instanceId === '') {
            $instanceId = trim((string)$request->get('instance_id', ''));
        }

        if ($instanceId === '') {
            $_SESSION['flash_error'] = 'Invalid widget instance specified for deletion.';
            return Response::redirect('/admin/widgets');
        }

        $success = $this->instanceManager->deleteInstance($instanceId);
        if ($success) {
            $_SESSION['flash_success'] = 'Widget removed from region.';
        } else {
            $_SESSION['flash_error'] = 'Failed to delete widget.';
        }

        return Response::redirect('/admin/widgets');
    }

    public function reorder(Request $request): Response
    {
        $regionId = trim((string)$request->post('region_id', ''));
        $instanceIds = (array)$request->post('instance_ids', []);

        if ($regionId !== '') {
            $this->instanceManager->reorderRegion($regionId, $instanceIds);
            return Response::json(['success' => true], 200);
        }

        return Response::json(['success' => false, 'message' => 'Missing region ID.'], 400);
    }

    public function move(Request $request): Response
    {
        $instanceId     = trim((string)$request->post('instance_id', ''));
        $targetRegionId = trim((string)$request->post('target_region_id', ''));
        $direction      = trim((string)$request->post('direction', '')); // 'up' or 'down'

        if ($instanceId === '') {
            $_SESSION['flash_error'] = 'Invalid widget instance.';
            return Response::redirect('/admin/widgets');
        }

        $instance = $this->instanceManager->getInstance($instanceId);
        if (!$instance) {
            $_SESSION['flash_error'] = 'Widget instance not found.';
            return Response::redirect('/admin/widgets');
        }

        $currentRegionId = $instance['region_id'];

        // If shifting within same region up or down
        if ($direction === 'up' || $direction === 'down') {
            $ids = $this->instanceManager->getRegionInstanceIds($currentRegionId);
            $idx = array_search($instanceId, $ids, true);
            if ($idx !== false) {
                $targetIdx = ($direction === 'up') ? $idx - 1 : $idx + 1;
                if ($targetIdx >= 0 && $targetIdx < count($ids)) {
                    // Swap positions
                    $temp = $ids[$idx];
                    $ids[$idx] = $ids[$targetIdx];
                    $ids[$targetIdx] = $temp;
                    $this->instanceManager->reorderRegion($currentRegionId, $ids);
                    $_SESSION['flash_success'] = 'Widget position updated.';
                    return Response::redirect('/admin/widgets');
                }
            }
        } elseif ($targetRegionId !== '' && $targetRegionId !== $currentRegionId) {
            $this->instanceManager->moveInstance($instanceId, $targetRegionId);
            $_SESSION['flash_success'] = 'Widget moved to ' . htmlspecialchars($targetRegionId) . '.';
            return Response::redirect('/admin/widgets');
        }

        return Response::redirect('/admin/widgets');
    }

    public function duplicate(Request $request): Response
    {
        $instanceId = trim((string)$request->post('instance_id', ''));
        if ($instanceId === '') {
            $_SESSION['flash_error'] = 'Invalid widget instance.';
            return Response::redirect('/admin/widgets');
        }

        $newId = $this->instanceManager->duplicateInstance($instanceId);
        if ($newId) {
            $_SESSION['flash_success'] = 'Widget duplicated successfully.';
        } else {
            $_SESSION['flash_error'] = 'Failed to duplicate widget.';
        }

        return Response::redirect('/admin/widgets');
    }

    public function reset(Request $request): Response
    {
        $this->layoutService->resetThemeLayout();
        $_SESSION['flash_success'] = 'Theme widgets restored to original defaults.';
        return Response::redirect('/admin/widgets');
    }
}

