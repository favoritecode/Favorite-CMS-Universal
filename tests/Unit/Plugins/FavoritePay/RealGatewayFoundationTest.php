<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use CreateFavoritePayTables;
use CreateSettingsTable;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Currency;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Pay\Contracts\ConfigurableGatewayInterface;
use FavoriteCMS\Pay\Contracts\PaymentGatewayInterface;
use FavoriteCMS\Pay\Contracts\RedirectPaymentGatewayInterface;
use FavoriteCMS\Pay\Contracts\RefundableGatewayInterface;
use FavoriteCMS\Pay\Contracts\StatusQueryableGatewayInterface;
use FavoriteCMS\Pay\Contracts\WebhookGatewayInterface;
use FavoriteCMS\Pay\Controllers\PaymentWebhookController;
use FavoriteCMS\Pay\Domain\GatewayRefundResult;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Domain\VerifiedWebhookResult;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\RefundService;
use FavoriteCMS\Pay\Services\WalletService;
use FavoriteCMS\Pay\Services\WebhookService;
use FavoriteCMS\Pay\Support\SafeLogger;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Mock Automated Payment Gateway Driver for Testing
 */
class MockAutomatedGateway implements
    PaymentGatewayInterface,
    WebhookGatewayInterface,
    RefundableGatewayInterface,
    RedirectPaymentGatewayInterface,
    StatusQueryableGatewayInterface,
    ConfigurableGatewayInterface
{
    private string $id;
    private string $title;
    private bool $enabled;
    private array $supportedCurrencies;
    private array $config;

    public function __construct(
        string $id = 'mock_automated',
        string $title = 'Mock Automated Gateway',
        bool $enabled = true,
        array $supportedCurrencies = ['BDT', 'USD'],
        array $config = ['api_key' => 'secret_mock_key', 'webhook_secret' => 'whsec_test_secret']
    ) {
        $this->id = $id;
        $this->title = $title;
        $this->enabled = $enabled;
        $this->supportedCurrencies = $supportedCurrencies;
        $this->config = $config;
    }

    public function getId(): string { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function getType(): PaymentMethodType { return PaymentMethodType::CARD; }
    public function isEnabled(): bool { return $this->enabled; }
    public function getSupportedCurrencies(): array { return $this->supportedCurrencies; }
    public function getInstructions(array $context = []): array { return []; }

    public function createAttempt(PaymentIntent $intent, array $params = []): PaymentAttempt
    {
        return new PaymentAttempt(
            'att_' . bin2hex(random_bytes(8)),
            $intent->getId(),
            $this->id,
            $intent->getChargeAmount(),
            PaymentStatus::PENDING,
            $params['provider_reference'] ?? null,
            null,
            null,
            null,
            null,
            $params['idempotency_key'] ?? null
        );
    }

    public function verifyAttempt(PaymentAttempt $attempt, array $verificationData = []): PaymentAttempt
    {
        return $attempt;
    }

    public function verifyWebhook(array $headers, string|array $payload): VerifiedWebhookResult
    {
        $signature = $headers['x-mock-signature'] ?? ($headers['X-Mock-Signature'] ?? '');
        if ($signature !== 'valid-mock-signature') {
            return VerifiedWebhookResult::rejected("Invalid mock webhook signature.");
        }

        $data = is_array($payload) ? $payload : (json_decode($payload, true) ?: []);
        if (empty($data['transaction_id'])) {
            return VerifiedWebhookResult::rejected("Missing transaction_id in webhook payload.");
        }

        $status = ($data['event'] ?? '') === 'payment.succeeded'
            ? PaymentStatus::SUCCEEDED
            : PaymentStatus::FAILED;

        $amountMinor = (int)($data['amount_minor'] ?? 0);
        $currency = (string)($data['currency'] ?? 'BDT');
        $money = new Money($amountMinor, $currency);

        return new VerifiedWebhookResult(
            true,
            (string)$data['transaction_id'],
            $data['provider_reference'] ?? 'prov_ref_test',
            $status,
            $money,
            null,
            $data
        );
    }

    public function refund(string $transactionId, Money $amount, string $reason = ''): GatewayRefundResult
    {
        if ($reason === 'force_failure') {
            return GatewayRefundResult::failure("Provider declined refund request.");
        }

        return GatewayRefundResult::success(
            'ref_mock_prov_' . bin2hex(random_bytes(4)),
            $amount,
            ['provider_status' => 'refunded']
        );
    }

    public function getRedirectUrl(PaymentAttempt $attempt): ?string
    {
        return "https://mock-gateway.example.com/pay/" . $attempt->getId();
    }

    public function getRedirectMethod(): string
    {
        return 'GET';
    }

    public function getRedirectPayload(PaymentAttempt $attempt): array
    {
        return ['session_id' => 'sess_' . $attempt->getId()];
    }

    public function queryStatus(PaymentAttempt $attempt): PaymentStatus
    {
        return PaymentStatus::SUCCEEDED;
    }

    public function getConfigSchema(): array
    {
        return [
            'api_key' => [
                'type' => 'text',
                'label' => 'API Key',
                'required' => true,
                'secret' => true,
            ],
            'webhook_secret' => [
                'type' => 'text',
                'label' => 'Webhook Secret',
                'required' => true,
                'secret' => true,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        return $config;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }
}

/**
 * Phase 6: Real Gateway Foundation Tests
 */
class RealGatewayFoundationTest extends TestCase
{
    private Database $db;
    private PDO $pdo;
    private Application $app;
    private CurrencyService $currencyService;
    private GatewayRegistry $registry;
    private PaymentService $paymentService;
    private WalletService $walletService;
    private RefundService $refundService;
    private WebhookService $webhookService;
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

        // Migrations
        require_once APP_ROOT . '/database/migrations/009_create_settings_table.php';
        $coreMigration = new CreateSettingsTable($this->db);
        $coreMigration->up();

        require_once APP_ROOT . '/plugins/favorite-pay/database/migrations/001_create_favorite_pay_tables.php';
        $payMigration = new CreateFavoritePayTables($this->db);
        $payMigration->up();

        // Application Container
        $this->app = Application::getInstance();
        $this->app->instance(Database::class, $this->db);

        $this->currencyService = new CurrencyService();
        $this->registry = new GatewayRegistry(true);

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

        $this->refundService = new RefundService(
            $this->paymentService,
            $this->registry,
            $this->db
        );

        $this->webhookService = new WebhookService(
            $this->registry,
            $this->paymentService
        );

        $this->app->instance(Database::class, $this->db);
        $this->app->instance(GatewayRegistry::class, $this->registry);
        $this->app->instance(PaymentService::class, $this->paymentService);
        $this->app->instance(WalletService::class, $this->walletService);
        $this->app->instance(RefundService::class, $this->refundService);
        $this->app->instance(WebhookService::class, $this->webhookService);
        $this->app->instance(\FavoriteCMS\Pay\Contracts\WebhookServiceInterface::class, $this->webhookService);
        $this->app->instance(\FavoriteCMS\Pay\Contracts\PaymentServiceInterface::class, $this->paymentService);
        $this->app->instance(\FavoriteCMS\Pay\Contracts\WalletServiceInterface::class, $this->walletService);
        $this->app->instance(\FavoriteCMS\Pay\Contracts\RefundServiceInterface::class, $this->refundService);

        $this->plugin = new FavoritePayPlugin($this->app);
        $this->plugin->boot();

        Setting::set('general', 'primary_currency', 'BDT', 'string');
    }

    protected function tearDown(): void
    {
        FavoritePayPlugin::reset();
        Setting::clearCache();
    }

    /**
     * 1. Existing manual gateways still register correctly.
     */
    public function testExistingManualGatewaysRegisterCorrectly(): void
    {
        $expected = ['manual_bd', 'manual_bkash', 'manual_nagad', 'manual_bank'];
        foreach ($expected as $id) {
            $this->assertTrue($this->registry->has($id), "Gateway {$id} must be registered.");
            $gw = $this->registry->get($id);
            $this->assertSame($id, $gw->getId());
            $this->assertTrue($gw->isEnabled());
        }

        // Aliases work
        $this->assertSame('manual_bkash', $this->registry->get('bkash_manual')->getId());
        $this->assertSame('manual_nagad', $this->registry->get('nagad_manual')->getId());
        $this->assertSame('manual_bank', $this->registry->get('bank_manual')->getId());
    }

    /**
     * 2. Duplicate gateway registration fails.
     */
    public function testDuplicateGatewayRegistrationFails(): void
    {
        $gateway = new MockAutomatedGateway('mock_duplicate');
        $this->registry->register($gateway);
        $this->assertTrue($this->registry->has('mock_duplicate'));

        // Attempting duplicate registration must throw InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("already registered");
        $this->registry->register(new MockAutomatedGateway('mock_duplicate'));
    }

    /**
     * 3. Unknown gateway lookup fails safely.
     */
    public function testUnknownGatewayLookupFailsSafely(): void
    {
        $this->assertFalse($this->registry->has('non_existent_gateway'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Payment gateway not registered: non_existent_gateway");
        $this->registry->get('non_existent_gateway');
    }

    /**
     * 4. Manual gateway does not need automated webhook capability.
     */
    public function testManualGatewayDoesNotNeedAutomatedWebhookCapability(): void
    {
        $manual = $this->registry->get('manual_bkash');
        $this->assertInstanceOf(PaymentGatewayInterface::class, $manual);
        $this->assertNotInstanceOf(WebhookGatewayInterface::class, $manual);
        $this->assertNotInstanceOf(RefundableGatewayInterface::class, $manual);

        $caps = $this->registry->getCapabilities('manual_bkash');
        $this->assertFalse($caps['supports_webhook']);
        $this->assertFalse($caps['supports_refund']);
        $this->assertTrue($caps['is_configurable']);
    }

    /**
     * 5. Automated-style mock gateway can implement the new capability contract.
     */
    public function testAutomatedStyleMockGatewayImplementsCapabilityContracts(): void
    {
        $mock = new MockAutomatedGateway('mock_full');
        $this->registry->register($mock);

        $this->assertInstanceOf(PaymentGatewayInterface::class, $mock);
        $this->assertInstanceOf(WebhookGatewayInterface::class, $mock);
        $this->assertInstanceOf(RefundableGatewayInterface::class, $mock);
        $this->assertInstanceOf(RedirectPaymentGatewayInterface::class, $mock);
        $this->assertInstanceOf(StatusQueryableGatewayInterface::class, $mock);
        $this->assertInstanceOf(ConfigurableGatewayInterface::class, $mock);

        $caps = $this->registry->getCapabilities('mock_full');
        $this->assertTrue($caps['supports_webhook']);
        $this->assertTrue($caps['supports_refund']);
        $this->assertTrue($caps['supports_redirect']);
        $this->assertTrue($caps['supports_query']);
        $this->assertTrue($caps['is_configurable']);
    }

    /**
     * 6. Unverified webhook cannot mark payment successful.
     */
    public function testUnverifiedWebhookCannotMarkPaymentSuccessful(): void
    {
        $mock = new MockAutomatedGateway('mock_auto_6');
        $this->registry->register($mock);

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_6',
            Money::bdt(10000),
            ['customer_id' => 1]
        );

        $attempt = $mock->createAttempt($intent, ['provider_reference' => 'mock_tx_6']);
        $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'DUMMY_TRX');

        // Send webhook WITHOUT signature
        $result = $this->webhookService->handle(
            'mock_auto_6',
            ['X-Mock-Signature' => 'invalid-signature'],
            ['transaction_id' => $intent->getId(), 'event' => 'payment.succeeded']
        );

        $this->assertFalse($result->isSuccess());
        $this->assertSame(401, $result->getStatusCode());
        $this->assertStringContainsString('signature', $result->getMessage());

        // Intent must NOT be succeeded
        $currentIntent = $this->paymentService->getIntent($intent->getId());
        $this->assertNotSame(PaymentStatus::SUCCEEDED, $currentIntent->getStatus());
    }

    /**
     * 7. Invalid webhook signature is rejected.
     */
    public function testInvalidWebhookSignatureIsRejected(): void
    {
        $mock = new MockAutomatedGateway('mock_auto_7');
        $this->registry->register($mock);

        $headers = ['X-Mock-Signature' => 'forged_fake_sig'];
        $payload = ['transaction_id' => 'tx_test_7'];

        $verified = $mock->verifyWebhook($headers, $payload);
        $this->assertFalse($verified->isVerified());
        $this->assertStringContainsString("signature", $verified->getErrorMessage() ?? '');
    }

    /**
     * 8. Verified webhook can reach PaymentService and update attempt/intent.
     */
    public function testVerifiedWebhookReachesPaymentService(): void
    {
        $mock = new MockAutomatedGateway('mock_auto_8');
        $this->registry->register($mock);

        $userId = 88;
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_8',
            Money::bdt(20000),
            ['customer_id' => $userId]
        );

        // Initiate attempt through mock gateway
        $attempt = $mock->createAttempt($intent, [
            'provider_reference' => 'prov_ref_8',
            'idempotency_key'    => 'idemp_8',
        ]);
        // Insert attempt into database and memory
        $this->db->insert('favorite_pay_attempts', [
            'attempt_id'         => $attempt->getId(),
            'transaction_id'     => $intent->getId(),
            'gateway_id'         => 'mock_auto_8',
            'amount'             => 20000,
            'currency'           => 'BDT',
            'status'             => 'pending',
            'provider_reference' => 'prov_ref_8',
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $headers = ['X-Mock-Signature' => 'valid-mock-signature'];
        $payload = [
            'transaction_id'     => $intent->getId(),
            'provider_reference' => 'prov_ref_8',
            'amount_minor'       => 20000,
            'currency'           => 'BDT',
            'event'              => 'payment.succeeded',
        ];

        $result = $this->webhookService->handle('mock_auto_8', $headers, $payload);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(200, $result->getStatusCode());

        $updatedIntent = $this->paymentService->getIntent($intent->getId());
        $this->assertSame(PaymentStatus::SUCCEEDED, $updatedIntent->getStatus());

        // Event triggered WalletService auto-settlement!
        $wallet = $this->walletService->getBalance($userId);
        $this->assertSame('BDT', $wallet->getCurrency());
        $this->assertSame(20000, $wallet->getAmount());
    }

    /**
     * 9. Repeated webhook delivery is idempotent.
     */
    public function testRepeatedWebhookDeliveryIsIdempotent(): void
    {
        $mock = new MockAutomatedGateway('mock_auto_9');
        $this->registry->register($mock);

        $userId = 99;
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_9',
            Money::bdt(15000),
            ['customer_id' => $userId]
        );

        $attempt = $mock->createAttempt($intent, ['provider_reference' => 'prov_ref_9']);
        $this->db->insert('favorite_pay_attempts', [
            'attempt_id'         => $attempt->getId(),
            'transaction_id'     => $intent->getId(),
            'gateway_id'         => 'mock_auto_9',
            'amount'             => 15000,
            'currency'           => 'BDT',
            'status'             => 'pending',
            'provider_reference' => 'prov_ref_9',
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $headers = ['X-Mock-Signature' => 'valid-mock-signature'];
        $payload = [
            'transaction_id'     => $intent->getId(),
            'provider_reference' => 'prov_ref_9',
            'amount_minor'       => 15000,
            'currency'           => 'BDT',
            'event'              => 'payment.succeeded',
        ];

        // First delivery
        $res1 = $this->webhookService->handle('mock_auto_9', $headers, $payload);
        $this->assertTrue($res1->isSuccess());
        $this->assertFalse($res1->isAlreadyProcessed());

        // Second duplicate delivery
        $res2 = $this->webhookService->handle('mock_auto_9', $headers, $payload);
        $this->assertTrue($res2->isSuccess());
        $this->assertTrue($res2->isAlreadyProcessed());
    }

    /**
     * 10. Repeated payment success cannot create duplicate wallet settlement.
     */
    public function testRepeatedPaymentSuccessCannotCreateDuplicateWalletSettlement(): void
    {
        $mock = new MockAutomatedGateway('mock_auto_10');
        $this->registry->register($mock);

        $userId = 100;
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_10',
            Money::bdt(35000),
            ['customer_id' => $userId]
        );

        $attempt = $mock->createAttempt($intent, ['provider_reference' => 'prov_ref_10']);
        $this->db->insert('favorite_pay_attempts', [
            'attempt_id'         => $attempt->getId(),
            'transaction_id'     => $intent->getId(),
            'gateway_id'         => 'mock_auto_10',
            'amount'             => 35000,
            'currency'           => 'BDT',
            'status'             => 'pending',
            'provider_reference' => 'prov_ref_10',
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $headers = ['X-Mock-Signature' => 'valid-mock-signature'];
        $payload = [
            'transaction_id'     => $intent->getId(),
            'provider_reference' => 'prov_ref_10',
            'amount_minor'       => 35000,
            'currency'           => 'BDT',
            'event'              => 'payment.succeeded',
        ];

        // Deliver webhook 3 times
        $this->webhookService->handle('mock_auto_10', $headers, $payload);
        $this->webhookService->handle('mock_auto_10', $headers, $payload);
        $this->webhookService->handle('mock_auto_10', $headers, $payload);

        // Wallet credited exactly ONCE
        $balance = $this->walletService->getBalance($userId);
        $this->assertSame(35000, $balance->getAmount());

        $entries = $this->db->select("SELECT * FROM favorite_pay_wallet_entries WHERE user_id = ?", [$userId]);
        $this->assertCount(1, $entries, "Must have exactly 1 ledger entry, no duplicate settlement.");
    }

    /**
     * 11. Webhook amount mismatch is rejected.
     */
    public function testWebhookAmountMismatchIsRejected(): void
    {
        $mock = new MockAutomatedGateway('mock_auto_11');
        $this->registry->register($mock);

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_11',
            Money::bdt(50000), // 500.00 BDT
            ['customer_id' => 11]
        );

        $attempt = $mock->createAttempt($intent, ['provider_reference' => 'prov_ref_11']);
        $this->db->insert('favorite_pay_attempts', [
            'attempt_id'         => $attempt->getId(),
            'transaction_id'     => $intent->getId(),
            'gateway_id'         => 'mock_auto_11',
            'amount'             => 50000,
            'currency'           => 'BDT',
            'status'             => 'pending',
            'provider_reference' => 'prov_ref_11',
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $headers = ['X-Mock-Signature' => 'valid-mock-signature'];
        // Received only 10,000 instead of 50,000!
        $payload = [
            'transaction_id'     => $intent->getId(),
            'provider_reference' => 'prov_ref_11',
            'amount_minor'       => 10000,
            'currency'           => 'BDT',
            'event'              => 'payment.succeeded',
        ];

        $result = $this->webhookService->handle('mock_auto_11', $headers, $payload);
        $this->assertFalse($result->isSuccess());
        $this->assertSame(422, $result->getStatusCode());
        $this->assertStringContainsString('mismatch', $result->getMessage());

        $currentIntent = $this->paymentService->getIntent($intent->getId());
        $this->assertNotSame(PaymentStatus::SUCCEEDED, $currentIntent->getStatus());
    }

    /**
     * 12. Webhook currency mismatch is rejected.
     */
    public function testWebhookCurrencyMismatchIsRejected(): void
    {
        $mock = new MockAutomatedGateway('mock_auto_12');
        $this->registry->register($mock);

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_12',
            Money::bdt(20000), // Expected BDT
            ['customer_id' => 12]
        );

        $attempt = $mock->createAttempt($intent, ['provider_reference' => 'prov_ref_12']);
        $this->db->insert('favorite_pay_attempts', [
            'attempt_id'         => $attempt->getId(),
            'transaction_id'     => $intent->getId(),
            'gateway_id'         => 'mock_auto_12',
            'amount'             => 20000,
            'currency'           => 'BDT',
            'status'             => 'pending',
            'provider_reference' => 'prov_ref_12',
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $headers = ['X-Mock-Signature' => 'valid-mock-signature'];
        // Received USD instead of BDT
        $payload = [
            'transaction_id'     => $intent->getId(),
            'provider_reference' => 'prov_ref_12',
            'amount_minor'       => 20000,
            'currency'           => 'USD',
            'event'              => 'payment.succeeded',
        ];

        $result = $this->webhookService->handle('mock_auto_12', $headers, $payload);
        $this->assertFalse($result->isSuccess());
        $this->assertSame(422, $result->getStatusCode());
        $this->assertStringContainsString('mismatch', $result->getMessage());
    }

    /**
     * 13. Currency mismatch does not credit wallet.
     */
    public function testCurrencyMismatchDoesNotCreditWallet(): void
    {
        $mock = new MockAutomatedGateway('mock_auto_13');
        $this->registry->register($mock);

        $userId = 130;
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_13',
            Money::bdt(10000),
            ['customer_id' => $userId]
        );

        $attempt = $mock->createAttempt($intent, ['provider_reference' => 'prov_ref_13']);
        $this->db->insert('favorite_pay_attempts', [
            'attempt_id'         => $attempt->getId(),
            'transaction_id'     => $intent->getId(),
            'gateway_id'         => 'mock_auto_13',
            'amount'             => 10000,
            'currency'           => 'BDT',
            'status'             => 'pending',
            'provider_reference' => 'prov_ref_13',
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        // Attempt webhook with mismatched currency
        $this->webhookService->handle(
            'mock_auto_13',
            ['X-Mock-Signature' => 'valid-mock-signature'],
            [
                'transaction_id'     => $intent->getId(),
                'provider_reference' => 'prov_ref_13',
                'amount_minor'       => 10000,
                'currency'           => 'EUR',
                'event'              => 'payment.succeeded',
            ]
        );

        // Wallet must have 0 balance
        $balance = $this->walletService->getBalance($userId);
        $this->assertSame(0, $balance->getAmount());

        $entries = $this->db->select("SELECT * FROM favorite_pay_wallet_entries WHERE user_id = ?", [$userId]);
        $this->assertCount(0, $entries);
    }

    /**
     * 14. Gateway cannot directly mutate wallet balance through the intended architecture.
     */
    public function testGatewayCannotDirectlyMutateWalletBalance(): void
    {
        // Gateway driver class does NOT have reference or access to WalletService
        $mock = new MockAutomatedGateway('mock_auto_14');
        $this->registry->register($mock);

        $ref = new \ReflectionClass($mock);
        $this->assertFalse($ref->hasProperty('walletService'));
        $this->assertFalse($ref->hasMethod('creditWallet'));
        $this->assertFalse($ref->hasMethod('settleWallet'));
    }

    /**
     * 15. Unsupported refund capability fails cleanly.
     */
    public function testUnsupportedRefundCapabilityFailsCleanly(): void
    {
        $userId = 150;
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_manual_refund',
            Money::bdt(8000),
            ['customer_id' => $userId]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        // Manual gateway does NOT implement RefundableGatewayInterface
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("does not support automated refunds");
        $this->refundService->createGatewayRefund($intent->getId(), Money::bdt(8000), 'Customer cancel', 'manual_bkash');
    }

    /**
     * 16. Gateway secrets are not exposed in logs/errors.
     */
    public function testGatewaySecretsAreNotExposedInLogsOrErrors(): void
    {
        $sensitiveContext = [
            'gateway_id'     => 'stripe_test',
            'api_key'        => 'sk_live_very_secret_key_12345',
            'webhook_secret' => 'whsec_secret_signing_key_abcde',
            'pan'            => '4111111111111111',
            'cvv'            => '123',
            'card_number'    => '4111-2222-3333-4444',
            'normal_field'   => 'normal_value',
        ];

        $sanitized = SafeLogger::sanitize($sensitiveContext);

        $this->assertSame('[REDACTED]', $sanitized['api_key']);
        $this->assertSame('[REDACTED]', $sanitized['webhook_secret']);
        $this->assertSame('[REDACTED]', $sanitized['pan']);
        $this->assertSame('[REDACTED]', $sanitized['cvv']);
        $this->assertSame('[REDACTED]', $sanitized['card_number']);
        $this->assertSame('normal_value', $sanitized['normal_field']);
        $this->assertSame('stripe_test', $sanitized['gateway_id']);
    }

    /**
     * 17. Automated gateway refund succeeds when supported.
     */
    public function testAutomatedGatewayRefundSucceedsWhenSupported(): void
    {
        $mock = new MockAutomatedGateway('mock_auto_17');
        $this->registry->register($mock);

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_refund_success',
            Money::bdt(12000),
            ['customer_id' => 170]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        $refund = $this->refundService->createGatewayRefund(
            $intent->getId(),
            Money::bdt(12000),
            'Return requested',
            'mock_auto_17'
        );

        $this->assertNotEmpty($refund['id']);
        $this->assertSame(12000, $refund['amount']);
        $this->assertSame('BDT', $refund['currency']);
        $this->assertNotEmpty($refund['provider_refund_reference']);

        $updatedIntent = $this->paymentService->getIntent($intent->getId());
        $this->assertSame(PaymentStatus::REFUNDED, $updatedIntent->getStatus());
    }

    /**
     * 18. Webhook Controller handles HTTP requests and returns JSON response.
     */
    public function testPaymentWebhookControllerHandlesRequest(): void
    {
        $mock = new MockAutomatedGateway('mock_auto_18');
        $this->registry->register($mock);

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_18',
            Money::bdt(25000),
            ['customer_id' => 180]
        );

        $attempt = $mock->createAttempt($intent, ['provider_reference' => 'prov_18']);
        $this->db->insert('favorite_pay_attempts', [
            'attempt_id'         => $attempt->getId(),
            'transaction_id'     => $intent->getId(),
            'gateway_id'         => 'mock_auto_18',
            'amount'             => 25000,
            'currency'           => 'BDT',
            'status'             => 'pending',
            'provider_reference' => 'prov_18',
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $controller = new PaymentWebhookController($this->app, $this->webhookService);

        $_SERVER['HTTP_X_MOCK_SIGNATURE'] = 'valid-mock-signature';
        $postData = [
            'transaction_id'     => $intent->getId(),
            'provider_reference' => 'prov_18',
            'amount_minor'       => 25000,
            'currency'           => 'BDT',
            'event'              => 'payment.succeeded',
        ];

        $request = new Request([], $postData, [], [], [], ['REQUEST_METHOD' => 'POST']);
        $response = $controller->handle($request, 'mock_auto_18');

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('success', $body['status']);
        $this->assertFalse($body['already_processed']);
    }
}
