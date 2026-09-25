<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

use FavoriteCMS\Installer\UrlResolver;
use FavoriteCMS\Models\Setting;

class Url
{
    /**
     * Resolve the authoritative canonical base URL for the application.
     * Precedence:
     * 1. Setting::get('general', 'site_url')
     * 2. Explicit config('app.url') (when valid and not default localhost)
     * 3. Request-detected currentBaseUrl() via UrlResolver
     * 4. Fallback to config('app.url') or 'http://localhost'
     */
    public static function base(?Request $request = null): string
    {
        $resolver = new UrlResolver();

        // 1. Authoritative setting in database
        try {
            $settingUrl = (string)Setting::get('general', 'site_url', '');
            if ($settingUrl !== '') {
                $normalized = $resolver->normalizeSiteUrl($settingUrl);
                if ($normalized !== null) {
                    return rtrim($normalized, '/');
                }
            }
        } catch (\Throwable) {
            // Database not yet initialized or table missing
        }

        // 2. Explicit config('app.url') when set and not default localhost
        $configured = (string)config('app.url', '');
        if ($configured !== '' && strtolower(rtrim($configured, '/')) !== 'http://localhost') {
            $normalized = $resolver->normalizeSiteUrl($configured);
            if ($normalized !== null) {
                return rtrim($normalized, '/');
            }
        }

        // 3. Dynamic detection from current HTTP request
        $req = $request ?? (isset($GLOBALS['request']) && $GLOBALS['request'] instanceof Request ? $GLOBALS['request'] : Request::capture());
        $server = $req->server();
        if (!empty($server['HTTP_HOST']) || !empty($server['SERVER_NAME'])) {
            $current = $resolver->currentBaseUrl($req);
            return rtrim($current, '/');
        }

        // 4. Safe fallback
        return rtrim($configured !== '' ? $configured : 'http://localhost', '/');
    }

    /**
     * Generate a canonical absolute URL to a given application path.
     */
    public static function to(string $path = '', ?Request $request = null): string
    {
        $base = static::base($request);
        if ($path === '' || $path === '/') {
            return $base;
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

