<?php

declare(strict_types=1);

namespace FavoriteCMS\Installer;

use FavoriteCMS\Core\Request;

class InstallerSession
{
    public function __construct(protected UrlResolver $urls)
    {
    }

    public function start(Request $request): void
    {
        if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
            return;
        }

        $basePath = $this->urls->basePath($request);
        $savePath = APP_ROOT . '/storage/sessions';
        if (!is_dir($savePath)) {
            @mkdir($savePath, 0775, true);
        }
        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }

        session_name('fcms_' . substr(sha1(APP_ROOT), 0, 16));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $basePath === '' ? '/' : $basePath . '/',
            'secure' => $this->urls->isHttps($request),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
