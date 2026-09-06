<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteDigital;

use DateTimeImmutable;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Controllers\AdminProductController;
use FavoriteCMS\Digital\Controllers\AdminServiceController;
use FavoriteCMS\Digital\Controllers\CustomerAccountController;
use FavoriteCMS\Digital\Controllers\CustomerCheckoutController;
use FavoriteCMS\Digital\Controllers\CustomerDownloadController;
use FavoriteCMS\Digital\Controllers\CustomerStorefrontController;
use FavoriteCMS\Digital\Support\OrderLifecycleState;
use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;
use FavoriteCMS\Digital\Repositories\DownloadRepository;
use FavoriteCMS\Digital\Repositories\EntitlementRepository;
use FavoriteCMS\Digital\Repositories\OrderRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Repositories\RefundRepository;
use FavoriteCMS\Digital\Repositories\WalletRepository;
use FavoriteCMS\Digital\Services\CheckoutService;
use FavoriteCMS\Digital\Services\CustomerAccountService;
use FavoriteCMS\Digital\Services\DefaultEntitlementChecker;
use FavoriteCMS\Digital\Services\DigitalFileStorageService;
use FavoriteCMS\Digital\Services\DownloadService;
use FavoriteCMS\Digital\Services\FulfillmentService;
use FavoriteCMS\Digital\Services\MembershipLifecycleService;
use FavoriteCMS\Digital\Services\OrderService;
use FavoriteCMS\Digital\Services\ProductManagementService;
use FavoriteCMS\Digital\Services\RefundService;
use FavoriteCMS\Digital\Services\StorefrontService;
use FavoriteCMS\Digital\Services\WalletService;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * FavoriteDigitalV101RegressionTest
 *
 * Exhaustive regression test suite covering Scenarios A through AQ for v1.0.1:
 * - Manual Payments (A - N)
 * - Cover Images (O - T)
 * - Digital Resources (U - AA)
 * - Broad File Formats (AB - AH)
 * - Security Boundaries (AI - AK)
 * - Public View & Storefront Guards (AL - AQ)
 */
class FavoriteDigitalV101RegressionTest extends TestCase
{
    private Application $app;
    private PDO $sqlitePdo;
    private Database $sqliteDb;
    private ProductRepository $productRepo;
    private OrderRepository $orderRepo;
    private EntitlementRepository $entitlementRepo;
    private DownloadRepository $downloadRepo;
    private RefundRepository $refundRepo;
    private WalletRepository $walletRepo;
    private WalletService $walletService;
    private MembershipLifecycleService $membershipService;
    private DigitalFileStorageService $storageService;
    private ProductManagementService $productService;
    private OrderService $orderService;
    private FulfillmentService $fulfillmentService;
    private CheckoutService $checkoutService;
    private CustomerCheckoutController $checkoutController;
    private StorefrontService $storefrontService;
    private CustomerStorefrontController $storefrontController;
    private CustomerAccountService $accountService;
    private CustomerAccountController $accountController;
    private DownloadService $downloadService;
    private PaymentServiceInterface $mockPayService;

    private string $tempStorageDir;
    private array $createdTempFiles = [];
    private array $intents = [];
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

        // Run all migrations (including 016)
        $migrator = new Migrator($this->sqliteDb);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');

        $this->app->singleton(Database::class, fn () => $this->sqliteDb);

        // Temp storage
        $this->tempStorageDir = sys_get_temp_dir() . '/fd_v101_test_' . uniqid('', true);
        @mkdir($this->tempStorageDir, 0755, true);

        $this->storageService = new DigitalFileStorageService(
            $this->tempStorageDir . '/files',
            104857600,
            $this->tempStorageDir . '/images',
            $this->tempStorageDir . '/proofs'
        );
        $this->productRepo = new ProductRepository($this->sqliteDb);
        $this->orderRepo = new OrderRepository($this->sqliteDb);
        $this->entitlementRepo = new EntitlementRepository($this->sqliteDb);
        $this->downloadRepo = new DownloadRepository($this->sqliteDb);
        $this->refundRepo = new RefundRepository($this->sqliteDb);
        $this->walletRepo = new WalletRepository($this->sqliteDb);

        $this->walletService = new WalletService($this->walletRepo, $this->sqliteDb);
        $this->membershipService = new MembershipLifecycleService($this->productRepo);
        $this->productService = new ProductManagementService($this->productRepo, $this->storageService);

        $this->orderService = new OrderService(
            $this->orderRepo,
            $this->productRepo,
            $this->membershipService,
            new DefaultEntitlementChecker($this->sqliteDb),
            $this->sqliteDb
        );

