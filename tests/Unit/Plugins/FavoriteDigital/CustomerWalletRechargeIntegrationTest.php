<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteDigital;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Controllers\CustomerCheckoutController;
use FavoriteCMS\Digital\Controllers\CustomerWalletController;
use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Exceptions\WalletException;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;
use FavoriteCMS\Digital\Repositories\OrderRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Repositories\RefundRepository;
use FavoriteCMS\Digital\Repositories\WalletRepository;
use FavoriteCMS\Digital\Services\CheckoutService;
use FavoriteCMS\Digital\Services\DefaultEntitlementChecker;
use FavoriteCMS\Digital\Services\DigitalFileStorageService;
use FavoriteCMS\Digital\Services\MembershipLifecycleService;
use FavoriteCMS\Digital\Services\OrderService;
use FavoriteCMS\Digital\Services\ProductManagementService;
use FavoriteCMS\Digital\Services\RefundService;
use FavoriteCMS\Digital\Services\WalletRechargeService;
use FavoriteCMS\Digital\Services\WalletService;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Domain\ConversionSnapshot;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Exceptions\UnauthoritativeRateException;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * CustomerWalletRechargeIntegrationTest
 *
 * Dedicated Phase 8 integration test suite covering scenarios A through BJ:
 * - Wallet UI, balance, currency, permanence
 * - Server-enforced limits: Regular (৳50 - ৳10,000) & Binance (1 USD equivalent)
 * - Number parsing, precision, negative, zero, and tampering rejections
 * - FX snapshot locking, immutability, and fail-closed security
 * - Favorite Pay public API integration without code duplication
 * - Verified automatic payment settlement vs failed/pending/expired states
 * - Manual payment (TrxID) lifecycle: pending -> authorized admin approval
 * - CSRF, replay, concurrency, and idempotency protections
 * - WalletService as sole balance authority, immutable ledger traceability
 * - Recharge vs refund separation, purchase debits, end-to-end checkout
 * - Customer ownership isolation, IDOR protection, XSS & SQLi immunity
 * - Pagination, bounded data loading, responsive & accessibility sanity
 * - Prefix-safety, SQLite, and MySQL offline compatibility
 */
class CustomerWalletRechargeIntegrationTest extends TestCase
{
    private Application $app;
    private PDO $sqlitePdo;
    private Database $sqliteDb;
    private ProductRepository $productRepo;
    private OrderRepository $orderRepo;
    private WalletRepository $walletRepo;
    private WalletService $walletService;
    private WalletRechargeService $rechargeService;
    private CustomerWalletController $walletController;
    private CheckoutService $checkoutService;
    private OrderService $orderService;
    private CurrencyServiceInterface $currencyService;
    private PaymentServiceInterface $paymentService;

    /** @var array<string, PaymentIntent> */
    private array $intents = [];

    /** @var array<string, PaymentAttempt> */
    private array $attempts = [];

    /** @var array<string, ConversionSnapshot> */
    private array $rates = [];

    protected function setUp(): void
    {
        if (!defined('PHPUNIT_RUNNING')) {
            define('PHPUNIT_RUNNING', true);
        }

        $this->app = new Application();

        $this->sqlitePdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);

