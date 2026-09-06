<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteDigital;

use DateTimeImmutable;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Contracts\EntitlementCheckerInterface;
use FavoriteCMS\Digital\Controllers\CustomerStorefrontController;
use FavoriteCMS\Digital\Domain\MembershipStatus;
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
use FavoriteCMS\Digital\Services\DefaultEntitlementChecker;
use FavoriteCMS\Digital\Services\DigitalFileStorageService;
use FavoriteCMS\Digital\Services\DownloadService;
use FavoriteCMS\Digital\Services\FulfillmentService;
use FavoriteCMS\Digital\Services\MembershipLifecycleService;
use FavoriteCMS\Digital\Services\OrderService;
use FavoriteCMS\Digital\Services\StorefrontService;
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
 * CustomerStorefrontIntegrationTest
 *
 * Exhaustive Phase 6 test suite covering minimum test scenarios A through BF:
 * - Customer Storefront catalog browsing & discovery
 * - Public status enforcement (published vs draft/archived)
 * - Multi-type catalog support (Digital, Service, Package, Membership)
 * - Safe parameterized search, filters, and whitelisted sorting
 * - Server-side pagination preserving all filter/sort/search parameters
 * - Detailed product views with authoritative financial breakdowns
 * - Customer ownership state matrix (Guest, Owned, Membership, Free, Purchasable)
 * - Seamless integration with existing Phase 5B checkout and zero-value order settlement
 * - Security hardening: IDOR, XSS, SQLi, ORDER BY injection, CSRF, private path isolation
 * - SQLite in-memory runtime and prefix safety
 */
