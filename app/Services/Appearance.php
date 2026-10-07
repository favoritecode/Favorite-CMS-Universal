<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

use FavoriteCMS\Models\Setting;

/** One preference across setup, accounts, administration and the public site. */
final class Appearance
{
    public static function resolve(?object $user = null): string
    {
        $theme = $_COOKIE['favorite_admin_theme'] ?? null;
        if (!in_array($theme, ['dark', 'light'], true)) {
            $theme = null;
            if ($user && isset($user->id)) {
                try {
                    $theme = Setting::get('admin_appearance', 'user_' . $user->id, null);
                } catch (\Throwable) {
                    // Setup and recovery screens also work before installation.
                }
            }
            if (!in_array($theme, ['dark', 'light'], true)) {
                $theme = $_SESSION['admin_theme'] ?? 'dark';
            }
        }
        $theme = $theme === 'light' ? 'light' : 'dark';
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['admin_theme'] = $theme;
        }
        return $theme;
    }
}
