<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Hook;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\PaymentGatewayInterface;
use FavoriteCMS\Pay\Contracts\StatusQueryableGatewayInterface;
use FavoriteCMS\Pay\Controllers\CustomerAccountController;
use FavoriteCMS\Pay\Domain\ConversionSnapshot;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\WalletService;
use PHPUnit\Framework\TestCase;

class BinanceTestUserStub extends User
{
    public array $attributes = [];
    public array $rolesList = ['customer'];
    public array $permissionsList = [];

    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
        $this->id = $attributes['id'] ?? 1;
        $this->username = $attributes['username'] ?? 'testuser';
        $this->email = $attributes['email'] ?? 'test@example.com';
        $this->name = $attributes['name'] ?? 'Test User';
        $this->status = $attributes['status'] ?? 'active';
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
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
        return strtolower((string)($this->attributes['status'] ?? '')) === 'banned';
    }

    public function isSuspended(): bool
    {
        return strtolower((string)($this->attributes['status'] ?? '')) === 'suspended';
    }
}

class MockBinanceGateway implements PaymentGatewayInterface, StatusQueryableGatewayInterface
{
    private string $id = 'binance_pay';
    private string $apiKey = 'secret_api_key_12345';
    private string $apiSecret = 'ultra_secret_binance_key_99999';
    private string $certSn = 'cert_sn_abcdef123456';
    private PaymentStatus $mockStatus = PaymentStatus::PENDING;

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return 'Binance Pay';
    }

    public function getType(): PaymentMethodType
    {
        return PaymentMethodType::AUTOMATIC;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function getSupportedCurrencies(): array
    {
        return ['USDT', 'USD', 'BDT'];
    }

    public function getInstructions(array $context = []): array
    {
        return [];
    }

    public function setMockStatus(PaymentStatus $status): void
    {
        $this->mockStatus = $status;
    }

    public function createAttempt(PaymentIntent $intent, array $params = []): PaymentAttempt
    {
        $attemptId = 'att_binance_' . bin2hex(random_bytes(6));
        $chargeAmount = new Money(900, 'USDT'); // e.g. 9.00 USDT
        
        return new PaymentAttempt(
            $attemptId,
            $intent->getId(),
            $this->id,
            $chargeAmount,
            PaymentStatus::PENDING,
            'binance_prepay_' . bin2hex(random_bytes(4)),
            null,
            null,
            null,
            null,
            null,
            null,
            [
                'checkout_url'      => 'https://pay.binance.com/checkout/order_' . $intent->getId(),
                'qrcode_link'       => 'https://pay.binance.com/qr/order_' . $intent->getId() . '.png',
                'qr_content'        => 'binance://pay?order=' . $intent->getId(),
                'merchant_trade_no' => 'trade_' . $intent->getId(),
                'prepay_id'         => 'prepay_' . $intent->getId(),
                'rate_factor'       => 110000000,
                'rate_scale'        => 1000000,
                'base_currency'     => 'BDT',
                'charge_currency'   => 'USDT',
            ]
        );
    }

    public function verifyAttempt(PaymentAttempt $attempt, array $verificationData = []): PaymentAttempt
    {
        return $attempt;
    }

    public function queryStatus(PaymentAttempt $attempt): PaymentStatus
    {
        return $this->mockStatus;
    }

    public function getConfig(): array
    {
        return [
            'api_key'        => $this->apiKey,
            'api_secret'     => $this->apiSecret,
            'certificate_sn' => $this->certSn,
        ];
    }
}

class BinanceCheckoutAndAutomaticStatusTest extends TestCase
{
    private Application $app;
    private CurrencyService $currencyService;
    private GatewayRegistry $registry;
    private PaymentService $paymentService;
    private WalletService $walletService;
    private CustomerAccountController $controller;
    private MockBinanceGateway $binanceGateway;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_SESSION['_csrf_token'] = 'valid-test-csrf-token';
        unset($GLOBALS['_test_current_user']);

        $this->app = new Application(dirname(__DIR__, 3));
        $this->currencyService = new CurrencyService();
        $this->registry = new GatewayRegistry();

        $this->binanceGateway = new MockBinanceGateway();
        $this->registry->register($this->binanceGateway, ['binance'], true);

        $this->paymentService = new PaymentService($this->currencyService, $this->registry);
        $this->walletService = new WalletService($this->currencyService, $this->paymentService);

