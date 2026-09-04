<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use AddStatusAndNotesToFavoritePayRates;
use CreateFavoritePayTables;
use CreateSettingsTable;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Controllers\PaymentRateController;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Exceptions\UnauthoritativeRateException;
use FavoriteCMS\Pay\Gateways\Binance\BinancePayGateway;
use FavoriteCMS\Pay\Gateways\Binance\BinancePayHttpClient;
use FavoriteCMS\Pay\Permissions\PaymentPermission;
use FavoriteCMS\Pay\Providers\DatabaseRateProvider;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use PDO;
use PHPUnit\Framework\TestCase;

if (!class_exists('FavoriteCMS\\Tests\\Unit\\Plugins\\FavoritePay\\OperatorUserStub')) {
    class OperatorUserStub extends User
    {
        private array $rolesList;
        private array $permissionsList;

        public function __construct(array $attributes = [], array $roles = [], array $permissions = [])
        {
            $this->attributes = array_merge([
                'id'       => 1,
                'username' => 'operator_user',
                'email'    => 'operator@example.com',
                'status'   => 'active',
            ], $attributes);
            $this->rolesList = $roles;
            $this->permissionsList = $permissions;
        }

        public function getId(): int
        {
            return (int)($this->attributes['id'] ?? 1);
        }

        public function isActive(): bool
        {
            return ($this->attributes['status'] ?? '') === 'active';
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

class OperatorExchangeRateTest extends TestCase
{
    private Application $app;
    private PDO $pdo;
    private Database $db;
    private DatabaseRateProvider $rateProvider;
    private CurrencyService $currencyService;
    private PaymentRateController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        $GLOBALS['_test_current_user'] = null;

        $this->app = Application::getInstance();

        // In-memory SQLite Database
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

        // Run migrations
        require_once APP_ROOT . '/database/migrations/009_create_settings_table.php';
        (new CreateSettingsTable($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-pay/database/migrations/001_create_favorite_pay_tables.php';
        (new CreateFavoritePayTables($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-pay/database/migrations/003_add_status_and_notes_to_favorite_pay_rates.php';
        (new AddStatusAndNotesToFavoritePayRates($this->db))->up();

        Setting::clearCache();

        $this->rateProvider = new DatabaseRateProvider($this->db);
        $this->currencyService = new CurrencyService($this->rateProvider, $this->db);

        $this->controller = new PaymentRateController(
            $this->app,
            $this->currencyService,
            $this->rateProvider
        );
    }

    // ==========================================
    // 1. RBAC & Access Control Tests
    // ==========================================

    public function testUnauthenticatedUserIsRedirectedToLogin(): void
    {
        $_SESSION = [];
        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET']);

        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/login', $response->getHeaders()['Location'] ?? '');
    }

    public function testInactiveUserIsDeniedAccessWith403(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $inactiveUser = new OperatorUserStub(['id' => 42, 'status' => 'suspended'], ['super-admin']);
        $GLOBALS['_test_current_user'] = $inactiveUser;

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Access Denied', $response->getContent());
    }

    public function testUserWithoutManageRatesPermissionIsDeniedWith403(): void
    {
        $_SESSION['auth_user_id'] = 10;
        // User with only VIEW and VERIFY permissions
        $viewerUser = new OperatorUserStub(
            ['id' => 10, 'status' => 'active'],
            [],
            [PaymentPermission::VIEW, PaymentPermission::VERIFY]
        );
        $GLOBALS['_test_current_user'] = $viewerUser;

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('You do not have permission', $response->getContent());
    }

    public function testUserWithManageRatesPermissionCanAccessIndex(): void
    {
        $_SESSION['auth_user_id'] = 15;
        $managerUser = new OperatorUserStub(
            ['id' => 15, 'status' => 'active'],
            [],
            [PaymentPermission::MANAGE_RATES]
        );
        $GLOBALS['_test_current_user'] = $managerUser;

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $output = $this->controller->handle($request);

        $this->assertIsString($output);
        $this->assertStringContainsString('Exchange Rate Management', $output);
        $this->assertStringContainsString('Configure Authoritative Exchange Rate', $output);
    }

    public function testSuperAdminCanAccessIndex(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $superAdmin = new OperatorUserStub(
            ['id' => 1, 'status' => 'active'],
            ['super-admin'],
            []
        );
        $GLOBALS['_test_current_user'] = $superAdmin;

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $output = $this->controller->handle($request);

        $this->assertIsString($output);
        $this->assertStringContainsString('Authoritative Rates Audit Log', $output);
    }

    // ==========================================
    // 2. CSRF Validation Tests
    // ==========================================

    public function testPostFailsIfCsrfTokenIsInvalid(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'valid_secret_csrf_token';
        $GLOBALS['_test_current_user'] = new OperatorUserStub([], ['super-admin']);

        $request = new Request([], [
            '_token'         => 'tampered_token',
            'base_currency'  => 'USDT',
            'quote_currency' => 'BDT',
            'rate'           => '122.50',
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('CSRF failure', $_SESSION['flash_error'] ?? '');
    }

    // ==========================================
    // 3. Validation & Decimal Arithmetic Tests
    // ==========================================

    public function testStoreRejectsBackdatingEffectiveTimeEarlierThanActiveRate(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'token_abc';
        $GLOBALS['_test_current_user'] = new OperatorUserStub([], ['super-admin']);

        // First rate effective at 12:00
        $req1 = new Request([], [
            '_token'         => 'token_abc',
            'base_currency'  => 'USDT',
            'quote_currency' => 'BDT',
            'rate'           => '120.00',
            'effective_at'   => '2026-09-04 12:00:00',
        ], ['REQUEST_METHOD' => 'POST']);
        $this->controller->handle($req1);

        // Attempt second rate backdated to 11:00
        $req2 = new Request([], [
            '_token'         => 'token_abc',
            'base_currency'  => 'USDT',
            'quote_currency' => 'BDT',
            'rate'           => '121.00',
            'effective_at'   => '2026-09-04 11:00:00',
        ], ['REQUEST_METHOD' => 'POST']);
        $this->controller->handle($req2);

        $this->assertStringContainsString('Cannot backdate rate', $_SESSION['flash_error'] ?? '');
    }

    public function testStoreRejectsAbsurdRateExceedingDomainBound(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'token_abc';
        $GLOBALS['_test_current_user'] = new OperatorUserStub([], ['super-admin']);

        $req = new Request([], [
            '_token'         => 'token_abc',
            'base_currency'  => 'USDT',
            'quote_currency' => 'BDT',
            'rate'           => '999999999.00',
        ], ['REQUEST_METHOD' => 'POST']);
        $this->controller->handle($req);

        $this->assertStringContainsString('must be between 0.000001 and 100,000,000.00', $_SESSION['flash_error'] ?? '');
    }

    public function testStoreRejectsIdenticalCurrencies(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'token_abc';
        $GLOBALS['_test_current_user'] = new OperatorUserStub([], ['super-admin']);

        $request = new Request([], [
            '_token'         => 'token_abc',
            'base_currency'  => 'USDT',
            'quote_currency' => 'USDT',
            'rate'           => '1.00',
        ], ['REQUEST_METHOD' => 'POST']);

        $this->controller->handle($request);

        $this->assertStringContainsString('cannot be the same', $_SESSION['flash_error'] ?? '');
    }

    public function testStoreRejectsNonNumericOrNegativeRate(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'token_abc';
        $GLOBALS['_test_current_user'] = new OperatorUserStub([], ['super-admin']);

        // Test non-numeric
        $request = new Request([], [
            '_token'         => 'token_abc',
            'base_currency'  => 'USDT',
            'quote_currency' => 'BDT',
            'rate'           => 'invalid_rate',
        ], ['REQUEST_METHOD' => 'POST']);
        $this->controller->handle($request);
        $this->assertStringContainsString('Exchange rate must be a valid positive decimal', $_SESSION['flash_error'] ?? '');

        // Test negative
        $requestNeg = new Request([], [
            '_token'         => 'token_abc',
            'base_currency'  => 'USDT',
            'quote_currency' => 'BDT',
            'rate'           => '-120.00',
        ], ['REQUEST_METHOD' => 'POST']);
        $this->controller->handle($requestNeg);
        $this->assertStringContainsString('Exchange rate must be a valid positive decimal', $_SESSION['flash_error'] ?? '');
    }

    public function testStoreRejectsExpirationPriorToEffectiveTime(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'token_abc';
        $GLOBALS['_test_current_user'] = new OperatorUserStub([], ['super-admin']);

        $request = new Request([], [
            '_token'         => 'token_abc',
            'base_currency'  => 'USDT',
            'quote_currency' => 'BDT',
            'rate'           => '122.50',
            'effective_at'   => '2026-09-04 12:00:00',
            'expires_at'     => '2026-09-04 11:00:00', // Prior to effective
        ], ['REQUEST_METHOD' => 'POST']);

        $this->controller->handle($request);

        $this->assertStringContainsString('Expiration time must be strictly after effective time', $_SESSION['flash_error'] ?? '');
    }

    public function testStoreConfiguresValidAuthoritativeRateWithExactFactor(): void
    {
        $_SESSION['auth_user_id'] = 7;
        $_SESSION['_token'] = 'token_abc';
        $GLOBALS['_test_current_user'] = new OperatorUserStub(['id' => 7], ['super-admin']);

        $request = new Request([], [
            '_token'         => 'token_abc',
            'base_currency'  => 'USDT',
            'quote_currency' => 'BDT',
            'rate'           => '122.50',
            'notes'          => 'Weekly treasury rate',
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $this->controller->handle($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('configured successfully', $_SESSION['flash_success'] ?? '');

        // Verify in database
        $row = $this->db->selectOne("SELECT * FROM favorite_pay_rates WHERE base_currency = 'USDT' AND quote_currency = 'BDT' LIMIT 1");
        $this->assertNotNull($row);
        $this->assertSame(122500000, (int)$row->rate_factor);
        $this->assertSame(1000000, (int)$row->rate_scale);
        $this->assertSame('active', (string)$row->status);
        $this->assertSame(1, (int)$row->is_authoritative);
        $this->assertSame(7, (int)$row->operator_id);
        $this->assertSame('Weekly treasury rate', (string)$row->notes);
    }

    // ==========================================
    // 4. Overlap Prevention & Non-destructive Retirement
    // ==========================================

    public function testSubsequentRateRetiresPriorActiveRateWithoutDeletingIt(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'token_abc';
        $GLOBALS['_test_current_user'] = new OperatorUserStub([], ['super-admin']);

        // First rate: 120.00
        $req1 = new Request([], [
            '_token'         => 'token_abc',
            'base_currency'  => 'USDT',
            'quote_currency' => 'BDT',
            'rate'           => '120.00',
            'notes'          => 'Initial rate',
        ], ['REQUEST_METHOD' => 'POST']);
        $this->controller->handle($req1);

        $allRates1 = $this->rateProvider->getAllRates();
        $this->assertCount(1, $allRates1);
        $this->assertSame('active', $allRates1[0]['status']);
        $firstId = (int)$allRates1[0]['id'];

        // Second rate: 125.00
        $req2 = new Request([], [
            '_token'         => 'token_abc',
            'base_currency'  => 'USDT',
            'quote_currency' => 'BDT',
            'rate'           => '125.00',
            'notes'          => 'Updated rate',
        ], ['REQUEST_METHOD' => 'POST']);
        $this->controller->handle($req2);

        $allRates2 = $this->rateProvider->getAllRates();
        $this->assertCount(2, $allRates2); // HISTORICAL RECORD PRESERVED (NOT DELETED)

        // Check first rate is now 'retired'
        $oldRow = $this->rateProvider->getRateById($firstId);
        $this->assertNotNull($oldRow);
        $this->assertSame('retired', $oldRow['status']);
        $this->assertNotNull($oldRow['expires_at']);

        // Check provider returns newest active rate
        $activeSnapshot = $this->rateProvider->getRate('USDT', 'BDT');
        $this->assertNotNull($activeSnapshot);
        $this->assertSame(125000000, $activeSnapshot->getRateFactor());
    }

    // ==========================================
    // 5. Manual Deactivation Test
    // ==========================================

    public function testOperatorCanDeactivateRate(): void
    {
        $_SESSION['auth_user_id'] = 3;
        $_SESSION['_token'] = 'token_abc';
        $GLOBALS['_test_current_user'] = new OperatorUserStub(['id' => 3], ['super-admin']);

        // Create rate
        $reqCreate = new Request([], [
            '_token'         => 'token_abc',
            'base_currency'  => 'USDT',
            'quote_currency' => 'BDT',
            'rate'           => '122.50',
        ], ['REQUEST_METHOD' => 'POST']);
        $this->controller->handle($reqCreate);

        $activeRow = $this->rateProvider->getRate('USDT', 'BDT');
        $this->assertNotNull($activeRow);

        $rateRecord = $this->db->selectOne("SELECT id FROM favorite_pay_rates WHERE base_currency = 'USDT' LIMIT 1");
        $rateId = (int)($rateRecord->id ?? $rateRecord['id']);

        // Deactivate rate
        $reqDeactivate = new Request([], [
            '_token'  => 'token_abc',
            'action'  => 'deactivate',
            'rate_id' => $rateId,
        ], ['REQUEST_METHOD' => 'POST']);
        $this->controller->handle($reqDeactivate);

        $this->assertStringContainsString('has been deactivated', $_SESSION['flash_success'] ?? '');

        // Verify record still exists but is inactive
        $deactivatedRow = $this->rateProvider->getRateById($rateId);
        $this->assertNotNull($deactivatedRow);
        $this->assertSame('inactive', $deactivatedRow['status']);

        // Provider now returns null
        $this->assertNull($this->rateProvider->getRate('USDT', 'BDT'));
    }

    // ==========================================
    // 6. CurrencyService & Binance Checkout Integration
    // ==========================================

    public function testOperatorRateConverts120BdtToExactly1Usdt(): void
    {
        // Operator configures 1 USDT = 120.00 BDT
        $this->currencyService->setOperatorRate('USDT', '120.00', 1, 'BDT');

        // Verify direct rate
        $directRate = $this->currencyService->getRate('USDT', 'BDT');
        $this->assertTrue($directRate->isValidForPayment());
        $this->assertSame(120000000, $directRate->getRateFactor());

        // Verify inverse rate BDT -> USDT
        $inverseRate = $this->currencyService->getRate('BDT', 'USDT');
        $this->assertTrue($inverseRate->isValidForPayment());

        // Convert 120.00 BDT (12000 minor units) to USDT
        $bdtMoney = new Money(12000, 'BDT');
        $usdtMoney = $this->currencyService->convert($bdtMoney, 'USDT');

        $this->assertSame('USDT', $usdtMoney->getCurrency());
        $this->assertSame(100, $usdtMoney->getAmount()); // Exactly 1.00 USDT (100 cents)
        $this->assertSame('1.00', $usdtMoney->toMajorUnit());
    }

    public function testDeactivatingRateCausesCurrencyServiceToFailClosed(): void
    {
        // 1. Set operator rate
        $snap = $this->currencyService->setOperatorRate('USDT', '120.00', 1, 'BDT');
        $this->assertTrue($this->currencyService->hasRate('BDT', 'USDT'));

        // 2. Deactivate it in database
        $rateRow = $this->db->selectOne("SELECT id FROM favorite_pay_rates WHERE base_currency = 'USDT' LIMIT 1");
        $this->rateProvider->deactivateRate((int)($rateRow->id ?? $rateRow['id']), 1);

        // 3. Re-instantiate CurrencyService to simulate new HTTP request pulling from DB
        $freshCurrencyService = new CurrencyService($this->rateProvider, $this->db);

        $this->assertFalse($freshCurrencyService->hasRate('BDT', 'USDT'));

        $this->expectException(UnauthoritativeRateException::class);
        $freshCurrencyService->getRate('BDT', 'USDT');
    }

    // ==========================================
    // 7. BinancePayGateway Diagnostics Integration
    // ==========================================

    public function testBinanceGatewayDiagnosticsReflectOperatorRateStatus(): void
    {
        $mockTransport = function (): array {
            return ['statusCode' => 200, 'body' => json_encode(['status' => 'SUCCESS'])];
        };
        $client = new BinancePayHttpClient('cert_123', 'secret_456', BinancePayHttpClient::DEFAULT_BASE_URL, $mockTransport);

        // Gateway initially has no rate
        $gateway = new BinancePayGateway([
            'enabled'            => true,
            'certificate_sn'     => 'cert_123',
            'api_secret'         => 'secret_456',
            'preferred_currency' => 'USDT',
        ], $client, $this->db, $this->currencyService);

        // Initial state: missing rate
        $diag1 = $gateway->getConfigurationStatus();
        $this->assertSame('NOT_READY', $diag1['state']);
        $this->assertFalse($diag1['currency_compatible']);
        $this->assertStringContainsString('No valid authoritative rate', $diag1['rate_status']);

        // Now configure operator rate
        $this->currencyService->setOperatorRate('USDT', '122.50', 1, 'BDT');

        // Updated state: READY and Valid
        $diag2 = $gateway->getConfigurationStatus();
        $this->assertSame('READY', $diag2['state']);
        $this->assertTrue($diag2['currency_compatible']);
        $this->assertSame('Valid (Fresh)', $diag2['rate_status']);
    }
}
