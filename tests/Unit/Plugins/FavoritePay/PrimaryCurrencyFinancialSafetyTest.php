<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use CreateFavoritePayTables;
use CreateSettingsTable;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Currency;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Http\Controllers\Admin\SettingController;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\RefundService;
use FavoriteCMS\Pay\Services\WalletService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PrimaryCurrencyFinancialSafetyTest extends TestCase
{
    private Database $db;
    private PDO $pdo;
    private Application $app;
    private CurrencyService $currencyService;
    private GatewayRegistry $registry;
    private PaymentService $paymentService;
    private WalletService $walletService;
    private RefundService $refundService;
    private FavoritePayPlugin $plugin;

    protected function setUp(): void
    {
        $_SESSION = [];
        Setting::clearCache();
        FavoritePayPlugin::reset();

        // 1. In-memory SQLite for complete relational isolation
        $this->pdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);

        $this->db = new class($this->pdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite'];
                $this->prefix = '';
            }
            public function getConnection(): PDO
            {
                return $this->pdo;
            }
        };

        // Run migrations
        require_once APP_ROOT . '/database/migrations/009_create_settings_table.php';
        $coreMigration = new CreateSettingsTable($this->db);
        $coreMigration->up();

        require_once APP_ROOT . '/plugins/favorite-pay/database/migrations/001_create_favorite_pay_tables.php';
        $payMigration = new CreateFavoritePayTables($this->db);
        $payMigration->up();

        // 2. Setup Application container & services
        $this->app = Application::getInstance();
        $this->app->instance(Database::class, $this->db);

        $this->currencyService = new CurrencyService();
        $this->registry = new GatewayRegistry();

        $gateway = new ManualBangladeshGateway(
            'manual_bkash',
            'bKash Manual Payment',
            PaymentMethodType::MANUAL_BKASH,
            [
                'channel'        => 'bkash',
                'account_number' => '01700000000',
            ]
        );
        $this->registry->register($gateway);

        $this->paymentService = new PaymentService(
            $this->currencyService,
            $this->registry,
            $this->db
        );

        $this->walletService = new WalletService(
            $this->currencyService,
            $this->paymentService,
            $this->db
        );

        $this->refundService = new RefundService($this->paymentService);

        $this->app->instance(PaymentService::class, $this->paymentService);
        $this->app->instance(WalletService::class, $this->walletService);
        $this->app->instance(\FavoriteCMS\Pay\Contracts\CurrencyServiceInterface::class, $this->currencyService);
        $this->app->instance(\FavoriteCMS\Pay\Contracts\WalletServiceInterface::class, $this->walletService);
        $this->app->instance(\FavoriteCMS\Pay\Contracts\PaymentServiceInterface::class, $this->paymentService);
        $this->app->instance(\FavoriteCMS\Pay\Contracts\RefundServiceInterface::class, $this->refundService);

        $this->plugin = new FavoritePayPlugin($this->app);
        $this->plugin->boot();

        // Ensure default currency is BDT initially
        Setting::set('general', 'primary_currency', 'BDT', 'string');
    }

    protected function tearDown(): void
    {
        FavoritePayPlugin::reset();
        Setting::clearCache();
    }

    /**
     * 1. Primary Currency can change when there is NO financial activity.
     */
    public function testPrimaryCurrencyCanChangeWhenNoFinancialActivity(): void
    {
        $this->assertFalse($this->plugin->hasFinancialActivity());
        $this->assertFalse(Currency::isPrimaryCurrencyLocked());

        $reason = null;
        $this->assertTrue(Currency::canChangePrimaryCurrency('USD', $reason));
        $this->assertNull($reason);

        // Change from BDT to USD
        Currency::setPrimaryCurrency('USD');
        $this->assertSame('USD', Currency::getPrimaryCurrency());
        $this->assertSame('USD', $this->currencyService->getBaseCurrency());
        $this->assertSame('USD', $this->walletService->getPrimaryCurrency());

        // Change from USD to EUR
        Currency::setPrimaryCurrency('EUR');
        $this->assertSame('EUR', Currency::getPrimaryCurrency());
    }

    /**
     * Gateway configuration alone must NOT block currency changes.
     */
    public function testGatewayConfigurationAloneDoesNotBlockCurrencyChange(): void
    {
        // Register additional gateways
        $nagad = new ManualBangladeshGateway('manual_nagad', 'Nagad', PaymentMethodType::MANUAL_NAGAD, []);
        $bank = new ManualBangladeshGateway('manual_bank', 'Bank', PaymentMethodType::MANUAL_BANK, []);
        $this->registry->register($nagad);
        $this->registry->register($bank);

        $this->assertFalse($this->plugin->hasFinancialActivity());
        $this->assertFalse(Currency::isPrimaryCurrencyLocked());

        Currency::setPrimaryCurrency('USD');
        $this->assertSame('USD', Currency::getPrimaryCurrency());
    }

    /**
     * 2. Primary Currency change is blocked when a payment transaction exists.
     */
    public function testPrimaryCurrencyChangeBlockedWhenPaymentTransactionExists(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_101',
            Money::bdt(25000),
            ['customer_id' => 1]
        );

        $this->assertTrue($this->plugin->hasFinancialActivity());
        $lockReason = null;
        $this->assertTrue(Currency::isPrimaryCurrencyLocked($lockReason));
        $this->assertStringContainsString('financial activity', $lockReason);

        $reason = null;
        $this->assertFalse(Currency::canChangePrimaryCurrency('USD', $reason));
        $this->assertStringContainsString('financial activity', $reason);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('financial activity');
        Currency::setPrimaryCurrency('USD');
    }

    /**
     * 3. Primary Currency change is blocked when a payment attempt exists.
     */
    public function testPrimaryCurrencyChangeBlockedWhenPaymentAttemptExists(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_102',
            Money::bdt(15000),
            ['customer_id' => 1]
        );

        $this->paymentService->submitManualVerification(
            $intent->getId(),
            'manual_bkash',
            'TRX_ATTEMPT_001'
        );

        $this->assertTrue($this->plugin->hasFinancialActivity());
        $this->assertTrue(Currency::isPrimaryCurrencyLocked());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('financial activity');
        Currency::setPrimaryCurrency('EUR');
    }

    /**
     * 4. Primary Currency change is blocked when a refund exists.
     */
    public function testPrimaryCurrencyChangeBlockedWhenRefundExists(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_103',
            Money::bdt(10000),
            ['customer_id' => 1]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        $this->refundService->createRefund($intent->getId(), Money::bdt(5000), 'Customer requested partial refund');

        $this->assertTrue($this->plugin->hasFinancialActivity());
        $this->assertTrue(Currency::isPrimaryCurrencyLocked());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('financial activity');
        Currency::setPrimaryCurrency('USD');
    }

    /**
     * 5. Primary Currency change is blocked when a wallet exists.
     */
    public function testPrimaryCurrencyChangeBlockedWhenWalletExists(): void
    {
        $this->db->insert('favorite_pay_wallets', [
            'user_id'    => 55,
            'balance'    => 0,
            'currency'   => 'BDT',
            'status'     => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertTrue($this->plugin->hasFinancialActivity());
        $this->assertTrue(Currency::isPrimaryCurrencyLocked());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('financial activity');
        Currency::setPrimaryCurrency('USD');
    }

    /**
     * 6. Primary Currency change is blocked when a wallet ledger entry exists.
     */
    public function testPrimaryCurrencyChangeBlockedWhenWalletLedgerEntryExists(): void
    {
        $this->db->insert('favorite_pay_wallet_entries', [
            'entry_id'        => 'led_test_001',
            'wallet_id'       => 1,
            'user_id'         => 66,
            'type'            => 'credit',
            'amount'          => 5000,
            'balance_after'   => 5000,
            'reference_type'  => 'deposit',
            'reference_id'    => 'dep_001',
            'idempotency_key' => 'idemp_001',
            'description'     => 'Initial test deposit',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->assertTrue($this->plugin->hasFinancialActivity());
        $this->assertTrue(Currency::isPrimaryCurrencyLocked());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('financial activity');
        Currency::setPrimaryCurrency('USD');
    }

    /**
     * 7. Failed currency change leaves the original Primary Currency unchanged.
     */
    public function testFailedCurrencyChangeLeavesOriginalPrimaryCurrencyUnchanged(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite_digital',
            'sub_001',
            Money::bdt(9900),
            ['customer_id' => 10]
        );

        $this->assertSame('BDT', Currency::getPrimaryCurrency());

        try {
            Currency::setPrimaryCurrency('USD');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('financial activity', $e->getMessage());
        }

        // Primary Currency MUST still be BDT
        $this->assertSame('BDT', Currency::getPrimaryCurrency());
        $this->assertSame('BDT', Setting::get('general', 'primary_currency'));
        $this->assertSame('BDT', $this->currencyService->getBaseCurrency());
    }

    /**
     * 8. Existing wallet currency remains unchanged.
     */
    public function testExistingWalletCurrencyRemainsUnchanged(): void
    {
        $userId = 201;
        $this->walletService->deposit($userId, Money::bdt(50000), 'dep_bdt_201');

        $wallet = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
        $this->assertSame('BDT', $wallet->currency);
        $this->assertSame(50000, (int)$wallet->balance);

        // Attempt currency change (will fail)
        try {
            Currency::setPrimaryCurrency('USD');
        } catch (RuntimeException) {
        }

        $walletAfter = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
        $this->assertSame('BDT', $walletAfter->currency);
        $this->assertSame(50000, (int)$walletAfter->balance);
    }

    /**
     * 9. Existing transaction currency remains unchanged.
     */
    public function testExistingTransactionCurrencyRemainsUnchanged(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_bdt_hist',
            Money::bdt(45000),
            ['customer_id' => 301]
        );

        try {
            Currency::setPrimaryCurrency('EUR');
        } catch (RuntimeException) {
        }

        $tx = $this->db->selectOne("SELECT * FROM favorite_pay_transactions WHERE transaction_id = ?", [$intent->getId()]);
        $this->assertSame('BDT', $tx->base_currency);
        $this->assertSame(45000, (int)$tx->base_amount);
    }

    /**
     * 10. Existing ledger entry currency remains unchanged.
     */
    public function testExistingLedgerEntryCurrencyRemainsUnchanged(): void
    {
        $userId = 401;
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_ledger_check',
            Money::bdt(60000),
            ['customer_id' => $userId]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);
        $entry = $this->walletService->settleSuccessfulPayment($intent->getId());

        try {
            Currency::setPrimaryCurrency('GBP');
        } catch (RuntimeException) {
        }

        $ledger = $this->db->selectOne("SELECT * FROM favorite_pay_wallet_entries WHERE reference_id = ?", [$intent->getId()]);
        $this->assertSame(60000, (int)$ledger->amount);
        $this->assertSame(60000, (int)$ledger->balance_after);

        $wallet = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
        $this->assertSame('BDT', $wallet->currency);
    }

    /**
     * 11. New wallet uses current Primary Currency when wallet creation is allowed.
     */
    public function testNewWalletUsesCurrentPrimaryCurrencyWhenWalletCreationAllowed(): void
    {
        // Fresh install with no activity: change primary currency to USD
        Currency::setPrimaryCurrency('USD');
        $this->assertSame('USD', Currency::getPrimaryCurrency());

        $userId = 501;
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_usd_new',
            Money::usd(2500), // $25.00 USD
            ['customer_id' => $userId]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        $entry = $this->walletService->settleSuccessfulPayment($intent->getId());
        $this->assertSame('USD', $entry->getAmount()->getCurrency());
        $this->assertSame(2500, $entry->getAmount()->getAmount());

        $wallet = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
        $this->assertSame('USD', $wallet->currency);
        $this->assertSame(2500, (int)$wallet->balance);
    }

    /**
     * 12. Settlement with matching currencies succeeds.
     */
    public function testSettlementWithMatchingCurrenciesSucceeds(): void
    {
        $userId = 601;
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_matching',
            Money::bdt(30000),
            ['customer_id' => $userId]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        $entry = $this->walletService->settleSuccessfulPayment($intent->getId());
        $this->assertSame('BDT', $entry->getAmount()->getCurrency());
        $this->assertSame(30000, $entry->getAmount()->getAmount());

        $balance = $this->walletService->getBalance($userId);
        $this->assertSame('BDT', $balance->getCurrency());
        $this->assertSame(30000, $balance->getAmount());
    }

    /**
     * 13. Settlement with mismatched currencies is rejected safely.
     */
    public function testSettlementWithMismatchedCurrenciesIsRejectedSafely(): void
    {
        $userId = 701;

        // User already has an existing BDT wallet
        $this->db->insert('favorite_pay_wallets', [
            'user_id'    => $userId,
            'balance'    => 10000,
            'currency'   => 'BDT',
            'status'     => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Create a transaction with USD base amount (e.g. from an external service or legacy)
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'mismatched_tx_1',
            Money::usd(5000),
            ['customer_id' => $userId]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        $this->expectException(\Throwable::class);
        $this->walletService->settleSuccessfulPayment($intent->getId());
    }

    /**
     * 14. Mismatched settlement does not change wallet balance.
     */
    public function testMismatchedSettlementDoesNotChangeWalletBalance(): void
    {
        $userId = 801;
        $this->db->insert('favorite_pay_wallets', [
            'user_id'    => $userId,
            'balance'    => 40000,
            'currency'   => 'BDT',
            'status'     => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'mismatched_tx_2',
            Money::usd(7500),
            ['customer_id' => $userId]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        try {
            $this->walletService->settleSuccessfulPayment($intent->getId());
            $this->fail('Expected exception for mismatched settlement.');
        } catch (\Throwable) {
        }

        // Balance in database MUST remain exactly 40,000
        $wallet = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
        $this->assertSame(40000, (int)$wallet->balance);
        $this->assertSame('BDT', $wallet->currency);

        // In-memory balance must also remain unchanged
        $balance = $this->walletService->getBalance($userId);
        $this->assertSame(40000, $balance->getAmount());
        $this->assertSame('BDT', $balance->getCurrency());
    }

    /**
     * 15. Mismatched settlement does not create a ledger entry.
     */
    public function testMismatchedSettlementDoesNotCreateLedgerEntry(): void
    {
        $userId = 901;
        $this->db->insert('favorite_pay_wallets', [
            'user_id'    => $userId,
            'balance'    => 20000,
            'currency'   => 'BDT',
            'status'     => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $entriesBefore = $this->db->select("SELECT * FROM favorite_pay_wallet_entries WHERE user_id = ?", [$userId]);
        $this->assertCount(0, $entriesBefore);

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'mismatched_tx_3',
            Money::usd(1000),
            ['customer_id' => $userId]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        try {
            $this->walletService->settleSuccessfulPayment($intent->getId());
        } catch (\Throwable) {
        }

        $entriesAfter = $this->db->select("SELECT * FROM favorite_pay_wallet_entries WHERE user_id = ?", [$userId]);
        $this->assertCount(0, $entriesAfter, 'Zero ledger entries should be created on failed settlement.');
    }

    /**
     * 16. Super-admin / admin controller cannot bypass the Primary Currency financial safety rule.
     */
    public function testSuperAdminSettingControllerCannotBypassPrimaryCurrencySafetyRule(): void
    {
        // 1. Establish financial activity
        $this->paymentService->createIntent(
            'favorite_shop',
            'admin_bypass_prevention',
            Money::bdt(10000),
            ['customer_id' => 1]
        );

        $controller = new SettingController($this->app);

        // 2. Simulate super-admin submitting form with primary_currency = USD
        $request = new Request([], [
            'site_name'        => 'My Store',
            'primary_currency' => 'USD',
        ], [], [], [], ['REQUEST_METHOD' => 'POST']);

        $response = $controller->update($request);

        // Controller redirects back with flash error
        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotEmpty($_SESSION['flash_error'] ?? null);
        $this->assertStringContainsString('financial activity', $_SESSION['flash_error']);

        // Site primary currency MUST remain BDT
        $this->assertSame('BDT', Currency::getPrimaryCurrency());
        $this->assertSame('BDT', Setting::get('general', 'primary_currency'));

        // Direct programmatic call also cannot bypass
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('financial activity');
        Currency::setPrimaryCurrency('EUR');
    }
}
