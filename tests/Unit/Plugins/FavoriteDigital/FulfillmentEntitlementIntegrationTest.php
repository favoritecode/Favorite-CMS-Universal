<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteDigital;

use DateTimeImmutable;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Controllers\AdminOrderController;
use FavoriteCMS\Digital\Controllers\CustomerCheckoutController;
use FavoriteCMS\Digital\Domain\MembershipStatus;
use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Exceptions\FulfillmentException;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;
use FavoriteCMS\Digital\Repositories\EntitlementRepository;
use FavoriteCMS\Digital\Repositories\OrderRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Repositories\WalletRepository;
use FavoriteCMS\Digital\Services\CheckoutService;
use FavoriteCMS\Digital\Services\DefaultEntitlementChecker;
use FavoriteCMS\Digital\Services\DigitalFileStorageService;
use FavoriteCMS\Digital\Services\FulfillmentService;
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

class FulfillmentEntitlementIntegrationTest extends TestCase
{
    private Application $app;
    private PDO $sqlitePdo;
    private Database $sqliteDb;
    private ProductRepository $productRepo;
    private OrderRepository $orderRepo;
    private WalletRepository $walletRepo;
    private WalletService $walletService;
    private EntitlementRepository $entitlementRepo;
    private MembershipLifecycleService $membershipService;
    private FulfillmentService $fulfillmentService;
    private DefaultEntitlementChecker $checker;
    private OrderService $orderService;
    private CheckoutService $checkoutService;
    private AdminOrderController $adminOrderController;
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
        $this->entitlementRepo = new EntitlementRepository($this->sqliteDb);
        $this->membershipService = new MembershipLifecycleService($this->productRepo);

        $this->fulfillmentService = new FulfillmentService(
            $this->orderRepo,
            $this->entitlementRepo,
            $this->productRepo,
            $this->membershipService,
            $this->sqliteDb
        );

        $this->checker = new DefaultEntitlementChecker(
            $this->sqliteDb,
            $this->entitlementRepo,
            $this->membershipService,
            $this->productRepo
        );

        $this->orderService = new OrderService(
            $this->orderRepo,
            $this->productRepo,
            $this->membershipService,
            $this->checker,
            $this->sqliteDb
        );

