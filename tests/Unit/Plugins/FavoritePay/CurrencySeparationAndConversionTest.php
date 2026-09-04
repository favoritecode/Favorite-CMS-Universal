<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Pay\Domain\ConversionSnapshot;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Domain\VerifiedWebhookResult;
use FavoriteCMS\Pay\Exceptions\UnauthoritativeRateException;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Gateways\Binance\BinancePayGateway;
use FavoriteCMS\Pay\Gateways\Binance\BinancePayHttpClient;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
use FavoriteCMS\Pay\Providers\DatabaseRateProvider;
use FavoriteCMS\Pay\Providers\LiveExchangeRateProvider;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\WalletService;
use FavoriteCMS\Pay\Services\WebhookService;
use FavoriteCMS\Pay\Support\DecimalFormatter;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Currency Separation and Payment Flow Regression Test Suite
 *
 * Verifies strict separation between commercial original order amount/currency
 * and gateway acquiring amount/currency across all manual and automatic payment methods.
 */
class CurrencySeparationAndConversionTest extends TestCase
{
    private PDO $pdo;
    private Database $db;
    private CurrencyService $currencyService;
    private GatewayRegistry $registry;
    private PaymentService $paymentService;
    private WalletService $walletService;
    private WebhookService $webhookService;
    private BinancePayGateway $binanceGateway;

    /** @var array<int, array{method: string, url: string, headers: array, body: string}> */
    private array $interceptedRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        Setting::clearCache();
        FavoritePayPlugin::reset();

        $app = Application::getInstance();

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

