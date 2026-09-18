<?php

declare(strict_types=1);

namespace FavoriteCMS\Installer;

use FavoriteCMS\Core\Database;

class InstallationStateManager
{
    public function lockPath(): string
    {
        return APP_ROOT . '/storage/installed.lock';
    }

    public function hasLock(): bool
    {
        return is_file($this->lockPath());
    }

    public function writeLock(string $siteUrl, string $adminUsername): void
    {
        $dir = dirname($this->lockPath());
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the storage directory for the installation lock.');
        }

        $content = sprintf(
            "installed_at=%s\ninstalled_by=%s\napp_url=%s\nversion=%s\n",
            date('c'),
            $adminUsername,
            $siteUrl,
            defined('APP_VERSION') ? APP_VERSION : 'unknown'
        );

        $tmp = $this->lockPath() . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false || !rename($tmp, $this->lockPath())) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write the persistent installation lock.');
        }
    }

    public function databaseLooksInstalled(Database $db): bool
    {
        try {
            if (!$db->tableExists('settings') || !$db->tableExists('users')) {
                return false;
            }

            $admin = $db->selectOne("SELECT id FROM `users` WHERE `status` = 'active' LIMIT 1");
            $setting = $db->selectOne("SELECT id FROM `settings` WHERE `group_name` = 'general' AND `setting_key` = 'site_name' LIMIT 1");

            return $admin !== null && $setting !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function databaseLooksPartial(Database $db): bool
    {
        foreach (['cms_migrations', 'users', 'settings', 'roles'] as $table) {
            if ($db->tableExists($table)) {
                return true;
            }
        }

        return false;
    }
}
