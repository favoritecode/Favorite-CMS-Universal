<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use CreateFavoritePayTables;
use CreateSettingsTable;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Pay\Controllers\PaymentWebhookController;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Contracts\RefundServiceInterface;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
use FavoriteCMS\Pay\Contracts\WebhookServiceInterface;
use FavoriteCMS\Pay\Domain\GatewayRefundResult;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Domain\VerifiedWebhookResult;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Gateways\Binance\BinancePayGateway;
use FavoriteCMS\Pay\Gateways\Binance\BinancePayHttpClient;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\RefundService;
use FavoriteCMS\Pay\Services\WalletService;
use FavoriteCMS\Pay\Services\WebhookService;
use FavoriteCMS\Pay\Support\DecimalFormatter;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BinancePayGatewayTest extends TestCase
{
    private Application $app;
    private Database $db;
    private PDO $pdo;
    private CurrencyService $currencyService;
    private GatewayRegistry $registry;
    private PaymentService $paymentService;
    private WalletService $walletService;
    private RefundService $refundService;
    private WebhookService $webhookService;
    private FavoritePayPlugin $plugin;
    private BinancePayGateway $gateway;
    private BinancePayHttpClient $httpClient;

    /** @var array<int, array{method: string, url: string, headers: array, body: string}> */
    private array $interceptedRequests = [];

    /** @var array<string, mixed>|callable|null */
    private mixed $mockResponseHandler = null;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        Setting::clearCache();
        FavoritePayPlugin::reset();

        $this->app = Application::getInstance();

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
        $this->app->instance(Database::class, $this->db);

        // Migrations
        require_once APP_ROOT . '/database/migrations/009_create_settings_table.php';
        (new CreateSettingsTable($this->db))->up();

        require_once APP_ROOT . '/plugins/favorite-pay/database/migrations/001_create_favorite_pay_tables.php';
        (new CreateFavoritePayTables($this->db))->up();

        // Default primary currency for tests
        Setting::set('general', 'primary_currency', 'USDT', 'string');

        $this->currencyService = new CurrencyService();
        $this->registry = new GatewayRegistry(false);
        $this->paymentService = new PaymentService($this->currencyService, $this->registry, $this->db);
        $this->walletService = new WalletService($this->currencyService, $this->paymentService, $this->db);
        $this->refundService = new RefundService($this->paymentService, $this->registry, $this->db);
        $this->webhookService = new WebhookService($this->registry, $this->paymentService, $this->db);

        $this->app->instance(GatewayRegistry::class, $this->registry);
        $this->app->instance(PaymentService::class, $this->paymentService);
        $this->app->instance(WalletService::class, $this->walletService);
        $this->app->instance(RefundService::class, $this->refundService);
        $this->app->instance(WebhookService::class, $this->webhookService);
        $this->app->instance(PaymentServiceInterface::class, $this->paymentService);
        $this->app->instance(WalletServiceInterface::class, $this->walletService);
        $this->app->instance(RefundServiceInterface::class, $this->refundService);
        $this->app->instance(WebhookServiceInterface::class, $this->webhookService);

        // Setup mock HTTP client transport
        $this->interceptedRequests = [];
        $this->mockResponseHandler = null;

        $transport = function (string $method, string $url, array $headers, string $body): array {
            $this->interceptedRequests[] = [
                'method'  => $method,
                'url'     => $url,
                'headers' => $headers,
                'body'    => $body,
            ];

            if (is_callable($this->mockResponseHandler)) {
                return ($this->mockResponseHandler)($method, $url, $headers, $body);
            }

            if (is_array($this->mockResponseHandler)) {
                return $this->mockResponseHandler;
            }

            // Default mock response: standard success
            return [
                'statusCode' => 200,
                'body'       => json_encode([
                    'status' => 'SUCCESS',
                    'code'   => '000000',
                    'data'   => [
                        'prepayId'    => '293838292838',
                        'checkoutUrl' => 'https://pay.binance.com/checkout?id=293838292838',
                        'qrcodeLink'  => 'https://qr.binance.com/293838292838',
                        'qrContent'   => 'binance://pay?order=293838292838',
                    ],
                ]),
            ];
        };

        $this->httpClient = new BinancePayHttpClient(
            'cert_sn_test_123456',
            'sec_key_test_abcdef987654321',
            BinancePayHttpClient::DEFAULT_BASE_URL,
            $transport
        );

        $this->gateway = new BinancePayGateway([
            'enabled'        => true,
            'certificate_sn' => 'cert_sn_test_123456',
            'api_secret'     => 'sec_key_test_abcdef987654321',
            'sandbox'        => false,
        ], $this->httpClient, $this->db);

        $this->registry->register($this->gateway, ['binance']);

        // Boot plugin to register event hooks (e.g. favorite.pay.payment.succeeded -> wallet settlement)
        $this->plugin = new FavoritePayPlugin($this->app);
        $this->plugin->boot();
    }

    protected function tearDown(): void
    {
        FavoritePayPlugin::reset();
        Setting::clearCache();
        parent::tearDown();
    }

    // ==========================================
    // 1. SIGNING TESTS
    // ==========================================

    public function testBinancePaySignatureGeneration(): void
    {
        $timestamp = '1625000000000';
        $nonce = 'abcdef0123456789abcdef0123456789';
        $body = '{"merchantTradeNo":"FP123456","orderAmount":10.5,"currency":"USDT"}';
        $secret = 'test_secret_key_12345';

        $signature = BinancePayHttpClient::buildSignature($timestamp, $nonce, $body, $secret);

        // Verification: uppercase hex SHA512
        $payload = $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $expected = strtoupper(hash_hmac('sha512', $payload, $secret));

        $this->assertSame($expected, $signature);
        $this->assertSame(128, strlen($signature)); // SHA512 is 64 bytes = 128 hex chars
        $this->assertSame($signature, strtoupper($signature));
    }

    public function testSignatureChangesWithDifferentSecret(): void
    {
        $timestamp = '1625000000000';
        $nonce = 'abcdef0123456789abcdef0123456789';
        $body = '{"order":"test"}';

        $sig1 = BinancePayHttpClient::buildSignature($timestamp, $nonce, $body, 'secret_A');
        $sig2 = BinancePayHttpClient::buildSignature($timestamp, $nonce, $body, 'secret_B');

        $this->assertNotSame($sig1, $sig2);
    }

    public function testSignatureChangesWithDifferentBody(): void
    {
        $timestamp = '1625000000000';
        $nonce = 'abcdef0123456789abcdef0123456789';
        $secret = 'secret_key';

        $sig1 = BinancePayHttpClient::buildSignature($timestamp, $nonce, '{"amount":10.0}', $secret);
        $sig2 = BinancePayHttpClient::buildSignature($timestamp, $nonce, '{"amount":10.01}', $secret);

        $this->assertNotSame($sig1, $sig2);
    }

    public function testSignatureChangesWithDifferentTimestamp(): void
    {
        $nonce = 'abcdef0123456789abcdef0123456789';
        $body = '{"amount":10.0}';
        $secret = 'secret_key';

        $sig1 = BinancePayHttpClient::buildSignature('1625000000000', $nonce, $body, $secret);
        $sig2 = BinancePayHttpClient::buildSignature('1625000000001', $nonce, $body, $secret);

        $this->assertNotSame($sig1, $sig2);
    }

    public function testSignatureChangesWithDifferentNonce(): void
    {
        $timestamp = '1625000000000';
        $body = '{"amount":10.0}';
        $secret = 'secret_key';

        $sig1 = BinancePayHttpClient::buildSignature($timestamp, '11111111111111111111111111111111', $body, $secret);
        $sig2 = BinancePayHttpClient::buildSignature($timestamp, '22222222222222222222222222222222', $body, $secret);

        $this->assertNotSame($sig1, $sig2);
    }

    public function testNonceIsExactly32CharactersAndCryptographicallyRandom(): void
    {
        $nonce1 = BinancePayHttpClient::generateNonce();
        $nonce2 = BinancePayHttpClient::generateNonce();

        $this->assertSame(32, strlen($nonce1));
        $this->assertSame(32, strlen($nonce2));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $nonce1);
        $this->assertNotSame($nonce1, $nonce2);
    }

    public function testTimestampIsMilliseconds(): void
    {
        $ts = BinancePayHttpClient::generateTimestamp();
        $this->assertMatchesRegularExpression('/^\d{13,}$/', $ts);
        $ms = (int)$ts;
        $nowMs = (int)(microtime(true) * 1000);
        $this->assertLessThanOrEqual(1000, abs($nowMs - $ms));
    }

    // ==========================================
    // 2. CONFIGURATION TESTS
    // ==========================================

    public function testMissingCertificateSnFailsSafely(): void
    {
        $client = new BinancePayHttpClient(null, 'secret_test', BinancePayHttpClient::DEFAULT_BASE_URL, function () {
            return ['statusCode' => 200, 'body' => '{}'];
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Certificate-SN");
        $client->request('POST', '/test');
    }

    public function testMissingSecretFailsSafely(): void
    {
        $client = new BinancePayHttpClient('cert_123', null, BinancePayHttpClient::DEFAULT_BASE_URL, function () {
            return ['statusCode' => 200, 'body' => '{}'];
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("API secret key is not configured");
        $client->request('POST', '/test');
    }

    public function testSecretFieldInConfigSchemaIsMarkedSecret(): void
    {
        $schema = $this->gateway->getConfigSchema();
        $this->assertArrayHasKey('api_secret', $schema);
        $this->assertTrue($schema['api_secret']['secret']);
        $this->assertSame('password', $schema['api_secret']['type']);
    }

    public function testSetConfigPreservesExistingSecretWhenBlankProvided(): void
    {
        $gateway = new BinancePayGateway([
            'certificate_sn' => 'cert_orig',
            'api_secret'     => 'super_secret_123',
        ]);

        $gateway->setConfig([
            'enabled'        => true,
            'certificate_sn' => 'cert_updated',
            'api_secret'     => '', // blank on edit
        ]);

        $config = $gateway->getConfig();
        $this->assertSame('cert_updated', $config['certificate_sn']);
        $this->assertSame('super_secret_123', $config['api_secret']);
    }

    // ==========================================
    // 3. DECIMAL FORMATTER TESTS
    // ==========================================

    public function testDecimalFormatterConversions(): void
    {
        $this->assertSame('10.50', DecimalFormatter::minorUnitToDecimal(1050, 2));
        $this->assertSame('0.05', DecimalFormatter::minorUnitToDecimal(5, 2));
        $this->assertSame('100.00', DecimalFormatter::minorUnitToDecimal(10000, 2));
        $this->assertSame('0.00', DecimalFormatter::minorUnitToDecimal(0, 2));
        $this->assertSame('500', DecimalFormatter::minorUnitToDecimal(500, 0));

        $this->assertSame(1050, DecimalFormatter::decimalToMinorUnits('10.50', 2));
        $this->assertSame(5, DecimalFormatter::decimalToMinorUnits('0.05', 2));
        $this->assertSame(10000, DecimalFormatter::decimalToMinorUnits('100.00', 2));
        $this->assertSame(10000, DecimalFormatter::decimalToMinorUnits('100', 2));
        $this->assertSame(0, DecimalFormatter::decimalToMinorUnits('0.00', 2));
    }

    public function testDecimalFormatterRejectsInvalidStrings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DecimalFormatter::decimalToMinorUnits('10.50.20', 2);
    }

    // ==========================================
    // 4. CREATE ORDER TESTS
    // ==========================================

    public function testCreateOrderSendsCorrectEndpointAndHeaders(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_101',
            new Money(2550, 'USDT'),
            ['customer_id' => 1]
        );

        $attempt = $this->gateway->createAttempt($intent, [
            'return_url' => 'https://example.com/checkout/success',
            'cancel_url' => 'https://example.com/checkout/cancel',
        ]);

        $this->assertCount(1, $this->interceptedRequests);
        $req = $this->interceptedRequests[0];

        $this->assertSame('POST', $req['method']);
        $this->assertSame('https://bpay.binanceapi.com/binancepay/openapi/v3/order', $req['url']);

        // Verify required headers
        $this->assertArrayHasKey('BinancePay-Timestamp', $req['headers']);
        $this->assertArrayHasKey('BinancePay-Nonce', $req['headers']);
        $this->assertArrayHasKey('BinancePay-Certificate-SN', $req['headers']);
        $this->assertArrayHasKey('BinancePay-Signature', $req['headers']);
        $this->assertSame('cert_sn_test_123456', $req['headers']['BinancePay-Certificate-SN']);

        // Verify JSON payload
        $body = json_decode($req['body'], true);
        $this->assertSame('USDT', $body['currency']);
        $this->assertSame(25.5, (float)$body['orderAmount']);
        $this->assertSame('https://example.com/checkout/success', $body['returnUrl']);
        $this->assertSame('https://example.com/checkout/cancel', $body['cancelUrl']);

        // Verify merchantTradeNo format: alphanumeric, max 32 chars
        $tradeNo = $body['merchantTradeNo'];
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{1,32}$/', $tradeNo);
        $this->assertLessThanOrEqual(32, strlen($tradeNo));
        $this->assertSame($tradeNo, $attempt->getTransactionReference());

        // Verify redirect metadata
        $this->assertSame('https://pay.binance.com/checkout?id=293838292838', $this->gateway->getRedirectUrl($attempt));
    }

    public function testUnsupportedCurrencyIsRejectedSafely(): void
    {
        // BDT is not a supported Binance Pay payment currency
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_bdt',
            Money::bdt(50000),
            ['customer_id' => 1]
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Binance Pay does not support payment currency 'BDT'");
        $this->gateway->createAttempt($intent);
    }

    public function testNonPositiveAmountIsRejected(): void
    {
        $intent = new PaymentIntent(
            'pi_zero',
            'favorite_shop',
            'order_zero',
            new Money(0, 'USDT'),
            new Money(0, 'USDT'),
            PaymentStatus::PENDING
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("strictly positive");
        $this->gateway->createAttempt($intent);
    }

    public function testProviderBusinessErrorIsHandledSafely(): void
    {
        $this->mockResponseHandler = [
            'statusCode' => 200,
            'body'       => json_encode([
                'status'       => 'FAIL',
                'code'         => '400002',
                'errorMessage' => 'Invalid merchant account configuration.',
            ]),
        ];

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_err',
            new Money(1000, 'USDT')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Binance Pay error [400002]: Invalid merchant account configuration.");
        $this->gateway->createAttempt($intent);
    }

    public function testTransportFailureThrowsSafeException(): void
    {
        $this->mockResponseHandler = [
            'statusCode' => 0,
            'body'       => '',
        ];

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_transport_err',
            new Money(1000, 'USDT')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Binance Pay transport failure");
        $this->gateway->createAttempt($intent);
    }

    // ==========================================
    // 5. WEBHOOK VERIFICATION TESTS
    // ==========================================

    public function testValidWebhookAccepted(): void
    {
        $secret = 'sec_key_test_abcdef987654321';
        $timestamp = '1625000000000';
        $nonce = '32characternoncetest123456789012';

        $payload = [
            'bizType'   => 'PAY',
            'bizId'     => 123456,
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => [
                'merchantTradeNo' => 'FPTESTORDER12345',
                'totalFee'        => '50.00',
                'currency'        => 'USDT',
            ],
        ];
        $rawBody = json_encode($payload);
        $signature = BinancePayHttpClient::buildSignature($timestamp, $nonce, $rawBody, $secret);

        $headers = [
            'binancepay-timestamp'      => $timestamp,
            'binancepay-nonce'          => $nonce,
            'binancepay-signature'      => $signature,
            'binancepay-certificate-sn' => 'cert_sn_test_123456',
        ];

        $result = $this->gateway->verifyWebhook($headers, $rawBody);

        $this->assertTrue($result->isVerified());
        $this->assertSame('FPTESTORDER12345', $result->getTransactionId());
        $this->assertSame(PaymentStatus::SUCCEEDED, $result->getStatus());
        $this->assertSame(5000, $result->getAmount()->getAmount());
        $this->assertSame('USDT', $result->getAmount()->getCurrency());
    }

    public function testInvalidWebhookSignatureRejected(): void
    {
        $headers = [
            'binancepay-timestamp'      => '1625000000000',
            'binancepay-nonce'          => '32characternoncetest123456789012',
            'binancepay-signature'      => 'INVALID_SIGNATURE_HEX_CODE',
            'binancepay-certificate-sn' => 'cert_sn_test_123456',
        ];

        $result = $this->gateway->verifyWebhook($headers, '{"test":1}');

        $this->assertFalse($result->isVerified());
        $this->assertStringContainsString('Invalid Binance Pay webhook signature', $result->getErrorMessage());
    }

    public function testMissingWebhookHeadersRejected(): void
    {
        $headers = [
            'binancepay-timestamp' => '1625000000000',
            // Missing nonce and signature
        ];

        $result = $this->gateway->verifyWebhook($headers, '{"test":1}');

        $this->assertFalse($result->isVerified());
        $this->assertStringContainsString('Missing required Binance Pay webhook headers', $result->getErrorMessage());
    }

    public function testWebhookModifiedBodyRejected(): void
    {
        $secret = 'sec_key_test_abcdef987654321';
        $timestamp = '1625000000000';
        $nonce = '32characternoncetest123456789012';
        $originalBody = json_encode(['bizType' => 'PAY', 'data' => ['totalFee' => '10.00']]);
        $signature = BinancePayHttpClient::buildSignature($timestamp, $nonce, $originalBody, $secret);

        $headers = [
            'binancepay-timestamp' => $timestamp,
            'binancepay-nonce'     => $nonce,
            'binancepay-signature' => $signature,
        ];

        // Tampered body
        $tamperedBody = json_encode(['bizType' => 'PAY', 'data' => ['totalFee' => '100.00']]);
        $result = $this->gateway->verifyWebhook($headers, $tamperedBody);

        $this->assertFalse($result->isVerified());
        $this->assertStringContainsString('Invalid Binance Pay webhook signature', $result->getErrorMessage());
    }

    public function testWebhookModifiedSignatureRejected(): void
    {
        $secret = 'sec_key_test_abcdef987654321';
        $timestamp = '1625000000000';
        $nonce = '32characternoncetest123456789012';
        $rawBody = json_encode(['bizType' => 'PAY', 'data' => ['merchantTradeNo' => 'FPTEST1']]);
        $signature = BinancePayHttpClient::buildSignature($timestamp, $nonce, $rawBody, $secret);

        // Tamper signature by modifying characters
        $tamperedSig = substr($signature, 0, -2) . ($signature[-2] === 'A' ? 'BB' : 'AA');

        $headers = [
            'binancepay-timestamp' => $timestamp,
            'binancepay-nonce'     => $nonce,
            'binancepay-signature' => $tamperedSig,
        ];

        $result = $this->gateway->verifyWebhook($headers, $rawBody);
        $this->assertFalse($result->isVerified());
        $this->assertStringContainsString('Invalid Binance Pay webhook signature', $result->getErrorMessage());
    }

    public function testWebhookModifiedTimestampRejected(): void
    {
        $secret = 'sec_key_test_abcdef987654321';
        $timestamp = '1625000000000';
        $nonce = '32characternoncetest123456789012';
        $rawBody = json_encode(['bizType' => 'PAY']);
        $signature = BinancePayHttpClient::buildSignature($timestamp, $nonce, $rawBody, $secret);

        $headers = [
            'binancepay-timestamp' => '1625000000001', // modified timestamp
            'binancepay-nonce'     => $nonce,
            'binancepay-signature' => $signature,
        ];

        $result = $this->gateway->verifyWebhook($headers, $rawBody);
        $this->assertFalse($result->isVerified());
        $this->assertStringContainsString('Invalid Binance Pay webhook signature', $result->getErrorMessage());
    }

    public function testWebhookMissingSignatureHeaderRejected(): void
    {
        $headers = [
            'binancepay-timestamp' => '1625000000000',
            'binancepay-nonce'     => '32characternoncetest123456789012',
            // Missing signature
        ];

        $result = $this->gateway->verifyWebhook($headers, '{"test":1}');
        $this->assertFalse($result->isVerified());
        $this->assertStringContainsString('Missing required Binance Pay webhook headers', $result->getErrorMessage());
    }

    public function testWebhookMissingNonceOrTimestampRejected(): void
    {
        $headersWithoutTimestamp = [
            'binancepay-nonce'     => '32characternoncetest123456789012',
            'binancepay-signature' => 'SIG',
        ];
        $this->assertFalse($this->gateway->verifyWebhook($headersWithoutTimestamp, '{"test":1}')->isVerified());

        $headersWithoutNonce = [
            'binancepay-timestamp' => '1625000000000',
            'binancepay-signature' => 'SIG',
        ];
        $this->assertFalse($this->gateway->verifyWebhook($headersWithoutNonce, '{"test":1}')->isVerified());
    }

    public function testWebhookMalformedOrEmptyPayloadRejected(): void
    {
        $secret = 'sec_key_test_abcdef987654321';
        $timestamp = '1625000000000';
        $nonce = '32characternoncetest123456789012';

        // 1. Empty string payload
        $headers = [
            'binancepay-timestamp' => $timestamp,
            'binancepay-nonce'     => $nonce,
            'binancepay-signature' => BinancePayHttpClient::buildSignature($timestamp, $nonce, '', $secret),
        ];
        $emptyResult = $this->gateway->verifyWebhook($headers, '');
        $this->assertFalse($emptyResult->isVerified());
        $this->assertStringContainsString('Malformed or empty Binance Pay webhook payload', $emptyResult->getErrorMessage());

        // 2. Malformed JSON with valid signature
        $malformedJson = '{"broken": json';
        $headers['binancepay-signature'] = BinancePayHttpClient::buildSignature($timestamp, $nonce, $malformedJson, $secret);
        $malformedResult = $this->gateway->verifyWebhook($headers, $malformedJson);
        $this->assertFalse($malformedResult->isVerified());
        $this->assertStringContainsString('Malformed or empty Binance Pay webhook payload', $malformedResult->getErrorMessage());
    }

    public function testWebhookRsaStyleSignatureRejected(): void
    {
        $timestamp = '1625000000000';
        $nonce = '32characternoncetest123456789012';
        $rawBody = json_encode(['bizType' => 'PAY', 'data' => ['merchantTradeNo' => 'FPTEST1']]);

        // Base64 RSA-like signature
        $rsaStyleSig = base64_encode(random_bytes(256));

        $headers = [
            'binancepay-timestamp'      => $timestamp,
            'binancepay-nonce'          => $nonce,
            'binancepay-signature'      => $rsaStyleSig,
            'binancepay-certificate-sn' => 'cert_sn_test_123456',
        ];

        $result = $this->gateway->verifyWebhook($headers, $rawBody);

        $this->assertFalse($result->isVerified());
        $this->assertStringContainsString('Invalid Binance Pay webhook signature', $result->getErrorMessage());
    }

    public function testWebhookAmountMismatchRejected(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_mismatch',
            new Money(10000, 'USDT'),
            ['customer_id' => 1]
        );

        $attempt = $this->gateway->createAttempt($intent);
        $merchantTradeNo = $attempt->getTransactionReference();

        // Save attempt to DB
        $this->db->insert('favorite_pay_attempts', [
            'attempt_id'         => $attempt->getId(),
            'transaction_id'     => $intent->getId(),
            'gateway_id'         => 'binance_pay',
            'amount'             => 10000,
            'currency'           => 'USDT',
            'status'             => 'pending',
            'provider_reference' => $merchantTradeNo,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        // Webhook arrives with 50.00 (5000 minor units) instead of expected 100.00 (10000)
        $secret = 'sec_key_test_abcdef987654321';
        $timestamp = '1625000000000';
        $nonce = '32characternoncetest123456789012';
        $payload = [
            'bizType'   => 'PAY',
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => [
                'merchantTradeNo' => $merchantTradeNo,
                'totalFee'        => '50.00',
                'currency'        => 'USDT',
            ],
        ];
        $rawBody = json_encode($payload);
        $signature = BinancePayHttpClient::buildSignature($timestamp, $nonce, $rawBody, $secret);

        $headers = [
            'binancepay-timestamp'      => $timestamp,
            'binancepay-nonce'          => $nonce,
            'binancepay-signature'      => $signature,
            'binancepay-certificate-sn' => 'cert_sn_test_123456',
        ];

        $handleResult = $this->webhookService->handle('binance_pay', $headers, $rawBody);

        $this->assertFalse($handleResult->isSuccess());
        $this->assertSame(422, $handleResult->getStatusCode());
        $this->assertStringContainsString('mismatch', $handleResult->getMessage());
    }

    public function testSuccessfulWebhookTriggersWalletSettlement(): void
    {
        $userId = 99;
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_success',
            new Money(4000, 'USDT'),
            ['customer_id' => $userId]
        );

        $attempt = $this->gateway->createAttempt($intent);
        $merchantTradeNo = $attempt->getTransactionReference();

        $this->db->insert('favorite_pay_attempts', [
            'attempt_id'         => $attempt->getId(),
            'transaction_id'     => $intent->getId(),
            'gateway_id'         => 'binance_pay',
            'amount'             => 4000,
            'currency'           => 'USDT',
            'status'             => 'pending',
            'provider_reference' => $merchantTradeNo,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $secret = 'sec_key_test_abcdef987654321';
        $timestamp = '1625000000000';
        $nonce = '32characternoncetest123456789012';
        $payload = [
            'bizType'   => 'PAY',
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => [
                'merchantTradeNo' => $merchantTradeNo,
                'totalFee'        => '40.00',
                'currency'        => 'USDT',
            ],
        ];
        $rawBody = json_encode($payload);
        $signature = BinancePayHttpClient::buildSignature($timestamp, $nonce, $rawBody, $secret);

        $headers = [
            'binancepay-timestamp'      => $timestamp,
            'binancepay-nonce'          => $nonce,
            'binancepay-signature'      => $signature,
            'binancepay-certificate-sn' => 'cert_sn_test_123456',
        ];

        $handleResult = $this->webhookService->handle('binance_pay', $headers, $rawBody);

        $this->assertTrue($handleResult->isSuccess());
        $this->assertSame(200, $handleResult->getStatusCode());

        // Check intent status is SUCCEEDED
        $updatedIntent = $this->paymentService->getIntent($intent->getId());
        $this->assertSame(PaymentStatus::SUCCEEDED, $updatedIntent->getStatus());

        // Check wallet was credited
        $balance = $this->walletService->getBalance($userId);
        $this->assertSame(4000, $balance->getAmount());

        // Second delivery is idempotent and does not double-credit wallet
        $duplicateResult = $this->webhookService->handle('binance_pay', $headers, $rawBody);
        $this->assertTrue($duplicateResult->isSuccess());
        $this->assertTrue($duplicateResult->isAlreadyProcessed());

        $balanceAfter = $this->walletService->getBalance($userId);
        $this->assertSame(4000, $balanceAfter->getAmount());
    }

    public function testWebhookWrongCurrencyRejected(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_wrong_currency',
            new Money(5000, 'USDT')
        );

        $attempt = $this->gateway->createAttempt($intent);
        $merchantTradeNo = $attempt->getTransactionReference();

        $this->db->insert('favorite_pay_attempts', [
            'attempt_id'         => $attempt->getId(),
            'transaction_id'     => $intent->getId(),
            'gateway_id'         => 'binance_pay',
            'amount'             => 5000,
            'currency'           => 'USDT',
            'status'             => 'pending',
            'provider_reference' => $merchantTradeNo,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $secret = 'sec_key_test_abcdef987654321';
        $timestamp = '1625000000000';
        $nonce = '32characternoncetest123456789012';
        $payload = [
            'bizType'   => 'PAY',
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => [
                'merchantTradeNo' => $merchantTradeNo,
                'totalFee'        => '50.00',
                'currency'        => 'EUR', // Wrong currency!
            ],
        ];
        $rawBody = json_encode($payload);
        $signature = BinancePayHttpClient::buildSignature($timestamp, $nonce, $rawBody, $secret);

        $headers = [
            'binancepay-timestamp'      => $timestamp,
            'binancepay-nonce'          => $nonce,
            'binancepay-signature'      => $signature,
            'binancepay-certificate-sn' => 'cert_sn_test_123456',
        ];

        $handleResult = $this->webhookService->handle('binance_pay', $headers, $rawBody);
        $this->assertFalse($handleResult->isSuccess());
        $this->assertSame(422, $handleResult->getStatusCode());
        $this->assertStringContainsString('mismatch', $handleResult->getMessage());
    }

    public function testWebhookUnknownMerchantTradeNoRejected(): void
    {
        $secret = 'sec_key_test_abcdef987654321';
        $timestamp = '1625000000000';
        $nonce = '32characternoncetest123456789012';
        $payload = [
            'bizType'   => 'PAY',
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => [
                'merchantTradeNo' => 'UNKNOWN_NONEXISTENT_TRADE_NO_999',
                'totalFee'        => '50.00',
                'currency'        => 'USDT',
            ],
        ];
        $rawBody = json_encode($payload);
        $signature = BinancePayHttpClient::buildSignature($timestamp, $nonce, $rawBody, $secret);

        $headers = [
            'binancepay-timestamp'      => $timestamp,
            'binancepay-nonce'          => $nonce,
            'binancepay-signature'      => $signature,
            'binancepay-certificate-sn' => 'cert_sn_test_123456',
        ];

        $handleResult = $this->webhookService->handle('binance_pay', $headers, $rawBody);
        $this->assertFalse($handleResult->isSuccess());
        $this->assertSame(404, $handleResult->getStatusCode());
    }

    public function testWebhookInvalidSignatureCanNeverTriggerPaymentSuccessOrWalletCredit(): void
    {
        $userId = 77;
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_forged_attack',
            new Money(8000, 'USDT'),
            ['customer_id' => $userId]
        );

        $attempt = $this->gateway->createAttempt($intent);
        $merchantTradeNo = $attempt->getTransactionReference();

        $this->db->insert('favorite_pay_attempts', [
            'attempt_id'         => $attempt->getId(),
            'transaction_id'     => $intent->getId(),
            'gateway_id'         => 'binance_pay',
            'amount'             => 8000,
            'currency'           => 'USDT',
            'status'             => 'pending',
            'provider_reference' => $merchantTradeNo,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        // Attacker attempts to forge PAY_SUCCESS with bogus signature
        $payload = [
            'bizType'   => 'PAY',
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => [
                'merchantTradeNo' => $merchantTradeNo,
                'totalFee'        => '80.00',
                'currency'        => 'USDT',
            ],
        ];
        $rawBody = json_encode($payload);

        $headers = [
            'binancepay-timestamp'      => '1625000000000',
            'binancepay-nonce'          => '32characternoncetest123456789012',
            'binancepay-signature'      => 'DEADBEEF0123456789ABCDEFDEADBEEF0123456789ABCDEFDEADBEEF0123456789ABCDEFDEADBEEF0123456789ABCDEFDEADBEEF0123456789ABCDEFDEADBEEF01234567',
            'binancepay-certificate-sn' => 'cert_sn_test_123456',
        ];

        $handleResult = $this->webhookService->handle('binance_pay', $headers, $rawBody);

        $this->assertFalse($handleResult->isSuccess());
        $this->assertSame(401, $handleResult->getStatusCode());

        // Verify intent status is STILL PENDING, not SUCCEEDED
        $unmodifiedIntent = $this->paymentService->getIntent($intent->getId());
        $this->assertSame(PaymentStatus::PENDING, $unmodifiedIntent->getStatus());

        // Verify attempt status is STILL PENDING
        $row = $this->db->selectOne("SELECT * FROM favorite_pay_attempts WHERE attempt_id = ?", [$attempt->getId()]);
        $this->assertNotNull($row);
        $this->assertSame('pending', $row->status);

        // Verify wallet was NEVER credited
        $balance = $this->walletService->getBalance($userId);
        $this->assertSame(0, $balance->getAmount());
    }

    public function testConfigSchemaDoesNotContainPublicKey(): void
    {
        $schema = $this->gateway->getConfigSchema();
        $this->assertArrayNotHasKey('public_key', $schema);
        $this->assertArrayHasKey('certificate_sn', $schema);
        $this->assertArrayHasKey('api_secret', $schema);
    }

    // ==========================================
    // 6. ORDER QUERY TESTS
    // ==========================================

    public function testOrderQuerySuccessStatus(): void
    {
        $this->mockResponseHandler = [
            'statusCode' => 200,
            'body'       => json_encode([
                'status' => 'SUCCESS',
                'code'   => '000000',
                'data'   => [
                    'merchantTradeNo' => 'FPTESTORDERQUERY1',
                    'status'          => 'PAID',
                    'currency'        => 'USDT',
                    'orderAmount'     => '15.00',
                ],
            ]),
        ];

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_query_test',
            new Money(1500, 'USDT')
        );

        $attempt = new PaymentAttempt(
            'att_query_1',
            $intent->getId(),
            'binance_pay',
            new Money(1500, 'USDT'),
            PaymentStatus::PENDING,
            'FPTESTORDERQUERY1',
            null,
            null,
            null,
            null,
            null,
            null,
            ['merchant_trade_no' => 'FPTESTORDERQUERY1']
        );

        $status = $this->gateway->queryStatus($attempt);

        $this->assertSame(PaymentStatus::SUCCEEDED, $status);
        $this->assertCount(1, $this->interceptedRequests);
        $this->assertSame('https://bpay.binanceapi.com/binancepay/openapi/order/query', $this->interceptedRequests[0]['url']);
    }

    public function testOrderQueryAmountMismatchThrowsException(): void
    {
        $this->mockResponseHandler = [
            'statusCode' => 200,
            'body'       => json_encode([
                'status' => 'SUCCESS',
                'code'   => '000000',
                'data'   => [
                    'merchantTradeNo' => 'FPTESTORDERQUERY2',
                    'status'          => 'PAID',
                    'currency'        => 'USDT',
                    'orderAmount'     => '10.00', // Expected 15.00
                ],
            ]),
        ];

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_query_mismatch',
            new Money(1500, 'USDT')
        );

        $attempt = new PaymentAttempt(
            'att_query_2',
            $intent->getId(),
            'binance_pay',
            new Money(1500, 'USDT'),
            PaymentStatus::PENDING,
            'FPTESTORDERQUERY2',
            null,
            null,
            null,
            null,
            null,
            null,
            ['merchant_trade_no' => 'FPTESTORDERQUERY2']
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Order query mismatch");
        $this->gateway->queryStatus($attempt);
    }

    // ==========================================
    // 7. REFUND TESTS
    // ==========================================

    public function testBinancePayRefundSuccess(): void
    {
        $this->mockResponseHandler = [
            'statusCode' => 200,
            'body'       => json_encode([
                'status' => 'SUCCESS',
                'code'   => '000000',
                'data'   => [
                    'refundId'        => 'refund_binance_123456',
                    'refundRequestId' => 'REFTEST12345',
                    'status'          => 'SUCCESS',
                ],
            ]),
        ];

        $result = $this->gateway->refund('FPTRADE12345', new Money(1200, 'USDT'), 'Customer return');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('refund_binance_123456', $result->getProviderRefundReference());
        $this->assertSame(1200, $result->getAmount()->getAmount());
    }

    public function testBinancePayRefundFailureHandled(): void
    {
        $this->mockResponseHandler = [
            'statusCode' => 200,
            'body'       => json_encode([
                'status'       => 'FAIL',
                'code'         => '400010',
                'errorMessage' => 'Insufficient merchant balance for refund.',
            ]),
        ];

        $result = $this->gateway->refund('FPTRADE12345', new Money(1200, 'USDT'), 'Customer return');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Insufficient merchant balance', $result->getErrorMessage());
    }

    // ==========================================
    // 8. REGISTRY & ALIAS TESTS
    // ==========================================

    public function testBinanceGatewayIsLoadedInRegistry(): void
    {
        $registry = new GatewayRegistry(true);
        $this->assertTrue($registry->has('binance_pay'));
        $this->assertTrue($registry->has('binance')); // Alias
        $this->assertSame('binance_pay', $registry->resolveId('binance'));

        $gw = $registry->get('binance_pay');
        $this->assertInstanceOf(BinancePayGateway::class, $gw);
        $this->assertSame('Binance Pay', $gw->getTitle());
        $this->assertSame(PaymentMethodType::CRYPTO, $gw->getType());
    }

    public function testWebhookCurrencyMismatchRejected(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_cur_mismatch',
            new Money(10000, 'USDT'),
            ['customer_id' => 1]
        );

        $attempt = $this->gateway->createAttempt($intent);
        $merchantTradeNo = $attempt->getTransactionReference();

        $this->db->insert('favorite_pay_attempts', [
            'attempt_id'         => $attempt->getId(),
            'transaction_id'     => $intent->getId(),
            'gateway_id'         => 'binance_pay',
            'amount'             => 10000,
            'currency'           => 'USDT',
            'status'             => 'pending',
            'provider_reference' => $merchantTradeNo,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $secret = 'sec_key_test_abcdef987654321';
        $timestamp = '1625000000000';
        $nonce = '32characternoncetest123456789012';
        $payload = [
            'bizType'   => 'PAY',
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => [
                'merchantTradeNo' => $merchantTradeNo,
                'totalFee'        => '100.00',
                'currency'        => 'EUR',
            ],
        ];
        $rawBody = json_encode($payload);
        $signature = BinancePayHttpClient::buildSignature($timestamp, $nonce, $rawBody, $secret);

        $headers = [
            'binancepay-timestamp'      => $timestamp,
            'binancepay-nonce'          => $nonce,
            'binancepay-signature'      => $signature,
            'binancepay-certificate-sn' => 'cert_sn_test_123456',
        ];

        $handleResult = $this->webhookService->handle('binance_pay', $headers, $rawBody);

        $this->assertFalse($handleResult->isSuccess());
        $this->assertSame(422, $handleResult->getStatusCode());
        $this->assertStringContainsString('mismatch', $handleResult->getMessage());
    }

    public function testWebhookUnknownTradeNoRejected(): void
    {
        $secret = 'sec_key_test_abcdef987654321';
        $timestamp = '1625000000000';
        $nonce = '32characternoncetest123456789012';
        $payload = [
            'bizType'   => 'PAY',
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => [
                'merchantTradeNo' => 'UNKNOWN_NONEXISTENT_TRADE_NO',
                'totalFee'        => '100.00',
                'currency'        => 'USDT',
            ],
        ];
        $rawBody = json_encode($payload);
        $signature = BinancePayHttpClient::buildSignature($timestamp, $nonce, $rawBody, $secret);

        $headers = [
            'binancepay-timestamp'      => $timestamp,
            'binancepay-nonce'          => $nonce,
            'binancepay-signature'      => $signature,
            'binancepay-certificate-sn' => 'cert_sn_test_123456',
        ];

        $handleResult = $this->webhookService->handle('binance_pay', $headers, $rawBody);

        $this->assertFalse($handleResult->isSuccess());
        $this->assertSame(404, $handleResult->getStatusCode());
    }

    public function testWebhookNonSuccessStatusDoesNotSettle(): void
    {
        $userId = 150;
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_nonsuccess',
            new Money(3000, 'USDT'),
            ['customer_id' => $userId]
        );

        $attempt = $this->gateway->createAttempt($intent);
        $merchantTradeNo = $attempt->getTransactionReference();

        $this->db->insert('favorite_pay_attempts', [
            'attempt_id'         => $attempt->getId(),
            'transaction_id'     => $intent->getId(),
            'gateway_id'         => 'binance_pay',
            'amount'             => 3000,
            'currency'           => 'USDT',
            'status'             => 'pending',
            'provider_reference' => $merchantTradeNo,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        $secret = 'sec_key_test_abcdef987654321';
        $timestamp = '1625000000000';
        $nonce = '32characternoncetest123456789012';
        $payload = [
            'bizType'   => 'PAY',
            'bizStatus' => 'PAY_CLOSED',
            'data'      => [
                'merchantTradeNo' => $merchantTradeNo,
                'totalFee'        => '30.00',
                'currency'        => 'USDT',
            ],
        ];
        $rawBody = json_encode($payload);
        $signature = BinancePayHttpClient::buildSignature($timestamp, $nonce, $rawBody, $secret);

        $headers = [
            'binancepay-timestamp'      => $timestamp,
            'binancepay-nonce'          => $nonce,
            'binancepay-signature'      => $signature,
            'binancepay-certificate-sn' => 'cert_sn_test_123456',
        ];

        $handleResult = $this->webhookService->handle('binance_pay', $headers, $rawBody);

        $this->assertTrue($handleResult->isSuccess()); // Webhook handled cleanly
        $this->assertNotSame(PaymentStatus::SUCCEEDED, $handleResult->getAttempt()->getStatus());

        // Wallet was NOT credited
        $balance = $this->walletService->getBalance($userId);
        $this->assertSame(0, $balance->getAmount());
    }

    public function testOrderQueryByPrepayId(): void
    {
        $this->mockResponseHandler = [
            'statusCode' => 200,
            'body'       => json_encode([
                'status' => 'SUCCESS',
                'code'   => '000000',
                'data'   => [
                    'prepayId'        => 'PREPAY98765',
                    'merchantTradeNo' => 'FPTESTPREPAY',
                    'status'          => 'PAID',
                    'currency'        => 'USDT',
                    'orderAmount'     => '20.00',
                ],
            ]),
        ];

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_prepay_query',
            new Money(2000, 'USDT')
        );

        $attempt = new PaymentAttempt(
            'att_query_prepay',
            $intent->getId(),
            'binance_pay',
            new Money(2000, 'USDT'),
            PaymentStatus::PENDING,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            ['prepay_id' => 'PREPAY98765']
        );

        $status = $this->gateway->queryStatus($attempt);

        $this->assertSame(PaymentStatus::SUCCEEDED, $status);
        $this->assertCount(1, $this->interceptedRequests);
        $reqBody = json_decode($this->interceptedRequests[0]['body'], true);
        $this->assertSame('PREPAY98765', $reqBody['prepayId']);
    }

    public function testOrderQueryNonPaidStatusReturnsPendingOrCancelled(): void
    {
        $this->mockResponseHandler = [
            'statusCode' => 200,
            'body'       => json_encode([
                'status' => 'SUCCESS',
                'code'   => '000000',
                'data'   => [
                    'merchantTradeNo' => 'FPPENDING1',
                    'status'          => 'PENDING',
                    'currency'        => 'USDT',
                    'orderAmount'     => '20.00',
                ],
            ]),
        ];

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_pending_query',
            new Money(2000, 'USDT')
        );

        $attempt = new PaymentAttempt(
            'att_query_pending',
            $intent->getId(),
            'binance_pay',
            new Money(2000, 'USDT'),
            PaymentStatus::PENDING,
            'FPPENDING1',
            null,
            null,
            null,
            null,
            null,
            null,
            ['merchant_trade_no' => 'FPPENDING1']
        );

        $status = $this->gateway->queryStatus($attempt);
        $this->assertSame(PaymentStatus::PENDING, $status);
    }
}
