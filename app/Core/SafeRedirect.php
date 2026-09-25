<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

/**
 * Validates user-supplied return/redirect targets so they can only point to a
 * local path on this site, never to an external host or another URL scheme.
 */
final class SafeRedirect
{
    private const MAX_LENGTH = 2000;

    /**
     * Authentication endpoints that must never be used as a return target
     * (prevents redirect loops and an immediate logout right after login).
     */
    private const BLOCKED_PATHS = [
        '/login',
        '/logout',
        '/register',
        '/signup',
        '/admin/login',
        '/admin/logout',
        '/admin/register',
    ];

    /**
     * Return the target when it is a safe root-relative local path, otherwise null.
     */
    public static function localPath(mixed $target): ?string
    {
        if (!is_string($target)) {
            return null;
        }

        $target = trim($target);
        if ($target === '' || strlen($target) > self::MAX_LENGTH) {
            return null;
        }

        // Must start with exactly one forward slash: rejects "//host", "/\host", schemes and relative paths.
        if ($target[0] !== '/' || str_starts_with($target, '//') || str_contains($target, '\\')) {
            return null;
        }

        // Raw whitespace and control characters are never valid (header injection, obfuscation).
        if (preg_match('/[\x00-\x20\x7F]/', $target) === 1) {
            return null;
        }

        // Reject percent-encoded variants that decode into the forms rejected above.
        $decoded = $target;
        for ($i = 0; $i < 3; $i++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }
        if (str_starts_with($decoded, '//') || str_contains($decoded, '\\') || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1) {
            return null;
        }

        $parts = parse_url($target);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['port']) || isset($parts['user'])) {
            return null;
        }

        if (in_array(self::normalizedPath($parts['path'] ?? '/'), self::BLOCKED_PATHS, true)) {
            return null;
        }

        return $target;
    }

    /**
     * Normalize a path for endpoint comparison (strips the install base path and trailing slashes).
     */
    private static function normalizedPath(string $path): string
    {
        $basePath = rtrim((string)($GLOBALS['favorite_cms_base_path'] ?? ''), '/');
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
        }

        $path = strtolower(rtrim($path, '/'));
        return $path === '' ? '/' : $path;
    }
}