        $this->controller = new CustomerAccountController(
            $this->app,
            $this->walletService,
            $this->paymentService,
            $this->registry,
            $this->currencyService
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);
    }

    private function setLoggedInUser(int $id = 1, string $status = 'active', string $username = 'testuser'): BinanceTestUserStub
    {
        $user = new BinanceTestUserStub([
            'id'       => $id,
            'username' => $username,
            'email'    => "{$username}@example.com",
            'name'     => ucfirst($username),
            'status'   => $status,
        ]);
        $GLOBALS['_test_current_user'] = $user;
        $_SESSION['user_id'] = $id;
        return $user;
    }

    // =========================================================================
    // 1. RECHARGE REDIRECT TO BINANCE CHECKOUT
    // =========================================================================

    public function testRechargeWithBinanceRedirectsToDedicatedCheckoutScreen(): void
    {
        $this->setLoggedInUser(101, 'active', 'crypto_user');

        $postData = [
            'amount'      => '1000.00',
            'gateway'     => 'binance',
            '_csrf_token' => 'valid-test-csrf-token',
        ];

        $req = new Request([], $postData, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/recharge']);
        $resp = $this->controller->recharge($req);

        $this->assertSame(302, $resp->getStatusCode());
        $location = $resp->getHeaders()['Location'] ?? '';
        $this->assertStringStartsWith('/account/recharge/binance/pi_', $location);

        // Verify intent was created and is pending
        $intentId = basename($location);
        $intent = $this->paymentService->getIntent($intentId);
        $this->assertNotNull($intent);
        $this->assertSame(PaymentStatus::PENDING, $intent->getStatus());
        $this->assertSame(101, $intent->getUserId());

        // CRITICAL INVARIANT: Wallet is NOT credited!
        $balance = $this->walletService->getBalance(101);
        $this->assertSame(0, $balance->getAmount(), 'Wallet must have zero balance after intent creation');
    }

    // =========================================================================
    // 2. CHECKOUT PAGE RENDERING & REQUIRED ELEMENTS
    // =========================================================================

    public function testBinanceCheckoutPageRendersAllRequiredElements(): void
    {
        $this->setLoggedInUser(102, 'active', 'checkout_user');

        $intent = $this->paymentService->createIntent('favorite-pay', 'order_102', Money::bdt(100000), [
            'customer_id' => 102,
            'gateway_id'  => 'binance_pay',
        ]);

        $this->paymentService->initiatePayment($intent->getId(), 'binance_pay');

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge/binance/' . $intent->getId()]);
        $resp = $this->controller->binanceCheckout($req, $intent->getId());

        $this->assertSame(200, $resp->getStatusCode());
        $html = $resp->getContent();

        // 1. Payment ID
        $this->assertStringContainsString($intent->getId(), $html);

        // 2. Primary Accounting Amount (BDT)
        $this->assertStringContainsString('1000.00', $html);
        $this->assertStringContainsString('BDT', $html);

        // 3. Binance Payable Amount (USDT)
        $this->assertStringContainsString('9.00', $html);
        $this->assertStringContainsString('USDT', $html);

        // 4. QR Code element / link
        $this->assertStringContainsString('https://pay.binance.com/qr/order_' . $intent->getId() . '.png', $html);

        // 5. Open Binance button / validated URL
        $this->assertStringContainsString('https://pay.binance.com/checkout/order_' . $intent->getId(), $html);
        $this->assertStringContainsString('Open Binance', $html);

        // 6. Waiting for payment status indicator
        $this->assertStringContainsString('Waiting for payment', $html);

        // 7. Polling script pointing to canonical status endpoint
        $this->assertStringContainsString('/account/payments/\' + encodeURIComponent(paymentId) + \'/status', $html);
    }

    // =========================================================================
    // 3. AUTHENTICATION & ACCESS CONTROL
    // =========================================================================

    public function testBinanceCheckoutEnforcesAuthentication(): void
    {
        // Unauthenticated request
        unset($GLOBALS['_test_current_user']);
        $_SESSION = [];

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge/binance/pi_sample123']);
        $resp = $this->controller->binanceCheckout($req, 'pi_sample123');

        $this->assertSame(302, $resp->getStatusCode());
        $this->assertStringContainsString('/login', $resp->getHeaders()['Location'] ?? '');
    }

    public function testBinanceCheckoutStrictIdorProtection(): void
    {
        // Payment belongs to User 103
        $this->setLoggedInUser(103, 'active', 'user_a');
        $intent = $this->paymentService->createIntent('favorite-pay', 'order_103', Money::bdt(50000), [
            'customer_id' => 103,
            'gateway_id'  => 'binance_pay',
        ]);

        // User 104 tries to view User 103's checkout
        $this->setLoggedInUser(104, 'active', 'attacker_b');
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge/binance/' . $intent->getId()]);
        $resp = $this->controller->binanceCheckout($req, $intent->getId());

        $this->assertSame(403, $resp->getStatusCode());
        $this->assertStringContainsString('Access denied', $resp->getContent());
    }

    public function testBinanceCheckoutReturns404ForNonexistentIntent(): void
    {
        $this->setLoggedInUser(105, 'active', 'user_105');

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge/binance/pi_nonexistent']);
        $resp = $this->controller->binanceCheckout($req, 'pi_nonexistent');

        $this->assertSame(404, $resp->getStatusCode());
    }

    public function testBinanceCheckoutBlocksSuspendedUserFromCreatingNewAttempts(): void
    {
        // Suspended user
        $this->setLoggedInUser(106, 'suspended', 'suspended_user');

        // Create intent directly in paymentService (as if prior to suspension)
        $intent = $this->paymentService->createIntent('favorite-pay', 'order_106', Money::bdt(20000), [
            'customer_id' => 106,
            'gateway_id'  => 'binance_pay',
        ]);

        // Attempting to initiate new gateway checkout while suspended must be blocked with 403
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge/binance/' . $intent->getId()]);
        $resp = $this->controller->binanceCheckout($req, $intent->getId());

        $this->assertSame(403, $resp->getStatusCode());
        $this->assertStringContainsString('suspended', strtolower($resp->getContent()));
    }

    public function testBinanceCheckoutBlocksBannedUser(): void
    {
        $this->setLoggedInUser(107, 'banned', 'banned_user');

        $intent = $this->paymentService->createIntent('favorite-pay', 'order_107', Money::bdt(20000), [
            'customer_id' => 107,
            'gateway_id'  => 'binance_pay',
        ]);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge/binance/' . $intent->getId()]);
        $resp = $this->controller->binanceCheckout($req, $intent->getId());

        $this->assertSame(403, $resp->getStatusCode());
        $this->assertStringContainsString('banned', strtolower($resp->getContent()));
    }

    // =========================================================================
    // 4. CHECKOUT URL VALIDATION & ZERO PHISHING
    // =========================================================================

    public function testBinanceCheckoutValidatesCheckoutUrl(): void
    {
        $this->setLoggedInUser(108, 'active', 'user_108');

        // Intent with an unsafe external/phishing checkout URL injected in metadata
        $intent = $this->paymentService->createIntent('favorite-pay', 'order_108', Money::bdt(50000), [
            'customer_id' => 108,
            'gateway_id'  => 'binance_pay',
        ]);

        $fakeAttempt = new PaymentAttempt(
            'att_fake_108',
            $intent->getId(),
            'binance_pay',
            new Money(500, 'USDT'),
            PaymentStatus::PENDING,
            'ref_fake',
            null, null, null, null, null, null,
            [
                'checkout_url' => 'https://malicious-phishing-site.com/steal-creds',
                'qrcode_link'  => 'https://pay.binance.com/qr/order_108.png',
            ]
        );
        $this->paymentService->recordAttempt($fakeAttempt);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge/binance/' . $intent->getId()]);
        $resp = $this->controller->binanceCheckout($req, $intent->getId());

        $this->assertSame(200, $resp->getStatusCode());
        $html = $resp->getContent();

        // The malicious URL must NOT be rendered in an active checkout button
        $this->assertStringNotContainsString('malicious-phishing-site.com', $html);
    }

    // =========================================================================
    // 5. CUSTOMER PAYMENT STATUS POLLING ENDPOINT
    // =========================================================================

    public function testPaymentStatusEndpointRequiresAuthentication(): void
    {
        unset($GLOBALS['_test_current_user']);
        $_SESSION = [];

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments/pi_test/status']);
        $resp = $this->controller->paymentStatus($req, 'pi_test');

        $this->assertSame(401, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true);
        $this->assertSame('Authentication required.', $data['error'] ?? '');
    }

    public function testPaymentStatusEndpointEnforcesIdorProtection(): void
    {
        $this->setLoggedInUser(110, 'active', 'user_110');
        $intent = $this->paymentService->createIntent('favorite-pay', 'order_110', Money::bdt(30000), [
            'customer_id' => 110,
            'gateway_id'  => 'binance_pay',
        ]);

        // User 111 queries User 110's payment status
        $this->setLoggedInUser(111, 'active', 'user_111');
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments/' . $intent->getId() . '/status']);
        $resp = $this->controller->paymentStatus($req, $intent->getId());

        $this->assertSame(403, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true);
        $this->assertSame('Access denied.', $data['error'] ?? '');
    }

    public function testPaymentStatusEndpointReturns404ForNonexistentIntent(): void
    {
        $this->setLoggedInUser(112, 'active', 'user_112');

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments/pi_missing/status']);
        $resp = $this->controller->paymentStatus($req, 'pi_missing');

        $this->assertSame(404, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true);
        $this->assertSame('Payment intent not found.', $data['error'] ?? '');
    }

    public function testPaymentStatusEndpointReturnsPendingState(): void
    {
        $this->setLoggedInUser(113, 'active', 'user_113');
        $intent = $this->paymentService->createIntent('favorite-pay', 'order_113', Money::bdt(50000), [
            'customer_id' => 113,
            'gateway_id'  => 'binance_pay',
        ]);

        $this->paymentService->initiatePayment($intent->getId(), 'binance_pay');

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments/' . $intent->getId() . '/status']);
        $resp = $this->controller->paymentStatus($req, $intent->getId());

        $this->assertSame(200, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertSame($intent->getId(), $data['payment_id']);
        $this->assertSame('pending', $data['status']);
        $this->assertSame('Waiting for payment...', $data['status_label']);
        $this->assertFalse($data['is_final']);
        $this->assertFalse($data['is_success']);
        $this->assertFalse($data['wallet_settled']);
        $this->assertSame('500.00', $data['amount']);
        $this->assertSame('BDT', $data['currency']);
        $this->assertSame('9.00', $data['binance_amount']);
        $this->assertSame('USDT', $data['binance_currency']);
    }

    // =========================================================================
    // 6. ZERO PRE-CREDIT SAFETY INVARIANT & STATUS POLLING
    // =========================================================================

    public function testZeroPreCreditSafetyInvariantDuringPolling(): void
    {
        $this->setLoggedInUser(114, 'active', 'safety_user');

        $initialBalance = $this->walletService->getBalance(114);
        $this->assertSame(0, $initialBalance->getAmount());

        $intent = $this->paymentService->createIntent('favorite-pay', 'order_114', Money::bdt(75000), [
            'customer_id' => 114,
            'gateway_id'  => 'binance_pay',
        ]);

        $this->paymentService->initiatePayment($intent->getId(), 'binance_pay');

        // Poll 10 consecutive times while payment is pending
        for ($i = 0; $i < 10; $i++) {
            $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments/' . $intent->getId() . '/status']);
            $resp = $this->controller->paymentStatus($req, $intent->getId());
            $this->assertSame(200, $resp->getStatusCode());

            // ABSOLUTE SAFETY INVARIANT: Wallet balance must REMAIN 0!
            $currentBalance = $this->walletService->getBalance(114);
            $this->assertSame(0, $currentBalance->getAmount(), "Wallet was credited prematurely during poll #{$i}!");
        }
    }

    // =========================================================================
    // 7. AUTHORITATIVE SUCCESS & SETTLEMENT CONFIRMATION
    // =========================================================================

    public function testPaymentStatusReflectsAuthoritativeSuccessAndSettlement(): void
    {
        $this->setLoggedInUser(115, 'active', 'settle_user');

        $intent = $this->paymentService->createIntent('favorite-pay', 'order_115', Money::bdt(150000), [
            'customer_id' => 115,
            'gateway_id'  => 'binance_pay',
        ]);

        $this->paymentService->initiatePayment($intent->getId(), 'binance_pay');

        // Before payment: wallet is 0
        $this->assertSame(0, $this->walletService->getBalance(115)->getAmount());

        // Configure mock gateway to return SUCCEEDED when polled
        $this->binanceGateway->setMockStatus(PaymentStatus::SUCCEEDED);

        // Status poll occurs: gateway confirms SUCCEEDED, intent updates and wallet settles
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments/' . $intent->getId() . '/status']);
        $resp = $this->controller->paymentStatus($req, $intent->getId());

        $this->assertSame(200, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertSame('succeeded', $data['status']);
        $this->assertTrue($data['is_final']);
        $this->assertTrue($data['is_success']);
        $this->assertSame('Payment Successful', $data['status_label']);

        // Settle wallet via hook/service
        $this->walletService->settleSuccessfulPayment($intent->getId());

        // Authoritative wallet settlement verified: 1,500.00 BDT (150,000 Poisha) credited
        $balance = $this->walletService->getBalance(115);
        $this->assertSame(150000, $balance->getAmount());
    }

    // =========================================================================
    // 8. IDEMPOTENCY: MULTIPLE STATUS CHECKS NEVER DUPLICATE CREDITS
    // =========================================================================

    public function testPaymentStatusPollingIdempotency(): void
    {
        $this->setLoggedInUser(116, 'active', 'idempotent_user');

        $intent = $this->paymentService->createIntent('favorite-pay', 'order_116', Money::bdt(80000), [
            'customer_id' => 116,
            'gateway_id'  => 'binance_pay',
        ]);

        $this->paymentService->initiatePayment($intent->getId(), 'binance_pay');

        // Settle payment once
        $this->binanceGateway->setMockStatus(PaymentStatus::SUCCEEDED);
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);
        $this->walletService->settleSuccessfulPayment($intent->getId());

        // Poll 5 consecutive times after success
        for ($i = 0; $i < 5; $i++) {
            $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments/' . $intent->getId() . '/status']);
            $resp = $this->controller->paymentStatus($req, $intent->getId());
            $this->assertSame(200, $resp->getStatusCode());

            $data = json_decode($resp->getContent(), true);
            $this->assertSame('succeeded', $data['status']);
            $this->assertTrue($data['is_success']);

            // Verify wallet balance is strictly 80,000 Poisha (never multiplied)
            $balance = $this->walletService->getBalance(116);
            $this->assertSame(80000, $balance->getAmount(), "Duplicate credit detected on poll #{$i}!");
        }
    }

    // =========================================================================
    // 9. ZERO CREDENTIAL LEAKAGE
    // =========================================================================

    public function testZeroCredentialLeakageInCheckoutAndStatus(): void
    {
        $this->setLoggedInUser(117, 'active', 'security_audit');

        $intent = $this->paymentService->createIntent('favorite-pay', 'order_117', Money::bdt(45000), [
            'customer_id' => 117,
            'gateway_id'  => 'binance_pay',
        ]);

        $this->paymentService->initiatePayment($intent->getId(), 'binance_pay');

        // 1. Check Checkout HTML view
        $viewReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge/binance/' . $intent->getId()]);
        $viewResp = $this->controller->binanceCheckout($viewReq, $intent->getId());
        $html = $viewResp->getContent();

        $this->assertStringNotContainsString('ultra_secret_binance_key_99999', $html);
        $this->assertStringNotContainsString('secret_api_key_12345', $html);
        $this->assertStringNotContainsString('cert_sn_abcdef123456', $html);

        // 2. Check Status JSON endpoint
        $statusReq = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments/' . $intent->getId() . '/status']);
        $statusResp = $this->controller->paymentStatus($statusReq, $intent->getId());
        $json = $statusResp->getContent();

        $this->assertStringNotContainsString('ultra_secret_binance_key_99999', $json);
        $this->assertStringNotContainsString('secret_api_key_12345', $json);
        $this->assertStringNotContainsString('cert_sn_abcdef123456', $json);
    }

    // =========================================================================
    // 10. BANNED USER BLOCKED FROM STATUS ENDPOINT
    // =========================================================================

    public function testBannedUserBlockedFromStatusEndpoint(): void
    {
        $this->setLoggedInUser(118, 'banned', 'banned_user_118');

        $intent = $this->paymentService->createIntent('favorite-pay', 'order_118', Money::bdt(10000), [
            'customer_id' => 118,
            'gateway_id'  => 'binance_pay',
        ]);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments/' . $intent->getId() . '/status']);
        $resp = $this->controller->paymentStatus($req, $intent->getId());

        $this->assertSame(403, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true);
        $this->assertSame('Account access restricted.', $data['error'] ?? '');
    }
}
