<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Update;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Services\BackupService;
use FavoriteCMS\Services\RestoreService;
use RuntimeException;
use Throwable;
use ZipArchive;

class UpdateManager
{
    // Update State Machine Constants
    public const STATE_READY                = 'READY';
    public const STATE_BACKUP_CREATED       = 'BACKUP_CREATED';
    public const STATE_MAINTENANCE_ENABLED  = 'MAINTENANCE_ENABLED';
    public const STATE_PACKAGE_STAGED       = 'PACKAGE_STAGED';
    public const STATE_FILES_PREPARED       = 'FILES_PREPARED';
    public const STATE_FILES_UPDATED        = 'FILES_UPDATED';
    public const STATE_MIGRATIONS_STARTED   = 'MIGRATIONS_STARTED';
    public const STATE_MIGRATIONS_COMPLETED = 'MIGRATIONS_COMPLETED';
    public const STATE_HEALTH_CHECK_STARTED = 'HEALTH_CHECK_STARTED';
    public const STATE_COMPLETED            = 'COMPLETED';
    public const STATE_FAILED               = 'FAILED';
    public const STATE_RECOVERY_REQUIRED    = 'RECOVERY_REQUIRED';

    protected Container $app;
    protected string $appRoot;
    protected string $storageDir;
    protected string $tempDir;
    protected string $lockFile;
    protected string $stateFile;
    protected string $logFile;

    protected UpdatePackageValidator $validator;
    protected MaintenanceMode $maintenance;
    protected ReleaseDiscovery $discovery;
    protected BackupService $backupService;
    protected RestoreService $restoreService;
    protected array $newlyAddedMigrations = [];

    public function __construct(
        ?Container $app = null,
        ?string $appRoot = null,
        ?UpdatePackageValidator $validator = null,
        ?MaintenanceMode $maintenance = null,
        ?ReleaseDiscovery $discovery = null,
        ?BackupService $backupService = null,
        ?RestoreService $restoreService = null
    ) {
        $this->app = $app ?? app();
        $this->appRoot = $appRoot ?: (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3));
        $this->storageDir = $this->appRoot . '/storage';
        $this->tempDir = $this->storageDir . '/temp';
        $this->lockFile = $this->storageDir . '/update.lock';
        $this->stateFile = $this->storageDir . '/update_state.json';
        $this->logFile = $this->storageDir . '/logs/update.log';

        $this->validator = $validator ?? new UpdatePackageValidator();
        $this->maintenance = $maintenance ?? new MaintenanceMode($this->appRoot);
        $this->discovery = $discovery ?? new ReleaseDiscovery($this->appRoot, $this->validator);
        $this->backupService = $backupService ?? new BackupService($this->storageDir . '/backups', $this->appRoot);
        $this->restoreService = $restoreService ?? new RestoreService($this->appRoot);

