<?php

declare(strict_types=1);

namespace FavoriteCMS\Themes;

use FavoriteCMS\Core\Application;
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

    public function getActiveTheme(): string
    {
        try {
            $active = Setting::get('theme', 'active_theme', 'default');
            return is_string($active) && $active !== '' ? $active : 'default';
        } catch (\Throwable) {
            return 'default';
        }
    }

    /**
     * Activate a theme with safe validation and rollback.
     */
    public function activateTheme(string $themeId): bool
    {
        $targetDir = $this->themesPath . '/' . $themeId;
        if (!is_dir($targetDir)) {
            throw new \InvalidArgumentException("Theme directory does not exist: {$themeId}");
        }

        // Validate required theme files
        if (!file_exists($targetDir . '/index.php')) {
            throw new \RuntimeException("Theme '{$themeId}' is invalid: missing required index.php template.");
        }

        $previous = $this->getActiveTheme();

        try {
            Setting::set('theme', 'active_theme', $themeId);
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
        if (empty($uploadedFile['tmp_name']) || !is_uploaded_file($uploadedFile['tmp_name'])) {
            throw new \InvalidArgumentException("No valid file uploaded.");
        }

        $zip = new ZipArchive();
        $res = $zip->open($uploadedFile['tmp_name']);
        if ($res !== true) {
            throw new \RuntimeException("Could not open ZIP archive (code {$res}).");
        }

        // Security check: Guard against Path Traversal (Zip Slip vulnerability)
        $themeId = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $filename = $stat['name'];

            if (str_contains($filename, '..') || str_starts_with($filename, '/') || str_starts_with($filename, '\\')) {
                $zip->close();
                throw new \SecurityException("Malicious path detected in ZIP archive: {$filename}");
            }

            // Determine root folder in zip
            $parts = explode('/', trim($filename, '/'));
            if ($themeId === null && !empty($parts[0])) {
                $themeId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $parts[0]);
            }
        }

        if (empty($themeId)) {
            $themeId = 'theme_' . bin2hex(random_bytes(4));
        }

        $extractPath = $this->themesPath . '/' . $themeId;
        if (!is_dir($extractPath)) {
            mkdir($extractPath, 0775, true);
        }

        $zip->extractTo($this->themesPath);
        $zip->close();

        return [
            'theme_id' => $themeId,
            'success'  => true,
        ];
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

