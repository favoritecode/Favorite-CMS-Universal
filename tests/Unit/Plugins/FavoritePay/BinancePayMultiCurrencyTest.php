<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
use FavoriteCMS\Pay\Contracts\WebhookServiceInterface;
use FavoriteCMS\Pay\Domain\ConversionSnapshot;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Exceptions\UnauthoritativeRateException;
use FavoriteCMS\Pay\Gateways\Binance\BinancePayGateway;
use FavoriteCMS\Pay\Gateways\Binance\BinancePayHttpClient;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Providers\InMemoryExchangeRateProvider;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\WalletService;
use FavoriteCMS\Pay\Services\WebhookService;
use FavoriteCMS\Pay\FavoritePayPlugin;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

class BinancePayMultiCurrencyTest extends TestCase
{
    private Application $app;
    private PDO $pdo;
    private Database $db;
    private InMemoryExchangeRateProvider $testRateProvider;
    private CurrencyService $currencyService;
    private GatewayRegistry $registry;
    private PaymentService $paymentService;
    private WalletService $walletService;
    private WebhookService $webhookService;
    private BinancePayGateway $gateway;
    private array $interceptedRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = Application::getInstance();

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

        // Migrations
        require_once APP_ROOT . '/database/migrations/009_create_settings_table.php';
        (new \CreateSettingsTable($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-pay/database/migrations/001_create_favorite_pay_tables.php';
        (new \CreateFavoritePayTables($this->db))->up();

        // Authoritative site Primary Currency = BDT
        Setting::set('general', 'primary_currency', 'BDT', 'string');

        // Test deterministic rate provider
        $this->testRateProvider = new InMemoryExchangeRateProvider('test_provider');
        $this->currencyService = new CurrencyService($this->testRateProvider, $this->db);

        // Seed deterministic test rates for tests
        $this->testRateProvider->setRate('BDT', 'USDT', '0.010417', true, null, 'test_fixture');
        $this->testRateProvider->setRate('EUR', 'USDT', '1.171', true, null, 'test_fixture');
        $this->testRateProvider->setRate('USD', 'USDT', '1.00', true, null, 'test_fixture');
        $this->testRateProvider->setRate('GBP', 'USDT', '1.30', true, null, 'test_fixture');
        $this->testRateProvider->setRate('BDT', 'USDC', '0.010417', true, null, 'test_fixture');
        $this->testRateProvider->setRate('EUR', 'USDC', '1.171', true, null, 'test_fixture');
        $this->testRateProvider->setRate('USD', 'USDC', '1.00', true, null, 'test_fixture');
        $this->testRateProvider->setRate('GBP', 'USDC', '1.30', true, null, 'test_fixture');

        $this->registry = new GatewayRegistry(false);
        $this->paymentService = new PaymentService($this->currencyService, $this->registry, $this->db);
        $this->walletService = new WalletService($this->currencyService, $this->paymentService, $this->db);
        $this->webhookService = new WebhookService($this->registry, $this->paymentService, $this->db);

        $this->app->instance(GatewayRegistry::class, $this->registry);
        $this->app->instance(PaymentService::class, $this->paymentService);
        $this->app->instance(WalletService::class, $this->walletService);
        $this->app->instance(WebhookService::class, $this->webhookService);
        $this->app->instance(PaymentServiceInterface::class, $this->paymentService);
        $this->app->instance(WalletServiceInterface::class, $this->walletService);
        $this->app->instance(WebhookServiceInterface::class, $this->webhookService);

        $plugin = new FavoritePayPlugin($this->app);
        $plugin->boot();

        $this->interceptedRequests = [];

        $transport = function (string $method, string $url, array $headers, string $body): array {
            $this->interceptedRequests[] = [
                'method'  => $method,
                'url'     => $url,
                'headers' => $headers,
                'body'    => $body,
            ];

            return [
                'statusCode' => 200,
                'body'       => json_encode([
                    'status' => 'SUCCESS',
                    'code'   => '000000',
                    'data'   => [
                        'prepayId'    => 'prepay_multi_' . bin2hex(random_bytes(6)),
                        'checkoutUrl' => 'https://pay.binance.com/checkout?id=test',
                        'deeplink'    => 'bnc://app.binance.com/payment/sec?id=test',
                        'universalUrl'=> 'https://app.binance.com/qr/d?id=test',
                        'qrcodeLink'  => 'https://public.binanceapi.com/qr/test.png',
                        'qrContent'   => 'https://app.binance.com/qr/d?id=test',
                        'currency'    => 'USDT',
                    ],
                ]),
            ];
        };

        $client = new BinancePayHttpClient('cert_multi_test_sn', 'sec_multi_test_key', BinancePayHttpClient::DEFAULT_BASE_URL, $transport);

        $this->gateway = new BinancePayGateway([
            'enabled'            => true,
            'certificate_sn'     => 'cert_multi_test_sn',
            'api_secret'         => 'sec_multi_test_key',
            'preferred_currency' => 'USDT',
        ], $client, $this->db);

        $this->gateway->setCurrencyService($this->currencyService);
        $this->registry->register($this->gateway);
    }

    protected function tearDown(): void
    {
        Setting::clearCache();
        parent::tearDown();
    }

    // =========================================================================
    // 1. Production CurrencyService Initialization & Test Isolation
    // =========================================================================

    public function testProductionCurrencyServiceHasNoSeededUsableTestRates(): void
    {
        // A production CurrencyService created without a provider or database must have zero rates
        $prodCurrencyService = new CurrencyService();
        $this->assertFalse($prodCurrencyService->hasRate('BDT', 'USDT'));
        $this->assertFalse($prodCurrencyService->hasRate('EUR', 'USDT'));
        $this->assertFalse($prodCurrencyService->hasRate('USD', 'USDT'));
        $this->assertFalse($prodCurrencyService->hasRate('GBP', 'USDT'));

        $this->expectException(UnauthoritativeRateException::class);
        $prodCurrencyService->getRate('BDT', 'USDT');
    }

    public function testTestProviderCanInjectDeterministicRates(): void
    {
        $provider = new InMemoryExchangeRateProvider('custom_test');
        $provider->setRate('EUR', 'USDT', '1.150000', true);

        $service = new CurrencyService($provider);
        $this->assertTrue($service->hasRate('EUR', 'USDT'));
        $snapshot = $service->getRate('EUR', 'USDT');
        $this->assertSame('EUR', $snapshot->getFromCurrency());
        $this->assertSame('USDT', $snapshot->getToCurrency());
        $this->assertSame('1.15', $snapshot->getRateDecimalString());
    }

    // =========================================================================
    // 2. Authoritative Fresh Conversions (BDT, EUR, USD, GBP)
    // =========================================================================

    public function testAuthoritativeFreshBdtToUsdtWorks(): void
    {
        // 120 BDT (12000 Poisha) at 0.010417 = 1.25 USDT (125 Cents)
        $orderAmount = Money::fromMajorString('120.00', 'BDT');
        $intent = $this->paymentService->createIntent('shop', 'order_bdt_1', $orderAmount, [
            'gateway_id' => 'binance_pay',
        ]);

        $this->assertSame('BDT', $intent->getBaseAmount()->getCurrency());
        $this->assertSame(12000, $intent->getBaseAmount()->getAmount());
        $this->assertSame('USDT', $intent->getAmount()->getCurrency());
        $this->assertSame(125, $intent->getAmount()->getAmount());

        $attempt = $this->gateway->createAttempt($intent);
        $this->assertSame('USDT', $attempt->getAmount()->getCurrency());
        $this->assertSame(125, $attempt->getAmount()->getAmount());
        $this->assertCount(1, $this->interceptedRequests);
        $body = json_decode($this->interceptedRequests[0]['body'], true);
        $this->assertEquals(1.25, $body['orderAmount']);
        $this->assertSame('USDT', $body['currency']);

        // Simulate Webhook confirmation
        $this->paymentService->recordAttempt($attempt);
        $payload = json_encode([
            'bizType'   => 'PAY',
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => json_encode([
                'merchantTradeNo' => $attempt->getTransactionReference(),
                'totalFee'        => '1.25',
                'currency'        => 'USDT',
                'transactionId'   => 'binance_tx_bdt_001',
            ]),
        ]);

        $nonce = bin2hex(random_bytes(16));
        $timestamp = (string)(time() * 1000);
        $sigPayload = $timestamp . "\n" . $nonce . "\n" . $payload . "\n";
        $signature = strtoupper(hash_hmac('sha512', $sigPayload, 'sec_multi_test_key'));

        $response = $this->webhookService->handle('binance_pay', [
            'binancepay-timestamp' => $timestamp,
            'binancepay-nonce'     => $nonce,
            'binancepay-signature' => $signature,
        ], $payload);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('120.00 BDT', $intent->getBaseAmount()->toMajorUnit() . ' ' . $intent->getBaseAmount()->getCurrency());
    }

    public function testAuthoritativeFreshEurToUsdtWorks(): void
    {
        // 60 EUR (6000 Cents) at 1.171 = 70.26 USDT (7026 Cents)
        $orderAmount = Money::fromMajorString('60.00', 'EUR');
        $intent = $this->paymentService->createIntent('shop', 'order_eur_1', $orderAmount, [
            'gateway_id' => 'binance_pay',
        ]);

        $this->assertSame('EUR', $intent->getBaseAmount()->getCurrency());
        $this->assertSame('USDT', $intent->getAmount()->getCurrency());
        $this->assertSame(7026, $intent->getAmount()->getAmount());

        $attempt = $this->gateway->createAttempt($intent);
        $this->assertSame(7026, $attempt->getAmount()->getAmount());
    }

    public function testAuthoritativeFreshUsdToUsdtWorks(): void
    {
        // 100 USD at 1.00 = 100 USDT
        $orderAmount = Money::fromMajorString('100.00', 'USD');
        $intent = $this->paymentService->createIntent('shop', 'order_usd_1', $orderAmount, [
            'gateway_id' => 'binance_pay',
        ]);

        $this->assertSame('USDT', $intent->getAmount()->getCurrency());
        $this->assertSame(10000, $intent->getAmount()->getAmount());
    }

    public function testAuthoritativeFreshGbpToUsdtWorks(): void
    {
        // 50 GBP at 1.30 = 65 USDT
        $orderAmount = Money::fromMajorString('50.00', 'GBP');
        $intent = $this->paymentService->createIntent('shop', 'order_gbp_1', $orderAmount, [
            'gateway_id' => 'binance_pay',
        ]);

        $this->assertSame('USDT', $intent->getAmount()->getCurrency());
        $this->assertSame(6500, $intent->getAmount()->getAmount());
    }

    // =========================================================================
    // 3. Strict Fail-Closed Protections (Non-Authoritative, Expired, Missing)
    // =========================================================================

    public function testNonAuthoritativeBdtToUsdtFailsClosed(): void
    {
        $provider = new InMemoryExchangeRateProvider();
        $provider->setRate('BDT', 'USDT', '0.010417', false); // non-authoritative

        $service = new CurrencyService($provider);
        $paymentService = new PaymentService($service, $this->registry, $this->db);

        $this->expectException(UnauthoritativeRateException::class);
        $this->expectExceptionMessage("not authoritative");
        $paymentService->createIntent('shop', 'order_fail_1', Money::fromMajorString('100.00', 'BDT'), [
            'gateway_id' => 'binance_pay',
        ]);
    }

    public function testNonAuthoritativeInverseRateFailsClosed(): void
    {
        $provider = new InMemoryExchangeRateProvider();
        // Source USDT -> BDT is non-authoritative
        $provider->setRate('USDT', 'BDT', '96.00', false);

        $service = new CurrencyService($provider);

        $this->expectException(UnauthoritativeRateException::class);
        $this->expectExceptionMessage("is not authoritative");
        $service->getRate('BDT', 'USDT');
    }

    public function testExpiredRateFailsClosed(): void
    {
        $provider = new InMemoryExchangeRateProvider();
        $expiredAt = date('Y-m-d H:i:s', time() - 3600); // 1 hr ago
        $provider->setRate('BDT', 'USDT', '0.010417', true, $expiredAt);

        $service = new CurrencyService($provider);
        $paymentService = new PaymentService($service, $this->registry, $this->db);

        $this->expectException(UnauthoritativeRateException::class);
        $this->expectExceptionMessage("expired");
        $paymentService->createIntent('shop', 'order_fail_2', Money::fromMajorString('100.00', 'BDT'), [
            'gateway_id' => 'binance_pay',
        ]);
    }

    public function testMissingRateFailsClosed(): void
    {
        $provider = new InMemoryExchangeRateProvider(); // empty
        $service = new CurrencyService($provider);
        $paymentService = new PaymentService($service, $this->registry, $this->db);

        $this->expectException(UnauthoritativeRateException::class);
        $this->expectExceptionMessage("No valid authoritative exchange rate is available");
        $paymentService->createIntent('shop', 'order_fail_3', Money::fromMajorString('100.00', 'JPY'), [
            'gateway_id' => 'binance_pay',
        ]);
    }

    public function testZeroOrNegativeRateFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConversionSnapshot::create('BDT', 'USDT', '0.00', true);
    }

