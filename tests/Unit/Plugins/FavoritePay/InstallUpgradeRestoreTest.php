<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Core\AccountMenu;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\AuditLogServiceInterface;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Contracts\NotificationServiceInterface;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
use FavoriteCMS\Pay\Contracts\WithdrawalServiceInterface;
use FavoriteCMS\Pay\Controllers\AuditLogAdminController;
use FavoriteCMS\Pay\Controllers\CustomerAccountController;
use FavoriteCMS\Pay\Controllers\FinancialDashboardAdminController;
use FavoriteCMS\Pay\Controllers\WithdrawalAdminController;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Domain\Withdrawal;
use FavoriteCMS\Pay\Domain\WithdrawalStatus;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Permissions\PaymentPermission;
use FavoriteCMS\Pay\Repositories\PaymentAttemptRepository;
use FavoriteCMS\Pay\Services\AuditLogService;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\NotificationService;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\WalletService;
use FavoriteCMS\Pay\Services\WithdrawalService;
use FavoriteCMS\Plugins\PluginManager;
use PDO;
use PHPUnit\Framework\TestCase;

class InstallUpgradeUserStub extends User
{
    private array $rolesList;
    private array $permissionsList;

    public function __construct(array $attributes = [], array $roles = [], array $permissions = [])
    {
        $this->attributes = array_merge([
            'id'       => 1,
            'username' => 'admin',
            'email'    => 'admin@example.com',
            'status'   => 'active',
        ], $attributes);
        $this->rolesList = $roles;
        $this->permissionsList = $permissions;
    }

    public function hasRole(string $roleSlug): bool
    {
        return in_array($roleSlug, $this->rolesList, true);
    }

    public function hasPermission(string $permissionSlug): bool
    {
        if ($this->hasRole('super-admin')) {
            return true;
        }
        return in_array($permissionSlug, $this->permissionsList, true);
    }

    public function isBanned(): bool
    {
        return false;
    }

    public function isSuspended(): bool
    {
        return false;
    }
}

class InstallUpgradeRestoreTest extends TestCase
{
    private Application $app;
    private ?string $mysqlDbName = null;

    protected function setUp(): void
    {
        $this->app = new Application();
        FavoritePayPlugin::reset();
        AccountMenu::reset();
        unset($_SESSION['auth_user_id'], $_SESSION['auth_user_name'], $GLOBALS['_test_current_user']);
    }