        $this->fulfillmentService = new FulfillmentService(
            $this->orderRepo,
            $this->entitlementRepo,
            $this->productRepo,
            $this->membershipService,
            $this->sqliteDb
        );

        $this->downloadService = new DownloadService(
            $this->downloadRepo,
            $this->entitlementRepo,
            $this->productRepo,
            $this->membershipService,
            new DefaultEntitlementChecker($this->sqliteDb),
            $this->storageService,
            $this->sqliteDb
        );

        $this->intents = [];
        $this->attempts = [];

        // Mock Favorite Pay
        $this->mockPayService = new class($this, $this->intents, $this->attempts) implements PaymentServiceInterface {
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
                    throw new InvalidArgumentException("Intent not found: {$intentId}");
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
                    $transactionReference,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    $details
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

            public function getAttempt(string $attemptId): ?PaymentAttempt
            {
                return $this->attempts[$attemptId] ?? null;
            }

            public function getAttemptsForIntent(string $intentId): array
            {
                return array_values(array_filter($this->attempts, fn($a) => $a->getIntentId() === $intentId));
            }

            public function getAvailablePaymentMethods(?string $currency = null): array
            {
                return [
                    ['id' => 'bkash_manual', 'title' => 'bKash Personal', 'type' => 'manual_bkash', 'is_manual' => true],
                    ['id' => 'nagad_manual', 'title' => 'Nagad Merchant', 'type' => 'manual_nagad', 'is_manual' => true],
                ];
            }

            public function getCheckoutCalculation(PaymentIntent $intent, string $gatewayId): array
            {
                return [
                    'gateway_id'      => $gatewayId,
                    'base_amount'     => $intent->getBaseAmount()->toMajorUnit(),
                    'charge_amount'   => $intent->getChargeAmount()->toMajorUnit(),
                    'base_currency'   => $intent->getBaseAmount()->getCurrency(),
                    'charge_currency' => $intent->getChargeAmount()->getCurrency(),
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

        $this->checkoutController = new CustomerCheckoutController(
            $this->app,
            $this->checkoutService,
            $this->storageService
        );

        $this->storefrontService = new StorefrontService(
            $this->productRepo,
            new DefaultEntitlementChecker($this->sqliteDb),
            $this->membershipService,
            $this->orderService,
            $this->checkoutService
        );

        $this->storefrontController = new CustomerStorefrontController(
            $this->app,
            $this->storefrontService
        );

        $this->accountService = new CustomerAccountService(
            $this->entitlementRepo,
            $this->productRepo,
            $this->orderRepo,
            $this->orderService,
            $this->membershipService,
            $this->downloadService,
            $this->refundRepo,
            $this->walletService,
            $this->sqliteDb
        );

        $this->accountController = new CustomerAccountController(
            $this->app,
            $this->accountService
        );

        $_SESSION = [
            'auth_user_id'   => 1,
            'auth_user_name' => 'Admin User',
            '_token'         => 'csrf_token_v101_test',
        ];
        $GLOBALS['_test_current_user_id'] = 1;
        $adminUser = new class extends User {
            public int $id = 1;
            public function isActive(): bool { return true; }
            public function can(string $cap): bool { return true; }
        };
        $GLOBALS['_test_current_user'] = $adminUser;
    }

    protected function tearDown(): void
    {
        foreach ($this->createdTempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        if (is_dir($this->tempStorageDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempStorageDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $f) {
                if ($f->isDir()) {
                    @rmdir($f->getRealPath());
                } else {
                    @unlink($f->getRealPath());
                }
            }
            @rmdir($this->tempStorageDir);
        }
        unset($GLOBALS['_test_current_user'], $GLOBALS['_test_current_user_id']);
        $_SESSION = [];
    }

    private function isAllowedExtension(string $filename): bool
    {
        try {
            $this->storageService->validateExtension($filename);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function createSampleProduct(string $type = ProductType::DIGITAL, float $price = 50.0, string $status = 'published'): object
    {
        $data = [
            'product_type'     => $type,
            'title'            => 'Test ' . ucfirst($type) . ' ' . uniqid(),
            'slug'             => 'test-' . $type . '-' . uniqid(),
            'original_price'   => $price,
            'discount_percent' => 0,
            'final_price'      => $price,
            'currency'         => 'BDT',
            'status'           => $status,
        ];
        $id = $this->productRepo->createProduct($data);
        if ($type === ProductType::DIGITAL) {
            $this->productRepo->saveProductDetails($id, [
                'file_path'     => 'test.zip',
                'file_name'     => 'test.zip',
                'file_size'     => 1024,
                'resource_type' => 'file',
            ]);
        } elseif ($type === ProductType::SERVICE) {
            $this->productRepo->saveServiceDetails($id, [
                'delivery_time_days' => 3,
                'service_scope'      => 'Full setup',
            ]);
        }
        return $this->productRepo->findProduct($id);
    }

    // =========================================================================
    // Manual Payment Scenarios (A - N)
    // =========================================================================

    public function testScenarioA_BkashConfiguredDisplay(): void
    {
        $bkash = new \FavoriteCMS\Pay\Gateways\ManualBangladeshGateway(
            'bkash_manual',
            'bKash Personal',
            \FavoriteCMS\Pay\Domain\PaymentMethodType::MANUAL_BKASH,
            ['account_number' => '01700000000', 'account_type' => 'personal']
        );
        $this->assertTrue($bkash->isConfigured());
        $this->assertSame('01700000000', $bkash->getInstructions()['account_number']);
        $this->assertSame('personal', $bkash->getInstructions()['account_type']);
    }

    public function testScenarioB_NagadConfiguredDisplay(): void
    {
        $nagad = new \FavoriteCMS\Pay\Gateways\ManualBangladeshGateway(
            'nagad_manual',
            'Nagad Merchant',
            \FavoriteCMS\Pay\Domain\PaymentMethodType::MANUAL_NAGAD,
            ['account_number' => '01800000000', 'account_type' => 'merchant']
        );
        $this->assertTrue($nagad->isConfigured());
        $this->assertSame('01800000000', $nagad->getInstructions()['account_number']);
        $this->assertSame('merchant', $nagad->getInstructions()['account_type']);
    }

    public function testScenarioC_RocketConfiguredDisplay(): void
    {
        $rocket = new \FavoriteCMS\Pay\Gateways\ManualBangladeshGateway(
            'rocket_manual',
            'Rocket Personal',
            \FavoriteCMS\Pay\Domain\PaymentMethodType::MANUAL_ROCKET,
            ['account_number' => '01900000000', 'account_type' => 'personal']
        );
        $this->assertTrue($rocket->isConfigured());
        $this->assertSame('01900000000', $rocket->getInstructions()['account_number']);
    }

    public function testScenarioD_BankConfiguredDisplay(): void
    {
        $bank = new \FavoriteCMS\Pay\Gateways\ManualBangladeshGateway(
            'bank_manual',
            'Bank Transfer',
            \FavoriteCMS\Pay\Domain\PaymentMethodType::MANUAL_BANK,
            ['bank_name' => 'City Bank PLC', 'account_number' => '123456789', 'branch_name' => 'Gulshan']
        );
        $this->assertTrue($bank->isConfigured());
        $this->assertSame('City Bank PLC', $bank->getInstructions()['bank_name']);
        $this->assertSame('123456789', $bank->getInstructions()['account_number']);
    }

    public function testScenarioE_MissingReceiverNumberHidesMethod(): void
    {
        $unconfigured = new \FavoriteCMS\Pay\Gateways\ManualBangladeshGateway(
            'bkash_empty',
            'bKash Empty',
            \FavoriteCMS\Pay\Domain\PaymentMethodType::MANUAL_BKASH,
            ['account_number' => '']
        );
        $this->assertFalse($unconfigured->isConfigured());
    }

    public function testScenarioF_SenderInformationRequired(): void
    {
        $prod = $this->createSampleProduct();
        $order = $this->orderService->createOrder(1, [['product_id' => $prod->id, 'quantity' => 1]]);

        // Submit without TrxID -> validation error
        $req = new Request(
            [],
            ['order_id' => $order->id, 'gateway' => 'bkash_manual', 'gateway_id' => 'bkash_manual', 'sender_account' => '01711111111', 'trx_id' => ''],
            ['REQUEST_METHOD' => 'POST']
        );
        $res = $this->checkoutController->handleManualSubmit($req, (string)$order->order_number, 1);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('Transaction ID (TrxID) is required', $_SESSION['flash_error'] ?? '');
    }

    public function testScenarioG_TrxIdRequired(): void
    {
        $prod = $this->createSampleProduct();
        $order = $this->orderService->createOrder(1, [['product_id' => $prod->id, 'quantity' => 1]]);

        $req = new Request(
            [],
            ['order_id' => $order->id, 'gateway' => 'bkash_manual', 'gateway_id' => 'bkash_manual', 'sender_account' => '', 'trx_id' => '   '],
            ['REQUEST_METHOD' => 'POST']
        );
        $res = $this->checkoutController->handleManualSubmit($req, (string)$order->order_number, 1);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertNotEmpty($_SESSION['flash_error'] ?? '');
    }

    public function testScenarioH_ProofUploadAccepted(): void
    {
        $tmpProof = tempnam(sys_get_temp_dir(), 'proof_') . '.png';
        file_put_contents($tmpProof, "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15c4");
        $this->createdTempFiles[] = $tmpProof;

        $fileUpload = [
            'name'     => 'screenshot.png',
            'type'     => 'image/png',
            'tmp_name' => $tmpProof,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmpProof),
        ];

        $meta = $this->storageService->storeProofUpload($fileUpload);
        $this->assertNotEmpty($meta['file_path']);
        $this->assertStringContainsString('proofs/', $meta['file_path']);
        $this->assertTrue(file_exists($this->storageService->getProofsDir() . '/' . basename($meta['file_path'])));
    }

    public function testScenarioI_InvalidProofRejected(): void
    {
        $tmpBadProof = tempnam(sys_get_temp_dir(), 'bad_proof_') . '.php';
        file_put_contents($tmpBadProof, '<?php echo "hack";');
        $this->createdTempFiles[] = $tmpBadProof;

        $fileUpload = [
            'name'     => 'exploit.php',
            'type'     => 'application/x-php',
            'tmp_name' => $tmpBadProof,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmpBadProof),
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->storageService->storeProofUpload($fileUpload);
    }

    public function testScenarioJ_CustomerOwnershipEnforced(): void
    {
        $prod = $this->createSampleProduct();
        $order = $this->orderService->createOrder(1, [['product_id' => $prod->id, 'quantity' => 1]]);

        // User 2 attempts to checkout User 1's order
        $GLOBALS['_test_current_user_id'] = 2;
        $req = new Request(
            [],
            ['order_id' => $order->id, 'gateway' => 'bkash_manual', 'gateway_id' => 'bkash_manual', 'trx_id' => 'TRX12345'],
            ['REQUEST_METHOD' => 'POST']
        );
        $res = $this->checkoutController->handleManualSubmit($req, (string)$order->order_number, 2);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('not authorized', $_SESSION['flash_error'] ?? '');
    }

    public function testScenarioK_ManualSubmissionRemainsAwaitingVerification(): void
    {
        $prod = $this->createSampleProduct();
        $order = $this->orderService->createOrder(1, [['product_id' => $prod->id, 'quantity' => 1]]);

        $req = new Request(
            [],
            ['order_id' => $order->id, 'gateway' => 'bkash_manual', 'gateway_id' => 'bkash_manual', 'sender_account' => '01712345678', 'trx_id' => 'TRX778899'],
            ['REQUEST_METHOD' => 'POST']
        );
        $res = $this->checkoutController->handleManualSubmit($req, (string)$order->order_number, 1);
        $this->assertSame(302, $res->getStatusCode());

        $freshOrder = $this->orderRepo->findOrder((int)$order->id);
        $this->assertSame(OrderLifecycleState::STATUS_PENDING, $freshOrder->status);
        $this->assertSame(OrderLifecycleState::PAYMENT_PENDING, $freshOrder->payment_status);

        // Verification attempt in mockPayService
        $this->assertNotEmpty($this->attempts);
        $attempt = end($this->attempts);
        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $attempt->getStatus());
        $this->assertSame('TRX778899', $attempt->getTransactionReference());
    }

    public function testScenarioL_AdminApprovalSettlesPayment(): void
    {
        $prod = $this->createSampleProduct();
        $order = $this->orderService->createOrder(1, [['product_id' => $prod->id, 'quantity' => 1]]);

        $req = new Request(
            [],
            ['order_id' => $order->id, 'gateway' => 'bkash_manual', 'gateway_id' => 'bkash_manual', 'sender_account' => '01712345678', 'trx_id' => 'TRX_SETTLE_1'],
            ['REQUEST_METHOD' => 'POST']
        );
        $this->checkoutController->handleManualSubmit($req, (string)$order->order_number, 1);

        // Admin approves
        $attempt = end($this->attempts);
        $this->mockPayService->approveManualPayment($attempt->getId(), 1, 'Verified via SMS');

        // Digital plugin triggers settlement
        $this->checkoutService->verifyAndSettlePayment((int)$order->id, $attempt->getIntentId());

        $settledOrder = $this->orderRepo->findOrder((int)$order->id);
        $this->assertSame(OrderLifecycleState::STATUS_COMPLETED, $settledOrder->status);
        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $settledOrder->payment_status);

        // Entitlement granted
        $activeEnt = $this->entitlementRepo->findActiveEntitlement(1, (int)$prod->id);
        $this->assertNotNull($activeEnt);
    }

    public function testScenarioM_ReplayDoesNotDoubleSettle(): void
    {
        $prod = $this->createSampleProduct();
        $order = $this->orderService->createOrder(1, [['product_id' => $prod->id, 'quantity' => 1]]);

        $req = new Request(
            [],
            ['order_id' => $order->id, 'gateway' => 'bkash_manual', 'gateway_id' => 'bkash_manual', 'sender_account' => '01712345678', 'trx_id' => 'TRX_REPLAY'],
            ['REQUEST_METHOD' => 'POST']
        );
        $this->checkoutController->handleManualSubmit($req, (string)$order->order_number, 1);
        $attempt = end($this->attempts);

        $this->checkoutService->verifyAndSettlePayment((int)$order->id, $attempt->getIntentId());
        $count1 = count($this->entitlementRepo->getEntitlementsByUser(1));

        // Replay
        $this->checkoutService->verifyAndSettlePayment((int)$order->id, $attempt->getIntentId());
        $count2 = count($this->entitlementRepo->getEntitlementsByUser(1));

        $this->assertSame($count1, $count2);
    }

    public function testScenarioN_NoEntitlementBeforeApproval(): void
    {
        $prod = $this->createSampleProduct();
        $order = $this->orderService->createOrder(1, [['product_id' => $prod->id, 'quantity' => 1]]);

        $req = new Request(
            [],
            ['order_id' => $order->id, 'gateway' => 'bkash_manual', 'gateway_id' => 'bkash_manual', 'sender_account' => '01712345678', 'trx_id' => 'TRX_PRE_ENT'],
            ['REQUEST_METHOD' => 'POST']
        );
        $this->checkoutController->handleManualSubmit($req, (string)$order->order_number, 1);

        // Entitlements should be strictly 0
        $ent = $this->entitlementRepo->findActiveEntitlement(1, (int)$prod->id);
        $this->assertNull($ent);
        $this->assertEmpty($this->entitlementRepo->getEntitlementsByUser(1));
    }

    // =========================================================================
    // Cover Image Scenarios (O - T)
    // =========================================================================

    public function testScenarioO_ProductImageUpload(): void
    {
        $tmpImg = tempnam(sys_get_temp_dir(), 'cov_') . '.jpg';
        file_put_contents($tmpImg, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00\xFF\xDB");
        $this->createdTempFiles[] = $tmpImg;

        $fileUpload = [
            'name'     => 'cover.jpg',
            'type'     => 'image/jpeg',
            'tmp_name' => $tmpImg,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmpImg),
        ];

        $prodId = $this->productService->createDigitalProduct(
            [
                'title'          => 'Product With Uploaded Cover',
                'original_price' => 20.0,
                'final_price'    => 20.0,
            ],
            [
                'file_name'     => 'doc.pdf',
                'file_path'     => 'files/doc.pdf',
                'file_size'     => 1024,
                'resource_type' => 'file',
            ],
            null,
            $fileUpload
        );
        $prod = $this->productRepo->findProduct($prodId);

        $this->assertNotNull($prod->cover_image_path);
        $this->assertStringContainsString('images/', $prod->cover_image_path);
    }

    public function testScenarioP_ProductImageUrl(): void
    {
        $prodId = $this->productService->createDigitalProduct(
            [
                'title'           => 'Product With Cover URL',
                'original_price'  => 20.0,
                'final_price'     => 20.0,
                'cover_image_url' => 'https://images.example.com/cover.png',
            ],
            [
                'file_name'     => 'doc.pdf',
                'file_path'     => 'files/doc.pdf',
                'file_size'     => 1024,
                'resource_type' => 'file',
            ]
        );
        $prod = $this->productRepo->findProduct($prodId);

        $this->assertSame('https://images.example.com/cover.png', $prod->cover_image_url);
    }

    public function testScenarioQ_ServiceImageUpload(): void
    {
        $tmpImg = tempnam(sys_get_temp_dir(), 'scov_') . '.png';
        file_put_contents($tmpImg, "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15c4");
        $this->createdTempFiles[] = $tmpImg;

        $fileUpload = [
            'name'     => 'service_banner.png',
            'type'     => 'image/png',
            'tmp_name' => $tmpImg,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmpImg),
        ];

        $serviceId = $this->productService->createService(
            [
                'title'               => 'Consulting Service',
                'original_price'      => 150.0,
                'final_price'         => 150.0,
            ],
            [
                'delivery_time_days'  => 5,
            ],
            $fileUpload
        );
        $service = $this->productRepo->findProduct($serviceId);

        $this->assertNotNull($service->cover_image_path);
        $this->assertStringContainsString('images/', $service->cover_image_path);
    }

    public function testScenarioR_ServiceImageUrl(): void
    {
        $serviceId = $this->productService->createService(
            [
                'title'               => 'Service URL Cover',
                'original_price'      => 150.0,
                'final_price'         => 150.0,
                'cover_image_url'     => 'https://cdn.example.com/service.jpg',
            ],
            [
                'delivery_time_days'  => 5,
            ]
        );
        $service = $this->productRepo->findProduct($serviceId);

        $this->assertSame('https://cdn.example.com/service.jpg', $service->cover_image_url);
    }

    public function testScenarioS_UnsafeImageRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->productService->createDigitalProduct(
            [
                'title'           => 'Malicious Cover URL',
                'original_price'  => 20.0,
                'final_price'     => 20.0,
                'cover_image_url' => 'javascript:alert("hacked")',
            ],
            [
                'file_name'     => 'doc.pdf',
                'file_path'     => 'files/doc.pdf',
                'file_size'     => 1024,
                'resource_type' => 'file',
            ]
        );
    }

