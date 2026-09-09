<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Integration;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Services\BackupService;
use FavoriteCMS\Services\RestoreService;
use FavoriteCMS\Services\Update\UpdateManager;
use FavoriteCMS\Services\Update\UpdatePackageValidator;
use FavoriteCMS\Services\Update\MaintenanceMode;
use PDO;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class CoreUpdateLifecycleTest extends TestCase
{
    protected string $testDir;
    protected string $testAppRoot;
    protected ?PDO $pdo = null;
    protected string $dbName;
    protected string $prefix = 'fvcms_utest_';
    protected Database $db;
    protected ?Database $originalDb = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDb = app()->has(Database::class) ? app()->make(Database::class) : null;
        $this->testDir = sys_get_temp_dir() . '/fcms_upd_test_' . bin2hex(random_bytes(4));
        $this->testAppRoot = $this->testDir . '/app_root';
        @mkdir($this->testAppRoot, 0775, true);

        // Setup a local test database
        $this->dbName = 'fcms_up_test_' . bin2hex(random_bytes(4));
        try {
            $this->pdo = new PDO('mysql:host=localhost;port=3306', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $this->pdo->exec("CREATE DATABASE `{$this->dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->pdo->exec("USE `{$this->dbName}`");

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

            // Build test app directory structure mimicking existing v1.0.9-beta installation
            $this->setupTestInstallation();

        } catch (\Throwable $e) {
            $this->markTestSkipped('Local MySQL server not available for update lifecycle test: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalDb !== null) {
            app()->instance(Database::class, $this->originalDb);
        }

        if ($this->pdo) {
            $this->pdo->exec("DROP DATABASE IF EXISTS `{$this->dbName}`");
        }

        $this->removeDir($this->testDir);
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

    protected function setupTestInstallation(): void
    {
        // 1. Directories
        $dirs = [
            'app/Core',
            'config',
            'database/migrations',
            'resources/views',
            'public/uploads',
            'public/assets',
            'plugins/favorite-quick-notes',
            'themes/default',
            'storage/backups',
            'storage/temp',
            'storage/logs',
            'storage/cache',
        ];
        foreach ($dirs as $d) {
            @mkdir($this->testAppRoot . '/' . $d, 0775, true);
        }

        // 2. Base files
        file_put_contents($this->testAppRoot . '/.env', "DB_DATABASE={$this->dbName}\nDB_PREFIX={$this->prefix}\nSITE_KEY=secret123\n");
        file_put_contents($this->testAppRoot . '/storage/installed.lock', "installed_at=2026-09-01T00:00:00Z\nversion=1.0.9-beta\n");
        file_put_contents($this->testAppRoot . '/bootstrap.php', "<?php define('APP_VERSION', '1.0.9-beta');\ndefine('APP_ROOT', '{$this->testAppRoot}');\n");
        file_put_contents($this->testAppRoot . '/index.php', "<?php // root index\n");
        file_put_contents($this->testAppRoot . '/migrate.php', "<?php // cli migrate\n");
        file_put_contents($this->testAppRoot . '/public/index.php', "<?php // public index\n");
        file_put_contents($this->testAppRoot . '/app/Core/Application.php', "<?php namespace FavoriteCMS\Core; class Application { public function version() { return '1.0.9-beta'; } }\n");
        file_put_contents($this->testAppRoot . '/themes/default/index.php', "<?php // theme index\n");

        // User data files
        file_put_contents($this->testAppRoot . '/public/uploads/user-avatar.png', 'USER_AVATAR_IMAGE_DATA_123');
        file_put_contents($this->testAppRoot . '/plugins/favorite-quick-notes/plugin.php', '<?php // custom plugin active');

        // 3. Database Schema & Data
        $p = $this->prefix;
        $this->pdo->exec("CREATE TABLE `{$this->dbName}`.`{$p}cms_migrations` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL,
            batch INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->pdo->exec("CREATE TABLE `{$this->dbName}`.`{$p}settings` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_name VARCHAR(50) NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            value LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY group_key (group_name, setting_key)
        )");

        $this->pdo->exec("CREATE TABLE `{$this->dbName}`.`{$p}users` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            email VARCHAR(100) NOT NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT 'author',
            status VARCHAR(20) DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->pdo->exec("CREATE TABLE `{$this->dbName}`.`{$p}posts` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            content LONGTEXT NOT NULL,
            status VARCHAR(20) DEFAULT 'publish',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->pdo->exec("CREATE TABLE `{$this->dbName}`.`{$p}pages` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            content LONGTEXT NOT NULL,
            status VARCHAR(20) DEFAULT 'publish',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->pdo->exec("CREATE TABLE `{$this->dbName}`.`{$p}taxonomies` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            type VARCHAR(20) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $this->pdo->exec("CREATE TABLE `{$this->dbName}`.`{$p}media` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Insert initial baseline rows
        $this->pdo->exec("INSERT INTO `{$this->dbName}`.`{$p}cms_migrations` (`migration`, `batch`) VALUES
            ('001_create_cms_migrations_table', 1),
            ('002_create_users_table', 1),
            ('003_create_roles_permissions', 1),
            ('004_create_posts_table', 1),
            ('005_create_pages_table', 1),
            ('006_create_taxonomies_table', 1),
            ('007_create_media_table', 1),
            ('008_create_menus_table', 1),
            ('009_create_settings_table', 1)");

        $this->pdo->exec("INSERT INTO `{$this->dbName}`.`{$p}settings` (`group_name`, `setting_key`, `value`) VALUES
            ('general', 'site_name', 'My Production Blog'),
            ('general', 'site_url', 'https://example.com'),
            ('general', 'site_timezone', 'America/New_York'),
            ('seo', 'meta_title', 'SEO Title for Blog'),
            ('theme', 'active_theme', 'default')");

        $this->pdo->exec("INSERT INTO `{$this->dbName}`.`{$p}users` (`username`, `email`, `password`, `role`, `status`) VALUES
            ('admin_user', 'admin@example.com', '\$2y\$10\$hashed_admin_password_123', 'administrator', 'active'),
            ('author_bob', 'bob@example.com', '\$2y\$10\$hashed_bob_password_456', 'author', 'active')");

        $this->pdo->exec("INSERT INTO `{$this->dbName}`.`{$p}posts` (`title`, `slug`, `content`, `status`) VALUES
            ('First Great Post', 'first-great-post', '<p>Exciting news about Favorite CMS.</p>', 'publish'),
            ('Second Draft Post', 'second-draft-post', '<p>Draft content in progress.</p>', 'draft')");

        $this->pdo->exec("INSERT INTO `{$this->dbName}`.`{$p}pages` (`title`, `slug`, `content`, `status`) VALUES
            ('About Us', 'about', '<p>Welcome to our company profile.</p>', 'publish'),
            ('Contact', 'contact', '<p>Get in touch with us.</p>', 'publish')");

        $this->pdo->exec("INSERT INTO `{$this->dbName}`.`{$p}taxonomies` (`name`, `slug`, `type`) VALUES
            ('News', 'news', 'category'),
            ('Technology', 'technology', 'category'),
            ('Tutorial', 'tutorial', 'tag')");

        $this->pdo->exec("INSERT INTO `{$this->dbName}`.`{$p}media` (`filename`, `file_path`, `mime_type`, `file_size`) VALUES
            ('user-avatar.png', '/uploads/user-avatar.png', 'image/png', 26)");
    }

    /**
     * Create a realistic release update ZIP for v1.0.10-beta.
     */
    protected function createUpdateZip(string $zipPath, bool $brokenMigration = false): void
    {
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $prefix = 'Favorite-CMS-Universal/';

        // Release manifest
        $zip->addFromString($prefix . 'release.json', json_encode([
            'product'          => 'Favorite CMS Universal',
            'product_id'       => 'favorite-cms-universal',
            'version'          => '1.0.10-beta',
            'min_core_version' => '1.0.0-beta',
            'version'          => '1.0.10',
            'min_core_version' => '1.0.0',
            'min_php'          => '8.1.0',
            'created_at'       => date('c'),
        ]));

        // Core runtime files
        $zip->addFromString($prefix . 'bootstrap.php', "<?php define('APP_VERSION', '1.0.10-beta');\n");
        $zip->addFromString($prefix . 'bootstrap.php', "<?php define('APP_VERSION', '1.0.10');\n");
        $zip->addFromString($prefix . 'index.php', "<?php // updated root index\n");
        $zip->addFromString($prefix . 'migrate.php', "<?php // updated migrate\n");
        $zip->addFromString($prefix . 'public/index.php', "<?php // updated public index\n");
        $zip->addFromString($prefix . 'app/Core/Application.php', "<?php namespace FavoriteCMS\Core; class Application { public function version() { return '1.0.10-beta'; } }\n");
        $zip->addFromString($prefix . 'app/Core/Application.php', "<?php namespace FavoriteCMS\Core; class Application { public function version() { return '1.0.10'; } }\n");
        $zip->addFromString($prefix . 'resources/views/test.php', "<?php // new view template\n");

        // Add new database migration 015
        if ($brokenMigration) {
            $zip->addFromString($prefix . 'database/migrations/015_add_broken_migration.php', "<?php
class AddBrokenMigration {
    protected \$db;
    public function __construct(\$db) { \$this->db = \$db; }
    public function up() { throw new \RuntimeException('Simulated migration failure in test'); }
}");
        } else {
            $zip->addFromString($prefix . 'database/migrations/015_add_verified_table.php', "<?php
class AddVerifiedTable {
    protected \$db;
    public function __construct(\$db) { \$this->db = \$db; }
    public function up() {
        \$p = \$this->db->prefix();
        \$this->db->execute(\"CREATE TABLE `{\$p}cms_update_verified` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            note VARCHAR(100) NOT NULL
        )\");
if (!class_exists('AddVerifiedTable')) {
    class AddVerifiedTable {
        protected \$db;
        public function __construct(\$db) { \$this->db = \$db; }
        public function up() {
            \$p = \$this->db->prefix();
            \$this->db->execute(\"CREATE TABLE IF NOT EXISTS `{\$p}cms_update_verified` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                note VARCHAR(100) NOT NULL
            )\");
        }
    }
}");
        }

        $zip->close();
    }

    public function testSuccessfulCoreUpdateLifecycleAndDataPreservation(): void
    {
        $zipPath = $this->testDir . '/update_v1.0.10.zip';
        $this->createUpdateZip($zipPath, false);

        $updateManager = new UpdateManager(
            app(),
            $this->testAppRoot,
            new UpdatePackageValidator(),
            new MaintenanceMode($this->testAppRoot),
            null,
            new BackupService($this->testAppRoot . '/storage/backups', $this->testAppRoot),
            new RestoreService($this->testAppRoot)
        );

        $result = $updateManager->runUpdate($zipPath);

        // 1. Verify update result status
        $this->assertTrue($result['success']);
        $this->assertEquals('1.0.10-beta', $result['updated_version']);
        $this->assertEquals('1.0.10', $result['updated_version']);
        $this->assertNotEmpty($result['backup_file']);
        $this->assertFileExists($this->testAppRoot . '/storage/backups/' . $result['backup_file']);

        // 2. Verify state and maintenance mode
        $state = $updateManager->getState();
        $this->assertEquals(UpdateManager::STATE_COMPLETED, $state['state']);
        $this->assertFalse($updateManager->getMaintenance()->isActive());

        // 3. Verify Core files were upgraded
        $bootstrapContent = file_get_contents($this->testAppRoot . '/bootstrap.php');
        $this->assertStringContainsString('1.0.10-beta', $bootstrapContent);
        $this->assertStringContainsString('1.0.10', $bootstrapContent);
        $this->assertFileExists($this->testAppRoot . '/resources/views/test.php');

        // 4. Verify new migration 015 was executed
        $p = $this->prefix;
        $migRan = $this->pdo->query("SELECT id FROM `{$this->dbName}`.`{$p}cms_migrations` WHERE `migration` = '015_add_verified_table'")->fetchColumn();
        $this->assertNotEmpty($migRan);

        $tableExists = (bool)$this->pdo->query("SHOW TABLES FROM `{$this->dbName}` LIKE '{$p}cms_update_verified'")->fetchColumn();
        $this->assertTrue($tableExists);

        // 5. CRITICAL DATA PRESERVATION VERIFICATIONS:
        // A. Configuration file .env was NEVER modified
        $envContent = file_get_contents($this->testAppRoot . '/.env');
        $this->assertStringContainsString('SITE_KEY=secret123', $envContent);

        // B. Installed lockfile was NEVER modified or deleted
        $this->assertFileExists($this->testAppRoot . '/storage/installed.lock');
        $lockContent = file_get_contents($this->testAppRoot . '/storage/installed.lock');
        $this->assertStringContainsString('installed_at=2026-09-01', $lockContent);

        // C. User uploaded media in public/uploads/ was NEVER modified
        $this->assertFileExists($this->testAppRoot . '/public/uploads/user-avatar.png');
        $this->assertEquals('USER_AVATAR_IMAGE_DATA_123', file_get_contents($this->testAppRoot . '/public/uploads/user-avatar.png'));

        // D. User installed plugins in plugins/ were NEVER modified
        $this->assertFileExists($this->testAppRoot . '/plugins/favorite-quick-notes/plugin.php');
        $this->assertStringContainsString('custom plugin active', file_get_contents($this->testAppRoot . '/plugins/favorite-quick-notes/plugin.php'));

        // E. Database Posts: 100% preserved
        $postRows = $this->pdo->query("SELECT * FROM `{$this->dbName}`.`{$p}posts` ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $postRows);
        $this->assertEquals('First Great Post', $postRows[0]['title']);
        $this->assertEquals('publish', $postRows[0]['status']);
        $this->assertEquals('Second Draft Post', $postRows[1]['title']);
        $this->assertEquals('draft', $postRows[1]['status']);

        // F. Database Pages: 100% preserved
        $pageRows = $this->pdo->query("SELECT * FROM `{$this->dbName}`.`{$p}pages` ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $pageRows);
        $this->assertEquals('About Us', $pageRows[0]['title']);
        $this->assertEquals('Contact', $pageRows[1]['title']);

        // G. Database Settings: 100% preserved
        $siteName = $this->pdo->query("SELECT value FROM `{$this->dbName}`.`{$p}settings` WHERE group_name='general' AND setting_key='site_name'")->fetchColumn();
        $this->assertEquals('My Production Blog', $siteName);
        $timezone = $this->pdo->query("SELECT value FROM `{$this->dbName}`.`{$p}settings` WHERE group_name='general' AND setting_key='site_timezone'")->fetchColumn();
        $this->assertEquals('America/New_York', $timezone);
        $seoTitle = $this->pdo->query("SELECT value FROM `{$this->dbName}`.`{$p}settings` WHERE group_name='seo' AND setting_key='meta_title'")->fetchColumn();
        $this->assertEquals('SEO Title for Blog', $seoTitle);

        // H. Database Users: 100% preserved
        $adminUser = $this->pdo->query("SELECT * FROM `{$this->dbName}`.`{$p}users` WHERE username='admin_user'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($adminUser);
        $this->assertEquals('$2y$10$hashed_admin_password_123', $adminUser['password']);
        $this->assertEquals('administrator', $adminUser['role']);

        // I. Database Taxonomies: 100% preserved
        $taxCount = (int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->dbName}`.`{$p}taxonomies`")->fetchColumn();
        $this->assertEquals(3, $taxCount);

        // J. Database Media records: 100% preserved
        $mediaRow = $this->pdo->query("SELECT * FROM `{$this->dbName}`.`{$p}media` WHERE filename='user-avatar.png'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($mediaRow);
        $this->assertEquals('/uploads/user-avatar.png', $mediaRow['file_path']);
    }

    public function testFailedMigrationTriggersRollbackAndPreservesBackup(): void
    {
        $zipPath = $this->testDir . '/broken_update.zip';
        $this->createUpdateZip($zipPath, true); // broken migration

        $updateManager = new UpdateManager(
            app(),
            $this->testAppRoot,
            new UpdatePackageValidator(),
            new MaintenanceMode($this->testAppRoot),
            null,
            new BackupService($this->testAppRoot . '/storage/backups', $this->testAppRoot),
            new RestoreService($this->testAppRoot)
        );

        $caughtException = null;
        try {
            $updateManager->runUpdate($zipPath);
        } catch (\Throwable $e) {
            $caughtException = $e;
        }

        $this->assertNotNull($caughtException);
        $this->assertStringContainsString('Simulated migration failure', $caughtException->getMessage());

        // Verify Core files rolled back to 1.0.9-beta
        $bootstrapContent = file_get_contents($this->testAppRoot . '/bootstrap.php');
        $this->assertStringContainsString('1.0.9-beta', $bootstrapContent);

        // Verify backup was created and remains available
        $backups = glob($this->testAppRoot . '/storage/backups/*.zip');
        $this->assertNotEmpty($backups);

        // Verify state is marked failed and accurately documents non-transactional MySQL DDL
        $state = $updateManager->getState();
        $this->assertEquals(UpdateManager::STATE_FAILED, $state['state']);
        $this->assertTrue($state['database_rollback_required']);
        $this->assertStringContainsString('Database migrations in MySQL are non-transactional', $state['database_recovery_note']);
        $this->assertNotEmpty($state['backup']['filename']);

        // Verify newly copied broken migration was deleted from database/migrations so no broken migration remains
        $this->assertFileDoesNotExist($this->testAppRoot . '/database/migrations/015_add_broken_migration.php');

        // Verify newly added view was rolled back and does not leave mixed state
        $this->assertFileDoesNotExist($this->testAppRoot . '/resources/views/test.php');

        // Verify lock is released so administrator can retry or recover
        $this->assertFalse($updateManager->isUpdateInProgress());
    }

    public function testConcurrentUpdateProtection(): void
    {
        $updateManager = new UpdateManager(
            app(),
            $this->testAppRoot,
            new UpdatePackageValidator(),
            new MaintenanceMode($this->testAppRoot)
        );

        $locked = $updateManager->acquireLock('existing_session', '1.0.10-beta');
        $locked = $updateManager->acquireLock('existing_session', '1.0.10');
        $this->assertTrue($locked);
        $this->assertTrue($updateManager->isUpdateInProgress());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Another update operation is currently in progress');

        $zipPath = $this->testDir . '/update_v1.0.10.zip';
        $this->createUpdateZip($zipPath, false);

        $updateManager->runUpdate($zipPath);
    }

    public function testSubdirectoryCmsInstallationUpdateAndLinkPreservation(): void
    {
        $origBase = $GLOBALS['favorite_cms_base_path'] ?? null;
        $GLOBALS['favorite_cms_base_path'] = '/cms';

        try {
            $zipPath = $this->testDir . '/update_sub_v1.0.10.zip';
            $this->createUpdateZip($zipPath, false);

            $updateManager = new UpdateManager(
                app(),
                $this->testAppRoot,
                new UpdatePackageValidator(),
                new MaintenanceMode($this->testAppRoot),
                null,
                new BackupService($this->testAppRoot . '/storage/backups', $this->testAppRoot),
                new RestoreService($this->testAppRoot)
            );

            $result = $updateManager->runUpdate($zipPath);

            $this->assertTrue($result['success']);
            $this->assertEquals('1.0.10', $result['updated_version']);

            // Verify base path remains respected
            $this->assertEquals('/cms', $GLOBALS['favorite_cms_base_path']);

            // Verify post-update site files remain accessible
            $bootstrap = file_get_contents($this->testAppRoot . '/bootstrap.php');
            $this->assertStringContainsString('1.0.10', $bootstrap);

            // Verify database records remain intact under subdirectory deployment
            $p = $this->prefix;
            $postCount = (int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->dbName}`.`{$p}posts`")->fetchColumn();
            $this->assertEquals(2, $postCount);

            $pageCount = (int)$this->pdo->query("SELECT COUNT(*) FROM `{$this->dbName}`.`{$p}pages`")->fetchColumn();
            $this->assertEquals(2, $pageCount);
        } finally {
            if ($origBase !== null) {
                $GLOBALS['favorite_cms_base_path'] = $origBase;
            } else {
                unset($GLOBALS['favorite_cms_base_path']);
            }
        }
    }
}