        $this->ensureDirectories();
    }

    protected function ensureDirectories(): void
    {
        foreach ([$this->storageDir, $this->tempDir, $this->storageDir . '/logs', $this->storageDir . '/backups'] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }

    public function getValidator(): UpdatePackageValidator
    {
        return $this->validator;
    }

    public function getMaintenance(): MaintenanceMode
    {
        return $this->maintenance;
    }

    public function getDiscovery(): ReleaseDiscovery
    {
        return $this->discovery;
    }

    public function getBackupService(): BackupService
    {
        return $this->backupService;
    }

    public function getRestoreService(): RestoreService
    {
        return $this->restoreService;
    }

    // -------------------------------------------------------------------------
    // Lock & State Machine Management (Idempotency, Double-Click Safety)
    // -------------------------------------------------------------------------

    public function isUpdateInProgress(): bool
    {
        if (!is_file($this->lockFile)) {
            return false;
        }

        $lockData = $this->getLockData();
        if ($lockData === null) {
            return false;
        }

        $lockTime = (int)($lockData['time'] ?? 0);
        // Stale lock detection: 15 minutes timeout
        if (time() - $lockTime > 900) {
            $this->log('Stale update lock detected (older than 15 minutes).');
            return false;
        }

        return true;
    }

    public function acquireLock(string $sessionId, string $targetVersion): bool
    {
        if ($this->isUpdateInProgress()) {
            return false;
        }

        $payload = [
            'session_id'     => $sessionId,
            'started_at'     => date('c'),
            'time'           => time(),
            'initiated_by'   => $_SESSION['auth_user_name'] ?? 'administrator',
            'target_version' => $targetVersion,
            'phase'          => self::STATE_READY,
        ];

        $tmp = $this->lockFile . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $this->lockFile)) {
            @unlink($tmp);
            return false;
        }

        $this->updateState(self::STATE_READY, [
            'session_id'     => $sessionId,
            'target_version' => $targetVersion,
        ]);

        return true;
    }

    public function releaseLock(): void
    {
        if (is_file($this->lockFile)) {
            @unlink($this->lockFile);
        }
    }

    public function getLockData(): ?array
    {
        if (!is_file($this->lockFile)) {
            return null;
        }
        $raw = @file_get_contents($this->lockFile);
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : null;
    }

    public function getState(): array
    {
        if (is_file($this->stateFile)) {
            $data = json_decode((string)@file_get_contents($this->stateFile), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return [
            'state'          => self::STATE_READY,
            'updated_at'     => date('c'),
            'target_version' => '',
            'backup'         => null,
            'error'          => null,
        ];
    }

    protected function updateState(string $newState, array $extra = []): void
    {
        $current = $this->getState();
        $current['state'] = $newState;
        $current['updated_at'] = date('c');

        foreach ($extra as $k => $v) {
            $current[$k] = $v;
        }

        file_put_contents($this->stateFile, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        // Also update lockfile if active
        if (is_file($this->lockFile)) {
            $lockData = $this->getLockData() ?: [];
            $lockData['phase'] = $newState;
            $lockData['time'] = time();
            file_put_contents($this->lockFile, json_encode($lockData, JSON_PRETTY_PRINT), LOCK_EX);
        }

        $this->log("State transition: -> {$newState}");
    }

    // -------------------------------------------------------------------------
    // Pre-Update Health Check (Phase 7)
    // -------------------------------------------------------------------------

    public function preUpdateCheck(?string $zipPath = null): array
    {
        $checks = [];
        $passed = true;

        // 1. PHP Version
        $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
        $checks['php_version'] = [
            'title'   => 'PHP Version (>= 8.1.0)',
            'current' => PHP_VERSION,
            'passed'  => $phpOk,
            'message' => $phpOk ? 'PHP version supported' : 'PHP 8.1.0 or higher is required',
        ];
        if (!$phpOk) $passed = false;

        // 2. Required PHP Extensions
        $requiredExtensions = ['zip', 'pdo', 'pdo_mysql', 'json', 'mbstring', 'curl'];
        $missingExt = [];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $missingExt[] = $ext;
            }
        }
        $extOk = empty($missingExt);
        $checks['extensions'] = [
            'title'   => 'Required PHP Extensions',
            'current' => $extOk ? 'All present' : 'Missing: ' . implode(', ', $missingExt),
            'passed'  => $extOk,
            'message' => $extOk ? 'All required extensions available' : 'Install missing extensions: ' . implode(', ', $missingExt),
        ];
        if (!$extOk) $passed = false;

        // 3. Writable Directories
        $writablePaths = [
            'app'                 => $this->appRoot . '/app',
            'config'              => $this->appRoot . '/config',
            'database/migrations' => $this->appRoot . '/database/migrations',
            'resources'           => $this->appRoot . '/resources',
            'public'              => $this->appRoot . '/public',
            'storage'             => $this->storageDir,
            'storage/backups'     => $this->storageDir . '/backups',
            'storage/temp'        => $this->tempDir,
        ];
        $unwritable = [];
        foreach ($writablePaths as $label => $dir) {
            if (!is_dir($dir) || !is_writable($dir)) {
                $unwritable[] = $label;
            }
        }
        $writableOk = empty($unwritable);
        $checks['writable_paths'] = [
            'title'   => 'Filesystem Permissions (Writable Directories)',
            'current' => $writableOk ? 'All writable' : 'Unwritable: ' . implode(', ', $unwritable),
            'passed'  => $writableOk,
            'message' => $writableOk ? 'Core directories are writable' : 'Grant write permissions (0775/0755) to: ' . implode(', ', $unwritable),
        ];
        if (!$writableOk) $passed = false;

        // 4. Database Connection & Migrations Table
        $dbOk = false;
        $dbMsg = '';
        try {
            $db = $this->app->make(Database::class);
            $pdo = $db->getPdo();
            $dbOk = ($pdo !== null);
            $migrator = new Migrator($db);
            $migrator->createMigrationsTableIfNotExists();
            $dbMsg = 'Database connected (MySQL ' . ($pdo->getAttribute(\PDO::ATTR_SERVER_VERSION) ?: '') . ')';
        } catch (Throwable $e) {
            $dbOk = false;
            $dbMsg = 'Database error: ' . $e->getMessage();
        }
        $checks['database'] = [
            'title'   => 'Database Connectivity',
            'current' => $dbOk ? 'Connected' : 'Connection failed',
            'passed'  => $dbOk,
            'message' => $dbMsg,
        ];
        if (!$dbOk) $passed = false;

        // 5. Disk Space
        $freeBytes = @disk_free_space($this->appRoot);
        $spaceOk = ($freeBytes === false || $freeBytes > 52428800); // 50MB min
        $checks['disk_space'] = [
            'title'   => 'Available Disk Space (>= 50 MB)',
            'current' => $freeBytes !== false ? round($freeBytes / 1024 / 1024, 2) . ' MB free' : 'Unknown',
            'passed'  => $spaceOk,
            'message' => $spaceOk ? 'Sufficient disk space' : 'Insufficient disk space (minimum 50 MB required for backup and staging)',
        ];
        if (!$spaceOk) $passed = false;

        // 6. Optional: Validate Candidate ZIP if provided
        $packageValidation = null;
        if ($zipPath !== null && is_file($zipPath)) {
            $packageValidation = $this->validator->validate($zipPath);
            $checks['package_valid'] = [
                'title'   => 'Update Package Structure & Integrity',
                'current' => $packageValidation['valid'] ? "Version {$packageValidation['version']}" : 'Invalid package',
                'passed'  => $packageValidation['valid'],
                'message' => $packageValidation['valid'] ? 'Package verified successfully' : implode('; ', $packageValidation['errors']),
            ];
            if (!$packageValidation['valid']) $passed = false;
        }

        return [
            'passed'             => $passed,
            'checked_at'         => date('c'),
            'checks'             => $checks,
            'package_validation' => $packageValidation,
        ];
    }

    // -------------------------------------------------------------------------
    // Execution Pipeline (Phases 8 through 15)
    // -------------------------------------------------------------------------

    /**
     * Run the complete update process.
     *
     * @param string $zipPath Full path to the update package ZIP.
     * @param array $options Configuration options (e.g. ['expected_sha256' => '...']).
     * @return array Update result summary.
     */
    public function runUpdate(string $zipPath, array $options = []): array
    {
        $sessionId = bin2hex(random_bytes(8));
        $this->log("==================================================");
        $this->log("Starting Core Update Session: {$sessionId}");

        // 1. Validate Package
        $expectedHash = $options['expected_sha256'] ?? null;
        $validation = $this->validator->validate($zipPath, $expectedHash);

        if (!$validation['valid']) {
            $errorMsg = 'Package validation failed: ' . implode('; ', $validation['errors']);
            $this->log("ERROR: {$errorMsg}");
            $this->updateState(self::STATE_FAILED, ['error' => $errorMsg]);
            throw new RuntimeException($errorMsg);
        }

        $targetVersion = $validation['version'];
        $rootPrefix = $validation['root_prefix'];

        // 2. Acquire Concurrency Lock
        if (!$this->acquireLock($sessionId, $targetVersion)) {
            throw new RuntimeException("Another update operation is currently in progress. Please wait.");
        }

        $backupResult = null;
        $stageDir = null;
        $coreBackupDir = null;

        try {
            // 3. Pre-Update Health Check
            $health = $this->preUpdateCheck($zipPath);
            if (!$health['passed']) {
                $failedChecks = [];
                foreach ($health['checks'] as $c) {
                    if (!$c['passed']) $failedChecks[] = "{$c['title']}: {$c['message']}";
                }
                throw new RuntimeException("Pre-update health check failed: " . implode(' | ', $failedChecks));
            }

            // 4. Automatic Full Pre-Update Backup (Phase 8)
            $this->log("Creating automatic pre-update backup...");
            $backupResult = $this->backupService->createBackup([
                'include_media'   => true,
                'include_themes'  => true,
                'include_plugins' => true,
            ]);

            if (empty($backupResult['path']) || !is_file($backupResult['path'])) {
                throw new RuntimeException("Automatic pre-update backup failed to produce an archive.");
            }

            $this->updateState(self::STATE_BACKUP_CREATED, [
                'backup' => [
                    'filename' => $backupResult['filename'],
                    'path'     => $backupResult['path'],
                    'size'     => $backupResult['size'],
                    'sha256'   => $backupResult['sha256'],
                ],
            ]);
            $this->log("Pre-update backup successfully created: {$backupResult['filename']} (" . round($backupResult['size'] / 1024 / 1024, 2) . " MB)");

            // 5. Enable Maintenance Mode (Phase 9)
            $this->log("Enabling maintenance mode...");
            $maintenanceEnabled = $this->maintenance->enable([
                'target_version' => $targetVersion,
                'session_id'     => $sessionId,
                'phase'          => self::STATE_MAINTENANCE_ENABLED,
            ]);

            if (!$maintenanceEnabled) {
                throw new RuntimeException("Could not enable maintenance mode.");
            }
            $this->updateState(self::STATE_MAINTENANCE_ENABLED);

            // 6. Stage the Update Archive (Phase 10)
            $this->log("Extracting package to temporary staging area...");
            $stageDir = $this->tempDir . '/stage_' . $sessionId;
            if (!mkdir($stageDir, 0775, true) && !is_dir($stageDir)) {
                throw new RuntimeException("Failed to create update staging directory: {$stageDir}");
            }

            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException("Could not open package archive for extraction: {$zipPath}");
            }
            $zip->extractTo($stageDir);
            $zip->close();

            $stagedRoot = ($rootPrefix !== '') ? $stageDir . '/' . $rootPrefix : $stageDir;
            $this->updateState(self::STATE_PACKAGE_STAGED, ['stage_dir' => $stagedRoot]);
            $this->log("Package extracted to stage: {$stagedRoot}");

            // 7. Prepare Local Core Backup for Fast Rollback (Phase 13)
            $coreBackupDir = $this->tempDir . '/core_backup_' . $sessionId;
            @mkdir($coreBackupDir, 0775, true);
            $this->backupCurrentCoreFiles($coreBackupDir);
            $this->updateState(self::STATE_FILES_PREPARED);

            // 8. Apply File Replacement Strategy (Phase 11)
            $this->log("Applying Core file updates (strictly preserving user data, uploads, plugins, and .env)...");
            $this->replaceCoreFiles($stagedRoot);
            $this->updateState(self::STATE_FILES_UPDATED);
            $this->log("Core files updated successfully.");

            // 9. Execute Database Schema Migrations (Phase 12)
            $this->log("Running pending database migrations...");
            $this->updateState(self::STATE_MIGRATIONS_STARTED);

            $db = $this->app->make(Database::class);
            $migrator = new Migrator($db);
            $appliedMigrations = $migrator->migrate($this->appRoot . '/database/migrations');

            $this->updateState(self::STATE_MIGRATIONS_COMPLETED, [
                'applied_migrations' => $appliedMigrations,
            ]);
            $this->log("Migrations completed. Applied " . count($appliedMigrations) . " migration(s).");

            // 10. Post-Update Health Check (Phase 15)
            $this->log("Performing post-update verification check...");
            $this->updateState(self::STATE_HEALTH_CHECK_STARTED);

            $postCheck = $this->postUpdateHealthCheck();
            if (!$postCheck['passed']) {
                throw new RuntimeException("Post-update health check failed: " . implode('; ', $postCheck['errors']));
            }

            // 11. Finalize & Clear Maintenance Mode
            $this->log("Finalizing update and disabling maintenance mode...");
            $this->maintenance->disable();
            $this->updateState(self::STATE_COMPLETED, [
                'installed_version' => $targetVersion,
                'completed_at'      => date('c'),
            ]);

            // Release lock
            $this->releaseLock();

            // Cleanup staging & temporary directories
            $this->cleanupDir($stageDir);
            $this->cleanupDir($coreBackupDir);

            // Clear cache
            $this->clearCache();

            $this->log("SUCCESS: Core updated to version {$targetVersion}.");

            return [
                'success'            => true,
                'session_id'         => $sessionId,
                'previous_version'   => defined('APP_VERSION') ? APP_VERSION : '1.0.0',
                'updated_version'    => $targetVersion,
                'applied_migrations' => $appliedMigrations,
                'backup_file'        => $backupResult['filename'],
                'backup_size'        => $backupResult['size'],
            ];

        } catch (Throwable $e) {
            $this->log("CRITICAL UPDATE FAILURE: " . $e->getMessage());
            $failedPhase = $this->getState()['state'] ?? self::STATE_FAILED;
            $stateData = [
                'error'        => $e->getMessage(),
                'trace'        => $e->getTraceAsString(),
                'failed_phase' => $failedPhase,
                'backup'       => $backupResult ? [
                    'filename' => $backupResult['filename'],
                    'path'     => $backupResult['path'],
                ] : null,
            ];

            if ($failedPhase === self::STATE_MIGRATIONS_STARTED || $failedPhase === self::STATE_MIGRATIONS_COMPLETED) {
                $stateData['database_rollback_required'] = true;
                $stateData['database_recovery_note'] = 'Database migrations in MySQL are non-transactional (DDL causes implicit commit). Restore database from authoritative pre-update backup: ' . ($backupResult['filename'] ?? 'storage/backups/');
            }

            $this->updateState(self::STATE_FAILED, $stateData);

            // Attempt safe rollback of Core files if they were touched
            if ($coreBackupDir && is_dir($coreBackupDir)) {
                $this->log("Attempting automatic file rollback from {$coreBackupDir}...");
                try {
                    $this->restoreCoreFiles($coreBackupDir);
                    $this->log("File rollback completed successfully.");
                } catch (Throwable $rollbackErr) {
                    $this->log("EMERGENCY: File rollback failed: " . $rollbackErr->getMessage());
                    $this->updateState(self::STATE_RECOVERY_REQUIRED, [
                        'rollback_error' => $rollbackErr->getMessage(),
                    ]);
                }
            }

            // Ensure maintenance mode is cleared or preserved safely
            $this->maintenance->disable();
            $this->releaseLock();

            // Clean staging if safe
            if ($stageDir && is_dir($stageDir)) {
                $this->cleanupDir($stageDir);
            }

            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // File Replacement Engine (Phase 11 - Strict Green/Red Separation)
    // -------------------------------------------------------------------------

    /**
     * Backup current live core files to a temporary staging folder for fast rollback.
     */
    protected function backupCurrentCoreFiles(string $backupDestination): void
    {
        $coreDirs = [
            'app',
            'resources',
            'vendor',
            'config',
            'public/assets',
            'themes/default',
            'public/themes/default',
        ];

        foreach ($coreDirs as $dir) {
            $src = $this->appRoot . '/' . $dir;
            if (is_dir($src)) {
                $dst = $backupDestination . '/' . $dir;
                $this->copyDirectoryRecursive($src, $dst);
            }
        }

        $coreFiles = [
            'bootstrap.php',
            'index.php',
            'migrate.php',
            'public/index.php',
            'README.txt',
            'README.md',
            'LICENSE',
            'CHANGELOG.md',
        ];

        foreach ($coreFiles as $file) {
            $src = $this->appRoot . '/' . $file;
            if (is_file($src)) {
                $dst = $backupDestination . '/' . $file;
                $parent = dirname($dst);
                if (!is_dir($parent)) {
                    @mkdir($parent, 0775, true);
                }
                @copy($src, $dst);
            }
        }
    }

    /**
     * Restore core files from temporary backup destination.
     * Cleans destination directories first to ensure NO mixed state is left behind.
     */
    protected function restoreCoreFiles(string $backupSource): void
    {
        // 1. Revert newly added migration files so database/migrations is clean
        if (!empty($this->newlyAddedMigrations)) {
            foreach ($this->newlyAddedMigrations as $newMigration) {
                if (file_exists($newMigration)) {
                    @unlink($newMigration);
                }
            }
            $this->newlyAddedMigrations = [];
        }

        // 2. Restore core directories (cleaning existing destination first to guarantee NO mixed state!)
        $coreDirs = [
            'app',
            'resources',
            'vendor',
            'config',
            'public/assets',
            'themes/default',
            'public/themes/default',
        ];

        foreach ($coreDirs as $dir) {
            $src = $backupSource . '/' . $dir;
            $dst = $this->appRoot . '/' . $dir;

            if (is_dir($src)) {
                // Remove existing dst to prevent mixed state (new files lingering from failed update)
                if (is_dir($dst)) {
                    $this->cleanupDir($dst);
                }
                $this->copyDirectoryRecursive($src, $dst);
            } elseif (is_dir($dst)) {
                $this->cleanupDir($dst);
            }
        }

        // 3. Restore root and entrypoint files
        $coreFiles = [
            'bootstrap.php',
            'index.php',
            'migrate.php',
            'public/index.php',
            'README.txt',
            'README.md',
            'LICENSE',
            'CHANGELOG.md',
        ];

        foreach ($coreFiles as $file) {
            $src = $backupSource . '/' . $file;
            $dst = $this->appRoot . '/' . $file;

            if (is_file($src)) {
                $parent = dirname($dst);
                if (!is_dir($parent)) {
                    @mkdir($parent, 0775, true);
                }
                @copy($src, $dst);
            } elseif (is_file($dst)) {
                @unlink($dst);
            }
        }
    }

    /**
     * Replace Core runtime files according to strict boundary rules.
     */
    protected function replaceCoreFiles(string $stagedRoot): void
    {
        $this->newlyAddedMigrations = [];

        // 1. Replace Green Core Directories
        $greenDirs = [
            'app',
            'resources',
            'vendor',
        ];

        foreach ($greenDirs as $dir) {
            $src = $stagedRoot . '/' . $dir;
            $dst = $this->appRoot . '/' . $dir;
            if (is_dir($src)) {
                $this->copyDirectoryRecursive($src, $dst);
            }
        }

        // 2. Additive Migrations: Copy new migration files only (tracking newly added files for rollback)
        $srcMigrations = $stagedRoot . '/database/migrations';
        $dstMigrations = $this->appRoot . '/database/migrations';
        if (is_dir($srcMigrations)) {
            if (!is_dir($dstMigrations)) {
                @mkdir($dstMigrations, 0775, true);
            }
            $files = glob($srcMigrations . '/*.php');
            if ($files) {
                foreach ($files as $file) {
                    $targetFile = $dstMigrations . '/' . basename($file);
                    if (!file_exists($targetFile)) {
                        $this->newlyAddedMigrations[] = $targetFile;
                    }
                    @copy($file, $targetFile);
                }
            }
        }

        // 3. Bundled Default Theme Updates
        $srcDefaultTheme = $stagedRoot . '/themes/default';
        $dstDefaultTheme = $this->appRoot . '/themes/default';
        if (is_dir($srcDefaultTheme)) {
            $this->copyDirectoryRecursive($srcDefaultTheme, $dstDefaultTheme);
        }

        $srcDefaultPublicTheme = $stagedRoot . '/public/themes/default';
        $dstDefaultPublicTheme = $this->appRoot . '/public/themes/default';
        if (is_dir($srcDefaultPublicTheme)) {
            $this->copyDirectoryRecursive($srcDefaultPublicTheme, $dstDefaultPublicTheme);
        }

        // 4. Public Assets
        $srcPublicAssets = $stagedRoot . '/public/assets';
        $dstPublicAssets = $this->appRoot . '/public/assets';
        if (is_dir($srcPublicAssets)) {
            $this->copyDirectoryRecursive($srcPublicAssets, $dstPublicAssets);
        }

        // 5. Root Entrypoint Files
        $coreFiles = [
            'bootstrap.php',
            'index.php',
            'migrate.php',
            'README.txt',
            'README.md',
            'LICENSE',
            'CHANGELOG.md',
        ];

        foreach ($coreFiles as $file) {
            $src = $stagedRoot . '/' . $file;
            $dst = $this->appRoot . '/' . $file;
            if (is_file($src)) {
                @copy($src, $dst);
            }
        }

        // Public index.php
        $srcPubIndex = $stagedRoot . '/public/index.php';
        $dstPubIndex = $this->appRoot . '/public/index.php';
        if (is_file($srcPubIndex)) {
            @copy($srcPubIndex, $dstPubIndex);
        }

        // 6. Yellow List: Config files (inspect / merge default templates, never discard custom settings)
        $srcConfig = $stagedRoot . '/config';
        if (is_dir($srcConfig)) {
            $configFiles = glob($srcConfig . '/*.php');
            if ($configFiles) {
                foreach ($configFiles as $cFile) {
                    $target = $this->appRoot . '/config/' . basename($cFile);
                    if (!is_file($target)) {
                        @copy($cFile, $target);
                    }
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Post-Update Health Check (Phase 15)
    // -------------------------------------------------------------------------

    public function postUpdateHealthCheck(): array
    {
        $errors = [];

        try {
            // 1. DB connection
            $db = $this->app->make(Database::class);
            $pdo = $db->getPdo();
            if ($pdo === null) {
                $errors[] = 'Database connection could not be established.';
            }

            // 2. Settings check
            $siteName = Setting::get('general', 'site_name');
            if ($siteName === null) {
                $errors[] = 'Could not query settings table after update.';
            }

            // 3. Active theme check
            $activeTheme = Setting::get('theme', 'active_theme', 'default');
            $themePath = $this->appRoot . '/themes/' . $activeTheme;
            if (!is_dir($themePath)) {
                $errors[] = "Active theme directory does not exist: {$themePath}";
            }

            // 4. Media uploads intact
            $uploadsDir = $this->appRoot . '/public/uploads';
            if (!is_dir($uploadsDir)) {
                $errors[] = 'Public uploads directory is missing.';
            }

        } catch (Throwable $e) {
            $errors[] = 'Health check exception: ' . $e->getMessage();
        }

        return [
            'passed' => empty($errors),
            'errors' => $errors,
        ];
    }

    // -------------------------------------------------------------------------
    // Utilities & Helpers
    // -------------------------------------------------------------------------

    protected function copyDirectoryRecursive(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            @mkdir($dst, 0775, true);
        }

        $items = scandir($src);
        if ($items === false) return;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $srcPath = $src . '/' . $item;
            $dstPath = $dst . '/' . $item;

            if (is_dir($srcPath)) {
                $this->copyDirectoryRecursive($srcPath, $dstPath);
            } else {
                @copy($srcPath, $dstPath);
            }
        }
    }

    protected function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) return;

        $items = scandir($dir);
        if ($items === false) return;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . '/' . $item;
            if (is_dir($p)) {
                $this->cleanupDir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    public function clearCache(): void
    {
        $cacheDir = $this->storageDir . '/cache';
        if (is_dir($cacheDir)) {
            $items = glob($cacheDir . '/*');
            if ($items) {
                foreach ($items as $item) {
                    if (basename($item) !== '.gitkeep') {
                        if (is_file($item)) {
                            @unlink($item);
                        }
                    }
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    public function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[{$timestamp}] {$message}\n";
        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    public function getLogs(int $maxLines = 100): array
    {
        if (!is_file($this->logFile)) {
            return [];
        }

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        return array_slice($lines, -$maxLines);
    }
}