    public function testScenarioT_BrokenExternalImageHandledSafely(): void
    {
        $tmpDoc = tempnam(sys_get_temp_dir(), 'doc_') . '.pdf';
        file_put_contents($tmpDoc, '%PDF-1.4 test');
        $this->createdTempFiles[] = $tmpDoc;

        // Valid safe HTTP URL to a 404 image must save without error and render safely
        $prodId = $this->productService->createDigitalProduct(
            [
                'title'           => 'Product With 404 Image',
                'original_price'  => 20.0,
                'final_price'     => 20.0,
                'status'          => 'published',
                'cover_image_url' => 'https://broken.example.com/nonexistent_image_404.jpg',
            ],
            [
                'file_name'     => 'doc.pdf',
                'file_path'     => $tmpDoc,
                'file_size'     => 1024,
                'resource_type' => 'file',
            ]
        );
        $prod = $this->productRepo->findProduct($prodId);
        $this->assertSame('https://broken.example.com/nonexistent_image_404.jpg', $prod->cover_image_url);

        $detail = $this->storefrontService->getProductDetail($prod->slug);
        $this->assertNotNull($detail);
        $this->assertSame('https://broken.example.com/nonexistent_image_404.jpg', $detail['product']->cover_image_url);
    }

    // =========================================================================
    // Resource Scenarios (U - AA)
    // =========================================================================

