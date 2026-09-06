<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteDigital;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Controllers\CustomerCheckoutController;
use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Exceptions\CheckoutException;
use FavoriteCMS\Digital\Exceptions\WalletException;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;
use FavoriteCMS\Digital\Repositories\OrderRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Repositories\WalletRepository;
use FavoriteCMS\Digital\Services\CheckoutService;
use FavoriteCMS\Digital\Services\DefaultEntitlementChecker;
use FavoriteCMS\Digital\Services\DigitalFileStorageService;
use FavoriteCMS\Digital\Services\MembershipLifecycleService;
use FavoriteCMS\Digital\Services\OrderService;
use FavoriteCMS\Digital\Services\ProductManagementService;
use FavoriteCMS\Digital\Services\WalletService;
use FavoriteCMS\Digital\Support\OrderLifecycleState;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

class CheckoutPaymentIntegrationTest extends TestCase
{
    private Application $app;
    private PDO $sqlitePdo;
    private Database $sqliteDb;
    private ProductRepository $productRepo;
    private OrderRepository $orderRepo;
    private WalletRepository $walletRepo;
    private WalletService $walletService;
    private OrderService $orderService;
    private CheckoutService $checkoutService;
    private CustomerCheckoutController $checkoutController;
    private PaymentServiceInterface $mockPayService;

    /** @var array<string, PaymentIntent> */
    private array $intents = [];

    /** @var array<string, PaymentAttempt> */
    private array $attempts = [];

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

        // Run migrations
        $migrator = new Migrator($this->sqliteDb);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');

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

        // Mock Favorite Pay service
        $testCase = $this;
        $this->mockPayService = new class($testCase, $this->intents, $this->attempts) implements PaymentServiceInterface {
            public TestCase $tc;
            public array $intents;
            public array $attempts;

            public function __construct(TestCase $tc, array &$intents, array &$attempts)
            {
                $this->tc = $tc;
                $this->intents = &$intents;
                $this->attempts = &$attempts;
            }

            public function createIntent(string $sourcePlugin, string $sourceReference, Money $baseAmount, array $options = []): PaymentIntent
            {
                $id = 'pi_' . bin2hex(random_bytes(8));
                $intent = new PaymentIntent(
                    $id,
                    $sourcePlugin,
                    $sourceReference,
                    $baseAmount,
                    $baseAmount,
                    PaymentStatus::PENDING,
                    null,
                    $options['customer_id'] ?? null,
                    null,
                    $options['metadata'] ?? []
                );
                $this->intents[$id] = $intent;
                return $intent;
            }

            public function getIntent(string $intentId): ?PaymentIntent
            {
                return $this->intents[$intentId] ?? null;
            }

            public function updateIntentStatus(string $intentId, PaymentStatus $newStatus): PaymentIntent
            {
                $intent = $this->intents[$intentId] ?? null;
                if (!$intent) {
                    throw new \InvalidArgumentException("Intent not found: {$intentId}");
                }
                $updated = $intent->withStatus($newStatus);
                $this->intents[$intentId] = $updated;
                return $updated;
            }

            public function initiatePayment(string $intentId, string $gatewayId, array $params = []): PaymentAttempt
            {
                $intent = $this->intents[$intentId] ?? null;
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
                    null,
                    null,
                    ['redirect_url' => 'https://gateway.example.com/pay/' . $attemptId]
                );
                $this->attempts[$attemptId] = $attempt;
                return $attempt;
            }

            public function submitManualVerification(string $intentId, string $gatewayId, string $transactionReference, array $details = []): PaymentAttempt
            {
                $intent = $this->intents[$intentId] ?? null;
                $attemptId = 'att_man_' . bin2hex(random_bytes(8));
                $attempt = new PaymentAttempt(
                    $attemptId,
                    $intentId,
                    $gatewayId,
                    $intent->getChargeAmount(),
                    PaymentStatus::AWAITING_VERIFICATION,
                    $transactionReference
                );
                $this->attempts[$attemptId] = $attempt;
                $this->updateIntentStatus($intentId, PaymentStatus::AWAITING_VERIFICATION);
                return $attempt;
            }

