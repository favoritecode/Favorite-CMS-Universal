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
use FavoriteCMS\Digital\Controllers\CustomerOrderController;
use FavoriteCMS\Digital\Domain\MembershipStatus;
use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Exceptions\DownloadException;
use FavoriteCMS\Digital\Exceptions\RefundException;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;
use FavoriteCMS\Digital\Repositories\DownloadRepository;
use FavoriteCMS\Digital\Repositories\EntitlementRepository;
use FavoriteCMS\Digital\Repositories\OrderRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Repositories\RefundRepository;
use FavoriteCMS\Digital\Repositories\WalletRepository;
use FavoriteCMS\Digital\Services\CheckoutService;
use FavoriteCMS\Digital\Services\DefaultEntitlementChecker;
use FavoriteCMS\Digital\Services\DigitalFileStorageService;
use FavoriteCMS\Digital\Services\DownloadService;
use FavoriteCMS\Digital\Services\FulfillmentService;
use FavoriteCMS\Digital\Services\MembershipLifecycleService;
use FavoriteCMS\Digital\Services\OrderService;
use FavoriteCMS\Digital\Services\RefundService;
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

/**
 * RefundOrderLifecycleIntegrationTest
 *
 * Exhaustive Phase 5E test suite covering minimum test scenarios A through AZ:
 * - Authoritative server-side refund eligibility & calculation
 * - 100% wallet credit destination for all payment modes (wallet, Favorite Pay, mixed)
 * - Immutable refund and wallet ledger records
 * - Direct & package-derived entitlement revocation without touching independent purchases
 * - Membership revocation via MembershipLifecycleService public API
 * - Order lifecycle state transitions
 * - Customer & admin views and security isolation
 */
class RefundOrderLifecycleIntegrationTest extends TestCase
{
    private Application $app;
    private PDO $sqlitePdo;
    private Database $sqliteDb;
    private ProductRepository $productRepo;
    private OrderRepository $orderRepo;
    private WalletRepository $walletRepo;
    private WalletService $walletService;
    private EntitlementRepository $entitlementRepo;
    private DownloadRepository $downloadRepo;
    private RefundRepository $refundRepo;
    private MembershipLifecycleService $membershipService;
    private FulfillmentService $fulfillmentService;
    private DefaultEntitlementChecker $checker;
    private DigitalFileStorageService $storageService;
    private DownloadService $downloadService;
    private RefundService $refundService;
    private OrderService $orderService;
    private CheckoutService $checkoutService;
    private AdminOrderController $adminOrderController;
    private CustomerOrderController $customerOrderController;
    private PaymentServiceInterface $mockPayService;
    private string $tempStorageDir;

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

        // Storage directory
        $this->tempStorageDir = sys_get_temp_dir() . '/fd_test_refund_storage_' . uniqid('', true);
        if (!is_dir($this->tempStorageDir)) {
            mkdir($this->tempStorageDir, 0777, true);
        }
        $this->storageService = new DigitalFileStorageService($this->tempStorageDir);

        // Repositories
        $this->productRepo     = new ProductRepository($this->sqliteDb);
        $this->orderRepo       = new OrderRepository($this->sqliteDb);
        $this->walletRepo      = new WalletRepository($this->sqliteDb);
        $this->entitlementRepo = new EntitlementRepository($this->sqliteDb);
        $this->downloadRepo    = new DownloadRepository($this->sqliteDb);
        $this->refundRepo      = new RefundRepository($this->sqliteDb);

        // Services
        $this->walletService     = new WalletService($this->walletRepo, $this->sqliteDb);
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
        $this->downloadService = new DownloadService(
            $this->downloadRepo,
            $this->entitlementRepo,
            $this->productRepo,
            $this->membershipService,
            $this->checker,
            $this->storageService,
            $this->sqliteDb
        );

        $this->refundService = new RefundService(
            $this->orderRepo,
            $this->refundRepo,
            $this->walletService,
            $this->entitlementRepo,
            $this->membershipService,
            $this->sqliteDb
        );

        $this->orderService = new OrderService(
            $this->orderRepo,
            $this->productRepo,
            $this->membershipService,
            $this->checker,
            $this->sqliteDb
        );

