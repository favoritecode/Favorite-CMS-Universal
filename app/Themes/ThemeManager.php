<?php

declare(strict_types=1);

namespace FavoriteCMS\Themes;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Exceptions\SecurityException;
use FavoriteCMS\Models\Setting;
use ZipArchive;

class ThemeManager
{
    protected Application $app;
    protected string $themesPath;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->themesPath = APP_ROOT . '/themes';
    }

    /**
     * Get all installed themes with their metadata from theme.json.
     */
    public function getInstalledThemes(): array
    {
        $themes = [];
        if (!is_dir($this->themesPath)) {
            return $themes;
        }

        $dirs = glob($this->themesPath . '/*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            $id = basename($dir);
            $manifestFile = $dir . '/theme.json';
            $meta = [
                'id'          => $id,
                'name'        => ucfirst($id),
                'version'     => '1.0.0',
                'author'      => 'Unknown',
                'description' => '',
                'active'      => ($id === $this->getActiveTheme()),
                'path'        => $dir,
            ];

            if (file_exists($manifestFile)) {
                $json = json_decode((string)file_get_contents($manifestFile), true);
                if (is_array($json)) {
                    $meta = array_merge($meta, $json);
                    $meta['id'] = $id; // always match directory name
                    $meta['active'] = ($id === $this->getActiveTheme());
                }
            }

            $themes[$id] = $meta;
        }

        return $themes;
    }

    protected function entitlement(string $id):array{
        $path=$this->themesPath.'/'.$id.'/theme.json';$manifest=is_file($path)?json_decode((string)file_get_contents($path),true):[];$manifest=is_array($manifest)?$manifest:[];$manifest['id']=$id;
        return (new \FavoriteCMS\Central\Client())->theme($manifest);
    }
    public function getActiveTheme(): string
    {
        try {
            $active = Setting::get('theme', 'active_theme', 'default');
            $activeTheme = is_string($active) && $active !== '' ? $active : 'default';
            if (!is_dir($this->themesPath . '/' . $activeTheme) && is_dir($this->themesPath . '/default')) {
                return 'default';
            }
            if($activeTheme!=='default'&&empty($this->entitlement($activeTheme)['allowed']))return 'default';
            return $activeTheme;
        } catch (\Throwable) {
            return 'default';
        }
    }

    /**
     * Activate a theme with safe validation and rollback.
     */
    public function activateTheme(string $themeId): bool
    {
        if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/',$themeId))throw new \InvalidArgumentException('Invalid theme identity.');
        $targetDir = $this->themesPath . '/' . $themeId;
        if (!is_dir($targetDir)) {
            throw new \InvalidArgumentException("Theme directory does not exist: {$themeId}");
        }

        // Validate required theme files
        if (!file_exists($targetDir . '/index.php')) {
            throw new \RuntimeException("Theme '{$themeId}' is invalid: missing required index.php template.");
        }

        if($themeId!=='default'&&empty($this->entitlement($themeId)['allowed']))throw new \RuntimeException('Theme access requires an active trial or license. Open Licenses & connection.');
        $previous = $this->getActiveTheme();

        try {
            Setting::set('theme', 'active_theme', $themeId);
            // Ensure default layout is seeded for the activated theme
            try {
                (new \FavoriteCMS\Themes\ThemeLayoutService($this->app))->ensureDefaultLayout($themeId);
            } catch (\Throwable) {
                // Ignore layout seeding error during activation
            }
            return true;
        } catch (\Throwable $e) {
            // Rollback to previous theme
            Setting::set('theme', 'active_theme', $previous);
            throw new \RuntimeException("Failed to activate theme '{$themeId}': " . $e->getMessage());
        }
    }

    /**
     * Upload and install a theme ZIP archive safely.
     */
    public function installFromZip(array $uploadedFile): array
    {
        $package = \FavoriteCMS\Services\ExtensionPackageInstaller::install($uploadedFile, 'theme', $this->themesPath);
        return ['theme_id'=>$package['id'], 'success'=>true];
    }

    public function deleteTheme(string $themeId): bool
    {
        if ($themeId === 'default' || $themeId === $this->getActiveTheme()) {
            throw new \InvalidArgumentException("Cannot delete the active or default theme.");
        }

        $dir = $this->themesPath . '/' . $themeId;
        if (!is_dir($dir)) {
            return false;
        }

        $this->deleteRecursive($dir);
        return true;
    }

    protected function deleteRecursive(string $dir): void
    {
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->deleteRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}

