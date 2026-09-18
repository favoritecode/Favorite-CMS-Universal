<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Config;
use FavoriteCMS\Installer\DatabaseProvisioner;
use FavoriteCMS\Installer\InstallationService;
use FavoriteCMS\Installer\InstallationStateManager;
use PDO;
use PHPUnit\Framework\TestCase;

final class InstallerLifecycleTest extends TestCase
{
    private string $dbName = '';

    protected function setUp(): void
    {
        if (is_file(APP_ROOT . '/.env') || is_file(APP_ROOT . '/storage/installed.lock')) {
            self::markTestSkipped('Lifecycle test requires a release-like root with no .env and no installed.lock.');
        }

        try {
            $pdo = $this->serverPdo();
        } catch (\Throwable $e) {
            self::markTestSkipped('Local MySQL is not reachable: ' . $e->getMessage());
        }

        $this->dbName = 'favorite_cms_test_' . bin2hex(random_bytes(4));
        $pdo->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    protected function tearDown(): void
    {
        @unlink(APP_ROOT . '/.env');
        @unlink(APP_ROOT . '/storage/installed.lock');
        @unlink(APP_ROOT . '/storage/installed.lock.tmp');

        if ($this->dbName !== '') {
            try {
                $this->serverPdo()->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }

        foreach (['APP_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_PREFIX'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    public function testFreshInstallationLifecycleCreatesSchemaAdminConfigAndPersistentLock(): void
    {
        $app = new Application();
        $provisioner = new DatabaseProvisioner();
        $state = new InstallationStateManager();
        $service = new InstallationService($app, $provisioner, $state);

        $config = [
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => '3306',
            'database' => $this->dbName,
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => 'fvcms_',
        ];

        $service->install(
            $config,
            ['name' => 'Lifecycle Test Site', 'url' => 'https://cms.canbangla.net/'],
            ['username' => 'owner_admin', 'email' => 'owner@example.com', 'password' => 'StrongPass123']
        );

        self::assertFileExists(APP_ROOT . '/.env');
        self::assertFileExists(APP_ROOT . '/storage/installed.lock');
        self::assertStringContainsString('APP_URL=https://cms.canbangla.net/', (string)file_get_contents(APP_ROOT . '/.env'));
        self::assertStringContainsString('DB_PREFIX=fvcms_', (string)file_get_contents(APP_ROOT . '/.env'));

        $db = new Database($config);
        self::assertTrue($db->tableExists('users'));
        self::assertTrue($db->tableExists('settings'));
        self::assertTrue($state->databaseLooksInstalled($db));

        $user = $db->selectOne("SELECT * FROM `users` WHERE `username` = ? LIMIT 1", ['owner_admin']);
        self::assertNotNull($user);
        self::assertNotSame('StrongPass123', $user->password);
        self::assertTrue(password_verify('StrongPass123', $user->password));

        self::assertTrue($app->isInstalled());
        self::assertInstanceOf(Config::class, $app->make(Config::class));
    }

    private function serverPdo(): PDO
    {
        return new PDO('mysql:host=localhost;port=3306;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
