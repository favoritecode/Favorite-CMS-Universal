<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Controllers\PaymentAdminController;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Repositories\PaymentAttemptRepository;
use FavoriteCMS\Plugins\PluginManager;
use PDO;
use PHPUnit\Framework\TestCase;

class SharedHostingLifecycleTest extends TestCase
{
    private Application $app;
    private ?string $mysqlDbName = null;

    protected function setUp(): void
    {
        $this->app = new Application();
        FavoritePayPlugin::reset();
    }

    protected function tearDown(): void
    {
        FavoritePayPlugin::reset();
        unset($_SESSION['auth_user_id'], $_SESSION['auth_user_name'], $GLOBALS['_test_current_user']);

        if ($this->mysqlDbName !== null) {
            try {
                $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                $pdo->exec("DROP DATABASE IF EXISTS `{$this->mysqlDbName}`");
            } catch (\Throwable) {
            }
            $this->mysqlDbName = null;
        }
    }

    public function testDatabaseRegisterPrefixableTables(): void
    {
        $db = new Database([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => 'fvcms_d066_',
        ]);

        $this->assertNotContains('favorite_pay_gateways', $db->getPrefixableTables());
        $this->assertSame('favorite_pay_gateways', $db->table('favorite_pay_gateways'));

        // Register Pay tables
        $db->registerPrefixableTables(FavoritePayPlugin::TABLES);

        $this->assertContains('favorite_pay_gateways', $db->getPrefixableTables());
        $this->assertContains('favorite_pay_rates', $db->getPrefixableTables());
        $this->assertSame('fvcms_d066_favorite_pay_gateways', $db->table('favorite_pay_gateways'));
        $this->assertSame('fvcms_d066_favorite_pay_rates', $db->table('favorite_pay_rates'));

        // Registering duplicate tables is idempotent
        $db->registerPrefixableTables(['favorite_pay_gateways']);
        $tables = $db->getPrefixableTables();
        $counts = array_count_values($tables);
        $this->assertSame(1, $counts['favorite_pay_gateways']);
    }

    public function testPluginManagerAutoRegistersManifestTables(): void
    {
        $db = new Database([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => 'test_pfx_',
        ]);
        $this->app->singleton(Database::class, fn() => $db);

        $manager = new PluginManager($this->app);
        $manager->loadPlugin('favorite-pay');

        // Verify tables from plugin.json are now registered with Database
        $this->assertContains('favorite_pay_gateways', $db->getPrefixableTables());
        $this->assertContains('favorite_pay_attempts', $db->getPrefixableTables());
        $this->assertSame('test_pfx_favorite_pay_gateways', $db->table('favorite_pay_gateways'));
    }

    public function testPluginActivationIsDeterministicAndIdempotentOnMySql(): void
    {
        try {
            $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Local MySQL is not running: ' . $e->getMessage());
        }

        $testDb = 'fvcms_idemp_test_' . bin2hex(random_bytes(3));
        $pdo->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        try {
            $prefix = 'fvcms_idemp_';
            $db = new Database([
                'driver'    => 'mysql',
                'host'      => '127.0.0.1',
                'port'      => '3306',
                'database'  => $testDb,
                'username'  => 'root',
                'password'  => '',
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => $prefix,
            ]);
            $this->app->singleton(Database::class, fn() => $db);

            // Run core migrations
            $coreMigrator = new Migrator($db);
            $coreMigrator->migrate(APP_ROOT . '/database/migrations');

            $manager = new PluginManager($this->app);
            $this->assertNotContains('favorite-pay', $manager->getActivePlugins());

            // 1. Activate
            $result = $manager->activatePlugin('favorite-pay');
            $this->assertTrue($result);
            $this->assertContains('favorite-pay', $manager->getActivePlugins());

            // Verify all 7 tables exist with the prefix
            foreach (FavoritePayPlugin::TABLES as $table) {
                $this->assertTrue($db->tableExists($table), "Expected table {$table} to exist");
            }

            // 2. Repeated activation is safe and idempotent
            $secondResult = $manager->activatePlugin('favorite-pay');
            $this->assertTrue($secondResult);
            $this->assertContains('favorite-pay', $manager->getActivePlugins());

            // 3. Deactivate
            $deactivateResult = $manager->deactivatePlugin('favorite-pay');
            $this->assertTrue($deactivateResult);
            $this->assertNotContains('favorite-pay', $manager->getActivePlugins());
        } finally {
            $pdo->exec("DROP DATABASE IF EXISTS `{$testDb}`");
        }
    }