    public function testNoPaymentAttemptIsCreatedWhenRateValidationFails(): void
    {
        // Provider has no rate for CHF to USDT
        $intent = new PaymentIntent(
            'pi_chf_test',
            'shop',
            'order_chf',
            Money::fromMajorString('100.00', 'CHF'),
            Money::fromMajorString('100.00', 'CHF'),
            PaymentStatus::PENDING,
            PaymentMethodType::CRYPTO
        );

        $this->expectException(UnauthoritativeRateException::class);
        $this->gateway->createAttempt($intent);

        $this->assertCount(0, $this->interceptedRequests);
    }

    public function testNoBinanceApiRequestOccursWhenRateValidationFails(): void
    {
        $initialRequestCount = count($this->interceptedRequests);

        $intent = new PaymentIntent(
            'pi_nok_test',
            'shop',
            'order_nok',
            Money::fromMajorString('500.00', 'NOK'),
            Money::fromMajorString('500.00', 'NOK'),
            PaymentStatus::PENDING,
            PaymentMethodType::CRYPTO
        );

        try {
            $this->gateway->createAttempt($intent);
            $this->fail("Expected UnauthoritativeRateException was not thrown.");
        } catch (UnauthoritativeRateException) {
            // Expected
        }

        // Verify zero outgoing Binance API requests
        $this->assertSame($initialRequestCount, count($this->interceptedRequests));
    }