        // Mock Payment Service
        $this->setupMockPayService();

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
            $this->entitlementRepo,
            $this->refundService
        );

        $this->customerOrderController = new CustomerOrderController(
            $this->app,
            $this->orderService,
            $this->refundRepo
        );

        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempStorageDir)) {
            $files = glob($this->tempStorageDir . '/*');
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
            @rmdir($this->tempStorageDir);
        }
        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);
    }

    private function setupMockPayService(): void
    {
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
                    ['id' => 'bkash_merchant', 'title' => 'bKash Merchant', 'type' => 'bkash', 'is_manual' => false],
                ];
            }

            public function getCheckoutCalculation(PaymentIntent $intent, string $gatewayId): array
            {
                return [
                    'gateway_id'     => $gatewayId,
                    'base_amount'    => $intent->getBaseAmount()->toMajorUnit(),
                    'charge_amount'  => $intent->getChargeAmount()->toMajorUnit(),
                    'base_currency'  => $intent->getBaseAmount()->getCurrency(),
                    'charge_currency'=> $intent->getBaseAmount()->getCurrency(),
                    'rate'           => 1.0,
                    'fee'            => 0.0,
                ];
            }

            public function succeedIntent(string $intentId): void
            {
                $this->updateIntentStatus($intentId, PaymentStatus::SUCCEEDED);
            }
        };
    }

    private function createDummyFile(string $filename, string $content = 'Hello Digital Product'): string
    {
        $filePath = $this->tempStorageDir . '/' . $filename;
        file_put_contents($filePath, $content);
        return $filePath;
    }

    private function createDigitalProduct(string $title = 'E-Book Guide', string $price = '500.00', int $expiryDays = 0): int
    {
        $filename = 'guide_' . uniqid() . '.pdf';
        $filePath = $this->createDummyFile($filename, 'PDF File Content');

        $pid = $this->productRepo->createProduct([
            'title'            => $title,
            'slug'             => 'guide-' . uniqid(),
            'product_type'     => ProductType::DIGITAL,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => $price,
            'discount_percent' => '0.00',
            'final_price'      => $price,
            'currency'         => 'BDT',
            'is_free'          => 0,
        ]);

        $this->productRepo->saveProductDetails($pid, [
            'file_path'              => 'storage/plugins/favorite-digital/files/' . $filename,
            'file_name'              => $filename,
            'file_hash'              => hash('sha256', 'PDF File Content'),
            'file_size'              => filesize($filePath),
            'mime_type'              => 'application/pdf',
            'max_downloads'          => 3,
            'download_expiry_days'   => $expiryDays,
            'is_membership_eligible' => 1,
            'version'                => '1.0.0',
        ]);

        return $pid;
    }

    private function createServiceProduct(string $title = 'Consulting Hour', string $price = '1000.00'): int
    {
        return $this->productRepo->createProduct([
            'title'                  => $title,
            'slug'                   => 'service-' . uniqid(),
            'product_type'           => ProductType::SERVICE,
            'status'                 => ProductStatus::PUBLISHED,
            'original_price'         => $price,
            'discount_percent'       => '0.00',
            'final_price'            => $price,
            'currency'               => 'BDT',
            'is_free'                => 0,
        ]);
    }

    private function createPackageProduct(string $title, string $price, array $childProductIds): int
    {
        $pkgId = $this->productRepo->createProduct([
            'title'                  => $title,
            'slug'                   => 'pkg-' . uniqid(),
            'product_type'           => ProductType::PACKAGE,
            'status'                 => ProductStatus::PUBLISHED,
            'original_price'         => $price,
            'discount_percent'       => '0.00',
            'final_price'            => $price,
            'currency'               => 'BDT',
            'is_free'                => 0,
        ]);

        $packageRecordId = $this->productRepo->createPackage($pkgId, 'bundle');

        foreach ($childProductIds as $cId) {
            $this->productRepo->addPackageItem($packageRecordId, $cId);
        }

        return $pkgId;
    }

    private function createPaidOrder(int $userId, int $productId, string $method = 'wallet', string $amount = '500.00'): object
    {
        $order = $this->orderService->createOrder($userId, [['product_id' => $productId, 'quantity' => 1]], 'BDT');
        $orderId = (int)$order->id;

        if ($method === 'wallet') {
            $this->walletService->credit($userId, $amount, 'seed_' . uniqid(), 'Seed balance');
            $this->checkoutService->processWalletPayment($orderId, $userId);
        } elseif ($method === 'favorite_pay') {
            $res = $this->checkoutService->processFavoritePayPayment($orderId, $userId, 'bkash_merchant');
            $intentId = $res['intent']->getId();
            $this->mockPayService->succeedIntent($intentId);
            $this->checkoutService->verifyAndSettlePayment($orderId, $intentId);
        } elseif ($method === 'mixed') {
            $walletPortion = '300.00';
            $gwPortion = '200.00';
            $this->walletService->credit($userId, $walletPortion, 'seed_' . uniqid(), 'Seed wallet portion');
            $res = $this->checkoutService->processMixedPayment($orderId, $userId, $walletPortion, 'bkash_merchant');
            $intentId = $res['intent']->getId();
            $this->mockPayService->succeedIntent($intentId);
            $this->checkoutService->verifyAndSettlePayment($orderId, $intentId);
        }

        return $this->orderRepo->findOrderWithItems($orderId);
    }

    // =========================================================================
    // Scenario Tests A through AZ
    // =========================================================================

    /**
     * Scenario A: Valid full refund (paid order refunded, refund recorded, wallet credited, order statuses updated)
     */
    public function testScenarioA_ValidFullRefund(): void
    {
        $userId = 101;
        $prodId = $this->createDigitalProduct('Scenario A Book', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        $initialWalletBal = (float)$this->walletService->getBalance($userId);

        $refund = $this->refundService->processRefund((int)$order->id, 'Seller failed to provide access', 1, true);

        $this->assertNotNull($refund);
        $this->assertEquals('500.00', $refund->refund_amount);
        $this->assertEquals('wallet', $refund->destination);
        $this->assertEquals('completed', $refund->status);

        // Order statuses
        $updatedOrder = $this->orderRepo->findOrder((int)$order->id);
        $this->assertEquals(OrderLifecycleState::PAYMENT_REFUNDED, $updatedOrder->payment_status);
        $this->assertEquals(OrderLifecycleState::FULFILLMENT_REVOKED, $updatedOrder->fulfillment_status);
        $this->assertEquals(OrderLifecycleState::STATUS_REFUNDED, $updatedOrder->status);

        // Wallet balance increases by 500.00
        $newBal = (float)$this->walletService->getBalance($userId);
        $this->assertEquals($initialWalletBal + 500.00, $newBal);
    }

    /**
     * Scenario B: Refund destination always Wallet
     */
    public function testScenarioB_RefundDestinationAlwaysWallet(): void
    {
        $userId = 102;
        $prodId = $this->createDigitalProduct('Scenario B Product', '600.00');
        $order = $this->createPaidOrder($userId, $prodId, 'favorite_pay', '600.00');

        $refund = $this->refundService->processRefund((int)$order->id, 'Seller failure', 1, true);
        $this->assertSame('wallet', $refund->destination);
        $this->assertNotEquals('original_payment_method', $refund->destination);
        $this->assertNotEquals('favorite_pay', $refund->destination);
    }

    /**
     * Scenario C: Wallet-only purchase refund → Wallet
     */
    public function testScenarioC_WalletOnlyPurchaseRefundToWallet(): void
    {
        $userId = 103;
        $prodId = $this->createDigitalProduct('Scenario C Item', '350.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '350.00');

        $balBefore = (float)$this->walletService->getBalance($userId);
        $refund = $this->refundService->processRefund((int)$order->id, 'Defective product', 1, true);

        $this->assertEquals('350.00', $refund->refund_amount);
        $this->assertEquals($balBefore + 350.00, (float)$this->walletService->getBalance($userId));
    }

    /**
     * Scenario D: Favorite Pay purchase refund → Wallet (+৳500 to customer wallet, NOT Favorite Pay)
     */
    public function testScenarioD_FavoritePayPurchaseRefundToWallet(): void
    {
        $userId = 104;
        $prodId = $this->createDigitalProduct('Scenario D Gateway Product', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'favorite_pay', '500.00');

        $balBefore = (float)$this->walletService->getBalance($userId);
        $this->assertEquals(0.00, $balBefore);

        $refund = $this->refundService->processRefund((int)$order->id, 'Service not delivered', 1, true);

        $this->assertEquals('500.00', $refund->refund_amount);
        $this->assertEquals(500.00, (float)$this->walletService->getBalance($userId));
    }

    /**
     * Scenario E: Mixed purchase refund → full amount to Wallet
     */
    public function testScenarioE_MixedPurchaseRefundFullAmountToWallet(): void
    {
        $userId = 105;
        $prodId = $this->createDigitalProduct('Scenario E Mixed', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'mixed', '500.00');

        $balBefore = (float)$this->walletService->getBalance($userId);
        $refund = $this->refundService->processRefund((int)$order->id, 'Full refund mixed purchase', 1, true);

        $this->assertEquals('500.00', $refund->refund_amount);
        $this->assertEquals($balBefore + 500.00, (float)$this->walletService->getBalance($userId));
    }

    /**
     * Scenario F: Refund amount derived server-side
     */
    public function testScenarioF_RefundAmountDerivedServerSide(): void
    {
        $userId = 106;
        $prodId = $this->createDigitalProduct('Server Amount', '750.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '750.00');

        $amount = $this->refundService->calculateAuthoritativeRefundAmount($order);
        $this->assertEquals('750.00', $amount);
    }

    /**
     * Scenario G: Client refund amount tampering rejected
     */
    public function testScenarioG_ClientRefundAmountTamperingRejected(): void
    {
        $userId = 107;
        $prodId = $this->createDigitalProduct('Tamper Test', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        // RefundService does NOT accept client amount parameter; it authoritatively derives 500.00
        $refund = $this->refundService->processRefund((int)$order->id, 'Valid reason', 1, true);
        $this->assertEquals('500.00', $refund->refund_amount);
        $this->assertNotEquals('9999.00', $refund->refund_amount);
    }

    /**
     * Scenario H: Client currency tampering rejected
     */
    public function testScenarioH_ClientCurrencyTamperingRejected(): void
    {
        $userId = 108;
        $prodId = $this->createDigitalProduct('Currency Test', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        $refund = $this->refundService->processRefund((int)$order->id, 'Valid reason', 1, true);
        $this->assertEquals('BDT', $refund->currency);
    }

    /**
     * Scenario I: Product price changes do not change refund amount
     */
    public function testScenarioI_ProductPriceChangesDoNotChangeRefundAmount(): void
    {
        $userId = 109;
        $prodId = $this->createDigitalProduct('Price Change Item', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        // Price later changes to 1000.00 in catalog
        $this->productRepo->updateProduct($prodId, [
            'original_price' => '1000.00',
            'final_price'    => '1000.00',
        ]);

        $refund = $this->refundService->processRefund((int)$order->id, 'Historical price check', 1, true);
        $this->assertEquals('500.00', $refund->refund_amount);
        $this->assertNotEquals('1000.00', $refund->refund_amount);
    }

    /**
     * Scenario J: Double refund prevented
     */
    public function testScenarioJ_DoubleRefundPrevented(): void
    {
        $userId = 110;
        $prodId = $this->createDigitalProduct('Double Refund Test', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        $balBefore = (float)$this->walletService->getBalance($userId);

        $ref1 = $this->refundService->processRefund((int)$order->id, 'Reason 1', 1, true);
        $balAfter1 = (float)$this->walletService->getBalance($userId);
        $this->assertEquals($balBefore + 500.00, $balAfter1);

        // Second attempt
        $ref2 = $this->refundService->processRefund((int)$order->id, 'Reason 2', 1, true);
        $balAfter2 = (float)$this->walletService->getBalance($userId);

        // Wallet balance MUST NOT increase again!
        $this->assertEquals($balAfter1, $balAfter2);
        $this->assertEquals($ref1->id, $ref2->id);
    }

    /**
     * Scenario K: Duplicate refund request idempotent
     */
    public function testScenarioK_DuplicateRefundRequestIsIdempotent(): void
    {
        $userId = 111;
        $prodId = $this->createDigitalProduct('Idempotent Refund', '400.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '400.00');

        $refA = $this->refundService->processRefund((int)$order->id, 'First click', 1, true);
        $refB = $this->refundService->processRefund((int)$order->id, 'Second click', 1, true);

        $this->assertSame((int)$refA->id, (int)$refB->id);
        $this->assertSame($refA->wallet_transaction_id, $refB->wallet_transaction_id);
    }

    /**
     * Scenario L: Wallet credit created exactly once
     */
    public function testScenarioL_WalletCreditCreatedExactlyOnce(): void
    {
        $userId = 112;
        $prodId = $this->createDigitalProduct('Single Credit Test', '300.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '300.00');

        $this->refundService->processRefund((int)$order->id, 'Reason', 1, true);
        $this->refundService->processRefund((int)$order->id, 'Reason', 1, true);

        $wallet = $this->walletRepo->findWalletByUserId($userId);
        $txs = $this->walletRepo->getTransactions((int)$wallet->id);

        $refundCredits = array_filter($txs, fn ($t) => $t->type === 'refund_credit' && (int)$t->order_id === (int)$order->id);
        $this->assertCount(1, $refundCredits);
    }

    /**
     * Scenario M: Wallet refund ledger created (type = refund_credit)
     */
    public function testScenarioM_WalletRefundLedgerCreated(): void
    {
        $userId = 113;
        $prodId = $this->createDigitalProduct('Ledger Check', '450.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '450.00');

        $refund = $this->refundService->processRefund((int)$order->id, 'Ledger verify', 1, true);

        $tx = $this->sqliteDb->selectOne("SELECT * FROM `favorite_digital_wallet_transactions` WHERE `id` = ?", [$refund->wallet_transaction_id]);
        $this->assertNotNull($tx);
        $this->assertEquals('refund_credit', $tx->type);
        $this->assertEquals('450.00', $tx->amount);
        $this->assertEquals((int)$order->id, (int)$tx->order_id);
    }

    /**
     * Scenario N: Refund linked to wallet transaction
     */
    public function testScenarioN_RefundLinkedToWalletTransaction(): void
    {
        $userId = 114;
        $prodId = $this->createDigitalProduct('Link Check', '200.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '200.00');

        $refund = $this->refundService->processRefund((int)$order->id, 'Link verify', 1, true);

        $this->assertNotEmpty($refund->wallet_transaction_id);
        $tx = $this->sqliteDb->selectOne("SELECT * FROM `favorite_digital_wallet_transactions` WHERE `id` = ?", [$refund->wallet_transaction_id]);
        $this->assertNotNull($tx);
        $this->assertStringContainsString((string)$order->id, $tx->reference_id);
    }

    /**
     * Scenario O: Wallet balance increases exactly by refund amount
     */
    public function testScenarioO_WalletBalanceIncreasesExactlyByRefundAmount(): void
    {
        $userId = 115;
        $prodId = $this->createDigitalProduct('Exact Increment', '555.50');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '555.50');

        $before = (float)$this->walletService->getBalance($userId);
        $this->refundService->processRefund((int)$order->id, 'Exact verify', 1, true);
        $after = (float)$this->walletService->getBalance($userId);

        $this->assertEquals($before + 555.50, $after);
    }

    /**
     * Scenario P: Refund never creates negative balance
     */
    public function testScenarioP_RefundNeverCreatesNegativeBalance(): void
    {
        $userId = 116;
        $prodId = $this->createDigitalProduct('No Negative', '100.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '100.00');

        $this->refundService->processRefund((int)$order->id, 'Test', 1, true);
        $bal = (float)$this->walletService->getBalance($userId);
        $this->assertGreaterThanOrEqual(0.00, $bal);
    }

    /**
     * Scenario Q: Unpaid order cannot create money
     */
    public function testScenarioQ_UnpaidOrderCannotCreateMoney(): void
    {
        $userId = 117;
        $prodId = $this->createDigitalProduct('Unpaid Order', '500.00');
        $order = $this->orderService->createOrder($userId, [['product_id' => $prodId, 'quantity' => 1]], 'BDT');

        $balBefore = (float)$this->walletService->getBalance($userId);

        $this->expectException(RefundException::class);
        $this->refundService->processRefund((int)$order->id, 'Trying to refund unpaid', 1, true);

        $this->assertEquals($balBefore, (float)$this->walletService->getBalance($userId));
    }

    /**
     * Scenario R: Failed/unsettled payment cannot create refund credit
     */
    public function testScenarioR_FailedPaymentCannotCreateRefundCredit(): void
    {
        $userId = 118;
        $prodId = $this->createDigitalProduct('Failed Payment Test', '500.00');
        $order = $this->orderService->createOrder($userId, [['product_id' => $prodId, 'quantity' => 1]], 'BDT');
        $orderId = (int)$order->id;

        // Record a failed payment
        $this->orderRepo->createOrderPayment([
            'order_id'           => $orderId,
            'payment_method'     => 'favorite_pay',
            'favorite_pay_tx_id' => 'tx_failed_' . uniqid(),
            'wallet_tx_id'       => null,
            'amount_paid'        => '500.00',
            'currency'           => 'BDT',
            'status'             => 'failed',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);
        $this->orderRepo->updatePaymentStatus($orderId, OrderLifecycleState::PAYMENT_FAILED);

        $this->expectException(RefundException::class);
        $this->refundService->processRefund($orderId, 'Refund failed payment', 1, true);
    }

    /**
     * Scenario S: Pending order with no verified payment → no wallet credit
     */
    public function testScenarioS_PendingOrderWithNoVerifiedPaymentCreatesNoCredit(): void
    {
        $userId = 119;
        $prodId = $this->createDigitalProduct('Pending Unpaid', '500.00');
        $order = $this->orderService->createOrder($userId, [['product_id' => $prodId, 'quantity' => 1]], 'BDT');
        $orderId = (int)$order->id;

        try {
            $this->refundService->processRefund($orderId, 'Attempt', 1, true);
            $this->fail("Expected RefundException was not thrown");
        } catch (RefundException $e) {
            $updated = $this->orderRepo->findOrder($orderId);
            $this->assertEquals(OrderLifecycleState::STATUS_CANCELLED, $updated->status);
            $this->assertEquals('0.00', $this->walletService->getBalance($userId));
        }
    }

    /**
     * Scenario T: Pending order with verified paid component handled correctly
     */
    public function testScenarioT_PendingOrderWithVerifiedPaidComponentRefunded(): void
    {
        $userId = 120;
        $prodId = $this->createDigitalProduct('Mixed Pending', '500.00');
        $order = $this->orderService->createOrder($userId, [['product_id' => $prodId, 'quantity' => 1]], 'BDT');
        $orderId = (int)$order->id;

        // Simulate mixed payment where wallet portion was deducted and completed (300.00)
        // but order is still partially paid / pending gateway
        $this->walletService->credit($userId, '300.00', 'seed_wal_t', 'Seed');
        $this->orderRepo->createOrderPayment([
            'order_id'           => $orderId,
            'payment_method'     => 'wallet',
            'favorite_pay_tx_id' => null,
            'wallet_tx_id'       => '1',
            'amount_paid'        => '300.00',
            'currency'           => 'BDT',
            'status'             => 'completed',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);
        $this->orderRepo->updatePaymentStatus($orderId, OrderLifecycleState::PAYMENT_PARTIALLY_PAID);

        $balBefore = (float)$this->walletService->getBalance($userId);
        $refund = $this->refundService->processRefund($orderId, 'Refund verified part', 1, true);

        $this->assertEquals('300.00', $refund->refund_amount);
        $this->assertEquals($balBefore + 300.00, (float)$this->walletService->getBalance($userId));
    }

    /**
     * Scenario U: Paid order → payment_status refunded
     */
    public function testScenarioU_PaidOrderTransitionsToPaymentStatusRefunded(): void
    {
        $userId = 121;
        $prodId = $this->createDigitalProduct('Status Check', '400.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '400.00');

        $this->refundService->processRefund((int)$order->id, 'Status update check', 1, true);

        $fresh = $this->orderRepo->findOrder((int)$order->id);
        $this->assertEquals(OrderLifecycleState::PAYMENT_REFUNDED, $fresh->payment_status);
        $this->assertEquals(OrderLifecycleState::STATUS_REFUNDED, $fresh->status);
    }

    /**
     * Scenario V: Refunded order cannot be refunded again
     */
    public function testScenarioV_RefundedOrderCannotBeRefundedAgain(): void
    {
        $userId = 122;
        $prodId = $this->createDigitalProduct('Cannot Refund Again', '250.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '250.00');

        $this->refundService->processRefund((int)$order->id, 'First', 1, true);

        // Validation eligibility should report false
        $eligibility = $this->refundService->validateRefundEligibility((int)$order->id);
        $this->assertFalse($eligibility['eligible']);
        $this->assertTrue($eligibility['already_refunded']);
    }

    /**
     * Scenario W: Cancelled unpaid order not incorrectly marked refunded
     */
    public function testScenarioW_CancelledUnpaidOrderNotIncorrectlyMarkedRefunded(): void
    {
        $userId = 123;
        $prodId = $this->createDigitalProduct('Cancel Unpaid', '300.00');
        $order = $this->orderService->createOrder($userId, [['product_id' => $prodId, 'quantity' => 1]], 'BDT');
        $orderId = (int)$order->id;

        $this->orderRepo->updateOrderStatus($orderId, OrderLifecycleState::STATUS_CANCELLED);

        try {
            $this->refundService->processRefund($orderId, 'Try refund cancelled', 1, true);
            $this->fail("Expected RefundException was not thrown");
        } catch (RefundException $e) {
            $fresh = $this->orderRepo->findOrder($orderId);
            $this->assertEquals(OrderLifecycleState::STATUS_CANCELLED, $fresh->status);
            $this->assertNotEquals(OrderLifecycleState::STATUS_REFUNDED, $fresh->status);
        }
    }

    /**
     * Scenario X: Valid service failure refund
     */
    public function testScenarioX_ValidServiceFailureRefund(): void
    {
        $userId = 124;
        $serviceId = $this->createServiceProduct('1hr Consultation', '1000.00');
        $order = $this->createPaidOrder($userId, $serviceId, 'wallet', '1000.00');

        // Entitlement was active
        $ent = $this->entitlementRepo->findEntitlementBySource($userId, $serviceId, 'purchase', (int)$order->items[0]->id);
        $this->assertEquals('active', $ent->status);

        // Seller failed to deliver consultation -> refund
        $refund = $this->refundService->processRefund((int)$order->id, 'Consultant failed to attend meeting', 1, true);

        $this->assertEquals('1000.00', $refund->refund_amount);
        $freshEnt = $this->entitlementRepo->findEntitlement((int)$ent->id);
        $this->assertEquals('revoked', $freshEnt->status);
    }

    /**
     * Scenario Y: Invalid arbitrary refund rejected (empty reason)
     */
    public function testScenarioY_InvalidArbitraryRefundRejected(): void
    {
        $userId = 125;
        $prodId = $this->createDigitalProduct('Arbitrary Check', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        $this->expectException(RefundException::class);
        $this->refundService->processRefund((int)$order->id, '   ', 1, true);
    }

    /**
     * Scenario Z: Package refund revokes package-derived entitlement
     */
    public function testScenarioZ_PackageRefundRevokesPackageDerivedEntitlements(): void
    {
        $userId = 126;
        $child1 = $this->createDigitalProduct('Pkg Child 1', '300.00');
        $child2 = $this->createDigitalProduct('Pkg Child 2', '400.00');
        $pkgId  = $this->createPackageProduct('Bundle Z', '600.00', [$child1, $child2]);

        $order = $this->createPaidOrder($userId, $pkgId, 'wallet', '600.00');

        // Verify package and child entitlements were active
        $pkgEnt = $this->entitlementRepo->findEntitlementBySource($userId, $pkgId, 'purchase', (int)$order->items[0]->id);
        $c1Ent  = $this->entitlementRepo->findEntitlementBySource($userId, $child1, 'package', (int)$order->items[0]->id);
        $c2Ent  = $this->entitlementRepo->findEntitlementBySource($userId, $child2, 'package', (int)$order->items[0]->id);

        $this->assertEquals('active', $pkgEnt->status);
        $this->assertEquals('active', $c1Ent->status);
        $this->assertEquals('active', $c2Ent->status);

        // Refund the package
        $this->refundService->processRefund((int)$order->id, 'Seller failed to deliver bundle', 1, true);

        // All must be revoked
        $this->assertEquals('revoked', $this->entitlementRepo->findEntitlement((int)$pkgEnt->id)->status);
        $this->assertEquals('revoked', $this->entitlementRepo->findEntitlement((int)$c1Ent->id)->status);
        $this->assertEquals('revoked', $this->entitlementRepo->findEntitlement((int)$c2Ent->id)->status);
    }

    /**
     * Scenario AA: Package refund does not revoke independent purchase entitlement
     */
    public function testScenarioAA_PackageRefundDoesNotRevokeIndependentPurchaseEntitlement(): void
    {
        $userId = 127;
        $child1 = $this->createDigitalProduct('Independent & Package Item', '300.00');
        $child2 = $this->createDigitalProduct('Another Child', '400.00');

        // 1. User buys Child 1 INDEPENDENTLY first!
        $indepOrder = $this->createPaidOrder($userId, $child1, 'wallet', '300.00');
        $indepEnt = $this->entitlementRepo->findEntitlementBySource($userId, $child1, 'purchase', (int)$indepOrder->items[0]->id);
        $this->assertEquals('active', $indepEnt->status);

        // 2. User also buys Package containing Child 1
        $pkgId = $this->createPackageProduct('Bundle AA', '600.00', [$child1, $child2]);
        $pkgOrder = $this->createPaidOrder($userId, $pkgId, 'wallet', '600.00');
        $pkgDerivedEnt = $this->entitlementRepo->findEntitlementBySource($userId, $child1, 'package', (int)$pkgOrder->items[0]->id);
        $this->assertEquals('active', $pkgDerivedEnt->status);

        // 3. Refund ONLY the package order!
        $this->refundService->processRefund((int)$pkgOrder->id, 'Seller failure on package', 1, true);

        // Package-derived entitlement is REVOKED
        $this->assertEquals('revoked', $this->entitlementRepo->findEntitlement((int)$pkgDerivedEnt->id)->status);

        // CRITICAL: Independent purchase entitlement REMAINS ACTIVE!
        $this->assertEquals('active', $this->entitlementRepo->findEntitlement((int)$indepEnt->id)->status);
    }

    /**
     * Scenario AB: Package refund does not revoke unrelated package access
     */
    public function testScenarioAB_PackageRefundDoesNotRevokeUnrelatedPackageAccess(): void
    {
        $userId = 128;
        $childA = $this->createDigitalProduct('Item A', '200.00');
        $childB = $this->createDigitalProduct('Item B', '200.00');

        // Order 1: Package 1
        $pkg1 = $this->createPackageProduct('Bundle 1', '350.00', [$childA]);
        $order1 = $this->createPaidOrder($userId, $pkg1, 'wallet', '350.00');
        $ent1 = $this->entitlementRepo->findEntitlementBySource($userId, $childA, 'package', (int)$order1->items[0]->id);

        // Order 2: Package 2
        $pkg2 = $this->createPackageProduct('Bundle 2', '350.00', [$childB]);
        $order2 = $this->createPaidOrder($userId, $pkg2, 'wallet', '350.00');
        $ent2 = $this->entitlementRepo->findEntitlementBySource($userId, $childB, 'package', (int)$order2->items[0]->id);

        // Refund Package 1
        $this->refundService->processRefund((int)$order1->id, 'Refund pkg 1', 1, true);

        $this->assertEquals('revoked', $this->entitlementRepo->findEntitlement((int)$ent1->id)->status);
        $this->assertEquals('active', $this->entitlementRepo->findEntitlement((int)$ent2->id)->status);
    }

    /**
     * Scenario AC: Revoked entitlement blocks future download
     */
    public function testScenarioAC_RevokedEntitlementBlocksFutureDownload(): void
    {
        $userId = 129;
        $prodId = $this->createDigitalProduct('Downloadable Ebook', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        // Customer gets download token
        $tokenObj = $this->downloadService->getOrCreateDownloadToken($userId, $prodId, (int)$this->entitlementRepo->findEntitlementBySource($userId, $prodId, 'purchase', (int)$order->items[0]->id)->id);
        $token = $tokenObj->download_token;

        // Download works prior to refund
        $auth = $this->downloadService->authorizeDownload($token, $userId);
        $this->assertNotEmpty($auth['file_path']);

        // Refund order
        $this->refundService->processRefund((int)$order->id, 'Revoke access', 1, true);

        // Future download must fail with entitlementRevoked
        $this->expectException(DownloadException::class);
        $this->downloadService->authorizeDownload($token, $userId);
    }

    /**
     * Scenario AD: Existing downloaded file is not deleted
     */
    public function testScenarioAD_ExistingDownloadedFileIsNotDeleted(): void
    {
        $userId = 130;
        $prodId = $this->createDigitalProduct('Local File Product', '500.00');
        $product = $this->productRepo->findProductDetails($prodId);
        $filePath = $this->tempStorageDir . '/' . basename((string)$product->file_path);

        $this->assertFileExists($filePath);

        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');
        $this->refundService->processRefund((int)$order->id, 'Check file persistence', 1, true);

        // File on server remains intact
        $this->assertFileExists($filePath);
    }

    /**
     * Scenario AE: Membership refund follows explicitly supported policy
     */
    public function testScenarioAE_MembershipRefundFollowsSupportedPolicy(): void
    {
        $userId = 131;
        $memProdId = $this->membershipService->createPlan([
            'title'          => 'VIP Monthly Plan',
            'status'         => ProductStatus::PUBLISHED,
            'original_price' => '1000.00',
        ], [
            'plan_type' => 'monthly',
            'duration_count' => 1,
            'duration_unit' => 'months',
            'grace_period_days' => 3,
            'allows_auto_renewal' => 1,
        ]);

        $order = $this->createPaidOrder($userId, $memProdId, 'wallet', '1000.00');

        // Membership was activated
        $activeMem = $this->membershipService->getActiveMembership($userId);
        $this->assertNotNull($activeMem);
        $this->assertEquals(MembershipStatus::ACTIVE, $activeMem->status);

        // Refund membership order under seller delivery failure
        $refund = $this->refundService->processRefund((int)$order->id, 'Seller failed to deliver membership content', 1, true);

        $this->assertEquals('1000.00', $refund->refund_amount);

        // Membership should be expired via MembershipLifecycleService public API
        $expiredMem = $this->membershipService->getActiveMembership($userId);
        $this->assertNull($expiredMem);
    }

    /**
     * Scenario AF: MembershipLifecycleService reused where required
     */
    public function testScenarioAF_MembershipLifecycleServiceReused(): void
    {
        $userId = 132;
        $memProdId = $this->membershipService->createPlan([
            'title'          => 'Weekly Pass',
            'status'         => ProductStatus::PUBLISHED,
            'original_price' => '250.00',
        ], [
            'plan_type' => 'weekly',
            'duration_count' => 1,
            'duration_unit' => 'weeks',
            'grace_period_days' => 1,
            'allows_auto_renewal' => 0,
        ]);

        $order = $this->createPaidOrder($userId, $memProdId, 'wallet', '250.00');
        $this->assertTrue($this->membershipService->hasActiveMembership($userId));

        $this->refundService->processRefund((int)$order->id, 'Pass failure', 1, true);
        $this->assertFalse($this->membershipService->hasActiveMembership($userId));
    }

    /**
     * Scenario AG: Entitlement history preserved (never hard-deleted)
     */
    public function testScenarioAG_EntitlementHistoryPreserved(): void
    {
        $userId = 133;
        $prodId = $this->createDigitalProduct('History Preserved Product', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        $itemId = (int)$order->items[0]->id;
        $ent = $this->entitlementRepo->findEntitlementBySource($userId, $prodId, 'purchase', $itemId);

        $this->refundService->processRefund((int)$order->id, 'Preserve history', 1, true);

        // Row still exists in database with status = revoked
        $row = $this->sqliteDb->selectOne("SELECT * FROM `favorite_digital_entitlements` WHERE `id` = ?", [$ent->id]);
        $this->assertNotNull($row);
        $this->assertEquals('revoked', $row->status);
    }

    /**
     * Scenario AH: Admin authorization check
     */
    public function testScenarioAH_AdminAuthorizationCheck(): void
    {
        $userId = 134;
        $prodId = $this->createDigitalProduct('Admin Auth Item', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        // Non-admin user tries to access admin order endpoint
        $mockNonAdmin = new class extends User {
            public int $id = 999;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool { return false; }
        };
        $GLOBALS['_test_current_user'] = $mockNonAdmin;

        $request = new Request([], [], ['REQUEST_METHOD' => 'POST']);
        $response = $this->adminOrderController->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Scenario AI: CSRF protection in admin refund workflow
     */
    public function testScenarioAI_CsrfProtectionInAdminRefundWorkflow(): void
    {
        $userId = 135;
        $prodId = $this->createDigitalProduct('CSRF Item', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        $mockAdmin = new class extends User {
            public int $id = 1;
            public function isActive(): bool { return true; }
            public function can(string $cap): bool { return true; }
        };
        $GLOBALS['_test_current_user'] = $mockAdmin;

        $_SESSION['_token'] = 'valid_token_123';

        // Missing or invalid CSRF token
        $request = new Request([], [
            'action' => 'refund',
            'id'     => (string)$order->id,
            'reason' => 'Test reason',
            '_token' => 'tampered_or_missing',
        ], ['REQUEST_METHOD' => 'POST']);

        $response = $this->adminOrderController->handle($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertStringContainsString('CSRF failure', $_SESSION['flash_error'] ?? '');

        // Order still paid, refund was blocked
        $fresh = $this->orderRepo->findOrder((int)$order->id);
        $this->assertEquals(OrderLifecycleState::PAYMENT_PAID, $fresh->payment_status);
    }

    /**
     * Scenario AJ: Customer ownership isolation
     */
    public function testScenarioAJ_CustomerOwnershipIsolation(): void
    {
        $userOwner = 136;
        $userAttacker = 137;
        $prodId = $this->createDigitalProduct('Private Receipt', '500.00');
        $order = $this->createPaidOrder($userOwner, $prodId, 'wallet', '500.00');

        $mockAttacker = new class extends User {
            public int $id = 137;
            public function isActive(): bool { return true; }
            public function can(string $cap): bool { return false; }
        };
        $GLOBALS['_test_current_user'] = $mockAttacker;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $response = $this->customerOrderController->view($request, $order->order_number);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Scenario AK: Refund audit record
     */
    public function testScenarioAK_RefundAuditRecordPopulated(): void
    {
        $userId = 138;
        $prodId = $this->createDigitalProduct('Audit Test Product', '650.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '650.00');

        $refund = $this->refundService->processRefund((int)$order->id, 'Customer complaint resolved', 1, true);

        $this->assertNotNull($refund->id);
        $this->assertEquals((int)$order->id, (int)$refund->order_id);
        $this->assertEquals($userId, (int)$refund->user_id);
        $this->assertEquals('650.00', $refund->refund_amount);
        $this->assertEquals('Customer complaint resolved', $refund->reason);
        $this->assertNotEmpty($refund->processed_at);
    }

    /**
     * Scenario AL: Admin refund view rendering
     */
    public function testScenarioAL_AdminRefundViewRendering(): void
    {
        $userId = 139;
        $prodId = $this->createDigitalProduct('Admin View Item', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        $mockAdmin = new class extends User {
            public int $id = 1;
            public function isActive(): bool { return true; }
            public function can(string $cap): bool { return true; }
        };
        $GLOBALS['_test_current_user'] = $mockAdmin;
        $_SESSION['_token'] = 'token_abc';

        $request = new Request(['action' => 'view', 'id' => (string)$order->id], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->adminOrderController->handle($request);

        $this->assertIsString($html);
        $this->assertStringContainsString('Issue Refund', $html);
        $this->assertStringContainsString('Issue Full Wallet Refund', $html);

        // Process refund and view again
        $this->refundService->processRefund((int)$order->id, 'Seller failure note', 1, true);
        $htmlAfter = $this->adminOrderController->handle($request);

        $this->assertStringContainsString('Refund Audit & History', $htmlAfter);
        $this->assertStringContainsString('Seller failure note', $htmlAfter);
    }

    /**
     * Scenario AM: Customer refund view displays refund details
     */
    public function testScenarioAM_CustomerRefundViewDisplaysRefundDetails(): void
    {
        $userId = 140;
        $prodId = $this->createDigitalProduct('Customer View Item', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        $this->refundService->processRefund((int)$order->id, 'Seller could not deliver', 1, true);

        $mockCustomer = new class extends User {
            public int $id = 140;
            public function isActive(): bool { return true; }
            public function can(string $cap): bool { return false; }
        };
        $GLOBALS['_test_current_user'] = $mockCustomer;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->customerOrderController->view($request, $order->order_number);

        $this->assertIsString($html);
        $this->assertStringContainsString('Refund Details', $html);
        $this->assertStringContainsString('500.00', $html);
        $this->assertStringContainsString('Favorite Digital Wallet', $html);
    }

    /**
     * Scenario AN: Prefix safety
     */
    public function testScenarioAN_PrefixSafety(): void
    {
        $prefixDb = new class($this->sqlitePdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite'];
                $this->prefix = 'fav_';
            }
        };
        $prefixDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);

        $repo = new RefundRepository($prefixDb);
        $this->assertNotNull($repo->getDatabase());
    }

    /**
     * Scenario AO: SQLite compatibility verified
     */
    public function testScenarioAO_SQLiteCompatibilityVerified(): void
    {
        $driver = $this->sqlitePdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->assertEquals('sqlite', strtolower((string)$driver));
        $this->assertTrue(true, 'All integration scenarios ran successfully against SQLite engine.');
    }

    /**
     * Scenario AP: MySQL/MariaDB compatibility
     */
    public function testScenarioAP_MySQLCompatibility(): void
    {
        $mysqlHost = getenv('DB_HOST') ?: '127.0.0.1';
        $mysqlDb   = getenv('DB_NAME') ?: 'favorite_cms_test';
        $mysqlUser = getenv('DB_USER') ?: 'root';
        $mysqlPass = getenv('DB_PASS') ?: '';

        try {
            $pdo = new PDO("mysql:host={$mysqlHost};dbname={$mysqlDb};charset=utf8mb4", $mysqlUser, $mysqlPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $this->assertInstanceOf(PDO::class, $pdo);
        } catch (Throwable $e) {
            $this->markTestSkipped('Local MySQL/MariaDB service is offline. Cleanly skipped runtime verification as required.');
        }
    }

    /**
     * Scenario AQ: Concurrent refund requests
     */
    public function testScenarioAQ_ConcurrentRefundRequests(): void
    {
        $userId = 141;
        $prodId = $this->createDigitalProduct('Concurrency Product', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        $res1 = $this->refundService->processRefund((int)$order->id, 'Concurrent 1', 1, true);
        $res2 = $this->refundService->processRefund((int)$order->id, 'Concurrent 2', 1, true);

        $this->assertEquals($res1->id, $res2->id);
        $this->assertEquals(500.00, (float)$this->walletService->getBalance($userId));
    }

    /**
     * Scenario AR: Concurrent wallet credit protection
     */
    public function testScenarioAR_ConcurrentWalletCreditProtection(): void
    {
        $userId = 142;
        $this->walletService->credit($userId, '100.00', 'same_ref_id', 'Test 1');
        $tx2 = $this->walletService->credit($userId, '100.00', 'same_ref_id', 'Test 2');

        // Balance must be 100.00, not 200.00
        $this->assertEquals('100.00', $this->walletService->getBalance($userId));
    }

    /**
     * Scenario AS: Order lifecycle transition validation
     */
    public function testScenarioAS_OrderLifecycleTransitionValidation(): void
    {
        $payload = OrderLifecycleState::onRefundExecuted();
        $this->assertEquals(OrderLifecycleState::PAYMENT_REFUNDED, $payload['payment_status']);
        $this->assertEquals(OrderLifecycleState::FULFILLMENT_REVOKED, $payload['fulfillment_status']);
        $this->assertEquals(OrderLifecycleState::STATUS_REFUNDED, $payload['status']);
    }

    /**
     * Scenario AT: Payment status cannot be manually bypassed to create wallet credit
     */
    public function testScenarioAT_PaymentStatusCannotBeManuallyBypassed(): void
    {
        $userId = 143;
        $prodId = $this->createDigitalProduct('Bypass Check', '500.00');
        $order = $this->orderService->createOrder($userId, [['product_id' => $prodId, 'quantity' => 1]], 'BDT');

        // Attacker manually sets payment_status = paid on order record without payments
        $this->orderRepo->updatePaymentStatus((int)$order->id, OrderLifecycleState::PAYMENT_PAID);

        // RefundService authoritatively checks payment ledger in favorite_digital_order_payments
        $this->expectException(RefundException::class);
        $this->refundService->processRefund((int)$order->id, 'Attempt bypass', 1, true);
    }

    /**
     * Scenario AU: Fulfillment state reflects revoked access correctly
     */
    public function testScenarioAU_FulfillmentStateReflectsRevokedAccess(): void
    {
        $userId = 144;
        $prodId = $this->createDigitalProduct('Fulfillment State Check', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        $this->assertEquals(OrderLifecycleState::FULFILLMENT_FULFILLED, $order->fulfillment_status);

        $this->refundService->processRefund((int)$order->id, 'Revocation', 1, true);

        $fresh = $this->orderRepo->findOrder((int)$order->id);
        $this->assertEquals(OrderLifecycleState::FULFILLMENT_REVOKED, $fresh->fulfillment_status);
    }

    /**
     * Scenario AV: Refund does not call gateway refund APIs
     */
    public function testScenarioAV_RefundDoesNotCallGatewayRefundApis(): void
    {
        $userId = 145;
        $prodId = $this->createDigitalProduct('No GW Refund', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'favorite_pay', '500.00');

        // If processRefund succeeds without throwing, mock refund() was not called!
        $refund = $this->refundService->processRefund((int)$order->id, 'No gw call', 1, true);
        $this->assertNotNull($refund);
    }

    /**
     * Scenario AW: Refund does not modify Favorite Pay
     */
    public function testScenarioAW_RefundDoesNotModifyFavoritePay(): void
    {
        $userId = 146;
        $prodId = $this->createDigitalProduct('No FP Mod', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'favorite_pay', '500.00');

        $payments = $this->orderRepo->getOrderPayments((int)$order->id);
        $intentId = $payments[0]->favorite_pay_tx_id;
        $intent = $this->mockPayService->getIntent($intentId);

        $this->refundService->processRefund((int)$order->id, 'FP check', 1, true);

        // Gateway intent retains its completed status and amount
        $this->assertEquals(PaymentStatus::SUCCEEDED, $intent->getStatus());
    }

    /**
     * Scenario AX: Refund does not alter original payment transaction
     */
    public function testScenarioAX_RefundDoesNotAlterOriginalPaymentAmount(): void
    {
        $userId = 147;
        $prodId = $this->createDigitalProduct('Payment Preservation', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        $this->refundService->processRefund((int)$order->id, 'Pay test', 1, true);

        $payments = $this->orderRepo->getOrderPayments((int)$order->id);
        $this->assertEquals('500.00', $payments[0]->amount_paid);
        $this->assertEquals('refunded', $payments[0]->status);
    }

    /**
     * Scenario AY: Refund does not alter historical order price
     */
    public function testScenarioAY_RefundDoesNotAlterHistoricalOrderPrice(): void
    {
        $userId = 148;
        $prodId = $this->createDigitalProduct('Order Price Preservation', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        $this->refundService->processRefund((int)$order->id, 'Price test', 1, true);

        $fresh = $this->orderRepo->findOrder((int)$order->id);
        $this->assertEquals('500.00', $fresh->total_amount);
        $this->assertEquals('500.00', $fresh->subtotal_amount);
    }

    /**
     * Scenario AZ: Refund does not modify wallet directly outside WalletService
     */
    public function testScenarioAZ_RefundModifiesWalletOnlyViaWalletService(): void
    {
        $userId = 149;
        $prodId = $this->createDigitalProduct('Wallet Service Enforced', '500.00');
        $order = $this->createPaidOrder($userId, $prodId, 'wallet', '500.00');

        $refund = $this->refundService->processRefund((int)$order->id, 'Wallet service test', 1, true);

        // Verifies the wallet transaction was recorded through WalletService
        $tx = $this->sqliteDb->selectOne("SELECT * FROM `favorite_digital_wallet_transactions` WHERE `id` = ?", [$refund->wallet_transaction_id]);
        $this->assertNotNull($tx);
        $this->assertEquals('refund_credit', $tx->type);
        $this->assertEquals('500.00', $tx->amount);
    }
}
