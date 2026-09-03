<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Plugins\PluginManager;

class PluginController
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function index(Request $request): Response
    {
        $manager = new PluginManager($this->app);
        $plugins = $manager->getInstalledPlugins();
        $bootErrors = $manager->getBootErrors();

        $viewData = [
            'pageTitle'   => 'Plugins',
            'activeMenu'  => 'plugins',
            'plugins'     => $plugins,
            'bootErrors'  => $bootErrors,
            'contentView' => APP_ROOT . '/resources/views/admin/plugins/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function activate(Request $request): Response
    {
        $id = trim((string)$request->get('id', ''));
        if ($id === '') {
            $_SESSION['flash_error'] = 'Invalid plugin identifier.';
            return Response::redirect('/admin/plugins');
        }

        $manager = new PluginManager($this->app);

        try {
            $manager->activatePlugin($id);
            $_SESSION['flash_success'] = "Plugin '{$id}' activated successfully.";
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return Response::redirect('/admin/plugins');
    }

    public function deactivate(Request $request): Response
    {
        $id = trim((string)$request->get('id', ''));
        if ($id === '') {
            $_SESSION['flash_error'] = 'Invalid plugin identifier.';
            return Response::redirect('/admin/plugins');
        }

        $manager = new PluginManager($this->app);

        try {
            $manager->deactivatePlugin($id);
            $_SESSION['flash_success'] = "Plugin '{$id}' deactivated.";
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return Response::redirect('/admin/plugins');
    }

    public function upload(Request $request): Response
    {
        $manager = new PluginManager($this->app);

        if (empty($_FILES['plugin_zip'])) {
            $_SESSION['flash_error'] = 'No ZIP file selected.';
            return Response::redirect('/admin/plugins');
        }

        try {
            $res = $manager->installFromZip($_FILES['plugin_zip']);
            if ($res['valid']) {
                $_SESSION['flash_success'] = "Plugin '{$res['plugin_id']}' installed successfully and verified.";
            } else {
                $err = implode(' ', $res['errors']);
                $_SESSION['flash_error'] = "Plugin installed with warnings: {$err}";
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = "Plugin installation failed: " . $e->getMessage();
        }

        return Response::redirect('/admin/plugins');
    }

    public function delete(Request $request): Response
    {
        $id = trim((string)$request->get('id', ''));
        if ($id === '') {
            $_SESSION['flash_error'] = 'Invalid plugin identifier.';
            return Response::redirect('/admin/plugins');
        }

        $manager = new PluginManager($this->app);

        try {
            $manager->uninstallPlugin($id);
            $_SESSION['flash_success'] = "Plugin '{$id}' uninstalled and deleted.";
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return Response::redirect('/admin/plugins');
    }
}