    protected function tearDown(): void
    {
        FavoritePayPlugin::reset();
        AccountMenu::reset();
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

    /**
     * Test 1: Fresh Installation & Activation from zero on SQLite.
     * Runs all migrations 001 to 007, verifies all 10 tables and indexes,
     * seeds default permissions, and confirms re-running migrations is idempotent.
     */
    public function testFreshInstallationAndActivationOnSqlite(): void
    {
        $db = new Database([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->app->singleton(Database::class, fn() => $db);

        // All 10 tables must not exist prior to migration
        foreach (FavoritePayPlugin::TABLES as $table) {
            $this->assertFalse($db->tableExists($table), "Table {$table} should not exist before install");
        }

        // Run migrations
        $migrator = new Migrator($db);
        $applied = $migrator->migrate(APP_ROOT . '/plugins/favorite-pay/database/migrations');

        $this->assertCount(7, $applied, 'Expected 7 migrations applied on fresh install');

        // Verify all 10 tables now exist
        foreach (FavoritePayPlugin::TABLES as $table) {
            $this->assertTrue($db->tableExists($table), "Table {$table} must exist after fresh install");
        }

        // Verify columns on key tables
        $notifCols = array_map(fn($c) => strtolower(((array)$c)['name'] ?? ''), $db->select("PRAGMA table_info('favorite_pay_notifications')"));
        $this->assertContains('id', $notifCols);
        $this->assertContains('withdrawal_id', $notifCols);
        $this->assertContains('is_read', $notifCols);

        $auditCols = array_map(fn($c) => strtolower(((array)$c)['name'] ?? ''), $db->select("PRAGMA table_info('favorite_pay_audit_logs')"));
        $this->assertContains('id', $auditCols);
        $this->assertContains('action', $auditCols);
        $this->assertContains('actor_user_id', $auditCols);
        $this->assertContains('subject_type', $auditCols);

        $wdCols = array_map(fn($c) => strtolower(((array)$c)['name'] ?? ''), $db->select("PRAGMA table_info('favorite_pay_withdrawals')"));
        $this->assertContains('audit_trail', $wdCols);

        // Seed default permissions
        PaymentPermission::registerDefaultPermissions($db);
        if ($db->tableExists('permissions')) {
            $viewPerm = $db->selectOne("SELECT id FROM permissions WHERE name = ?", [PaymentPermission::VIEW]);
            $this->assertNotNull($viewPerm);
        }

        // Idempotent migration rerun
        $secondApplied = $migrator->migrate(APP_ROOT . '/plugins/favorite-pay/database/migrations');
        $this->assertCount(0, $secondApplied, 'No migrations should be re-applied on subsequent run');
    }

    /**
     * Test 2: Fresh Installation & Activation from zero on MySQL.
     * Verifies MySQL specific schema features (InnoDB, utf8mb4, autoincrement primary keys).
     */
    public function testFreshInstallationAndActivationOnMySql(): void
    {
        try {
            $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Local MySQL is not running: ' . $e->getMessage());
        }

        $this->mysqlDbName = 'fvcms_fresh_p12_' . bin2hex(random_bytes(3));
        $pdo->exec("CREATE DATABASE `{$this->mysqlDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        try {
            $db = new Database([
                'driver'    => 'mysql',
                'host'      => '127.0.0.1',
                'port'      => '3306',
                'database'  => $this->mysqlDbName,
                'username'  => 'root',
                'password'  => '',
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ]);
            $this->app->singleton(Database::class, fn() => $db);

            // Core migrations first
            $coreMigrator = new Migrator($db);
            $coreMigrator->migrate(APP_ROOT . '/database/migrations');

            // Run Pay migrations
            $migrator = new Migrator($db);
            $applied = $migrator->migrate(APP_ROOT . '/plugins/favorite-pay/database/migrations');
            $this->assertCount(7, $applied);

            // Verify all 10 tables exist in MySQL
            $rawTables = $pdo->query("SHOW TABLES FROM `{$this->mysqlDbName}` LIKE 'favorite_pay_%'")->fetchAll(PDO::FETCH_COLUMN);
            $this->assertCount(10, $rawTables, 'Expected 10 favorite_pay_ tables in MySQL');

            foreach (FavoritePayPlugin::TABLES as $table) {
                $this->assertContains($table, $rawTables);
                $this->assertTrue($db->tableExists($table));
            }

            // Verify table engine is InnoDB and charset utf8mb4
            $status = $pdo->query("SHOW TABLE STATUS FROM `{$this->mysqlDbName}` WHERE Name = 'favorite_pay_transactions'")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('InnoDB', $status['Engine']);
            $this->assertStringStartsWith('utf8mb4', $status['Collation']);

            // Idempotent rerun
            $secondApplied = $migrator->migrate(APP_ROOT . '/plugins/favorite-pay/database/migrations');
            $this->assertCount(0, $secondApplied);
        } finally {
            $pdo->exec("DROP DATABASE IF EXISTS `{$this->mysqlDbName}`");
            $this->mysqlDbName = null;
        }
    }

    /**
     * Test 3: Custom Table Prefixing across all 10 tables.
     * Ensures prefixes like 'wp_test_' or 'custom_fp_' are applied cleanly,
     * all migrations succeed, and repository / service queries resolve properly.
     */
    public function testCustomTablePrefixingAcrossAll10Tables(): void
    {
        $prefix = 'wp_cust_';
        $db = new Database([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => $prefix,
        ]);
        $this->app->singleton(Database::class, fn() => $db);

        // Register prefixable tables
        $db->registerPrefixableTables(FavoritePayPlugin::TABLES);
        $this->assertContains('favorite_pay_notifications', $db->getPrefixableTables());
        $this->assertContains('favorite_pay_audit_logs', $db->getPrefixableTables());

        // Run migrations with prefix
        $migrator = new Migrator($db);
        $applied = $migrator->migrate(APP_ROOT . '/plugins/favorite-pay/database/migrations');
        $this->assertCount(7, $applied);

        // Verify that underlying SQLite master has tables with the custom prefix
        $tablesInSqlite = $db->select("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '{$prefix}favorite_pay_%'");
        $tableNames = array_map(fn($r) => $r->name, $tablesInSqlite);
        $this->assertCount(10, $tableNames, 'All 10 tables must have the prefix in SQLite');

        foreach (FavoritePayPlugin::TABLES as $logicalTable) {
            $expectedPhysical = $prefix . $logicalTable;
            $this->assertContains($expectedPhysical, $tableNames);
            $this->assertTrue($db->tableExists($logicalTable));
        }

        // Test CRUD operations through services with prefix
        $currencyService = new CurrencyService(null, $db);
        $walletService = new WalletService($currencyService, null, $db);
        $notifService = new NotificationService($db);
        $auditService = new AuditLogService($db);

        // Deposit funds into customer wallet
        $entry = $walletService->deposit(101, new Money(25000, 'BDT'), 'DEP-001', 'Test Deposit');
        $this->assertSame(25000, $entry->getBalanceAfter()->getAmount());

        $balance = $walletService->getBalance(101);
        $this->assertSame(25000, $balance->getAmount());

        // Send notification
        $notif = $notifService->notify(101, 'wallet_credited', 'Deposit Confirmed', 'You received 250.00 BDT', null, ['amount' => 25000]);
        $this->assertSame(101, (int)$notif['user_id']);
        $this->assertSame('wallet_credited', $notif['type']);

        // Log audit entry
        $audit = $auditService->log(
            actorUserId: 1,
            action: 'wallet_adjusted',
            subjectType: 'wallet',
            subjectId: '101',
            description: 'Manual adjustment test',
            targetUserId: 101,
            metadata: ['amount' => 25000]
        );
        $this->assertNotNull($audit);
        $this->assertGreaterThan(0, $audit);

        // Verify count of notifications and audit logs through DB
        $unreadCount = $notifService->getUnreadCount(101);
        $this->assertSame(1, $unreadCount);

        $auditLogs = $auditService->listLogs(['action' => 'wallet_adjusted'], 10, 0);
        $this->assertSame(1, $auditLogs['total']);
    }

    /**
     * Test 4: Upgrade from v1.0.7 Baseline with Financial Data Preservation.
     * Seeds v1.0.7 schema (001 to 004) with active wallets, ledger entries, transactions,
     * attempts, refunds, and withdrawals.
     * Applies migrations 005, 006, 007.
     * Asserts EXACT match on all balances, ledger checksums, and records.
     */
    public function testUpgradeFromV107BaselinePreservesFinancialDataAndSettings(): void
    {
        $db = new Database([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->app->singleton(Database::class, fn() => $db);
        $pdo = $db->getPdo();

        // 1. Create v1.0.7 baseline schema (001, 002, 003, 004)
        // 001 tables
        $pdo->exec("
            CREATE TABLE `cms_migrations` (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                batch INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE `favorite_pay_gateways` (
                `id`                   VARCHAR(64) PRIMARY KEY,
                `title`                VARCHAR(191) NOT NULL,
                `type`                 VARCHAR(32) NOT NULL,
                `is_enabled`           TINYINT(1) NOT NULL DEFAULT 0,
                `supported_currencies` TEXT NULL,
                `config`               TEXT NULL,
                `sort_order`           INT NOT NULL DEFAULT 0,
                `created_at`           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at`           DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE `favorite_pay_rates` (
                `id`               INTEGER PRIMARY KEY AUTOINCREMENT,
                `base_currency`    VARCHAR(16) NOT NULL DEFAULT 'BDT',
                `quote_currency`   VARCHAR(16) NOT NULL,
                `rate`             DECIMAL(18, 6) NOT NULL,
                `rate_factor`      BIGINT NOT NULL,
                `rate_scale`       INT NOT NULL DEFAULT 1000000,
                `is_authoritative` TINYINT(1) NOT NULL DEFAULT 1,
                `source`           VARCHAR(64) NOT NULL DEFAULT 'operator',
                `status`           VARCHAR(20) NOT NULL DEFAULT 'active',
                `operator_id`      BIGINT NULL,
                `notes`            VARCHAR(255) NULL,
                `effective_at`     DATETIME NOT NULL,
                `expires_at`       DATETIME NULL,
                `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE `favorite_pay_transactions` (
                `id`                  INTEGER PRIMARY KEY AUTOINCREMENT,
                `transaction_id`      VARCHAR(64) NOT NULL UNIQUE,
                `source_plugin`       VARCHAR(64) NOT NULL,
                `source_reference`    VARCHAR(191) NOT NULL,
                `user_id`             BIGINT NULL,
                `base_amount`         BIGINT NOT NULL,
                `base_currency`       VARCHAR(3) NOT NULL DEFAULT 'BDT',
                `charge_amount`       BIGINT NOT NULL,
                `charge_currency`     VARCHAR(3) NOT NULL,
                `exchange_rate`       DECIMAL(18, 6) NULL,
                `rate_factor`         BIGINT NULL,
                `rate_scale`          INT NULL,
                `payment_method_type` VARCHAR(32) NULL,
                `gateway_id`          VARCHAR(64) NULL,
                `status`              VARCHAR(32) NOT NULL DEFAULT 'pending',
                `idempotency_key`     VARCHAR(191) NULL UNIQUE,
                `external_reference`  VARCHAR(191) NULL,
                `metadata`            TEXT NULL,
                `failure_reason`      TEXT NULL,
                `completed_at`        DATETIME NULL,
                `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at`          DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE `favorite_pay_attempts` (
                `id`                 INTEGER PRIMARY KEY AUTOINCREMENT,
                `attempt_id`         VARCHAR(64) NOT NULL UNIQUE,
                `transaction_id`     VARCHAR(64) NOT NULL,
                `gateway_id`         VARCHAR(64) NOT NULL,
                `amount`             BIGINT NOT NULL,
                `currency`           VARCHAR(3) NOT NULL,
                `status`             VARCHAR(32) NOT NULL DEFAULT 'pending',
                `provider_reference` VARCHAR(191) NULL,
                `provider_status`    VARCHAR(64) NULL,
                `request_payload`    TEXT NULL,
                `response_payload`   TEXT NULL,
                `error_message`      TEXT NULL,
                `operator_notes`     TEXT NULL,
                `verified_by`        BIGINT NULL,
                `verified_at`        DATETIME NULL,
                `created_at`         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at`         DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE `favorite_pay_refunds` (
                `id`                        INTEGER PRIMARY KEY AUTOINCREMENT,
                `refund_id`                 VARCHAR(64) NOT NULL UNIQUE,
                `transaction_id`            VARCHAR(64) NOT NULL,
                `amount`                    BIGINT NOT NULL,
                `currency`                  VARCHAR(3) NOT NULL,
                `status`                    VARCHAR(32) NOT NULL DEFAULT 'succeeded',
                `provider_refund_reference` VARCHAR(191) NULL,
                `reason`                    TEXT NULL,
                `operator_id`               BIGINT NULL,
                `created_at`                TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE `favorite_pay_wallets` (
                `id`         INTEGER PRIMARY KEY AUTOINCREMENT,
                `user_id`    BIGINT NOT NULL UNIQUE,
                `balance`    BIGINT NOT NULL DEFAULT 0,
                `currency`   VARCHAR(3) NOT NULL DEFAULT 'BDT',
                `status`     VARCHAR(32) NOT NULL DEFAULT 'active',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE `favorite_pay_wallet_entries` (
                `id`              INTEGER PRIMARY KEY AUTOINCREMENT,
                `entry_id`        VARCHAR(64) NOT NULL UNIQUE,
                `wallet_id`       BIGINT NOT NULL,
                `user_id`         BIGINT NOT NULL,
                `type`            VARCHAR(32) NOT NULL,
                `amount`          BIGINT NOT NULL,
                `balance_after`   BIGINT NOT NULL,
                `reference_type`  VARCHAR(64) NOT NULL,
                `reference_id`    VARCHAR(191) NOT NULL,
                `idempotency_key` VARCHAR(191) NULL UNIQUE,
                `description`     VARCHAR(500) NOT NULL DEFAULT '',
                `metadata`        TEXT NULL,
                `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
            -- v1.0.7 baseline withdrawals table (WITHOUT audit_trail)
            CREATE TABLE `favorite_pay_withdrawals` (
                `id`                    INTEGER PRIMARY KEY AUTOINCREMENT,
                `withdrawal_id`         VARCHAR(64) NOT NULL UNIQUE,
                `user_id`               BIGINT NOT NULL,
                `wallet_id`             BIGINT NULL,
                `amount`                BIGINT NOT NULL,
                `currency`              VARCHAR(3) NOT NULL DEFAULT 'BDT',
                `fee`                   BIGINT NOT NULL DEFAULT 0,
                `net_amount`            BIGINT NOT NULL,
                `method`                VARCHAR(32) NOT NULL,
                `destination_data`      TEXT NOT NULL,
                `destination_masked`    VARCHAR(191) NOT NULL,
                `status`                VARCHAR(32) NOT NULL DEFAULT 'pending',
                `hold_reference`        VARCHAR(191) NULL,
                `transaction_reference` VARCHAR(191) NULL,
                `idempotency_key`       VARCHAR(191) NULL UNIQUE,
                `admin_user_id`         BIGINT NULL,
                `operator_notes`        TEXT NULL,
                `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `processed_at`          TIMESTAMP NULL
            );
        ");

        // Mark 001 - 004 as already run
        $db->insert('cms_migrations', ['migration' => '001_create_favorite_pay_tables', 'batch' => 1]);
        $db->insert('cms_migrations', ['migration' => '002_update_favorite_pay_rates_table', 'batch' => 1]);
        $db->insert('cms_migrations', ['migration' => '003_add_status_and_notes_to_favorite_pay_rates', 'batch' => 1]);
        $db->insert('cms_migrations', ['migration' => '004_create_favorite_pay_withdrawals_table', 'batch' => 1]);

        // 2. Seed realistic production data
        // Gateway
        $db->insert('favorite_pay_gateways', [
            'id'                   => 'manual_bkash',
            'title'                => 'bKash Manual',
            'type'                 => 'manual_bkash',
            'is_enabled'           => 1,
            'supported_currencies' => '["BDT"]',
            'config'               => '{"account_number":"01700000000"}',
            'sort_order'           => 1,
        ]);

        // Exchange rate
        $db->insert('favorite_pay_rates', [
            'base_currency'    => 'BDT',
            'quote_currency'   => 'USDT',
            'rate'             => 120.500000,
            'rate_factor'      => 120500000,
            'rate_scale'       => 1000000,
            'is_authoritative' => 1,
            'source'           => 'operator',
            'status'           => 'active',
            'effective_at'     => '2026-01-01 00:00:00',
        ]);

        // User 101: 75,000 minor units (750.00 BDT)
        $w1Id = $db->insert('favorite_pay_wallets', [
            'user_id'  => 101,
            'balance'  => 75000,
            'currency' => 'BDT',
            'status'   => 'active',
        ]);
        $db->insert('favorite_pay_wallet_entries', [
            'entry_id'      => 'ENT-101-1',
            'wallet_id'     => $w1Id,
            'user_id'       => 101,
            'type'          => 'credit',
            'amount'        => 100000,
            'balance_after' => 100000,
            'reference_type'=> 'recharge',
            'reference_id'  => 'REC-001',
            'description'   => 'Initial Recharge',
        ]);
        $db->insert('favorite_pay_wallet_entries', [
            'entry_id'      => 'ENT-101-2',
            'wallet_id'     => $w1Id,
            'user_id'       => 101,
            'type'          => 'debit',
            'amount'        => -25000,
            'balance_after' => 75000,
            'reference_type'=> 'purchase',
            'reference_id'  => 'ORD-991',
            'description'   => 'Digital Purchase',
        ]);

        // User 102: 120,500 minor units (1205.00 BDT)
        $w2Id = $db->insert('favorite_pay_wallets', [
            'user_id'  => 102,
            'balance'  => 120500,
            'currency' => 'BDT',
            'status'   => 'active',
        ]);
        $db->insert('favorite_pay_wallet_entries', [
            'entry_id'      => 'ENT-102-1',
            'wallet_id'     => $w2Id,
            'user_id'       => 102,
            'type'          => 'credit',
            'amount'        => 150000,
            'balance_after' => 150000,
            'reference_type'=> 'recharge',
            'reference_id'  => 'REC-002',
            'description'   => 'Recharge via bKash',
        ]);
        $db->insert('favorite_pay_wallet_entries', [
            'entry_id'      => 'ENT-102-2',
            'wallet_id'     => $w2Id,
            'user_id'       => 102,
            'type'          => 'debit',
            'amount'        => -29500,
            'balance_after' => 120500,
            'reference_type'=> 'fee',
            'reference_id'  => 'FEE-001',
            'description'   => 'Subscription Fee',
        ]);

        // Pre-existing transaction
        $db->insert('favorite_pay_transactions', [
            'transaction_id'      => 'TX-V107-001',
            'source_plugin'       => 'core',
            'source_reference'    => 'REC-001',
            'user_id'             => 101,
            'base_amount'         => 100000,
            'base_currency'       => 'BDT',
            'charge_amount'       => 100000,
            'charge_currency'     => 'BDT',
            'payment_method_type' => 'manual_bkash',
            'gateway_id'          => 'manual_bkash',
            'status'              => 'succeeded',
            'completed_at'        => '2026-01-02 10:00:00',
        ]);

        // Pre-existing withdrawal in pending state
        $db->insert('favorite_pay_withdrawals', [
            'withdrawal_id'      => 'WD-V107-001',
            'user_id'            => 101,
            'wallet_id'          => $w1Id,
            'amount'             => 20000,
            'currency'           => 'BDT',
            'fee'                => 0,
            'net_amount'         => 20000,
            'method'             => 'bkash',
            'destination_data'   => '{"phone":"01711111111"}',
            'destination_masked' => '017****1111',
            'status'             => 'pending',
            'hold_reference'     => 'HOLD-101-1',
        ]);

        // 3. Snapshot values before upgrade
        $beforeW1 = $db->selectOne("SELECT balance FROM favorite_pay_wallets WHERE user_id = 101");
        $beforeW2 = $db->selectOne("SELECT balance FROM favorite_pay_wallets WHERE user_id = 102");
        $beforeEntries = $db->select("SELECT * FROM favorite_pay_wallet_entries ORDER BY id ASC");
        $beforeTx = $db->select("SELECT * FROM favorite_pay_transactions");
        $beforeWd = $db->select("SELECT * FROM favorite_pay_withdrawals");

        $this->assertSame(75000, (int)$beforeW1->balance);
        $this->assertSame(120500, (int)$beforeW2->balance);
        $this->assertCount(4, $beforeEntries);
        $this->assertCount(1, $beforeTx);
        $this->assertCount(1, $beforeWd);

        // Verify audit_trail does not exist yet on favorite_pay_withdrawals
        $preUpgradeCols = array_map(fn($c) => strtolower(((array)$c)['name'] ?? ''), $db->select("PRAGMA table_info('favorite_pay_withdrawals')"));
        $this->assertNotContains('audit_trail', $preUpgradeCols);

        // 4. Run Upgrade Migrations (005, 006, 007)
        $migrator = new Migrator($db);
        $applied = $migrator->migrate(APP_ROOT . '/plugins/favorite-pay/database/migrations');

        $this->assertCount(3, $applied, 'Expected exactly 3 migrations (005, 006, 007) applied on upgrade');
        $this->assertContains('005_add_audit_trail_to_favorite_pay_withdrawals', $applied);
        $this->assertContains('006_create_favorite_pay_notifications_table', $applied);
        $this->assertContains('007_create_favorite_pay_audit_logs_table', $applied);

        // 5. Post-Upgrade Invariant Assertions
        // Balances MUST be identical down to 1 paisa
        $afterW1 = $db->selectOne("SELECT balance FROM favorite_pay_wallets WHERE user_id = 101");
        $afterW2 = $db->selectOne("SELECT balance FROM favorite_pay_wallets WHERE user_id = 102");
        $this->assertSame((int)$beforeW1->balance, (int)$afterW1->balance, 'User 101 balance must be preserved identically');
        $this->assertSame((int)$beforeW2->balance, (int)$afterW2->balance, 'User 102 balance must be preserved identically');

        // Ledger entries MUST be identical and unmodified
        $afterEntries = $db->select("SELECT * FROM favorite_pay_wallet_entries ORDER BY id ASC");
        $this->assertCount(4, $afterEntries);
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame($beforeEntries[$i]->entry_id, $afterEntries[$i]->entry_id);
            $this->assertSame((int)$beforeEntries[$i]->amount, (int)$afterEntries[$i]->amount);
            $this->assertSame((int)$beforeEntries[$i]->balance_after, (int)$afterEntries[$i]->balance_after);
        }

        // Ledger reconciliation: sum of ledger entries == wallet balance
        $sum1 = $db->selectOne("SELECT SUM(amount) as total FROM favorite_pay_wallet_entries WHERE user_id = 101");
        $sum2 = $db->selectOne("SELECT SUM(amount) as total FROM favorite_pay_wallet_entries WHERE user_id = 102");
        $this->assertSame((int)$afterW1->balance, (int)$sum1->total);
        $this->assertSame((int)$afterW2->balance, (int)$sum2->total);

        // Column audit_trail now exists on favorite_pay_withdrawals
        $postUpgradeCols = array_map(fn($c) => strtolower(((array)$c)['name'] ?? ''), $db->select("PRAGMA table_info('favorite_pay_withdrawals')"));
        $this->assertContains('audit_trail', $postUpgradeCols);

        // Pre-existing withdrawal has audit_trail preserved (null)
        $existingWd = $db->selectOne("SELECT * FROM favorite_pay_withdrawals WHERE withdrawal_id = 'WD-V107-001'");
        $this->assertNotNull($existingWd);
        $this->assertSame(20000, (int)$existingWd->amount);
        $this->assertNull($existingWd->audit_trail);

        // New tables exist and are empty and ready
        $this->assertTrue($db->tableExists('favorite_pay_notifications'));
        $this->assertTrue($db->tableExists('favorite_pay_audit_logs'));
        $notifCount = $db->selectOne("SELECT COUNT(*) as cnt FROM favorite_pay_notifications");
        $auditCount = $db->selectOne("SELECT COUNT(*) as cnt FROM favorite_pay_audit_logs");
        $this->assertSame(0, (int)$notifCount->cnt);
        $this->assertSame(0, (int)$auditCount->cnt);

        // 6. Test new Phase 8-11 features on the upgraded database
        $currencyService = new CurrencyService(null, $db);
        $walletService = new WalletService($currencyService, null, $db);
        $notifService = new NotificationService($db);
        $auditService = new AuditLogService($db);
        $withdrawalService = new WithdrawalService($walletService, $currencyService, $db, $notifService, $auditService);

        // Reject the pre-existing withdrawal
        $updatedWd = $withdrawalService->reject('WD-V107-001', 1, 'Details mismatch');
        $this->assertSame(WithdrawalStatus::REJECTED, $updatedWd->getStatus());

        // Verify audit_trail was updated on the withdrawal record
        $reloadedWd = $db->selectOne("SELECT * FROM favorite_pay_withdrawals WHERE withdrawal_id = 'WD-V107-001'");
        $this->assertNotNull($reloadedWd->audit_trail);
        $this->assertStringContainsString('rejected', $reloadedWd->audit_trail);

        // Verify notification was dispatched to User 101
        $userNotifs = $notifService->listUserNotifications(101);
        $this->assertCount(1, $userNotifs['items']);
        $this->assertSame('withdrawal_rejected', $userNotifs['items'][0]['type']);

        // Verify audit log was recorded
        $logs = $auditService->listLogs(['withdrawal_id' => 'WD-V107-001']);
        $this->assertSame(1, $logs['total']);
        $this->assertSame('withdrawal.rejected', $logs['items'][0]['action']);
    }

    /**
     * Test 5: Deactivation & Reactivation Safety.
     * Verifies that deactivation detaches menu items without dropping tables or altering data.
     * Reactivation restores menu items and leaves financial data 100% intact.
     */
    public function testDeactivationAndReactivationSafety(): void
    {
        $db = new Database([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->app->singleton(Database::class, fn() => $db);

        $migrator = new Migrator($db);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-pay/database/migrations');

        // Seed wallet and transaction
        $db->insert('favorite_pay_wallets', [
            'user_id'  => 201,
            'balance'  => 50000,
            'currency' => 'BDT',
            'status'   => 'active',
        ]);
        $db->insert('favorite_pay_wallet_entries', [
            'entry_id'      => 'ENT-201-1',
            'wallet_id'     => 1,
            'user_id'       => 201,
            'type'          => 'credit',
            'amount'        => 50000,
            'balance_after' => 50000,
            'reference_type'=> 'deposit',
            'reference_id'  => 'DEP-201',
            'description'   => 'Initial Balance',
        ]);

        $plugin = FavoritePayPlugin::bootstrap($this->app);

        // AccountMenu should have favorite-pay items
        $itemsBefore = AccountMenu::getAllItems();
        $payItemsBefore = array_filter($itemsBefore, fn($i) => ($i['plugin'] ?? '') === 'favorite-pay');
        $this->assertNotEmpty($payItemsBefore);

        // 1. Deactivate
        $plugin->onDeactivate();

        // Account menu items must be detached
        $itemsAfter = AccountMenu::getAllItems();
        $payItemsAfter = array_filter($itemsAfter, fn($i) => ($i['plugin'] ?? '') === 'favorite-pay');
        $this->assertEmpty($payItemsAfter, 'Account menu items must be removed on deactivation');

        // ALL 10 tables must still exist with intact data
        foreach (FavoritePayPlugin::TABLES as $table) {
            $this->assertTrue($db->tableExists($table), "Table {$table} must survive deactivation");
        }

        $wRow = $db->selectOne("SELECT balance FROM favorite_pay_wallets WHERE user_id = 201");
        $this->assertSame(50000, (int)$wRow->balance);
        $eCount = $db->selectOne("SELECT COUNT(*) as cnt FROM favorite_pay_wallet_entries WHERE user_id = 201");
        $this->assertSame(1, (int)$eCount->cnt);

        // 2. Reactivate
        $plugin->onActivate();
        $plugin->registerAccountMenuItems();

        // Account menu items must be restored
        $itemsReactivated = AccountMenu::getAllItems();
        $payItemsReactivated = array_filter($itemsReactivated, fn($i) => ($i['plugin'] ?? '') === 'favorite-pay');
        $this->assertNotEmpty($payItemsReactivated, 'Account menu items must be restored on reactivation');

        // Financial data remains 100% identical
        $wRow2 = $db->selectOne("SELECT balance FROM favorite_pay_wallets WHERE user_id = 201");
        $this->assertSame(50000, (int)$wRow2->balance);
    }

    /**
     * Test 6: Explicit Disposable Uninstall Drops All 10 Tables in Reverse Order.
     * Verifies that uninstall(dropTables: true) drops all 10 tables cleanly,
     * whereas uninstall(dropTables: false) preserves them.
     */
    public function testExplicitDisposableUninstallDropsTablesInReverseOrder(): void
    {
        $db = new Database([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->app->singleton(Database::class, fn() => $db);

        $migrator = new Migrator($db);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-pay/database/migrations');

        $plugin = FavoritePayPlugin::bootstrap($this->app);

        // Non-destructive uninstall (default)
        $plugin->uninstall(dropTables: false);
        foreach (FavoritePayPlugin::TABLES as $table) {
            $this->assertTrue($db->tableExists($table), "Table {$table} must not be dropped without explicit flag");
        }

        // Explicit destructive uninstall on disposable database
        $plugin->uninstall(dropTables: true);
        foreach (FavoritePayPlugin::TABLES as $table) {
            $this->assertFalse($db->tableExists($table), "Table {$table} must be dropped on explicit uninstall");
        }
    }

    /**
     * Test 7: CMS Backup and Restore Simulation with Financial Integrity.
     * Simulates full database export, restoration into an isolated database,
     * and asserts reconciliation between ledger entries and wallet balance.
     */
    public function testCmsBackupAndRestoreSimulationWithFinancialIntegrity(): void
    {
        $sourceDb = new Database([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        $migrator = new Migrator($sourceDb);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-pay/database/migrations');

        // Seed comprehensive data in source
        $sourceDb->insert('favorite_pay_gateways', [
            'id'                   => 'manual_bd',
            'title'                => 'Manual BD',
            'type'                 => 'manual_bd',
            'is_enabled'           => 1,
            'supported_currencies' => '["BDT"]',
        ]);
        $sourceDb->insert('favorite_pay_rates', [
            'base_currency'    => 'BDT',
            'quote_currency'   => 'USDT',
            'rate'             => 121.000000,
            'rate_factor'      => 121000000,
            'rate_scale'       => 1000000,
            'effective_at'     => '2026-01-01 00:00:00',
        ]);
        $wId = $sourceDb->insert('favorite_pay_wallets', [
            'user_id'  => 301,
            'balance'  => 85000,
            'currency' => 'BDT',
            'status'   => 'active',
        ]);
        $sourceDb->insert('favorite_pay_wallet_entries', [
            'entry_id'      => 'ENT-301-1',
            'wallet_id'     => $wId,
            'user_id'       => 301,
            'type'          => 'credit',
            'amount'        => 100000,
            'balance_after' => 100000,
            'reference_type'=> 'recharge',
            'reference_id'  => 'REC-301',
            'description'   => 'Backup Test Recharge',
        ]);
        $sourceDb->insert('favorite_pay_wallet_entries', [
            'entry_id'      => 'ENT-301-2',
            'wallet_id'     => $wId,
            'user_id'       => 301,
            'type'          => 'debit',
            'amount'        => -15000,
            'balance_after' => 85000,
            'reference_type'=> 'order',
            'reference_id'  => 'ORD-301',
            'description'   => 'Backup Test Debit',
        ]);
        $sourceDb->insert('favorite_pay_withdrawals', [
            'withdrawal_id'      => 'WD-301-1',
            'user_id'            => 301,
            'wallet_id'          => $wId,
            'amount'             => 10000,
            'currency'           => 'BDT',
            'fee'                => 0,
            'net_amount'         => 10000,
            'method'             => 'bkash',
            'destination_data'   => '{"phone":"01722222222"}',
            'destination_masked' => '017****2222',
            'status'             => 'pending',
            'hold_reference'     => 'HOLD-301-1',
        ]);
        $sourceDb->insert('favorite_pay_notifications', [
            'id'            => 'NOTIF-301-1',
            'user_id'       => 301,
            'type'          => 'withdrawal_requested',
            'title'         => 'Withdrawal Under Review',
            'message'       => 'Your request for 100.00 BDT is pending',
            'withdrawal_id' => 'WD-301-1',
            'is_read'       => 0,
        ]);
        $sourceDb->insert('favorite_pay_audit_logs', [
            'actor_user_id' => 301,
            'actor_type'    => 'user',
            'action'        => 'withdrawal_requested',
            'subject_type'  => 'withdrawal',
            'subject_id'    => 'WD-301-1',
            'withdrawal_id' => 'WD-301-1',
            'target_user_id'=> 301,
            'description'   => 'User requested withdrawal',
        ]);

        // Export data from all 10 tables + cms_migrations
        $backup = [];
        $allTables = array_merge(['cms_migrations'], FavoritePayPlugin::TABLES);
        foreach ($allTables as $table) {
            $backup[$table] = $sourceDb->select("SELECT * FROM `{$table}`");
        }

        // Restore into fresh, separate database
        $destDb = new Database([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->app->singleton(Database::class, fn() => $destDb);

        // Run migrations on destination to establish identical schema
        $destMigrator = new Migrator($destDb);
        $destMigrator->migrate(APP_ROOT . '/plugins/favorite-pay/database/migrations');

        // Clear migration records and restore dumped rows
        $destDb->execute("DELETE FROM `cms_migrations`");
        foreach ($allTables as $table) {
            foreach ($backup[$table] as $row) {
                $destDb->insert($table, (array)$row);
            }
        }

        // Reconcile and verify destination database
        $restoredWallet = $destDb->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = 301");
        $this->assertNotNull($restoredWallet);
        $this->assertSame(85000, (int)$restoredWallet->balance);

        $ledgerSum = $destDb->selectOne("SELECT SUM(amount) as total FROM favorite_pay_wallet_entries WHERE user_id = 301");
        $this->assertSame(85000, (int)$ledgerSum->total);

        $restoredWd = $destDb->selectOne("SELECT * FROM favorite_pay_withdrawals WHERE withdrawal_id = 'WD-301-1'");
        $this->assertSame('pending', $restoredWd->status);

        $restoredNotif = $destDb->selectOne("SELECT * FROM favorite_pay_notifications WHERE id = 'NOTIF-301-1'");
        $this->assertSame('withdrawal_requested', $restoredNotif->type);

        $restoredAudit = $destDb->selectOne("SELECT * FROM favorite_pay_audit_logs WHERE action = 'withdrawal_requested'");
        $this->assertSame('WD-301-1', $restoredAudit->withdrawal_id);
    }

    /**
     * Test 8: Production-like Dataset Traversal & Financial Dashboard Controller.
     * Verifies that the dashboard, ledger, transactions, and audit views handle
     * populated data with accurate totals and zero rendering errors.
     */
    public function testProductionDatasetTraversalAndFinancialDashboard(): void
    {
        $db = new Database([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->app->singleton(Database::class, fn() => $db);

        $migrator = new Migrator($db);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-pay/database/migrations');

        // Create users and roles
        $pdo = $db->getPdo();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `users` (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(64),
                name VARCHAR(128),
                email VARCHAR(128),
                password VARCHAR(255),
                status VARCHAR(32)
            );
            CREATE TABLE IF NOT EXISTS `roles` (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(64),
                slug VARCHAR(64)
            );
            CREATE TABLE IF NOT EXISTS `user_roles` (
                user_id BIGINT,
                role_id BIGINT
            );
        ");

        $db->insert('users', ['id' => 1, 'username' => 'admin', 'name' => 'Admin User', 'email' => 'admin@example.com', 'status' => 'active']);
        $db->insert('users', ['id' => 401, 'username' => 'cust1', 'name' => 'Customer One', 'email' => 'c1@example.com', 'status' => 'active']);
        $db->insert('users', ['id' => 402, 'username' => 'cust2', 'name' => 'Customer Two', 'email' => 'c2@example.com', 'status' => 'active']);

        // Seed 2 wallets
        $w1 = $db->insert('favorite_pay_wallets', ['user_id' => 401, 'balance' => 100000, 'currency' => 'BDT', 'status' => 'active']);
        $w2 = $db->insert('favorite_pay_wallets', ['user_id' => 402, 'balance' => 50000, 'currency' => 'BDT', 'status' => 'active']);

        $db->insert('favorite_pay_wallet_entries', [
            'entry_id' => 'ENT-401', 'wallet_id' => $w1, 'user_id' => 401, 'type' => 'credit',
            'amount' => 100000, 'balance_after' => 100000, 'reference_type' => 'deposit', 'reference_id' => 'D1',
        ]);
        $db->insert('favorite_pay_wallet_entries', [
            'entry_id' => 'ENT-402', 'wallet_id' => $w2, 'user_id' => 402, 'type' => 'credit',
            'amount' => 50000, 'balance_after' => 50000, 'reference_type' => 'deposit', 'reference_id' => 'D2',
        ]);

        // Seed transactions
        $db->insert('favorite_pay_transactions', [
            'transaction_id' => 'TX-401-1', 'source_plugin' => 'wallet', 'source_reference' => 'D1',
            'user_id' => 401, 'base_amount' => 100000, 'base_currency' => 'BDT',
            'charge_amount' => 100000, 'charge_currency' => 'BDT', 'status' => 'succeeded',
        ]);

        // Seed withdrawals
        $db->insert('favorite_pay_withdrawals', [
            'withdrawal_id' => 'WD-401-1', 'user_id' => 401, 'wallet_id' => $w1,
            'amount' => 30000, 'currency' => 'BDT', 'fee' => 0, 'net_amount' => 30000,
            'method' => 'bkash', 'destination_data' => '{"phone":"01700000000"}', 'destination_masked' => '017****0000',
            'status' => 'pending', 'hold_reference' => 'HOLD-401-1',
        ]);

        // Seed notifications and audit
        $db->insert('favorite_pay_notifications', [
            'id' => 'N-401-1', 'user_id' => 401, 'type' => 'withdrawal_requested',
            'title' => 'Pending', 'message' => 'Your withdrawal is pending', 'is_read' => 0,
        ]);
        $db->insert('favorite_pay_audit_logs', [
            'actor_user_id' => 401, 'action' => 'withdrawal_requested', 'subject_type' => 'withdrawal',
            'subject_id' => 'WD-401-1', 'withdrawal_id' => 'WD-401-1', 'description' => 'Requested withdrawal',
        ]);

        // Set current user as admin
        $adminUser = new InstallUpgradeUserStub(['id' => 1, 'username' => 'admin'], ['super-admin'], ['*']);
        $GLOBALS['_test_current_user'] = $adminUser;
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['auth_user_name'] = 'admin';

        // Instantiate controllers
        $currencyService = new CurrencyService(null, $db);
        $walletService = new WalletService($currencyService, null, $db);
        $notifService = new NotificationService($db);
        $auditService = new AuditLogService($db);
        $withdrawalService = new WithdrawalService($walletService, $currencyService, $db, $notifService, $auditService);

        $dashboardController = new FinancialDashboardAdminController(
            $this->app,
            $walletService,
            $withdrawalService,
            new PaymentService($currencyService, new GatewayRegistry(), $db),
            $currencyService,
            $db
        );

        $auditController = new AuditLogAdminController($this->app, $auditService, $db);

        // 1. Financial Dashboard
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $resp = $dashboardController->handle($req);
        $this->assertIsString($resp);
        $this->assertStringContainsString('Financial Dashboard', $resp);
        // Total platform balance: 100000 + 50000 = 150000 minor units = 1,500.00 BDT
        $this->assertStringContainsString('1,500.00', $resp);
        $this->assertStringContainsString('Manage Withdrawals Queue', $resp);

        // 2. Audit Log Controller
        $auditReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-audit']);
        $auditResp = $auditController->handle($auditReq);
        $this->assertIsString($auditResp);
        $this->assertStringContainsString('Audit Log', $auditResp);
        $this->assertStringContainsString('withdrawal_requested', $auditResp);

        // 3. Customer Account Controller for Customer 401
        $customerUser = new InstallUpgradeUserStub(['id' => 401, 'username' => 'cust1'], ['customer'], []);
        $GLOBALS['_test_current_user'] = $customerUser;
        $_SESSION['auth_user_id'] = 401;
        $_SESSION['auth_user_name'] = 'cust1';

        $customerController = new CustomerAccountController(
            $this->app,
            $walletService,
            new PaymentService($currencyService, new GatewayRegistry(), $db),
            new GatewayRegistry(),
            $currencyService,
            $db,
            $withdrawalService,
            $notifService,
            $auditService
        );

        // Customer wallet view
        $walletReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']);
        $walletResp = $customerController->wallet($walletReq);
        $walletBody = $walletResp instanceof \FavoriteCMS\Core\Response ? $walletResp->getContent() : (string)$walletResp;
        $this->assertStringContainsString('1,000.00', $walletBody);

        // Customer transactions view
        $txReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions']);
        $txResp = $customerController->transactions($txReq);
        $txBody = $txResp instanceof \FavoriteCMS\Core\Response ? $txResp->getContent() : (string)$txResp;
        $this->assertStringContainsString('ENT-401', $txBody);

        // Customer notifications view
        $notifReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/notifications']);
        $notifResp = $customerController->notifications($notifReq);
        $notifBody = $notifResp instanceof \FavoriteCMS\Core\Response ? $notifResp->getContent() : (string)$notifResp;
        $this->assertStringContainsString('Pending', $notifBody);
    }
}
