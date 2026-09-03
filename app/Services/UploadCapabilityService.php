<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;

class UploadCapabilityService
{
    protected ?Application $app;

    public function __construct(?Application $app = null)
    {
        $this->app = $app;
    }

    /**
     * Parse PHP shorthand byte values like '128M', '2G', '512K' into integer bytes.
     */
    public static function parseByteString(string|int|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        $trimmed = trim((string)$value);
        $last = strtolower($trimmed[strlen($trimmed) - 1]);
        $number = (float)substr($trimmed, 0, -1);

        return match ($last) {
            'g' => (int)round($number * 1024 * 1024 * 1024),
            'm' => (int)round($number * 1024 * 1024),
            'k' => (int)round($number * 1024),
            default => (int)round((float)$trimmed),
        };
    }

    /**
     * Format byte values into human-readable strings (e.g. '128.00 MB').
     */
    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int)floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return round($value, $precision) . ' ' . $units[$power];
    }

    /**
     * Return detected PHP environment and server upload limits.
     */
    public function getServerLimits(): array
    {
        $uploadMaxRaw = (string)ini_get('upload_max_filesize');
        $postMaxRaw   = (string)ini_get('post_max_size');
        $memoryRaw    = (string)ini_get('memory_limit');
        $maxExecTime  = (int)ini_get('max_execution_time');
        $maxInputTime = (int)ini_get('max_input_time');

        $uploadMaxBytes = self::parseByteString($uploadMaxRaw);
        $postMaxBytes   = self::parseByteString($postMaxRaw);
        $memoryBytes    = self::parseByteString($memoryRaw);

        // Effective server limit is the bottleneck between upload_max_filesize and post_max_size
        $effectiveServerBytes = $uploadMaxBytes;
        if ($postMaxBytes > 0 && $postMaxBytes < $effectiveServerBytes) {
            $effectiveServerBytes = $postMaxBytes;
        }

        $freeDiskBytes = null;
        $uploadsDir = defined('APP_ROOT') ? APP_ROOT . '/public/uploads' : sys_get_temp_dir();
        if (is_dir($uploadsDir)) {
            $disk = @disk_free_space($uploadsDir);
            if ($disk !== false) {
                $freeDiskBytes = (int)$disk;
            }
        }

        return [
            'upload_max_filesize_raw'   => $uploadMaxRaw,
            'upload_max_filesize_bytes' => $uploadMaxBytes,
            'post_max_size_raw'         => $postMaxRaw,
            'post_max_size_bytes'       => $postMaxBytes,
            'memory_limit_raw'          => $memoryRaw,
            'memory_limit_bytes'        => $memoryBytes,
            'max_execution_time'        => $maxExecTime,
            'max_input_time'            => $maxInputTime,
            'effective_server_bytes'    => $effectiveServerBytes,
            'effective_server_formatted'=> self::formatBytes($effectiveServerBytes),
            'disk_free_bytes'           => $freeDiskBytes,
            'disk_free_formatted'       => $freeDiskBytes !== null ? self::formatBytes($freeDiskBytes) : 'Unknown',
        ];
    }

    /**
     * Get the maximum allowed upload limit for a given user or user ID.
     */
    public function getEffectiveUserLimit(?User $user = null): int
    {
        $serverLimits = $this->getServerLimits();
        $serverMax = $serverLimits['effective_server_bytes'];

        $isAdmin = false;
        $hasLargePermission = false;

        if ($user) {
            $isAdmin = $user->hasRole('super-admin') || $user->hasRole('admin');
            $hasLargePermission = $user->hasPermission('upload_large_media') || $user->hasPermission('manage_settings');
        } elseif (isset($_SESSION['auth_user_id'])) {
            $userModel = User::find((int)$_SESSION['auth_user_id']);
            if ($userModel) {
                $isAdmin = $userModel->hasRole('super-admin') || $userModel->hasRole('admin');
                $hasLargePermission = $userModel->hasPermission('upload_large_media') || $userModel->hasPermission('manage_settings');
            }
        }

        $adminSettingBytes = 0;
        $userSettingBytes  = 52428800; // 50MB default

        try {
            if (\FavoriteCMS\Core\Container::getInstance()->has(\FavoriteCMS\Core\Database::class)) {
                $adminSettingBytes = (int)Setting::get('media', 'max_upload_size_admin', 0);
                $userSettingBytes  = (int)Setting::get('media', 'max_upload_size_user', 52428800);
            }
        } catch (\Throwable $e) {
            // Fallback to defaults if settings table is not available
        }

        // Administrators and users with upload_large_media permission receive the highest server-supported limit
        if ($isAdmin || $hasLargePermission) {
            if ($adminSettingBytes > 0 && $adminSettingBytes < $serverMax) {
                return $adminSettingBytes;
            }
            return $serverMax;
        }

        // Normal users are governed by configurable limit, strictly capped by the server capability
        if ($userSettingBytes <= 0) {
            $userSettingBytes = 52428800;
        }

        return min($userSettingBytes, $serverMax);
    }

    /**
     * Get structured capability report for the current user and UI presentation.
     */
    public function getUserCapabilities(?User $user = null): array
    {
        $serverLimits = $this->getServerLimits();
        $userLimit = $this->getEffectiveUserLimit($user);

        return [
            'server' => $serverLimits,
            'user'   => [
                'max_upload_bytes'     => $userLimit,
                'max_upload_formatted' => self::formatBytes($userLimit),
                'is_server_capped'     => ($userLimit >= $serverLimits['effective_server_bytes']),
            ],
            'allowed_categories' => [
                'images'    => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
                'videos'    => ['mp4', 'webm', 'mkv', 'mov', 'avi', 'ogv'],
                'audio'     => ['mp3', 'wav', 'ogg', 'm4a'],
                'documents' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv'],
                'archives'  => ['zip', 'tar', 'gz'],
            ],
        ];
    }
}