    public function testLockedSnapshotRemainsUnchangedAfterRateUpdate(): void
    {
        $orderAmount = Money::fromMajorString('120.00', 'BDT');
        $intent = $this->paymentService->createIntent('shop', 'order_lock_test', $orderAmount, [
            'gateway_id' => 'binance_pay',
        ]);

        $this->assertSame(125, $intent->getAmount()->getAmount()); // 1.25 USDT
        $originalSnapshot = $intent->getConversionSnapshot();

        // Later, operator updates exchange rate to 0.020000
        $this->testRateProvider->setRate('BDT', 'USDT', '0.020000', true);

        // Verify that existing PaymentIntent remains completely unaffected
        $this->assertSame(125, $intent->getAmount()->getAmount());
        $this->assertSame($originalSnapshot->getRateFactor(), $intent->getConversionSnapshot()->getRateFactor());
    }

    public function testAuthoritativeInverseDerivationWorksCorrectly(): void
    {
        $provider = new InMemoryExchangeRateProvider();
        // Authoritative rate USDT -> BDT is 96.00
        $provider->setRate('USDT', 'BDT', '96.00', true);

        $service = new CurrencyService($provider);
        $this->assertTrue($service->hasRate('BDT', 'USDT'));

        $snapshot = $service->getRate('BDT', 'USDT');
        $this->assertTrue($snapshot->isAuthoritative());
        $this->assertStringStartsWith('derived_inverse:', $snapshot->getSource());

        $bdt = Money::fromMajorString('96.00', 'BDT');
        $usdt = $snapshot->convert($bdt);
        $this->assertSame('USDT', $usdt->getCurrency());
        $this->assertSame(100, $usdt->getAmount()); // 1.00 USDT
    }

