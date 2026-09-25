<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;

class UploadCapabilityService
{
    // Exact CMS configured limits
    public const DEFAULT_ADMIN_LIMIT_BYTES     = 7516192768; // 7 GB (7168 MB)
    public const DEFAULT_MODERATOR_LIMIT_BYTES = 524288000;  // 500 MB
    public const DEFAULT_USER_LIMIT_BYTES      = 209715200;  // 200 MB

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
     * Format byte values into human-readable strings (e.g. '7.00 GB', '500.00 MB').
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
        if ($postMaxBytes > 0 && ($effectiveServerBytes === 0 || $postMaxBytes < $effectiveServerBytes)) {
            $effectiveServerBytes = $postMaxBytes;
        }
        if ($effectiveServerBytes <= 0 && $uploadMaxBytes > 0) {
            $effectiveServerBytes = $uploadMaxBytes;
        }

        $freeDiskBytes = null;
        $uploadsDir = defined('APP_ROOT') ? APP_ROOT . '/public/uploads' : sys_get_temp_dir();
        if (is_dir($uploadsDir)) {
            $disk = @disk_free_space($uploadsDir);
            if ($disk !== false) {
                $freeDiskBytes = (int)$disk;
            }
        }

        // Available disk space bottleneck if lower than PHP limit
        if ($freeDiskBytes !== null && $freeDiskBytes > 0 && $effectiveServerBytes > 0 && $freeDiskBytes < $effectiveServerBytes) {
            $effectiveServerBytes = $freeDiskBytes;
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
     * Determine role category ('admin', 'moderator', or 'user') for a given user.
     */
    public function getUserRoleCategory(?User $user = null): string
    {
        $userModel = $user;
        if (!$userModel && isset($_SESSION['auth_user_id'])) {
            $userModel = User::find((int)$_SESSION['auth_user_id']);
        }

        if (!$userModel) {
            return 'user';
        }

        // 1. Admin roles & permissions
        if (
            $userModel->hasRole('super-admin') ||
            $userModel->hasRole('admin') ||
            $userModel->hasPermission('upload_large_media') ||
            $userModel->hasPermission('manage_settings')
        ) {
            return 'admin';
        }

        // 2. Moderator / Editor roles & permissions
        if (
            $userModel->hasRole('moderator') ||
            $userModel->hasRole('editor') ||
            $userModel->hasPermission('upload_moderator_media') ||
            $userModel->hasPermission('moderate_comments') ||
            $userModel->hasPermission('publish_posts')
        ) {
            return 'moderator';
        }

        // 3. Standard user
        return 'user';
    }

    /**
     * Get the CMS-configured role maximum upload limit in bytes (independent of server cap).
     * Defaults: Admin = 7 GB, Moderator = 500 MB, User = 200 MB.
     */
    public function getConfiguredUserLimit(?User $user = null): int
    {
        $role = $this->getUserRoleCategory($user);

        $adminBytes     = self::DEFAULT_ADMIN_LIMIT_BYTES;
        $moderatorBytes = self::DEFAULT_MODERATOR_LIMIT_BYTES;
        $userBytes      = self::DEFAULT_USER_LIMIT_BYTES;

        try {
            if (\FavoriteCMS\Core\Container::getInstance()->has(\FavoriteCMS\Core\Database::class)) {
                $adminSetting = (int)Setting::get('media', 'max_upload_size_admin', self::DEFAULT_ADMIN_LIMIT_BYTES);
                $modSetting   = (int)Setting::get('media', 'max_upload_size_moderator', self::DEFAULT_MODERATOR_LIMIT_BYTES);
                $userSetting  = (int)Setting::get('media', 'max_upload_size_user', self::DEFAULT_USER_LIMIT_BYTES);

                if ($adminSetting > 0) {
                    $adminBytes = $adminSetting;
                }
                if ($modSetting > 0) {
                    $moderatorBytes = $modSetting;
                }
                if ($userSetting > 0) {
                    $userBytes = $userSetting;
                }
            }
        } catch (\Throwable) {
            // Fallback to constants
        }

        return match ($role) {
            'admin'     => $adminBytes,
            'moderator' => $moderatorBytes,
            default     => $userBytes,
        };
    }

    /**
     * Get the effective upload limit: lower of CMS role limit vs actual server-supported limit.
     */
    public function getEffectiveUserLimit(?User $user = null): int
    {
        $serverLimits = $this->getServerLimits();
        $serverMax = $serverLimits['effective_server_bytes'];
        $configuredLimit = $this->getConfiguredUserLimit($user);

        if ($serverMax > 0) {
            return min($configuredLimit, $serverMax);
        }

        return $configuredLimit;
    }

    /**
     * Get structured capability report for the user and UI presentation.
     */
    public function getUserCapabilities(?User $user = null): array
    {
        $serverLimits    = $this->getServerLimits();
        $roleCategory    = $this->getUserRoleCategory($user);
        $configuredLimit = $this->getConfiguredUserLimit($user);
        $effectiveLimit  = $this->getEffectiveUserLimit($user);
        $serverCapBytes  = $serverLimits['effective_server_bytes'];

        $isServerCapped = ($serverCapBytes > 0 && $configuredLimit > $serverCapBytes);

        return [
            'server' => $serverLimits,
            'user'   => [
                'role_category'              => $roleCategory,
                'configured_limit_bytes'     => $configuredLimit,
                'configured_limit_formatted' => self::formatBytes($configuredLimit),
                'max_upload_bytes'           => $effectiveLimit,
                'max_upload_formatted'       => self::formatBytes($effectiveLimit),
                'is_server_capped'           => $isServerCapped,
                'server_capped_reason'       => $isServerCapped
                    ? "Your configured role allowance is " . self::formatBytes($configuredLimit) . ", but actual uploads are constrained to " . self::formatBytes($serverCapBytes) . " by the hosting server (upload_max_filesize / post_max_size)."
                    : null,
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
