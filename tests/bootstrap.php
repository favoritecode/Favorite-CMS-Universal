<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('PHPUNIT_RUNNING', true);

// Tests need writable session storage independent of the host PHP configuration.
$testSessionDirectory = sys_get_temp_dir() . '/favorite_cms_phpunit_sessions_' . bin2hex(random_bytes(8));
if (!mkdir($testSessionDirectory, 0700)) {
    throw new RuntimeException('Could not create PHPUnit session storage.');
}
session_save_path($testSessionDirectory);
register_shutdown_function(static function () use ($testSessionDirectory): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    foreach (glob($testSessionDirectory . '/sess_*') ?: [] as $sessionFile) {
        unlink($sessionFile);
    }
    rmdir($testSessionDirectory);
});

// Simulate web environment for tests
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['HTTP_HOST']      = 'favorite-cms.local';

require APP_ROOT . '/vendor/autoload.php';

function test_plugin_directory(string $id): string
{
    foreach ([APP_ROOT . '/plugins', dirname(APP_ROOT) . '/Favorite-CMS-Assets/plugins'] as $base) {
        if (is_dir($base . '/' . $id)) {
            return $base . '/' . $id;
        }
    }
    throw new RuntimeException('Required integration-test plugin is unavailable.');
}
// Dynamic plugin autoloading for testing
$pluginScanPaths = [
    APP_ROOT . '/plugins',
    dirname(APP_ROOT) . '/Favorite-CMS-Assets/plugins',
];
foreach ($pluginScanPaths as $pScanPath) {
    if (is_dir($pScanPath)) {
        foreach (glob($pScanPath . '/*/autoload.php') as $pAutoload) {
            require_once $pAutoload;
        }
    }
}

// Load .env
if (file_exists(APP_ROOT . '/.env')) {
    $lines = file(APP_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
        $name  = trim($name);
        $value = trim($value, " \t\"'");
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv("$name=$value");
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
        }
    }
}