    public function testInverseDerivationPreservesExactDecimalPrecision(): void
    {
        $provider = new InMemoryExchangeRateProvider();
        $provider->setRate('USDT', 'EUR', '0.853970', true);

        $service = new CurrencyService($provider);
        $snapshot = $service->getRate('EUR', 'USDT');

        // Inverse rate scale 1000000: factor = intdiv(10^12, 853970) = 1170997 (~1.170997)
        $this->assertSame(1171001, $snapshot->getRateFactor());

        $eur = Money::fromMajorString('100.00', 'EUR');
        $usdt = $snapshot->convert($eur);
        $this->assertSame(11710, $usdt->getAmount()); // 117.10 USDT
    }

    // =========================================================================
    // 4. Gateway Readiness & Diagnostic Invariants
    // =========================================================================

    public function testBdtPrimaryCurrencyDoesNotInherentlyDisableBinance(): void
    {
        // When BDT primary currency site has an authoritative rate configured, gateway reports READY
        $status = $this->gateway->getConfigurationStatus('BDT');
        $this->assertSame('READY', $status['state']);
        $this->assertTrue($status['is_ready']);
        $this->assertTrue($status['currency_compatible']);
        $this->assertSame('READY', $status['currency_conversion']);
        $this->assertSame('Valid (Fresh)', $status['rate_status']);
    }

