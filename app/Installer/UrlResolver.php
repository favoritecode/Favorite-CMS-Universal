<?php

declare(strict_types=1);

namespace FavoriteCMS\Installer;

use FavoriteCMS\Core\Request;

class UrlResolver
{
    public function basePath(Request $request): string
    {
        $server = $request->server();
        $requestPath = $this->normalizePath(parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $scriptName = $this->normalizePath($server['SCRIPT_NAME'] ?? '/index.php');
        $scriptDir = $this->normalizePath(str_replace('\\', '/', dirname($scriptName)));
        $publicless = $scriptDir;

        if ($publicless === '/public') {
            $publicless = '';
        } elseif (str_ends_with($publicless, '/public')) {
            $publicless = substr($publicless, 0, -7);
        }

        $candidates = array_values(array_unique([$scriptDir, $publicless, '']));
        usort($candidates, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                return '';
            }
            if ($requestPath === $candidate || str_starts_with($requestPath . '/', rtrim($candidate, '/') . '/')) {
                return rtrim($candidate, '/');
            }
        }

        return '';
    }

    public function currentBaseUrl(Request $request): string
    {
        $server = $request->server();
        $scheme = $this->isHttps($request) ? 'https' : 'http';
        $host = $this->validatedHost((string)($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost'));
        $path = $this->basePath($request);

        return rtrim($scheme . '://' . $host . $path, '/') . '/';
    }

    public function route(Request $request, string $path): string
    {
        $base = $this->basePath($request);
        return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
    }

    public function isHttps(Request $request): bool
    {
        $server = $request->server();

        if (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off') {
            return true;
        }

        if (($server['SERVER_PORT'] ?? null) === '443') {
            return true;
        }

        if (($server['REQUEST_SCHEME'] ?? '') === 'https') {
            return true;
        }

        if (!$this->trustedProxyHeadersEnabled($server)) {
            return false;
        }

        $proto = strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $ssl = strtolower((string)($server['HTTP_X_FORWARDED_SSL'] ?? ''));

        return $proto === 'https' || str_starts_with($proto, 'https,') || $ssl === 'on';
    }

    public function normalizeSiteUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || !$this->isValidHost($host)) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = $this->normalizePath($parts['path'] ?? '/');
        if (str_contains($path, '..')) {
            return null;
        }

        return rtrim($scheme . '://' . strtolower($host) . $port . $path, '/') . '/';
    }

    protected function trustedProxyHeadersEnabled(array $server): bool
    {
        if (filter_var($_ENV['TRUST_PROXY_HEADERS'] ?? $_SERVER['TRUST_PROXY_HEADERS'] ?? false, FILTER_VALIDATE_BOOL)) {
            return true;
        }

        $remote = (string)($server['REMOTE_ADDR'] ?? '');
        return $remote === '127.0.0.1'
            || $remote === '::1'
            || str_starts_with($remote, '10.')
            || str_starts_with($remote, '192.168.')
            || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $remote) === 1;
    }

    protected function validatedHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_contains($host, '@')) {
            return 'localhost';
        }

        $withoutPort = $host;
        if (str_starts_with($withoutPort, '[')) {
            return preg_match('/^\[[0-9a-f:]+\](?::\d{1,5})?$/i', $withoutPort) === 1 ? $withoutPort : 'localhost';
        }

        if (str_contains($withoutPort, ':')) {
            [$withoutPort, $port] = explode(':', $withoutPort, 2);
            if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
                return 'localhost';
            }
        }

        return $this->isValidHost($withoutPort) ? $host : 'localhost';
    }

    protected function isValidHost(string $host): bool
    {
        return $host !== ''
            && strlen($host) <= 253
            && preg_match('/^(localhost|[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*|\d{1,3}(?:\.\d{1,3}){3})$/i', $host) === 1;
    }

    protected function normalizePath(string $path): string
    {
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';
        return $path === '/' ? '' : rtrim($path, '/');
    }
}