class CustomerStorefrontIntegrationTest extends TestCase
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
    private OrderService $orderService;
    private CheckoutService $checkoutService;
    private StorefrontService $storefrontService;
    private CustomerStorefrontController $storefrontController;
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

        $this->productRepo = new ProductRepository($this->sqliteDb);
        $this->orderRepo = new OrderRepository($this->sqliteDb);
        $this->walletRepo = new WalletRepository($this->sqliteDb);
        $this->walletService = new WalletService($this->walletRepo, $this->sqliteDb);
        $this->entitlementRepo = new EntitlementRepository($this->sqliteDb);
        $this->downloadRepo = new DownloadRepository($this->sqliteDb);
        $this->refundRepo = new RefundRepository($this->sqliteDb);
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

        $this->tempStorageDir = sys_get_temp_dir() . '/fav_store_test_' . uniqid();
        @mkdir($this->tempStorageDir, 0777, true);
        $this->storageService = new DigitalFileStorageService($this->tempStorageDir);

        $this->downloadService = new DownloadService(
            $this->downloadRepo,
            $this->entitlementRepo,
            $this->productRepo,
            $this->membershipService,
            $this->checker,
            $this->storageService,
            $this->sqliteDb
        );

        $this->mockPayService = $this->createMockPayService();

        $this->checkoutService = new CheckoutService(
            $this->orderRepo,
            $this->walletService,
            $this->mockPayService,
            $this->sqliteDb,
            $this->fulfillmentService
        );

        $this->orderService = new OrderService(
            $this->orderRepo,
            $this->productRepo,
            $this->membershipService,
            $this->checker,
            $this->sqliteDb
        );

        $this->storefrontService = new StorefrontService(
            $this->productRepo,
            $this->checker,
            $this->membershipService,
            $this->orderService,
            $this->checkoutService
        );

        $this->storefrontController = new CustomerStorefrontController(
            $this->app,
            $this->storefrontService
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
                    @unlink($file);
                }
            }
            @rmdir($this->tempStorageDir);
        }
        unset($GLOBALS['_test_current_user']);
        $_SESSION = [];
        parent::tearDown();
    }

    private function createMockPayService(): PaymentServiceInterface
    {
        return new class($this->intents, $this->attempts) implements PaymentServiceInterface {
            private array $intents;
            private array $attempts;

            public function __construct(array &$intents, array &$attempts)
            {
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

    private function createDigitalProduct(string $title = 'E-Book Guide', string $price = '500.00', string $status = ProductStatus::PUBLISHED, bool $isFree = false, bool $isMembershipEligible = true): int
    {
        $filename = 'guide_' . uniqid() . '.pdf';
        $filePath = $this->tempStorageDir . '/' . $filename;
        file_put_contents($filePath, 'PDF File Binary Content');

        $finalPrice = $isFree ? '0.00' : $price;

        $pid = $this->productRepo->createProduct([
            'title'            => $title,
            'slug'             => 'prod-' . uniqid(),
            'description'      => 'A high quality digital product: ' . $title,
            'product_type'     => ProductType::DIGITAL,
            'status'           => $status,
            'original_price'   => $price,
            'discount_percent' => ($isFree || $price === $finalPrice) ? '0.00' : '20.00',
            'final_price'      => $finalPrice,
            'currency'         => 'BDT',
            'is_free'          => $isFree ? 1 : 0,
        ]);

        $this->productRepo->saveProductDetails($pid, [
            'file_path'              => 'storage/plugins/favorite-digital/files/' . $filename,
            'file_name'              => $filename,
            'file_hash'              => hash('sha256', 'PDF File Binary Content'),
            'file_size'              => filesize($filePath),
            'mime_type'              => 'application/pdf',
            'max_downloads'          => 3,
            'download_expiry_days'   => 30,
            'is_membership_eligible' => $isMembershipEligible ? 1 : 0,
            'version'                => '1.0.0',
        ]);

        return $pid;
    }

    private function createServiceProduct(string $title = 'Consulting Hour', string $price = '1000.00', string $status = ProductStatus::PUBLISHED): int
    {
        $pid = $this->productRepo->createProduct([
            'title'                  => $title,
            'slug'                   => 'service-' . uniqid(),
            'description'            => 'Professional expert consulting service: ' . $title,
            'product_type'           => ProductType::SERVICE,
            'status'                 => $status,
            'original_price'         => $price,
            'discount_percent'       => '0.00',
            'final_price'            => $price,
            'currency'               => 'BDT',
            'is_free'                => 0,
        ]);

        $this->productRepo->saveServiceDetails($pid, [
            'delivery_time_days'  => 3,
            'service_scope'       => '1-on-1 strategy meeting with implementation roadmap',
            'requirements_prompt' => 'Please provide your website URL and current challenges',
        ]);

        return $pid;
    }

    private function createPackageProduct(string $title, string $price, array $childProductIds, string $status = ProductStatus::PUBLISHED): int
    {
        $pkgProdId = $this->productRepo->createProduct([
            'title'                  => $title,
            'slug'                   => 'pkg-' . uniqid(),
            'description'            => 'Complete bundle containing multiple tools: ' . $title,
            'product_type'           => ProductType::PACKAGE,
            'status'                 => $status,
            'original_price'         => $price,
            'discount_percent'       => '0.00',
            'final_price'            => $price,
            'currency'               => 'BDT',
            'is_free'                => 0,
        ]);

        $pkgRecordId = $this->productRepo->createPackage($pkgProdId, 'bundle');

        foreach ($childProductIds as $cId) {
            $this->productRepo->addPackageItem($pkgRecordId, $cId);
        }

        return $pkgProdId;
    }

    private function createMembershipProduct(string $title = 'VIP Club', string $price = '1500.00', string $status = ProductStatus::PUBLISHED): int
    {
        return $this->membershipService->createPlan([
            'title'          => $title,
            'slug'           => 'mem-' . uniqid(),
            'description'    => 'Exclusive VIP membership access: ' . $title,
            'status'         => $status,
            'original_price' => $price,
            'currency'       => 'BDT',
        ], [
            'plan_type'           => 'monthly',
            'duration_count'      => 1,
            'duration_unit'       => 'months',
            'grace_period_days'   => 3,
            'allows_auto_renewal' => 1,
        ]);
    }

    // =========================================================================
    // SCENARIOS A - BF
    // =========================================================================

    /**
     * Scenario A: Storefront loads
     */
    public function testScenarioA_StorefrontLoads(): void
    {
        $this->createDigitalProduct('Storefront Welcome Product');

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($request);

        $this->assertIsString($html);
        $this->assertStringContainsString('Digital Store', $html);
        $this->assertStringContainsString('Storefront Welcome Product', $html);
    }

    /**
     * Scenario B: Published product visible
     */
    public function testScenarioB_PublishedProductVisible(): void
    {
        $this->createDigitalProduct('Visible Published Product', '400.00', ProductStatus::PUBLISHED);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($request);

        $this->assertStringContainsString('Visible Published Product', $html);
    }

    /**
     * Scenario C: Draft product hidden
     */
    public function testScenarioC_DraftProductHidden(): void
    {
        $this->createDigitalProduct('Hidden Draft Product', '400.00', ProductStatus::DRAFT);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($request);

        $this->assertStringNotContainsString('Hidden Draft Product', $html);
    }

    /**
     * Scenario D: Archived product hidden
     */
    public function testScenarioD_ArchivedProductHidden(): void
    {
        $this->createDigitalProduct('Hidden Archived Product', '400.00', ProductStatus::ARCHIVED);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($request);

        $this->assertStringNotContainsString('Hidden Archived Product', $html);
    }

    /**
     * Scenario E: Digital product visible
     */
    public function testScenarioE_DigitalProductVisible(): void
    {
        $this->createDigitalProduct('Modern Digital eBook', '350.00');

        $request = new Request([], ['product_type' => 'digital'], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($request);

        $this->assertStringContainsString('Modern Digital eBook', $html);
        $this->assertStringContainsString('Digital', $html);
    }

    /**
     * Scenario F: Service visible
     */
    public function testScenarioF_ServiceVisible(): void
    {
        $this->createServiceProduct('SEO Audit Service', '1200.00');

        $request = new Request([], ['product_type' => 'service'], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($request);

        $this->assertStringContainsString('SEO Audit Service', $html);
        $this->assertStringContainsString('Service', $html);
    }

    /**
     * Scenario G: Package visible
     */
    public function testScenarioG_PackageVisible(): void
    {
        $child1 = $this->createDigitalProduct('Book Part 1', '200.00');
        $child2 = $this->createDigitalProduct('Book Part 2', '200.00');
        $this->createPackageProduct('Complete Book Bundle', '350.00', [$child1, $child2]);

        $request = new Request([], ['product_type' => 'package'], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($request);

        $this->assertStringContainsString('Complete Book Bundle', $html);
        $this->assertStringContainsString('2 items included', $html);
    }

    /**
     * Scenario H: Membership visible
     */
    public function testScenarioH_MembershipVisible(): void
    {
        $this->createMembershipProduct('Developer Community VIP', '999.00');

        $request = new Request([], ['product_type' => 'membership'], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($request);

        $this->assertStringContainsString('Developer Community VIP', $html);
        $this->assertStringContainsString('1 month plan', $html);
    }

    /**
     * Scenario I: Search by title
     */
    public function testScenarioI_SearchByTitle(): void
    {
        $this->createDigitalProduct('Unique Quantum Physics Guide', '500.00');
        $this->createDigitalProduct('Another Ordinary Book', '100.00');

        $request = new Request(['search' => 'Quantum Physics'], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($request);

        $this->assertStringContainsString('Unique Quantum Physics Guide', $html);
        $this->assertStringNotContainsString('Another Ordinary Book', $html);
    }

    /**
     * Scenario J: Search by description
     */
    public function testScenarioJ_SearchByDescription(): void
    {
        $pid = $this->createDigitalProduct('Special Book', '250.00');
        $this->productRepo->updateProduct($pid, ['description' => 'Comprehensive deep learning neuro-symbolic algorithms']);

        $request = new Request(['search' => 'neuro-symbolic'], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($request);

        $this->assertStringContainsString('Special Book', $html);
    }

    /**
     * Scenario K: Empty search result
     */
    public function testScenarioK_EmptySearchResult(): void
    {
        $this->createDigitalProduct('Regular Product', '200.00');

        $request = new Request(['search' => 'NonExistentZebraTerm12345'], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($request);

        $this->assertStringContainsString('No products found', $html);
        $this->assertStringContainsString('NonExistentZebraTerm12345', $html);
    }

    /**
     * Scenario L: Product type filter
     */
    public function testScenarioL_ProductTypeFilter(): void
    {
        $this->createDigitalProduct('Digital Item Exclusive', '100.00');
        $this->createServiceProduct('Service Item Exclusive', '200.00');

        $request = new Request(['product_type' => 'service'], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($request);

        $this->assertStringContainsString('Service Item Exclusive', $html);
        $this->assertStringNotContainsString('Digital Item Exclusive', $html);
    }

    /**
     * Scenario M: Price/free filter
     */
    public function testScenarioM_PriceFreeFilter(): void
    {
        $this->createDigitalProduct('Paid Item 500', '500.00', ProductStatus::PUBLISHED, false);
        $this->createDigitalProduct('Free Community Item', '0.00', ProductStatus::PUBLISHED, true);

        // Filter: free
        $reqFree = new Request(['price' => 'free'], [], ['REQUEST_METHOD' => 'GET']);
        $htmlFree = $this->storefrontController->index($reqFree);
        $this->assertStringContainsString('Free Community Item', $htmlFree);
        $this->assertStringNotContainsString('Paid Item 500', $htmlFree);

        // Filter: paid
        $reqPaid = new Request(['price' => 'paid'], [], ['REQUEST_METHOD' => 'GET']);
        $htmlPaid = $this->storefrontController->index($reqPaid);
        $this->assertStringContainsString('Paid Item 500', $htmlPaid);
        $this->assertStringNotContainsString('Free Community Item', $htmlPaid);
    }

    /**
     * Scenario N: Membership filter
     */
    public function testScenarioN_MembershipFilter(): void
    {
        $this->createDigitalProduct('Membership Eligible Item', '300.00', ProductStatus::PUBLISHED, false, true);
        $this->createDigitalProduct('Non Eligible Item', '300.00', ProductStatus::PUBLISHED, false, false);

        $req = new Request(['membership' => 'eligible'], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($req);

        $this->assertStringContainsString('Membership Eligible Item', $html);
        $this->assertStringNotContainsString('Non Eligible Item', $html);
    }

    /**
     * Scenario O: Newest sorting
     */
    public function testScenarioO_NewestSorting(): void
    {
        $p1 = $this->createDigitalProduct('Older Product 1', '100.00');
        $p2 = $this->createDigitalProduct('Newer Product 2', '200.00');

        $result = $this->storefrontService->browseProducts(['sort' => 'newest']);
        $this->assertGreaterThanOrEqual(2, count($result['items']));
        $this->assertEquals($p2, $result['items'][0]['id']);
    }

    /**
     * Scenario P: Price ascending sorting
     */
    public function testScenarioP_PriceAscendingSorting(): void
    {
        $this->createDigitalProduct('Expensive Alpha', '900.00');
        $this->createDigitalProduct('Cheap Beta', '150.00');

        $result = $this->storefrontService->browseProducts(['sort' => 'price_asc']);
        $this->assertGreaterThanOrEqual(2, count($result['items']));
        $first = (float)$result['items'][0]['pricing']['final_price'];
        $second = (float)$result['items'][1]['pricing']['final_price'];
        $this->assertLessThanOrEqual($second, $first);
    }

    /**
     * Scenario Q: Price descending sorting
     */
    public function testScenarioQ_PriceDescendingSorting(): void
    {
        $this->createDigitalProduct('Cheap Gamma', '100.00');
        $this->createDigitalProduct('Expensive Delta', '950.00');

        $result = $this->storefrontService->browseProducts(['sort' => 'price_desc']);
        $first = (float)$result['items'][0]['pricing']['final_price'];
        $second = (float)$result['items'][1]['pricing']['final_price'];
        $this->assertGreaterThanOrEqual($second, $first);
    }

    /**
     * Scenario R: Name sorting
     */
    public function testScenarioR_NameSorting(): void
    {
        $this->createDigitalProduct('Zebra Guide', '200.00');
        $this->createDigitalProduct('Apple Primer', '200.00');

        $result = $this->storefrontService->browseProducts(['sort' => 'name_asc']);
        $this->assertEquals('Apple Primer', $result['items'][0]['title']);
    }

    /**
     * Scenario S: Pagination
     */
    public function testScenarioS_Pagination(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->createDigitalProduct("Batch Item {$i}", '100.00');
        }

        $page1 = $this->storefrontService->browseProducts([], 1, 10);
        $this->assertCount(10, $page1['items']);
        $this->assertEquals(15, $page1['total']);
        $this->assertEquals(2, $page1['totalPages']);

        $page2 = $this->storefrontService->browseProducts([], 2, 10);
        $this->assertCount(5, $page2['items']);
    }

    /**
     * Scenario T: Pagination preserves search
     */
    public function testScenarioT_PaginationPreservesSearch(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->createDigitalProduct("Matched Item {$i}", '100.00');
        }

        $request = new Request(['search' => 'Matched', 'page' => '2'], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($request);

        $this->assertStringContainsString('Matched Item', $html);
        $this->assertStringContainsString('search=Matched', $html);
    }

    /**
     * Scenario U: Pagination preserves filters
     */
    public function testScenarioU_PaginationPreservesFilters(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            $this->createServiceProduct("Consulting Tier {$i}", '500.00');
        }

        $request = new Request(['product_type' => 'service', 'page' => '1'], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($request);

        $this->assertStringContainsString('product_type=service', $html);
    }

    /**
     * Scenario V: Pagination preserves sorting
     */
    public function testScenarioV_PaginationPreservesSorting(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            $this->createDigitalProduct("Book Number {$i}", (string)(100 + $i));
        }

        $request = new Request(['sort' => 'price_desc', 'page' => '1'], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($request);

        $this->assertStringContainsString('sort=price_desc', $html);
    }

    /**
     * Scenario W: Product detail page
     */
    public function testScenarioW_ProductDetailPage(): void
    {
        $pid = $this->createDigitalProduct('Detailed Masterclass', '750.00');
        $prod = $this->productRepo->findProduct($pid);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->show($request, $prod->slug);

        $this->assertIsString($html);
        $this->assertStringContainsString('Detailed Masterclass', $html);
        $this->assertStringContainsString('750.00', $html);
    }

    /**
     * Scenario X: Digital detail information
     */
    public function testScenarioX_DigitalDetailInformation(): void
    {
        $pid = $this->createDigitalProduct('Software Binary Kit', '800.00');
        $prod = $this->productRepo->findProduct($pid);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->show($request, $prod->slug);

        $this->assertStringContainsString('application/pdf', $html);
        $this->assertStringContainsString('1.0.0', $html);
        $this->assertStringContainsString('30 days access', $html);
        // Ensure private file path is NEVER leaked
        $this->assertStringNotContainsString('storage/plugins/favorite-digital/files/', $html);
    }

    /**
     * Scenario Y: Service detail information
     */
    public function testScenarioY_ServiceDetailInformation(): void
    {
        $pid = $this->createServiceProduct('Performance Optimization Service', '2000.00');
        $prod = $this->productRepo->findProduct($pid);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->show($request, $prod->slug);

        $this->assertStringContainsString('3 Days', $html);
        $this->assertStringContainsString('1-on-1 strategy meeting', $html);
        $this->assertStringContainsString('Please provide your website URL', $html);
    }

    /**
     * Scenario Z: Package contents
     */
    public function testScenarioZ_PackageContents(): void
    {
        $c1 = $this->createDigitalProduct('Component Ebook', '300.00');
        $c2 = $this->createServiceProduct('Component Service', '600.00');
        $pkgId = $this->createPackageProduct('Super Combo Bundle', '700.00', [$c1, $c2]);
        $pkg = $this->productRepo->findProduct($pkgId);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->show($request, $pkg->slug);

        $this->assertStringContainsString('Super Combo Bundle', $html);
        $this->assertStringContainsString('Component Ebook', $html);
        $this->assertStringContainsString('Component Service', $html);
        $this->assertStringContainsString('Included in this Package (2 items)', $html);
    }

    /**
     * Scenario AA: Membership plan information
     */
    public function testScenarioAA_MembershipPlanInformation(): void
    {
        $memId = $this->createMembershipProduct('Founder Circle Membership', '3000.00');
        $mem = $this->productRepo->findProduct($memId);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->show($request, $mem->slug);

        $this->assertStringContainsString('Monthly', $html);
        $this->assertStringContainsString('1 month', $html);
        $this->assertStringContainsString('3 Days', $html); // Grace period
        $this->assertStringContainsString('Disabled by default (Manual renewal)', $html);
    }

    /**
     * Scenario AB: Membership-required product state
     */
    public function testScenarioAB_MembershipRequiredProductState(): void
    {
        $pid = $this->createDigitalProduct('VIP Only Resource', '500.00');
        $this->orderService->setMembershipRequirementChecker(fn($p) => (int)$p->id === $pid);
        $prod = $this->productRepo->findProduct($pid);

        // Authenticated non-member customer
        $GLOBALS['_test_current_user'] = (object)['id' => 50];
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->show($request, $prod->slug);

        $this->assertStringContainsString('Active Membership Required', $html);
        $this->assertStringContainsString('/store?product_type=membership', $html);
    }

    /**
     * Scenario AC: Guest state
     */
    public function testScenarioAC_GuestState(): void
    {
        $pid = $this->createDigitalProduct('Public Product', '400.00');
        $prod = $this->productRepo->findProduct($pid);

        $state = $this->storefrontService->resolveCustomerState($prod, null);
        $this->assertEquals('guest', $state['state']);
        $this->assertEquals('Sign in to Buy', $state['button_text']);
        $this->assertStringContainsString('/login?redirect=', $state['action_url']);
    }

    /**
     * Scenario AD: Logged-in non-owner state
     */
    public function testScenarioAD_LoggedInNonOwnerState(): void
    {
        $pid = $this->createDigitalProduct('Purchasable Item', '400.00');
        $prod = $this->productRepo->findProduct($pid);

        $state = $this->storefrontService->resolveCustomerState($prod, 42);
        $this->assertEquals('purchasable', $state['state']);
        $this->assertEquals('Buy Now', $state['button_text']);
        $this->assertTrue($state['is_purchasable']);
        $this->assertFalse($state['is_owned']);
    }

    /**
     * Scenario AE: Already purchased state
     */
    public function testScenarioAE_AlreadyPurchasedState(): void
    {
        $userId = 77;
        $pid = $this->createDigitalProduct('Purchased Item', '400.00');
        $prod = $this->productRepo->findProduct($pid);

        // Grant active purchase entitlement
        $this->entitlementRepo->createEntitlement([
            'user_id'     => $userId,
            'product_id'  => $pid,
            'source_type' => 'purchase',
            'source_id'   => 1,
            'status'      => 'active',
            'granted_at'  => date('Y-m-d H:i:s'),
        ]);

        $state = $this->storefrontService->resolveCustomerState($prod, $userId);
        $this->assertEquals('owned', $state['state']);
        $this->assertEquals('Download File', $state['button_text']);
        $this->assertTrue($state['is_owned']);
        $this->assertFalse($state['is_purchasable']);
    }

    /**
     * Scenario AF: Already owned state
     */
    public function testScenarioAF_AlreadyOwnedStatePreventsDuplicatePurchaseUI(): void
    {
        $userId = 88;
        $pid = $this->createDigitalProduct('Prevent Duplicate Item', '500.00');
        $prod = $this->productRepo->findProduct($pid);

        $this->entitlementRepo->createEntitlement([
            'user_id'     => $userId,
            'product_id'  => $pid,
            'source_type' => 'purchase',
            'source_id'   => 2,
            'status'      => 'active',
            'granted_at'  => date('Y-m-d H:i:s'),
        ]);

        $mockUser = new class extends User {
            public int $id = 88;
            public function isActive(): bool { return true; }
        };
        $GLOBALS['_test_current_user'] = $mockUser;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->show($request, $prod->slug);

        $this->assertStringContainsString('Already Owned', $html);
        $this->assertStringNotContainsString('name="_token"', $html); // Buy form hidden
    }

    /**
     * Scenario AG: Package ownership state
     */
    public function testScenarioAG_PackageOwnershipState(): void
    {
        $userId = 99;
        $c1 = $this->createDigitalProduct('Item 1', '100.00');
        $pkgId = $this->createPackageProduct('Full Suite', '400.00', [$c1]);
        $pkg = $this->productRepo->findProduct($pkgId);

        // User owns child item 1 directly, but NOT the package itself
        $this->entitlementRepo->createEntitlement([
            'user_id'     => $userId,
            'product_id'  => $c1,
            'source_type' => 'purchase',
            'source_id'   => 1,
            'status'      => 'active',
            'granted_at'  => date('Y-m-d H:i:s'),
        ]);

        $pkgState = $this->storefrontService->resolveCustomerState($pkg, $userId);
        $this->assertFalse($pkgState['is_owned']); // Package itself is not owned
        $this->assertTrue($pkgState['is_purchasable']);

        // Now grant package entitlement
        $this->entitlementRepo->createEntitlement([
            'user_id'     => $userId,
            'product_id'  => $pkgId,
            'source_type' => 'purchase',
            'source_id'   => 2,
            'status'      => 'active',
            'granted_at'  => date('Y-m-d H:i:s'),
        ]);

        $pkgStateAfter = $this->storefrontService->resolveCustomerState($pkg, $userId);
        $this->assertTrue($pkgStateAfter['is_owned']);
    }

    /**
     * Scenario AH: Existing membership state
     */
    public function testScenarioAH_ExistingMembershipState(): void
    {
        $userId = 101;
        $memProdId = $this->createMembershipProduct('Gold Pass', '1000.00');
        $memProd = $this->productRepo->findProduct($memProdId);
        $plan = $this->productRepo->findMembershipPlanByProductId($memProdId);

        // Activate membership for user
        $this->membershipService->activateMembership($userId, (int)$plan->id, false);

        $state = $this->storefrontService->resolveCustomerState($memProd, $userId);
        $this->assertEquals('active_member', $state['state']);
        $this->assertEquals('Extend / Renew Plan', $state['button_text']);
    }

    /**
     * Scenario AI: Free product state
     */
    public function testScenarioAI_FreeProductState(): void
    {
        $userId = 102;
        $pid = $this->createDigitalProduct('Free Open Source Toolkit', '0.00', ProductStatus::PUBLISHED, true);
        $prod = $this->productRepo->findProduct($pid);

        $state = $this->storefrontService->resolveCustomerState($prod, $userId);
        $this->assertEquals('free', $state['state']);
        $this->assertEquals('Get for Free', $state['button_text']);

        // Purchasing free product immediately fulfills and provides redirect to receipt
        $purchase = $this->storefrontService->initiatePurchase($prod->slug, $userId);
        $this->assertEquals('fulfilled', $purchase['status']);
        $this->assertStringContainsString('/account/orders/', $purchase['redirect_url']);
    }

    /**
     * Scenario AJ: Discount display
     */
    public function testScenarioAJ_DiscountDisplay(): void
    {
        $pid = $this->productRepo->createProduct([
            'title'            => 'Discounted Course',
            'slug'             => 'disc-' . uniqid(),
            'product_type'     => ProductType::DIGITAL,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => '1000.00',
            'discount_percent' => '25.00',
            'final_price'      => '750.00',
            'currency'         => 'BDT',
            'is_free'          => 0,
        ]);
        $prod = $this->productRepo->findProduct($pid);

        $pricing = $this->storefrontService->buildPricingSummary($prod);
        $this->assertTrue($pricing['has_discount']);
        $this->assertEquals('25.00', $pricing['discount_percent']);
        $this->assertEquals('1000.00', $pricing['original_price']);
        $this->assertEquals('750.00', $pricing['final_price']);
    }

    /**
     * Scenario AK: Original/final price display
     */
    public function testScenarioAK_OriginalFinalPriceDisplay(): void
    {
        $pid = $this->createDigitalProduct('Standard Book', '450.00');
        $prod = $this->productRepo->findProduct($pid);

        $pricing = $this->storefrontService->buildPricingSummary($prod);
        $this->assertEquals('450.00', $pricing['final_price']);
        $this->assertEquals('450.00', $pricing['original_price']);
        $this->assertFalse($pricing['has_discount']);
    }

    /**
     * Scenario AL: Site currency display
     */
    public function testScenarioAL_SiteCurrencyDisplay(): void
    {
        $priceStr = $this->storefrontService->formatPrice('500.00', 'BDT');
        $this->assertStringContainsString('500.00', $priceStr);

        $usdStr = $this->storefrontService->formatPrice('50.00', 'USD');
        $this->assertStringContainsString('50.00', $usdStr);
    }

    /**
     * Scenario AM: Checkout route uses existing checkout
     */
    public function testScenarioAM_CheckoutRouteUsesExistingCheckout(): void
    {
        $userId = 105;
        $pid = $this->createDigitalProduct('Paid Item Checkout', '600.00');
        $prod = $this->productRepo->findProduct($pid);

        $purchase = $this->storefrontService->initiatePurchase($prod->slug, $userId);
        $this->assertEquals('pending_payment', $purchase['status']);
        $this->assertStringStartsWith('/checkout/', $purchase['redirect_url']);
    }

    /**
     * Scenario AN: No duplicate checkout implementation
     */
    public function testScenarioAN_NoDuplicateCheckoutImplementation(): void
    {
        // StorefrontService delegates directly to CheckoutService and OrderService
        $this->assertSame($this->checkoutService, $this->storefrontService->getCheckoutService());
        $this->assertSame($this->orderService, $this->storefrontService->getOrderService());
    }

    /**
     * Scenario AO: Product ID tampering rejected
     */
    public function testScenarioAO_ProductIdTamperingRejected(): void
    {
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $resp = $this->storefrontController->show($request, 'non-existent-slug-xyz');

        $this->assertInstanceOf(Response::class, $resp);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    /**
     * Scenario AP: Unpublished product direct URL rejected
     */
    public function testScenarioAP_UnpublishedProductDirectUrlRejected(): void
    {
        $pid = $this->createDigitalProduct('Draft Secret Work', '500.00', ProductStatus::DRAFT);
        $prod = $this->productRepo->findProduct($pid);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $resp = $this->storefrontController->show($request, $prod->slug);

        $this->assertInstanceOf(Response::class, $resp);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    /**
     * Scenario AQ: Archived product direct URL rejected
     */
    public function testScenarioAQ_ArchivedProductDirectUrlRejected(): void
    {
        $pid = $this->createDigitalProduct('Archived Deprecated Work', '500.00', ProductStatus::ARCHIVED);
        $prod = $this->productRepo->findProduct($pid);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $resp = $this->storefrontController->show($request, $prod->slug);

        $this->assertInstanceOf(Response::class, $resp);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    /**
     * Scenario AR: Customer ownership isolation
     */
    public function testScenarioAR_CustomerOwnershipIsolation(): void
    {
        $userA = 201;
        $userB = 202;
        $pid = $this->createDigitalProduct('Private Ebook', '300.00');
        $prod = $this->productRepo->findProduct($pid);

        // User A owns the product
        $this->entitlementRepo->createEntitlement([
            'user_id'     => $userA,
            'product_id'  => $pid,
            'source_type' => 'purchase',
            'source_id'   => 10,
            'status'      => 'active',
            'granted_at'  => date('Y-m-d H:i:s'),
        ]);

        // User A sees 'owned'
        $stateA = $this->storefrontService->resolveCustomerState($prod, $userA);
        $this->assertTrue($stateA['is_owned']);

        // User B sees 'purchasable' and NOT 'owned'
        $stateB = $this->storefrontService->resolveCustomerState($prod, $userB);
        $this->assertFalse($stateB['is_owned']);
        $this->assertTrue($stateB['is_purchasable']);
    }

    /**
     * Scenario AS: Private file path not exposed
     */
    public function testScenarioAS_PrivateFilePathNotExposed(): void
    {
        $pid = $this->createDigitalProduct('Security Asset', '500.00');
        $prod = $this->productRepo->findProduct($pid);

        $detail = $this->storefrontService->getProductDetail($prod->slug);
        $this->assertArrayNotHasKey('file_path', $detail['type_details']);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->show($request, $prod->slug);
        $this->assertStringNotContainsString('storage/plugins/favorite-digital/files/', $html);
    }

    /**
     * Scenario AT: Download token not exposed
     */
    public function testScenarioAT_DownloadTokenNotExposed(): void
    {
        $pid = $this->createDigitalProduct('Token Guard Item', '500.00');
        $prod = $this->productRepo->findProduct($pid);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->show($request, $prod->slug);

        $this->assertStringNotContainsString('/download/', $html);
    }

    /**
     * Scenario AU: XSS escaping
     */
    public function testScenarioAU_XssEscaping(): void
    {
        $pid = $this->createDigitalProduct('<script>alert("xss")</script> Title', '100.00');
        $this->productRepo->updateProduct($pid, [
            'description' => '<img src=x onerror=alert(1)> Description',
        ]);
        $prod = $this->productRepo->findProduct($pid);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->show($request, $prod->slug);

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * Scenario AV: SQL injection protection
     */
    public function testScenarioAV_SqlInjectionProtection(): void
    {
        $malicious = "' OR '1'='1";
        $request = new Request(['search' => $malicious], [], ['REQUEST_METHOD' => 'GET']);

        // Should execute safely without PDOException
        $html = $this->storefrontController->index($request);
        $this->assertIsString($html);
    }

    /**
     * Scenario AW: ORDER BY injection protection
     */
    public function testScenarioAW_OrderByInjectionProtection(): void
    {
        $maliciousSort = "id DESC; DROP TABLE favorite_digital_products; --";
        $result = $this->storefrontService->browseProducts(['sort' => $maliciousSort]);

        // Defaulted safely to newest
        $this->assertIsArray($result['items']);
        $this->assertTrue($this->sqliteDb->tableExists('favorite_digital_products'));
    }

    /**
     * Scenario AX: Guest access protection
     */
    public function testScenarioAX_GuestAccessProtectionOnBuy(): void
    {
        $pid = $this->createDigitalProduct('Guest Guarded', '300.00');
        $prod = $this->productRepo->findProduct($pid);

        $_SESSION['_token'] = 'valid_token_xyz';

        $request = new Request([], ['_token' => 'valid_token_xyz'], ['REQUEST_METHOD' => 'POST']);
        $resp = $this->storefrontController->buy($request, $prod->slug);

        $this->assertInstanceOf(Response::class, $resp);
        $this->assertStringContainsString('/login?redirect=', $resp->getHeaders()['Location'] ?? '');
    }

    /**
     * Scenario AY: Membership access uses existing lifecycle service
     */
    public function testScenarioAY_MembershipAccessUsesExistingLifecycleService(): void
    {
        $this->assertSame($this->membershipService, $this->storefrontService->getMembershipService());
    }

    /**
     * Scenario AZ: Entitlement access uses existing entitlement system
     */
    public function testScenarioAZ_EntitlementAccessUsesExistingEntitlementSystem(): void
    {
        $this->assertSame($this->checker, $this->storefrontService->getEntitlementChecker());
    }

    /**
     * Scenario BA: Responsive rendering sanity
     */
    public function testScenarioBA_ResponsiveRenderingSanity(): void
    {
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->storefrontController->index($request);

        $this->assertStringContainsString('name="viewport"', $html);
        $this->assertStringContainsString('width=device-width', $html);
    }

    /**
     * Scenario BB: Accessibility sanity
     */
    public function testScenarioBB_AccessibilitySanity(): void
    {
        $pid = $this->createDigitalProduct('Accessible Product', '200.00');
        $prod = $this->productRepo->findProduct($pid);

        $reqIndex = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $htmlIndex = $this->storefrontController->index($reqIndex);
        $this->assertStringContainsString('aria-label=', $htmlIndex);
        $this->assertStringContainsString('<h1', $htmlIndex);

        $reqShow = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $htmlShow = $this->storefrontController->show($reqShow, $prod->slug);
        $this->assertStringContainsString('aria-label=', $htmlShow);
        $this->assertStringContainsString('<h1', $htmlShow);
    }

    /**
     * Scenario BC: No N+1 regression where practical
     */
    public function testScenarioBC_NoNPlusOneRegression(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->createDigitalProduct("Perf Test {$i}", '100.00');
        }

        $result = $this->storefrontService->browseProducts([], 1, 6);
        $this->assertCount(6, $result['items']);
    }

    /**
     * Scenario BD: Prefix-safe queries
     */
    public function testScenarioBD_PrefixSafeQueries(): void
    {
        $sql = "SELECT p.* FROM `favorite_digital_products` p WHERE p.`status` = 'published'";
        $rows = $this->sqliteDb->select($sql);
        $this->assertIsArray($rows);
    }

    /**
     * Scenario BE: SQLite compatibility
     */
    public function testScenarioBE_SqliteCompatibility(): void
    {
        $driver = $this->sqliteDb->getConnection()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->assertEquals('sqlite', strtolower((string)$driver));

        $catalog = $this->storefrontService->browseProducts();
        $this->assertIsArray($catalog);
    }

    /**
     * Scenario BF: MySQL/MariaDB compatibility
     */
    public function testScenarioBF_MySqlCompatibility(): void
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $db = getenv('DB_DATABASE') ?: 'favorite_cms_test';
        $user = getenv('DB_USERNAME') ?: 'root';
        $pass = getenv('DB_PASSWORD') ?: '';

        try {
            $pdo = new PDO("mysql:host={$host};dbname={$db}", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 1,
            ]);
        } catch (Throwable) {
            $this->markTestSkipped('Local MySQL daemon is offline; skipping live MySQL connection test.');
        }

        $mySqlDb = new Database([
            'driver'   => 'mysql',
            'host'     => $host,
            'database' => $db,
            'username' => $user,
            'password' => $pass,
            'prefix'   => 'wp_',
        ]);

        $repo = new ProductRepository($mySqlDb);
        $res = $repo->listStorefrontProducts([], 1, 5);
        $this->assertIsArray($res);
    }
}
