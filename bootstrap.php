<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0.0');
}
if (!defined('CMS_NAME')) {
    define('CMS_NAME', 'Favorite CMS');
}

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

require APP_ROOT . '/vendor/autoload.php';

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;

$app = new Application();

// Load environment variables
if (file_exists(APP_ROOT . '/.env')) {
    $lines = file(APP_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\"'");
        putenv(sprintf('%s=%s', $name, $value));
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

if (env('APP_DEBUG', false)) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

// Bind core services
$app->singleton(Config::class, function () {
    return new Config();
});

$app->singleton(Database::class, function ($app) {
    $config = $app->get(Config::class);
    $dbConfig = $config->get('database');
    return new Database($dbConfig);
});

return $app;