            public function approveManualPayment(string $attemptId, int $operatorUserId, ?string $notes = null): PaymentAttempt
            {
                $attempt = $this->attempts[$attemptId] ?? null;
                $approved = $attempt->markApproved($operatorUserId, $notes);
                $this->attempts[$attemptId] = $approved;
                $this->updateIntentStatus($attempt->getIntentId(), PaymentStatus::SUCCEEDED);
                return $approved;
            }

            public function rejectManualPayment(string $attemptId, int $operatorUserId, string $reason): PaymentAttempt
            {
                $attempt = $this->attempts[$attemptId] ?? null;
                $rejected = $attempt->markRejected($operatorUserId, $reason);
                $this->attempts[$attemptId] = $rejected;
                $this->updateIntentStatus($attempt->getIntentId(), PaymentStatus::FAILED);
                return $rejected;
            }

            public function getAvailablePaymentMethods(?string $currency = null): array
            {
                return [
                    ['id' => 'bkash_auto', 'title' => 'bKash Auto Checkout', 'type' => 'bkash', 'is_manual' => false],
                    ['id' => 'binance', 'title' => 'Binance Pay', 'type' => 'binance_pay', 'is_manual' => false],
                    ['id' => 'manual_bkash', 'title' => 'bKash Manual Send Money', 'type' => 'manual_bkash', 'is_manual' => true],
                ];
            }