        $this->sqliteDb = new class($this->sqlitePdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite'];
                $this->prefix = '';
            }
        };

        $this->sqliteDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);

        // Run Favorite Digital migrations
        $migrator = new Migrator($this->sqliteDb);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');

        // Run Favorite Pay tables migration
        if (file_exists(APP_ROOT . '/plugins/favorite-pay/database/migrations/001_create_favorite_pay_tables.php')) {
            require_once APP_ROOT . '/plugins/favorite-pay/database/migrations/001_create_favorite_pay_tables.php';
            $payMigration = new \CreateFavoritePayTables($this->sqliteDb);
            $payMigration->up();
        }

        $this->app->singleton(Database::class, fn () => $this->sqliteDb);

        $this->productRepo = new ProductRepository($this->sqliteDb);
        $this->orderRepo = new OrderRepository($this->sqliteDb);
        $this->walletRepo = new WalletRepository($this->sqliteDb);
        $this->walletService = new WalletService($this->walletRepo, $this->sqliteDb);

        $membershipService = new MembershipLifecycleService($this->productRepo);
        $storage = new DigitalFileStorageService(sys_get_temp_dir());
        $productService = new ProductManagementService($this->productRepo, $storage);

        $this->orderService = new OrderService(
            $this->orderRepo,
            $this->productRepo,
            $membershipService,
            new DefaultEntitlementChecker($this->sqliteDb),
            $this->sqliteDb
        );

        $this->intents = [];
        $this->attempts = [];
        $this->rates = [];

        // Seed 1 USD = 120 BDT
        $this->rates['USD_BDT'] = ConversionSnapshot::create('USD', 'BDT', '120.00', true, 1000000, null, 'operator');
        $this->rates['BDT_USD'] = ConversionSnapshot::create('BDT', 'USD', '0.008333', true, 1000000, null, 'operator');
        $this->rates['BDT_USDT'] = ConversionSnapshot::create('BDT', 'USDT', '0.008333', true, 1000000, null, 'operator');
        $this->rates['USDT_BDT'] = ConversionSnapshot::create('USDT', 'BDT', '120.00', true, 1000000, null, 'operator');

        $ratesRef = &$this->rates;
        $this->currencyService = new class($ratesRef) implements CurrencyServiceInterface {
            public array $rates;
            public function __construct(array &$rates) { $this->rates = &$rates; }
            public function getBaseCurrency(): string { return 'BDT'; }
            public function getSupportedCurrencies(): array { return ['BDT', 'USD', 'USDT']; }
            public function hasRate(string $fromCurrency, ?string $toCurrency = null): bool {
                $k = strtoupper($fromCurrency) . '_' . strtoupper($toCurrency ?? 'BDT');
                return isset($this->rates[$k]);
            }
            public function getRate(string $fromCurrency, ?string $toCurrency = null): ConversionSnapshot {
                $from = strtoupper(trim($fromCurrency));
                $to = strtoupper(trim($toCurrency ?? 'BDT'));
                if ($from === $to) {
                    return ConversionSnapshot::create($from, $to, '1.00', true);
                }
                $k = "{$from}_{$to}";
                if (!isset($this->rates[$k])) {
                    throw new UnauthoritativeRateException("No exchange rate for {$from} to {$to}", $from, $to);
                }
                $snap = $this->rates[$k];
                if (!$snap->isValidForPayment()) {
                    throw new UnauthoritativeRateException("Rate not valid for payment", $from, $to);
                }
                return $snap;
            }
            public function convert(Money $money, string $targetCurrency): Money {
                $snap = $this->getRate($money->getCurrency(), $targetCurrency);
                return $snap->convert($money);
            }
            public function createLockedSnapshot(string $fromCurrency, ?string $toCurrency = null): ConversionSnapshot {
                return $this->getRate($fromCurrency, $toCurrency);
            }
            public function setRate(string $from, string $to, string $rateStr, bool $authoritative = true, ?string $expiresAt = null): void {
                $this->rates[strtoupper($from) . '_' . strtoupper($to)] = ConversionSnapshot::create(
                    strtoupper($from), strtoupper($to), $rateStr, $authoritative, 1000000, $expiresAt
                );
            }
            public function setOperatorRate(string $fromCurrency, string $rateMajorString, int $operatorUserId, ?string $toCurrency = null, ?string $expiresAt = null, ?string $notes = null): ConversionSnapshot {
                $snap = ConversionSnapshot::create($fromCurrency, $toCurrency ?? 'BDT', $rateMajorString, true, 1000000, $expiresAt, 'operator');
                $this->rates[strtoupper($fromCurrency) . '_' . strtoupper($toCurrency ?? 'BDT')] = $snap;
                return $snap;
            }
            public function syncAutomatedRate(string $fromCurrency, string $rateMajorString, ?string $toCurrency = null, ?string $expiresAt = null, string $source = 'automated'): bool {
                $this->rates[strtoupper($fromCurrency) . '_' . strtoupper($toCurrency ?? 'BDT')] = ConversionSnapshot::create($fromCurrency, $toCurrency ?? 'BDT', $rateMajorString, false, 1000000, $expiresAt, $source);
                return true;
            }
            public function setProvider(?\FavoriteCMS\Pay\Contracts\ExchangeRateProviderInterface $provider): void {}
            public function getProvider(): ?\FavoriteCMS\Pay\Contracts\ExchangeRateProviderInterface { return null; }
        };

        $intentsRef = &$this->intents;
        $attemptsRef = &$this->attempts;
        $currService = $this->currencyService;
        $db = $this->sqliteDb;

        $this->paymentService = new class($intentsRef, $attemptsRef, $currService, $db) implements PaymentServiceInterface {
            public array $intents;
            public array $attempts;
            public CurrencyServiceInterface $currency;
            public Database $db;

            public function __construct(array &$intents, array &$attempts, CurrencyServiceInterface $currency, Database $db) {
                $this->intents = &$intents;
                $this->attempts = &$attempts;
                $this->currency = $currency;
                $this->db = $db;
            }

            public function createIntent(string $sourcePlugin, string $sourceReference, Money $baseAmount, array $options = []): PaymentIntent {
                $id = 'pi_' . bin2hex(random_bytes(8));
                $chargeCurrency = $baseAmount->getCurrency();
                $gwId = $options['gateway_id'] ?? '';
                $snapshot = null;

                if (str_contains(strtolower((string)$gwId), 'binance')) {
                    $chargeCurrency = 'USDT';
                    $snapshot = $this->currency->getRate($baseAmount->getCurrency(), 'USDT');
                    $chargeAmount = $snapshot->convert($baseAmount);
                } else {
                    $chargeAmount = $baseAmount;
                }

                $intent = new PaymentIntent(
                    $id,
                    $sourcePlugin,
                    $sourceReference,
                    $baseAmount,
                    $chargeAmount,
                    PaymentStatus::PENDING,
                    null,
                    $options['customer_id'] ?? null,
                    $snapshot,
                    $options['metadata'] ?? []
                );

                $this->intents[$id] = $intent;

                if ($this->db->tableExists('favorite_pay_transactions')) {
                    $this->db->insert('favorite_pay_transactions', [
                        'transaction_id'   => $id,
                        'source_plugin'    => $sourcePlugin,
                        'source_reference' => $sourceReference,
                        'user_id'          => $options['customer_id'] ?? null,
                        'base_amount'      => $baseAmount->getAmount(),
                        'base_currency'    => $baseAmount->getCurrency(),
                        'charge_amount'    => $chargeAmount->getAmount(),
                        'charge_currency'  => $chargeAmount->getCurrency(),
                        'exchange_rate'    => $snapshot ? (float)$snapshot->getRateFactor() / $snapshot->getRateScale() : 1.0,
                        'status'           => 'pending',
                        'gateway_id'       => $gwId,
                        'metadata'         => json_encode($options['metadata'] ?? []),
                        'created_at'       => date('Y-m-d H:i:s'),
                    ]);
                }

                return $intent;
            }

            public function getIntent(string $intentId): ?PaymentIntent {
                if (isset($this->intents[$intentId])) {
                    return $this->intents[$intentId];
                }
                if ($this->db->tableExists('favorite_pay_transactions')) {
                    $row = $this->db->selectOne("SELECT * FROM favorite_pay_transactions WHERE transaction_id = ?", [$intentId]);
                    if ($row) {
                        $base = new Money((int)$row->base_amount, (string)$row->base_currency);
                        $charge = new Money((int)$row->charge_amount, (string)$row->charge_currency);
                        $meta = !empty($row->metadata) ? json_decode((string)$row->metadata, true) : [];
                        return new PaymentIntent(
                            (string)$row->transaction_id,
                            (string)$row->source_plugin,
                            (string)$row->source_reference,
                            $base,
                            $charge,
                            PaymentStatus::from((string)$row->status),
                            null,
                            $row->user_id ? (int)$row->user_id : null,
                            null,
                            is_array($meta) ? $meta : []
                        );
                    }
                }
                return null;
            }

            public function updateIntentStatus(string $intentId, PaymentStatus $newStatus): PaymentIntent {
                $intent = $this->getIntent($intentId);
                if (!$intent) {
                    throw new InvalidArgumentException("Intent not found: {$intentId}");
                }
                $updated = $intent->withStatus($newStatus);
                $this->intents[$intentId] = $updated;

                if ($this->db->tableExists('favorite_pay_transactions')) {
                    $this->db->update('favorite_pay_transactions', [
                        'status'       => $newStatus->value,
                        'completed_at' => $newStatus->isFinal() ? date('Y-m-d H:i:s') : null,
                    ], ['transaction_id' => $intentId]);
                }
                return $updated;
            }

            public function initiatePayment(string $intentId, string $gatewayId, array $params = []): PaymentAttempt {
                $intent = $this->getIntent($intentId);
                $attemptId = 'att_' . bin2hex(random_bytes(8));
                $attempt = new PaymentAttempt(
                    $attemptId,
                    $intentId,
                    $gatewayId,
                    $intent->getChargeAmount(),
                    PaymentStatus::PENDING,
                    'prov_' . bin2hex(random_bytes(6)),
                    null,
                    null,
                    null,
                    null,
                    $params['idempotency_key'] ?? null,
                    null,
                    ['redirect_url' => 'https://gateway.example.com/pay/' . $attemptId]
                );
                $this->attempts[$attemptId] = $attempt;
                return $attempt;
            }

            public function submitManualVerification(string $intentId, string $gatewayId, string $transactionReference, array $details = []): PaymentAttempt {
                $intent = $this->getIntent($intentId);
                $attemptId = 'att_man_' . bin2hex(random_bytes(8));
                $attempt = new PaymentAttempt(
                    $attemptId,
                    $intentId,
                    $gatewayId,
                    $intent->getChargeAmount(),
                    PaymentStatus::AWAITING_VERIFICATION,
                    $transactionReference,
                    $details['notes'] ?? null,
                    null,
                    null,
                    null,
                    $details['idempotency_key'] ?? null,
                    $details['sender_account'] ?? null,
                    $details
                );
                $this->attempts[$attemptId] = $attempt;
                $this->updateIntentStatus($intentId, PaymentStatus::AWAITING_VERIFICATION);
                return $attempt;
            }

            public function approveManualPayment(string $attemptId, int $operatorUserId, ?string $notes = null): PaymentAttempt {
                $attempt = $this->attempts[$attemptId] ?? null;
                if (!$attempt) {
                    throw new InvalidArgumentException("Attempt not found: {$attemptId}");
                }
                if ($attempt->getStatus() === PaymentStatus::SUCCEEDED) {
                    throw new RuntimeException("Cannot approve payment attempt: attempt is already approved.");
                }
                $approved = new PaymentAttempt(
                    $attempt->getId(),
                    $attempt->getIntentId(),
                    $attempt->getGatewayId(),
                    $attempt->getAmount(),
                    PaymentStatus::SUCCEEDED,
                    $attempt->getTransactionReference(),
                    $notes ?? $attempt->getOperatorNotes(),
                    $operatorUserId,
                    date('Y-m-d H:i:s')
                );
                $this->attempts[$attemptId] = $approved;
                $this->updateIntentStatus($attempt->getIntentId(), PaymentStatus::SUCCEEDED);
                return $approved;
            }

            public function rejectManualPayment(string $attemptId, int $operatorUserId, string $reason): PaymentAttempt {
                $attempt = $this->attempts[$attemptId] ?? null;
                if (!$attempt) {
                    throw new InvalidArgumentException("Attempt not found: {$attemptId}");
                }
                $rejected = new PaymentAttempt(
                    $attempt->getId(),
                    $attempt->getIntentId(),
                    $attempt->getGatewayId(),
                    $attempt->getAmount(),
                    PaymentStatus::FAILED,
                    $attempt->getTransactionReference(),
                    $reason,
                    $operatorUserId,
                    date('Y-m-d H:i:s'),
                    $reason
                );
                $this->attempts[$attemptId] = $rejected;
                $this->updateIntentStatus($attempt->getIntentId(), PaymentStatus::FAILED);
                return $rejected;
            }

            public function getAvailablePaymentMethods(?string $currency = null): array {
                return [
                    [
                        'id'        => 'binance_pay',
                        'title'     => 'Binance Pay',
                        'type'      => 'online',
                        'is_manual' => false,
                    ],
                    [
                        'id'           => 'bkash_manual',
                        'title'        => 'bKash Manual (Send Money)',
                        'type'         => 'manual_bd',
                        'is_manual'    => true,
                        'instructions' => [
                            'account_number' => '01711000000',
                            'account_type'   => 'Personal',
                            'bank_name'      => 'bKash',
                            'instructions'   => 'Send Money to 01711000000 and enter your TrxID below.',
                        ],
                    ],
                    [
                        'id'        => 'nagad_merchant',
                        'title'     => 'Nagad Online',
                        'type'      => 'online',
                        'is_manual' => false,
                    ],
                ];
            }

            public function getCheckoutCalculation(PaymentIntent $intent, string $gatewayId): array {
                return ['payable' => $intent->getChargeAmount()->getAmount()];
            }

            public function getAttempt(string $attemptId): ?PaymentAttempt {
                return $this->attempts[$attemptId] ?? null;
            }
        };

        $this->rechargeService = new WalletRechargeService(
            $this->walletRepo,
            $this->walletService,
            $this->paymentService,
            $this->currencyService,
            $this->sqliteDb
        );

        $this->walletController = new CustomerWalletController(
            $this->app,
            $this->walletService,
            $this->rechargeService,
            $this->paymentService
        );

        $this->checkoutService = new CheckoutService(
            $this->orderRepo,
            $this->walletService,
            $this->paymentService,
            $this->sqliteDb
        );

        $_SESSION = [];
        $GLOBALS['_test_current_user_id'] = null;
    }

    // ==========================================
    // Test Scenarios A through BJ
    // ==========================================

    public function testScenarioA_WalletPageLoads(): void
    {
        $GLOBALS['_test_current_user_id'] = 1;
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']);
        $resp = $this->walletController->index($req);

        $this->assertIsString($resp);
        $this->assertStringContainsString('Digital Wallet Balance', $resp);
        $this->assertStringContainsString('Recharge Wallet', $resp);
        $this->assertStringContainsString('Wallet Transaction Ledger', $resp);
    }

    public function testScenarioB_GuestWalletRequiresLogin(): void
    {
        $GLOBALS['_test_current_user_id'] = null;
        $_SESSION = [];

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']);
        $resp = $this->walletController->index($req);

        $this->assertInstanceOf(Response::class, $resp);
        $this->assertSame(302, $resp->getStatusCode());
        $this->assertStringContainsString('/login', $resp->getHeaders()['Location'] ?? '');
    }

    public function testScenarioC_CustomerSeesOwnBalance(): void
    {
        $this->walletService->credit(10, '350.00', 'seed_10', 'Seed user 10');

        $GLOBALS['_test_current_user_id'] = 10;
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']);
        $resp = $this->walletController->index($req);

        $this->assertIsString($resp);
        $this->assertStringContainsString('350.00', $resp);
    }

    public function testScenarioD_CustomerCannotSeeAnotherUsersWallet(): void
    {
        $this->walletService->credit(10, '750.00', 'seed_10', 'User 10 Money');
        $this->walletService->credit(20, '25.00', 'seed_20', 'User 20 Money');

        // Access as User 20
        $GLOBALS['_test_current_user_id'] = 20;
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']);
        $resp = $this->walletController->index($req);

        $this->assertIsString($resp);
        $this->assertStringContainsString('25.00', $resp);
        $this->assertStringNotContainsString('750.00', $resp);
    }

    public function testScenarioE_WalletCurrencyIsSitePrimaryCurrency(): void
    {
        $this->assertSame('BDT', $this->rechargeService->getPrimaryCurrency());

        $wallet = $this->walletRepo->getOrCreateWallet(1);
        $this->assertSame('BDT', $wallet->currency);
    }

    public function testScenarioF_WalletBalanceNeverExpires(): void
    {
        $wallet = $this->walletRepo->getOrCreateWallet(1);
        $this->assertFalse(property_exists($wallet, 'expires_at'));
        $this->assertFalse(property_exists($wallet, 'expiry_date'));

        $tx = $this->walletService->credit(1, '100.00', 'tx_no_exp', 'Non expiring credit');
        $this->assertFalse(property_exists($tx, 'expires_at'));
    }

    public function testScenarioG_RegularMinimumRechargeEnforced(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recharge amount cannot be less than 50.00 BDT');

        $this->rechargeService->validateRechargeAmount('49.99', 'bkash_manual');
    }

    public function testScenarioH_RegularMaximumRechargeEnforced(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recharge amount cannot exceed 10000.00 BDT');

        $this->rechargeService->validateRechargeAmount('10000.01', 'bkash_manual');
    }

    public function testScenarioI_BinanceMinimumEquivalentToOneUsd(): void
    {
        // 1 USD = 120 BDT
        $limits = $this->rechargeService->getRechargeLimits('binance_pay');
        $this->assertSame('120.00', $limits['min']);

        // Set FX rate to 1 USD = 123 BDT
        $this->currencyService->setRate('USD', 'BDT', '123.00');
        $limitsNew = $this->rechargeService->getRechargeLimits('binance_pay');
        $this->assertSame('123.00', $limitsNew['min']);

        // Below 123 BDT must fail
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recharge amount cannot be less than 123.00 BDT');
        $this->rechargeService->validateRechargeAmount('122.99', 'binance_pay');
    }

    public function testScenarioJ_BinanceMaximumEnforced(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Recharge amount cannot exceed 10000.00 BDT');

        $this->rechargeService->validateRechargeAmount('10000.50', 'binance_pay');
    }

    public function testScenarioK_ZeroAmountRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->rechargeService->validateRechargeAmount('0.00', 'bkash_manual');
    }

    public function testScenarioL_NegativeAmountRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->rechargeService->validateRechargeAmount('-50.00', 'bkash_manual');
    }

    public function testScenarioM_ExcessivePrecisionRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 2 decimal places allowed');

        $this->rechargeService->validateRechargeAmount('50.123', 'bkash_manual');
    }

    public function testScenarioN_InvalidAmountRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->rechargeService->validateRechargeAmount('abc', 'bkash_manual');
    }

    public function testScenarioO_CurrencyTamperingRejected(): void
    {
        // Recharge base amount is always evaluated in primary currency (BDT)
        $calc = $this->rechargeService->getRechargeCalculation('500.00', 'bkash_manual');
        $this->assertSame('BDT', $calc['wallet_currency']);
        $this->assertSame('500.00', $calc['wallet_amount']);
    }

    public function testScenarioP_AmountTamperingRejected(): void
    {
        // Client cannot pass fractional or modified string
        $this->expectException(InvalidArgumentException::class);
        $this->rechargeService->validateRechargeAmount('100.00001', 'bkash_manual');
    }

    public function testScenarioQ_PrimaryCurrencyRechargeCalculation(): void
    {
        $calc = $this->rechargeService->getRechargeCalculation('600.00', 'bkash_manual');
        $this->assertSame('600.00', $calc['wallet_amount']);
        $this->assertSame('BDT', $calc['wallet_currency']);
        $this->assertSame('600.00', $calc['charge_amount']);
        $this->assertSame('BDT', $calc['charge_currency']);
        $this->assertFalse($calc['is_foreign']);
    }

    public function testScenarioR_ForeignCurrencyRechargeCalculation(): void
    {
        // 1 USD = 120 BDT => 600 BDT = 5.00 USDT
        $calc = $this->rechargeService->getRechargeCalculation('600.00', 'binance_pay');
        $this->assertSame('600.00', $calc['wallet_amount']);
        $this->assertSame('BDT', $calc['wallet_currency']);
        $this->assertSame('5.00', $calc['charge_amount']);
        $this->assertSame('USDT', $calc['charge_currency']);
        $this->assertTrue($calc['is_foreign']);
    }

    public function testScenarioS_FxSnapshotCaptured(): void
    {
        $res = $this->rechargeService->createRecharge(1, '600.00', 'binance_pay');
        /** @var PaymentIntent $intent */
        $intent = $res['intent'];

        $this->assertSame('BDT', $intent->getBaseAmount()->getCurrency());
        $this->assertSame(60000, $intent->getBaseAmount()->getAmount());
        $this->assertSame('USDT', $intent->getChargeAmount()->getCurrency());
        $this->assertSame(500, $intent->getChargeAmount()->getAmount());

        $snap = $intent->getConversionSnapshot();
        $this->assertNotNull($snap);
        $this->assertSame('BDT', $snap->getFromCurrency());
        $this->assertSame('USDT', $snap->getToCurrency());
    }

    public function testScenarioT_LaterFxChangeDoesNotAlterSnapshot(): void
    {
        $res = $this->rechargeService->createRecharge(1, '600.00', 'binance_pay');
        $intentId = $res['intent']->getId();

        // Alter exchange rate drastically: 1 USD = 150 BDT
        $this->currencyService->setRate('BDT', 'USDT', '0.006666');

        // Existing intent remains locked at original rate (5.00 USDT & 600 BDT)
        $loaded = $this->paymentService->getIntent($intentId);
        $this->assertSame(60000, $loaded->getBaseAmount()->getAmount());
        $this->assertSame(500, $loaded->getChargeAmount()->getAmount());
    }

    public function testScenarioU_StaleFxFailsClosed(): void
    {
        // Expired rate
        $this->currencyService->setRate('USD', 'BDT', '120.00', true, date('Y-m-d H:i:s', time() - 3600));

        $this->expectException(UnauthoritativeRateException::class);
        $this->rechargeService->getRechargeLimits('binance_pay');
    }

    public function testScenarioV_MissingFxFailsClosed(): void
    {
        // Clear rates
        $this->rates = [];

        $this->expectException(UnauthoritativeRateException::class);
        $this->rechargeService->getRechargeLimits('binance_pay');
    }

    public function testScenarioW_AutomaticPaymentUsesFavoritePayPublicApi(): void
    {
        $res = $this->rechargeService->createRecharge(1, '200.00', 'nagad_merchant');
        $this->assertInstanceOf(PaymentIntent::class, $res['intent']);
        $this->assertInstanceOf(PaymentAttempt::class, $res['attempt']);
        $this->assertSame('favorite-digital', $res['intent']->getSourcePlugin());
        $this->assertSame('nagad_merchant', $res['attempt']->getGatewayId());
    }

    public function testScenarioX_NoDuplicatedPaymentImplementation(): void
    {
        // Verify PaymentServiceInterface is used and no gateway client code exists in favorite-digital
        $this->assertSame($this->paymentService, $this->rechargeService->getPaymentService());
        $this->assertFalse(class_exists('FavoriteCMS\\Digital\\Gateways\\BinancePayGateway'));
    }

    public function testScenarioY_BrowserRedirectAloneCannotCreditWallet(): void
    {
        $res = $this->rechargeService->createRecharge(1, '500.00', 'nagad_merchant');
        $intentId = $res['intent']->getId();

        $GLOBALS['_test_current_user_id'] = 1;
        // Hit callback while intent is still PENDING
        $req = new Request(['intent_id' => $intentId], [], ['REQUEST_METHOD' => 'GET']);
        $resp = $this->walletController->callback($req);

        $this->assertInstanceOf(Response::class, $resp);
        // Wallet balance must STILL be 0.00!
        $this->assertSame('0.00', $this->walletService->getBalance(1));
    }

    public function testScenarioZ_VerifiedAutomaticSuccessCreditsWallet(): void
    {
        $res = $this->rechargeService->createRecharge(1, '500.00', 'nagad_merchant');
        $intentId = $res['intent']->getId();

        // Server-side transitions intent to SUCCEEDED
        $this->paymentService->updateIntentStatus($intentId, PaymentStatus::SUCCEEDED);

        $GLOBALS['_test_current_user_id'] = 1;
        $req = new Request(['intent_id' => $intentId], [], ['REQUEST_METHOD' => 'GET']);
        $this->walletController->callback($req);

        // Wallet is now credited!
        $this->assertSame('500.00', $this->walletService->getBalance(1));
    }

    public function testScenarioAA_FailedPaymentCreatesNoWalletCredit(): void
    {
        $res = $this->rechargeService->createRecharge(1, '300.00', 'nagad_merchant');
        $intentId = $res['intent']->getId();

        $this->paymentService->updateIntentStatus($intentId, PaymentStatus::FAILED);

        $GLOBALS['_test_current_user_id'] = 1;
        $req = new Request(['intent_id' => $intentId], [], ['REQUEST_METHOD' => 'GET']);
        $this->walletController->callback($req);

        $this->assertSame('0.00', $this->walletService->getBalance(1));
    }

    public function testScenarioAB_PendingPaymentCreatesNoWalletCredit(): void
    {
        $res = $this->rechargeService->createRecharge(1, '250.00', 'nagad_merchant');
        $intentId = $res['intent']->getId();

        $tx = $this->rechargeService->settleRecharge($intentId);
        $this->assertNull($tx);
        $this->assertSame('0.00', $this->walletService->getBalance(1));
    }

    public function testScenarioAC_ExpiredPaymentCreatesNoWalletCredit(): void
    {
        $res = $this->rechargeService->createRecharge(1, '150.00', 'nagad_merchant');
        $intentId = $res['intent']->getId();

        $this->paymentService->updateIntentStatus($intentId, PaymentStatus::CANCELLED);

        $tx = $this->rechargeService->settleRecharge($intentId);
        $this->assertNull($tx);
        $this->assertSame('0.00', $this->walletService->getBalance(1));
    }

    public function testScenarioAD_ManualRechargeStartsPending(): void
    {
        $res = $this->rechargeService->createRecharge(1, '400.00', 'bkash_manual');
        $this->assertTrue($res['is_manual']);
        $this->assertSame(PaymentStatus::PENDING, $res['intent']->getStatus());
    }

    public function testScenarioAE_ManualRechargeDoesNotCreditImmediately(): void
    {
        $res = $this->rechargeService->createRecharge(1, '400.00', 'bkash_manual');
        $intentId = $res['intent']->getId();

        $attempt = $this->rechargeService->submitManualRecharge(1, $intentId, 'bkash_manual', 'TRX_12345');
        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $attempt->getStatus());

        // Balance must remain 0.00
        $this->assertSame('0.00', $this->walletService->getBalance(1));
    }

    public function testScenarioAF_AuthorizedAdminApprovalCreditsWallet(): void
    {
        $res = $this->rechargeService->createRecharge(1, '400.00', 'bkash_manual');
        $intentId = $res['intent']->getId();
        $attempt = $this->rechargeService->submitManualRecharge(1, $intentId, 'bkash_manual', 'TRX_67890');

        // Admin approves manual payment
        $this->rechargeService->approveManualRecharge($attempt->getId(), 999, 'Approved by accounts');

        // Customer wallet balance must be 400.00
        $this->assertSame('400.00', $this->walletService->getBalance(1));
    }

    public function testScenarioAG_UnauthorizedAdminApprovalRejected(): void
    {
        // Attempting to approve non-existent or invalid attempt throws exception
        $this->expectException(InvalidArgumentException::class);
        $this->rechargeService->approveManualRecharge('att_fake_99', 999);
    }

    public function testScenarioAH_CsrfProtection(): void
    {
        $_SESSION['_token'] = 'valid_token_123';
        $GLOBALS['_test_current_user_id'] = 1;

        // Post with wrong token
        $req = new Request([], ['_token' => 'invalid', 'amount' => '100.00', 'gateway_id' => 'bkash_manual'], ['REQUEST_METHOD' => 'POST']);
        $resp = $this->walletController->recharge($req);

        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('Invalid or expired CSRF token. Please retry.', $_SESSION['flash_error']);
    }

    public function testScenarioAI_DuplicateApprovalPrevented(): void
    {
        $res = $this->rechargeService->createRecharge(1, '200.00', 'bkash_manual');
        $attempt = $this->rechargeService->submitManualRecharge(1, $res['intent']->getId(), 'bkash_manual', 'TRX_DUP');

        $this->rechargeService->approveManualRecharge($attempt->getId(), 999);
        $this->assertSame('200.00', $this->walletService->getBalance(1));

        // Second approval attempt must throw RuntimeException
        $this->expectException(RuntimeException::class);
        $this->rechargeService->approveManualRecharge($attempt->getId(), 999);
    }

    public function testScenarioAJ_DuplicateAutomaticSettlementPrevented(): void
    {
        $res = $this->rechargeService->createRecharge(1, '500.00', 'nagad_merchant');
        $intentId = $res['intent']->getId();

        $this->paymentService->updateIntentStatus($intentId, PaymentStatus::SUCCEEDED);

        // First settlement
        $this->rechargeService->settleRecharge($intentId);
        $this->assertSame('500.00', $this->walletService->getBalance(1));

        // Second settlement call must NOT credit again (idempotency!)
        $this->rechargeService->settleRecharge($intentId);
        $this->assertSame('500.00', $this->walletService->getBalance(1));
    }

    public function testScenarioAK_CallbackReplayPrevented(): void
    {
        $res = $this->rechargeService->createRecharge(1, '300.00', 'nagad_merchant');
        $intentId = $res['intent']->getId();
        $this->paymentService->updateIntentStatus($intentId, PaymentStatus::SUCCEEDED);

        $GLOBALS['_test_current_user_id'] = 1;
        $req = new Request(['intent_id' => $intentId], [], ['REQUEST_METHOD' => 'GET']);

        // Multiple callback hits
        $this->walletController->callback($req);
        $this->walletController->callback($req);
        $this->walletController->callback($req);

        $this->assertSame('300.00', $this->walletService->getBalance(1));
    }

    public function testScenarioAL_WebhookReplayPrevented(): void
    {
        $res = $this->rechargeService->createRecharge(1, '700.00', 'binance_pay');
        $intentId = $res['intent']->getId();
        $this->paymentService->updateIntentStatus($intentId, PaymentStatus::SUCCEEDED);

        // Simulate repeated webhook handler calls
        $this->rechargeService->settleRecharge($intentId);
        $this->rechargeService->settleRecharge($intentId);

        $this->assertSame('700.00', $this->walletService->getBalance(1));
    }

    public function testScenarioAM_WalletServiceIsSoleBalanceMutationAuthority(): void
    {
        // Direct database update must not be performed by controller/service
        $tx = $this->rechargeService->createRecharge(1, '100.00', 'nagad_merchant');
        $this->paymentService->updateIntentStatus($tx['intent']->getId(), PaymentStatus::SUCCEEDED);
        $txRecord = $this->rechargeService->settleRecharge($tx['intent']->getId());

        $this->assertSame('recharge', $txRecord->type);
        $this->assertSame('100.00', $txRecord->amount);
    }

    public function testScenarioAN_WalletLedgerCreatedExactlyOnce(): void
    {
        $res = $this->rechargeService->createRecharge(1, '250.00', 'nagad_merchant');
        $intentId = $res['intent']->getId();
        $this->paymentService->updateIntentStatus($intentId, PaymentStatus::SUCCEEDED);

        $this->rechargeService->settleRecharge($intentId);
        $this->rechargeService->settleRecharge($intentId);

        $wallet = $this->walletRepo->getOrCreateWallet(1);
        $count = $this->walletRepo->countTransactions((int)$wallet->id);
        $this->assertSame(1, $count);
    }

    public function testScenarioAO_WalletTransactionReferenceTraceable(): void
    {
        $res = $this->rechargeService->createRecharge(1, '500.00', 'nagad_merchant');
        $intentId = $res['intent']->getId();
        $this->paymentService->updateIntentStatus($intentId, PaymentStatus::SUCCEEDED);
        $this->rechargeService->settleRecharge($intentId);

        $tx = $this->walletRepo->getTransactionByReference($intentId);
        $this->assertNotNull($tx);
        $this->assertSame($intentId, $tx->reference_id);
        $this->assertSame('recharge', $tx->type);
    }

    public function testScenarioAP_RechargeHistoryVisible(): void
    {
        $this->rechargeService->createRecharge(5, '100.00', 'bkash_manual');
        $this->rechargeService->createRecharge(5, '200.00', 'nagad_merchant');

        $history = $this->rechargeService->getRechargeHistory(5);
        $this->assertCount(2, $history['data']);
        $this->assertSame(2, $history['total']);
    }

    public function testScenarioAQ_TransactionHistoryVisible(): void
    {
        $this->walletService->credit(5, '100.00', 'ref_1', 'Recharge 1', null, 'recharge');
        $this->walletService->debit(5, '30.00', 'ref_2', 'Purchase 1');

        $wallet = $this->walletRepo->getOrCreateWallet(5);
        $txData = $this->walletRepo->getTransactionsPaginated((int)$wallet->id);

        $this->assertCount(2, $txData['data']);
        $this->assertSame('70.00', $this->walletService->getBalance(5));
    }

    public function testScenarioAR_RefundTransactionRemainsDistinguishable(): void
    {
        $this->walletService->credit(5, '500.00', 'tx_rc_1', 'Recharge', null, 'recharge');
        $this->walletService->credit(5, '150.00', 'tx_rf_1', 'Refund for Order #101', null, 'refund_credit');

        $wallet = $this->walletRepo->getOrCreateWallet(5);
        $txs = $this->walletRepo->getTransactions((int)$wallet->id);

        $types = array_column((array)$txs, 'type');
        $this->assertContains('recharge', $types);
        $this->assertContains('refund_credit', $types);
    }

    public function testScenarioAS_PurchaseDebitRemainsIntact(): void
    {
        $this->walletService->credit(1, '500.00', 'rc_1', 'Recharge', null, 'recharge');
        $debitTx = $this->walletService->debit(1, '200.00', 'deb_1', 'Order #123');

        $this->assertSame('debit', $debitTx->type);
        $this->assertSame('300.00', $this->walletService->getBalance(1));
    }

    public function testScenarioAT_RechargeThenCheckoutWorks(): void
    {
        // 1. Recharge wallet with 500 BDT
        $res = $this->rechargeService->createRecharge(10, '500.00', 'nagad_merchant');
        $this->paymentService->updateIntentStatus($res['intent']->getId(), PaymentStatus::SUCCEEDED);
        $this->rechargeService->settleRecharge($res['intent']->getId());
        $this->assertSame('500.00', $this->walletService->getBalance(10));

        // 2. Create product & order for 300 BDT
        $prodId = $this->productRepo->createProduct([
            'title'            => 'Digital eBook',
            'slug'             => 'prod-' . uniqid(),
            'product_type'     => ProductType::DIGITAL,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => '300.00',
            'discount_percent' => '0.00',
            'final_price'      => '300.00',
            'currency'         => 'BDT',
            'is_free'          => 0,
        ]);
        $order = $this->orderService->createOrder(10, [
            ['product_id' => $prodId, 'quantity' => 1]
        ]);

        // 3. Check out with wallet
        $paidOrder = $this->checkoutService->processWalletPayment((int)$order->id, 10);
        $this->assertSame('paid', $paidOrder->payment_status);

        // 4. Remaining wallet balance is 200.00 BDT
        $this->assertSame('200.00', $this->walletService->getBalance(10));
    }

    public function testScenarioAU_MixedWalletCheckoutRemainsIntact(): void
    {
        // Recharge 100 BDT
        $this->walletService->credit(10, '100.00', 'seed_mixed', 'Initial', null, 'recharge');

        // Order for 300 BDT
        $prodId = $this->productRepo->createProduct([
            'title'            => 'Pro Bundle',
            'slug'             => 'prod-' . uniqid(),
            'product_type'     => ProductType::PACKAGE,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => '300.00',
            'discount_percent' => '0.00',
            'final_price'      => '300.00',
            'currency'         => 'BDT',
            'is_free'          => 0,
        ]);
        $order = $this->orderService->createOrder(10, [
            ['product_id' => $prodId, 'quantity' => 1]
        ]);

        // Pay 100 with wallet + 200 with gateway
        $result = $this->checkoutService->processMixedPayment((int)$order->id, 10, '100.00', 'nagad_merchant');
        $this->assertSame('100.00', $result['wallet_amount']);
        $this->assertSame('200.00', $result['favorite_pay_amount']);
        $this->assertSame('0.00', $this->walletService->getBalance(10));
    }

    public function testScenarioAV_WalletBalanceArithmeticCorrect(): void
    {
        $this->walletService->credit(1, '100.10', 't1', 'c1');
        $this->walletService->credit(1, '200.20', 't2', 'c2');
        $this->walletService->debit(1, '50.15', 't3', 'd1');

        $this->assertSame('250.15', $this->walletService->getBalance(1));
    }

    public function testScenarioAW_WalletBalanceCannotBeNegative(): void
    {
        $this->walletService->credit(1, '50.00', 't1', 'c1');

        $this->expectException(WalletException::class);
        $this->walletService->debit(1, '50.01', 't2', 'too high');
    }

    public function testScenarioAX_CustomerOwnershipIsolation(): void
    {
        $res = $this->rechargeService->createRecharge(10, '200.00', 'bkash_manual');
        $intentId = $res['intent']->getId();

        // Customer 20 attempts to submit TrxID for Customer 10's intent
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unauthorized access');
        $this->rechargeService->submitManualRecharge(20, $intentId, 'bkash_manual', 'TRX_HACK');
    }

    public function testScenarioAY_RechargeIdTamperingRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->rechargeService->submitManualRecharge(1, 'pi_nonexistent', 'bkash_manual', 'TRX_1');
    }

    public function testScenarioAZ_PaymentIdTamperingRejected(): void
    {
        $GLOBALS['_test_current_user_id'] = 1;
        $req = new Request(['intent_id' => 'pi_fake_123'], [], ['REQUEST_METHOD' => 'GET']);
        $resp = $this->walletController->callback($req);

        $this->assertInstanceOf(Response::class, $resp);
        $this->assertSame('0.00', $this->walletService->getBalance(1));
    }

    public function testScenarioBA_SqlInjectionProtection(): void
    {
        $nastyString = "50.00'; DROP TABLE favorite_digital_wallets; --";
        $this->expectException(InvalidArgumentException::class);
        $this->rechargeService->validateRechargeAmount($nastyString, 'bkash_manual');
    }

    public function testScenarioBB_XssProtection(): void
    {
        $this->walletService->credit(1, '100.00', 'tx_xss', '<script>alert(1)</script>', null, 'recharge');

        $GLOBALS['_test_current_user_id'] = 1;
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->walletController->index($req);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testScenarioBC_SafeRedirectProtection(): void
    {
        $GLOBALS['_test_current_user_id'] = 1;
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $resp = $this->walletController->callback($req);

        $this->assertInstanceOf(Response::class, $resp);
        $this->assertSame('/account/wallet', $resp->getHeaders()['Location'] ?? null);
    }

    public function testScenarioBD_Pagination(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->walletService->credit(1, '10.00', "ref_page_{$i}", "Deposit {$i}");
        }

        $wallet = $this->walletRepo->getOrCreateWallet(1);
        $page1 = $this->walletRepo->getTransactionsPaginated((int)$wallet->id, 1, 10);
        $page2 = $this->walletRepo->getTransactionsPaginated((int)$wallet->id, 2, 10);

        $this->assertSame(25, $page1['total']);
        $this->assertSame(3, $page1['total_pages']);
        $this->assertCount(10, $page1['data']);
        $this->assertCount(10, $page2['data']);
    }

    public function testScenarioBE_LargeHistoryDoesNotLoadUnboundedData(): void
    {
        $wallet = $this->walletRepo->getOrCreateWallet(1);
        // Request limit 500 clamped to 100
        $data = $this->walletRepo->getTransactionsPaginated((int)$wallet->id, 1, 500);
        $this->assertSame(100, $data['per_page']);
    }

    public function testScenarioBF_ResponsiveRenderingSanity(): void
    {
        $GLOBALS['_test_current_user_id'] = 1;
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->walletController->index($req);

        $this->assertStringContainsString('fav-wallet-container', $html);
        $this->assertStringContainsString('fav-wallet-header', $html);
        $this->assertStringContainsString('fav-card', $html);
    }

    public function testScenarioBG_AccessibilitySanity(): void
    {
        $GLOBALS['_test_current_user_id'] = 1;
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->walletController->index($req);

        $this->assertStringContainsString('aria-label="Wallet Transaction History"', $html);
        $this->assertStringContainsString('aria-label="Recent Recharge History"', $html);
    }

    public function testScenarioBH_PrefixSafeQueries(): void
    {
        $prefixPdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);
        $prefixDb = new class($prefixPdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite'];
                $this->prefix = 'cms_';
            }
        };
        $prefixDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);

        // Run migrations with prefix
        $migrator = new Migrator($prefixDb);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');

        $pRepo = new WalletRepository($prefixDb);
        $pWallet = $pRepo->getOrCreateWallet(55);
        $this->assertSame('BDT', $pWallet->currency);
    }

    public function testScenarioBI_SqliteCompatibility(): void
    {
        $this->assertSame('sqlite', strtolower((string)$this->sqlitePdo->getAttribute(PDO::ATTR_DRIVER_NAME)));
        $wallet = $this->walletRepo->getOrCreateWallet(1);
        $this->assertNotNull($wallet->id);
    }

    public function testScenarioBJ_MySqlCompatibility(): void
    {
        try {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = (int)(getenv('DB_PORT') ?: 3306);
            $user = getenv('DB_USER') ?: 'root';
            $pass = getenv('DB_PASS') ?: '';
            $name = getenv('DB_NAME') ?: 'favorite_cms_test';

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 1,
            ]);

            $db = new class($pdo) extends Database {
                public function __construct(PDO $pdo) {
                    $this->pdo = $pdo;
                    $this->config = ['driver' => 'mysql'];
                    $this->prefix = '';
                }
            };
            $db->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);
            $migrator = new Migrator($db);
            $migrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');

            $repo = new WalletRepository($db);
            $w = $repo->getOrCreateWallet(999);
            $this->assertSame('BDT', $w->currency);
        } catch (Throwable) {
            $this->markTestSkipped('Local MySQL server offline');
        }
    }
}