        $this->db->execute("
            CREATE TABLE favorite_pay_rates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                base_currency VARCHAR(10) NOT NULL,
                quote_currency VARCHAR(10) NOT NULL,
                rate REAL NULL,
                rate_factor INTEGER NOT NULL,
                rate_scale INTEGER NOT NULL DEFAULT 1000000,
                is_authoritative INTEGER NOT NULL DEFAULT 1,
                status VARCHAR(20) DEFAULT 'active',
                effective_at DATETIME NOT NULL,
                expires_at DATETIME NULL,
                source VARCHAR(50) DEFAULT 'database',
                notes TEXT NULL,
                operator_id INTEGER NULL,
                created_at DATETIME NOT NULL
            );
            CREATE TABLE favorite_pay_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                transaction_id VARCHAR(64) UNIQUE NOT NULL,
                user_id INTEGER NULL,
                source_plugin VARCHAR(50) NOT NULL,
                source_reference VARCHAR(100) NOT NULL,
                amount_bdt INTEGER NOT NULL,
                amount_pay INTEGER NOT NULL,
                currency_pay VARCHAR(10) NOT NULL,
                status VARCHAR(30) NOT NULL,
                payment_method_type VARCHAR(50) NULL,
                completed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            );
            CREATE TABLE favorite_pay_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                attempt_id VARCHAR(64) UNIQUE NOT NULL,
                transaction_id VARCHAR(64) NOT NULL,
                gateway_id VARCHAR(50) NOT NULL,
                amount INTEGER NOT NULL,
                currency VARCHAR(10) NOT NULL,
                status VARCHAR(30) NOT NULL,
                provider_reference VARCHAR(100) NULL,
                operator_notes TEXT NULL,
                verified_by INTEGER NULL,
                verified_at DATETIME NULL,
                error_message TEXT NULL,
                response_payload TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL
            );
            CREATE TABLE favorite_pay_wallets (
                user_id INTEGER PRIMARY KEY,
                balance INTEGER NOT NULL DEFAULT 0,
                currency VARCHAR(10) NOT NULL DEFAULT 'BDT',
                updated_at DATETIME NOT NULL
            );
            CREATE TABLE favorite_pay_wallet_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entry_id VARCHAR(64) UNIQUE NOT NULL,
                user_id INTEGER NOT NULL,
                type VARCHAR(20) NOT NULL,
                amount INTEGER NOT NULL,
                currency VARCHAR(10) NOT NULL,
                balance_after INTEGER NOT NULL,
                reference_type VARCHAR(50) NOT NULL,
                reference_id VARCHAR(100) NOT NULL,
                idempotency_key VARCHAR(100) NULL,
                description TEXT NULL,
                created_at DATETIME NOT NULL
            );
        ");

        $databaseProvider = new DatabaseRateProvider($this->db);
        $this->currencyService = new CurrencyService($databaseProvider, $this->db);
        $this->registry = new GatewayRegistry();
        $this->paymentService = new PaymentService($this->currencyService, $this->registry, $this->db);
        $this->walletService = new WalletService($this->currencyService, $this->paymentService, $this->db);
        $this->webhookService = new WebhookService($this->registry, $this->paymentService);

        if (function_exists('remove_action')) {
            remove_action('favorite.pay.payment.succeeded');
        }
        if (function_exists('add_action')) {
            add_action('favorite.pay.payment.succeeded', function ($data) {
                $txId = $data['transaction_id'] ?? null;
                if ($txId) {
                    $this->walletService->settleSuccessfulPayment($txId);
                }
            });
        }

        // Register Manual Gateways

        $this->registry->register(new ManualBangladeshGateway(
            'bkash_personal',
            'Manual bKash',
            \FavoriteCMS\Pay\Domain\PaymentMethodType::MANUAL_BD,
            ['account_number' => '01700000001', 'channel' => 'bkash_personal'],
            true
        ));
        $this->registry->register(new ManualBangladeshGateway(
            'nagad_personal',
            'Manual Nagad',
            \FavoriteCMS\Pay\Domain\PaymentMethodType::MANUAL_BD,
            ['account_number' => '01700000002', 'channel' => 'nagad_personal'],
            true
        ));
        $this->registry->register(new ManualBangladeshGateway(
            'rocket_personal',
            'Manual Rocket',
            \FavoriteCMS\Pay\Domain\PaymentMethodType::MANUAL_BD,
            ['account_number' => '01700000003', 'channel' => 'rocket_personal'],
            true
        ));
        $this->registry->register(new ManualBangladeshGateway(
            'bank_asia',
            'Manual Bank Transfer',
            \FavoriteCMS\Pay\Domain\PaymentMethodType::MANUAL_BANK,
            ['account_number' => '1234567890', 'channel' => 'bank_asia'],
            true
        ));


        // Register Binance Gateway with intercepted transport
        $this->interceptedRequests = [];
        $httpClient = new BinancePayHttpClient('test_cert_sn', 'test_api_secret');
        $httpClient->setTransport(function (string $method, string $url, array $headers, string $body) {
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
                        'prepayId'     => 'prepay_test_9999',
                        'checkoutUrl'  => 'https://pay.binance.com/checkout?id=9999',
                        'universalUrl' => 'https://app.binance.com/qr/9999',
                    ],
                ]),
            ];
        });

        $this->binanceGateway = new BinancePayGateway([
            'enabled'        => true,
            'certificate_sn' => 'test_cert_sn',
            'api_secret'     => 'test_api_secret',
        ], $httpClient, $this->db, $this->currencyService);

        $this->registry->register($this->binanceGateway);
    }

    protected function tearDown(): void
    {
        if (function_exists('remove_action')) {
            remove_action('favorite.pay.payment.succeeded');
        }
        parent::tearDown();
    }

    /**
     * TEST 1: 120 BDT manual bKash -> charge = 120 BDT, conversion = none.
     */
    public function testManualBkashChargesExactOriginalOrderWithoutConversion(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_101',
            Money::bdt(12000), // 120.00 BDT
            ['customer_id' => 1]
        );

        $this->assertSame(12000, $intent->getBaseAmount()->getAmount());
        $this->assertSame('BDT', $intent->getBaseAmount()->getCurrency());
        $this->assertSame(12000, $intent->getChargeAmount()->getAmount());
        $this->assertSame('BDT', $intent->getChargeAmount()->getCurrency());
        $this->assertNull($intent->getConversionSnapshot());

        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'bkash_personal',
            'TRX_BKASH_120',
            ['sender_account' => '01711111111']
        );

        $this->assertSame(12000, $attempt->getAmount()->getAmount());
        $this->assertSame('BDT', $attempt->getAmount()->getCurrency());
        $this->assertSame(12000, $attempt->getChargeAmount()->getAmount());
        $this->assertSame('BDT', $attempt->getChargeCurrency());
        $this->assertSame(12000, $attempt->getOriginalOrderAmount()->getAmount());
        $this->assertSame('BDT', $attempt->getOriginalOrderCurrency());
        $this->assertNull($attempt->getConversionSnapshot());
        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $attempt->getStatus());
    }

    /**
     * TEST 2: 120 BDT manual Nagad -> charge = 120 BDT.
     */
    public function testManualNagadChargesExactOriginalOrderWithoutConversion(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_102',
            Money::bdt(12000),
            ['customer_id' => 1]
        );

        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'nagad_personal',
            'TRX_NAGAD_120'
        );

        $this->assertSame(12000, $attempt->getAmount()->getAmount());
        $this->assertSame('BDT', $attempt->getAmount()->getCurrency());
        $this->assertSame(12000, $attempt->getOriginalOrderAmount()->getAmount());
        $this->assertSame('BDT', $attempt->getOriginalOrderCurrency());
    }

    /**
     * TEST 3: 120 BDT manual Rocket -> charge = 120 BDT.
     */
    public function testManualRocketChargesExactOriginalOrderWithoutConversion(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_103',
            Money::bdt(12000),
            ['customer_id' => 1]
        );

        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'rocket_personal',
            'TRX_ROCKET_120'
        );

        $this->assertSame(12000, $attempt->getAmount()->getAmount());
        $this->assertSame('BDT', $attempt->getAmount()->getCurrency());
    }

    /**
     * TEST 4: 120 BDT manual Bank -> charge = 120 BDT.
     */
    public function testManualBankChargesExactOriginalOrderWithoutConversion(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_104',
            Money::bdt(12000),
            ['customer_id' => 1]
        );

        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'bank_asia',
            'DEP_REF_120'
        );

        $this->assertSame(12000, $attempt->getAmount()->getAmount());
        $this->assertSame('BDT', $attempt->getAmount()->getCurrency());
    }

    /**
     * TEST 5: 120 BDT Binance with example rate: 1 USDT = 127 BDT
     * -> charge approximately 0.94 USDT (94 minor units)
     * -> NOT 120 USDT
     * -> NOT 120 USD
     */
    public function testBinanceChargesConvertedUsdtAndNeverOriginalNumericAmount(): void
    {
        // 1. Authoritative rate: 1 USDT = 127 BDT
        $this->currencyService->setOperatorRate('USDT', '127.00', 1, 'BDT');

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_binance_120',
            Money::bdt(12000), // 120.00 BDT
            ['customer_id' => 1]
        );

        $attempt = $this->binanceGateway->createAttempt($intent);

        // Verify request sent to Binance Pay OpenAPI
        $this->assertCount(1, $this->interceptedRequests);
        $request = $this->interceptedRequests[0];
        $payload = json_decode($request['body'], true);

        // Absolute business rule checks:
        // 120 / 127 = 0.944881... -> 0.94 USDT (94 minor units)
        $this->assertSame('USDT', $payload['currency']);
        $this->assertSame(0.94, (float)$payload['orderAmount']);
        $this->assertNotSame(120.0, (float)$payload['orderAmount']);
        $this->assertNotSame('USD', $payload['currency']);
        $this->assertNotSame('BDT', $payload['currency']);

        // Attempt verification
        $this->assertSame('USDT', $attempt->getAmount()->getCurrency());
        $this->assertSame(94, $attempt->getAmount()->getAmount()); // 0.94 USDT
        $this->assertSame('USDT', $attempt->getChargeCurrency());
        $this->assertSame(94, $attempt->getChargeAmount()->getAmount());

        // Original order amount preserved
        $this->assertSame('BDT', $attempt->getOriginalOrderCurrency());
        $this->assertSame(12000, $attempt->getOriginalOrderAmount()->getAmount());

        // Snapshot is authoritative and locked
        $snapshot = $attempt->getConversionSnapshot();
        $this->assertNotNull($snapshot);
        $this->assertSame('BDT', $snapshot->getFromCurrency());
        $this->assertSame('USDT', $snapshot->getToCurrency());
        $this->assertTrue($snapshot->isAuthoritative());
    }

    /**
     * TEST 6: 120 USD Binance with 1 USD = 1 USDT example provider rate
     * -> charge = 120 USDT
     * -> only because the conversion provider says so
     * -> no hardcoded 1:1 logic
     */
    public function testBinanceChargesUsdtBasedOnProviderRateForUsdOrder(): void
    {
        // Provider explicitly states rate between USD and USDT
        $this->currencyService->setOperatorRate('USD', '1.00', 1, 'USDT');

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_usd_120',
            Money::usd(12000), // 120.00 USD
            ['customer_id' => 1]
        );

        $attempt = $this->binanceGateway->createAttempt($intent);

        $this->assertCount(1, $this->interceptedRequests);
        $payload = json_decode($this->interceptedRequests[0]['body'], true);

        $this->assertSame('USDT', $payload['currency']);
        $this->assertSame(120.0, (float)$payload['orderAmount']);
        $this->assertSame('USDT', $attempt->getAmount()->getCurrency());
        $this->assertSame(12000, $attempt->getAmount()->getAmount());

        $this->assertSame('USD', $attempt->getOriginalOrderCurrency());
        $this->assertSame(12000, $attempt->getOriginalOrderAmount()->getAmount());
    }

    /**
     * TEST 7: Change rate after snapshot
     * -> existing attempt amount remains unchanged
     */
    public function testRateChangeAfterSnapshotDoesNotMutateExistingAttemptAmount(): void
    {
        // Initial rate: 1 USDT = 127 BDT
        $this->currencyService->setOperatorRate('USDT', '127.00', 1, 'BDT');

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_locked_test',
            Money::bdt(12000),
            ['customer_id' => 1]
        );

        $attempt = $this->binanceGateway->createAttempt($intent);
        $this->assertSame(94, $attempt->getAmount()->getAmount()); // 0.94 USDT

        // Now market rate changes drastically: 1 USDT = 150 BDT
        $this->currencyService->setOperatorRate('USDT', '150.00', 1, 'BDT');

        // Existing attempt amount remains strictly locked at 94 minor units (0.94 USDT)
        $this->assertSame(94, $attempt->getAmount()->getAmount());
        $this->assertSame('USDT', $attempt->getAmount()->getCurrency());

        // Snapshot rate factor inside attempt remains unchanged
        $snap = $attempt->getConversionSnapshot();
        $this->assertNotNull($snap);
        $this->assertSame(7874, $snap->getRateFactor());
    }

    /**
     * TEST 8: Webhook with correct acquiring amount/currency -> accepted, wallet settled in BDT.
     */
    public function testWebhookWithCorrectAcquiringAmountAcceptedAndSettlesInOriginalCurrency(): void
    {
        $this->currencyService->setOperatorRate('USDT', '127.00', 1, 'BDT');

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_wh_test',
            Money::bdt(12000),
            ['customer_id' => 42]
        );

        $attempt = $this->binanceGateway->createAttempt($intent);
        $this->paymentService->recordAttempt($attempt);

        $tradeNo = $attempt->getTransactionReference();

        // Webhook payload sending 0.94 USDT
        $payload = [
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => [
                'merchantTradeNo' => $tradeNo,
                'totalFee'        => '0.94',
                'currency'        => 'USDT',
                'status'          => 'PAID',
            ],
        ];
        $body = json_encode($payload);
        $timestamp = (string)(time() * 1000);
        $nonce = 'nonce_12345678901234567890123456789012';
        $signPayload = $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $signature = hash_hmac('sha512', $signPayload, 'test_api_secret');

        $headers = [
            'BinancePay-Timestamp'      => $timestamp,
            'BinancePay-Nonce'          => $nonce,
            'BinancePay-Certificate-SN' => 'test_cert_sn',
            'BinancePay-Signature'      => $signature,
        ];

        $result = $this->webhookService->handle('binance_pay', $headers, $body);
        $this->assertTrue($result->isSuccess());

        // Attempt is now succeeded
        $updatedAttempt = $this->paymentService->getAttempt($attempt->getId());
        $this->assertSame(PaymentStatus::SUCCEEDED, $updatedAttempt->getStatus());

        // Wallet settlement: Credited exactly 120.00 BDT (12000 minor units), NOT 0.94 USDT
        $walletBalance = $this->walletService->getBalance(42);
        $this->assertSame('BDT', $walletBalance->getCurrency());
        $this->assertSame(12000, $walletBalance->getAmount());
    }

    /**
     * TEST 9: Webhook with original numeric amount (120) but wrong currency -> rejected.
     */
    public function testWebhookWithOriginalNumericAmountInWrongCurrencyRejected(): void
    {
        $this->currencyService->setOperatorRate('USDT', '127.00', 1, 'BDT');

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_wh_mismatch',
            Money::bdt(12000),
            ['customer_id' => 43]
        );

        $attempt = $this->binanceGateway->createAttempt($intent);
        $this->paymentService->recordAttempt($attempt);
        $tradeNo = $attempt->getTransactionReference();

        // Webhook tries to send 120.00 USDT (attempt expects 0.94 USDT)
        $payload = [
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => [
                'merchantTradeNo' => $tradeNo,
                'totalFee'        => '120.00',
                'currency'        => 'USDT',
                'status'          => 'PAID',
            ],
        ];
        $body = json_encode($payload);
        $timestamp = (string)(time() * 1000);
        $nonce = 'nonce_12345678901234567890123456789012';
        $signature = hash_hmac('sha512', $timestamp . "\n" . $nonce . "\n" . $body . "\n", 'test_api_secret');

        $headers = [
            'BinancePay-Timestamp'      => $timestamp,
            'BinancePay-Nonce'          => $nonce,
            'BinancePay-Certificate-SN' => 'test_cert_sn',
            'BinancePay-Signature'      => $signature,
        ];

        $result = $this->webhookService->handle('binance_pay', $headers, $body);
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('mismatch', strtolower($result->getMessage()));

        // Attempt remains PENDING
        $updatedAttempt = $this->paymentService->getAttempt($attempt->getId());
        $this->assertSame(PaymentStatus::PENDING, $updatedAttempt->getStatus());

        // Wallet is NOT credited
        $this->assertSame(0, $this->walletService->getBalance(43)->getAmount());
    }

    /**
     * TEST 10: Missing or expired FX rate -> Binance payment creation fails closed.
     */
    public function testMissingOrExpiredFxRateFailsClosed(): void
    {
        // Clean currency service without rates
        $cleanService = new CurrencyService(new DatabaseRateProvider($this->db), $this->db);
        $binance = new BinancePayGateway([
            'enabled'        => true,
            'certificate_sn' => 'test_cert_sn',
            'api_secret'     => 'test_api_secret',
        ], null, $this->db, $cleanService);

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_no_rate',
            Money::bdt(12000)
        );

        $this->expectException(UnauthoritativeRateException::class);
        $binance->createAttempt($intent);
    }

    /**
     * TEST 11: Live FX provider failure -> no fake fallback.
     */
    public function testLiveFxProviderFailureFailsClosedWithoutFakeFallback(): void
    {
        $failingTransport = function () {
            return ['statusCode' => 500, 'body' => 'Service Unavailable'];
        };

        $liveProvider = new LiveExchangeRateProvider($this->db, new DatabaseRateProvider($this->db), [
            'fallback_database' => true,
        ], $failingTransport);

        $currencyService = new CurrencyService($liveProvider, $this->db);
        $this->assertFalse($currencyService->hasRate('BDT', 'USDT'));

        $this->expectException(UnauthoritativeRateException::class);
        $currencyService->getRate('BDT', 'USDT');
    }

    /**
     * TEST 12: No production hardcoded exchange rates exist in codebase.
     */
    public function testNoProductionHardcodedExchangeRatesExist(): void
    {
        $filesToAudit = [
            __DIR__ . '/../../../../plugins/favorite-pay/src/Services/CurrencyService.php',
            __DIR__ . '/../../../../plugins/favorite-pay/src/Providers/LiveExchangeRateProvider.php',
            __DIR__ . '/../../../../plugins/favorite-pay/src/Providers/DatabaseRateProvider.php',
            __DIR__ . '/../../../../plugins/favorite-pay/src/Gateways/Binance/BinancePayGateway.php',
            __DIR__ . '/../../../../plugins/favorite-pay/src/Domain/ConversionSnapshot.php',
            __DIR__ . '/../../../../plugins/favorite-pay/src/Domain/Money.php',
        ];

        foreach ($filesToAudit as $filePath) {
            $this->assertFileExists($filePath);
            $content = file_get_contents($filePath);

            // Assert no hardcoded market rate numbers
            $this->assertDoesNotMatchRegularExpression('/\b127(\.0+)?\b/', $content, "Found hardcoded 127 rate in {$filePath}");
            $this->assertDoesNotMatchRegularExpression('/\b122\.8\b/', $content, "Found hardcoded 122.8 rate in {$filePath}");
            $this->assertDoesNotMatchRegularExpression('/\b0\.94\b/', $content, "Found hardcoded 0.94 in {$filePath}");
        }
    }

    /**
     * Magnitude and Decimal Placement Safety Test.
     */
    public function testMagnitudeAndDecimalPlacementSafety(): void
    {
        // 1 USDT = 127 BDT
        $snapshot = ConversionSnapshot::create('USDT', 'BDT', '127.00', true);
        $invSnapshot = new ConversionSnapshot('BDT', 'USDT', 7874, 1000000);

        $sourceMoney = Money::bdt(12000); // 120.00 BDT
        $converted = $invSnapshot->convert($sourceMoney);

        // Magnitude check: must be 94 minor units (0.94 USDT)
        $this->assertSame(94, $converted->getAmount());
        $this->assertSame('USDT', $converted->getCurrency());
        $this->assertSame('0.94', DecimalFormatter::minorUnitToDecimal($converted->getAmount(), 2));

        // It must NOT be 9400 (94.00 USDT) or 12000 (120.00 USDT)
        $this->assertLessThan(100, $converted->getAmount());
        $this->assertGreaterThan(90, $converted->getAmount());
    }

    /**
     * Checkout Calculation Display Test.
     */
    public function testCheckoutCalculationProvidesExplicitSeparation(): void
    {
        $this->currencyService->setOperatorRate('USDT', '127.00', 1, 'BDT');

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_checkout_display',
            Money::bdt(12000)
        );

        // Manual bKash calculation
        $bkashCalc = $this->paymentService->getCheckoutCalculation($intent, 'bkash_personal');
        $this->assertFalse($bkashCalc['has_conversion']);
        $this->assertSame(12000, $bkashCalc['original_order_amount']);
        $this->assertSame('BDT', $bkashCalc['original_order_currency']);
        $this->assertSame(12000, $bkashCalc['charge_amount']);
        $this->assertSame('BDT', $bkashCalc['charge_currency']);

        // Binance Pay calculation
        $binanceCalc = $this->paymentService->getCheckoutCalculation($intent, 'binance_pay');
        $this->assertTrue($binanceCalc['has_conversion']);
        $this->assertSame(12000, $binanceCalc['original_order_amount']);
        $this->assertSame('BDT', $binanceCalc['original_order_currency']);
        $this->assertSame(94, $binanceCalc['charge_amount']);
        $this->assertSame('USDT', $binanceCalc['charge_currency']);
        $this->assertStringContainsString('120.00 BDT', $binanceCalc['display_note']);
        $this->assertStringContainsString('0.94 USDT', $binanceCalc['display_note']);
    }

    /**
     * TEST 15: 120 BDT -> USD conversion charges current equivalent in USD, NOT 120 USD.
     */
    public function test120BdtToUsdChargesCurrentEquivalentUsd(): void
    {
        // Quote: 1 USD = 122.00 BDT
        $this->currencyService->setOperatorRate('USD', '122.00', 1, 'BDT');

        $bdtMoney = Money::bdt(12000); // 120.00 BDT
        $usdMoney = $this->currencyService->convert($bdtMoney, 'USD');

        $this->assertSame('USD', $usdMoney->getCurrency());
        // 120 / 122 = 0.9836... -> 98 cents (0.98 USD)
        $this->assertSame(98, $usdMoney->getAmount());
        $this->assertNotSame(12000, $usdMoney->getAmount(), 'Must never charge raw numeric 120.00 USD');
    }

    /**
     * TEST 16: 120 INR -> USD conversion charges current equivalent in USD, NOT 120 USD.
     */
    public function test120InrToUsdChargesCurrentEquivalentUsd(): void
    {
        // Quote: 1 USD = 86.50 INR
        $this->currencyService->setOperatorRate('USD', '86.50', 1, 'INR');

        $inrMoney = new Money(12000, 'INR'); // 120.00 INR
        $usdMoney = $this->currencyService->convert($inrMoney, 'USD');

        $this->assertSame('USD', $usdMoney->getCurrency());
        // 120 / 86.50 = 1.38728... -> 139 cents (1.39 USD)
        $this->assertSame(139, $usdMoney->getAmount());
        $this->assertNotSame(12000, $usdMoney->getAmount(), 'Must never charge raw numeric 120.00 USD for 120 INR order');
    }

    /**
     * TEST 17: 120 INR -> USDT conversion via triangulated cross-rate.
     */
    public function test120InrToUsdtViaTriangulationChargesCurrentEquivalentUsdt(): void
    {
        // 1 USD = 86.50 INR, and 1 USD = 1.0005 USDT
        $this->currencyService->setOperatorRate('USD', '86.50', 1, 'INR');
        $this->currencyService->setOperatorRate('USD', '1.0005', 1, 'USDT');

        $inrMoney = new Money(12000, 'INR'); // 120.00 INR
        $usdtMoney = $this->currencyService->convert($inrMoney, 'USDT');

        $this->assertSame('USDT', $usdtMoney->getCurrency());
        // 120 * (1.0005 / 86.50) = 1.38797... -> 139 minor units (1.39 USDT)
        $this->assertSame(139, $usdtMoney->getAmount());
        $this->assertNotSame(12000, $usdtMoney->getAmount(), 'Must never charge raw numeric 120.00 USDT for 120 INR order');
    }

    /**
     * TEST 18: Webhook with wrong acquiring amount is rejected.
     */
    public function testWebhookWithWrongAcquiringAmountRejected(): void
    {
        $this->currencyService->setOperatorRate('USDT', '127.00', 1, 'BDT');

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_wh_wrong_amount',
            Money::bdt(12000),
            ['customer_id' => 44]
        );

        $attempt = $this->binanceGateway->createAttempt($intent);
        $this->paymentService->recordAttempt($attempt);
        $tradeNo = $attempt->getTransactionReference();

        // Webhook sends 0.90 USDT instead of 0.94 USDT
        $payload = [
            'bizStatus' => 'PAY_SUCCESS',
            'data'      => [
                'merchantTradeNo' => $tradeNo,
                'totalFee'        => '0.90',
                'currency'        => 'USDT',
                'status'          => 'PAID',
            ],
        ];
        $body = json_encode($payload);
        $timestamp = (string)(time() * 1000);
        $nonce = 'nonce_12345678901234567890123456789012';
        $signature = hash_hmac('sha512', $timestamp . "\n" . $nonce . "\n" . $body . "\n", 'test_api_secret');

        $headers = [
            'BinancePay-Timestamp'      => $timestamp,
            'BinancePay-Nonce'          => $nonce,
            'BinancePay-Certificate-SN' => 'test_cert_sn',
            'BinancePay-Signature'      => $signature,
        ];

        $result = $this->webhookService->handle('binance_pay', $headers, $body);
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('mismatch', strtolower($result->getMessage()));
    }

    /**
     * TEST 19: Stale / expired live rate fails closed.
     */
    public function testStaleLiveRateFailsClosed(): void
    {
        $expiredSnapshot = new ConversionSnapshot(
            'BDT',
            'USDT',
            7874,
            1000000,
            true,
            date('Y-m-d H:i:s', time() - 7200),
            date('Y-m-d H:i:s', time() - 3600), // expired 1 hour ago
            'live_fx'
        );

        $this->assertTrue($expiredSnapshot->isExpired());
        $this->assertFalse($expiredSnapshot->isValidForPayment());
    }

    /**
     * TEST 20: Live provider outage does NOT silently fall back to operator rates.
     */
    public function testLiveProviderOutageDoesNotSilentlyUseManualOperatorRate(): void
    {
        // 1. Operator rate exists in database
        $this->db->insert('favorite_pay_rates', [
            'base_currency'    => 'USDT',
            'quote_currency'   => 'BDT',
            'rate_factor'      => 127000000,
            'rate_scale'       => 1000000,
            'is_authoritative' => 1,
            'status'           => 'active',
            'source'           => 'operator',
            'effective_at'     => date('Y-m-d H:i:s', time() - 3600),
            'expires_at'       => null,
            'created_at'       => date('Y-m-d H:i:s', time() - 3600),
        ]);

        // 2. Failing live transport
        $failingTransport = function () {
            return ['statusCode' => 503, 'body' => 'Service Unavailable'];
        };

        // 3. Live provider configured with fallback_database = false (default fail-closed)
        $liveProvider = new LiveExchangeRateProvider($this->db, new DatabaseRateProvider($this->db), [
            'fallback_database' => false,
        ], $failingTransport);

        $rate = $liveProvider->getRate('BDT', 'USDT');
        $this->assertNull($rate, 'Live provider must fail closed (return null) rather than silently using manual operator rate');
    }

    /**
     * TEST 21: Rounding boundaries and undercharge protection.
     */
    public function testRoundingBoundariesAndUnderchargeProtection(): void
    {
        $snapshot = new ConversionSnapshot('BDT', 'USDT', 7874, 1000000); // 1 USDT = 127 BDT

        // A. Very small non-zero amount: 1 Poisha (0.01 BDT = 1 minor unit)
        // 1 * 7874 / 1,000,000 = 0.007874 minor units.
        // Undercharge safeguard must ensure positive orders charge at least 1 minor unit (0.01 USDT).
        $tinyBdt = Money::bdt(1);
        $tinyUsdt = $snapshot->convert($tinyBdt);
        $this->assertSame(1, $tinyUsdt->getAmount(), 'Tiny positive amount must not round down to 0 minor units');

        // B. Rounding boundary: 120.64 BDT (12064 minor units)
        // 12064 * 7874 = 94,991,936. Half-up (+500,000) = 95,491,936 -> 95 minor units (0.95 USDT)
        $boundaryBdt = Money::bdt(12064);
        $boundaryUsdt = $snapshot->convert($boundaryBdt);
        $this->assertSame(95, $boundaryUsdt->getAmount());

        // C. Very large amount: 10,000,000 BDT (1,000,000,000 minor units)
        // 1,000,000,000 * 7874 / 1,000,000 = 7,874,000 minor units = 78,740.00 USDT
        $largeBdt = Money::bdt(1000000000);
        $largeUsdt = $snapshot->convert($largeBdt);
        $this->assertSame(7874000, $largeUsdt->getAmount());
        $this->assertSame('78740.00', $largeUsdt->toMajorUnit());
    }

    /**
     * TEST 22: Refund retains strict separation of original order currency and acquiring currency.
     */
    public function testRefundPreservesOriginalAndAcquiringCurrencySeparation(): void
    {
        $refundService = new \FavoriteCMS\Pay\Services\RefundService(
            $this->paymentService,
            $this->registry,
            $this->db
        );

        $this->currencyService->setOperatorRate('USDT', '127.00', 1, 'BDT');

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_refund_test',
            Money::bdt(12000),
            ['customer_id' => 45]
        );

        $attempt = $this->binanceGateway->createAttempt($intent);
        $this->paymentService->recordAttempt($attempt);
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        $refund = $refundService->createRefund(
            $intent->getId(),
            $attempt->getAmount(),
            'Customer return',
            ['operator_id' => 1]
        );

        $this->assertSame('BDT', $refund['original_order_currency']);
        $this->assertSame(12000, $refund['original_order_amount']);
        $this->assertSame('USDT', $refund['charge_currency']);
        $this->assertSame(94, $refund['charge_amount']);
    }
}
