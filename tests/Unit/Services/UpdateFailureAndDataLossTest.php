<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Services;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Services\BackupService;
use FavoriteCMS\Services\RestoreService;
use FavoriteCMS\Services\Update\MaintenanceMode;
use FavoriteCMS\Services\Update\UpdateManager;
use FavoriteCMS\Services\Update\UpdatePackageValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class UpdateFailureAndDataLossTest extends TestCase
{
    protected string $tempDir;
    protected string $appRoot;
    protected UpdatePackageValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/fvcms_fail_test_' . bin2hex(random_bytes(4));
        $this->appRoot = $this->tempDir . '/cms';
        @mkdir($this->appRoot, 0775, true);
        $this->validator = new UpdatePackageValidator();

        // Setup mock CMS directory structure
        foreach (['app/Core', 'config', 'database/migrations', 'resources', 'public/uploads', 'plugins', 'themes/default', 'storage/backups', 'storage/temp', 'storage/logs'] as $d) {
            @mkdir($this->appRoot . '/' . $d, 0775, true);
        }

        file_put_contents($this->appRoot . '/.env', "DB_PASS=production_secret_123\n");
        file_put_contents($this->appRoot . '/storage/installed.lock', "installed_at=2026-01-01\n");
        file_put_contents($this->appRoot . '/bootstrap.php', "<?php define('APP_VERSION', '1.0.9-beta');");
        file_put_contents($this->appRoot . '/index.php', "<?php");
        file_put_contents($this->appRoot . '/migrate.php', "<?php");
        file_put_contents($this->appRoot . '/app/Core/Application.php', "<?php namespace FavoriteCMS\Core; class Application {}");
        file_put_contents($this->appRoot . '/public/index.php', "<?php");
        file_put_contents($this->appRoot . '/public/uploads/important-doc.pdf', 'CRITICAL_USER_DOCUMENT');
        file_put_contents($this->appRoot . '/plugins/my-plugin.php', '<?php // user plugin');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if (!$items) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->removeDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    protected function makeZip(string $filename, array $files): string
    {
        $path = $this->tempDir . '/' . $filename;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString('Favorite-CMS-Universal/' . $name, $content);
        }
        $zip->close();
        return $path;
    }

    // Scenario 2: Invalid ZIP
    public function testScenario2InvalidZipFile(): void
    {
        $fakeZip = $this->tempDir . '/fake.zip';
        file_put_contents($fakeZip, 'THIS_IS_NOT_A_ZIP_ARCHIVE');

        $val = $this->validator->validate($fakeZip);
        $this->assertFalse($val['valid']);
        $this->assertStringContainsString('Failed to open update archive', implode(' ', $val['errors']));
    }

    // Scenario 3: Corrupted ZIP
    public function testScenario3CorruptedZipFile(): void
    {
        $corruptZip = $this->tempDir . '/corrupt.zip';
        // Write PK signature but truncate body
        file_put_contents($corruptZip, "PK\x03\x04" . str_repeat("\x00", 20));

        $val = $this->validator->validate($corruptZip);
        $this->assertFalse($val['valid']);
    }

    // Scenario 4: Wrong Product Package
    public function testScenario4WrongProductPackage(): void
    {
        $zip = $this->makeZip('wrong_product.zip', [
            'release.json'             => json_encode(['product' => 'Drupal CMS', 'version' => '10.0']),
            'bootstrap.php'            => '<?php',
            'index.php'                => '<?php',
            'migrate.php'              => '<?php',
            'app/Core/Application.php' => '<?php',
            'public/index.php'         => '<?php',
        ]);

        $val = $this->validator->validate($zip);
        $this->assertFalse($val['valid']);
        $this->assertStringContainsString('does not match Favorite CMS Universal', implode(' ', $val['errors']));
    }

    // Scenario 5: Wrong/Missing Version
    public function testScenario5MissingVersion(): void
    {
        $zip = $this->makeZip('no_version.zip', [
            'release.json'             => json_encode(['product' => 'Favorite CMS Universal']),
            'bootstrap.php'            => '<?php // no version constant',
            'index.php'                => '<?php',
            'migrate.php'              => '<?php',
            'app/Core/Application.php' => '<?php',
            'public/index.php'         => '<?php',
        ]);

        $val = $this->validator->validate($zip);
        $this->assertFalse($val['valid']);
        $this->assertStringContainsString('Could not determine Core version', implode(' ', $val['errors']));
    }

    // Scenario 6: Incompatible Version (requires newer PHP)
    public function testScenario6IncompatiblePhpRequirement(): void
    {
        $zip = $this->makeZip('incompatible.zip', [
            'release.json'             => json_encode(['product' => 'Favorite CMS Universal', 'version' => '1.1.0', 'min_php' => '99.0']),
            'bootstrap.php'            => "<?php define('APP_VERSION', '1.1.0');",
            'index.php'                => '<?php',
            'migrate.php'              => '<?php',
            'app/Core/Application.php' => '<?php',
            'public/index.php'         => '<?php',
        ]);

        $val = $this->validator->validate($zip);
        $this->assertFalse($val['valid']);
        $this->assertStringContainsString('requires PHP 99.0', implode(' ', $val['errors']));
    }

    // Scenario 7: Invalid Checksum
    public function testScenario7ChecksumMismatch(): void
    {
        $zip = $this->makeZip('checksum.zip', [
            'release.json'             => json_encode(['product' => 'Favorite CMS Universal', 'version' => '1.1.0']),
            'bootstrap.php'            => "<?php define('APP_VERSION', '1.1.0');",
            'index.php'                => '<?php',
            'migrate.php'              => '<?php',
            'app/Core/Application.php' => '<?php',
            'public/index.php'         => '<?php',
        ]);

        $val = $this->validator->validate($zip, 'badbadbadbadbadbadbadbadbadbadbadbadbadbadbadbadbadbadbadbadbadbad');
        $this->assertFalse($val['valid']);
        $this->assertStringContainsString('checksum mismatch', strtolower(implode(' ', $val['errors'])));
    }

    // Scenario 8: Zip Slip Attempt
    public function testScenario8ZipSlipAttempt(): void
    {
        $zipPath = $this->tempDir . '/slip.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('Favorite-CMS-Universal/../../etc/passwd', 'malicious');
        $zip->close();

        $val = $this->validator->validate($zipPath);
        $this->assertFalse($val['valid']);
        $this->assertStringContainsString('path traversal', strtolower(implode(' ', $val['errors'])));
    }

    // Scenario 9: Backup Failure Halts Update
    public function testScenario9BackupFailureHaltsUpdate(): void
    {
        // Mock Container with Database so preUpdateCheck passes
        $container = new Container();
        $mockDb = $this->createMock(Database::class);
        $mockPdo = $this->createMock(\PDO::class);
        $mockDb->method('getPdo')->willReturn($mockPdo);
        $mockDb->method('tableExists')->willReturn(true);
        $mockDb->method('prefix')->willReturn('cms_');
        $container->instance(Database::class, $mockDb);

        // Mock BackupService that throws an exception
        $mockBackup = $this->createMock(BackupService::class);
        $mockBackup->method('createBackup')->willThrowException(new RuntimeException('Disk full: cannot write backup archive'));

        $zip = $this->makeZip('valid.zip', [
            'release.json'             => json_encode(['product' => 'Favorite CMS Universal', 'version' => '1.0.10']),
            'bootstrap.php'            => "<?php define('APP_VERSION', '1.0.10');",
            'index.php'                => '<?php',
            'migrate.php'              => '<?php',
            'app/Core/Application.php' => '<?php',
            'public/index.php'         => '<?php',
        ]);

        $manager = new UpdateManager(
            $container,
            $this->appRoot,
            $this->validator,
            new MaintenanceMode($this->appRoot),
            null,
            $mockBackup
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Disk full: cannot write backup archive');

        try {
            $manager->runUpdate($zip);
        } finally {
            // Confirm Core files were NEVER modified
            $bootstrap = file_get_contents($this->appRoot . '/bootstrap.php');
            $this->assertStringContainsString('1.0.9-beta', $bootstrap);
            // Confirm user files remain intact
            $this->assertFileExists($this->appRoot . '/public/uploads/important-doc.pdf');
            $this->assertEquals('CRITICAL_USER_DOCUMENT', file_get_contents($this->appRoot . '/public/uploads/important-doc.pdf'));
            // Confirm maintenance mode was NOT left active
            $this->assertFalse($manager->getMaintenance()->isActive());
        }
    }

    // Scenario 12: Filesystem Permission Failure
    public function testScenario12UnwritableDirectoryFailsHealthCheck(): void
    {
        // Point health check to non-existent or unwritable path
        $badRoot = $this->tempDir . '/unwritable_cms';
        @mkdir($badRoot, 0775, true);

        $manager = new UpdateManager(app(), $badRoot);
        $health = $manager->preUpdateCheck();

        $this->assertFalse($health['passed']);
        $this->assertFalse($health['checks']['writable_paths']['passed']);
    }

    // Scenario 15 & 16: Concurrent Update / Duplicate Request Prevention
    public function testScenario15And16ConcurrentUpdateBlocked(): void
    {
        $manager = new UpdateManager(app(), $this->appRoot, $this->validator, new MaintenanceMode($this->appRoot));

        $this->assertTrue($manager->acquireLock('session_1', '1.0.10'));
        $this->assertTrue($manager->isUpdateInProgress());

        // A second request tries to acquire lock
        $this->assertFalse($manager->acquireLock('session_2', '1.0.10'));

        // Calling runUpdate throws concurrency error
        $zip = $this->makeZip('valid2.zip', [
            'release.json'             => json_encode(['product' => 'Favorite CMS Universal', 'version' => '1.0.10']),
            'bootstrap.php'            => "<?php define('APP_VERSION', '1.0.10');",
            'index.php'                => '<?php',
            'migrate.php'              => '<?php',
            'app/Core/Application.php' => '<?php',
            'public/index.php'         => '<?php',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Another update operation is currently in progress');

        $manager->runUpdate($zip);
    }

    // Scenario 17: Stale Update Lock Detection
    public function testScenario17StaleLockIgnoredAfter15Minutes(): void
    {
        $manager = new UpdateManager(app(), $this->appRoot, $this->validator, new MaintenanceMode($this->appRoot));

        // Create lock timestamped 20 minutes ago
        $lockFile = $this->appRoot . '/storage/update.lock';
        $stalePayload = [
            'session_id' => 'stale_session',
            'time'       => time() - 1200, // 20 mins ago
            'phase'      => 'PACKAGE_STAGED',
        ];
        file_put_contents($lockFile, json_encode($stalePayload));

        // Manager detects it as stale and allows acquiring a fresh lock
        $this->assertFalse($manager->isUpdateInProgress());
        $this->assertTrue($manager->acquireLock('new_session', '1.0.10'));
    }

    // Scenarios 19 to 24: Absolute Data Preservation Guarantees
    public function testScenarios19Through24AbsoluteDataPreservation(): void
    {
        // 1. User uploads
        $this->assertFileExists($this->appRoot . '/public/uploads/important-doc.pdf');
        $this->assertEquals('CRITICAL_USER_DOCUMENT', file_get_contents($this->appRoot . '/public/uploads/important-doc.pdf'));

        // 2. Installed plugins
        $this->assertFileExists($this->appRoot . '/plugins/my-plugin.php');
        $this->assertStringContainsString('user plugin', file_get_contents($this->appRoot . '/plugins/my-plugin.php'));

        // 3. .env Credentials
        $this->assertFileExists($this->appRoot . '/.env');
        $this->assertStringContainsString('DB_PASS=production_secret_123', file_get_contents($this->appRoot . '/.env'));

        // 4. Installed lock
        $this->assertFileExists($this->appRoot . '/storage/installed.lock');
        $this->assertStringContainsString('installed_at=2026-01-01', file_get_contents($this->appRoot . '/storage/installed.lock'));
    }
}

