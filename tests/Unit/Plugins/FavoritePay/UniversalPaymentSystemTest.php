<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use CreateFavoritePayTables;
use CreateSettingsTable;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Controllers\PaymentGatewaySettingsController;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Exceptions\UnauthoritativeRateException;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Gateways\Binance\BinancePayGateway;
use FavoriteCMS\Pay\Gateways\Binance\BinancePayHttpClient;
use FavoriteCMS\Pay\Gateways\Bkash\BkashMerchantGateway;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
use FavoriteCMS\Pay\Providers\DatabaseRateProvider;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\WalletService;
use FavoriteCMS\Pay\Services\WebhookService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

if (!class_exists('FavoriteCMS\\Tests\\Unit\\Plugins\\FavoritePay\\UniversalTestUserStub')) {
    class UniversalTestUserStub extends User
    {
        private array $rolesList;
        private array $permissionsList;

        public function __construct(array $attributes = [], array $roles = [], array $permissions = [])
        {
            $this->attributes = array_merge([
                'id'       => 1,
                'username' => 'operator_admin',
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
    }
}

class UniversalPaymentSystemTest extends TestCase
{
    private Application $app;
    private PDO $pdo;
    private Database $db;
    private GatewayRegistry $registry;
    private CurrencyService $currencyService;
    private PaymentService $paymentService;
    private WalletService $walletService;
    private PaymentGatewaySettingsController $settingsController;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $GLOBALS['_test_current_user'] = null;

        $this->app = Application::getInstance();

        // In-memory isolated SQLite database
        $this->pdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
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

        $this->app->instance(Database::class, $this->db);

        require_once APP_ROOT . '/database/migrations/009_create_settings_table.php';
        (new CreateSettingsTable($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-pay/database/migrations/001_create_favorite_pay_tables.php';
        (new CreateFavoritePayTables($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-pay/database/migrations/003_add_status_and_notes_to_favorite_pay_rates.php';
        (new \AddStatusAndNotesToFavoritePayRates($this->db))->up();

        Setting::clearCache();

        $this->currencyService = new CurrencyService(null, $this->db);
        $this->app->instance(CurrencyServiceInterface::class, $this->currencyService);

        $this->registry = new GatewayRegistry(true);
        $this->app->instance(GatewayRegistry::class, $this->registry);

        $this->paymentService = new PaymentService($this->currencyService, $this->registry, $this->db);
        $this->app->instance(PaymentService::class, $this->paymentService);

        $this->walletService = new WalletService($this->currencyService, $this->paymentService, $this->db);
        $this->app->instance(WalletService::class, $this->walletService);

        $this->settingsController = new PaymentGatewaySettingsController($this->app, $this->registry);
    }

    protected function tearDown(): void
    {
        Setting::clearCache();
        FavoritePayPlugin::reset();
        parent::tearDown();
    }

    private function authenticateSuperAdmin(): void
    {
        $user = new UniversalTestUserStub(['id' => 1, 'status' => 'active'], ['super-admin']);
        $GLOBALS['_test_current_user'] = $user;
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'csrf_universal_test';
    }

    // =========================================================================
    // SECTION A: MANUAL PAYMENTS (Exact 120 BDT, NO FX CONVERSION, LIFECYCLE)
    // =========================================================================

    public function testManualBkashPaymentChargesExact120BdtWithoutConversion(): void
    {
        // 120 BDT order (12000 minor units)
        $intent = $this->paymentService->createIntent(
            'favorite-shop',
            'ORD-BKASH-120',
            Money::bdt(12000),
            ['gateway_id' => 'manual_bkash']
        );

        // Base and charge amounts MUST be exactly 120 BDT
        $this->assertSame(12000, $intent->getBaseAmount()->getAmount());
        $this->assertSame('BDT', $intent->getBaseAmount()->getCurrency());
        $this->assertSame(12000, $intent->getChargeAmount()->getAmount());
        $this->assertSame('BDT', $intent->getChargeAmount()->getCurrency());
        $this->assertNull($intent->getConversionSnapshot(), 'Manual bKash payment must NOT perform FX conversion.');

        // Customer submits proof
        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'manual_bkash',
            'TRX_BKASH_120',
            ['sender_account' => '01711000000', 'notes' => 'Exact 120 BDT paid']
        );

        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $attempt->getStatus());
        $this->assertSame(12000, $attempt->getAmount()->getAmount());
        $this->assertSame('BDT', $attempt->getAmount()->getCurrency());
    }

    public function testManualNagadPaymentChargesExact120BdtWithoutConversion(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite-shop',
            'ORD-NAGAD-120',
            Money::bdt(12000),
            ['gateway_id' => 'manual_nagad']
        );

        $this->assertSame(12000, $intent->getBaseAmount()->getAmount());
        $this->assertSame('BDT', $intent->getBaseAmount()->getCurrency());
        $this->assertSame(12000, $intent->getChargeAmount()->getAmount());
        $this->assertSame('BDT', $intent->getChargeAmount()->getCurrency());
        $this->assertNull($intent->getConversionSnapshot());

        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'manual_nagad',
            'TRX_NAGAD_120',
            ['sender_account' => '01811000000']
        );

        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $attempt->getStatus());
        $this->assertSame(12000, $attempt->getAmount()->getAmount());
        $this->assertSame('BDT', $attempt->getAmount()->getCurrency());
    }

    public function testManualRocketPaymentChargesExact120BdtWithoutConversion(): void
    {
        $this->assertTrue($this->registry->has('manual_rocket'));
        $this->assertTrue($this->registry->has('rocket_manual'));

        $intent = $this->paymentService->createIntent(
            'favorite-shop',
            'ORD-ROCKET-120',
            Money::bdt(12000),
            ['gateway_id' => 'manual_rocket']
        );

        $this->assertSame(12000, $intent->getBaseAmount()->getAmount());
        $this->assertSame('BDT', $intent->getBaseAmount()->getCurrency());
        $this->assertSame(12000, $intent->getChargeAmount()->getAmount());
        $this->assertSame('BDT', $intent->getChargeAmount()->getCurrency());
        $this->assertNull($intent->getConversionSnapshot());

        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'manual_rocket',
            'TRX_ROCKET_120',
            ['sender_account' => '01911000000']
        );

        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $attempt->getStatus());
        $this->assertSame(12000, $attempt->getAmount()->getAmount());
        $this->assertSame('BDT', $attempt->getAmount()->getCurrency());
    }

    public function testManualBankPaymentChargesExact120BdtWithoutConversion(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite-shop',
            'ORD-BANK-120',
            Money::bdt(12000),
            ['gateway_id' => 'manual_bank']
        );

        $this->assertSame(12000, $intent->getBaseAmount()->getAmount());
        $this->assertSame('BDT', $intent->getBaseAmount()->getCurrency());
        $this->assertSame(12000, $intent->getChargeAmount()->getAmount());
        $this->assertSame('BDT', $intent->getChargeAmount()->getCurrency());
        $this->assertNull($intent->getConversionSnapshot());

        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'manual_bank',
            'DEP_REF_120',
            ['sender_account' => 'AC-998877']
        );

        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $attempt->getStatus());
        $this->assertSame(12000, $attempt->getAmount()->getAmount());
        $this->assertSame('BDT', $attempt->getAmount()->getCurrency());
    }

    public function testDisabledManualMethodIsHiddenAndCannotProcessPayment(): void
    {
        $bkash = $this->registry->get('manual_bkash');
        $bkash->setEnabled(false);

        $methods = $this->paymentService->getAvailablePaymentMethods('BDT');
        $methodIds = array_column($methods, 'id');
        $this->assertNotContains('manual_bkash', $methodIds);

        $intent = $this->paymentService->createIntent('favorite-shop', 'ORD-DIS-1', Money::bdt(5000));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("disabled");

        $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_DIS');
    }

    public function testUnconfiguredManualMethodWithoutNumberIsHiddenFromCheckout(): void
    {
        $nagad = $this->registry->get('manual_nagad');
        $nagad->setEnabled(true);
        $nagad->setConfig(['account_number' => '']); // empty receiving number

        $this->assertFalse($nagad->isConfigured());
        $this->assertFalse($nagad->isAvailable());

        $methods = $this->paymentService->getAvailablePaymentMethods('BDT');
        $methodIds = array_column($methods, 'id');
        $this->assertNotContains('manual_nagad', $methodIds);
    }

    public function testManualPaymentEntersAwaitingVerificationAndCannotAutoCreditWallet(): void
    {
        $userId = 42;
        $intent = $this->paymentService->createIntent(
            'favorite-digital',
            'SUB-9001',
            Money::bdt(12000),
            ['gateway_id' => 'manual_bkash', 'customer_id' => $userId]
        );

        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'manual_bkash',
            'TRX_NO_AUTOCREDIT'
        );

        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $attempt->getStatus());

        // Wallet must NOT be credited upon submission
        $balance = $this->walletService->getBalance($userId);
        $this->assertSame(0, $balance->getAmount(), 'Wallet must remain 0 before admin approval.');

        // Admin approves payment
        $approvedAttempt = $this->paymentService->approveManualPayment($attempt->getId(), 1, 'Verified in statement');
        $this->assertSame(PaymentStatus::SUCCEEDED, $approvedAttempt->getStatus());

        // Settle wallet using existing settlement lifecycle
        $this->walletService->settleSuccessfulPayment($intent->getId());
        $newBalance = $this->walletService->getBalance($userId);
        $this->assertSame(12000, $newBalance->getAmount(), 'Wallet must be credited with exact 120 BDT after approval & settlement.');
    }

    public function testDuplicateTrxIdIsRejectedForManualPayments(): void
    {
        $intent1 = $this->paymentService->createIntent('shop', 'ORD-DUP-1', Money::bdt(10000));
        $intent2 = $this->paymentService->createIntent('shop', 'ORD-DUP-2', Money::bdt(10000));

        $this->paymentService->submitManualVerification($intent1->getId(), 'manual_bkash', 'TRX_SAME_REF');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Duplicate transaction reference 'TRX_SAME_REF' for gateway 'manual_bkash'");

        $this->paymentService->submitManualVerification($intent2->getId(), 'manual_bkash', 'TRX_SAME_REF');
    }

    // =========================================================================
    // SECTION B: AUTOMATIC GATEWAYS (bKash, City Bank, Binance Pay)
    // =========================================================================

    public function testBkashAutomaticGatewayConfigurationStatesAndSecretProtection(): void
    {
        $bkash = new BkashMerchantGateway();

        // 1. Initial state: DISABLED or NOT_CONFIGURED
        $bkash->setEnabled(false);
        $status = $bkash->getConfigurationStatus();
        $this->assertSame('DISABLED', $status['state']);
        $this->assertFalse($status['is_ready']);

        // 2. Enabled but credentials missing => NOT_CONFIGURED
        $bkash->setEnabled(true);
        $status = $bkash->getConfigurationStatus();
        $this->assertSame('NOT_CONFIGURED', $status['state']);
        $this->assertFalse($status['is_ready']);
        $this->assertStringContainsString('missing', $status['message']);

        // 3. Fully configured => READY
        $bkash->setConfig([
            'enabled'    => true,
            'sandbox'    => true,
            'app_key'    => 'bkash_app_key_live_test',
            'app_secret' => 'bkash_app_secret_super_secret',
            'username'   => 'bkash_merchant_user',
            'password'   => 'bkash_merchant_pass_123',
        ]);

        $this->assertTrue($bkash->isConfigured());
        $status = $bkash->getConfigurationStatus();
        $this->assertSame('READY', $status['state']);
        $this->assertTrue($status['is_ready']);

        // 4. Secret preservation: updating without secret keeps existing
        $bkash->setConfig([
            'enabled'    => true,
            'app_key'    => 'bkash_app_key_live_test',
            'app_secret' => '', // blank => preserve
            'username'   => 'bkash_merchant_user',
            'password'   => '', // blank => preserve
        ]);
        $cfg = $bkash->getConfig();
        $this->assertSame('bkash_app_secret_super_secret', $cfg['app_secret']);
        $this->assertSame('bkash_merchant_pass_123', $cfg['password']);

        // 5. Public config must not leak secrets
        $public = $bkash->getPublicConfig();
        $this->assertArrayNotHasKey('app_secret', $public);
        $this->assertArrayNotHasKey('password', $public);
    }

    public function testUnconfiguredAutomaticGatewaysCannotProcessAttempts(): void
    {
        $bkash = new BkashMerchantGateway(['enabled' => true]);
        $intent = $this->paymentService->createIntent('shop', 'ORD-FAIL-1', Money::bdt(5000));

        try {
            $bkash->createAttempt($intent);
            $this->fail("Expected RuntimeException when creating attempt on unconfigured bKash gateway.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("not configured", $e->getMessage());
        }

        $binance = new BinancePayGateway(['enabled' => true]);
        try {
            $binance->createAttempt($intent);
            $this->fail("Expected RuntimeException when creating attempt on unconfigured Binance gateway.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("not configured", $e->getMessage());
        }
    }

    // =========================================================================
    // SECTION C: BINANCE PAY CURRENCY FLOW & CONVERSION PRESERVATION
    // =========================================================================

    public function testBinancePay120BdtOrderPreservesOriginalOrderAndConvertsToUsdt(): void
    {
        // Set an authoritative rate in database: 1 USDT = 127 BDT
        $this->currencyService->setOperatorRate('USDT', '127', 1, 'BDT');

        // Order is 120 BDT
        $orderAmount = Money::bdt(12000); // 120.00 BDT
        $intent = $this->paymentService->createIntent(
            'favorite-shop',
            'ORD-BINANCE-120-BDT',
            $orderAmount,
            ['gateway_id' => 'binance_pay']
        );

        // 1. Original order MUST remain exactly 120 BDT
        $this->assertSame(12000, $intent->getBaseAmount()->getAmount());
        $this->assertSame('BDT', $intent->getBaseAmount()->getCurrency());

        // 2. Acquiring currency MUST be USDT
        $this->assertSame('USDT', $intent->getChargeAmount()->getCurrency());

        // 3. Converted USDT amount: 120 BDT / 127 = ~0.94 USDT (exact integer: 12000 * 7874 / 1000000 = 94 cents = 0.94 USDT)
        $this->assertSame(94, $intent->getChargeAmount()->getAmount());

        // 4. Conversion snapshot is locked on attempt
        $snapshot = $intent->getConversionSnapshot();
        $this->assertNotNull($snapshot);
        $this->assertSame('BDT', $snapshot->getFromCurrency());
        $this->assertSame('USDT', $snapshot->getToCurrency());
        $this->assertSame(7874, $snapshot->getRateFactor());
        $this->assertTrue($snapshot->isValidForPayment());
    }

    public function testBinancePay120UsdOrderConvertsToUsdtAndPreservesOriginalOrder(): void
    {
        // 1 USD = 1 USDT authoritative parity rate
        $this->currencyService->setOperatorRate('USD', '1.00', 1, 'USDT');

        $orderAmount = Money::usd(12000); // 120.00 USD
        $intent = $this->paymentService->createIntent(
            'favorite-shop',
            'ORD-BINANCE-120-USD',
            $orderAmount,
            ['gateway_id' => 'binance_pay']
        );

        // Original order remains 120 USD
        $this->assertSame(12000, $intent->getBaseAmount()->getAmount());
        $this->assertSame('USD', $intent->getBaseAmount()->getCurrency());

        // Charge amount in acquiring currency USDT
        $this->assertSame(12000, $intent->getChargeAmount()->getAmount());
        $this->assertSame('USDT', $intent->getChargeAmount()->getCurrency());
    }

    public function testBinanceFailsSafelyIfNoConversionRateExistsWithoutHardcodedFallback(): void
    {
        // Unsupported currency XYZ with no configured or market exchange rate
        $orderAmount = new Money(12000, 'XYZ');

        $this->expectException(UnauthoritativeRateException::class);
        $this->expectExceptionMessage("No valid authoritative exchange rate is available for conversion from 'XYZ' to 'USDT'.");

        $this->paymentService->createIntent(
            'favorite-shop',
            'ORD-XYZ-BINANCE',
            $orderAmount,
            ['gateway_id' => 'binance_pay']
        );
    }

    // =========================================================================
    // SECTION D: ADMIN CONTROLLER & NAVIGATION
    // =========================================================================

    public function testSettingsControllerHandlesManualPaymentsSubmission(): void
    {
        $this->authenticateSuperAdmin();

        $postRequest = new Request([], [
            '_token'         => 'csrf_universal_test',
            'method'         => 'manual_bkash',
            'enabled'        => '1',
            'account_number' => '01799887766',
            'account_name'   => 'Universal Merchant',
            'account_type'   => 'Merchant',
            'instructions'   => 'Send money and enter TrxID',
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $this->settingsController->handleManual($postRequest);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());

        // Verify gateway in registry is updated
        $bkash = $this->registry->get('manual_bkash');
        $this->assertTrue($bkash->isEnabled());
        $this->assertTrue($bkash->isConfigured());
        $instructions = $bkash->getInstructions();
        $this->assertSame('01799887766', $instructions['account_number']);
        $this->assertSame('Universal Merchant', $instructions['account_name']);
    }

    public function testSettingsControllerHandlesBkashAutomaticSubmission(): void
    {
        $this->authenticateSuperAdmin();

        $postRequest = new Request([], [
            '_token'     => 'csrf_universal_test',
            'gateway'    => 'bkash_direct',
            'enabled'    => '1',
            'sandbox'    => '1',
            'app_key'    => 'my_bkash_app_key',
            'app_secret' => 'my_bkash_app_secret',
            'username'   => 'my_bkash_user',
            'password'   => 'my_bkash_pass',
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $this->settingsController->handleAutomatic($postRequest);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());

        $bkash = $this->registry->get('bkash_direct');
        $this->assertTrue($bkash->isEnabled());
        $this->assertTrue($bkash->isConfigured());
        $this->assertSame('READY', $bkash->getConfigurationStatus()['state']);
    }
}
