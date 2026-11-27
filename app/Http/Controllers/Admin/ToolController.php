<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Services\BackupService;
use FavoriteCMS\Services\BloggerImportService;
use FavoriteCMS\Services\Import\ImportEngine;
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

        $this->backupService->maybeApplyRetention();
        $backups = $this->backupService->getBackups();

        // Flash message handling
        $notice = $_SESSION['_flash_notice'] ?? null;
        $error = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash_notice'], $_SESSION['_flash_error']);

        $viewData = [
            'pageTitle'   => 'Tools & Backup Manager',
            'activeMenu'  => 'tools',
            'diagnostics' => $diagnostics,
            'backups'        => $backups,
            'backupPolicy'   => $this->backupService->retentionPolicy(),
            'notice'         => $notice,
            'error'          => $error,
            'bloggerPreview' => $_SESSION['blogger_import_preview'] ?? null,
            'bloggerToken'   => $_SESSION['blogger_import_token'] ?? '',
            'csrfToken'      => $_SESSION['_token'] ?? '',
            'contentView'    => APP_ROOT . '/resources/views/admin/tools/index.php',
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

        $real=realpath($filePath);$base=realpath(APP_ROOT.'/storage/backups');
        if(is_link($filePath)||!$real||!$base||dirname($real)!==$base)return Response::make('Invalid backup file request.',400);
        return new \FavoriteCMS\Core\FileDownloadResponse($real,$safeName,'application/zip');
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

    public function deleteSelectedBackups(Request $request): Response
    {
        $this->validateCsrf($request);

        $files = $request->post('files', []);
        if (!is_array($files) || $files === []) {
            $_SESSION['_flash_error'] = 'Select at least one backup to delete.';
            return Response::redirect('/admin/tools');
        }
        $result = $this->backupService->deleteBackups($files);
        if ($result['deleted'] !== []) {
            $_SESSION['_flash_notice'] = 'Deleted ' . count($result['deleted']) . ' backup' . (count($result['deleted']) === 1 ? '' : 's') . '.';
        }
        if ($result['failed'] !== []) {
            $_SESSION['_flash_error'] = count($result['failed']) . ' selected file(s) could not be deleted.';
        }
        return Response::redirect('/admin/tools');
    }

    public function cleanupBackups(Request $request): Response
    {
        $this->validateCsrf($request);

        $policy = $this->backupService->retentionPolicy();
        $result = $this->backupService->applyRetention();
        \FavoriteCMS\Models\Setting::set('backup', 'last_cleanup_at', time(), 'int');
        $_SESSION['_flash_notice'] = count($result['deleted']) === 0
            ? 'Nothing to clean up: the newest ' . $policy['keep_latest'] . ' backups are always kept and the others are not older than ' . $policy['max_age_days'] . ' days.'
            : 'Removed ' . count($result['deleted']) . ' old backup file(s) and freed ' . round($result['freed'] / 1048576, 1) . ' MB.';
        return Response::redirect('/admin/tools');
    }

    public function restoreBackup(Request $request): Response
    {
        $this->validateCsrf($request);
        if ((new \FavoriteCMS\Services\Update\UpdateManager($this->app))->isExecutionRunning()) return Response::make('A software update is running. Wait until it finishes before restoring site data.', 409);

        $file = $_FILES['restore_file'] ?? null;
        if (!$file || empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['_flash_error'] = 'Please select a valid site backup (.zip) archive to restore.';
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

            (new \FavoriteCMS\Services\Update\UpdateManager($this->app))->acknowledgeDatabaseRestore();

            $_SESSION['_flash_notice'] = "Site successfully restored! {$result['tables_restored']} tables restored; {$result['migrated_urls']} URL references migrated." . (!empty($result['warnings']) ? ' ' . implode(' ', $result['warnings']) : '');
        } catch (Throwable $e) {
            $_SESSION['_flash_error'] = "Restore failed: " . $e->getMessage();
        }

        return Response::redirect('/admin/tools');
    }

    public function export(Request $request): Response
    {
        $path=APP_ROOT.'/storage/backups/database-export-'.bin2hex(random_bytes(16)).'.sql';
        try{
            if(function_exists('set_time_limit'))@set_time_limit(300);
            $db=$this->app->make(Database::class);$this->backupService->dumpDatabase($db->getPdo(),$db->prefix(),$path);
            return new \FavoriteCMS\Core\FileDownloadResponse($path,'favorite_cms_database_'.\FavoriteCMS\Core\DateTime::format(time(), 'Y-m-d_His').'.sql','application/sql',true);
        }catch(Throwable $e){if(is_file($path))@unlink($path);$_SESSION['_flash_error']='Database export failed; no incomplete dump was offered. '.mb_substr(strip_tags($e->getMessage()),0,350);return Response::redirect('/admin/tools');}
    }

    public function bloggerImportPreview(Request $request): Response
    {
        $this->validateCsrf($request);

        $xmlContent = '';
        if (!empty($_FILES['blogger_file']['tmp_name']) && is_uploaded_file($_FILES['blogger_file']['tmp_name'])) {
            $xmlContent = (string)file_get_contents($_FILES['blogger_file']['tmp_name']);
        } elseif ($request->post('xml_content')) {
            $xmlContent = (string)$request->post('xml_content');
        }

        if (trim($xmlContent) === '') {
            $_SESSION['_flash_error'] = 'Please choose a valid Blogger XML backup file or provide XML content.';
            return Response::redirect('/admin/tools#blogger-import');
        }

        try {
            $service = new BloggerImportService($this->app);
            if (str_starts_with($xmlContent, "PK\x03\x04")) {
                $xmlContent = $service->extractZipContent($xmlContent);
            }

            $preview = $service->preview($xmlContent);

            if (!$preview['success']) {
                $_SESSION['_flash_error'] = 'Blogger XML Parse Error: ' . ($preview['error'] ?? 'Unknown error.');
                return Response::redirect('/admin/tools#blogger-import');
            }

            // Stash XML in temporary storage for process step
            $tempDir = APP_ROOT . '/storage/cache';
            if (!is_dir($tempDir)) {
                @mkdir($tempDir, 0775, true);
            }
            $importToken = bin2hex(random_bytes(16));
            file_put_contents($tempDir . '/blogger_' . $importToken . '.xml', $xmlContent);

            $_SESSION['blogger_import_token'] = $importToken;
            $_SESSION['blogger_import_preview'] = $preview;
            $_SESSION['_flash_notice'] = "Blogger XML analyzed: {$preview['counts']['posts']} posts, {$preview['counts']['pages']} pages, and {$preview['counts']['comments']} comments found.";
        } catch (Throwable $e) {
            $_SESSION['_flash_error'] = 'Failed to analyze Blogger XML: ' . $e->getMessage();
        }

        return Response::redirect('/admin/tools#blogger-import');
    }

    public function bloggerImportProcess(Request $request): Response
    {
        $this->validateCsrf($request);

        $importToken = (string)$request->post('import_token', $_SESSION['blogger_import_token'] ?? '');
        $tempFile = APP_ROOT . '/storage/cache/blogger_' . basename($importToken) . '.xml';

        $xmlContent = '';
        if ($importToken !== '' && file_exists($tempFile)) {
            $xmlContent = (string)file_get_contents($tempFile);
        } elseif (!empty($_FILES['blogger_file']['tmp_name']) && is_uploaded_file($_FILES['blogger_file']['tmp_name'])) {
            $xmlContent = (string)file_get_contents($_FILES['blogger_file']['tmp_name']);
        }

        if (trim($xmlContent) === '') {
            $_SESSION['_flash_error'] = 'Import session expired or no XML file found. Please upload your Blogger XML file again.';
            return Response::redirect('/admin/tools#blogger-import');
        }

        try {
            $service = new BloggerImportService($this->app);
            $options = [
                'author_id'       => (int)$request->post('author_id', $_SESSION['auth_user_id'] ?? 1),
                'import_posts'    => $request->post('import_posts', '1') === '1',
                'import_pages'    => $request->post('import_pages', '1') === '1',
                'import_comments' => $request->post('import_comments', '1') === '1',
                'default_status'  => (string)$request->post('default_status', 'preserve'),
            ];

            $result = $service->import($xmlContent, $options);

            // Clean up temporary file
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            unset($_SESSION['blogger_import_token'], $_SESSION['blogger_import_preview']);

            if ($result['success']) {
                $c = $result['counts'];
                $_SESSION['_flash_notice'] = "Blogger import complete! Imported {$c['posts']} post(s), {$c['pages']} page(s), {$c['comments']} comment(s), and {$c['tags']} tag(s).";
            } else {
                $_SESSION['_flash_error'] = "Import completed with warnings/errors: " . implode('; ', array_slice($result['errors'], 0, 3));
            }
        } catch (Throwable $e) {
            $_SESSION['_flash_error'] = 'Blogger import failed: ' . $e->getMessage();
        }

        return Response::redirect('/admin/tools#blogger-import');
    }

    public function importIndex(Request $request): Response
    {
        $engine = new ImportEngine($this->app);
        $adapters = $engine->getAdapters();
        $platformRegistry = $engine->getPlatformRegistry();

        $preview = $_SESSION['cms_import_preview'] ?? null;
        $token = $_SESSION['cms_import_token'] ?? '';
        $report = $_SESSION['cms_import_report'] ?? null;

        $notice = $_SESSION['_flash_notice'] ?? null;
        $error = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash_notice'], $_SESSION['_flash_error']);

        $viewData = [
            'pageTitle'        => 'Content Import & Migration',
            'activeMenu'       => 'tools-import',
            'adapters'         => $adapters,
            'platformRegistry' => $platformRegistry,
            'preview'          => $preview,
            'token'            => $token,
            'report'           => $report,
            'notice'           => $notice,
            'error'            => $error,
            'csrfToken'        => $_SESSION['_token'] ?? '',
            'contentView'      => APP_ROOT . '/resources/views/admin/tools/import.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function importPreview(Request $request): Response
    {
        $this->validateCsrf($request);

        $content = '';
        $filename = null;
        $mime = null;

        if (!empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
            $content = (string)file_get_contents($_FILES['import_file']['tmp_name']);
            $filename = (string)$_FILES['import_file']['name'];
            $mime = (string)($_FILES['import_file']['type'] ?? null);
        } elseif ($request->post('content')) {
            $content = (string)$request->post('content');
            $filename = 'content.xml';
        }

        if (trim($content) === '') {
            $_SESSION['_flash_error'] = 'Please choose a valid export file or provide content to analyze.';
            return Response::redirect('/admin/tools/import');
        }

        try {
            $engine = new ImportEngine($this->app);
            $selectedAdapter = (string)$request->post('source_adapter', '');
            $adapterId = $selectedAdapter !== '' ? $selectedAdapter : null;

            if (str_starts_with($content, "PK\x03\x04") || ($filename !== null && str_ends_with(strtolower($filename), '.zip'))) {
                $content = $engine->extractArchiveContent($content, $filename);
            }

            $preview = $engine->preview($content, $adapterId, $filename);

            if (!$preview['success']) {
                $_SESSION['_flash_error'] = 'Import Analysis Error: ' . ($preview['error'] ?? 'Unknown error.');
                return Response::redirect('/admin/tools/import');
            }

            // Stash export file safely in storage/cache
            $tempDir = APP_ROOT . '/storage/cache';
            if (!is_dir($tempDir)) {
                @mkdir($tempDir, 0775, true);
            }
            $importToken = bin2hex(random_bytes(16));
            file_put_contents($tempDir . '/import_' . $importToken . '.dat', $content);

            $_SESSION['cms_import_token'] = $importToken;
            $_SESSION['cms_import_preview'] = $preview;
            $_SESSION['cms_import_adapter'] = $preview['adapter_id'] ?? $adapterId;
            unset($_SESSION['cms_import_report']);

            $c = $preview['counts'];
            $_SESSION['_flash_notice'] = "Analysis ready ({$preview['source_name']}): Found {$c['posts']} posts, {$c['pages']} pages, {$c['comments']} comments, and {$c['media']} media items.";
        } catch (Throwable $e) {
            $_SESSION['_flash_error'] = 'Failed to analyze export file: ' . $e->getMessage();
        }

        return Response::redirect('/admin/tools/import');
    }

    public function importProcess(Request $request): Response
    {
        $this->validateCsrf($request);

        $importToken = (string)$request->post('import_token', $_SESSION['cms_import_token'] ?? '');
        $tempFile = APP_ROOT . '/storage/cache/import_' . basename($importToken) . '.dat';

        $content = '';
        if ($importToken !== '' && file_exists($tempFile)) {
            $content = (string)file_get_contents($tempFile);
        } elseif (!empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
            $content = (string)file_get_contents($_FILES['import_file']['tmp_name']);
        }

        if (trim($content) === '') {
            $_SESSION['_flash_error'] = 'Import session expired or file not found. Please upload your export file again.';
            return Response::redirect('/admin/tools/import');
        }

        try {
            $engine = new ImportEngine($this->app);
            $adapterId = (string)$request->post('adapter_id', $_SESSION['cms_import_adapter'] ?? '');

            $options = [
                'deduplication_mode' => (string)$request->post('deduplication_mode', 'skip'),
                'import_media'       => $request->post('import_media', '1') === '1',
                'author_handling'    => (string)$request->post('author_handling', 'admin'),
                'default_status'     => (string)$request->post('default_status', 'preserve'),
                'import_posts'       => $request->post('import_posts', '1') === '1',
                'import_pages'       => $request->post('import_pages', '1') === '1',
                'import_comments'    => $request->post('import_comments', '1') === '1',
                'author_id'          => (int)($_SESSION['auth_user_id'] ?? 1),
            ];

            $report = $engine->import($content, $options, $adapterId ?: null);

            // Clean up temporary file
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            unset($_SESSION['cms_import_token'], $_SESSION['cms_import_preview'], $_SESSION['cms_import_adapter']);
            $_SESSION['cms_import_report'] = $report;

            $p = $report['posts'];
            $pg = $report['pages'];
            $m = $report['media'];

            $_SESSION['_flash_notice'] = "Migration complete ({$report['source']})! Posts: {$p['imported']} imported, {$p['updated']} updated, {$p['skipped']} skipped. Pages: {$pg['imported']} imported. Media: {$m['downloaded']} downloaded.";
        } catch (Throwable $e) {
            $_SESSION['_flash_error'] = 'Import failed: ' . $e->getMessage();
        }

        return Response::redirect('/admin/tools/import');
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
