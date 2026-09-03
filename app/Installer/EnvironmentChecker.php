<?php

declare(strict_types=1);

namespace FavoriteCMS\Installer;

use FavoriteCMS\Core\Request;

class EnvironmentChecker
{
    public function __construct(protected UrlResolver $urls)
    {
    }

    public function check(Request $request): array
    {
        $checks = [];
        $checks[] = $this->item('PHP 8.1+', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION);
        $checks[] = $this->item('PDO MySQL extension', extension_loaded('pdo_mysql'), 'Required for MySQL/MariaDB connections.');
        $checks[] = $this->item('OpenSSL extension', extension_loaded('openssl'), 'Used for secure keys and tokens.');
        $checks[] = $this->item('JSON extension', extension_loaded('json'), 'Required by core services.');
        $checks[] = $this->item('Sessions available', session_status() !== PHP_SESSION_DISABLED, 'Required for installer CSRF protection.');
        $checks[] = $this->writable('storage/sessions directory', APP_ROOT . '/storage/sessions');
        $checks[] = $this->writable('storage directory', APP_ROOT . '/storage');
        $checks[] = $this->writable('storage/logs directory', APP_ROOT . '/storage/logs');
        $checks[] = $this->writable('application root for .env', APP_ROOT);
        $checks[] = [
            'label' => 'Detected site URL',
            'status' => 'pass',
            'message' => $this->urls->currentBaseUrl($request),
        ];
        $checks[] = [
            'label' => 'HTTPS',
            'status' => $this->urls->isHttps($request) ? 'pass' : 'warn',
            'message' => $this->urls->isHttps($request) ? 'HTTPS detected.' : 'HTTP detected. This is acceptable locally; use HTTPS in production.',
        ];

        return $checks;
    }

    public function hasFailures(array $checks): bool
    {
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === 'fail') {
                return true;
            }
        }

        return false;
    }

    protected function writable(string $label, string $path): array
    {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        return $this->item($label, is_dir($path) && is_writable($path), $path);
    }

    protected function item(string $label, bool $passed, string $message): array
    {
        return [
            'label' => $label,
            'status' => $passed ? 'pass' : 'fail',
            'message' => $message,
        ];
    }
}
