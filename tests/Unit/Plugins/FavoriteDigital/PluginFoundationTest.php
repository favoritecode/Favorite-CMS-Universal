<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteDigital;

use DateTimeImmutable;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;
use FavoriteCMS\Digital\Support\MembershipPeriodCalculator;
use FavoriteCMS\Digital\Support\OrderLifecycleState;
use FavoriteCMS\Digital\Support\ProductPricingCalculator;
use FavoriteCMS\Plugins\PluginManager;
use PDO;
use PHPUnit\Framework\TestCase;

class PluginFoundationTest extends TestCase
{
    private Application $app;
    private PluginManager $pluginManager;
    private Database $sqliteDb;
    private PDO $sqlitePdo;

    protected function setUp(): void
    {
        $this->app = new Application();

        // In-memory SQLite PDO for isolated test runs
        $this->sqlitePdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);

        $this->sqliteDb = new class($this->sqlitePdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite'];
                $this->prefix = '';
            }
        };

        $this->app->singleton(Database::class, fn () => $this->sqliteDb);

        // Core PluginManager
        $this->pluginManager = new class($this->app) extends PluginManager {
            public function setActivePluginsForTest(array $plugins): void
            {
                $this->activePlugins = $plugins;
            }
        };
    }

    public function testManifestExistsAndHasCorrectMetadata(): void
    {
        $meta = $this->pluginManager->getPluginMetadata('favorite-digital');

        $this->assertSame('favorite-digital', $meta['id']);
        $this->assertSame('Favorite Digital', $meta['name']);
        $this->assertSame('1.0.1', $meta['version']);
        $this->assertSame('Favorite CMS Team', $meta['author']);
        $this->assertSame('plugin.php', $meta['entry_point']);
        $this->assertTrue($meta['valid']);
        $this->assertContains('favorite-pay', $meta['dependencies']);

        $expectedTables = [
            'favorite_digital_products',
            'favorite_digital_product_details',
            'favorite_digital_service_details',
            'favorite_digital_packages',
            'favorite_digital_package_items',
            'favorite_digital_membership_plans',
            'favorite_digital_memberships',
            'favorite_digital_orders',
            'favorite_digital_order_items',
            'favorite_digital_order_payments',
            'favorite_digital_entitlements',
            'favorite_digital_downloads',
            'favorite_digital_wallets',
            'favorite_digital_wallet_transactions',
            'favorite_digital_refunds',
        ];

        $this->assertCount(15, $meta['tables'], 'Plugin manifest must declare exactly 15 locked tables');
        $this->assertSame($expectedTables, $meta['tables']);
        $this->assertSame(FavoriteDigitalPlugin::TABLES, $meta['tables']);
    }

    public function testDependencyValidationFailsWhenFavoritePayInactive(): void
    {
        $this->pluginManager->setActivePluginsForTest([]);
        $validation = $this->pluginManager->validatePlugin('favorite-digital');

        $this->assertFalse($validation['valid']);
        $this->assertNotEmpty($validation['errors']);
        $this->assertStringContainsString('favorite-pay', implode(' ', $validation['errors']));
    }

    public function testDependencyValidationPassesWhenFavoritePayActive(): void
    {
        $this->pluginManager->setActivePluginsForTest(['favorite-pay']);
        $validation = $this->pluginManager->validatePlugin('favorite-digital');

        $this->assertTrue($validation['valid']);
        $this->assertEmpty($validation['errors']);
    }

    public function testPrefixableTablesRegistrationAndResolution(): void
    {
        $customPdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $customDb = new class($customPdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite', 'prefix' => 'fvt_'];
                $this->prefix = 'fvt_';
            }
        };

        $customDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);

        foreach (FavoriteDigitalPlugin::TABLES as $table) {
            $this->assertContains($table, $customDb->getPrefixableTables());
            $this->assertSame('`fvt_' . $table . '`', $customDb->quoteIdentifier($table));
        }
    }

    public function testAllFifteenMigrationsRunSuccessfullyInSqlite(): void
    {
        $this->sqliteDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);

        $migrator = new Migrator($this->sqliteDb);
        $migrationsPath = APP_ROOT . '/plugins/favorite-digital/database/migrations';
        $applied = $migrator->migrate($migrationsPath);

        $this->assertCount(16, $applied, 'Migrator must execute all 16 migrations in sequence');

        foreach (FavoriteDigitalPlugin::TABLES as $table) {
            $this->assertTrue(
                $this->sqliteDb->tableExists($table),
                "Failed asserting that table '{$table}' exists after migrations."
            );
        }
    }

    public function testExpectedColumnsInAllFifteenTables(): void
    {
        $this->sqliteDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);
        $migrator = new Migrator($this->sqliteDb);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');

        $expectedColumnsMap = [
            'favorite_digital_products' => [
                'id', 'title', 'slug', 'description', 'product_type', 'status',
                'original_price', 'discount_percent', 'final_price', 'currency', 'is_free',
                'created_at', 'updated_at',
            ],
            'favorite_digital_product_details' => [
                'id', 'product_id', 'version', 'file_path', 'file_name', 'file_hash',
                'file_size', 'mime_type', 'max_downloads', 'download_expiry_days',
                'is_membership_eligible', 'created_at', 'updated_at',
            ],
            'favorite_digital_service_details' => [
                'id', 'product_id', 'delivery_time_days', 'service_scope', 'requirements_prompt',
                'created_at', 'updated_at',
            ],
            'favorite_digital_packages' => [
                'id', 'product_id', 'package_type', 'total_items_count', 'created_at', 'updated_at',
            ],
            'favorite_digital_package_items' => [
                'id', 'package_id', 'included_product_id', 'sort_order', 'created_at',
            ],
            'favorite_digital_membership_plans' => [
                'id', 'product_id', 'plan_type', 'duration_count', 'duration_unit',
                'grace_period_days', 'allows_auto_renewal', 'created_at', 'updated_at',
            ],
            'favorite_digital_memberships' => [
                'id', 'user_id', 'plan_id', 'status', 'started_at', 'expires_at',
                'grace_expires_at', 'auto_renew', 'created_at', 'updated_at',
            ],
            'favorite_digital_orders' => [
                'id', 'order_number', 'user_id', 'status', 'payment_status', 'fulfillment_status',
                'subtotal_amount', 'discount_amount', 'total_amount', 'currency', 'notes',
                'created_at', 'updated_at',
            ],
            'favorite_digital_order_items' => [
                'id', 'order_id', 'product_id', 'product_type', 'unit_price', 'discount_percent',
                'final_price', 'currency', 'snapshot_data', 'created_at',
            ],
            'favorite_digital_order_payments' => [
                'id', 'order_id', 'payment_method', 'favorite_pay_tx_id', 'wallet_tx_id',
                'amount_paid', 'currency', 'status', 'created_at', 'updated_at',
            ],
            'favorite_digital_entitlements' => [
                'id', 'user_id', 'product_id', 'source_type', 'source_id', 'status',
                'granted_at', 'expires_at', 'created_at', 'updated_at',
            ],
            'favorite_digital_downloads' => [
                'id', 'entitlement_id', 'product_id', 'user_id', 'download_token',
                'ip_address', 'user_agent', 'downloaded_at', 'download_count', 'created_at',
            ],
            'favorite_digital_wallets' => [
                'id', 'user_id', 'balance_amount', 'currency', 'status', 'created_at', 'updated_at',
            ],
            'favorite_digital_wallet_transactions' => [
                'id', 'wallet_id', 'type', 'amount', 'balance_after', 'order_id',
                'reference_id', 'description', 'created_at',
            ],
            'favorite_digital_refunds' => [
                'id', 'order_id', 'order_item_id', 'user_id', 'refund_amount',
                'currency', 'destination', 'wallet_transaction_id', 'reason',
                'status', 'processed_at', 'created_at',
            ],
        ];

        foreach ($expectedColumnsMap as $table => $expectedCols) {
            $stmt = $this->sqlitePdo->query("PRAGMA table_info('{$table}')");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $actualCols = array_column($rows, 'name');

            foreach ($expectedCols as $col) {
                $this->assertContains(
                    $col,
                    $actualCols,
                    "Table '{$table}' is missing expected column '{$col}'"
                );
            }
        }
    }

    public function testProductPricingDerivationRuleAndHistoricalImmutability(): void
    {
        $this->sqliteDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);
        $migrator = new Migrator($this->sqliteDb);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');

        // Test 1: Derivation rules
        $derived1 = ProductPricingCalculator::deriveFinalPrice(1000.00, 20.00);
        $this->assertSame('800.00', $derived1);

        $derived2 = ProductPricingCalculator::deriveFinalPrice(1500.00, 15.50);
        $this->assertSame('1267.50', $derived2);

        $derivedFree = ProductPricingCalculator::deriveFinalPrice(1000.00, 0, true);
        $this->assertSame('0.00', $derivedFree);

        $derivedFullDiscount = ProductPricingCalculator::deriveFinalPrice(1000.00, 100.00);
        $this->assertSame('0.00', $derivedFullDiscount);

        $derivedNoDiscount = ProductPricingCalculator::deriveFinalPrice(500.00, 0.00);
        $this->assertSame('500.00', $derivedNoDiscount);

        // Test 2: Persist product with original_price and final_price
        $finalPrice = ProductPricingCalculator::deriveFinalPrice(1499.50, 15.00);
        $this->sqliteDb->insert('favorite_digital_products', [
            'title'            => 'Universal CMS Pro Theme',
            'slug'             => 'universal-cms-pro-theme',
            'description'      => 'Full responsive pro theme.',
            'product_type'     => 'digital',
            'status'           => 'published',
            'original_price'   => 1499.50,
            'discount_percent' => 15.00,
            'final_price'      => $finalPrice,
            'currency'         => 'BDT',
            'is_free'          => 0,
        ]);

        $prod = $this->sqliteDb->selectOne("SELECT * FROM `favorite_digital_products` WHERE slug = 'universal-cms-pro-theme'");
        $this->assertNotNull($prod);
        $this->assertEquals('1499.50', number_format((float)$prod->original_price, 2, '.', ''));
        $this->assertEquals('15.00', number_format((float)$prod->discount_percent, 2, '.', ''));
        $this->assertEquals('1274.58', number_format((float)$prod->final_price, 2, '.', ''));

        // Test 3: Historical Order Items remain completely immutable
        $snapshot = json_encode([
            'title'          => 'Universal CMS Pro Theme',
            'original_price' => '1499.50',
            'discount_pct'   => '15.00',
            'final_price'    => '1274.58',
            'version'        => '1.0.0',
        ]);

        $this->sqliteDb->insert('favorite_digital_order_items', [
            'order_id'         => 101,
            'product_id'       => (int)$prod->id,
            'product_type'     => 'digital',
            'unit_price'       => 1499.50,
            'discount_percent' => 15.00,
            'final_price'      => 1274.58,
            'currency'         => 'BDT',
            'snapshot_data'    => $snapshot,
        ]);

        // Catalog price now changes in the product table
        $this->sqliteDb->update('favorite_digital_products', [
            'original_price' => 2000.00,
            'final_price'    => 2000.00,
        ], ['id' => $prod->id]);

        // Assert order_item pricing has NOT changed
        $orderItem = $this->sqliteDb->selectOne("SELECT * FROM `favorite_digital_order_items` WHERE order_id = 101");
        $this->assertEquals('1499.50', number_format((float)$orderItem->unit_price, 2, '.', ''));
        $this->assertEquals('1274.58', number_format((float)$orderItem->final_price, 2, '.', ''));
        $this->assertSame($snapshot, $orderItem->snapshot_data);
    }

    public function testDeterministicCalendarMonthArithmeticAndMonthEndCases(): void
    {
        // 1. Standard mid-month transition (Jan 15 -> Feb 15)
        $start1 = new DateTimeImmutable('2026-01-15 10:30:00');
        $expiry1 = MembershipPeriodCalculator::addCalendarMonths($start1, 1);
        $this->assertSame('2026-02-15 10:30:00', $expiry1->format('Y-m-d H:i:s'));

        // 2. Month-end overflow prevention: Jan 31 -> Feb 28 in non-leap year (2026)
        $start2 = new DateTimeImmutable('2026-01-31 23:59:59');
        $expiry2 = MembershipPeriodCalculator::addCalendarMonths($start2, 1);
        $this->assertSame('2026-02-28 23:59:59', $expiry2->format('Y-m-d H:i:s'), 'Jan 31 must clamp to Feb 28 in non-leap year without overflowing to March');

        // 3. Month-end overflow prevention: Jan 31 -> Feb 29 in leap year (2028)
        $startLeap = new DateTimeImmutable('2028-01-31 18:00:00');
        $expiryLeap = MembershipPeriodCalculator::addCalendarMonths($startLeap, 1);
        $this->assertSame('2028-02-29 18:00:00', $expiryLeap->format('Y-m-d H:i:s'), 'Jan 31 must clamp to Feb 29 in leap year');

        // 4. 31st to 30-day month: Mar 31 -> Apr 30
        $startMar = new DateTimeImmutable('2026-03-31 12:00:00');
        $expiryMar = MembershipPeriodCalculator::addCalendarMonths($startMar, 1);
        $this->assertSame('2026-04-30 12:00:00', $expiryMar->format('Y-m-d H:i:s'));

        // 5. 31st to 30-day month: Aug 31 -> Sep 30
        $startAug = new DateTimeImmutable('2026-08-31 15:45:00');
        $expiryAug = MembershipPeriodCalculator::addCalendarMonths($startAug, 1);
        $this->assertSame('2026-09-30 15:45:00', $expiryAug->format('Y-m-d H:i:s'));

        // 6. Year rollover: Dec 31 -> Jan 31 next year
        $startDec = new DateTimeImmutable('2026-12-31 00:00:00');
        $expiryDec = MembershipPeriodCalculator::addCalendarMonths($startDec, 1);
        $this->assertSame('2027-01-31 00:00:00', $expiryDec->format('Y-m-d H:i:s'));

        // 7. Multi-month: Jan 31 + 3 months -> Apr 30
        $expiry3M = MembershipPeriodCalculator::addCalendarMonths($start2, 3);
        $this->assertSame('2026-04-30 23:59:59', $expiry3M->format('Y-m-d H:i:s'));
    }

    public function testMembershipExtensionPreservesActiveTime(): void
    {
        $now = new DateTimeImmutable('2026-05-01 10:00:00');

        // Case A: Member has active membership expiring on 2026-05-20 (20 days remaining)
        $activeExpiry = new DateTimeImmutable('2026-05-20 18:00:00');
        $newExpiry = MembershipPeriodCalculator::calculateExtensionExpiry($activeExpiry, $now, 'month', 1);

        // Must append to activeExpiry: May 20 + 1 calendar month = June 20
        $this->assertSame('2026-06-20 18:00:00', $newExpiry->format('Y-m-d H:i:s'), 'Extension must append to existing active expiration time');

        // Case B: Member is already expired (expired April 15)
        $expiredDate = new DateTimeImmutable('2026-04-15 12:00:00');
        $newExpiryExpired = MembershipPeriodCalculator::calculateExtensionExpiry($expiredDate, $now, 'month', 1);

        // Must start fresh from $now: May 1 + 1 calendar month = June 1
        $this->assertSame('2026-06-01 10:00:00', $newExpiryExpired->format('Y-m-d H:i:s'));

        // Case C: Weekly membership adds 7 days
        $weeklyExpiry = MembershipPeriodCalculator::calculatePeriodExpiry($now, 'week', 1);
        $this->assertSame('2026-05-08 10:00:00', $weeklyExpiry->format('Y-m-d H:i:s'));
    }

    public function testOrthogonalOrderLifecycleTransitions(): void
    {
        $this->sqliteDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);
        $migrator = new Migrator($this->sqliteDb);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');

        // 1. Initial State: Pending Payment
        $this->sqliteDb->insert('favorite_digital_orders', [
            'order_number'       => 'ORD-2026-001',
            'user_id'            => 10,
            'status'             => OrderLifecycleState::STATUS_PENDING,
            'payment_status'     => OrderLifecycleState::PAYMENT_PENDING,
            'fulfillment_status' => OrderLifecycleState::FULFILLMENT_UNFULFILLED,
            'subtotal_amount'    => 1000.00,
            'discount_amount'    => 100.00,
            'total_amount'       => 900.00,
            'currency'           => 'BDT',
        ]);

        $order = $this->sqliteDb->selectOne("SELECT * FROM `favorite_digital_orders` WHERE order_number = 'ORD-2026-001'");
        $this->assertSame('pending', $order->status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('unfulfilled', $order->fulfillment_status);

        // 2. Step 2: Payment Verified (Paid, but still unfulfilled)
        $this->sqliteDb->update('favorite_digital_orders', OrderLifecycleState::onPaymentSuccess(), ['id' => $order->id]);
        $order = $this->sqliteDb->selectOne("SELECT * FROM `favorite_digital_orders` WHERE id = {$order->id}");
        $this->assertSame('processing', $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('unfulfilled', $order->fulfillment_status, 'Fulfillment must remain unfulfilled until entitlements are created');

        // 3. Step 3: Access Granted (Fulfilled)
        $this->sqliteDb->update('favorite_digital_orders', OrderLifecycleState::onFulfillmentSuccess(), ['id' => $order->id]);
        $order = $this->sqliteDb->selectOne("SELECT * FROM `favorite_digital_orders` WHERE id = {$order->id}");
        $this->assertSame('completed', $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('fulfilled', $order->fulfillment_status);

        // 4. Test Payment Failed Path
        $this->sqliteDb->insert('favorite_digital_orders', [
            'order_number'       => 'ORD-2026-002',
            'user_id'            => 11,
            'status'             => OrderLifecycleState::STATUS_PENDING,
            'payment_status'     => OrderLifecycleState::PAYMENT_PENDING,
            'fulfillment_status' => OrderLifecycleState::FULFILLMENT_UNFULFILLED,
            'total_amount'       => 500.00,
            'currency'           => 'BDT',
        ]);
        $failedOrder = $this->sqliteDb->selectOne("SELECT * FROM `favorite_digital_orders` WHERE order_number = 'ORD-2026-002'");
        $this->sqliteDb->update('favorite_digital_orders', OrderLifecycleState::onPaymentFailure(), ['id' => $failedOrder->id]);
        $failedOrder = $this->sqliteDb->selectOne("SELECT * FROM `favorite_digital_orders` WHERE id = {$failedOrder->id}");
        $this->assertSame('failed', $failedOrder->status);
        $this->assertSame('failed', $failedOrder->payment_status);
        $this->assertSame('unfulfilled', $failedOrder->fulfillment_status);

        // 5. Test Refund / Revocation Path
        $this->sqliteDb->update('favorite_digital_orders', OrderLifecycleState::onRefundExecuted(), ['id' => $order->id]);
        $refundedOrder = $this->sqliteDb->selectOne("SELECT * FROM `favorite_digital_orders` WHERE id = {$order->id}");
        $this->assertSame('refunded', $refundedOrder->status);
        $this->assertSame('refunded', $refundedOrder->payment_status);
        $this->assertSame('revoked', $refundedOrder->fulfillment_status);
    }

    public function testPluginBootstrapAndLifecycle(): void
    {
        $plugin = FavoriteDigitalPlugin::bootstrap($this->app);
        $this->assertInstanceOf(FavoriteDigitalPlugin::class, $plugin);
        $this->assertSame($plugin, FavoriteDigitalPlugin::getInstance($this->app));

        $applied = $plugin->runMigrations();
        $this->assertIsArray($applied);

        $plugin->onActivate();
        $plugin->onDeactivate();

        $this->assertTrue(true, 'Lifecycle hooks executed without error');
    }

    public function testMigrationRollbackDown(): void
    {
        $this->sqliteDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);
        $migrator = new Migrator($this->sqliteDb);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');

        $migrationFiles = glob(APP_ROOT . '/plugins/favorite-digital/database/migrations/*.php');
        sort($migrationFiles);
        $reversed = array_reverse($migrationFiles);

        foreach ($reversed as $file) {
            require_once $file;
            $name = basename($file, '.php');
            $parts = explode('_', $name);
            array_shift($parts);
            $className = implode('', array_map('ucfirst', $parts));

            $instance = new $className($this->sqliteDb);
            $instance->down();
        }

        foreach (FavoriteDigitalPlugin::TABLES as $table) {
            $this->assertFalse(
                $this->sqliteDb->tableExists($table),
                "Table '{$table}' must be dropped after down() execution."
            );
        }
    }

    public function testLiveMySQLMigrationExecutionAndCleanup(): void
    {
        try {
            $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=favorite_cms;charset=utf8mb4', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL server not accessible: ' . $e->getMessage());
            return;
        }

        $testPrefix = 'test_fd_';
        $mySqlDb = new class($pdo, $testPrefix) extends Database {
            public function __construct(PDO $pdo, string $prefix)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'mysql', 'prefix' => $prefix];
                $this->prefix = $prefix;
            }
        };

        $mySqlDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);

        // Run migrations
        $migrator = new Migrator($mySqlDb);
        $applied = $migrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');
        $this->assertCount(16, $applied);

        // Verify tables exist in MySQL with prefix
        foreach (FavoriteDigitalPlugin::TABLES as $table) {
            $this->assertTrue(
                $mySqlDb->tableExists($table),
                "MySQL table '{$testPrefix}{$table}' must exist after migration"
            );
        }

        // Cleanup MySQL test tables
        $migrationFiles = glob(APP_ROOT . '/plugins/favorite-digital/database/migrations/*.php');
        sort($migrationFiles);
        foreach (array_reverse($migrationFiles) as $file) {
            require_once $file;
            $name = basename($file, '.php');
            $parts = explode('_', $name);
            array_shift($parts);
            $className = implode('', array_map('ucfirst', $parts));

            $instance = new $className($mySqlDb);
            $instance->down();
        }

        // Verify clean up
        foreach (FavoriteDigitalPlugin::TABLES as $table) {
            $this->assertFalse(
                $mySqlDb->tableExists($table),
                "MySQL table '{$testPrefix}{$table}' must be dropped after cleanup"
            );
        }

        $mySqlDb->execute("DROP TABLE IF EXISTS `cms_migrations`");
    }
}
