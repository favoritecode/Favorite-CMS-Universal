<?php
/**
 * Plugin Name: Favorite Digital
 * Plugin URI: https://github.com/favoritecode/Favorite-CMS-Universal
 * Description: Official digital products, downloadable files, services, bundles, and membership access management plugin for Favorite CMS.
 * Version: 1.0.1
 * Author: Favorite CMS Team
 */

declare(strict_types=1);

namespace FavoriteCMS\Digital;

require_once __DIR__ . '/autoload.php';

// Bootstrap plugin when Favorite CMS boots
if (isset($app) && $app instanceof \FavoriteCMS\Core\Application) {
    FavoriteDigitalPlugin::bootstrap($app);
} elseif (function_exists('app')) {
    $coreApp = app();
    if ($coreApp instanceof \FavoriteCMS\Core\Application) {
        FavoriteDigitalPlugin::bootstrap($coreApp);
    }
}

