<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Themes\ThemeManager;

class ThemeController
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function index(Request $request): Response
    {
        $manager = new ThemeManager($this->app);
        $themes = $manager->getInstalledThemes();
        $activeTheme = $manager->getActiveTheme();

        $viewData = [
            'pageTitle'   => 'Themes',
            'activeMenu'  => 'themes',
            'themes'      => $themes,
            'activeTheme' => $activeTheme,
            'contentView' => APP_ROOT . '/resources/views/admin/themes/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function activate(Request $request): Response
    {
        $themeId = trim((string)$request->get('id', ''));
        $manager = new ThemeManager($this->app);

        try {
            $manager->activateTheme($themeId);
            $_SESSION['flash_success'] = "Theme '{$themeId}' activated successfully.";
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return Response::redirect('/admin/themes');
    }

    public function upload(Request $request): Response
    {
        $manager = new ThemeManager($this->app);

        if (empty($_FILES['theme_zip'])) {
            $_SESSION['flash_error'] = 'No ZIP file selected.';
            return Response::redirect('/admin/themes');
        }

        try {
            $res = $manager->installFromZip($_FILES['theme_zip']);
            $_SESSION['flash_success'] = "Theme '{$res['theme_id']}' installed successfully.";
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = "Theme installation failed: " . $e->getMessage();
        }

        return Response::redirect('/admin/themes');
    }

    public function delete(Request $request): Response
    {
        $themeId = trim((string)$request->get('id', ''));
        $manager = new ThemeManager($this->app);

        try {
            $manager->deleteTheme($themeId);
            $_SESSION['flash_success'] = "Theme '{$themeId}' deleted.";
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return Response::redirect('/admin/themes');
    }
}