    public function testFailedActivationDoesNotMarkPluginActive(): void
    {
        $manager = new PluginManager($this->app);
        $activeBefore = $manager->getActivePlugins();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Cannot activate plugin 'non-existent-plugin'");

        try {
            $manager->activatePlugin('non-existent-plugin');
        } finally {
            $this->assertSame($activeBefore, $manager->getActivePlugins());
        }
    }

    public function testSharedHostingFullLifecycleOnMySqlWithRealisticPrefix(): void
    {
        try {
            $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Local MySQL is not running: ' . $e->getMessage());
        }

        $this->mysqlDbName = 'fvcms_sh_test_' . bin2hex(random_bytes(3));
        $pdo->exec("CREATE DATABASE `{$this->mysqlDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $prefix = 'fvcms_d066_';
        $config = [
            'driver'    => 'mysql',
            'host'      => '127.0.0.1',
            'port'      => '3306',
            'database'  => $this->mysqlDbName,
            'username'  => 'root',
            'password'  => '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => $prefix,
        ];

        $db = new Database($config);
        $this->app->singleton(Database::class, fn() => $db);

        // 1. Run core migrations
        $coreMigrator = new Migrator($db);
        $coreMigrator->migrate(APP_ROOT . '/database/migrations');

        // 2. Activate Favorite Pay
        $manager = new PluginManager($this->app);
        $activated = $manager->activatePlugin('favorite-pay');
        $this->assertTrue($activated);

        // 3. Inspect actual MySQL tables via information_schema / SHOW TABLES
        $rawTables = $pdo->query("SHOW TABLES FROM `{$this->mysqlDbName}` LIKE '{$prefix}favorite_pay_%'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(7, $rawTables, 'All 7 Pay tables must exist in MySQL with the configured prefix');

        foreach (FavoritePayPlugin::TABLES as $logicalTable) {
            $expectedPrefixed = $prefix . $logicalTable;
            $this->assertContains($expectedPrefixed, $rawTables);
            $this->assertTrue($db->tableExists($logicalTable), "tableExists({$logicalTable}) must return true");
        }

        // 4. Test Repository Queries on Prefixed Tables
        $repo = $this->app->make(PaymentAttemptRepository::class);
        $attemptsData = $repo->listAttempts(['status' => 'all'], 1, 25);
        $this->assertIsArray($attemptsData);
        $this->assertSame(0, $attemptsData['total']);
        $this->assertSame(0, $attemptsData['counts']['all']);

        // 5. Test Admin Controller Response (Simulation of /admin/page/favorite-pay)
        $db->insert('users', [
            'id'       => 1,
            'username' => 'superadmin',
            'name'     => 'Super Administrator',
            'email'    => 'admin@example.com',
            'password' => 'secret',
            'status'   => 'active',
        ]);
        $db->insert('user_roles', [
            'user_id' => 1,
            'role_id' => 1,
        ]);
        $superAdmin = User::find(1);
        $GLOBALS['_test_current_user'] = $superAdmin;
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['auth_user_name'] = 'superadmin';

        $controller = $this->app->make(PaymentAdminController::class);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay']);
        $renderedHtml = $controller->handle($req);

        $this->assertIsString($renderedHtml);
        $this->assertStringContainsString('Favorite Pay', $renderedHtml);
        $this->assertStringContainsString('Manual Payment Verification Queue', $renderedHtml);
        $this->assertStringContainsString('Awaiting Verification', $renderedHtml);
    }
}
