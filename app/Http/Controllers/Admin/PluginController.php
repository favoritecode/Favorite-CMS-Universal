<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
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

    public function bulkAction(Request $request): Response
    {
        $token = (string)$request->post('_token', '');
        if (empty($_SESSION['_token']) || !hash_equals($_SESSION['_token'], $token)) {
            $_SESSION['flash_error'] = 'Security verification failed (invalid CSRF token).';
            return Response::redirect('/admin/plugins');
        }

        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;
        if (!$currentUser || !$currentUser->isActive()) {
            $_SESSION['flash_error'] = 'Your account is inactive or suspended.';
            return Response::redirect('/admin/plugins');
        }

        if (!$currentUser->canManagePlugins()) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage plugins.</p>', 403);
        }

        $action = trim((string)$request->post('bulk_action', ''));
        $rawIds = (array)$request->post('ids', []);
        $ids = [];
        foreach ($rawIds as $rawId) {
            if (!is_string($rawId)) {
                continue;
            }
            $cleanId = trim($rawId);
            if ($cleanId === '' || preg_match('/[^a-zA-Z0-9_\-]/', $cleanId)) {
                continue;
            }
            $ids[] = $cleanId;
        }
        $ids = array_values(array_unique($ids));

        if (empty($action) || empty($ids)) {
            $_SESSION['flash_error'] = 'Please select at least one plugin and a bulk action.';
            return Response::redirect('/admin/plugins');
        }

        if (!in_array($action, ['activate', 'deactivate', 'delete'], true)) {
            $_SESSION['flash_error'] = 'Invalid plugin bulk action specified.';
            return Response::redirect('/admin/plugins');
        }

        $manager = new PluginManager($this->app);
        $installedPlugins = $manager->getInstalledPlugins();
        $successCount = 0;
        $failures = [];

        foreach ($ids as $id) {
            if (!isset($installedPlugins[$id])) {
                $failures[] = "Plugin '{$id}' does not exist";
                continue;
            }

            $meta = $installedPlugins[$id];
            $pluginName = $meta['name'] ?? $id;

            switch ($action) {
                case 'activate':
                    if (in_array($id, $manager->getActivePlugins(), true)) {
                        $failures[] = "'{$pluginName}' is already active";
                        continue 2;
                    }

                    try {
                        $manager->activatePlugin($id);
                        $successCount++;
                    } catch (\Throwable $e) {
                        $failures[] = "'{$pluginName}': " . $e->getMessage();
                    }
                    break;

                case 'deactivate':
                    if (!in_array($id, $manager->getActivePlugins(), true)) {
                        $failures[] = "'{$pluginName}' is not active";
                        continue 2;
                    }

                    // Dependency check: Does any OTHER active plugin (not being deactivated) depend on this?
                    $isDependedOn = false;
                    $dependedBy = '';
                    foreach ($installedPlugins as $otherId => $otherMeta) {
                        if ($otherId === $id) continue;
                        if (in_array($otherId, $manager->getActivePlugins(), true) && !in_array($otherId, $ids, true)) {
                            if (!empty($otherMeta['dependencies']) && in_array($id, $otherMeta['dependencies'], true)) {
                                $isDependedOn = true;
                                $dependedBy = $otherMeta['name'] ?? $otherId;
                                break;
                            }
                        }
                    }

                    if ($isDependedOn) {
                        $failures[] = "'{$pluginName}' cannot be deactivated because active plugin '{$dependedBy}' depends on it";
                        continue 2;
                    }

                    try {
                        $manager->deactivatePlugin($id);
                        $successCount++;
                    } catch (\Throwable $e) {
                        $failures[] = "'{$pluginName}': " . $e->getMessage();
                    }
                    break;

                case 'delete':
                    // Guard against deleting core/system/protected plugins
                    if (!empty($meta['core']) || !empty($meta['system']) || !empty($meta['protected'])) {
                        $failures[] = "'{$pluginName}' is a protected system plugin and cannot be deleted";
                        continue 2;
                    }

                    // Dependency check: Does any OTHER installed plugin (not being deleted) depend on this?
                    $isDependedOn = false;
                    $dependedBy = '';
                    foreach ($installedPlugins as $otherId => $otherMeta) {
                        if ($otherId === $id) continue;
                        if (!in_array($otherId, $ids, true)) {
                            if (!empty($otherMeta['dependencies']) && in_array($id, $otherMeta['dependencies'], true)) {
                                $isDependedOn = true;
                                $dependedBy = $otherMeta['name'] ?? $otherId;
                                break;
                            }
                        }
                    }

                    if ($isDependedOn) {
                        $failures[] = "'{$pluginName}' cannot be deleted because plugin '{$dependedBy}' depends on it";
                        continue 2;
                    }

                    // Verify directory path is strictly inside plugins directory
                    $pluginPath = $meta['path'] ?? (APP_ROOT . '/plugins/' . $id);
                    $realPluginPath = realpath($pluginPath);
                    $basePluginsPath = realpath(APP_ROOT . '/plugins');
                    if (!$realPluginPath || !$basePluginsPath || !str_starts_with($realPluginPath, $basePluginsPath)) {
                        $failures[] = "'{$pluginName}' has an invalid directory path";
                        continue 2;
                    }

                    try {
                        $deleted = $manager->uninstallPlugin($id);
                        if ($deleted) {
                            $successCount++;
                            unset($installedPlugins[$id]);
                        } else {
                            $failures[] = "'{$pluginName}' could not be deleted";
                        }
                    } catch (\Throwable $e) {
                        $failures[] = "'{$pluginName}': " . $e->getMessage();
                    }
                    break;
            }
        }

        $actionPastTense = match ($action) {
            'activate'   => 'activated',
            'deactivate' => 'deactivated',
            'delete'     => 'uninstalled and deleted',
            default      => 'processed',
        };

        if ($successCount > 0 && empty($failures)) {
            $_SESSION['flash_success'] = "{$successCount} plugin(s) successfully {$actionPastTense}.";
        } elseif ($successCount > 0 && !empty($failures)) {
            $errSummary = implode('; ', $failures);
            $_SESSION['flash_success'] = "{$successCount} plugin(s) {$actionPastTense}. Note: {$errSummary}";
        } else {
            $errSummary = !empty($failures) ? implode('; ', $failures) : 'No plugins were updated.';
            $_SESSION['flash_error'] = $errSummary;
        }

        return Response::redirect('/admin/plugins');
    }
}