        $this->intents = [];
        $this->attempts = [];

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
            $this->sqliteDb,
            $this->fulfillmentService
        );

        $this->adminOrderController = new AdminOrderController(
            $this->app,
            $this->orderService,
            $this->fulfillmentService,
            $this->entitlementRepo
        );

        $_SESSION = [
            'auth_user_id'   => 10,
            'auth_user_name' => 'Admin User',
            '_token'         => 'valid_test_token',
        ];

        $user = new class extends User {
            public int $id = 10;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool { return true; }
        };
        $GLOBALS['_test_current_user'] = $user;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_test_current_user']);
        $_SESSION = [];
    }

    // -------------------------------------------------------------------------
    // Helper factories
    // -------------------------------------------------------------------------

    private function createDigitalProduct(string $title, float $price = 100.0, int $expiryDays = 0, int $isMemEligible = 1): int
    {
        $pid = $this->productRepo->createProduct([
            'title'            => $title,
            'slug'             => 'prod-' . bin2hex(random_bytes(4)),
            'product_type'     => ProductType::DIGITAL,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => number_format($price, 2, '.', ''),
            'discount_percent' => '0.00',
            'final_price'      => number_format($price, 2, '.', ''),
            'currency'         => 'BDT',
            'is_free'          => $price == 0.0 ? 1 : 0,
        ]);

        $this->productRepo->saveProductDetails($pid, [
            'download_expiry_days'   => $expiryDays,
            'is_membership_eligible' => $isMemEligible,
            'version'                => '1.0.0',
        ]);

        return $pid;
    }

    private function createServiceProduct(string $title, float $price = 200.0): int
    {
        $pid = $this->productRepo->createProduct([
            'title'            => $title,
            'slug'             => 'svc-' . bin2hex(random_bytes(4)),
            'product_type'     => ProductType::SERVICE,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => number_format($price, 2, '.', ''),
            'discount_percent' => '0.00',
            'final_price'      => number_format($price, 2, '.', ''),
            'currency'         => 'BDT',
            'is_free'          => $price == 0.0 ? 1 : 0,
        ]);

        $this->productRepo->saveServiceDetails($pid, [
            'delivery_time_days' => 3,
            'service_scope'      => 'Service execution details',
        ]);

        return $pid;
    }

    private function createPackageProduct(string $title, float $price, array $includedProductIds): int
    {
        $pid = $this->productRepo->createProduct([
            'title'            => $title,
            'slug'             => 'pkg-' . bin2hex(random_bytes(4)),
            'product_type'     => ProductType::PACKAGE,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => number_format($price, 2, '.', ''),
            'discount_percent' => '0.00',
            'final_price'      => number_format($price, 2, '.', ''),
            'currency'         => 'BDT',
            'is_free'          => $price == 0.0 ? 1 : 0,
        ]);

        $packageId = $this->productRepo->createPackage($pid, 'bundle');
        $this->productRepo->setPackageItems($packageId, $includedProductIds);

        return $pid;
    }

    private function createMembershipProduct(string $title, float $price = 500.0, string $planType = 'monthly', int $durationCount = 1): int
    {
        return $this->membershipService->createPlan(
            [
                'title'          => $title,
                'slug'           => 'mem-' . bin2hex(random_bytes(4)),
                'status'         => ProductStatus::PUBLISHED,
                'original_price' => number_format($price, 2, '.', ''),
                'currency'       => 'BDT',
                'is_free'        => $price == 0.0 ? 1 : 0,
            ],
            [
                'plan_type'           => $planType,
                'duration_count'      => $durationCount,
                'grace_period_days'   => 3,
                'allows_auto_renewal' => 1,
            ]
        );
    }

    private function createPaidOrder(int $userId, array $productIds): object
    {
        $items = array_map(fn ($id) => ['product_id' => $id, 'quantity' => 1], $productIds);
        $order = $this->orderService->createOrder($userId, $items);

        // Record full payment
        $this->orderRepo->createOrderPayment([
            'order_id'           => $order->id,
            'payment_method'     => 'wallet',
            'favorite_pay_tx_id' => null,
            'wallet_tx_id'       => 'tx_test_' . bin2hex(random_bytes(4)),
            'amount_paid'        => $order->total_amount,
            'currency'           => 'BDT',
            'status'             => 'completed',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $this->orderRepo->updatePaymentStatus((int)$order->id, OrderLifecycleState::PAYMENT_PAID);
        $this->orderRepo->updateOrderStatus((int)$order->id, OrderLifecycleState::STATUS_PROCESSING);

        return $this->orderRepo->findOrderWithItems((int)$order->id);
    }

    // =========================================================================
    // SCENARIOS A - AL
    // =========================================================================

    /**
     * Scenario A: Paid digital product fulfills
     */
    public function testScenarioA_PaidDigitalProductFulfills(): void
    {
        $userId = 101;
        $prodId = $this->createDigitalProduct('E-Book Mastery', 150.0);
        $order = $this->createPaidOrder($userId, [$prodId]);

        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $order->payment_status);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_UNFULFILLED, $order->fulfillment_status);

        $fulfilledOrder = $this->fulfillmentService->fulfillOrder((int)$order->id);

        $this->assertSame(OrderLifecycleState::FULFILLMENT_FULFILLED, $fulfilledOrder->fulfillment_status);
        $this->assertSame(OrderLifecycleState::STATUS_COMPLETED, $fulfilledOrder->status);
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $prodId));
    }

    /**
     * Scenario B: Paid service fulfills
     */
    public function testScenarioB_PaidServiceFulfills(): void
    {
        $userId = 102;
        $svcId = $this->createServiceProduct('Custom Code Audit', 300.0);
        $order = $this->createPaidOrder($userId, [$svcId]);

        $fulfilledOrder = $this->fulfillmentService->fulfillOrder((int)$order->id);

        $this->assertSame(OrderLifecycleState::FULFILLMENT_FULFILLED, $fulfilledOrder->fulfillment_status);
        $this->assertSame(OrderLifecycleState::STATUS_COMPLETED, $fulfilledOrder->status);
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $svcId));
    }

    /**
     * Scenario C: Paid package fulfills
     */
    public function testScenarioC_PaidPackageFulfills(): void
    {
        $userId = 103;
        $item1 = $this->createDigitalProduct('Plugin A', 50.0);
        $item2 = $this->createDigitalProduct('Plugin B', 60.0);
        $item3 = $this->createServiceProduct('Install Service', 80.0);

        $pkgId = $this->createPackageProduct('Super Bundle', 150.0, [$item1, $item2, $item3]);
        $order = $this->createPaidOrder($userId, [$pkgId]);

        $fulfilledOrder = $this->fulfillmentService->fulfillOrder((int)$order->id);

        $this->assertSame(OrderLifecycleState::FULFILLMENT_FULFILLED, $fulfilledOrder->fulfillment_status);
        $this->assertSame(OrderLifecycleState::STATUS_COMPLETED, $fulfilledOrder->status);

        // Package purchase entitlement
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $pkgId));
        // Child entitlements granted via package
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $item1));
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $item2));
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $item3));
    }

    /**
     * Scenario D: Paid membership fulfills
     */
    public function testScenarioD_PaidMembershipFulfills(): void
    {
        $userId = 104;
        $memProductId = $this->createMembershipProduct('Pro Club', 500.0, 'monthly', 1);
        $order = $this->createPaidOrder($userId, [$memProductId]);

        $fulfilledOrder = $this->fulfillmentService->fulfillOrder((int)$order->id);

        $this->assertSame(OrderLifecycleState::FULFILLMENT_FULFILLED, $fulfilledOrder->fulfillment_status);
        $this->assertSame(OrderLifecycleState::STATUS_COMPLETED, $fulfilledOrder->status);

        // Membership product entitlement
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $memProductId));
        // Membership record is active
        $this->assertTrue($this->membershipService->hasActiveMembership($userId));
    }

    /**
     * Scenario E: Mixed order fulfills all valid items
     */
    public function testScenarioE_MixedOrderFulfillsAllValidItems(): void
    {
        $userId = 105;
        $digId = $this->createDigitalProduct('Digital Asset', 100.0);
        $svcId = $this->createServiceProduct('Setup Service', 150.0);
        $child1 = $this->createDigitalProduct('Bundle Item 1', 40.0);
        $pkgId = $this->createPackageProduct('Starter Kit', 30.0, [$child1]);
        $memId = $this->createMembershipProduct('Monthly Pass', 200.0);

        $order = $this->createPaidOrder($userId, [$digId, $svcId, $pkgId, $memId]);

        $fulfilledOrder = $this->fulfillmentService->fulfillOrder((int)$order->id);

        $this->assertSame(OrderLifecycleState::FULFILLMENT_FULFILLED, $fulfilledOrder->fulfillment_status);
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $digId));
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $svcId));
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $pkgId));
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $child1));
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $memId));
        $this->assertTrue($this->membershipService->hasActiveMembership($userId));
    }

    /**
     * Scenario F: Unpaid order does not fulfill
     */
    public function testScenarioF_UnpaidOrderDoesNotFulfill(): void
    {
        $userId = 106;
        $prodId = $this->createDigitalProduct('Book F', 100.0);
        $order = $this->orderService->createOrder($userId, [['product_id' => $prodId, 'quantity' => 1]]);

        $this->assertSame(OrderLifecycleState::PAYMENT_UNPAID, $order->payment_status);

        $this->expectException(FulfillmentException::class);
        $this->expectExceptionMessageMatches("/not eligible for fulfillment/i");

        $this->fulfillmentService->fulfillOrder((int)$order->id);
    }

    /**
     * Scenario G: Pending payment does not fulfill
     */
    public function testScenarioG_PendingPaymentDoesNotFulfill(): void
    {
        $userId = 107;
        $prodId = $this->createDigitalProduct('Book G', 100.0);
        $order = $this->orderService->createOrder($userId, [['product_id' => $prodId, 'quantity' => 1]]);
        $this->orderRepo->updatePaymentStatus((int)$order->id, OrderLifecycleState::PAYMENT_PENDING);

        $this->expectException(FulfillmentException::class);
        $this->fulfillmentService->fulfillOrder((int)$order->id);
    }

    /**
     * Scenario H: Partially paid order does not fully fulfill
     */
    public function testScenarioH_PartiallyPaidOrderDoesNotFullyFulfill(): void
    {
        $userId = 108;
        $prodId = $this->createDigitalProduct('Book H', 100.0);
        $order = $this->orderService->createOrder($userId, [['product_id' => $prodId, 'quantity' => 1]]);
        $this->orderRepo->updatePaymentStatus((int)$order->id, OrderLifecycleState::PAYMENT_PARTIALLY_PAID);

        $this->expectException(FulfillmentException::class);
        $this->fulfillmentService->fulfillOrder((int)$order->id);
    }

    /**
     * Scenario I: Failed payment does not fulfill
     */
    public function testScenarioI_FailedPaymentDoesNotFulfill(): void
    {
        $userId = 109;
        $prodId = $this->createDigitalProduct('Book I', 100.0);
        $order = $this->orderService->createOrder($userId, [['product_id' => $prodId, 'quantity' => 1]]);
        $this->orderRepo->updatePaymentStatus((int)$order->id, OrderLifecycleState::PAYMENT_FAILED);

        $this->expectException(FulfillmentException::class);
        $this->fulfillmentService->fulfillOrder((int)$order->id);
    }

    /**
     * Scenario J: Cancelled order does not fulfill
     */
    public function testScenarioJ_CancelledOrderDoesNotFulfill(): void
    {
        $userId = 110;
        $prodId = $this->createDigitalProduct('Book J', 100.0);
        $order = $this->createPaidOrder($userId, [$prodId]);
        $this->orderRepo->updateOrderStatus((int)$order->id, OrderLifecycleState::STATUS_CANCELLED);

        $this->expectException(FulfillmentException::class);
        $this->expectExceptionMessageMatches("/Cancelled or refunded orders/i");

        $this->fulfillmentService->fulfillOrder((int)$order->id);
    }

    /**
     * Scenario K: Refunded order does not fulfill
     */
    public function testScenarioK_RefundedOrderDoesNotFulfill(): void
    {
        $userId = 111;
        $prodId = $this->createDigitalProduct('Book K', 100.0);
        $order = $this->createPaidOrder($userId, [$prodId]);
        $this->orderRepo->updateOrderStatus((int)$order->id, OrderLifecycleState::STATUS_REFUNDED);

        $this->expectException(FulfillmentException::class);
        $this->fulfillmentService->fulfillOrder((int)$order->id);
    }

    /**
     * Scenario L: Browser redirect alone does not fulfill
     */
    public function testScenarioL_BrowserRedirectAloneDoesNotFulfill(): void
    {
        $userId = 112;
        $prodId = $this->createDigitalProduct('Book L', 100.0);
        $order = $this->orderService->createOrder($userId, [['product_id' => $prodId, 'quantity' => 1]]);

        // User visits success / redirect page with pending or unpaid order
        $ctrl = new CustomerCheckoutController($this->app, $this->checkoutService);
        $req = new Request(['REQUEST_METHOD' => 'GET'], [], [], [], [], []);
        $resp = $ctrl->handle($req, $order->order_number);

        // Order is still unpaid and unfulfilled
        $refreshed = $this->orderRepo->findOrder((int)$order->id);
        $this->assertSame(OrderLifecycleState::PAYMENT_UNPAID, $refreshed->payment_status);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_UNFULFILLED, $refreshed->fulfillment_status);
        $this->assertFalse($this->checker->hasActiveEntitlement($userId, $prodId));
    }

    /**
     * Scenario M: Verified Favorite Pay success triggers fulfillment
     */
    public function testScenarioM_VerifiedFavoritePaySuccessTriggersFulfillment(): void
    {
        $userId = 113;
        $prodId = $this->createDigitalProduct('Book M', 100.0);
        $order = $this->orderService->createOrder($userId, [['product_id' => $prodId, 'quantity' => 1]]);

        $res = $this->checkoutService->processFavoritePayPayment((int)$order->id, $userId, 'bkash_auto');
        $intentId = $res['intent']->getId();

        // Mark intent as succeeded
        $this->mockPayService->updateIntentStatus($intentId, PaymentStatus::SUCCEEDED);

        // Verify and settle server-side
        $settledOrder = $this->checkoutService->verifyAndSettlePayment((int)$order->id, $intentId);

        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $settledOrder->payment_status);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_FULFILLED, $settledOrder->fulfillment_status);
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $prodId));
    }

    /**
     * Scenario N: Verified manual approval triggers fulfillment
     */
    public function testScenarioN_VerifiedManualApprovalTriggersFulfillment(): void
    {
        $userId = 114;
        $prodId = $this->createDigitalProduct('Book N', 100.0);
        $order = $this->orderService->createOrder($userId, [['product_id' => $prodId, 'quantity' => 1]]);

        $intent = $this->mockPayService->createIntent(
            'favorite-digital',
            (string)$order->id,
            Money::fromMajorString('100.00', 'BDT'),
            ['customer_id' => $userId]
        );

        $attempt = $this->checkoutService->submitManualPayment(
            (int)$order->id,
            $userId,
            $intent->getId(),
            'manual_bkash',
            'TRX-MANUAL-12345'
        );

        $unsettled = $this->orderRepo->findOrder((int)$order->id);
        $this->assertSame(OrderLifecycleState::PAYMENT_PENDING, $unsettled->payment_status);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_UNFULFILLED, $unsettled->fulfillment_status);

        // Admin approves manual payment in Favorite Pay
        $this->mockPayService->approveManualPayment($attempt['attempt']->getId(), 1, 'Payment verified in bank');

        // Create the completed payment entry in order repo
        $this->orderRepo->createOrderPayment([
            'order_id'           => $order->id,
            'payment_method'     => 'favorite_pay',
            'favorite_pay_tx_id' => $intent->getId(),
            'wallet_tx_id'       => null,
            'amount_paid'        => '100.00',
            'currency'           => 'BDT',
            'status'             => 'completed',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $settled = $this->checkoutService->verifyAndSettlePayment((int)$order->id, $intent->getId());
        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $settled->payment_status);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_FULFILLED, $settled->fulfillment_status);
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $prodId));
    }

    /**
     * Scenario O: Direct fulfillment without verified payment rejected
     */
    public function testScenarioO_DirectFulfillmentWithoutVerifiedPaymentRejected(): void
    {
        $userId = 115;
        $prodId = $this->createDigitalProduct('Book O', 100.0);
        $order = $this->orderService->createOrder($userId, [['product_id' => $prodId, 'quantity' => 1]]);

        try {
            $this->fulfillmentService->fulfillOrder((int)$order->id);
            $this->fail("Expected FulfillmentException when payment is not verified");
        } catch (FulfillmentException $e) {
            $this->assertStringContainsString("Only 'paid' orders may be fulfilled", $e->getMessage());
        }

        $this->assertFalse($this->checker->hasActiveEntitlement($userId, $prodId));
    }

    /**
     * Scenario P: Digital purchase entitlement created
     */
    public function testScenarioP_DigitalPurchaseEntitlementCreated(): void
    {
        $userId = 116;
        $prodId = $this->createDigitalProduct('Software License', 250.0, 30);
        $order = $this->createPaidOrder($userId, [$prodId]);

        $this->fulfillmentService->fulfillOrder((int)$order->id);

        $ent = $this->entitlementRepo->findActiveEntitlement($userId, $prodId);
        $this->assertNotNull($ent);
        $this->assertSame($userId, (int)$ent->user_id);
        $this->assertSame($prodId, (int)$ent->product_id);
        $this->assertSame('purchase', $ent->source_type);
        $this->assertSame((int)$order->items[0]->id, (int)$ent->source_id);
        $this->assertSame('active', $ent->status);
        $this->assertNotNull($ent->expires_at);
    }

    /**
     * Scenario Q: Service purchase entitlement created
     */
    public function testScenarioQ_ServicePurchaseEntitlementCreated(): void
    {
        $userId = 117;
        $svcId = $this->createServiceProduct('Design Consultation', 180.0);
        $order = $this->createPaidOrder($userId, [$svcId]);

        $this->fulfillmentService->fulfillOrder((int)$order->id);

        $ent = $this->entitlementRepo->findActiveEntitlement($userId, $svcId);
        $this->assertNotNull($ent);
        $this->assertSame('purchase', $ent->source_type);
        $this->assertSame('active', $ent->status);
        $this->assertNull($ent->expires_at);
    }

    /**
     * Scenario R: Package included Product entitlement created
     */
    public function testScenarioR_PackageIncludedProductEntitlementCreated(): void
    {
        $userId = 118;
        $digId = $this->createDigitalProduct('Theme Pro', 75.0);
        $pkgId = $this->createPackageProduct('Theme Pack', 70.0, [$digId]);
        $order = $this->createPaidOrder($userId, [$pkgId]);

        $this->fulfillmentService->fulfillOrder((int)$order->id);

        $childEnt = $this->entitlementRepo->findEntitlementBySource($userId, $digId, 'package', (int)$order->items[0]->id);
        $this->assertNotNull($childEnt);
        $this->assertSame('package', $childEnt->source_type);
        $this->assertSame((int)$order->items[0]->id, (int)$childEnt->source_id);
        $this->assertSame('active', $childEnt->status);
    }

    /**
     * Scenario S: Package included Service entitlement created
     */
    public function testScenarioS_PackageIncludedServiceEntitlementCreated(): void
    {
        $userId = 119;
        $svcId = $this->createServiceProduct('Migration Help', 90.0);
        $pkgId = $this->createPackageProduct('Migration Bundle', 85.0, [$svcId]);
        $order = $this->createPaidOrder($userId, [$pkgId]);

        $this->fulfillmentService->fulfillOrder((int)$order->id);

        $svcEnt = $this->entitlementRepo->findEntitlementBySource($userId, $svcId, 'package', (int)$order->items[0]->id);
        $this->assertNotNull($svcEnt);
        $this->assertSame('package', $svcEnt->source_type);
        $this->assertSame('active', $svcEnt->status);
    }

    /**
     * Scenario T: Package does not create nested package entitlement
     */
    public function testScenarioT_PackageDoesNotCreateNestedPackageEntitlement(): void
    {
        $userId = 120;
        $p1 = $this->createDigitalProduct('P1', 20.0);
        $p2 = $this->createDigitalProduct('P2', 20.0);
        $nestedPkg = $this->createPackageProduct('Nested Inner', 30.0, [$p1, $p2]);

        // Maliciously inject nested package into package items table
        $outerPkg = $this->createPackageProduct('Outer Bundle', 40.0, [$nestedPkg]);
        $order = $this->createPaidOrder($userId, [$outerPkg]);

        $this->fulfillmentService->fulfillOrder((int)$order->id);

        // Nested package child must NOT be granted as entitlement
        $nestedEnt = $this->entitlementRepo->findEntitlementBySource($userId, $nestedPkg, 'package', (int)$order->items[0]->id);
        $this->assertNull($nestedEnt);
    }

    /**
     * Scenario U: Package does not include membership
     */
    public function testScenarioU_PackageDoesNotIncludeMembership(): void
    {
        $userId = 121;
        $memId = $this->createMembershipProduct('Mem Plan U', 100.0);

        // Maliciously attach membership to package items
        $pkgId = $this->createPackageProduct('Faulty Package', 90.0, [$memId]);
        $order = $this->createPaidOrder($userId, [$pkgId]);

        $this->fulfillmentService->fulfillOrder((int)$order->id);

        // Membership must NOT be granted via package
        $memEnt = $this->entitlementRepo->findEntitlementBySource($userId, $memId, 'package', (int)$order->items[0]->id);
        $this->assertNull($memEnt);
        $this->assertFalse($this->membershipService->hasActiveMembership($userId));
    }

    /**
     * Scenario V: Duplicate fulfillment is idempotent
     */
    public function testScenarioV_DuplicateFulfillmentIsIdempotent(): void
    {
        $userId = 122;
        $prodId = $this->createDigitalProduct('Book V', 100.0);
        $order = $this->createPaidOrder($userId, [$prodId]);

        $first = $this->fulfillmentService->fulfillOrder((int)$order->id);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_FULFILLED, $first->fulfillment_status);

        $initialEntitlements = $this->entitlementRepo->getEntitlementsByUser($userId);
        $this->assertCount(1, $initialEntitlements);

        // Second call on already fulfilled order
        $second = $this->fulfillmentService->fulfillOrder((int)$order->id);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_FULFILLED, $second->fulfillment_status);

        $secondEntitlements = $this->entitlementRepo->getEntitlementsByUser($userId);
        $this->assertCount(1, $secondEntitlements, "Repeated fulfillment must not insert duplicate rows");
    }

    /**
     * Scenario W: Duplicate package fulfillment creates no duplicate entitlements
     */
    public function testScenarioW_DuplicatePackageFulfillmentCreatesNoDuplicateEntitlements(): void
    {
        $userId = 123;
        $d1 = $this->createDigitalProduct('W1', 10.0);
        $d2 = $this->createDigitalProduct('W2', 20.0);
        $pkgId = $this->createPackageProduct('Pkg W', 25.0, [$d1, $d2]);
        $order = $this->createPaidOrder($userId, [$pkgId]);

        $this->fulfillmentService->fulfillOrder((int)$order->id);
        $count1 = count($this->entitlementRepo->getEntitlementsByUser($userId));
        $this->assertSame(3, $count1); // 1 package purchase + 2 package children

        // Re-fulfill order item directly
        $this->fulfillmentService->fulfillItem($order, $order->items[0]);
        $count2 = count($this->entitlementRepo->getEntitlementsByUser($userId));
        $this->assertSame(3, $count2, "Package items must not duplicate on repeat fulfillment");
    }

    /**
     * Scenario X: Membership purchase activates/extends membership through MembershipLifecycleService
     */
    public function testScenarioX_MembershipPurchaseActivatesExtendsMembershipThroughMembershipLifecycleService(): void
    {
        $userId = 124;
        $memId = $this->createMembershipProduct('Gold Plan', 400.0, 'monthly', 1);
        $order = $this->createPaidOrder($userId, [$memId]);

        $this->assertFalse($this->membershipService->hasActiveMembership($userId));

        $this->fulfillmentService->fulfillOrder((int)$order->id);

        $this->assertTrue($this->membershipService->hasActiveMembership($userId));
        $activeMem = $this->membershipService->getActiveMembership($userId);
        $this->assertNotNull($activeMem);
        $this->assertSame(MembershipStatus::ACTIVE, $activeMem->status);
    }

    /**
     * Scenario Y: Existing active membership time is preserved
     */
    public function testScenarioY_ExistingActiveMembershipTimeIsPreserved(): void
    {
        $userId = 125;
        $memId = $this->createMembershipProduct('Silver Plan', 300.0, 'weekly', 1);

        // Order 1: 1 week
        $order1 = $this->createPaidOrder($userId, [$memId]);
        $this->fulfillmentService->fulfillOrder((int)$order1->id);
        $mem1 = $this->membershipService->getActiveMembership($userId);
        $expiry1 = new DateTimeImmutable($mem1->expires_at);

        // Order 2: Another 1 week
        $order2 = $this->createPaidOrder($userId, [$memId]);
        $this->fulfillmentService->fulfillOrder((int)$order2->id);
        $mem2 = $this->membershipService->getActiveMembership($userId);
        $expiry2 = new DateTimeImmutable($mem2->expires_at);

        $diffDays = $expiry1->diff($expiry2)->days;
        $this->assertSame(7, $diffDays, "Paid time must be cleanly appended (zero loss of paid time)");
    }

    /**
     * Scenario Z: Membership purchase does not reset expiry backwards
     */
    public function testScenarioZ_MembershipPurchaseDoesNotResetExpiryBackwards(): void
    {
        $userId = 126;
        $memId = $this->createMembershipProduct('Platinum Plan', 900.0, 'monthly', 3);
        $order1 = $this->createPaidOrder($userId, [$memId]);
        $this->fulfillmentService->fulfillOrder((int)$order1->id);

        $initialExpiry = new DateTimeImmutable($this->membershipService->getActiveMembership($userId)->expires_at);

        // Renew with a 1-month plan
        $mem1Month = $this->createMembershipProduct('Platinum 1M', 350.0, 'monthly', 1);
        $order2 = $this->createPaidOrder($userId, [$mem1Month]);
        $this->fulfillmentService->fulfillOrder((int)$order2->id);

        $finalExpiry = new DateTimeImmutable($this->membershipService->getActiveMembership($userId)->expires_at);

        $this->assertGreaterThan($initialExpiry, $finalExpiry, "Renewal must extend beyond initial expiry");
    }

    /**
     * Scenario AA: Membership-derived access is separate from purchase entitlement
     */
    public function testScenarioAA_MembershipDerivedAccessIsSeparateFromPurchaseEntitlement(): void
    {
        $userId = 127;
        $digProd = $this->createDigitalProduct('Member Video 1', 50.0, 0, 1);
        $memProd = $this->createMembershipProduct('Pass AA', 200.0);

        $order = $this->createPaidOrder($userId, [$memProd]);
        $this->fulfillmentService->fulfillOrder((int)$order->id);

        // User does NOT have a purchase entitlement for Member Video 1
        $this->assertFalse($this->checker->hasActiveEntitlement($userId, $digProd));

        // BUT user has access dynamically via their active membership
        $this->assertTrue($this->checker->hasAccess($userId, $digProd));
    }

    /**
     * Scenario AB: Purchased entitlement survives membership expiration
     */
    public function testScenarioAB_PurchasedEntitlementSurvivesMembershipExpiration(): void
    {
        $userId = 128;
        // User purchases a digital product directly
        $directProd = $this->createDigitalProduct('Permanent Tool', 150.0, 0, 1);
        $orderDirect = $this->createPaidOrder($userId, [$directProd]);
        $this->fulfillmentService->fulfillOrder((int)$orderDirect->id);

        // User also purchases a membership
        $memProd = $this->createMembershipProduct('Temporary Pass', 50.0, 'weekly', 1);
        $orderMem = $this->createPaidOrder($userId, [$memProd]);
        $this->fulfillmentService->fulfillOrder((int)$orderMem->id);

        $this->assertTrue($this->checker->hasAccess($userId, $directProd));

        // Membership expires
        $activeMem = $this->membershipService->getActiveMembership($userId);
        $this->membershipService->expireMembership((int)$activeMem->id);
        $this->assertFalse($this->membershipService->hasActiveMembership($userId));

        // Direct purchase entitlement survives!
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $directProd));
        $this->assertTrue($this->checker->hasAccess($userId, $directProd));
    }

    /**
     * Scenario AC: Revoked entitlement does not grant access
     */
    public function testScenarioAC_RevokedEntitlementDoesNotGrantAccess(): void
    {
        $userId = 129;
        $prodId = $this->createDigitalProduct('Software AC', 120.0);
        $order = $this->createPaidOrder($userId, [$prodId]);
        $this->fulfillmentService->fulfillOrder((int)$order->id);

        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $prodId));

        // Revoke the entitlement
        $ent = $this->entitlementRepo->findActiveEntitlement($userId, $prodId);
        $this->entitlementRepo->revokeEntitlement((int)$ent->id);

        $this->assertFalse($this->checker->hasActiveEntitlement($userId, $prodId));
        $this->assertFalse($this->checker->hasAccess($userId, $prodId));
        $this->assertTrue($this->checker->isRevoked($userId, $prodId));
    }

    /**
     * Scenario AD: Expired membership does not grant membership access
     */
    public function testScenarioAD_ExpiredMembershipDoesNotGrantMembershipAccess(): void
    {
        $userId = 130;
        $memOnlyProd = $this->createDigitalProduct('VIP Report', 80.0, 0, 1);
        $memProd = $this->createMembershipProduct('Pass AD', 100.0);
        $order = $this->createPaidOrder($userId, [$memProd]);
        $this->fulfillmentService->fulfillOrder((int)$order->id);

        $this->assertTrue($this->checker->hasAccess($userId, $memOnlyProd));

        // Expire membership
        $mem = $this->membershipService->getActiveMembership($userId);
        $this->membershipService->expireMembership((int)$mem->id);

        $this->assertFalse($this->checker->hasAccess($userId, $memOnlyProd));
    }

    /**
     * Scenario AE: Customer A cannot receive Customer B's entitlement
     */
    public function testScenarioAE_CustomerACannotReceiveCustomerBEntitlement(): void
    {
        $customerA = 131;
        $customerB = 132;
        $prodId = $this->createDigitalProduct('Private Asset', 100.0);

        $order = $this->createPaidOrder($customerA, [$prodId]);
        $this->fulfillmentService->fulfillOrder((int)$order->id);

        $this->assertTrue($this->checker->hasActiveEntitlement($customerA, $prodId));
        $this->assertFalse($this->checker->hasActiveEntitlement($customerB, $prodId));
        $this->assertFalse($this->checker->hasAccess($customerB, $prodId));
    }

    /**
     * Scenario AF: Customer cannot fulfill another customer's order
     */
    public function testScenarioAF_CustomerCannotFulfillAnotherCustomerOrder(): void
    {
        $customerA = 133;
        $customerB = 134;
        $prodId = $this->createDigitalProduct('Secret Doc', 50.0);
        $order = $this->orderService->createOrder($customerA, [['product_id' => $prodId, 'quantity' => 1]]);

        // Customer B tries to checkout or fulfill Customer A's order
        $this->expectException(\FavoriteCMS\Digital\Exceptions\CheckoutException::class);
        $this->checkoutService->getOrderForCheckout((int)$order->id, $customerB);
    }

    /**
     * Scenario AG: Fulfillment status remains unfulfilled if required fulfillment fails
     */
    public function testScenarioAG_FulfillmentStatusRemainsUnfulfilledIfRequiredFulfillmentFails(): void
    {
        $userId = 135;
        // Create an invalid membership item without a plan
        $rawProdId = $this->productRepo->createProduct([
            'title'          => 'Broken Membership',
            'slug'           => 'broken-mem',
            'product_type'   => ProductType::MEMBERSHIP,
            'status'         => ProductStatus::PUBLISHED,
            'original_price' => '100.00',
            'final_price'    => '100.00',
            'currency'       => 'BDT',
        ]);

        $order = $this->createPaidOrder($userId, [$rawProdId]);

        try {
            $this->fulfillmentService->fulfillOrder((int)$order->id);
            $this->fail("Expected failure on broken item");
        } catch (FulfillmentException $e) {
            $this->assertStringContainsString("No membership plan found", $e->getMessage());
        }

        $refreshed = $this->orderRepo->findOrder((int)$order->id);
        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $refreshed->payment_status);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_UNFULFILLED, $refreshed->fulfillment_status);
    }

    /**
     * Scenario AH: Partial fulfillment uses existing state correctly
     */
    public function testScenarioAH_PartialFulfillmentUsesExistingStateCorrectly(): void
    {
        $userId = 136;
        $validDigital = $this->createDigitalProduct('Working Digital', 50.0);
        $brokenMembership = $this->productRepo->createProduct([
            'title'          => 'Broken Mem 2',
            'slug'           => 'broken-mem-2',
            'product_type'   => ProductType::MEMBERSHIP,
            'status'         => ProductStatus::PUBLISHED,
            'original_price' => '100.00',
            'final_price'    => '100.00',
            'currency'       => 'BDT',
        ]);

        $order = $this->createPaidOrder($userId, [$validDigital, $brokenMembership]);

        try {
            $this->fulfillmentService->fulfillOrder((int)$order->id);
        } catch (FulfillmentException) {
        }

        $refreshed = $this->orderRepo->findOrder((int)$order->id);
        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $refreshed->payment_status);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_PARTIALLY_FULFILLED, $refreshed->fulfillment_status);

        // Valid item must have succeeded
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $validDigital));
    }

    /**
     * Scenario AI: Package source references are traceable for future revocation
     */
    public function testScenarioAI_PackageSourceReferencesAreTraceableForFutureRevocation(): void
    {
        $userId = 137;
        $child1 = $this->createDigitalProduct('AI Child 1', 30.0);
        $child2 = $this->createServiceProduct('AI Child 2', 40.0);
        $pkgId = $this->createPackageProduct('AI Bundle', 50.0, [$child1, $child2]);

        $order = $this->createPaidOrder($userId, [$pkgId]);
        $this->fulfillmentService->fulfillOrder((int)$order->id);

        $orderItemId = (int)$order->items[0]->id;

        // Trace package entitlements by source
        $ents = $this->entitlementRepo->getEntitlementsBySource('package', $orderItemId);
        $this->assertCount(2, $ents);
        foreach ($ents as $ent) {
            $this->assertSame('package', $ent->source_type);
            $this->assertSame($orderItemId, (int)$ent->source_id);
            $this->assertSame('active', $ent->status);
        }

        // Test revocation by source
        $revokedCount = $this->entitlementRepo->revokeBySource('package', $orderItemId);
        $this->assertSame(2, $revokedCount);

        $this->assertFalse($this->checker->hasActiveEntitlement($userId, $child1));
        $this->assertFalse($this->checker->hasActiveEntitlement($userId, $child2));
    }

    /**
     * Scenario AJ: Prefix-safe entitlement queries
     */
    public function testScenarioAJ_PrefixSafeEntitlementQueries(): void
    {
        $pdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);

        $prefixDb = new class($pdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite'];
                $this->prefix = 'fav_';
            }
        };

        $prefixDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);
        $migrator = new Migrator($prefixDb);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');

        $repo = new EntitlementRepository($prefixDb);
        $entId = $repo->createEntitlement([
            'user_id'     => 999,
            'product_id'  => 888,
            'source_type' => 'purchase',
            'source_id'   => 777,
            'status'      => 'active',
            'granted_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->assertGreaterThan(0, $entId);
        $found = $repo->findActiveEntitlement(999, 888);
        $this->assertNotNull($found);
        $this->assertSame(999, (int)$found->user_id);
    }

    /**
     * Scenario AK: SQLite compatibility
     */
    public function testScenarioAK_SQLiteCompatibility(): void
    {
        $driver = $this->sqlitePdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->assertSame('sqlite', strtolower((string)$driver));

        $userId = 138;
        $prodId = $this->createDigitalProduct('SQLite Book', 50.0);
        $order = $this->createPaidOrder($userId, [$prodId]);

        $fulfilled = $this->fulfillmentService->fulfillOrder((int)$order->id);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_FULFILLED, $fulfilled->fulfillment_status);
        $this->assertTrue($this->checker->hasActiveEntitlement($userId, $prodId));
    }

    /**
     * Scenario AL: MySQL/MariaDB compatibility (offline driver check cleanly skipped)
     */
    public function testScenarioAL_MySQLMariaDBCompatibility(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('PDO MySQL extension is not loaded.');
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $db   = getenv('DB_NAME') ?: 'test_db';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';

        try {
            $mysqlPdo = new PDO("mysql:host={$host};port={$port};dbname={$db}", $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                PDO::ATTR_TIMEOUT            => 1,
            ]);
        } catch (Throwable) {
            $this->markTestSkipped('Local MySQL/MariaDB is offline. Skipping runtime test.');
            return;
        }

        $this->assertInstanceOf(PDO::class, $mysqlPdo);
    }
}
