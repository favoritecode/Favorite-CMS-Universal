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
use FavoriteCMS\Pay\Controllers\PaymentGatewaySettingsController;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Gateways\Binance\BinancePayGateway;
use FavoriteCMS\Pay\Gateways\Binance\BinancePayHttpClient;
use FavoriteCMS\Pay\Permissions\PaymentPermission;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

if (!class_exists('FavoriteCMS\\Tests\\Unit\\Plugins\\FavoritePay\\TestConfigUserStub')) {
    class TestConfigUserStub extends User
    {
        private array $rolesList;
        private array $permissionsList;

        public function __construct(array $attributes = [], array $roles = [], array $permissions = [])
        {
            $this->attributes = array_merge([
                'id'       => 1,
                'username' => 'testuser',
                'email'    => 'test@example.com',
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

class BinancePayConfigurationTest extends TestCase
{
    private Application $app;
    private PDO $pdo;
    private Database $db;
    private GatewayRegistry $registry;
    private BinancePayGateway $gateway;
    private PaymentGatewaySettingsController $controller;
    private array $interceptedRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        $GLOBALS['_test_current_user'] = null;

        $this->app = Application::getInstance();

        // In-memory SQLite Database using test pattern
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

        Setting::clearCache();

        $this->interceptedRequests = [];
        $mockTransport = function (string $method, string $url, array $headers, string $body): array {
            $this->interceptedRequests[] = [
                'method'  => $method,
                'url'     => $url,
                'headers' => $headers,
                'body'    => $body,
            ];
            return [
                'statusCode' => 200,
                'body'       => json_encode(['status' => 'SUCCESS', 'code' => '000000', 'data' => []]),
            ];
        };

        $client = new BinancePayHttpClient(null, null, BinancePayHttpClient::DEFAULT_BASE_URL, $mockTransport);

        $this->gateway = new BinancePayGateway([], $client, $this->db);
        $this->registry = new GatewayRegistry();
        $this->registry->register($this->gateway);

        $this->controller = new PaymentGatewaySettingsController($this->app, $this->registry);
    }

    protected function tearDown(): void
    {
        Setting::clearCache();
        FavoritePayPlugin::reset();
        parent::tearDown();
    }

    private function createAdminUser(int $id = 1, array $roles = ['admin']): TestConfigUserStub
    {
        $user = new TestConfigUserStub(
            ['id' => $id, 'username' => 'admin_user', 'email' => 'admin@example.com', 'status' => 'active'],
            $roles,
            ['manage_settings']
        );
        $GLOBALS['_test_current_user'] = $user;
        $_SESSION['auth_user_id'] = $id;
        $_SESSION['auth_user_name'] = 'admin_user';
        $_SESSION['_token'] = 'valid_csrf_token_12345';
        return $user;
    }

    private function createRegularUser(int $id = 2): TestConfigUserStub
    {
        $user = new TestConfigUserStub(
            ['id' => $id, 'username' => 'regular_user', 'email' => 'user@example.com', 'status' => 'active'],
            ['subscriber'],
            []
        );
        $GLOBALS['_test_current_user'] = $user;
        $_SESSION['auth_user_id'] = $id;
        $_SESSION['auth_user_name'] = 'regular_user';
        $_SESSION['_token'] = 'valid_csrf_token_12345';
        return $user;
    }

    // =========================================================================
    // 1. Gateway Defaults & Operational States
    // =========================================================================

    public function testGatewayDefaultsToDisabled(): void
    {
        $this->assertFalse($this->gateway->isEnabled());
        $this->assertFalse($this->gateway->isConfigured());
        $this->assertFalse($this->gateway->isReady('USDT'));

        $status = $this->gateway->getConfigurationStatus('USDT');
        $this->assertSame('DISABLED', $status['state']);
        $this->assertFalse($status['is_ready']);
    }

    public function testMissingCertificateSnPreventsReadiness(): void
    {
        $this->gateway->setConfig([
            'enabled'        => true,
            'certificate_sn' => '',
            'api_secret'     => 'test_api_secret_key_1234567890',
        ]);

        $this->assertTrue($this->gateway->isEnabled());
        $this->assertFalse($this->gateway->isConfigured());
        $this->assertFalse($this->gateway->isReady('USDT'));

        $status = $this->gateway->getConfigurationStatus('USDT');
        $this->assertSame('NOT_READY', $status['state']);
        $this->assertStringContainsString('Missing: Certificate-SN', $status['message']);
    }

    public function testMissingApiSecretPreventsReadiness(): void
    {
        $this->gateway->setConfig([
            'enabled'        => true,
            'certificate_sn' => 'cert_sn_test_123',
            'api_secret'     => '',
        ]);

        $this->assertTrue($this->gateway->isEnabled());
        $this->assertFalse($this->gateway->isConfigured());
        $this->assertFalse($this->gateway->isReady('USDT'));

        $status = $this->gateway->getConfigurationStatus('USDT');
        $this->assertSame('NOT_READY', $status['state']);
        $this->assertStringContainsString('Missing: API Secret Key', $status['message']);
    }

    public function testValidConfigurationReportsReadyForSupportedCurrency(): void
    {
        $this->gateway->setConfig([
            'enabled'        => true,
            'certificate_sn' => 'cert_sn_test_valid',
            'api_secret'     => 'secret_key_valid_1234567890',
        ]);

        $this->assertTrue($this->gateway->isEnabled());
        $this->assertTrue($this->gateway->isConfigured());
        $this->assertTrue($this->gateway->isReady('USDT'));

        $status = $this->gateway->getConfigurationStatus('USDT');
        $this->assertSame('READY', $status['state']);
        $this->assertTrue($status['is_ready']);
        $this->assertTrue($status['currency_compatible']);
    }

    // =========================================================================
    // 2. Secret Handling & Privacy
    // =========================================================================

    public function testExistingSecretIsNotReturnedByPublicConfigOrStatusApi(): void
    {
        $secret = 'super_confidential_secret_key_abcdef987654321';
        $this->gateway->setConfig([
            'enabled'        => true,
            'certificate_sn' => 'cert_sn_test_999',
            'api_secret'     => $secret,
        ]);

        $publicConfig = $this->gateway->getPublicConfig();
        $this->assertArrayNotHasKey('api_secret', $publicConfig);
        $this->assertTrue($publicConfig['has_api_secret']);

        $status = $this->gateway->getConfigurationStatus('USDT');
        $this->assertArrayNotHasKey('api_secret', $status);
        $this->assertTrue($status['has_api_secret']);

        $encodedStatus = json_encode($status);
        $this->assertStringNotContainsString($secret, $encodedStatus);
    }

    public function testBlankSecretUpdatePreservesExistingSecret(): void
    {
        $originalSecret = 'initial_merchant_secret_key_111';
        $this->gateway->setConfig([
            'enabled'        => true,
            'certificate_sn' => 'cert_orig',
            'api_secret'     => $originalSecret,
        ]);

        // Blank secret update
        $this->gateway->setConfig([
            'enabled'        => true,
            'certificate_sn' => 'cert_updated',
            'api_secret'     => '',
        ]);

        $rawConfig = $this->gateway->getConfig();
        $this->assertSame($originalSecret, $rawConfig['api_secret']);
        $this->assertSame('cert_updated', $rawConfig['certificate_sn']);
    }

    public function testReplacingSecretUpdatesIt(): void
    {
        $this->gateway->setConfig([
            'enabled'        => true,
            'certificate_sn' => 'cert_test',
            'api_secret'     => 'first_secret_111',
        ]);

        $newSecret = 'new_replacement_secret_222';
        $this->gateway->setConfig([
            'enabled'        => true,
            'certificate_sn' => 'cert_test',
            'api_secret'     => $newSecret,
        ]);

        $rawConfig = $this->gateway->getConfig();
        $this->assertSame($newSecret, $rawConfig['api_secret']);
    }

    public function testSecretDoesNotAppearInRenderedAdminHtml(): void
    {
        $secret = 'very_private_binance_secret_key_to_check';
        $this->gateway->setConfig([
            'enabled'        => true,
            'certificate_sn' => 'cert_sn_html_check',
            'api_secret'     => $secret,
        ]);

        $this->createAdminUser();
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);

        $response = $this->controller->handle($request);
        $html = is_string($response) ? $response : $response->getContent();

        $this->assertStringNotContainsString($secret, $html);
        $this->assertStringContainsString('Secret is saved securely', $html);
    }

    public function testSecretDoesNotAppearInExceptions(): void
    {
        $secret = 'secret_key_exception_test_xyz';
        $this->gateway->setConfig([
            'enabled'        => true,
            'certificate_sn' => 'cert_sn_test',
            'api_secret'     => $secret,
        ]);

        try {
            // Attempt with invalid currency
            $intent = new PaymentIntent(
                'pi_secret_test',
                'favorite_shop',
                'order_1',
                Money::bdt(5000),
                Money::bdt(5000),
                PaymentStatus::PENDING
            );
            $this->gateway->createAttempt($intent);
            $this->fail("Expected InvalidArgumentException");
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString($secret, $e->getMessage());
        }
    }

    // =========================================================================
    // 3. Security: SSRF Protection on Base URL
    // =========================================================================

    public function testArbitraryApiUrlCannotBeConfigured(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Disallowed Binance Pay API base URL');

        $this->gateway->validateConfig([
            'enabled'   => true,
            'base_url'  => 'http://attacker.example.com',
        ]);
    }

    public function testOfficialBaseUrlIsAllowed(): void
    {
        $validated = $this->gateway->validateConfig([
            'enabled'   => true,
            'base_url'  => 'https://bpay.binanceapi.com',
        ]);
        $this->assertSame('https://bpay.binanceapi.com', $validated['base_url']);
    }

    // =========================================================================
    // 4. Webhook URL Visibility & Dynamic Resolution
    // =========================================================================

    public function testWebhookUrlIsGeneratedFromApplicationBaseUrl(): void
    {
        Setting::set('general', 'site_url', 'https://merchant-store.com');
        Setting::clearCache();

        $webhookUrl = $this->gateway->getWebhookUrl();
        $this->assertSame('https://merchant-store.com/api/favorite-pay/webhook/binance_pay', $webhookUrl);

        $customBaseUrl = $this->gateway->getWebhookUrl('https://custom-domain.org');
        $this->assertSame('https://custom-domain.org/api/favorite-pay/webhook/binance_pay', $customBaseUrl);
    }

    public function testWebhookUrlContainsNoSecret(): void
    {
        $secret = 'secret_should_never_be_in_url_12345';
        $this->gateway->setConfig([
            'enabled'        => true,
            'certificate_sn' => 'cert_test',
            'api_secret'     => $secret,
        ]);

        $url = $this->gateway->getWebhookUrl('https://my-store.com');
        $this->assertStringNotContainsString($secret, $url);
        $this->assertSame('https://my-store.com/api/favorite-pay/webhook/binance_pay', $url);
    }

    // =========================================================================
    // 5. Primary Currency Compatibility
    // =========================================================================

    public function testUnsupportedPrimaryCurrencyWithoutRateReportsNotReady(): void
    {
        $this->gateway->setConfig([
            'enabled'        => true,
            'certificate_sn' => 'cert_sn_test',
            'api_secret'     => 'sec_key_test',
        ]);

        // When no rate is configured for BDT to USDT, status reports NOT_READY
        $status = $this->gateway->getConfigurationStatus('BDT');
        $this->assertSame('NOT_READY', $status['state']);
        $this->assertFalse($status['currency_compatible']);
        $this->assertFalse($status['is_ready']);
        $this->assertSame('NOT_READY', $status['currency_conversion']);
        $this->assertSame('None', $status['rate_source']);
        $this->assertStringContainsString('Exchange rate conversion is not available', $status['message']);
    }

    public function testPrimaryCurrencyWithAuthoritativeRateReportsReady(): void
    {
        $this->gateway->setConfig([
            'enabled'        => true,
            'certificate_sn' => 'cert_sn_test',
            'api_secret'     => 'sec_key_test',
        ]);

        $provider = new \FavoriteCMS\Pay\Providers\InMemoryExchangeRateProvider();
        $provider->setRate('BDT', 'USDT', '0.010417', true, null, 'operator');
        $currencyService = new \FavoriteCMS\Pay\Services\CurrencyService($provider);
        $this->gateway->setCurrencyService($currencyService);

        $status = $this->gateway->getConfigurationStatus('BDT');
        $this->assertSame('READY', $status['state']);
        $this->assertTrue($status['currency_compatible']);
        $this->assertTrue($status['is_ready']);
        $this->assertSame('READY', $status['currency_conversion']);
        $this->assertSame('Operator', $status['rate_source']);
        $this->assertSame('Valid (Fresh)', $status['rate_status']);
        $this->assertStringContainsString('Orders in BDT will be converted to USDT', $status['message']);
    }

    public function testNativeAcquiringCurrencyReportsReadyWithoutConversion(): void
    {
        $this->gateway->setConfig([
            'enabled'        => true,
            'certificate_sn' => 'cert_sn_test',
            'api_secret'     => 'sec_key_test',
        ]);

        // USDT is preferred acquiring currency, so primary currency USDT requires no conversion
        $status = $this->gateway->getConfigurationStatus('USDT');
        $this->assertSame('READY', $status['state']);
        $this->assertTrue($status['currency_compatible']);
        $this->assertTrue($status['is_ready']);
        $this->assertSame('READY', $status['currency_conversion']);
        $this->assertSame('Identity', $status['rate_source']);
    }

    // =========================================================================
    // 6. Runtime Safety: Disabled & Incomplete Gateways Block Live Calls
    // =========================================================================

    public function testDisabledGatewayDoesNotInitiateApiCalls(): void
    {
        $this->gateway->setConfig([
            'enabled'        => false,
            'certificate_sn' => 'cert_sn_test',
            'api_secret'     => 'sec_key_test',
        ]);

        $intent = new PaymentIntent(
            'pi_disabled_call',
            'favorite_shop',
            'order_1',
            new Money(5000, 'USDT'),
            new Money(5000, 'USDT'),
            PaymentStatus::PENDING
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Binance Pay gateway is disabled.');

        try {
            $this->gateway->createAttempt($intent);
        } finally {
            $this->assertCount(0, $this->interceptedRequests, "API request must never be sent when gateway is disabled.");
        }
    }

    public function testIncompleteGatewayDoesNotInitiateApiCalls(): void
    {
        $this->gateway->setConfig([
            'enabled'        => true,
            'certificate_sn' => '',
            'api_secret'     => '',
        ]);

        $intent = new PaymentIntent(
            'pi_incomplete_call',
            'favorite_shop',
            'order_1',
            new Money(5000, 'USDT'),
            new Money(5000, 'USDT'),
            PaymentStatus::PENDING
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Binance Pay is not configured');

        try {
            $this->gateway->createAttempt($intent);
        } finally {
            $this->assertCount(0, $this->interceptedRequests, "API request must never be sent when gateway is incomplete.");
        }
    }

    // =========================================================================
    // 7. Non-Financial Safety: Config Validation Never Modifies Ledgers
    // =========================================================================

    public function testConfigurationValidationDoesNotCreatePaymentTransactionsOrLedger(): void
    {
        $this->createAdminUser();

        $postData = [
            '_token'         => 'valid_csrf_token_12345',
            'enabled'        => '1',
            'certificate_sn' => 'cert_sn_config_test',
            'api_secret'     => 'sec_config_test_abcdef',
            'sandbox'        => '0',
        ];

        $request = new Request([], $postData, ['REQUEST_METHOD' => 'POST']);
        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());

        // Check zero attempts created
        $attempts = $this->db->select("SELECT * FROM favorite_pay_attempts");
        $this->assertCount(0, $attempts);

        // Check zero transactions created
        $transactions = $this->db->select("SELECT * FROM favorite_pay_transactions");
        $this->assertCount(0, $transactions);

        // Check zero wallet ledger entries created
        $ledger = $this->db->select("SELECT * FROM favorite_pay_wallet_entries");
        $this->assertCount(0, $ledger);
    }

    // =========================================================================
    // 8. Admin Authorization & Security Controls
    // =========================================================================

    public function testAuthorizedAdminCanUpdateConfiguration(): void
    {
        $this->createAdminUser();

        $postData = [
            '_token'         => 'valid_csrf_token_12345',
            'enabled'        => '1',
            'certificate_sn' => 'cert_sn_admin_updated',
            'api_secret'     => 'new_admin_secret_12345',
            'sandbox'        => '1',
        ];

        $request = new Request([], $postData, ['REQUEST_METHOD' => 'POST']);
        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());

        // Check in-memory gateway updated
        $this->assertTrue($this->gateway->isEnabled());
        $this->assertSame('cert_sn_admin_updated', $this->gateway->getConfig()['certificate_sn']);
        $this->assertSame('new_admin_secret_12345', $this->gateway->getConfig()['api_secret']);

        // Check persisted to Settings table
        Setting::clearCache();
        $saved = Setting::getGroup('favorite_pay_binance');
        $this->assertSame('1', (string)$saved['enabled']);
        $this->assertSame('cert_sn_admin_updated', $saved['certificate_sn']);
        $this->assertSame('new_admin_secret_12345', $saved['api_secret']);
        $this->assertSame('1', (string)$saved['sandbox']);
    }

    public function testUnauthorizedUserCannotUpdateConfiguration(): void
    {
        $this->createRegularUser();

        $postData = [
            '_token'         => 'valid_csrf_token_12345',
            'enabled'        => '1',
            'certificate_sn' => 'attacker_cert',
            'api_secret'     => 'attacker_secret',
        ];

        $request = new Request([], $postData, ['REQUEST_METHOD' => 'POST']);
        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());

        // Gateway remains unconfigured
        $this->assertFalse($this->gateway->isEnabled());
        $this->assertEmpty($this->gateway->getConfig()['certificate_sn'] ?? '');
    }

    public function testCsrfFailureBlocksConfigurationUpdate(): void
    {
        $this->createAdminUser();

        $postData = [
            '_token'         => 'invalid_csrf_token_attack',
            'enabled'        => '1',
            'certificate_sn' => 'csrf_fail_cert',
            'api_secret'     => 'csrf_fail_secret',
        ];

        $request = new Request([], $postData, ['REQUEST_METHOD' => 'POST']);
        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('CSRF', $_SESSION['flash_error'] ?? '');

        // Configuration was not changed
        $this->assertFalse($this->gateway->isEnabled());
    }

    public function testGetRequestCannotChangeConfiguration(): void
    {
        $this->createAdminUser();

        // Attempting to pass update parameters in GET query
        $getData = [
            'enabled'        => '1',
            'certificate_sn' => 'malicious_get_cert',
            'api_secret'     => 'malicious_get_secret',
        ];

        $request = new Request($getData, [], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $response = $this->controller->handle($request);

        $this->assertIsString($response);

        // Configuration was untouched
        $this->assertFalse($this->gateway->isEnabled());
        $this->assertEmpty($this->gateway->getConfig()['certificate_sn'] ?? '');
    }
}