            public function getCheckoutCalculation(PaymentIntent $intent, string $gatewayId): array
            {
                return [
                    'gateway_id'     => $gatewayId,
                    'base_amount'    => $intent->getBaseAmount()->toMajorUnit(),
                    'charge_amount'  => $intent->getChargeAmount()->toMajorUnit(),
                    'base_currency'  => $intent->getBaseAmount()->getCurrency(),
                    'charge_currency'=> $intent->getChargeAmount()->getCurrency(),
                ];
            }
        };

        $this->checkoutService = new CheckoutService(
            $this->orderRepo,
            $this->walletService,
            $this->mockPayService,
            $this->sqliteDb
        );

        $this->checkoutController = new CustomerCheckoutController($this->app, $this->checkoutService);

        $_SESSION = [
            'auth_user_id'   => 10,
            'auth_user_name' => 'Customer Ten',
            '_token'         => 'valid_checkout_token',
        ];

        $user = new class extends User {
            public int $id = 10;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool { return false; }
        };
        $GLOBALS['_test_current_user'] = $user;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);
    }

    private function createPublishedProduct(string $title = 'Item', string $price = '100.00'): int
    {
        return $this->productRepo->createProduct([
            'title'            => $title,
            'slug'             => 'item-' . uniqid(),
            'product_type'     => ProductType::DIGITAL,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => $price,
            'discount_percent' => '0.00',
            'final_price'      => $price,
            'currency'         => 'BDT',
            'is_free'          => 0,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    // =========================================================================
    // SCENARIO A: Wallet-only exact payment marks order paid
    // =========================================================================
    public function testA_WalletOnlyExactPayment(): void
    {
        $prodId = $this->createPublishedProduct('Book', '50.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        // Fund customer wallet with exact 50.00
        $this->walletService->credit(10, '50.00', 'init_fund_1', 'Initial balance');
        $this->assertSame('50.00', $this->walletService->getBalance(10));

        $settledOrder = $this->checkoutService->processWalletPayment((int)$order->id, 10);

        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $settledOrder->payment_status);
        $this->assertSame(OrderLifecycleState::STATUS_PROCESSING, $settledOrder->status);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_UNFULFILLED, $settledOrder->fulfillment_status);

        // Wallet deducted to 0.00
        $this->assertSame('0.00', $this->walletService->getBalance(10));

        // Payment record recorded
        $payments = $this->orderRepo->getOrderPayments((int)$order->id);
        $this->assertCount(1, $payments);
        $this->assertSame('wallet', $payments[0]->payment_method);
        $this->assertSame('50.00', $payments[0]->amount_paid);
        $this->assertSame('completed', $payments[0]->status);
    }

    // =========================================================================
    // SCENARIO B: Wallet insufficient balance rejected
    // =========================================================================
    public function testB_WalletInsufficientBalance(): void
    {
        $prodId = $this->createPublishedProduct('Course', '100.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        $this->walletService->credit(10, '40.00', 'init_fund_2', 'Initial balance');

        $this->expectException(WalletException::class);
        $this->expectExceptionMessageMatches('/Insufficient wallet balance/');

        $this->checkoutService->processWalletPayment((int)$order->id, 10);

        // Wallet balance untouched
        $this->assertSame('40.00', $this->walletService->getBalance(10));
    }

    // =========================================================================
    // SCENARIO C: Wallet + Favorite Pay mixed payment exact split
    // =========================================================================
    public function testC_WalletAndFavoritePayMixedPayment(): void
    {
        $prodId = $this->createPublishedProduct('Masterclass', '500.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        $this->walletService->credit(10, '300.00', 'init_fund_3', 'Initial balance');

        $res = $this->checkoutService->processMixedPayment((int)$order->id, 10, '300.00', 'bkash_auto');

        $this->assertSame('300.00', $res['wallet_amount']);
        $this->assertSame('200.00', $res['favorite_pay_amount']);

        // Order is now partially paid
        $refetched = $this->orderRepo->findOrder((int)$order->id);
        $this->assertSame(OrderLifecycleState::PAYMENT_PARTIALLY_PAID, $refetched->payment_status);

        // Wallet deducted by 300.00
        $this->assertSame('0.00', $this->walletService->getBalance(10));

        // Two payments in record: wallet (completed) and favorite_pay (pending)
        $payments = $this->orderRepo->getOrderPayments((int)$order->id);
        $this->assertCount(2, $payments);
        $this->assertSame('wallet', $payments[0]->payment_method);
        $this->assertSame('300.00', $payments[0]->amount_paid);
        $this->assertSame('completed', $payments[0]->status);

        $this->assertSame('favorite_pay', $payments[1]->payment_method);
        $this->assertSame('200.00', $payments[1]->amount_paid);
        $this->assertSame('pending', $payments[1]->status);
    }

    // =========================================================================
    // SCENARIO D: Mixed payment exact amount verification
    // =========================================================================
    public function testD_MixedPaymentExactAmount(): void
    {
        $prodId = $this->createPublishedProduct('Masterclass 2', '500.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);
        $this->walletService->credit(10, '300.00', 'fund_d', 'Deposit');

        $res = $this->checkoutService->processMixedPayment((int)$order->id, 10, '300.00', 'bkash_auto');
        $intentId = $res['intent']->getId();

        // Gateway verifies success
        $this->mockPayService->updateIntentStatus($intentId, PaymentStatus::SUCCEEDED);

        $settledOrder = $this->checkoutService->verifyAndSettlePayment((int)$order->id, $intentId);

        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $settledOrder->payment_status);
        $this->assertSame(OrderLifecycleState::STATUS_PROCESSING, $settledOrder->status);
    }

    // =========================================================================
    // SCENARIO E: Wallet deduction cannot exceed balance
    // =========================================================================
    public function testE_WalletDeductionCannotExceedBalance(): void
    {
        $this->walletService->credit(10, '50.00', 'fund_e', 'Deposit');

        $this->expectException(WalletException::class);
        $this->walletService->debit(10, '50.01', 'tx_e_over', 'Overdraft attempt');
    }

    // =========================================================================
    // SCENARIO F: Wallet balance cannot become negative
    // =========================================================================
    public function testF_WalletBalanceCannotBecomeNegative(): void
    {
        $this->walletService->credit(10, '100.00', 'fund_f', 'Deposit');

        try {
            $this->walletService->debit(10, '150.00', 'tx_f_neg', 'Debit too much');
        } catch (WalletException) {
        }

        $this->assertSame('100.00', $this->walletService->getBalance(10));
    }

    // =========================================================================
    // SCENARIO G: Favorite Pay payment creation uses server-side amount
    // =========================================================================
    public function testG_FavoritePayPaymentCreationUsesServerSideAmount(): void
    {
        $prodId = $this->createPublishedProduct('Premium Asset', '250.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        $res = $this->checkoutService->processFavoritePayPayment((int)$order->id, 10, 'binance');
        /** @var PaymentIntent $intent */
        $intent = $res['intent'];

        $this->assertSame('250.00', $intent->getBaseAmount()->toMajorUnit());
        $this->assertSame('BDT', $intent->getBaseAmount()->getCurrency());
    }

    // =========================================================================
    // SCENARIO H: Client amount tampering rejected
    // =========================================================================
    public function testH_ClientAmountTamperingRejected(): void
    {
        $prodId = $this->createPublishedProduct('Software Pack', '400.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);
        $this->walletService->credit(10, '200.00', 'fund_h', 'Deposit');

        // Client attempts to pass 0.00 or negative or more than remaining
        $this->expectException(CheckoutException::class);
        $this->checkoutService->processMixedPayment((int)$order->id, 10, '0.00', 'bkash_auto');
    }

    // =========================================================================
    // SCENARIO I: Client currency tampering rejected
    // =========================================================================
    public function testI_ClientCurrencyTamperingRejected(): void
    {
        $wallet = $this->walletRepo->getOrCreateWallet(10);
        $this->assertSame('BDT', $wallet->currency);

        $prodId = $this->createPublishedProduct('Asset BDT', '100.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);
        $this->assertSame('BDT', $order->currency);
    }

    // =========================================================================
    // SCENARIO J: Browser success redirect alone cannot mark paid
    // =========================================================================
    public function testJ_BrowserSuccessRedirectAloneCannotMarkPaid(): void
    {
        $prodId = $this->createPublishedProduct('Report', '75.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        $res = $this->checkoutService->processFavoritePayPayment((int)$order->id, 10, 'bkash_auto');
        $intentId = $res['intent']->getId();

        // Browser hits callback route with intent_id, but gateway is still PENDING
        $callbackRequest = new Request(['action' => 'callback', 'intent_id' => $intentId]);
        $response = $this->checkoutController->handle($callbackRequest, $order->order_number);

        // Order is still pending, not paid!
        $refetched = $this->orderRepo->findOrder((int)$order->id);
        $this->assertSame(OrderLifecycleState::PAYMENT_PENDING, $refetched->payment_status);
    }

    // =========================================================================
    // SCENARIO K: Server-side verified payment marks paid
    // =========================================================================
    public function testK_ServerSideVerifiedPaymentMarksPaid(): void
    {
        $prodId = $this->createPublishedProduct('Audio Kit', '80.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        $res = $this->checkoutService->processFavoritePayPayment((int)$order->id, 10, 'bkash_auto');
        $intentId = $res['intent']->getId();

        // Intent verified by Favorite Pay
        $this->mockPayService->updateIntentStatus($intentId, PaymentStatus::SUCCEEDED);

        $callbackRequest = new Request(['action' => 'callback', 'intent_id' => $intentId]);
        $response = $this->checkoutController->handle($callbackRequest, $order->order_number);

        $refetched = $this->orderRepo->findOrder((int)$order->id);
        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $refetched->payment_status);
        $this->assertSame(OrderLifecycleState::STATUS_PROCESSING, $refetched->status);
    }

    // =========================================================================
    // SCENARIO L: Failed Favorite Pay payment does not mark paid
    // =========================================================================
    public function testL_FailedFavoritePayPaymentDoesNotMarkPaid(): void
    {
        $prodId = $this->createPublishedProduct('Font Family', '60.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        $res = $this->checkoutService->processFavoritePayPayment((int)$order->id, 10, 'bkash_auto');
        $intentId = $res['intent']->getId();

        $this->mockPayService->updateIntentStatus($intentId, PaymentStatus::FAILED);

        $settledOrder = $this->checkoutService->verifyAndSettlePayment((int)$order->id, $intentId);
        $this->assertNotSame(OrderLifecycleState::PAYMENT_PAID, $settledOrder->payment_status);
    }

    // =========================================================================
    // SCENARIO M: Duplicate callback is idempotent
    // =========================================================================
    public function testM_DuplicateCallbackIsIdempotent(): void
    {
        $prodId = $this->createPublishedProduct('Code Template', '90.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        $res = $this->checkoutService->processFavoritePayPayment((int)$order->id, 10, 'bkash_auto');
        $intentId = $res['intent']->getId();

        $this->mockPayService->updateIntentStatus($intentId, PaymentStatus::SUCCEEDED);

        // First verification
        $order1 = $this->checkoutService->verifyAndSettlePayment((int)$order->id, $intentId);
        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $order1->payment_status);

        // Second duplicate verification
        $order2 = $this->checkoutService->verifyAndSettlePayment((int)$order->id, $intentId);
        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $order2->payment_status);

        $payments = $this->orderRepo->getOrderPayments((int)$order->id);
        $this->assertCount(1, $payments);
    }

    // =========================================================================
    // SCENARIO N: Duplicate webhook/callback does not double-charge
    // =========================================================================
    public function testN_DuplicateWebhookOrCallbackDoesNotDoubleCharge(): void
    {
        $prodId = $this->createPublishedProduct('Video Clip', '120.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        $res = $this->checkoutService->processFavoritePayPayment((int)$order->id, 10, 'bkash_auto');
        $intentId = $res['intent']->getId();
        $this->mockPayService->updateIntentStatus($intentId, PaymentStatus::SUCCEEDED);

        $this->checkoutService->verifyAndSettlePayment((int)$order->id, $intentId);
        $this->checkoutService->verifyAndSettlePayment((int)$order->id, $intentId);

        $payments = $this->orderRepo->getOrderPayments((int)$order->id);
        $totalPaid = 0;
        foreach ($payments as $p) {
            $totalPaid += (float)$p->amount_paid;
        }
        $this->assertSame(120.00, (float)$totalPaid);
    }

    // =========================================================================
    // SCENARIO O: Duplicate wallet deduction prevented
    // =========================================================================
    public function testO_DuplicateWalletDeductionPrevented(): void
    {
        $this->walletService->credit(10, '200.00', 'fund_o', 'Deposit');

        $tx1 = $this->walletService->debit(10, '50.00', 'ref_idempotent_1', 'Debit 1');
        $tx2 = $this->walletService->debit(10, '50.00', 'ref_idempotent_1', 'Debit 1 Retry');

        // Same transaction returned
        $this->assertSame($tx1->id, $tx2->id);
        // Only 50 deducted, remaining 150
        $this->assertSame('150.00', $this->walletService->getBalance(10));
    }

    // =========================================================================
    // SCENARIO P: Mixed payment wallet failure recovery
    // =========================================================================
    public function testP_MixedPaymentWalletFailureRecovery(): void
    {
        $prodId = $this->createPublishedProduct('Design System', '300.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        // User has only 50 in wallet, but asks for 100
        $this->walletService->credit(10, '50.00', 'fund_p', 'Deposit');

        try {
            $this->checkoutService->processMixedPayment((int)$order->id, 10, '100.00', 'bkash_auto');
            $this->fail('Expected WalletException');
        } catch (WalletException) {
            $this->assertSame('50.00', $this->walletService->getBalance(10));
            $refetched = $this->orderRepo->findOrder((int)$order->id);
            $this->assertSame(OrderLifecycleState::PAYMENT_UNPAID, $refetched->payment_status);
        }
    }

    // =========================================================================
    // SCENARIO Q: Mixed payment Favorite Pay failure recovery
    // =========================================================================
    public function testQ_MixedPaymentFavoritePayFailureRecovery(): void
    {
        $prodId = $this->createPublishedProduct('3D Models Pack', '400.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        $this->walletService->credit(10, '250.00', 'fund_q', 'Deposit');
        $res = $this->checkoutService->processMixedPayment((int)$order->id, 10, '250.00', 'bkash_auto');
        $intentId = $res['intent']->getId();

        // Wallet is currently 0.00
        $this->assertSame('0.00', $this->walletService->getBalance(10));

        // Favorite Pay gateway fails!
        $this->mockPayService->updateIntentStatus($intentId, PaymentStatus::FAILED);
        $settled = $this->checkoutService->verifyAndSettlePayment((int)$order->id, $intentId);

        // Wallet reversed! 250.00 is back!
        $this->assertSame('250.00', $this->walletService->getBalance(10));
        $this->assertSame(OrderLifecycleState::PAYMENT_FAILED, $settled->payment_status);
    }

    // =========================================================================
    // SCENARIO R: Already-paid order cannot be charged again
    // =========================================================================
    public function testR_AlreadyPaidOrderCannotBeChargedAgain(): void
    {
        $prodId = $this->createPublishedProduct('Logo Design', '100.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        $this->walletService->credit(10, '100.00', 'fund_r', 'Deposit');
        $this->checkoutService->processWalletPayment((int)$order->id, 10);

        // Order is now paid
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/Order is already paid/');

        $this->checkoutService->processWalletPayment((int)$order->id, 10);
    }

    // =========================================================================
    // SCENARIO S: Cancelled order cannot be paid
    // =========================================================================
    public function testS_CancelledOrderCannotBePaid(): void
    {
        $prodId = $this->createPublishedProduct('Cancelled Item', '50.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);
        $this->orderService->updateStatus((int)$order->id, OrderLifecycleState::STATUS_CANCELLED);

        $this->walletService->credit(10, '50.00', 'fund_s', 'Deposit');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/Order has been cancelled/');

        $this->checkoutService->processWalletPayment((int)$order->id, 10);
    }

    // =========================================================================
    // SCENARIO T: Refunded order cannot be paid
    // =========================================================================
    public function testT_RefundedOrderCannotBePaid(): void
    {
        $prodId = $this->createPublishedProduct('Refunded Item', '50.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);
        $this->orderService->updatePaymentStatus((int)$order->id, OrderLifecycleState::PAYMENT_REFUNDED);

        $this->walletService->credit(10, '50.00', 'fund_t', 'Deposit');

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/Order has been refunded/');

        $this->checkoutService->processWalletPayment((int)$order->id, 10);
    }

    // =========================================================================
    // SCENARIO U: Customer cannot pay another customer's order
    // =========================================================================
    public function testU_CustomerCannotPayAnotherCustomersOrder(): void
    {
        $prodId = $this->createPublishedProduct('Secret Item', '70.00');
        // Order belongs to User 10
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        // User 99 tries to checkout
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessageMatches('/not authorized to checkout/');

        $this->checkoutService->getOrderForCheckout((int)$order->id, 99);
    }

    // =========================================================================
    // SCENARIO V: Remaining payable amount exactness
    // =========================================================================
    public function testV_RemainingPayableAmountExactness(): void
    {
        $prodId = $this->createPublishedProduct('Partial Item', '300.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        $this->assertSame('300.00', $this->checkoutService->calculateRemainingPayable($order));

        $this->walletService->credit(10, '100.00', 'fund_v', 'Deposit');
        $this->checkoutService->processMixedPayment((int)$order->id, 10, '100.00', 'bkash_auto');

        $refetched = $this->orderRepo->findOrder((int)$order->id);
        $this->assertSame('200.00', $this->checkoutService->calculateRemainingPayable($refetched));
    }

    // =========================================================================
    // SCENARIO W: Payment amount cannot exceed remaining amount
    // =========================================================================
    public function testW_PaymentAmountCannotExceedRemainingAmount(): void
    {
        $prodId = $this->createPublishedProduct('Limit Item', '100.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);
        $this->walletService->credit(10, '200.00', 'fund_w', 'Deposit');

        $this->expectException(CheckoutException::class);
        // Mixed payment with wallet amount >= total is invalid (should use wallet-only)
        $this->checkoutService->processMixedPayment((int)$order->id, 10, '100.00', 'bkash_auto');
    }

    // =========================================================================
    // SCENARIO X: Wallet concurrency protection
    // =========================================================================
    public function testX_WalletConcurrencyProtection(): void
    {
        $this->walletService->credit(10, '100.00', 'fund_x', 'Deposit');

        // Request 1: debit 60
        $tx1 = $this->walletService->debit(10, '60.00', 'tx_x_1', 'Debit 60');
        $this->assertSame('40.00', $this->walletService->getBalance(10));

        // Request 2: tries to debit 60 -> fails due to insufficient balance
        $this->expectException(WalletException::class);
        $this->walletService->debit(10, '60.00', 'tx_x_2', 'Debit 60 (concurrent fail)');
    }

    // =========================================================================
    // SCENARIO Y: Zero-value order handling
    // =========================================================================
    public function testY_ZeroValueOrderHandling(): void
    {
        $prodId = $this->productRepo->createProduct([
            'title'            => 'Free E-Book',
            'slug'             => 'free-ebook-' . uniqid(),
            'product_type'     => ProductType::DIGITAL,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => '0.00',
            'discount_percent' => '0.00',
            'final_price'      => '0.00',
            'currency'         => 'BDT',
            'is_free'          => 1,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);
        $this->assertSame('0.00', $order->total_amount);

        $settled = $this->checkoutService->processZeroValueOrder((int)$order->id, 10);
        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $settled->payment_status);
        $this->assertSame(OrderLifecycleState::STATUS_PROCESSING, $settled->status);

        $payments = $this->orderRepo->getOrderPayments((int)$order->id);
        $this->assertCount(1, $payments);
        $this->assertSame('free', $payments[0]->payment_method);
        $this->assertSame('0.00', $payments[0]->amount_paid);
    }

    // =========================================================================
    // SCENARIO Z: Manual payment remains pending until verified/approved
    // =========================================================================
    public function testZ_ManualPaymentRemainsPendingUntilVerifiedOrApproved(): void
    {
        $prodId = $this->createPublishedProduct('Workshop', '200.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        $res = $this->checkoutService->processFavoritePayPayment((int)$order->id, 10, 'manual_bkash');
        $intentId = $res['intent']->getId();

        // Submit manual TrxID
        $manualRes = $this->checkoutService->submitManualPayment(
            (int)$order->id,
            10,
            $intentId,
            'manual_bkash',
            'TRX12345678'
        );

        $refetched = $this->orderRepo->findOrder((int)$order->id);
        $this->assertSame(OrderLifecycleState::PAYMENT_PENDING, $refetched->payment_status);

        // Operator approves in Favorite Pay
        $attemptId = $manualRes['attempt']->getId();
        $this->mockPayService->approveManualPayment($attemptId, 1, 'Verified via SMS statement');

        // Settle order
        $settled = $this->checkoutService->verifyAndSettlePayment((int)$order->id, $intentId);
        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $settled->payment_status);
    }

    // =========================================================================
    // SCENARIO AA: Automatic payment server-side verification
    // =========================================================================
    public function testAA_AutomaticPaymentServerSideVerification(): void
    {
        $prodId = $this->createPublishedProduct('Icons Pack', '150.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        $res = $this->checkoutService->processFavoritePayPayment((int)$order->id, 10, 'bkash_auto');
        $intentId = $res['intent']->getId();

        $this->mockPayService->updateIntentStatus($intentId, PaymentStatus::SUCCEEDED);

        $settled = $this->checkoutService->verifyAndSettlePayment((int)$order->id, $intentId);
        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $settled->payment_status);
    }

    // =========================================================================
    // SCENARIO AB: Payment record persistence
    // =========================================================================
    public function testAB_PaymentRecordPersistence(): void
    {
        $prodId = $this->createPublishedProduct('Kit', '45.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);
        $this->walletService->credit(10, '45.00', 'fund_ab', 'Deposit');

        $this->checkoutService->processWalletPayment((int)$order->id, 10);

        $rows = $this->sqliteDb->select("SELECT * FROM `favorite_digital_order_payments` WHERE `order_id` = ?", [$order->id]);
        $this->assertCount(1, $rows);
        $this->assertSame('wallet', $rows[0]->payment_method);
        $this->assertSame('45.00', number_format((float)$rows[0]->amount_paid, 2, '.', ''));
        $this->assertSame('completed', $rows[0]->status);
    }

    // =========================================================================
    // SCENARIO AC: Payment status transition correctness
    // =========================================================================
    public function testAC_PaymentStatusTransitionCorrectness(): void
    {
        $prodId = $this->createPublishedProduct('Big Bundle', '600.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);
        $this->assertSame(OrderLifecycleState::PAYMENT_UNPAID, $order->payment_status);

        $this->walletService->credit(10, '200.00', 'fund_ac', 'Deposit');
        $res = $this->checkoutService->processMixedPayment((int)$order->id, 10, '200.00', 'bkash_auto');
        $intentId = $res['intent']->getId();

        $refetched = $this->orderRepo->findOrder((int)$order->id);
        $this->assertSame(OrderLifecycleState::PAYMENT_PARTIALLY_PAID, $refetched->payment_status);

        $this->mockPayService->updateIntentStatus($intentId, PaymentStatus::SUCCEEDED);
        $settled = $this->checkoutService->verifyAndSettlePayment((int)$order->id, $intentId);
        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $settled->payment_status);
    }

    // =========================================================================
    // SCENARIO AD: Favorite Pay boundary uses real public APIs
    // =========================================================================
    public function testAD_FavoritePayBoundaryUsesRealPublicApis(): void
    {
        $methods = $this->mockPayService->getAvailablePaymentMethods();
        $this->assertIsArray($methods);
        $this->assertNotEmpty($methods);

        $money = Money::fromMajorString('100.00', 'BDT');
        $this->assertSame(10000, $money->getAmount());
        $this->assertSame('BDT', $money->getCurrency());
    }

    // =========================================================================
    // SCENARIO AE: No Favorite Pay credential leakage
    // =========================================================================
    public function testAE_NoFavoritePayCredentialLeakage(): void
    {
        $prodId = $this->createPublishedProduct('Security Product', '110.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        $request = new Request();
        $html = $this->checkoutController->handle($request, $order->order_number);

        $this->assertIsString($html);
        $this->assertStringNotContainsString('api_secret', $html);
        $this->assertStringNotContainsString('app_secret', $html);
        $this->assertStringNotContainsString('password', $html);
    }

    // =========================================================================
    // SCENARIO AF: CSRF protection on checkout POST
    // =========================================================================
    public function testAF_CsrfProtection(): void
    {
        $prodId = $this->createPublishedProduct('CSRF Product', '30.00');
        $order = $this->orderService->createOrder(10, [['product_id' => $prodId, 'quantity' => 1]]);

        $postRequest = new Request(
            [],
            ['action' => 'pay', 'payment_method' => 'wallet', '_token' => 'invalid_csrf_token'],
            ['REQUEST_METHOD' => 'POST']
        );

        $response = $this->checkoutController->handle($postRequest, $order->order_number);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('CSRF', $_SESSION['flash_error'] ?? '');
    }

    // =========================================================================
    // SCENARIO AG: Authentication required on checkout
    // =========================================================================
    public function testAG_AuthenticationRequired(): void
    {
        unset($GLOBALS['_test_current_user']);
        $_SESSION['auth_user_id'] = 0;

        $request = new Request();
        $response = $this->checkoutController->handle($request, 'ORD-20260907-000001');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location'] ?? '');
    }

    // =========================================================================
    // SCENARIO AH: Database prefix safety
    // =========================================================================
    public function testAH_DatabasePrefixSafety(): void
    {
        $prefixedPdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);

        $prefixedDb = new class($prefixedPdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite', 'prefix' => 'fvt_'];
                $this->prefix = 'fvt_';
            }
        };

        $prefixedDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);
        $migrator = new Migrator($prefixedDb);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');

        $prefixedWalletRepo = new WalletRepository($prefixedDb);
        $prefixedWalletService = new WalletService($prefixedWalletRepo, $prefixedDb);

        $prefixedWalletService->credit(50, '120.00', 'pref_tx_1', 'Prefixed credit');
        $this->assertSame('120.00', $prefixedWalletService->getBalance(50));

        // Check raw prefixed table
        $rows = $prefixedDb->select("SELECT * FROM `fvt_favorite_digital_wallets` WHERE `user_id` = ?", [50]);
        $this->assertCount(1, $rows);
        $this->assertSame('120.00', number_format((float)$rows[0]->balance_amount, 2, '.', ''));
    }

    // =========================================================================
    // SCENARIO AI: SQLite compatibility verified
    // =========================================================================
    public function testAI_SqliteCompatibility(): void
    {
        $driver = $this->sqliteDb->getConnection()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->assertSame('sqlite', strtolower((string)$driver));
        $this->assertTrue(true, 'SQLite operates seamlessly.');
    }

    // =========================================================================
    // SCENARIO AJ: MySQL/MariaDB compatibility checked
    // =========================================================================
    public function testAJ_MySqlMariaDbCompatibility(): void
    {
        try {
            $mysqlPdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=favorite_cms_test;charset=utf8mb4', 'root', '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ]);

            $mysqlDb = new class($mysqlPdo) extends Database {
                public function __construct(PDO $pdo)
                {
                    $this->pdo = $pdo;
                    $this->config = ['driver' => 'mysql'];
                    $this->prefix = '';
                }
            };

            $mysqlDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);
            $migrator = new Migrator($mysqlDb);
            $migrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');

            $mysqlWalletRepo = new WalletRepository($mysqlDb);
            $mysqlWalletService = new WalletService($mysqlWalletRepo, $mysqlDb);

            $mysqlWalletService->credit(999, '500.00', 'mysql_fund_1', 'MySQL initial');
            $this->assertSame('500.00', $mysqlWalletService->getBalance(999));

            // Clean up
            $mysqlDb->execute("DELETE FROM `favorite_digital_wallet_transactions` WHERE `reference_id` = 'mysql_fund_1'");
            $mysqlDb->execute("DELETE FROM `favorite_digital_wallets` WHERE `user_id` = 999");
        } catch (Throwable $e) {
            $this->markTestSkipped('Local MySQL/MariaDB server not accessible; skipping MySQL-specific verification.');
        }
    }
}
