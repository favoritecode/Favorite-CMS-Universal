<?php
/**
 * Plugin Name: Favorite Pay
 * Plugin URI: https://github.com/favoritecode/Favorite-CMS-Universal
 * Description: Authoritative shared payment orchestration, digital wallet, and exchange-rate management plugin for Favorite CMS.
 * Version: 1.0.0
 * Author: Favorite CMS Team
 */

declare(strict_types=1);

namespace FavoriteCMS\Pay;

require_once __DIR__ . '/autoload.php';

// Bootstrap plugin when Favorite CMS boots
if (isset($app) && $app instanceof \FavoriteCMS\Core\Application) {
    FavoritePayPlugin::bootstrap($app);
} elseif (function_exists('app')) {
    $coreApp = app();
    if ($coreApp instanceof \FavoriteCMS\Core\Application) {
        FavoritePayPlugin::bootstrap($coreApp);
    }
}
