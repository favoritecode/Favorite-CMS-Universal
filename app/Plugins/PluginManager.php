<?php

declare(strict_types=1);

namespace FavoriteCMS\Plugins;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Models\Setting;
use ZipArchive;

class PluginManager
{
    protected Application $app;
    protected string $pluginsPath;
    protected array $activePlugins = [];
    protected array $loadedPlugins = [];
    protected array $bootErrors = [];

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->pluginsPath = APP_ROOT . '/plugins';
        $this->loadActivePluginsList();
    }

    protected function loadActivePluginsList(): void
    {
        try {
            $raw = Setting::get('plugins', 'active', '[]');
            $list = is_array($raw) ? $raw : json_decode((string)$raw, true);
            $this->activePlugins = is_array($list) ? array_values(array_unique($list)) : [];
        } catch (\Throwable) {
            $this->activePlugins = [];
        }
    }

    public function getActivePlugins(): array
    {
        return $this->activePlugins;
    }

    public function getInstalledPlugins(): array
    {
        $plugins = [];
        if (!is_dir($this->pluginsPath)) {
            return $plugins;
        }

        $dirs = glob($this->pluginsPath . '/*', GLOB_ONLYDIR);
        if (!$dirs) {
            return $plugins;
        }

        foreach ($dirs as $dir) {
            $id = basename($dir);
            $plugins[$id] = $this->getPluginMetadata($id, $dir);
        }

        return $plugins;
    }

    public function getPluginMetadata(string $id, ?string $dir = null): array
    {
        $dir = $dir ?? ($this->pluginsPath . '/' . $id);
        $manifestFile = $dir . '/plugin.json';

        $meta = [
            'id'           => $id,
            'name'         => ucfirst(str_replace(['-', '_'], ' ', $id)),
            'version'      => '1.0.0',
            'author'       => 'Unknown',
            'description'  => '',
            'requires_php' => '8.1.0',
            'dependencies' => [],
            'tables'       => [],
            'active'       => in_array($id, $this->activePlugins, true),
            'entry_point'  => 'plugin.php',
            'path'         => $dir,
            'valid'        => true,
            'compatible'   => true,
            'errors'       => [],
        ];

        if (file_exists($manifestFile)) {
            $raw = file_get_contents($manifestFile);
            $json = json_decode((string)$raw, true);
            if (is_array($json)) {
                $meta = array_merge($meta, $json);
                $meta['id'] = $id; // always match directory identifier
                $meta['active'] = in_array($id, $this->activePlugins, true);
            } else {
                $meta['valid'] = false;
                $meta['errors'][] = 'Invalid JSON in plugin.json.';
            }
        } else {
            $meta['valid'] = false;
            $meta['errors'][] = 'Missing plugin.json manifest.';
        }

        // Check entry point file exists
        $entryFile = $dir . '/' . ($meta['entry_point'] ?? 'plugin.php');
        if (!file_exists($entryFile)) {
            $meta['valid'] = false;
            $meta['errors'][] = "Entry point file not found: " . ($meta['entry_point'] ?? 'plugin.php');
        }

        // Check PHP version compatibility
        if (!empty($meta['requires_php'])) {
            $minPhp = ltrim((string)$meta['requires_php'], '^>=~ ');
            if (version_compare(PHP_VERSION, $minPhp, '<')) {
                $meta['compatible'] = false;
                $meta['errors'][] = "Requires PHP {$meta['requires_php']}, but server is running PHP " . PHP_VERSION;
            }
        }

        // Check dependencies
        if (!empty($meta['dependencies']) && is_array($meta['dependencies'])) {
            foreach ($meta['dependencies'] as $dep) {
                if (!in_array($dep, $this->activePlugins, true)) {
                    $meta['compatible'] = false;
                    $meta['errors'][] = "Requires active dependency: {$dep}";
                }
            }
        }

        return $meta;
    }

    public function validatePlugin(string $pluginId): array
    {
        $targetDir = $this->pluginsPath . '/' . $pluginId;
        if (!is_dir($targetDir)) {
            return [
                'valid' => false,
                'errors' => ["Plugin directory does not exist: {$pluginId}"]
            ];
        }

        $meta = $this->getPluginMetadata($pluginId, $targetDir);
        return [
            'valid'      => $meta['valid'] && $meta['compatible'],
            'errors'     => $meta['errors'],
            'metadata'   => $meta,
        ];
    }

    public function loadPlugin(string $pluginId): void
    {
        if (isset($this->loadedPlugins[$pluginId])) {
            return;
        }

        $pluginDir = $this->pluginsPath . '/' . $pluginId;
        if (!is_dir($pluginDir)) {
            return;
        }

        $meta = $this->getPluginMetadata($pluginId, $pluginDir);

        // Auto-register prefixable tables declared in plugin manifest
        if (!empty($meta['tables']) && is_array($meta['tables']) && $this->app->has(\FavoriteCMS\Core\Database::class)) {
            $db = $this->app->make(\FavoriteCMS\Core\Database::class);
            $db->registerPrefixableTables($meta['tables']);
        }

        $entryFile = $pluginDir . '/' . ($meta['entry_point'] ?? 'plugin.php');
        if (file_exists($entryFile)) {
            try {
                $app = $this->app;
                require_once $entryFile;
                $this->loadedPlugins[$pluginId] = true;

                $candidateClasses = [
                    'FavoriteCMS\\Pay\\FavoritePayPlugin',
                    'FavoriteCMS\\Plugins\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $pluginId))) . 'Plugin',
                ];
                foreach ($candidateClasses as $class) {
                    if (class_exists($class) && method_exists($class, 'bootstrap')) {
                        $class::bootstrap($this->app);
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // Failure isolation: a broken plugin must not crash the entire site
                $this->bootErrors[$pluginId] = $e->getMessage();
                \FavoriteCMS\Core\Logger::error("Failed to load plugin '{$pluginId}': " . $e->getMessage());
            }
        }
    }

    public function activatePlugin(string $pluginId): bool
    {
        $validation = $this->validatePlugin($pluginId);
        if (!$validation['valid']) {
            $err = implode(' ', $validation['errors']);
            throw new \RuntimeException("Cannot activate plugin '{$pluginId}': {$err}");
        }

        if (in_array($pluginId, $this->activePlugins, true)) {
            return true;
        }

        $pluginDir = $this->pluginsPath . '/' . $pluginId;
        $meta = $this->getPluginMetadata($pluginId, $pluginDir);

        // 1. Auto-register prefixable tables declared in manifest
        if (!empty($meta['tables']) && is_array($meta['tables']) && $this->app->has(\FavoriteCMS\Core\Database::class)) {
            $db = $this->app->make(\FavoriteCMS\Core\Database::class);
            $db->registerPrefixableTables($meta['tables']);
        }

        // 2. Load plugin entry point into memory so its classes, services, and listeners are registered
        $this->loadPlugin($pluginId);

        // 3. Run database migrations if migrations directory exists
        $migrationsDir = $pluginDir . '/database/migrations';
        if (is_dir($migrationsDir) && $this->app->has(\FavoriteCMS\Core\Database::class)) {
            $db = $this->app->make(\FavoriteCMS\Core\Database::class);
            $migrator = new \FavoriteCMS\Core\Migrator($db);
            $migrator->migrate($migrationsDir);
        }

        // 4. Dispatch plugin.activated hook (active listeners now receive it)
        \FavoriteCMS\Core\Hook::doAction('plugin.activated', $pluginId);

        // 5. Persist active state only after successful activation
        $this->activePlugins[] = $pluginId;
        Setting::set('plugins', 'active', json_encode($this->activePlugins), 'json');
        \FavoriteCMS\Core\Logger::info("Plugin activated: {$pluginId}");

        return true;
    }

    public function deactivatePlugin(string $pluginId): bool
    {
        try {
            $this->loadPlugin($pluginId);
        } catch (\Throwable) {
        }

        $this->activePlugins = array_values(array_filter(
            $this->activePlugins,
            fn($id) => $id !== $pluginId
        ));

        Setting::set('plugins', 'active', json_encode($this->activePlugins), 'json');
        \FavoriteCMS\Core\Hook::doAction('plugin.deactivated', $pluginId);
        \FavoriteCMS\Core\Logger::info("Plugin deactivated: {$pluginId}");
        return true;
    }

    public function uninstallPlugin(string $pluginId): bool
    {
        $this->deactivatePlugin($pluginId);

        \FavoriteCMS\Core\Hook::doAction('plugin.uninstalled', $pluginId);
        \FavoriteCMS\Models\PluginSetting::deleteSetting($pluginId);
        \FavoriteCMS\Core\Logger::info("Plugin uninstalled: {$pluginId}");

        $dir = $this->pluginsPath . '/' . $pluginId;
        if (!is_dir($dir)) {
            return false;
        }

        $this->deleteRecursive($dir);
        return true;
    }

    /**
     * Boot all active plugins with failure isolation.
     */
    public function bootActivePlugins(): void
    {
        foreach ($this->activePlugins as $pluginId) {
            try {
                $this->loadPlugin($pluginId);
            } catch (\Throwable $e) {
                // Failure isolation: a broken plugin must not crash the entire site
                $this->bootErrors[$pluginId] = $e->getMessage();
                \FavoriteCMS\Core\Logger::error("Failed to boot plugin '{$pluginId}': " . $e->getMessage());
            }
        }

        \FavoriteCMS\Core\Hook::doAction('plugins.loaded', array_keys($this->loadedPlugins));
    }

    public function getBootErrors(): array
    {
        return $this->bootErrors;
    }

    /**
     * Upload and install a plugin ZIP archive safely with Path Traversal protection.
     */
    public function installFromZip(array $uploadedFile): array
    {
        if (empty($uploadedFile['tmp_name']) || !is_uploaded_file($uploadedFile['tmp_name'])) {
            throw new \InvalidArgumentException("No valid upload file provided.");
        }

        $zip = new ZipArchive();
        $res = $zip->open($uploadedFile['tmp_name']);
        if ($res !== true) {
            throw new \RuntimeException("Could not open ZIP archive (code {$res}).");
        }

        $pluginId = null;
        $hasSubdir = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $filename = $stat['name'];

            // Zip Slip protection
            if (str_contains($filename, '..') || str_starts_with($filename, '/') || str_starts_with($filename, '\\')) {
                $zip->close();
                throw new \RuntimeException("Malicious path detected in ZIP archive: {$filename}");
            }

            // Read plugin ID directly from manifest if available
            if (basename($filename) === 'plugin.json') {
                $rawJson = $zip->getFromIndex($i);
                if ($rawJson) {
                    $parsed = json_decode($rawJson, true);
                    if (is_array($parsed) && !empty($parsed['id'])) {
                        $pluginId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$parsed['id']);
                    }
                }
            }

            $parts = explode('/', trim($filename, '/'));
            if (count($parts) > 1 && !empty($parts[0])) {
                $hasSubdir = true;
            }
        }

        if (empty($pluginId)) {
            $pluginId = 'plugin_' . bin2hex(random_bytes(4));
        }

        $targetDir = $this->pluginsPath . '/' . $pluginId;

        if ($hasSubdir) {
            $zip->extractTo($this->pluginsPath);
        } else {
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0775, true);
            }
            $zip->extractTo($targetDir);
        }
        $zip->close();

        // Validate the newly extracted plugin
        $validation = $this->validatePlugin($pluginId);

        return [
            'plugin_id' => $pluginId,
            'success'   => true,
            'valid'     => $validation['valid'],
            'errors'    => $validation['errors'],
        ];
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