    public function testScenarioU_FileResourceWorks(): void
    {
        $tmpPkg = tempnam(sys_get_temp_dir(), 'pkg_') . '.zip';
        file_put_contents($tmpPkg, 'PK test zip');
        $this->createdTempFiles[] = $tmpPkg;

        $prodId = $this->productService->createDigitalProduct(
            [
                'title'          => 'File-Only Product',
                'original_price' => 10.0,
                'final_price'    => 10.0,
                'status'         => 'published',
            ],
            [
                'file_name'      => 'package.zip',
                'file_path'      => $tmpPkg,
                'file_size'      => 2048,
                'resource_type'  => 'file',
            ]
        );
        $prod = $this->productRepo->findProduct($prodId);

        $this->orderService->createOrder(1, [['product_id' => $prod->id, 'quantity' => 1]]);
        // Entitle user
        $this->entitlementRepo->createEntitlement([
            'user_id'     => 1,
            'product_id'  => $prod->id,
            'source_type' => 'direct',
            'status'      => 'active',
        ]);

        $lib = $this->accountService->getDigitalLibrary(1);
        $item = $lib['items'][0];
        $this->assertTrue($item['has_file_resource']);
        $this->assertFalse($item['has_url_resource']);
        $this->assertTrue($item['can_download']);
    }

