<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Exceptions\SecurityException;
use PDO;
use RuntimeException;
use ZipArchive;

class RestoreService
{
    protected string $appRoot;
    protected string $tempDir;

    public function __construct(?string $appRoot = null)
    {
        $this->appRoot = $appRoot ?: (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2));
        $this->tempDir = $this->appRoot . '/storage/temp';
        $this->ensureTempDirectory();
    }

    protected function ensureTempDirectory(): void
    {
        if (!is_dir($this->tempDir)) {
            @mkdir($this->tempDir, 0775, true);
        }
    }

    /**
     * Inspect a backup ZIP archive, validating manifest, integrity, and checking for path traversal.
     */
    public function inspectBackup(string $zipPath): array
    {
        if (!file_exists($zipPath)) {
            throw new RuntimeException("Backup file not found at: {$zipPath}");
        }

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP Zip extension is required to inspect backup archives.');
        }

        $zip = new ZipArchive();
        $res = $zip->open($zipPath);
        if ($res !== true) {
            throw new RuntimeException("Could not open backup ZIP archive (code: {$res})");
        }

        try {
            // 1. Path Traversal & Zip-Slip Protection Check
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'] ?? '';

                if (str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '\\') || preg_match('/^[a-zA-Z]:/', $name)) {
                    throw new SecurityException("Potential path traversal (Zip-Slip) attack detected in archive entry: {$name}");
                }
            }

            // 2. Locate and parse manifest.json
            $manifestJson = $zip->getFromName('manifest.json');
            if ($manifestJson === false) {
                throw new RuntimeException('Invalid backup archive: manifest.json is missing.');
            }

            $manifest = json_decode($manifestJson, true);
            if (!is_array($manifest) || empty($manifest['cms_version'])) {
                throw new RuntimeException('Invalid or corrupted manifest.json in backup archive.');
            }

            // 3. Verify database.sql exists
            $hasSql = ($zip->locateName('database.sql') !== false);
            if (!$hasSql) {
                throw new RuntimeException('Invalid backup archive: database.sql dump is missing.');
            }

            $size = filesize($zipPath);

            return [
                'valid'             => true,
                'cms_version'       => $manifest['cms_version'] ?? 'Unknown',
                'schema_version'    => $manifest['schema_version'] ?? 0,
                'created_at'        => $manifest['created_at'] ?? 'Unknown',
                'site_name'         => $manifest['site_name'] ?? 'Favorite CMS',
                'original_site_url' => $manifest['site_url'] ?? '',
                'table_prefix'      => $manifest['table_prefix'] ?? 'fvcms_',
                'tables'            => $manifest['tables'] ?? [],
                'total_rows'        => $manifest['total_rows'] ?? 0,
                'file_count'        => $manifest['file_count'] ?? 0,
                'size'              => $size,
                'manifest'          => $manifest,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Restore a backup archive into the target database and file system.
     *
     * @param string $zipPath Full path to the backup ZIP
     * @param array $targetDbConfig Target database credentials and prefix
     * @param string|null $newSiteUrl Target site URL (for domain migration)
     * @param bool $confirmOverwrite Explicit user consent to overwrite existing database tables
     * @return array
     */
    public function restoreBackup(
        string $zipPath,
        array $targetDbConfig,
        ?string $newSiteUrl = null,
        bool $confirmOverwrite = true
    ): array {
        $inspection = $this->inspectBackup($zipPath);
        $manifest = $inspection['manifest'];
        $oldPrefix = $manifest['table_prefix'] ?? 'fvcms_';
        $targetPrefix = (string)($targetDbConfig['prefix'] ?? $oldPrefix);

        // 1. Connect to target database
        $targetDb = new Database($targetDbConfig);
        $pdo = $targetDb->getPdo();

        // 2. Check for existing tables with target prefix
        $existingStmt = $pdo->query("SHOW TABLES LIKE '{$targetPrefix}%'");
        $existingTables = $existingStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($existingTables) && !$confirmOverwrite) {
            return [
                'success'         => false,
                'existing_tables' => $existingTables,
                'warning'         => 'The target database already contains ' . count($existingTables) . ' tables with prefix "' . $targetPrefix . '". Confirmation is required to overwrite.',
                'requires_confirmation' => true,
            ];
        }

        // 3. Staging extraction
        $stageDir = $this->tempDir . '/restore_stage_' . bin2hex(random_bytes(4));
        if (!mkdir($stageDir, 0775, true) && !is_dir($stageDir)) {
            throw new RuntimeException("Could not create restore staging directory: {$stageDir}");
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Could not open backup archive for extraction: {$zipPath}");
        }

        try {
            // Extract database.sql to staging
            $zip->extractTo($stageDir, ['database.sql', 'manifest.json']);
            $sqlFile = $stageDir . '/database.sql';

            if (!file_exists($sqlFile)) {
                throw new RuntimeException('Extracted database.sql file not found.');
            }

            // 4. Restore database SQL
            $this->executeSqlDump($pdo, $sqlFile, $oldPrefix, $targetPrefix);

            // 5. Format-Aware Domain & URL Migration
            $migratedUrlsCount = 0;
            $oldSiteUrl = rtrim((string)($manifest['site_url'] ?? ''), '/');
            $newSiteUrl = $newSiteUrl ? rtrim($newSiteUrl, '/') : null;

            if ($newSiteUrl && $oldSiteUrl !== '' && $newSiteUrl !== $oldSiteUrl) {
                $migratedUrlsCount = $this->migrateUrls($targetDb, $oldSiteUrl, $newSiteUrl, $targetPrefix);
            }

            // 6. Restore media files to public/uploads
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);

                // Extract public/uploads/
                if (str_starts_with($entryName, 'public/uploads/')) {
                    $rel = substr($entryName, strlen('public/uploads/'));
                    if ($rel !== '' && !str_ends_with($rel, '/')) {
                        $destPath = $this->appRoot . '/public/uploads/' . $rel;
                        $destDir = dirname($destPath);
                        if (!is_dir($destDir)) {
                            @mkdir($destDir, 0775, true);
                        }
                        copy("zip://{$zipPath}#{$entryName}", $destPath);
                    }
                }

                // Extract themes/ (custom user themes)
                if (str_starts_with($entryName, 'themes/')) {
                    $rel = substr($entryName, strlen('themes/'));
                    if ($rel !== '' && !str_ends_with($rel, '/')) {
                        $destPath = $this->appRoot . '/themes/' . $rel;
                        $destDir = dirname($destPath);
                        if (!is_dir($destDir)) {
                            @mkdir($destDir, 0775, true);
                        }
                        copy("zip://{$zipPath}#{$entryName}", $destPath);
                    }
                }

                // Extract plugins/ (custom user plugins)
                if (str_starts_with($entryName, 'plugins/')) {
                    $rel = substr($entryName, strlen('plugins/'));
                    if ($rel !== '' && !str_ends_with($rel, '/')) {
                        $destPath = $this->appRoot . '/plugins/' . $rel;
                        $destDir = dirname($destPath);
                        if (!is_dir($destDir)) {
                            @mkdir($destDir, 0775, true);
                        }
                        copy("zip://{$zipPath}#{$entryName}", $destPath);
                    }
                }
            }

            // Ensure uploads directory is secured
            $this->secureUploadsDirectory();

            // 7. Clear application runtime cache
            $this->clearCache();

            return [
                'success'           => true,
                'message'           => 'Site successfully restored from backup.',
                'tables_restored'   => count($manifest['tables'] ?? []),
                'migrated_urls'     => $migratedUrlsCount,
                'original_site_url' => $oldSiteUrl,
                'new_site_url'      => $newSiteUrl ?: $oldSiteUrl,
            ];
        } finally {
            $zip->close();
            $this->removeDirectory($stageDir);
        }
    }

    /**
     * Execute the SQL dump against the target PDO instance, adjusting table prefixes if needed.
     */
    protected function executeSqlDump(PDO $pdo, string $sqlFile, string $oldPrefix, string $targetPrefix): void
    {
        $handle = fopen($sqlFile, 'r');
        if (!$handle) {
            throw new RuntimeException("Could not open SQL dump file for execution: {$sqlFile}");
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0;');
        $pdo->exec('SET NAMES utf8mb4;');

        $currentQuery = '';
        $prefixDiffers = ($oldPrefix !== $targetPrefix && $oldPrefix !== '');

        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            if ($prefixDiffers) {
                // Safely translate backtick table names
                $line = str_replace('`' . $oldPrefix, '`' . $targetPrefix, $line);
            }

            $currentQuery .= $line;

            if (str_ends_with($trimmed, ';')) {
                $pdo->exec($currentQuery);
                $currentQuery = '';
            }
        }

        if (trim($currentQuery) !== '') {
            $pdo->exec($currentQuery);
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=1;');
        fclose($handle);
    }

    /**
     * Format-aware URL and domain migration across database tables.
     * Updates settings, post content, excerpts, and pages without breaking serialized strings.
     */
    public function migrateUrls(Database $db, string $oldUrl, string $newUrl, string $prefix): int
    {
        $oldUrl = rtrim($oldUrl, '/');
        $newUrl = rtrim($newUrl, '/');

        if ($oldUrl === '' || $newUrl === '' || $oldUrl === $newUrl) {
            return 0;
        }

        $count = 0;
        $pdo = $db->getPdo();

        // 1. Update settings table
        $settingsTable = $prefix . 'settings';
        $stmt = $pdo->prepare("UPDATE `{$settingsTable}` SET `value` = REPLACE(`value`, ?, ?) WHERE `value` LIKE ?");
        $stmt->execute([$oldUrl, $newUrl, '%' . $oldUrl . '%']);
        $count += $stmt->rowCount();

        // Explicitly update site_url setting
        $stmt = $pdo->prepare("UPDATE `{$settingsTable}` SET `value` = ? WHERE `setting_key` = 'site_url'");
        $stmt->execute([$newUrl]);

        // 2. Update posts table (content and excerpt)
        $postsTable = $prefix . 'posts';
        $stmt = $pdo->prepare("UPDATE `{$postsTable}` SET 
            `content` = REPLACE(`content`, ?, ?),
            `excerpt` = REPLACE(`excerpt`, ?, ?)
            WHERE `content` LIKE ? OR `excerpt` LIKE ?");
        $stmt->execute([
            $oldUrl,
            $newUrl,
            $oldUrl,
            $newUrl,
            '%' . $oldUrl . '%',
            '%' . $oldUrl . '%',
        ]);
        $count += $stmt->rowCount();

        // 3. Update pages table (content)
        $pagesTable = $prefix . 'pages';
        $stmt = $pdo->prepare("UPDATE `{$pagesTable}` SET 
            `content` = REPLACE(`content`, ?, ?)
            WHERE `content` LIKE ?");
        $stmt->execute([$oldUrl, $newUrl, '%' . $oldUrl . '%']);
        $count += $stmt->rowCount();

        // 4. Update media table
        $mediaTable = $prefix . 'media';
        try {
            $stmt = $pdo->prepare("UPDATE `{$mediaTable}` SET 
                `file_path` = REPLACE(`file_path`, ?, ?)
                WHERE `file_path` LIKE ?");
            $stmt->execute([$oldUrl, $newUrl, '%' . $oldUrl . '%']);
            $count += $stmt->rowCount();
        } catch (\Throwable) {
            // Optional table column
        }

        // 5. Update theme_options with recursive JSON/format-aware replacement
        $themeOptionsTable = $prefix . 'theme_options';
        try {
            $rows = $pdo->query("SELECT `id`, `option_value` FROM `{$themeOptionsTable}` WHERE `option_value` LIKE '%{$oldUrl}%'")->fetchAll(PDO::FETCH_ASSOC);
            $updateStmt = $pdo->prepare("UPDATE `{$themeOptionsTable}` SET `option_value` = :val WHERE `id` = :id");

            foreach ($rows as $row) {
                $val = (string)$row['option_value'];
                $replaced = $this->replaceInStructuredString($val, $oldUrl, $newUrl);
                $updateStmt->execute([
                    ':val' => $replaced,
                    ':id'  => $row['id'],
                ]);
                $count++;
            }
        } catch (\Throwable) {
            // Optional table
        }

        return $count;
    }

    /**
     * Format-aware replacement that handles JSON and plain text without corrupting structure.
     */
    protected function replaceInStructuredString(string $data, string $search, string $replace): string
    {
        // Try JSON decoding first
        $decoded = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $modified = $this->recursiveReplace($decoded, $search, $replace);
            return (string)json_encode($modified, JSON_UNESCAPED_SLASHES);
        }

        // Plain string replacement
        return str_replace($search, $replace, $data);
    }

    protected function recursiveReplace(mixed $data, string $search, string $replace): mixed
    {
        if (is_string($data)) {
            return str_replace($search, $replace, $data);
        }
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = $this->recursiveReplace($v, $search, $replace);
            }
        }
        return $data;
    }

    protected function secureUploadsDirectory(): void
    {
        $uploadsDir = $this->appRoot . '/public/uploads';
        if (!is_dir($uploadsDir)) {
            @mkdir($uploadsDir, 0775, true);
        }

        $htaccess = $uploadsDir . '/.htaccess';
        $rules = <<<HTACCESS
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>
RemoveHandler .php .phtml .php3 .php4 .php5 .phar .cgi .pl .py .sh
HTACCESS;
        @file_put_contents($htaccess, $rules);
    }

    protected function clearCache(): void
    {
        $cacheDir = $this->appRoot . '/storage/cache';
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . '/*') ?: [];
            foreach ($files as $file) {
                if (is_file($file) && basename($file) !== '.gitkeep') {
                    @unlink($file);
                }
            }
        }
    }

    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }

        @rmdir($dir);
    }
}
