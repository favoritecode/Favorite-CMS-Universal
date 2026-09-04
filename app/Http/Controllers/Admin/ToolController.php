<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Services\BackupService;
use FavoriteCMS\Services\RestoreService;
use Throwable;

class ToolController
{
    protected Application $app;
    protected BackupService $backupService;
    protected RestoreService $restoreService;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->backupService = new BackupService();
        $this->restoreService = new RestoreService();
    }

    public function index(Request $request): Response
    {
        $db = $this->app->make(Database::class);

        // System diagnostics
        $diagnostics = [
            'PHP Version'          => PHP_VERSION,
            'Server Software'      => $_SERVER['SERVER_SOFTWARE'] ?? 'Apache',
            'Database Driver'      => 'MySQL (PDO)',
            'Database Version'     => $db->selectOne("SELECT VERSION() as v")->v ?? 'Unknown',
            'Max Execution Time'   => ini_get('max_execution_time') . 's',
            'Memory Limit'         => ini_get('memory_limit'),
            'Upload Max Filesize'  => ini_get('upload_max_filesize'),
            'Post Max Size'        => ini_get('post_max_size'),
            'Zip Extension'        => extension_loaded('zip') ? 'Enabled (Native ZipArchive)' : 'Disabled',
            'Storage Writable'     => is_writable(APP_ROOT . '/storage') ? 'Yes (0775)' : 'No',
            'Uploads Writable'     => is_writable(APP_ROOT . '/public/uploads') ? 'Yes (0775)' : 'No',
            'cURL Extension'       => extension_loaded('curl') ? 'Enabled' : 'Disabled',
            'mbstring Extension'   => extension_loaded('mbstring') ? 'Enabled' : 'Disabled',
            'JSON Extension'       => extension_loaded('json') ? 'Enabled' : 'Disabled',
        ];

        $backups = $this->backupService->getBackups();

        // Flash message handling
        $notice = $_SESSION['_flash_notice'] ?? null;
        $error = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash_notice'], $_SESSION['_flash_error']);

        $viewData = [
            'pageTitle'   => 'Tools & Backup Manager',
            'activeMenu'  => 'tools',
            'diagnostics' => $diagnostics,
            'backups'     => $backups,
            'notice'      => $notice,
            'error'       => $error,
            'csrfToken'   => $_SESSION['_token'] ?? '',
            'contentView' => APP_ROOT . '/resources/views/admin/tools/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function createBackup(Request $request): Response
    {
        $this->validateCsrf($request);

        try {
            $includeMedia = $request->post('include_media', '1') === '1';
            $includeThemes = $request->post('include_themes', '1') === '1';
            $includePlugins = $request->post('include_plugins', '1') === '1';

            $result = $this->backupService->createBackup([
                'include_media'   => $includeMedia,
                'include_themes'  => $includeThemes,
                'include_plugins' => $includePlugins,
            ]);

            $_SESSION['_flash_notice'] = "Backup created successfully ({$result['filename']}, " . round($result['size'] / 1024 / 1024, 2) . " MB).";
        } catch (Throwable $e) {
            $_SESSION['_flash_error'] = "Backup creation failed: " . $e->getMessage();
        }

        return Response::redirect('/admin/tools');
    }

    public function downloadBackup(Request $request): Response
    {
        $file = (string)$request->get('file', '');
        $safeName = basename($file);

        if ($safeName === '' || !preg_match('/^favorite_cms_backup_[A-Za-z0-9_.-]+\.zip$/', $safeName)) {
            return Response::make('Invalid backup file request.', 400);
        }

        $filePath = APP_ROOT . '/storage/backups/' . $safeName;
        if (!file_exists($filePath)) {
            return Response::make('Backup file not found.', 404);
        }

        $response = Response::make((string)file_get_contents($filePath), 200);
        $response->header('Content-Type', 'application/zip');
        $response->header('Content-Disposition', 'attachment; filename="' . $safeName . '"');
        $response->header('Content-Length', (string)filesize($filePath));
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }

    public function deleteBackup(Request $request): Response
    {
        $this->validateCsrf($request);

        $file = (string)$request->post('file', '');
        try {
            $deleted = $this->backupService->deleteBackup($file);
            if ($deleted) {
                $_SESSION['_flash_notice'] = "Backup deleted successfully.";
            } else {
                $_SESSION['_flash_error'] = "Backup file could not be found or deleted.";
            }
        } catch (Throwable $e) {
            $_SESSION['_flash_error'] = "Delete failed: " . $e->getMessage();
        }

        return Response::redirect('/admin/tools');
    }

    public function restoreBackup(Request $request): Response
    {
        $this->validateCsrf($request);

        $file = $_FILES['restore_file'] ?? null;
        if (!$file || empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['_flash_error'] = 'Please select a valid Favorite CMS backup (.zip) archive to restore.';
            return Response::redirect('/admin/tools');
        }

        try {
            $db = $this->app->make(Database::class);
            $dbConfig = [
                'driver'   => 'mysql',
                'host'     => (string)env('DB_HOST', 'localhost'),
                'port'     => (string)env('DB_PORT', '3306'),
                'database' => (string)env('DB_DATABASE', ''),
                'username' => (string)env('DB_USERNAME', ''),
                'password' => (string)env('DB_PASSWORD', ''),
                'prefix'   => $db->prefix(),
            ];

            $newSiteUrl = trim((string)$request->post('new_site_url', ''));
            if ($newSiteUrl === '') {
                $newSiteUrl = (string)env('APP_URL', 'http://localhost');
            }

            $result = $this->restoreService->restoreBackup(
                $file['tmp_name'],
                $dbConfig,
                $newSiteUrl,
                true
            );

            $_SESSION['_flash_notice'] = "Site successfully restored! {$result['tables_restored']} tables restored; {$result['migrated_urls']} URL references migrated.";
        } catch (Throwable $e) {
            $_SESSION['_flash_error'] = "Restore failed: " . $e->getMessage();
        }

        return Response::redirect('/admin/tools');
    }

    public function export(Request $request): Response
    {
        $db = $this->app->make(Database::class);
        $tables = $db->select('SHOW TABLES');

        $backup = [
            'cms_version' => defined('APP_VERSION') ? APP_VERSION : '1.0.0-beta',
            'exported_at' => date('c'),
            'tables'      => [],
        ];

        foreach ($tables as $t) {
            $tableName = array_values((array)$t)[0];
            $rows = $db->select("SELECT * FROM `{$tableName}`");
            $backup['tables'][$tableName] = $rows;
        }

        $json = json_encode($backup, JSON_PRETTY_PRINT);
        $filename = 'favorite_cms_backup_' . date('Y-m-d_His') . '.json';

        $response = Response::make((string)$json, 200);
        $response->header('Content-Type', 'application/json');
        $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        return $response;
    }

    protected function validateCsrf(Request $request): void
    {
        $token = (string)$request->post('_token', '');
        $sessionToken = (string)($_SESSION['_token'] ?? '');

        if ($token === '' || !hash_equals($sessionToken, $token)) {
            throw new \RuntimeException('Security check failed (invalid CSRF token). Please try again.');
        }
    }
}