    public function testScenarioV_UrlResourceWorks(): void
    {
        $prodId = $this->productService->createDigitalProduct(
            [
                'title'          => 'URL-Only Product',
                'original_price' => 10.0,
                'final_price'    => 10.0,
                'status'         => 'published',
            ],
            [
                'resource_type'  => 'url',
                'resource_url'   => 'https://learn.example.com/courses/101',
            ]
        );
        $prod = $this->productRepo->findProduct($prodId);

        $this->entitlementRepo->createEntitlement([
            'user_id'     => 1,
            'product_id'  => $prod->id,
            'source_type' => 'direct',
            'status'      => 'active',
        ]);

        $lib = $this->accountService->getDigitalLibrary(1);
        $item = $lib['items'][0];
        $this->assertFalse($item['has_file_resource']);
        $this->assertTrue($item['has_url_resource']);
        $this->assertSame('/account/resource/' . $prod->id, $item['external_resource_url']);

        // Authorize resource
        $authorizedUrl = $this->accountService->authorizeExternalResource(1, (int)$prod->id);
        $this->assertSame('https://learn.example.com/courses/101', $authorizedUrl);
    }

    public function testScenarioW_ExistingFileResourcesContinueWorking(): void
    {
        $id = $this->productRepo->createProduct([
            'product_type' => ProductType::DIGITAL,
            'title'        => 'Legacy File Product',
            'slug'         => 'legacy-file-prod',
            'final_price'  => 15.0,
            'status'       => 'published',
        ]);
        $this->productRepo->saveProductDetails($id, [
            'file_name' => 'legacy.pdf',
            'file_path' => 'files/legacy.pdf',
            'file_size' => 4096,
        ]);

        $details = $this->productRepo->findProductDetails($id);
        $this->assertSame('file', $details->resource_type);
        $this->assertNull($details->resource_url);
    }