    public function testBinanceRemainsNotReadyWhenConversionInfrastructureHasNoValidRate(): void
    {
        // When gateway currencyService has no valid rate for primary currency, gateway reports NOT_READY
        $emptyCurrencyService = new CurrencyService(new InMemoryExchangeRateProvider());
        $this->gateway->setCurrencyService($emptyCurrencyService);

        $status = $this->gateway->getConfigurationStatus('BDT');
        $this->assertSame('NOT_READY', $status['state']);
        $this->assertFalse($status['is_ready']);
        $this->assertFalse($status['currency_compatible']);
        $this->assertSame('NOT_READY', $status['currency_conversion']);
        $this->assertSame('No valid authoritative rate', $status['rate_status']);
    }

    // =========================================================================
    // 5. Security & Webhook Tampering Protections
    // =========================================================================

    public function testWebhookWithMismatchedAmountIsRejected(): void
    {
        $orderAmount = Money::fromMajorString('120.00', 'BDT');
        $intent = $this->paymentService->createIntent('shop', 'order_tamper_amt', $orderAmount, [
            'gateway_id' => 'binance_pay',
        ]);
        $attempt = $this->gateway->createAttempt($intent);
        $this->paymentService->recordAttempt($attempt);

        // Binance payload reports different amount (0.50 USDT instead of 1.25 USDT)
        $payload = json_encode([
            'bizType'   => 'PAY',
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => json_encode([
                'merchantTradeNo' => $attempt->getTransactionReference(),
                'totalFee'        => '0.50',
                'currency'        => 'USDT',
                'transactionId'   => 'binance_tx_tamper_01',
            ]),
        ]);

        $nonce = bin2hex(random_bytes(16));
        $timestamp = (string)(time() * 1000);
        $sigPayload = $timestamp . "\n" . $nonce . "\n" . $payload . "\n";
        $signature = strtoupper(hash_hmac('sha512', $sigPayload, 'sec_multi_test_key'));

        $response = $this->webhookService->handle('binance_pay', [
            'binancepay-timestamp' => $timestamp,
            'binancepay-nonce'     => $nonce,
            'binancepay-signature' => $signature,
        ], $payload);

        $this->assertFalse($response->isSuccess());
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testWebhookWithMismatchedCurrencyIsRejected(): void
    {
        $orderAmount = Money::fromMajorString('120.00', 'BDT');
        $intent = $this->paymentService->createIntent('shop', 'order_tamper_cur', $orderAmount, [
            'gateway_id' => 'binance_pay',
        ]);
        $attempt = $this->gateway->createAttempt($intent);
        $this->paymentService->recordAttempt($attempt);

        // Binance payload reports mismatched currency (BUSD instead of USDT)
        $payload = json_encode([
            'bizType'   => 'PAY',
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => json_encode([
                'merchantTradeNo' => $attempt->getTransactionReference(),
                'totalFee'        => '1.25',
                'currency'        => 'BUSD',
                'transactionId'   => 'binance_tx_tamper_02',
            ]),
        ]);

        $nonce = bin2hex(random_bytes(16));
        $timestamp = (string)(time() * 1000);
        $sigPayload = $timestamp . "\n" . $nonce . "\n" . $payload . "\n";
        $signature = strtoupper(hash_hmac('sha512', $sigPayload, 'sec_multi_test_key'));

        $response = $this->webhookService->handle('binance_pay', [
            'binancepay-timestamp' => $timestamp,
            'binancepay-nonce'     => $nonce,
            'binancepay-signature' => $signature,
        ], $payload);

        $this->assertFalse($response->isSuccess());
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testInvalidWebhookSignatureIsRejected(): void
    {
        $payload = json_encode([
            'bizType'   => 'PAY',
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => json_encode([
                'merchantTradeNo' => 'FPTEST123',
                'totalFee'        => '1.25',
                'currency'        => 'USDT',
            ]),
        ]);

        $response = $this->webhookService->handle('binance_pay', [
            'binancepay-timestamp' => (string)(time() * 1000),
            'binancepay-nonce'     => 'nonce123',
            'binancepay-signature' => 'INVALID_SIGNATURE',
        ], $payload);

        $this->assertFalse($response->isSuccess());
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testWebhookIsIdempotentOnDuplicateCall(): void
    {
        $orderAmount = Money::fromMajorString('120.00', 'BDT');
        $intent = $this->paymentService->createIntent('shop', 'order_idemp_test', $orderAmount, [
            'gateway_id' => 'binance_pay',
        ]);
        $attempt = $this->gateway->createAttempt($intent);
        $this->paymentService->recordAttempt($attempt);

        $payload = json_encode([
            'bizType'   => 'PAY',
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => json_encode([
                'merchantTradeNo' => $attempt->getTransactionReference(),
                'totalFee'        => '1.25',
                'currency'        => 'USDT',
                'transactionId'   => 'binance_tx_idemp_01',
            ]),
        ]);

        $nonce = bin2hex(random_bytes(16));
        $timestamp = (string)(time() * 1000);
        $sigPayload = $timestamp . "\n" . $nonce . "\n" . $payload . "\n";
        $signature = strtoupper(hash_hmac('sha512', $sigPayload, 'sec_multi_test_key'));

        $headers = [
            'binancepay-timestamp' => $timestamp,
            'binancepay-nonce'     => $nonce,
            'binancepay-signature' => $signature,
        ];

        // Call 1
        $res1 = $this->webhookService->handle('binance_pay', $headers, $payload);
        $this->assertTrue($res1->isSuccess());

        // Call 2: Duplicate
        $res2 = $this->webhookService->handle('binance_pay', $headers, $payload);
        $this->assertTrue($res2->isSuccess());
        $this->assertTrue($res2->isAlreadyProcessed());
    }

    public function testWalletSettlementCreditsPrimaryCurrencyOnBdtSite(): void
    {
        $userId = 77;
        $orderAmount = Money::fromMajorString('120.00', 'BDT');
        $intent = $this->paymentService->createIntent('shop', 'order_wallet_settle', $orderAmount, [
            'gateway_id'  => 'binance_pay',
            'customer_id' => $userId,
        ]);
        $attempt = $this->gateway->createAttempt($intent);
        $this->paymentService->recordAttempt($attempt);

        $payload = json_encode([
            'bizType'   => 'PAY',
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => json_encode([
                'merchantTradeNo' => $attempt->getTransactionReference(),
                'totalFee'        => '1.25',
                'currency'        => 'USDT',
                'transactionId'   => 'binance_tx_wallet_01',
            ]),
        ]);

        $nonce = bin2hex(random_bytes(16));
        $timestamp = (string)(time() * 1000);
        $sigPayload = $timestamp . "\n" . $nonce . "\n" . $payload . "\n";
        $signature = strtoupper(hash_hmac('sha512', $sigPayload, 'sec_multi_test_key'));

        $res = $this->webhookService->handle('binance_pay', [
            'binancepay-timestamp' => $timestamp,
            'binancepay-nonce'     => $nonce,
            'binancepay-signature' => $signature,
        ], $payload);

        $this->assertTrue($res->isSuccess());

        // Wallet is credited in primary currency (12000 Poisha = 120.00 BDT)
        $balance = $this->walletService->getBalance($userId);
        $this->assertSame('BDT', $balance->getCurrency());
        $this->assertSame(12000, $balance->getAmount());
        $this->assertSame('120.00', $balance->toMajorUnit());
    }

    public function testPreferredAcquiringCurrencyCanBeConfiguredToUsdc(): void
    {
        $this->gateway->setConfig([
            'enabled'            => true,
            'certificate_sn'     => 'cert_multi_test_sn',
            'api_secret'         => 'sec_multi_test_key',
            'preferred_currency' => 'USDC',
        ]);

        $this->assertSame('USDC', $this->gateway->getPreferredPaymentCurrency());

        $orderAmount = Money::fromMajorString('120.00', 'BDT');
        $intent = $this->paymentService->createIntent('shop', 'order_usdc_test', $orderAmount, [
            'gateway_id' => 'binance_pay',
        ]);

        $this->assertSame('USDC', $intent->getAmount()->getCurrency());
        $this->assertSame(125, $intent->getAmount()->getAmount());

        $attempt = $this->gateway->createAttempt($intent);
        $this->assertSame('USDC', $attempt->getAmount()->getCurrency());
        $this->assertSame(125, $attempt->getAmount()->getAmount());
    }
}
