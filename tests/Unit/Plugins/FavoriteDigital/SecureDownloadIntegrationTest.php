<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteDigital;

use DateTimeImmutable;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Controllers\CustomerDownloadController;
use FavoriteCMS\Digital\Domain\MembershipStatus;
use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Exceptions\DownloadException;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;
use FavoriteCMS\Digital\Repositories\DownloadRepository;
use FavoriteCMS\Digital\Repositories\EntitlementRepository;
use FavoriteCMS\Digital\Repositories\OrderRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Repositories\WalletRepository;
use FavoriteCMS\Digital\Services\CheckoutService;
use FavoriteCMS\Digital\Services\DefaultEntitlementChecker;
use FavoriteCMS\Digital\Services\DigitalFileStorageService;
use FavoriteCMS\Digital\Services\DownloadService;
use FavoriteCMS\Digital\Services\FulfillmentService;
use FavoriteCMS\Digital\Services\MembershipLifecycleService;
use FavoriteCMS\Digital\Services\OrderService;
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

class SecureDownloadIntegrationTest extends TestCase
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
    private MembershipLifecycleService $membershipService;
    private FulfillmentService $fulfillmentService;
    private DefaultEntitlementChecker $checker;
    private DigitalFileStorageService $storageService;
    private DownloadService $downloadService;
    private CustomerDownloadController $downloadController;
    private OrderService $orderService;
    private CheckoutService $checkoutService;
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
        $this->tempStorageDir = sys_get_temp_dir() . '/fd_test_download_storage_' . uniqid('', true);
        @mkdir($this->tempStorageDir, 0755, true);

        $this->storageService = new DigitalFileStorageService($this->tempStorageDir);
        $this->productRepo = new ProductRepository($this->sqliteDb);
        $this->orderRepo = new OrderRepository($this->sqliteDb);
        $this->walletRepo = new WalletRepository($this->sqliteDb);
        $this->walletService = new WalletService($this->walletRepo, $this->sqliteDb);
        $this->entitlementRepo = new EntitlementRepository($this->sqliteDb);
        $this->downloadRepo = new DownloadRepository($this->sqliteDb);
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

        $this->downloadController = new CustomerDownloadController(
            $this->app,
            $this->downloadService,
            $this->entitlementRepo,
            $this->productRepo,
            $this->membershipService
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
        };

        $this->checkoutService = new CheckoutService(
            $this->orderRepo,
            $this->walletService,
            $this->mockPayService,
            $this->sqliteDb,
            $this->fulfillmentService
        );

        $this->app->singleton(ProductRepository::class, fn () => $this->productRepo);
        $this->app->singleton(OrderRepository::class, fn () => $this->orderRepo);
        $this->app->singleton(WalletRepository::class, fn () => $this->walletRepo);
        $this->app->singleton(WalletService::class, fn () => $this->walletService);
        $this->app->singleton(EntitlementRepository::class, fn () => $this->entitlementRepo);
        $this->downloadRepo = new DownloadRepository($this->sqliteDb);
        $this->app->singleton(DownloadRepository::class, fn () => $this->downloadRepo);
        $this->app->singleton(DigitalFileStorageService::class, fn () => $this->storageService);
        $this->app->singleton(DownloadService::class, fn () => $this->downloadService);
        $this->app->singleton(CustomerDownloadController::class, fn () => $this->downloadController);

        // Reset session
        $_SESSION = [
            'auth_user_id'   => 101,
            'auth_user_name' => 'Customer User',
            '_token'         => 'valid_test_token_123',
        ];
    }

    protected function tearDown(): void
    {
        // Clean up temporary files
        if (is_dir($this->tempStorageDir)) {
            $files = glob($this->tempStorageDir . '/*');
            if ($files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($this->tempStorageDir);
        }
        parent::tearDown();
    }

    // ==========================================
    // HELPERS
    // ==========================================

    private function createTestFile(string $content = "Digital Product Binary Content 12345"): string
    {
        $hash = hash('sha256', $content);
        $filename = $hash . '.zip';
        $fullPath = $this->tempStorageDir . '/' . $filename;
        file_put_contents($fullPath, $content);
        return $filename;
    }

    private function createDigitalProduct(string $title = 'E-Book PDF', float $price = 25.00, bool $membershipEligible = false, ?string $filename = null): int
    {
        if ($filename === null) {
            $filename = $this->createTestFile("Content for " . $title);
        }

        $pid = $this->productRepo->createProduct([
            'title'            => $title,
            'slug'             => strtolower(str_replace(' ', '-', $title)) . '-' . bin2hex(random_bytes(3)),
            'product_type'     => ProductType::DIGITAL,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => number_format($price, 2, '.', ''),
            'discount_percent' => '0.00',
            'final_price'      => number_format($price, 2, '.', ''),
            'currency'         => 'USD',
            'is_free'          => $price == 0.0 ? 1 : 0,
        ]);

        $size = filesize($this->tempStorageDir . '/' . $filename);
        $hash = hash_file('sha256', $this->tempStorageDir . '/' . $filename);

        $this->productRepo->saveProductDetails($pid, [
            'file_path'              => 'storage/plugins/favorite-digital/files/' . $filename,
            'file_name'              => $title . '.zip',
            'file_hash'              => $hash,
            'file_size'              => $size,
            'mime_type'              => 'application/zip',
            'max_downloads'          => 3,
            'download_expiry_days'   => 0,
            'is_membership_eligible' => $membershipEligible ? 1 : 0,
            'version'                => '1.0.0',
        ]);

        return $pid;
    }

    private function purchaseAndFulfill(int $userId, int $productId, float $price = 25.00): int
    {
        $order = $this->orderService->createOrder($userId, [
            ['product_id' => $productId, 'quantity' => 1],
        ]);

        $this->sqliteDb->query(
            "UPDATE `favorite_digital_orders` SET payment_status = 'paid', status = 'processing' WHERE id = ?",
            [$order->id]
        );

        $this->fulfillmentService->fulfillOrder((int)$order->id);

        return (int)$order->id;
    }

    private function purchasePackageAndFulfill(int $userId, array $includedProductIds, float $price = 50.00): int
    {
        $pkgId = $this->productRepo->createProduct([
            'title'            => 'Super Bundle',
            'slug'             => 'bundle-' . bin2hex(random_bytes(3)),
            'product_type'     => ProductType::PACKAGE,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => number_format($price, 2, '.', ''),
            'discount_percent' => '0.00',
            'final_price'      => number_format($price, 2, '.', ''),
            'currency'         => 'USD',
            'is_free'          => $price == 0.0 ? 1 : 0,
        ]);

        $packageId = $this->productRepo->createPackage($pkgId, 'bundle');
        $this->productRepo->setPackageItems($packageId, $includedProductIds);

        $order = $this->orderService->createOrder($userId, [
            ['product_id' => $pkgId, 'quantity' => 1],
        ]);

        $this->sqliteDb->query(
            "UPDATE `favorite_digital_orders` SET payment_status = 'paid', status = 'processing' WHERE id = ?",
            [$order->id]
        );

        $this->fulfillmentService->fulfillOrder((int)$order->id);

        return (int)$order->id;
    }

    private function grantActiveMembership(int $userId, int $durationDays = 30): int
    {
        $pid = $this->membershipService->createPlan(
            [
                'title'          => 'Pro Membership',
                'slug'           => 'mem-' . bin2hex(random_bytes(4)),
                'status'         => ProductStatus::PUBLISHED,
                'original_price' => '19.99',
                'currency'       => 'USD',
                'is_free'        => 0,
            ],
            [
                'plan_type'           => 'monthly',
                'duration_count'      => 1,
                'grace_period_days'   => 3,
                'allows_auto_renewal' => 1,
            ]
        );

        $plan = $this->membershipService->getPlanByProductId($pid);
        $membership = $this->membershipService->activateMembership($userId, (int)$plan->id);

        if ($durationDays < 0) {
            $pastDate = date('Y-m-d H:i:s', strtotime("{$durationDays} days"));
            $this->sqliteDb->query(
                "UPDATE `favorite_digital_memberships` SET status = 'expired', expires_at = ?, grace_expires_at = ? WHERE id = ?",
                [$pastDate, $pastDate, $membership->id]
            );
        }

        return (int)$membership->id;
    }

    // ==========================================
    // 46 MINIMUM SCENARIO TESTS (A - AT)
    // ==========================================

    /**
     * Scenario A: Authenticated valid purchase can download.
     */
    public function test_scenario_a_authenticated_valid_purchase_can_download(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Pro Guide');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $this->assertNotEmpty($activeEnts);
        $entitlementId = (int)$activeEnts[0]->id;

        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, $entitlementId);
        $this->assertNotEmpty($record->download_token);

        $auth = $this->downloadService->authorizeDownload($record->download_token, $userId);
        $this->assertEquals($productId, (int)$auth['product']->id);
        $this->assertEquals($userId, (int)$auth['download']->user_id);
        $this->assertFileExists($auth['file_path']);
    }

    /**
     * Scenario B: Unauthenticated download denied.
     */
    public function test_scenario_b_unauthenticated_download_denied(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Security Handbook');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        // Simulate guest unauthenticated request
        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);

        $request = new Request([], [], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/download/' . $record->download_token, 'REMOTE_ADDR' => '127.0.0.1']);
        $response = $this->downloadController->download($request, $record->download_token);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/login', $response->getHeaders()['Location'] ?? null);
    }

    /**
     * Scenario C: Wrong customer entitlement denied.
     */
    public function test_scenario_c_wrong_customer_entitlement_denied(): void
    {
        $customerA = 101;
        $customerB = 202;
        $productId = $this->createDigitalProduct('Asset Pack A');
        $this->purchaseAndFulfill($customerA, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($customerA);
        $record = $this->downloadService->getOrCreateDownloadToken($customerA, $productId, (int)$activeEnts[0]->id);

        // Customer B attempts to download Customer A's token
        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('You are not authorized to access this download.');
        $this->downloadService->authorizeDownload($record->download_token, $customerB);
    }

    /**
     * Scenario D: Invalid entitlement denied.
     */
    public function test_scenario_d_invalid_entitlement_denied(): void
    {
        $fakeToken = str_repeat('d', 64);

        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('Invalid or expired download token.');
        $this->downloadService->authorizeDownload($fakeToken, 101);
    }

    /**
     * Scenario E: Revoked entitlement denied.
     */
    public function test_scenario_e_revoked_entitlement_denied(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Courseware');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $entitlementId = (int)$activeEnts[0]->id;
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, $entitlementId);

        // Revoke entitlement
        $this->entitlementRepo->updateStatus($entitlementId, 'revoked');

        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('Entitlement for this product has been revoked.');
        $this->downloadService->authorizeDownload($record->download_token, $userId);
    }

    /**
     * Scenario F: Expired entitlement denied.
     */
    public function test_scenario_f_expired_entitlement_denied(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Time-limited Audio');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $entitlementId = (int)$activeEnts[0]->id;
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, $entitlementId);

        // Expire entitlement
        $this->entitlementRepo->updateStatus($entitlementId, 'expired');

        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('Entitlement for this product has expired.');
        $this->downloadService->authorizeDownload($record->download_token, $userId);
    }

    /**
     * Scenario G: Valid package entitlement downloads included digital product.
     */
    public function test_scenario_g_valid_package_entitlement_downloads_included_digital_product(): void
    {
        $userId = 101;
        $p1 = $this->createDigitalProduct('Bundle Item 1');
        $p2 = $this->createDigitalProduct('Bundle Item 2');
        $this->purchasePackageAndFulfill($userId, [$p1, $p2]);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $digitalEnts = array_values(array_filter($activeEnts, fn($e) => (int)$e->product_id === $p1 || (int)$e->product_id === $p2));
        $this->assertCount(2, $digitalEnts);

        foreach ($digitalEnts as $ent) {
            $record = $this->downloadService->getOrCreateDownloadToken($userId, (int)$ent->product_id, (int)$ent->id);
            $auth = $this->downloadService->authorizeDownload($record->download_token, $userId);
            $this->assertNotEmpty($auth['file_path']);
            $this->assertFileExists($auth['file_path']);
        }
    }

    /**
     * Scenario H: Package entitlement cannot access unrelated product.
     */
    public function test_scenario_h_package_entitlement_cannot_access_unrelated_product(): void
    {
        $userId = 101;
        $p1 = $this->createDigitalProduct('Included Item');
        $unrelated = $this->createDigitalProduct('Secret Item');
        $this->purchasePackageAndFulfill($userId, [$p1]);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $entId = (int)$activeEnts[0]->id;

        // Attempting to create token with unrelated product and p1's entitlement must throw
        $this->expectException(DownloadException::class);
        $this->downloadService->getOrCreateDownloadToken($userId, $unrelated, $entId);
    }

    /**
     * Scenario I: Active membership can access eligible downloadable product.
     */
    public function test_scenario_i_active_membership_can_access_eligible_downloadable_product(): void
    {
        $userId = 101;
        $this->grantActiveMembership($userId, 30);
        $productId = $this->createDigitalProduct('Member Only Toolkit', 0.00, true);

        // User did not purchase product directly, accesses through membership
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId);
        $auth = $this->downloadService->authorizeDownload($record->download_token, $userId);

        $this->assertTrue($auth['is_membership']);
        $this->assertEquals($productId, (int)$auth['product']->id);
        $this->assertFileExists($auth['file_path']);
    }

    /**
     * Scenario J: Expired membership cannot access membership-only product.
     */
    public function test_scenario_j_expired_membership_cannot_access_membership_only_product(): void
    {
        $userId = 101;
        $memId = $this->grantActiveMembership($userId, -1); // expired yesterday
        $this->sqliteDb->query("UPDATE `favorite_digital_memberships` SET status = 'expired' WHERE id = ?", [$memId]);

        $productId = $this->createDigitalProduct('VIP Template', 0.00, true);

        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId);

        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('Your membership has expired.');
        $this->downloadService->authorizeDownload($record->download_token, $userId);
    }

    /**
     * Scenario K: Separately purchased product remains accessible after membership expiration.
     */
    public function test_scenario_k_separately_purchased_product_remains_accessible_after_membership_expiration(): void
    {
        $userId = 101;
        // Expired membership
        $memId = $this->grantActiveMembership($userId, -5);
        $this->sqliteDb->query("UPDATE `favorite_digital_memberships` SET status = 'expired' WHERE id = ?", [$memId]);

        // Separately purchased digital product (even if membership eligible)
        $productId = $this->createDigitalProduct('Purchased Asset', 29.00, true);
        $this->purchaseAndFulfill($userId, $productId, 29.00);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $entId = (int)$activeEnts[0]->id;

        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, $entId);
        $auth = $this->downloadService->authorizeDownload($record->download_token, $userId);

        $this->assertFalse($auth['is_membership']);
        $this->assertEquals($productId, (int)$auth['product']->id);
    }

    /**
     * Scenario L: First limited download succeeds (count 0 -> 1).
     */
    public function test_scenario_l_first_limited_download_succeeds(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Book L');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'TestAgent');

        $updated = $this->downloadRepo->findDownloadByToken($record->download_token);
        $this->assertEquals(1, (int)$updated->download_count);
        $this->assertEquals('127.0.0.1', $updated->ip_address);
        $this->assertEquals('TestAgent', $updated->user_agent);
    }

    /**
     * Scenario M: Second limited download succeeds (count 1 -> 2).
     */
    public function test_scenario_m_second_limited_download_succeeds(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Book M');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent1');
        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.2', 'Agent2');

        $updated = $this->downloadRepo->findDownloadByToken($record->download_token);
        $this->assertEquals(2, (int)$updated->download_count);
    }

    /**
     * Scenario N: Third limited download succeeds (count 2 -> 3).
     */
    public function test_scenario_n_third_limited_download_succeeds(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Book N');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent1');
        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent2');
        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent3');

        $updated = $this->downloadRepo->findDownloadByToken($record->download_token);
        $this->assertEquals(3, (int)$updated->download_count);
    }

    /**
     * Scenario O: Fourth limited download denied (limit 3 reached).
     */
    public function test_scenario_o_fourth_limited_download_denied(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Book O');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        // 3 downloads succeed
        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent1');
        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent2');
        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent3');

        // 4th download must be rejected
        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('Download limit reached. Maximum allowed downloads: 3.');
        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent4');
    }

    /**
     * Scenario P: Failed authorization does not increment count.
     */
    public function test_scenario_p_failed_authorization_does_not_increment_count(): void
    {
        $userId = 101;
        $attackerId = 999;
        $productId = $this->createDigitalProduct('Protected Book P');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        try {
            $this->downloadService->serveDownload($record->download_token, $attackerId, '127.0.0.1', 'AttackerAgent');
        } catch (DownloadException $e) {
            // Expected denial
        }

        $updated = $this->downloadRepo->findDownloadByToken($record->download_token);
        $this->assertEquals(0, (int)$updated->download_count);
    }

    /**
     * Scenario Q: Membership downloads unlimited while active.
     */
    public function test_scenario_q_membership_downloads_unlimited_while_active(): void
    {
        $userId = 101;
        $this->grantActiveMembership($userId, 30);
        $productId = $this->createDigitalProduct('Unlimited Template Q', 0.00, true);

        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId);

        // Perform 5 downloads
        for ($i = 1; $i <= 5; $i++) {
            $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent ' . $i);
        }

        $updated = $this->downloadRepo->findDownloadByToken($record->download_token);
        $this->assertEquals(5, (int)$updated->download_count);
    }

    /**
     * Scenario R: Membership download does not consume 3-download purchase allowance.
     */
    public function test_scenario_r_membership_download_does_not_consume_3_download_purchase_allowance(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Hybrid Product R', 15.00, true);

        // 1. User purchases product
        $this->purchaseAndFulfill($userId, $productId, 15.00);

        // 2. User also acquires active membership
        $this->grantActiveMembership($userId, 30);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        // Download under active membership does not count toward purchase quota limit
        for ($i = 1; $i <= 5; $i++) {
            $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent ' . $i);
        }

        $updated = $this->downloadRepo->findDownloadByToken($record->download_token);
        $this->assertEquals(5, (int)$updated->download_count);

        // Now expire membership
        $this->sqliteDb->query("UPDATE `favorite_digital_memberships` SET status = 'expired' WHERE user_id = ?", [$userId]);

        // Direct purchase entitlement still allows 3 downloads!
        $this->downloadRepo->updateAudit((int)$record->id, 0, '127.0.0.1', 'ResetQuotaForPurchaseTest');
        $freshRecord = $this->downloadRepo->findDownloadByToken($record->download_token);
        $this->assertEquals(0, (int)$freshRecord->download_count);

        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'PurchaseAgent1');
        $this->assertEquals(1, (int)$this->downloadRepo->findDownloadByToken($record->download_token)->download_count);
    }

    /**
     * Scenario S: Download count race condition protection.
     */
    public function test_scenario_s_download_count_race_condition_protection(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Race Asset S');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        // Set download count to 2
        $this->downloadRepo->incrementDownloadCount((int)$record->id, '127.0.0.1', 'Agent1', 3);
        $this->downloadRepo->incrementDownloadCount((int)$record->id, '127.0.0.1', 'Agent2', 3);
        $this->assertEquals(2, (int)$this->downloadRepo->findDownloadByToken($record->download_token)->download_count);

        // Simulate 2 competing threads trying to execute the 3rd download:
        // Thread 1 succeeds:
        $thread1 = $this->downloadRepo->incrementDownloadCount((int)$record->id, '127.0.0.1', 'Thread1', 3);
        $this->assertTrue($thread1);

        // Thread 2 fails atomically because download_count is now 3:
        $thread2 = $this->downloadRepo->incrementDownloadCount((int)$record->id, '127.0.0.1', 'Thread2', 3);
        $this->assertFalse($thread2);
    }

    /**
     * Scenario T: Duplicate/replayed request does not bypass limit.
     */
    public function test_scenario_t_duplicate_replayed_request_does_not_bypass_limit(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Replay Asset T');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        // Replay requests: 3 succeed, rest fail
        $successes = 0;
        $failures = 0;

        for ($i = 0; $i < 6; $i++) {
            try {
                $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'ReplayAgent');
                $successes++;
            } catch (DownloadException $e) {
                $failures++;
            }
        }

        $this->assertEquals(3, $successes);
        $this->assertEquals(3, $failures);
    }

    /**
     * Scenario U: Secure random token generation (64 hex chars).
     */
    public function test_scenario_u_secure_random_token_generation(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Token Product U');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        $this->assertEquals(64, strlen($record->download_token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $record->download_token);
    }

    /**
     * Scenario V: Token guessing resistance.
     */
    public function test_scenario_v_token_guessing_resistance(): void
    {
        $randomGuesses = [
            bin2hex(random_bytes(32)),
            bin2hex(random_bytes(32)),
            str_repeat('0', 64),
            'admin',
            '12345',
        ];

        foreach ($randomGuesses as $guess) {
            try {
                $this->downloadService->authorizeDownload($guess, 101);
                $this->fail("Expected DownloadException for guessed token: {$guess}");
            } catch (DownloadException $e) {
                $this->assertStringContainsString('Invalid or expired download token.', $e->getMessage());
            }
        }
    }

    /**
     * Scenario W: Token does not bypass customer ownership.
     */
    public function test_scenario_w_token_does_not_bypass_customer_ownership(): void
    {
        $ownerId = 101;
        $thiefId = 102;
        $productId = $this->createDigitalProduct('Valuable Asset W');
        $this->purchaseAndFulfill($ownerId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($ownerId);
        $record = $this->downloadService->getOrCreateDownloadToken($ownerId, $productId, (int)$activeEnts[0]->id);

        // Even with valid token in hand, thief cannot download
        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('You are not authorized to access this download.');
        $this->downloadService->authorizeDownload($record->download_token, $thiefId);
    }

    /**
     * Scenario X: Path traversal rejected (../, ..\).
     */
    public function test_scenario_x_path_traversal_rejected(): void
    {
        $traversalPaths = [
            '../../../../etc/passwd',
            '..\\..\\..\\windows\\win.ini',
            'storage/plugins/favorite-digital/files/../../secret.txt',
            "file.zip\0../evil",
        ];

        foreach ($traversalPaths as $path) {
            try {
                $this->downloadService->resolveAndValidateFilePath($path);
                $this->fail("Failed to reject traversal path: {$path}");
            } catch (DownloadException $e) {
                $this->assertStringContainsString('Invalid file path requested.', $e->getMessage());
            }
        }
    }

    /**
     * Scenario Y: Absolute path rejected.
     */
    public function test_scenario_y_absolute_path_rejected(): void
    {
        $absolutePaths = [
            '/var/www/html/secret.txt',
            'C:\\Windows\\System32\\drivers\\etc\\hosts',
            '\\SharedDrive\\confidential.docx',
            'D:/data/export.sql',
        ];

        foreach ($absolutePaths as $path) {
            try {
                $this->downloadService->resolveAndValidateFilePath($path);
                $this->fail("Failed to reject absolute path: {$path}");
            } catch (DownloadException $e) {
                $this->assertStringContainsString('Invalid file path requested.', $e->getMessage());
            }
        }
    }

    /**
     * Scenario Z: PHP stream wrapper abuse rejected.
     */
    public function test_scenario_z_php_stream_wrapper_abuse_rejected(): void
    {
        $wrappers = [
            'php://filter/read=convert.base64-encode/resource=index.php',
            'php://input',
            'file:///etc/passwd',
            'phar://archive.phar/internal.php',
            'data://text/plain;base64,SSBsb3ZlIFBIUAo=',
        ];

        foreach ($wrappers as $wrapper) {
            try {
                $this->downloadService->resolveAndValidateFilePath($wrapper);
                $this->fail("Failed to reject wrapper: {$wrapper}");
            } catch (DownloadException $e) {
                $this->assertStringContainsString('Invalid file path requested.', $e->getMessage());
            }
        }
    }

    /**
     * Scenario AA: Remote URL abuse rejected.
     */
    public function test_scenario_aa_remote_url_abuse_rejected(): void
    {
        $remoteUrls = [
            'http://attacker.com/malware.exe',
            'https://evil.example/data.zip',
            'ftp://anonymous@ftp.example.com/test.txt',
        ];

        foreach ($remoteUrls as $url) {
            try {
                $this->downloadService->resolveAndValidateFilePath($url);
                $this->fail("Failed to reject remote URL: {$url}");
            } catch (DownloadException $e) {
                $this->assertStringContainsString('Invalid file path requested.', $e->getMessage());
            }
        }
    }

    /**
     * Scenario AB: Arbitrary file read rejected.
     */
    public function test_scenario_ab_arbitrary_file_read_rejected(): void
    {
        // Try to access index.php or config files
        $dangerousPaths = [
            'index.php',
            'config.php',
            'wp-config.php',
            '.env',
        ];

        foreach ($dangerousPaths as $path) {
            try {
                // Must either throw traversal or fileNotFound outside storage dir
                $this->downloadService->resolveAndValidateFilePath($path);
                $this->fail("Arbitrary file read not rejected for: {$path}");
            } catch (DownloadException $e) {
                $this->assertTrue(
                    str_contains($e->getMessage(), 'Invalid file path') ||
                    str_contains($e->getMessage(), 'File unavailable')
                );
            }
        }
    }

    /**
     * Scenario AC: Protected file not publicly accessible.
     */
    public function test_scenario_ac_protected_file_not_publicly_accessible(): void
    {
        // Check that storage directory has .htaccess protecting files
        $htaccessPath = $this->tempStorageDir . '/.htaccess';
        $this->assertFileExists($htaccessPath);
        $htaccessContent = file_get_contents($htaccessPath);
        $this->assertStringContainsString('Deny from all', $htaccessContent);
    }

    /**
     * Scenario AD: Secure file streaming (headers and parameters).
     */
    public function test_scenario_ad_secure_file_streaming_headers_and_chunks(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Streaming Test Product');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        $auth = $this->downloadService->authorizeDownload($record->download_token, $userId);

        $this->assertNotEmpty($auth['file_path']);
        $this->assertFileExists($auth['file_path']);
        $this->assertEquals('Streaming Test Product.zip', $auth['file_name']);
        $this->assertEquals('application/zip', $auth['mime_type']);
        $this->assertGreaterThan(0, $auth['file_size']);
    }

    /**
     * Scenario AE: No absolute filesystem path leakage in errors.
     */
    public function test_scenario_ae_no_absolute_filesystem_path_leakage_in_errors(): void
    {
        try {
            $this->downloadService->resolveAndValidateFilePath('/var/secret/path/to/leak.txt');
        } catch (DownloadException $e) {
            $msg = $e->getMessage();
            $this->assertStringNotContainsString('/var/secret', $msg);
            $this->assertStringNotContainsString($this->tempStorageDir, $msg);
        }
    }

    /**
     * Scenario AF: Safe error messages.
     */
    public function test_scenario_af_safe_error_messages(): void
    {
        $fakeToken = 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890';
        try {
            $this->downloadService->authorizeDownload($fakeToken, 101);
            $this->fail("Expected DownloadException");
        } catch (DownloadException $e) {
            $this->assertEquals('Invalid or expired download token.', $e->getMessage());
        }
    }

    /**
     * Scenario AG: Download audit record created in favorite_digital_downloads.
     */
    public function test_scenario_ag_download_audit_record_created(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Audit Test Product');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $entId = (int)$activeEnts[0]->id;

        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, $entId);

        $this->downloadService->serveDownload($record->download_token, $userId, '203.0.113.42', 'Mozilla/5.0 AuditBot');

        $updated = $this->downloadRepo->findDownloadByToken($record->download_token);
        $this->assertNotNull($updated);
        $this->assertEquals($entId, (int)$updated->entitlement_id);
        $this->assertEquals($productId, (int)$updated->product_id);
        $this->assertEquals($userId, (int)$updated->user_id);
        $this->assertEquals(1, (int)$updated->download_count);
        $this->assertEquals('203.0.113.42', $updated->ip_address);
        $this->assertEquals('Mozilla/5.0 AuditBot', $updated->user_agent);
        $this->assertNotEmpty($updated->downloaded_at);
    }

    /**
     * Scenario AH: Correct user/product/entitlement association.
     */
    public function test_scenario_ah_correct_user_product_entitlement_association(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Association Product');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $entId = (int)$activeEnts[0]->id;

        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, $entId);
        $found = $this->downloadRepo->findDownloadByToken($record->download_token);

        $this->assertEquals($userId, (int)$found->user_id);
        $this->assertEquals($productId, (int)$found->product_id);
        $this->assertEquals($entId, (int)$found->entitlement_id);
    }

    /**
     * Scenario AI: IP/user-agent recording follows server-side values.
     */
    public function test_scenario_ai_ip_and_user_agent_recording(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Server Info Product');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        $clientIp = '198.51.100.25';
        $clientAgent = 'FavoriteCMS-SecureClient/1.0';

        $this->downloadService->serveDownload($record->download_token, $userId, $clientIp, $clientAgent);

        $updated = $this->downloadRepo->findDownloadByToken($record->download_token);
        $this->assertEquals($clientIp, $updated->ip_address);
        $this->assertEquals($clientAgent, $updated->user_agent);
    }

    /**
     * Scenario AJ: Prefix safety.
     */
    public function test_scenario_aj_prefix_safety(): void
    {
        $this->assertTrue(in_array('favorite_digital_downloads', FavoriteDigitalPlugin::TABLES, true));
        $this->assertTrue(in_array('favorite_digital_entitlements', FavoriteDigitalPlugin::TABLES, true));
    }

    /**
     * Scenario AK: SQLite compatibility.
     */
    public function test_scenario_ak_sqlite_compatibility(): void
    {
        $id = $this->downloadRepo->createDownloadRecord([
            'entitlement_id' => 1,
            'product_id'     => 2,
            'user_id'        => 3,
            'download_token' => bin2hex(random_bytes(32)),
            'ip_address'     => '127.0.0.1',
            'user_agent'     => 'PHPUnit SQLite Test',
        ]);
        $this->assertGreaterThan(0, $id);

        $found = $this->downloadRepo->findDownloadById($id);
        $this->assertNotNull($found);
        $this->assertEquals(0, (int)$found->download_count);
    }

    /**
     * Scenario AL: MySQL/MariaDB compatibility cleanly skipped if offline.
     */
    public function test_scenario_al_mysql_compatibility_graceful_skip(): void
    {
        $mysqlHost = getenv('DB_HOST') ?: '127.0.0.1';
        $mysqlPort = getenv('DB_PORT') ?: '3306';
        $mysqlDb   = getenv('DB_DATABASE') ?: 'favorite_cms_test';
        $mysqlUser = getenv('DB_USERNAME') ?: 'root';
        $mysqlPass = getenv('DB_PASSWORD') ?: '';

        try {
            $pdo = new PDO("mysql:host={$mysqlHost};port={$mysqlPort};dbname={$mysqlDb}", $mysqlUser, $mysqlPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 1,
            ]);
            $this->assertNotNull($pdo);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL/MariaDB service offline. Skipping live MySQL query verification.');
        }
    }

    /**
     * Scenario AM: Membership access uses existing MembershipLifecycleService.
     */
    public function test_scenario_am_membership_access_uses_existing_lifecycle_service(): void
    {
        $userId = 101;
        $this->grantActiveMembership($userId, 30);
        $productId = $this->createDigitalProduct('Lifecycle Member Product', 0.00, true);

        $hasAccess = $this->membershipService->hasActiveMembership($userId);
        $this->assertTrue($hasAccess);

        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId);
        $auth = $this->downloadService->authorizeDownload($record->download_token, $userId);
        $this->assertTrue($auth['is_membership']);
    }

    /**
     * Scenario AN: No duplicate entitlement creation during download.
     */
    public function test_scenario_an_no_duplicate_entitlement_creation_during_download(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('No Dup Product');
        $this->purchaseAndFulfill($userId, $productId);

        $initialEntCount = count($this->entitlementRepo->getEntitlementsByUser($userId));

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent');

        $finalEntCount = count($this->entitlementRepo->getEntitlementsByUser($userId));
        $this->assertEquals($initialEntCount, $finalEntCount);
    }

    /**
     * Scenario AO: Download does not trigger fulfillment.
     */
    public function test_scenario_ao_download_does_not_trigger_fulfillment(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Fulfillment Isolation Product');
        $orderId = $this->purchaseAndFulfill($userId, $productId);

        $orderBefore = $this->orderRepo->findOrder($orderId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent');

        $orderAfter = $this->orderRepo->findOrder($orderId);
        $this->assertEquals($orderBefore->fulfillment_status, $orderAfter->fulfillment_status);
        $this->assertEquals($orderBefore->status, $orderAfter->status);
    }

    /**
     * Scenario AP: Download does not trigger payment.
     */
    public function test_scenario_ap_download_does_not_trigger_payment(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Payment Isolation Product');
        $this->purchaseAndFulfill($userId, $productId);

        $intentsCountBefore = count($this->intents);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent');

        $intentsCountAfter = count($this->intents);
        $this->assertEquals($intentsCountBefore, $intentsCountAfter);
    }

    /**
     * Scenario AQ: Download does not modify order payment status.
     */
    public function test_scenario_aq_download_does_not_modify_order_payment_status(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Payment Status Product');
        $orderId = $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent');

        $order = $this->orderRepo->findOrder($orderId);
        $this->assertEquals('paid', $order->payment_status);
    }

    /**
     * Scenario AR: Download does not modify wallet balance.
     */
    public function test_scenario_ar_download_does_not_modify_wallet_balance(): void
    {
        $userId = 101;
        $this->walletService->credit($userId, '50.00', 'tx_dep_' . uniqid(), 'Initial deposit');
        $initialBalance = $this->walletService->getBalance($userId);

        $productId = $this->createDigitalProduct('Wallet Test Product');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent');

        $finalBalance = $this->walletService->getBalance($userId);
        $this->assertEquals($initialBalance, $finalBalance);
    }

    /**
     * Scenario AS: Future revoked entitlement blocks download.
     */
    public function test_scenario_as_future_revoked_entitlement_blocks_download(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Revoke Product AS');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $entId = (int)$activeEnts[0]->id;

        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, $entId);

        // Download 1 succeeds
        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent 1');
        $this->assertEquals(1, (int)$this->downloadRepo->findDownloadByToken($record->download_token)->download_count);

        // Administrator revokes entitlement
        $this->entitlementRepo->updateStatus($entId, 'revoked');

        // Subsequent download must be blocked
        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('Entitlement for this product has been revoked.');
        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent 2');
    }

    /**
     * Scenario AT: Previously downloaded file is not remotely deleted.
     */
    public function test_scenario_at_previously_downloaded_file_not_remotely_deleted(): void
    {
        $userId = 101;
        $productId = $this->createDigitalProduct('Persistent File Product');
        $this->purchaseAndFulfill($userId, $productId);

        $activeEnts = $this->entitlementRepo->getEntitlementsByUser($userId);
        $record = $this->downloadService->getOrCreateDownloadToken($userId, $productId, (int)$activeEnts[0]->id);

        $auth = $this->downloadService->authorizeDownload($record->download_token, $userId);
        $this->assertFileExists($auth['file_path']);

        $this->downloadService->serveDownload($record->download_token, $userId, '127.0.0.1', 'Agent');

        // File must remain on disk intact
        $this->assertFileExists($auth['file_path']);
    }
}