    public function testScenarioX_UnsafeUrlRejected(): void
    {
        $unsafeUrls = [
            'javascript:alert(1)',
            'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
            'vbscript:msgbox(1)',
            'file:///etc/passwd',
            'ftp://ftp.example.com/file',
        ];

        foreach ($unsafeUrls as $badUrl) {
            try {
                $this->productService->createDigitalProduct(
                    [
                        'title'          => 'Bad URL Test',
                        'original_price' => 10.0,
                        'final_price'    => 10.0,
                    ],
                    [
                        'resource_type'  => 'url',
                        'resource_url'   => $badUrl,
                    ]
                );
                $this->fail("Expected exception for unsafe URL: {$badUrl}");
            } catch (InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function testScenarioY_UnauthorizedUrlResourceAccessDenied(): void
    {
        $prodId = $this->productService->createDigitalProduct(
            [
                'title'          => 'Private Online Course',
                'original_price' => 50.0,
                'final_price'    => 50.0,
                'status'         => 'published',
            ],
            [
                'resource_type'  => 'url',
                'resource_url'   => 'https://portal.example.com/members',
            ]
        );
        $prod = $this->productRepo->findProduct($prodId);

        // User 2 has not purchased this
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->accountService->authorizeExternalResource(2, (int)$prod->id);
    }

    public function testScenarioZ_RefundedProductResourceAccessDenied(): void
    {
        $prodId = $this->productService->createDigitalProduct(
            [
                'title'          => 'Refunded Online Course',
                'original_price' => 50.0,
                'final_price'    => 50.0,
                'status'         => 'published',
            ],
            [
                'resource_type'  => 'url',
                'resource_url'   => 'https://portal.example.com/members',
            ]
        );
        $prod = $this->productRepo->findProduct($prodId);

        $this->entitlementRepo->createEntitlement([
            'user_id'     => 1,
            'product_id'  => $prod->id,
            'source_type' => 'direct',
            'status'      => 'revoked', // Revoked due to refund
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->accountService->authorizeExternalResource(1, (int)$prod->id);
    }

    public function testScenarioAA_MembershipRequiredResourceDeniedAfterExpiry(): void
    {
        $prodId = $this->productService->createDigitalProduct(
            [
                'title'                  => 'Membership Only Webinar',
                'original_price'         => 30.0,
                'final_price'            => 30.0,
                'status'                 => 'published',
            ],
            [
                'resource_type'          => 'url',
                'resource_url'           => 'https://stream.example.com/live',
                'is_membership_eligible' => true,
            ]
        );
        $prod = $this->productRepo->findProduct($prodId);

        // User 1 has no active membership
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->accountService->authorizeExternalResource(1, (int)$prod->id);
    }

    // =========================================================================
    // Broad File Formats (AB - AH)
    // =========================================================================

    public function testScenarioAB_PdfUploadSupported(): void
    {
        $this->assertTrue($this->isAllowedExtension('document.pdf'));
    }

    public function testScenarioAC_ZipUploadSupported(): void
    {
        $this->assertTrue($this->isAllowedExtension('archive.zip'));
    }

    public function testScenarioAD_DocxUploadSupported(): void
    {
        $this->assertTrue($this->isAllowedExtension('report.docx'));
    }

    public function testScenarioAE_JpgUploadSupported(): void
    {
        $this->assertTrue($this->isAllowedExtension('photo.jpg'));
        $this->assertTrue($this->isAllowedExtension('image.jpeg'));
    }

    public function testScenarioAF_Mp3UploadSupported(): void
    {
        $this->assertTrue($this->isAllowedExtension('track.mp3'));
    }

    public function testScenarioAG_Mp4UploadSupported(): void
    {
        $this->assertTrue($this->isAllowedExtension('tutorial.mp4'));
    }

    public function testScenarioAH_AdditionalArchiveDocumentMediaTypesSupported(): void
    {
        $allowed = ['archive.7z', 'data.csv', 'book.epub', 'audio.flac', 'video.webm', 'sheet.xlsx'];
        foreach ($allowed as $f) {
            $this->assertTrue($this->isAllowedExtension($f), "Expected {$f} to be allowed");
        }
    }

    // =========================================================================
    // Security Boundaries (AI - AK)
    // =========================================================================

    public function testScenarioAI_PhpScriptUploadRejected(): void
    {
        $forbidden = [
            'shell.php', 'shell.phtml', 'shell.phar', 'script.cgi',
            'run.sh', 'virus.exe', 'batch.bat', 'cmd.cmd', 'lib.dll', 'hack.js'
        ];
        foreach ($forbidden as $f) {
            $this->assertFalse($this->isAllowedExtension($f), "Expected {$f} to be rejected");
        }
    }

    public function testScenarioAJ_PathTraversalRejected(): void
    {
        $badNames = [
            '../../etc/passwd',
            '..\\..\\windows\\system32',
            '/var/www/html/shell.zip',
        ];

        foreach ($badNames as $name) {
            $sanitized = $this->storageService->sanitizeFileName($name);
            $this->assertStringNotContainsString('/', $sanitized);
            $this->assertStringNotContainsString('\\', $sanitized);
            $this->assertStringNotContainsString('..', $sanitized);
        }
    }

    public function testScenarioAK_DoubleExtensionAttackRejected(): void
    {
        $doubleExts = [
            'exploit.php.jpg',
            'payload.phar.png',
            'script.sh.pdf',
            'shell.phtml.zip',
        ];

        foreach ($doubleExts as $f) {
            $this->assertFalse($this->isAllowedExtension($f), "Expected {$f} double-extension to be rejected");
        }
    }

    // =========================================================================
    // Public View & Storefront Guards (AL - AQ)
    // =========================================================================

    public function testScenarioAL_AdminViewOpensPublicDigitalProductPage(): void
    {
        $prod = $this->createSampleProduct(ProductType::DIGITAL, 25.0, 'published');
        // Storefront show responds 200 for published digital product
        $req = new Request([], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->show($req, $prod->slug);
        $this->assertIsString($html);
        $this->assertStringContainsString(htmlspecialchars($prod->title, ENT_QUOTES, 'UTF-8'), $html);
    }

    public function testScenarioAM_AdminViewOpensPublicServicePage(): void
    {
        $service = $this->createSampleProduct(ProductType::SERVICE, 75.0, 'published');
        $req = new Request([], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->show($req, $service->slug);
        $this->assertIsString($html);
        $this->assertStringContainsString(htmlspecialchars($service->title, ENT_QUOTES, 'UTF-8'), $html);
    }

    public function testScenarioAN_PackageViewUsesPublicStorefront(): void
    {
        $id = $this->productRepo->createProduct([
            'product_type' => ProductType::PACKAGE,
            'title'        => 'Ultimate Bundle',
            'slug'         => 'ultimate-bundle-test',
            'final_price'  => 99.0,
            'status'       => 'published',
        ]);
        $this->productRepo->createPackage($id, 'bundle');

        $req = new Request([], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->show($req, 'ultimate-bundle-test');
        $this->assertIsString($html);
        $this->assertStringContainsString('Ultimate Bundle', $html);
    }

    public function testScenarioAO_MembershipViewUsesPublicStorefront(): void
    {
        $id = $this->productRepo->createProduct([
            'product_type' => ProductType::MEMBERSHIP,
            'title'        => 'Pro Membership Plan',
            'slug'         => 'pro-membership-test',
            'final_price'  => 19.0,
            'status'       => 'published',
        ]);
        $this->productRepo->createMembershipPlan([
            'product_id'     => $id,
            'plan_type'      => 'monthly',
            'duration_count' => 1,
            'duration_unit'  => 'months',
        ]);

        $req = new Request([], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->show($req, 'pro-membership-test');
        $this->assertIsString($html);
        $this->assertStringContainsString('Pro Membership Plan', $html);
    }

    public function testScenarioAP_DraftViewCannotBypassPublication(): void
    {
        $draft = $this->createSampleProduct(ProductType::DIGITAL, 25.0, 'draft');
        $req = new Request([], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $res = $this->storefrontController->show($req, $draft->slug);
        $this->assertInstanceOf(Response::class, $res);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testScenarioAQ_ArchivedViewCannotBypassPublication(): void
    {
        $archived = $this->createSampleProduct(ProductType::DIGITAL, 25.0, 'archived');
        $req = new Request([], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $res = $this->storefrontController->show($req, $archived->slug);
        $this->assertInstanceOf(Response::class, $res);
        $this->assertSame(404, $res->getStatusCode());
    }
}
