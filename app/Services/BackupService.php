<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Setting;
use PDO;
use RuntimeException;
use ZipArchive;

class BackupService
{
    protected string $backupDir;

    public function __construct(?string $backupDir = null)
    {
        $this->backupDir = $backupDir ?: (defined('APP_ROOT') ? APP_ROOT . '/storage/backups' : dirname(__DIR__, 2) . '/storage/backups');
        $this->ensureBackupDirectory();
    }

    /**
     * Ensure the backup storage directory exists and is secured from direct web access.
     */
    protected function ensureBackupDirectory(): void
    {
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0775, true);
        }

        $htaccess = $this->backupDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
        }
    }

    /**
     * Create a complete, portable backup archive of the CMS.
     *
     * @param array $options Options: ['include_media' => true, 'include_themes' => true, 'include_plugins' => true]
     * @return array
     */
    public function createBackup(array $options = []): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP Zip extension is required to create backup archives.');
        }

        $includeMedia = $options['include_media'] ?? true;
        $includeThemes = $options['include_themes'] ?? true;
        $includePlugins = $options['include_plugins'] ?? true;

        $db = app(Database::class);
        $pdo = $db->getPdo();

        $timestamp = date('Y-m-d_His');
        $randomSuffix = bin2hex(random_bytes(3));
        $filename = "favorite_cms_backup_{$timestamp}_{$randomSuffix}.zip";
        $zipPath = $this->backupDir . '/' . $filename;

        $tempSqlFile = $this->backupDir . "/dump_{$timestamp}_{$randomSuffix}.sql";

        try {
            // 1. Dump database to SQL file
            $dumpResult = $this->dumpDatabase($pdo, $db->prefix(), $tempSqlFile);

            // 2. Initialize ZIP archive
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Could not create backup zip archive at {$zipPath}");
            }

            // 3. Add database SQL dump
            $zip->addFile($tempSqlFile, 'database.sql');

            // 4. Collect file inventory and checksums
            $fileInventory = [];
            $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);

            if ($includeMedia && is_dir($appRoot . '/public/uploads')) {
                $this->addDirectoryToZip($zip, $appRoot . '/public/uploads', 'public/uploads', $fileInventory);
            }

            if ($includeThemes && is_dir($appRoot . '/themes')) {
                $this->addDirectoryToZip($zip, $appRoot . '/themes', 'themes', $fileInventory);
            }

            if ($includePlugins && is_dir($appRoot . '/plugins')) {
                $this->addDirectoryToZip($zip, $appRoot . '/plugins', 'plugins', $fileInventory);
            }

            // 5. Build and add manifest.json
            $manifest = [
                'manifest_version' => 1,
                'cms_name'         => 'Favorite CMS Universal',
                'cms_version'      => defined('APP_VERSION') ? APP_VERSION : '1.0.0-beta',
                'schema_version'   => $this->getSchemaVersion($db),
                'created_at'       => date('c'),
                'site_name'        => Setting::get('general', 'site_name', 'Favorite CMS'),
                'site_url'         => Setting::get('general', 'site_url', (string)env('APP_URL', 'http://localhost')),
                'table_prefix'     => $db->prefix(),
                'tables'           => $dumpResult['tables'],
                'total_rows'       => $dumpResult['total_rows'],
                'database_size'    => filesize($tempSqlFile),
                'includes'         => [
                    'database' => true,
                    'media'    => $includeMedia,
                    'themes'   => $includeThemes,
                    'plugins'  => $includePlugins,
                ],
                'file_count'       => count($fileInventory),
                'sql_checksum'     => hash_file('sha256', $tempSqlFile),
            ];

            $zip->addFromString('manifest.json', (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->close();

            // Clean up temporary SQL file
            @unlink($tempSqlFile);

            $zipSize = filesize($zipPath);
            $zipHash = hash_file('sha256', $zipPath);

            return [
                'success'   => true,
                'filename'  => $filename,
                'path'      => $zipPath,
                'size'      => $zipSize,
                'sha256'    => $zipHash,
                'manifest'  => $manifest,
            ];
        } catch (\Throwable $e) {
            @unlink($tempSqlFile);
            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }
            throw $e;
        }
    }

    /**
     * Dump database tables to a SQL file using chunked streaming.
     */
    public function dumpDatabase(PDO $pdo, string $prefix, string $outputPath): array
    {
        $handle = fopen($outputPath, 'w');
        if (!$handle) {
            throw new RuntimeException("Could not open file for writing database dump: {$outputPath}");
        }

        fwrite($handle, "-- Favorite CMS Universal Database Dump\n");
        fwrite($handle, "-- Generated: " . date('c') . "\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
        fwrite($handle, "SET SQL_MODE=\"NO_AUTO_VALUE_ON_ZERO\";\n");
        fwrite($handle, "SET NAMES utf8mb4;\n\n");

        // Fetch tables matching prefix
        $tablesStmt = $pdo->query("SHOW TABLES LIKE '{$prefix}%'");
        $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

        $tablesSummary = [];
        $totalRows = 0;

        foreach ($tables as $table) {
            // Write DROP and CREATE TABLE
            fwrite($handle, "-- --------------------------------------------------------\n");
            fwrite($handle, "-- Table structure for `{$table}`\n");
            fwrite($handle, "-- --------------------------------------------------------\n");
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");

            $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
            $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
            $createSql = $createRow['Create Table'] ?? '';
            fwrite($handle, $createSql . ";\n\n");

            // Dump data in chunks of 500 rows to prevent high memory usage
            fwrite($handle, "-- Dumping data for `{$table}`\n");
            $countStmt = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
            $rowCount = (int)$countStmt->fetchColumn();
            $tablesSummary[$table] = $rowCount;
            $totalRows += $rowCount;

            if ($rowCount > 0) {
                $offset = 0;
                $chunkSize = 500;

                while ($offset < $rowCount) {
                    $rowsStmt = $pdo->query("SELECT * FROM `{$table}` LIMIT {$chunkSize} OFFSET {$offset}");
                    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($rows)) {
                        $columns = array_keys($rows[0]);
                        $escapedColumns = array_map(fn($c) => "`" . str_replace('`', '``', $c) . "`", $columns);
                        $colList = implode(', ', $escapedColumns);

                        $valListArray = [];
                        foreach ($rows as $row) {
                            $escapedVals = [];
                            foreach ($row as $val) {
                                if ($val === null) {
                                    $escapedVals[] = 'NULL';
                                } elseif (is_int($val) || is_float($val)) {
                                    $escapedVals[] = $val;
                                } else {
                                    $escapedVals[] = $pdo->quote((string)$val);
                                }
                            }
                            $valListArray[] = "(" . implode(', ', $escapedVals) . ")";
                        }

                        fwrite($handle, "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $valListArray) . ";\n");
                    }

                    $offset += $chunkSize;
                }
            }

            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        return [
            'tables'     => $tablesSummary,
            'total_rows' => $totalRows,
        ];
    }

    /**
     * Recursively add a directory to ZIP, strictly excluding dev/runtime caches and secrets.
     */
    protected function addDirectoryToZip(ZipArchive $zip, string $dir, string $zipPrefix, array &$inventory): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $excludePatterns = [
            '/\.git/',
            '/\.github/',
            '/tests/',
            '/\.env/',
            '/installed\.lock/',
            '/\.log$/',
            '/cache\//',
            '/sessions\//',
        ];

        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($dir) + 1);
            $normalizedRelative = str_replace('\\', '/', $relativePath);
            $zipEntryPath = $zipPrefix . '/' . $normalizedRelative;

            // Check exclusions
            $skip = false;
            foreach ($excludePatterns as $pattern) {
                if (preg_match($pattern, '/' . $zipEntryPath)) {
                    $skip = true;
                    break;
                }
            }

            if ($skip) {
                continue;
            }

            $zip->addFile($filePath, $zipEntryPath);
            $inventory[$zipEntryPath] = filesize($filePath);
        }
    }

    /**
     * Retrieve schema migration version count.
     */
    protected function getSchemaVersion(Database $db): int
    {
        try {
            $row = $db->selectOne("SELECT COUNT(*) as c FROM `{$db->prefix()}cms_migrations`");
            return (int)($row->c ?? 0);
        } catch (\Throwable) {
            return 13;
        }
    }

    /**
     * Get list of all available backups in the backup storage directory.
     */
    public function getBackups(): array
    {
        $backups = [];
        if (!is_dir($this->backupDir)) {
            return [];
        }

        $files = glob($this->backupDir . '/favorite_cms_backup_*.zip') ?: [];
        rsort($files);

        foreach ($files as $file) {
            $filename = basename($file);
            $size = filesize($file);
            $date = filemtime($file);

            $manifest = null;
            if (class_exists(ZipArchive::class)) {
                $zip = new ZipArchive();
                if ($zip->open($file) === true) {
                    $manifestJson = $zip->getFromName('manifest.json');
                    if ($manifestJson !== false) {
                        $manifest = json_decode($manifestJson, true);
                    }
                    $zip->close();
                }
            }

            $backups[] = [
                'filename'  => $filename,
                'path'      => $file,
                'size'      => $size,
                'date'      => $date,
                'manifest'  => $manifest,
            ];
        }

        return $backups;
    }

    /**
     * Delete a specific backup by filename.
     */
    public function deleteBackup(string $filename): bool
    {
        $safeName = basename($filename);
        if (!preg_match('/^favorite_cms_backup_[A-Za-z0-9_.-]+\.zip$/', $safeName)) {
            throw new \InvalidArgumentException('Invalid backup filename.');
        }

        $path = $this->backupDir . '/' . $safeName;
        if (file_exists($path)) {
            return @unlink($path);
        }

        return false;
    }
}

