<?php

declare(strict_types=1);

/**
 * Favorite Pay PSR-4 Autoloader
 *
 * Automatically maps FavoriteCMS\Pay\ namespace to plugins/favorite-pay/src/
 * ensuring modular, zero-configuration loading on standalone hosts.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'FavoriteCMS\\Pay\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
