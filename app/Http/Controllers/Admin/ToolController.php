<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;

class ToolController
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
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
            'Storage Writable'     => is_writable(APP_ROOT . '/storage') ? 'Yes (0775)' : 'No',
            'Uploads Writable'     => is_writable(APP_ROOT . '/public/uploads') ? 'Yes (0775)' : 'No',
            'cURL Extension'       => extension_loaded('curl') ? 'Enabled' : 'Disabled',
            'mbstring Extension'   => extension_loaded('mbstring') ? 'Enabled' : 'Disabled',
            'JSON Extension'       => extension_loaded('json') ? 'Enabled' : 'Disabled',
        ];

        $viewData = [
            'pageTitle'   => 'Tools & System Status',
            'activeMenu'  => 'tools',
            'diagnostics' => $diagnostics,
            'contentView' => APP_ROOT . '/resources/views/admin/tools/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function export(Request $request): Response
    {
        $db = $this->app->make(Database::class);
        $tables = $db->select('SHOW TABLES');

        $backup = [
            'cms_version' => APP_VERSION,
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
}

