<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Themes\BuilderElementRegistry;
use FavoriteCMS\Themes\ThemeLayoutService;

class CustomizeController
{
    protected Application $app;
    protected ThemeLayoutService $layoutService;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->layoutService = new ThemeLayoutService($app);
    }

    public function index(Request $request): Response
    {
        $themeId = $this->layoutService->getActiveThemeId();
        $manifest = $this->layoutService->getThemeManifest($themeId);
        $sections = $this->layoutService->getSections($themeId);
        $mods = $this->layoutService->getAllThemeMods($themeId);

        if (empty($mods['site_logo_url'])) {
            $mods['site_logo_url'] = get_site_logo_url();
        }
        if (empty($mods['site_favicon_url'])) {
            $mods['site_favicon_url'] = get_site_favicon_url();
        }

        $contentView = APP_ROOT . '/resources/views/admin/customize/index.php';
        $themeCustomizer = APP_ROOT . '/themes/' . $themeId . '/customizer.php';
        if (file_exists($themeCustomizer)) {
            $contentView = $themeCustomizer;
        }
        if (function_exists('apply_filters')) {
            $contentView = apply_filters('theme_customizer_view', $contentView, $themeId, $mods, $sections);
        }

        $globalTokens = $this->layoutService->getGlobalDesignTokens($themeId);
        $builderTree = $this->layoutService->getBuilderTree($themeId);
        $templates = $this->layoutService->getTemplates($themeId);
        $elementsRegistry = BuilderElementRegistry::getInstance()->all();

        try {
            $siteName = Setting::get('general', 'site_name', 'Favorite CMS');
        } catch (\Throwable) {
            $siteName = 'Favorite CMS';
        }

        $adminTheme = $_SESSION['admin_theme'] ?? 'light';
        if ($adminTheme !== 'dark') {
            $adminTheme = 'light';
        }

        $viewData = [
            'pageTitle'        => 'Customize: ' . ($manifest['name'] ?? ucfirst($themeId)),
            'themeName'        => $manifest['name'] ?? ucfirst($themeId),
            'themeId'          => $themeId,
            'manifest'         => $manifest,
            'sections'         => $sections,
            'mods'             => $mods,
            'contentView'      => $contentView,
            'globalTokens'     => $globalTokens,
            'builderTree'      => $builderTree,
            'templates'        => $templates,
            'elementsRegistry' => $elementsRegistry,
            'csrfToken'        => csrf_token(),
            'adminTheme'       => $adminTheme,
            'siteName'         => $siteName,
            'siteFaviconUrl'   => get_site_favicon_url(''),
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/customize/shell.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function save(Request $request): Response
    {
        $themeId = $this->layoutService->getActiveThemeId();

        // 1. Save theme mods (layout, colors, logo, copyright, custom css, etc.)
        $mods = (array)$request->post('mods', []);
        foreach ($mods as $key => $val) {
            $keyStr = (string)$key;
            if ($keyStr === 'site_logo_url' || $keyStr === 'site_favicon_url') {
                $val = sanitize_branding_url((string)$val);
            }
            $this->layoutService->setThemeMod($keyStr, $val, $themeId);
        }

        // 2. Save homepage section enabled states
        $sectionsConfig = (array)$request->post('sections', []);
        $allSections = $this->layoutService->getSections($themeId);

        foreach ($allSections as $s) {
            $sid = $s['id'];
            $isEnabled = !empty($sectionsConfig[$sid]['enabled']);
            $this->layoutService->updateSection($sid, ['enabled' => $isEnabled], $themeId);
        }

        // 3. Save batch section order if provided
        $sectionOrder = (array)$request->post('section_order', []);
        if (!empty($sectionOrder)) {
            $validIds = array_column($allSections, 'id');
            $cleanOrder = array_values(array_filter(
                array_map('strval', $sectionOrder),
                static fn(string $id): bool => in_array($id, $validIds, true)
            ));
            if (!empty($cleanOrder)) {
                $this->layoutService->reorderSections($cleanOrder, $themeId);
            }
        }

        // 4. Save Visual Builder tree if provided
        $builderTree = $request->post('builder_tree');
        if ($builderTree !== null) {
            if (is_string($builderTree)) {
                $builderTree = json_decode($builderTree, true);
            }
            if (is_array($builderTree)) {
                try {
                    $this->layoutService->saveBuilderTree($builderTree, $themeId);
                } catch (\Throwable $e) {
                    if ($this->isAjaxRequest($request)) {
                        return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
                    }
                }
            }
        }

        // 5. Save Global Design Tokens if provided
        $globalTokens = $request->post('tokens');
        if ($globalTokens !== null) {
            if (is_string($globalTokens)) {
                $globalTokens = json_decode($globalTokens, true);
            }
            if (is_array($globalTokens)) {
                $this->layoutService->saveGlobalDesignTokens($globalTokens, $themeId);
            }
        }

        if (function_exists('do_action')) {
            do_action('customize_save_after', $request, $themeId);
        }

        $_SESSION['flash_success'] = 'Theme layout and customization saved successfully.';

        if ($this->isAjaxRequest($request)) {
            return Response::json([
                'success' => true,
                'message' => 'Theme layout and customization saved successfully.',
            ]);
        }

        return Response::redirect('/admin/customize');
    }

    public function reorderSections(Request $request): Response
    {
        $themeId = $this->layoutService->getActiveThemeId();
        $sectionId = trim((string)$request->post('section_id', ''));
        $direction = trim((string)$request->post('direction', '')); // 'up' or 'down'

        $sections = $this->layoutService->getSections($themeId);
        $ids = array_column($sections, 'id');

        $idx = array_search($sectionId, $ids, true);
        if ($idx !== false) {
            $targetIdx = ($direction === 'up') ? $idx - 1 : $idx + 1;
            if ($targetIdx >= 0 && $targetIdx < count($ids)) {
                $temp = $ids[$idx];
                $ids[$idx] = $ids[$targetIdx];
                $ids[$targetIdx] = $temp;
                $this->layoutService->reorderSections($ids, $themeId);
                $_SESSION['flash_success'] = 'Homepage section order updated.';
            }
        }

        if ($this->isAjaxRequest($request)) {
            return Response::json(['success' => true, 'order' => $this->layoutService->getSections($themeId)]);
        }

        return Response::redirect('/admin/customize');
    }

    public function reset(Request $request): Response
    {
        $themeId = $this->layoutService->getActiveThemeId();
        $this->layoutService->resetThemeLayout($themeId);
        if (function_exists('do_action')) {
            do_action('customize_reset_after', $themeId);
        }
        $_SESSION['flash_success'] = 'Theme settings and sections restored to defaults.';

        if ($this->isAjaxRequest($request)) {
            return Response::json(['success' => true, 'message' => 'Theme settings restored to defaults.']);
        }

        return Response::redirect('/admin/customize');
    }

    public function getTemplates(Request $request): Response
    {
        $themeId = (string)($request->get('theme_id', '') ?: $this->layoutService->getActiveThemeId());
        $templates = $this->layoutService->getTemplates($themeId);
        return Response::json(['success' => true, 'templates' => $templates]);
    }

    public function saveTemplate(Request $request): Response
    {
        $themeId = (string)($request->post('theme_id', '') ?: $this->layoutService->getActiveThemeId());
        $name = trim((string)$request->post('name', 'New Template'));
        $data = $request->post('data', []);
        if (is_string($data)) {
            $data = json_decode($data, true) ?: [];
        }

        try {
            $template = $this->layoutService->saveTemplate($name, (array)$data, $themeId);
            return Response::json(['success' => true, 'template' => $template]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function deleteTemplate(Request $request): Response
    {
        $themeId = (string)($request->post('theme_id', '') ?: $this->layoutService->getActiveThemeId());
        $templateId = (string)$request->post('template_id', '');

        if ($templateId === '') {
            return Response::json(['success' => false, 'error' => 'Template ID is required.'], 400);
        }

        try {
            $this->layoutService->deleteTemplate($templateId, $themeId);
            return Response::json(['success' => true, 'message' => 'Template deleted successfully.']);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    protected function isAjaxRequest(Request $request): bool
    {
        return $request->isAjax()
            || str_contains((string)$request->header('Accept'), 'application/json')
            || !empty($request->post('_ajax'));
    }
}
