<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Update;

use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;

class MaintenanceMode
{
    protected string $appRoot;
    protected string $lockFile;

    public function __construct(?string $appRoot = null)
    {
        $this->appRoot = $appRoot ?: (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3));
        $this->lockFile = $this->appRoot . '/storage/maintenance.lock';
    }

    public function lockPath(): string
    {
        return $this->lockFile;
    }

    public function isActive(): bool
    {
        return is_file($this->lockFile);
    }

    /**
     * Enable maintenance mode atomically.
     */
    public function enable(array $metadata = []): bool
    {
        $dir = dirname($this->lockFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $payload = array_merge([
            'enabled_at'     => date('c'),
            'initiated_by'   => $_SESSION['auth_user_name'] ?? 'system',
            'token'          => bin2hex(random_bytes(16)),
            'target_version' => '',
            'phase'          => 'MAINTENANCE_ENABLED',
        ], $metadata);

        $tmp = $this->lockFile . '.tmp.' . bin2hex(random_bytes(4));
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $this->lockFile)) {
            @unlink($tmp);
            return false;
        }

        // Store bypass token in session for current admin user
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_maintenance_bypass_token'] = $payload['token'];
        }

        return true;
    }

    /**
     * Disable maintenance mode.
     */
    public function disable(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['_maintenance_bypass_token']);
        }

        if (is_file($this->lockFile)) {
            return @unlink($this->lockFile);
        }

        return true;
    }

    /**
     * Get maintenance metadata.
     */
    public function getMetadata(): ?array
    {
        if (!is_file($this->lockFile)) {
            return null;
        }

        $content = @file_get_contents($this->lockFile);
        if ($content === false || $content === '') {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Determine if current request should bypass maintenance mode.
     */
    public function isBypassed(Request $request): bool
    {
        // 1. Check session bypass token
        $meta = $this->getMetadata();
        $expectedToken = $meta['token'] ?? null;

        if ($expectedToken && !empty($_SESSION['_maintenance_bypass_token'])) {
            if (hash_equals((string)$expectedToken, (string)$_SESSION['_maintenance_bypass_token'])) {
                return true;
            }
        }

        // 2. Check query parameter token (for emergency admin bypass)
        $queryToken = $request->get('bypass_token');
        if ($expectedToken && $queryToken && is_string($queryToken)) {
            if (hash_equals((string)$expectedToken, $queryToken)) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['_maintenance_bypass_token'] = $queryToken;
                }
                return true;
            }
        }

        // 3. Authenticated admins updating or managing the system
        if (!empty($_SESSION['auth_user_id']) && !empty($_SESSION['auth_user_role'])) {
            if (in_array($_SESSION['auth_user_role'], ['administrator', 'super_admin'], true)) {
                $path = $request->path();
                // Allow admin updates routes and tools
                if (str_starts_with($path, '/admin/updates') || str_starts_with($path, '/admin/tools')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Render a lightweight 503 response.
     */
    public function renderResponse(Request $request): Response
    {
        $meta = $this->getMetadata();
        $targetVersion = !empty($meta['target_version']) ? ' to version ' . htmlspecialchars((string)$meta['target_version']) : '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance in Progress — Favorite CMS</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .container {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            max-width: 520px;
            width: 100%;
            padding: 36px 32px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            text-align: center;
        }
        .icon {
            font-size: 48px;
            margin-bottom: 16px;
            line-height: 1;
        }
        h1 {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 12px;
        }
        p {
            font-size: 14px;
            line-height: 1.6;
            color: #475569;
            margin-bottom: 20px;
        }
        .progress-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f1f5f9;
            padding: 8px 16px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
            color: #0284c7;
        }
        .spinner {
            width: 12px;
            height: 12px;
            border: 2px solid #bae6fd;
            border-top-color: #0284c7;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .footer {
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid #f1f5f9;
            font-size: 12px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🔄</div>
        <h1>System Update in Progress</h1>
        <p>This website is currently undergoing a scheduled system update{$targetVersion}. User data, posts, and configurations are being preserved safely.</p>
        <div class="progress-indicator">
            <div class="spinner"></div>
            <span>Please check back in a few moments</span>
        </div>
        <div class="footer">
            Favorite CMS Universal &bull; HTTP 503 Service Unavailable
        </div>
    </div>
</body>
</html>
HTML;

        $response = Response::make($html, 503);
        $response->header('Retry-After', '300');
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        return $response;
    }
}

