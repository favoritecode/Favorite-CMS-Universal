<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Exceptions\SecurityException;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Http\Controllers\Admin\ToolController;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Services\BackupService;
use FavoriteCMS\Services\RestoreService;
use PDO;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class BackupAndRestoreTest extends TestCase
{
    protected string $testDir;
    protected string $testBackupsDir;
    protected ?PDO $pdo = null;
    protected string $dbName;
    protected string $prefix = 'fvcms_btest_';
    protected Database $db;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/fcms_backup_test_' . bin2hex(random_bytes(4));
        $this->testBackupsDir = $this->testDir . '/backups';
        @mkdir($this->testBackupsDir, 0775, true);

        // Setup a local test database
        $this->dbName = 'fcms_bk_test_' . bin2hex(random_bytes(4));
        try {
            $this->pdo = new PDO('mysql:host=localhost;port=3306', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $this->pdo->exec("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $dbConfig = [
                'driver'    => 'mysql',
                'host'      => 'localhost',
                'port'      => '3306',
                'database'  => $this->dbName,
                'username'  => 'root',
                'password'  => '',
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => $this->prefix,
            ];

            $this->db = new Database($dbConfig);
            app()->instance(Database::class, $this->db);

            // Create essential tables with seed rows for backup/restore testing
            $this->pdo->exec("CREATE TABLE `{$this->dbName}`.`{$this->prefix}settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `group_name` VARCHAR(50) NOT NULL,
                `setting_key` VARCHAR(100) NOT NULL,
                `value` LONGTEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->pdo->exec("CREATE TABLE `{$this->dbName}`.`{$this->prefix}posts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(255) NOT NULL,
                `content` LONGTEXT NULL,
                `excerpt` TEXT NULL,
                `status` VARCHAR(20) DEFAULT 'publish'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->pdo->exec("CREATE TABLE `{$this->dbName}`.`{$this->prefix}pages` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(255) NOT NULL,
                `content` LONGTEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->pdo->exec("CREATE TABLE `{$this->dbName}`.`{$this->prefix}theme_options` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `theme_slug` VARCHAR(100) NOT NULL,
                `option_name` VARCHAR(100) NOT NULL,
                `option_value` LONGTEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // Seed sample data with original site URL
            $origUrl = 'http://oldsite.com';
            $this->pdo->exec("INSERT INTO `{$this->dbName}`.`{$this->prefix}settings` (`group_name`, `setting_key`, `value`) VALUES
                ('general', 'site_name', 'Original Site Name'),
                ('general', 'site_url', '{$origUrl}');");

            $this->pdo->exec("INSERT INTO `{$this->dbName}`.`{$this->prefix}posts` (`title`, `content`, `excerpt`) VALUES
                ('First Post', 'Visit our homepage at {$origUrl}/about for details.', 'Read more at {$origUrl}.');");

            $this->pdo->exec("INSERT INTO `{$this->dbName}`.`{$this->prefix}pages` (`title`, `content`) VALUES
                ('About Us', 'Welcome to {$origUrl}, the home of Favorite CMS.');");

            $themeJson = json_encode(['header_logo' => "{$origUrl}/uploads/logo.png", 'footer_link' => "{$origUrl}/terms"]);
            $this->pdo->exec("INSERT INTO `{$this->dbName}`.`{$this->prefix}theme_options` (`theme_slug`, `option_name`, `option_value`) VALUES
                ('default', 'branding', '{$themeJson}');");

        } catch (\Throwable $e) {
            $this->markTestSkipped('Local MySQL server not available for backup/restore test: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->pdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }

        // Clean test directory
        if (is_dir($this->testDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->testDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }
            @rmdir($this->testDir);
        }
    }

    public function testBackupCreationAndManifestIntegrity(): void
    {
        $backupService = new BackupService($this->testBackupsDir);
        $result = $backupService->createBackup([
            'include_media'   => false,
            'include_themes'  => false,
            'include_plugins' => false,
        ]);

        $this->assertTrue($result['success']);
        $this->assertFileExists($result['path']);
        $this->assertGreaterThan(0, $result['size']);

        // Inspect the generated ZIP archive
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($result['path']));

        // Verify manifest.json exists
        $manifestJson = $zip->getFromName('manifest.json');
        $this->assertNotFalse($manifestJson);

        $manifest = json_decode($manifestJson, true);
        $this->assertIsArray($manifest);
        $this->assertSame('Favorite CMS Universal', $manifest['cms_name']);
        $this->assertSame($this->prefix, $manifest['table_prefix']);
        $this->assertArrayHasKey('database.sql', array_flip(['database.sql']));

        // Verify database.sql dump exists and contains table definitions
        $sqlDump = $zip->getFromName('database.sql');
        $this->assertNotFalse($sqlDump);
        $this->assertStringContainsString("CREATE TABLE `{$this->prefix}posts`", $sqlDump);
        $this->assertStringContainsString("INSERT INTO `{$this->prefix}posts`", $sqlDump);
        $this->assertStringContainsString('Visit our homepage at http://oldsite.com/about', $sqlDump);

        $zip->close();
    }

    public function testZipSlipPathTraversalDefense(): void
    {
        $maliciousZipPath = $this->testDir . '/malicious_slip.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($maliciousZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('manifest.json', json_encode(['cms_version' => '1.0.0']));
        $zip->addFromString('database.sql', '-- SQL');
        $zip->addFromString('../../evil.php', '<?php echo "pwned";');
        $zip->close();

        $restoreService = new RestoreService($this->testDir);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Zip-Slip');
        $restoreService->inspectBackup($maliciousZipPath);
    }

    public function testCorruptedOrMissingManifestRejection(): void
    {
        $invalidZipPath = $this->testDir . '/invalid_manifest.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($invalidZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('database.sql', '-- SQL only, no manifest');
        $zip->close();

        $restoreService = new RestoreService($this->testDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('manifest.json is missing');
        $restoreService->inspectBackup($invalidZipPath);
    }

    public function testFormatAwareUrlMigration(): void
    {
        $restoreService = new RestoreService($this->testDir);

        $oldUrl = 'http://oldsite.com';
        $newUrl = 'https://newsite.org';

        $migratedCount = $restoreService->migrateUrls($this->db, $oldUrl, $newUrl, $this->prefix);
        $this->assertGreaterThan(0, $migratedCount);

        // 1. Verify settings table
        $siteUrl = $this->db->selectOne("SELECT `value` FROM `{$this->prefix}settings` WHERE `setting_key` = 'site_url'");
        $this->assertSame($newUrl, $siteUrl->value);

        // 2. Verify posts table
        $post = $this->db->selectOne("SELECT `content`, `excerpt` FROM `{$this->prefix}posts` WHERE `id` = 1");
        $this->assertStringContainsString("Visit our homepage at {$newUrl}/about", $post->content);
        $this->assertStringNotContainsString($oldUrl, $post->content);
        $this->assertStringContainsString("Read more at {$newUrl}.", $post->excerpt);

        // 3. Verify pages table
        $page = $this->db->selectOne("SELECT `content` FROM `{$this->prefix}pages` WHERE `id` = 1");
        $this->assertStringContainsString("Welcome to {$newUrl}", $page->content);

        // 4. Verify theme_options table (JSON structure preserved!)
        $themeOpt = $this->db->selectOne("SELECT `option_value` FROM `{$this->prefix}theme_options` WHERE `id` = 1");
        $decoded = json_decode($themeOpt->option_value, true);
        $this->assertIsArray($decoded);
        $this->assertSame("{$newUrl}/uploads/logo.png", $decoded['header_logo']);
        $this->assertSame("{$newUrl}/terms", $decoded['footer_link']);
    }

    public function testEndToEndRestoreWithDomainMigration(): void
    {
        // 1. Create a clean backup
        $backupService = new BackupService($this->testBackupsDir);
        $backup = $backupService->createBackup([
            'include_media'   => false,
            'include_themes'  => false,
            'include_plugins' => false,
        ]);

        // 2. Clear out posts table
        $this->pdo->exec("DELETE FROM `{$this->dbName}`.`{$this->prefix}posts`");
        $emptyPostCount = (int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->dbName}`.`{$this->prefix}posts`")->fetchColumn();
        $this->assertSame(0, $emptyPostCount);

        // 3. Restore with domain migration to https://migrated.example.com
        $restoreService = new RestoreService($this->testDir);
        $dbConfig = [
            'driver'    => 'mysql',
            'host'      => 'localhost',
            'port'      => '3306',
            'database'  => $this->dbName,
            'username'  => 'root',
            'password'  => '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => $this->prefix,
        ];

        $res = $restoreService->restoreBackup(
            $backup['path'],
            $dbConfig,
            'https://migrated.example.com',
            true
        );

        $this->assertTrue($res['success']);
        $this->assertSame('https://migrated.example.com', $res['new_site_url']);

        // 4. Verify post was restored and migrated
        $restoredPost = $this->db->selectOne("SELECT `content` FROM `{$this->prefix}posts` WHERE `id` = 1");
        $this->assertNotNull($restoredPost);
        $this->assertStringContainsString('https://migrated.example.com/about', $restoredPost->content);
    }
}
