<?php

declare(strict_types=1);

/**
 * Favorite Digital PSR-4 Autoloader
 *
 * Automatically maps FavoriteCMS\Digital\ namespace to plugins/favorite-digital/src/
 * and registers core plugin class alias.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'FavoriteCMS\\Digital\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Register class alias for Core PluginManager candidate lookup
if (!class_exists('FavoriteCMS\\Plugins\\FavoriteDigitalPlugin', false)) {
    class_alias(\FavoriteCMS\Digital\FavoriteDigitalPlugin::class, 'FavoriteCMS\\Plugins\\FavoriteDigitalPlugin');
}

