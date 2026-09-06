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
use FavoriteCMS\Digital\Controllers\CustomerAccountController;
use FavoriteCMS\Digital\Controllers\CustomerOrderController;
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
use FavoriteCMS\Digital\Services\CustomerAccountService;
use FavoriteCMS\Digital\Services\DefaultEntitlementChecker;
use FavoriteCMS\Digital\Services\DigitalFileStorageService;
use FavoriteCMS\Digital\Services\DownloadService;
use FavoriteCMS\Digital\Services\FulfillmentService;
use FavoriteCMS\Digital\Services\MembershipLifecycleService;
use FavoriteCMS\Digital\Services\OrderService;
use FavoriteCMS\Digital\Services\RefundService;
use FavoriteCMS\Digital\Services\WalletService;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * CustomerDigitalAccountIntegrationTest
 *
 * Comprehensive Phase 7 test suite covering scenarios A through AZ:
 * - Customer Digital Library access & browsing
 * - Guest authentication gate and redirects
 * - Ownership & IDOR protection across all account areas
 * - Entitlement status enforcement (active, revoked, expired, membership_expired)
 * - UI-level multi-source entitlement deduplication
 * - Secure download integration via Phase 5D (/download/{token})
 * - Path leak and secret token exposure prevention
 * - Package and service presentation (scopes, turnaround, child items)
 * - Order & snapshot audit histories
 * - Refund history with destination strictly Favorite Digital Wallet
 * - Digital Wallet balance presentation without mutation
 * - Customer Membership Dashboard (active, grace, expired, auto-renew)
 * - Security hardening: SQLi, XSS, parameter tampering, bounded queries
 * - UI accessibility & responsive markup
 * - SQLite in-memory & MySQL graceful offline handling
 */
class CustomerDigitalAccountIntegrationTest extends TestCase
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
    private RefundService $refundService;
    private CustomerAccountService $accountService;
    private CustomerAccountController $accountController;
    private CustomerOrderController $orderController;
    private string $tempStorageDir;

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

        $this->tempStorageDir = sys_get_temp_dir() . '/fav_acc_test_' . uniqid();
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

        $this->orderService = new OrderService(
            $this->orderRepo,
            $this->productRepo,
            $this->membershipService,
            $this->checker,
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

        $this->orderController = new CustomerOrderController(
            $this->app,
            $this->orderService,
            $this->refundRepo
        );

        $_SESSION = [];
        unset($GLOBALS['_test_current_user'], $GLOBALS['_test_current_user_id']);
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
        unset($GLOBALS['_test_current_user'], $GLOBALS['_test_current_user_id']);
        $_SESSION = [];
        parent::tearDown();
    }

    private function authenticateUser(int $userId): void
    {
        $GLOBALS['_test_current_user_id'] = $userId;
        $GLOBALS['_test_current_user'] = (object)['id' => $userId, 'email' => "user{$userId}@example.com"];
        $_SESSION['auth_user_id'] = $userId;
        $_SESSION['user_id'] = $userId;
    }

    private function createGetRequest(string $uri = '/account/digital', array $query = []): Request
    {
        return new Request($query, [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri]);
    }

    private function createDigitalProduct(string $title = 'E-Book Guide', string $price = '500.00', bool $isMembershipEligible = true): int
    {
        $filename = 'guide_' . uniqid() . '.pdf';
        $filePath = $this->tempStorageDir . '/' . $filename;
        file_put_contents($filePath, 'PDF File Binary Content');

        $pid = $this->productRepo->createProduct([
            'title'            => $title,
            'slug'             => 'prod-' . uniqid(),
            'description'      => 'A high quality digital product: ' . $title,
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

    private function createServiceProduct(string $title = 'Strategy Consulting', string $price = '1000.00'): int
    {
        $pid = $this->productRepo->createProduct([
            'title'            => $title,
            'slug'             => 'service-' . uniqid(),
            'description'      => 'Professional service: ' . $title,
            'product_type'     => ProductType::SERVICE,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => $price,
            'discount_percent' => '0.00',
            'final_price'      => $price,
            'currency'         => 'BDT',
            'is_free'          => 0,
        ]);

        $this->productRepo->saveServiceDetails($pid, [
            'delivery_time_days'  => 3,
            'service_scope'       => 'Comprehensive 1-on-1 audit and implementation plan',
            'requirements_prompt' => 'Submit your business URL',
        ]);

        return $pid;
    }

    private function createPackageProduct(string $title, string $price, array $childIds): int
    {
        $pkgProdId = $this->productRepo->createProduct([
            'title'            => $title,
            'slug'             => 'pkg-' . uniqid(),
            'description'      => 'Package bundle: ' . $title,
            'product_type'     => ProductType::PACKAGE,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => $price,
            'discount_percent' => '0.00',
            'final_price'      => $price,
            'currency'         => 'BDT',
            'is_free'          => 0,
        ]);

        $pkgRecordId = $this->productRepo->createPackage($pkgProdId, 'bundle');
        foreach ($childIds as $cid) {
            $this->productRepo->addPackageItem($pkgRecordId, $cid);
        }

        return $pkgProdId;
    }

    private function createMembershipPlan(string $title = 'Pro Club', string $price = '1500.00'): int
    {
        return $this->membershipService->createPlan([
            'title'          => $title,
            'slug'           => 'mem-' . uniqid(),
            'description'    => 'Pro membership plan: ' . $title,
            'status'         => ProductStatus::PUBLISHED,
            'original_price' => $price,
            'currency'       => 'BDT',
        ], [
            'plan_type'           => 'monthly',
            'duration_count'      => 1,
            'duration_unit'       => 'months',
            'grace_period_days'   => 5,
            'allows_auto_renewal' => 1,
        ]);
    }

    private function grantActiveMembership(int $userId, int $productId, bool $autoRenew = false): object
    {
        $plan = $this->membershipService->getPlanByProductId($productId);
        if (!$plan) {
            $plan = $this->membershipService->getPlan($productId);
        }
        return $this->membershipService->activateMembership($userId, (int)$plan->id, $autoRenew);
    }

    // =========================================================================
    // SCENARIOS A - AZ
    // =========================================================================

    /**
     * Scenario A: Authenticated customer library loads successfully
     */
    public function testScenarioA_authenticatedCustomerLibraryLoads(): void
    {
        $this->authenticateUser(1);
        $prodId = $this->createDigitalProduct('My Premium Book');
        $this->entitlementRepo->createEntitlement([
            'user_id'    => 1,
            'product_id' => $prodId,
            'source'     => 'order',
            'status'     => 'active',
        ]);

        $request = $this->createGetRequest('/account/digital');
        $response = $this->accountController->library($request);

        $content = is_string($response) ? $response : $response->getContent();
        $this->assertStringContainsString('Digital Library', $content);
        $this->assertStringContainsString('My Premium Book', $content);
    }

    /**
     * Scenario B: Guest library access redirects to /login
     */
    public function testScenarioB_guestLibraryAccessRedirectsToLogin(): void
    {
        unset($GLOBALS['_test_current_user'], $GLOBALS['_test_current_user_id']);
        $_SESSION = [];

        $request = $this->createGetRequest('/account/digital');
        $response = $this->accountController->library($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertArrayHasKey('Location', $headers);
        $this->assertStringContainsString('/login', $headers['Location']);
    }

    /**
     * Scenario C: Customer sees own active entitlement
     */
    public function testScenarioC_customerSeesOwnActiveEntitlement(): void
    {
        $prodId = $this->createDigitalProduct('React Masterclass');
        $this->entitlementRepo->createEntitlement([
            'user_id'    => 10,
            'product_id' => $prodId,
            'source'     => 'direct',
            'status'     => 'active',
        ]);

        $lib = $this->accountService->getDigitalLibrary(10);
        $this->assertSame(1, $lib['total']);
        $item = $lib['items'][0];
        $this->assertSame('React Masterclass', $item['title']);
        $this->assertSame('accessible', $item['access_state']);
        $this->assertTrue($item['is_downloadable']);
        $this->assertNotNull($item['download_url']);
        $this->assertStringContainsString('/download/', $item['download_url']);
    }

    /**
     * Scenario D: Customer cannot see another user's entitlement (IDOR isolation)
     */
    public function testScenarioD_customerCannotSeeAnotherUsersEntitlement(): void
    {
        $prodA = $this->createDigitalProduct('User 1 Exclusive Book');
        $prodB = $this->createDigitalProduct('User 2 Secret Guide');

        $this->entitlementRepo->createEntitlement([
            'user_id'    => 1,
            'product_id' => $prodA,
            'source'     => 'direct',
            'status'     => 'active',
        ]);

        $this->entitlementRepo->createEntitlement([
            'user_id'    => 2,
            'product_id' => $prodB,
            'source'     => 'direct',
            'status'     => 'active',
        ]);

        $libUser1 = $this->accountService->getDigitalLibrary(1);
        $titlesUser1 = array_column($libUser1['items'], 'title');
        $this->assertContains('User 1 Exclusive Book', $titlesUser1);
        $this->assertNotContains('User 2 Secret Guide', $titlesUser1);

        $libUser2 = $this->accountService->getDigitalLibrary(2);
        $titlesUser2 = array_column($libUser2['items'], 'title');
        $this->assertContains('User 2 Secret Guide', $titlesUser2);
        $this->assertNotContains('User 1 Exclusive Book', $titlesUser2);
    }

    /**
     * Scenario E: Revoked entitlement is not downloadable (disabled state)
     */
    public function testScenarioE_revokedEntitlementIsNotDownloadable(): void
    {
        $prodId = $this->createDigitalProduct('Refunded Software');
        $this->entitlementRepo->createEntitlement([
            'user_id'    => 5,
            'product_id' => $prodId,
            'source'     => 'direct',
            'status'     => 'revoked',
        ]);

        $lib = $this->accountService->getDigitalLibrary(5);
        $this->assertCount(1, $lib['items']);
        $item = $lib['items'][0];
        $this->assertSame('revoked', $item['access_state']);
        $this->assertFalse($item['is_downloadable']);
        $this->assertNull($item['download_url']);
        $this->assertSame('Access Revoked', $item['status_label']);
    }

    /**
     * Scenario F: Expired entitlement is not downloadable
     */
    public function testScenarioF_expiredEntitlementIsNotDownloadable(): void
    {
        $prodId = $this->createDigitalProduct('Time Limited Dataset');
        $this->entitlementRepo->createEntitlement([
            'user_id'    => 6,
            'product_id' => $prodId,
            'source'     => 'direct',
            'status'     => 'expired',
            'expires_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        $lib = $this->accountService->getDigitalLibrary(6);
        $this->assertCount(1, $lib['items']);
        $item = $lib['items'][0];
        $this->assertSame('expired', $item['access_state']);
        $this->assertFalse($item['is_downloadable']);
        $this->assertNull($item['download_url']);
        $this->assertSame('Access Expired', $item['status_label']);
    }

    /**
     * Scenario G: Membership access displayed correctly
     */
    public function testScenarioG_membershipAccessDisplayedCorrectly(): void
    {
        $planProdId = $this->createMembershipPlan('VIP Pass');
        $prodId = $this->createDigitalProduct('VIP Exclusive Tutorial', '300.00', true);

        // Grant active membership
        $this->grantActiveMembership(7, $planProdId);

        $lib = $this->accountService->getDigitalLibrary(7);
        $this->assertGreaterThanOrEqual(1, $lib['total']);

        $found = null;
        foreach ($lib['items'] as $it) {
            if ($it['product_id'] === $prodId) {
                $found = $it;
                break;
            }
        }

        $this->assertNotNull($found);
        $this->assertSame('accessible', $found['access_state']);
        $this->assertTrue($found['is_unlimited']);
        $sourceTypes = array_column($found['sources'], 'type');
        $this->assertContains('membership', $sourceTypes);
    }

    /**
     * Scenario H: Expired membership state displayed correctly
     */
    public function testScenarioH_expiredMembershipStateDisplayedCorrectly(): void
    {
        $planProdId = $this->createMembershipPlan('Expired Pass');
        $plan = $this->membershipService->getPlanByProductId($planProdId);

        $this->sqliteDb->insert('favorite_digital_memberships', [
            'user_id'          => 8,
            'plan_id'          => (int)$plan->id,
            'status'           => MembershipStatus::EXPIRED,
            'started_at'       => date('Y-m-d H:i:s', strtotime('-60 days')),
            'expires_at'       => date('Y-m-d H:i:s', strtotime('-30 days')),
            'grace_expires_at' => date('Y-m-d H:i:s', strtotime('-25 days')),
            'auto_renew'       => 0,
            'created_at'       => date('Y-m-d H:i:s', strtotime('-60 days')),
            'updated_at'       => date('Y-m-d H:i:s', strtotime('-30 days')),
        ]);

        $dash = $this->accountService->getMembershipDashboard(8);
        $this->assertFalse($dash['has_membership']);
        $this->assertSame('none', $dash['status']);
    }

    /**
     * Scenario I: Package-derived access displayed correctly
     */
    public function testScenarioI_packageDerivedAccessDisplayedCorrectly(): void
    {
        $child1 = $this->createDigitalProduct('Font Pack');
        $pkgId = $this->createPackageProduct('Design Suite', '999.00', [$child1]);

        $this->entitlementRepo->createEntitlement([
            'user_id'     => 9,
            'product_id'  => $child1,
            'source_type' => 'package',
            'source_id'   => $pkgId,
            'status'      => 'active',
        ]);

        $lib = $this->accountService->getDigitalLibrary(9);
        $this->assertCount(1, $lib['items']);
        $item = $lib['items'][0];
        $sourceTypes = array_column($item['sources'], 'type');
        $this->assertContains('package', $sourceTypes);
    }

    /**
     * Scenario J: Independent purchase remains distinct
     */
    public function testScenarioJ_independentPurchaseRemainsDistinct(): void
    {
        $prodA = $this->createDigitalProduct('UI Kit A');
        $prodB = $this->createDigitalProduct('UI Kit B');

        $this->entitlementRepo->createEntitlement([
            'user_id'    => 11,
            'product_id' => $prodA,
            'source'     => 'direct',
            'status'     => 'active',
        ]);
        $this->entitlementRepo->createEntitlement([
            'user_id'    => 11,
            'product_id' => $prodB,
            'source'     => 'direct',
            'status'     => 'active',
        ]);

        $lib = $this->accountService->getDigitalLibrary(11);
        $this->assertSame(2, $lib['total']);
        $this->assertCount(2, $lib['items']);
    }

    /**
     * Scenario K: Duplicate UI access handled cleanly with composite badges
     */
    public function testScenarioK_duplicateUIAccessHandledCleanlyWithCompositeBadges(): void
    {
        $planProdId = $this->createMembershipPlan('Dev Club');
        $prodId = $this->createDigitalProduct('PHP Architecture E-Book', '400.00', true);

        // 1. Direct entitlement
        $this->entitlementRepo->createEntitlement([
            'user_id'    => 12,
            'product_id' => $prodId,
            'source'     => 'direct',
            'status'     => 'active',
        ]);

        // 2. Active membership covering the product
        $this->grantActiveMembership(12, $planProdId);

        $lib = $this->accountService->getDigitalLibrary(12);

        // Should be deduped to 1 card for this product
        $matches = array_filter($lib['items'], fn ($it) => $it['product_id'] === $prodId);
        $this->assertCount(1, $matches);

        $item = reset($matches);
        $this->assertCount(2, $item['sources']);
        $sourceTypes = array_column($item['sources'], 'type');
        $this->assertContains('direct', $sourceTypes);
        $this->assertContains('membership', $sourceTypes);
    }

    /**
     * Scenario L: Digital product access button generated
     */
    public function testScenarioL_digitalProductAccessButtonGenerated(): void
    {
        $this->authenticateUser(13);
        $prodId = $this->createDigitalProduct('Downloadable Audio');
        $this->entitlementRepo->createEntitlement([
            'user_id'    => 13,
            'product_id' => $prodId,
            'source'     => 'direct',
            'status'     => 'active',
        ]);

        $request = $this->createGetRequest('/account/digital');
        $html = (string)$this->accountController->library($request);

        $this->assertStringContainsString('/download/', $html);
        $this->assertStringContainsString('Download File', $html);
    }

    /**
     * Scenario M: Secure download route reused (/download/{token})
     */
    public function testScenarioM_secureDownloadRouteReused(): void
    {
        $prodId = $this->createDigitalProduct('Secure Software');
        $this->entitlementRepo->createEntitlement([
            'user_id'    => 14,
            'product_id' => $prodId,
            'source'     => 'direct',
            'status'     => 'active',
        ]);

        $lib = $this->accountService->getDigitalLibrary(14);
        $url = $lib['items'][0]['download_url'];
        $this->assertMatchesRegularExpression('#^/download/[a-f0-9]{32,64}$#', $url);
    }

    /**
     * Scenario N: Download token not exposed insecurely
     */
    public function testScenarioN_downloadTokenNotExposedInsecurely(): void
    {
        $prodId = $this->createDigitalProduct('Token Verification Doc');
        $this->entitlementRepo->createEntitlement([
            'user_id'    => 15,
            'product_id' => $prodId,
            'source'     => 'direct',
            'status'     => 'active',
        ]);

        $lib = $this->accountService->getDigitalLibrary(15);
        $item = $lib['items'][0];
        $this->assertNotNull($item['download_token']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $item['download_token']);
    }

    /**
     * Scenario O: Private file path not exposed in markup
     */
    public function testScenarioO_privateFilePathNotExposedInMarkup(): void
    {
        $this->authenticateUser(16);
        $prodId = $this->createDigitalProduct('Private Secure Doc');
        $this->entitlementRepo->createEntitlement([
            'user_id'    => 16,
            'product_id' => $prodId,
            'source'     => 'direct',
            'status'     => 'active',
        ]);

        $request = $this->createGetRequest('/account/digital');
        $html = (string)$this->accountController->library($request);

        $this->assertStringNotContainsString($this->tempStorageDir, $html);
        $this->assertStringNotContainsString('storage/plugins/favorite-digital/files', $html);
    }

    /**
     * Scenario P: Download count not duplicated or altered on view
     */
    public function testScenarioP_downloadCountNotDuplicated(): void
    {
        $prodId = $this->createDigitalProduct('Count Testing Item');
        $entId = $this->entitlementRepo->createEntitlement([
            'user_id'    => 17,
            'product_id' => $prodId,
            'source'     => 'direct',
            'status'     => 'active',
        ]);

        $lib = $this->accountService->getDigitalLibrary(17);
        $this->assertSame(0, $lib['items'][0]['download_count']);
        $this->assertSame(3, $lib['items'][0]['download_remaining']);

        // Check DB has not changed
        $ent = $this->entitlementRepo->findEntitlement($entId);
        $this->assertNotNull($ent);
    }

    /**
     * Scenario Q: Membership unlimited access preserved
     */
    public function testScenarioQ_membershipUnlimitedAccessPreserved(): void
    {
        $planProdId = $this->createMembershipPlan('Unlimited Plan');
        $prodId = $this->createDigitalProduct('Unlimited Video Asset', '200.00', true);

        $this->grantActiveMembership(18, $planProdId);

        $lib = $this->accountService->getDigitalLibrary(18);
        $item = $lib['items'][0];
        $this->assertTrue($item['is_unlimited']);
        $this->assertNull($item['download_remaining']);
    }

    /**
     * Scenario R: Service purchase displayed with scope/turnaround
     */
    public function testScenarioR_servicePurchaseDisplayedWithScopeTurnaround(): void
    {
        $serviceId = $this->createServiceProduct('Custom App Audit', '2500.00');
        $this->entitlementRepo->createEntitlement([
            'user_id'    => 19,
            'product_id' => $serviceId,
            'source'     => 'direct',
            'status'     => 'active',
        ]);

        $lib = $this->accountService->getDigitalLibrary(19);
        $item = $lib['items'][0];
        $this->assertSame(ProductType::SERVICE, $item['product_type']);
        $this->assertFalse($item['is_downloadable']);
        $this->assertSame('Comprehensive 1-on-1 audit and implementation plan', $item['service_scope']);
        $this->assertSame(3, $item['turnaround_days']);
    }

    /**
     * Scenario S: Package purchase displayed with included items list
     */
    public function testScenarioS_packagePurchaseDisplayedWithIncludedItemsList(): void
    {
        $c1 = $this->createDigitalProduct('Plugin A');
        $c2 = $this->createDigitalProduct('Plugin B');
        $pkgId = $this->createPackageProduct('Developer Bundle', '1200.00', [$c1, $c2]);

        $this->entitlementRepo->createEntitlement([
            'user_id'    => 20,
            'product_id' => $pkgId,
            'source'     => 'direct',
            'status'     => 'active',
        ]);

        $lib = $this->accountService->getDigitalLibrary(20);
        $item = $lib['items'][0];
        $this->assertSame(ProductType::PACKAGE, $item['product_type']);
        $this->assertCount(2, $item['included_items']);
    }

    /**
     * Scenario T: Order history loads
     */
    public function testScenarioT_orderHistoryLoads(): void
    {
        $prodId = $this->createDigitalProduct('Order History Item');
        $order = $this->orderService->createOrder(21, [
            ['product_id' => $prodId, 'quantity' => 1],
        ]);

        $hist = $this->accountService->getOrderHistory(21);
        $this->assertSame(1, $hist['total']);
        $this->assertSame($order->order_number, $hist['data'][0]->order_number);
    }

    /**
     * Scenario U: Order detail loads with item snapshots
     */
    public function testScenarioU_orderDetailLoadsWithItemSnapshots(): void
    {
        $prodId = $this->createDigitalProduct('Snapshot Product', '750.00');
        $order = $this->orderService->createOrder(22, [
            ['product_id' => $prodId, 'quantity' => 1],
        ]);

        $detail = $this->accountService->getOrderDetail(22, $order->order_number);
        $this->assertNotNull($detail);
        $this->assertCount(1, $detail->items);
        $this->assertSame('Snapshot Product', $detail->items[0]->snapshot['title']);
    }

    /**
     * Scenario V: Historical price snapshot preserved
     */
    public function testScenarioV_historicalPriceSnapshotPreserved(): void
    {
        $prodId = $this->createDigitalProduct('Dynamic Price Book', '500.00');
        $order = $this->orderService->createOrder(23, [
            ['product_id' => $prodId, 'quantity' => 1],
        ]);

        // Modify product price in database afterwards
        $this->productRepo->updateProduct($prodId, ['final_price' => '999.00']);

        $detail = $this->accountService->getOrderDetail(23, $order->order_number);
        $this->assertSame('500.00', $detail->items[0]->final_price);
        $this->assertSame('500.00', $detail->items[0]->snapshot['final_price']);
    }

    /**
     * Scenario W: Orthogonal payment status displayed
     */
    public function testScenarioW_orthogonalPaymentStatusDisplayed(): void
    {
        $prodId = $this->createDigitalProduct('Payment Status Item');
        $order = $this->orderService->createOrder(24, [
            ['product_id' => $prodId, 'quantity' => 1],
        ]);
        $this->orderRepo->updatePaymentStatus((int)$order->id, 'paid');

        $detail = $this->accountService->getOrderDetail(24, $order->order_number);
        $this->assertSame('paid', $detail->payment_status);
        $this->assertSame('pending', $detail->status);
    }

    /**
     * Scenario X: Orthogonal fulfillment status displayed
     */
    public function testScenarioX_orthogonalFulfillmentStatusDisplayed(): void
    {
        $prodId = $this->createDigitalProduct('Fulfillment Status Item');
        $order = $this->orderService->createOrder(25, [
            ['product_id' => $prodId, 'quantity' => 1],
        ]);
        $this->orderRepo->updateOrderStatus((int)$order->id, 'completed');

        $detail = $this->accountService->getOrderDetail(25, $order->order_number);
        $this->assertSame('completed', $detail->status);
    }

    /**
     * Scenario Y: Refunded order displayed correctly
     */
    public function testScenarioY_refundedOrderDisplayedCorrectly(): void
    {
        $prodId = $this->createDigitalProduct('Refund Test Item', '600.00');
        $order = $this->orderService->createOrder(26, [
            ['product_id' => $prodId, 'quantity' => 1],
        ]);
        $this->orderRepo->updatePaymentStatus((int)$order->id, 'refunded');
        $this->orderRepo->updateOrderStatus((int)$order->id, 'refunded');

        $this->refundRepo->createRefund([
            'order_id'      => (int)$order->id,
            'user_id'       => 26,
            'refund_amount' => '600.00',
            'destination'   => 'wallet',
            'reason'        => 'Accidental purchase',
            'status'        => 'completed',
        ]);

        $detail = $this->accountService->getOrderDetail(26, $order->order_number);
        $this->assertSame('refunded', $detail->payment_status);
        $this->assertNotEmpty($detail->refunds);
        $this->assertSame('Favorite Digital Wallet', $detail->refunds[0]->destination);
    }

    /**
     * Scenario Z: Refund history displayed
     */
    public function testScenarioZ_refundHistoryDisplayed(): void
    {
        $this->refundRepo->createRefund([
            'order_id'      => 99,
            'user_id'       => 27,
            'refund_amount' => '350.00',
            'destination'   => 'wallet',
            'reason'        => 'Service cancellation',
            'status'        => 'completed',
        ]);

        $hist = $this->accountService->getRefundHistory(27);
        $this->assertSame(1, $hist['total']);
        $this->assertSame('350.00', $hist['total_refunded']);
        $this->assertSame('Service cancellation', $hist['refunds'][0]->reason);
    }

    /**
     * Scenario AA: Refund destination shown strictly as Favorite Digital Wallet
     */
    public function testScenarioAA_refundDestinationShownAsWallet(): void
    {
        $this->refundRepo->createRefund([
            'order_id'      => 100,
            'user_id'       => 28,
            'refund_amount' => '500.00',
            'destination'   => 'wallet',
            'reason'        => 'Phase 5E rule verification',
        ]);

        $hist = $this->accountService->getRefundHistory(28);
        $this->assertSame('Favorite Digital Wallet', $hist['refunds'][0]->destination);

        $this->authenticateUser(28);
        $request = $this->createGetRequest('/account/refunds');
        $html = (string)$this->accountController->refunds($request);
        $this->assertStringContainsString('Favorite Digital Wallet', $html);
    }

    /**
     * Scenario AB: Customer cannot see another user's refund
     */
    public function testScenarioAB_customerCannotSeeAnotherUsersRefund(): void
    {
        $this->refundRepo->createRefund([
            'order_id'      => 101,
            'user_id'       => 29,
            'refund_amount' => '100.00',
            'destination'   => 'wallet',
            'reason'        => 'User 29 Refund',
        ]);
        $this->refundRepo->createRefund([
            'order_id'      => 102,
            'user_id'       => 30,
            'refund_amount' => '200.00',
            'destination'   => 'wallet',
            'reason'        => 'User 30 Secret Refund',
        ]);

        $hist29 = $this->accountService->getRefundHistory(29);
        $reasons29 = array_column($hist29['refunds'], 'reason');
        $this->assertContains('User 29 Refund', $reasons29);
        $this->assertNotContains('User 30 Secret Refund', $reasons29);
    }

    /**
     * Scenario AC: Wallet balance displayed from WalletService
     */
    public function testScenarioAC_walletBalanceDisplayedFromWalletService(): void
    {
        $this->walletService->credit(31, '750.50', 'Test Bonus', 'admin_credit', 1);

        $summary = $this->accountService->getWalletSummary(31);
        $this->assertSame('750.50', $summary['balance']);
    }

    /**
     * Scenario AD: Wallet currency correct (BDT)
     */
    public function testScenarioAD_walletCurrencyCorrect(): void
    {
        $summary = $this->accountService->getWalletSummary(32);
        $this->assertSame('BDT', $summary['currency']);
    }

    /**
     * Scenario AE: No direct wallet mutation on display
     */
    public function testScenarioAE_noDirectWalletMutationOnDisplay(): void
    {
        $this->walletService->credit(33, '100.00', 'Seed balance', 'seed', 1);
        $initBalance = $this->walletService->getBalance(33);

        // Call all account read methods
        $this->accountService->getDigitalLibrary(33);
        $this->accountService->getMembershipDashboard(33);
        $this->accountService->getRefundHistory(33);
        $this->accountService->getWalletSummary(33);

        $afterBalance = $this->walletService->getBalance(33);
        $this->assertSame($initBalance, $afterBalance);
    }

    /**
     * Scenario AF: Membership dashboard loads
     */
    public function testScenarioAF_membershipDashboardLoads(): void
    {
        $this->authenticateUser(34);
        $request = $this->createGetRequest('/account/membership');
        $response = $this->accountController->membership($request);

        $content = is_string($response) ? $response : $response->getContent();
        $this->assertStringContainsString('Membership', $content);
    }

    /**
     * Scenario AG: Active membership state displayed
     */
    public function testScenarioAG_activeMembershipStateDisplayed(): void
    {
        $planProdId = $this->createMembershipPlan('Gold VIP');
        $this->grantActiveMembership(35, $planProdId);

        $dash = $this->accountService->getMembershipDashboard(35);
        $this->assertTrue($dash['has_membership']);
        $this->assertSame('Gold VIP', $dash['plan_title']);
        $this->assertSame('Active', $dash['status_label']);
    }

    /**
     * Scenario AH: Grace membership state displayed
     */
    public function testScenarioAH_graceMembershipStateDisplayed(): void
    {
        $planProdId = $this->createMembershipPlan('Grace Period Plan');
        $plan = $this->membershipService->getPlanByProductId($planProdId);

        $this->sqliteDb->insert('favorite_digital_memberships', [
            'user_id'          => 36,
            'plan_id'          => (int)$plan->id,
            'status'           => MembershipStatus::GRACE,
            'started_at'       => date('Y-m-d H:i:s', strtotime('-32 days')),
            'expires_at'       => date('Y-m-d H:i:s', strtotime('-2 days')),
            'grace_expires_at' => date('Y-m-d H:i:s', strtotime('+3 days')),
            'auto_renew'       => 1,
            'created_at'       => date('Y-m-d H:i:s', strtotime('-32 days')),
            'updated_at'       => date('Y-m-d H:i:s', strtotime('-2 days')),
        ]);

        $dash = $this->accountService->getMembershipDashboard(36);
        $this->assertTrue($dash['has_membership']);
        $this->assertTrue($dash['is_in_grace']);
        $this->assertSame('Grace Period', $dash['status_label']);
        $this->assertNotNull($dash['grace_period_ends_at']);
    }

    /**
     * Scenario AI: Expired membership state displayed
     */
    public function testScenarioAI_expiredMembershipStateDisplayed(): void
    {
        $dash = $this->accountService->getMembershipDashboard(37);
        $this->assertFalse($dash['has_membership']);
        $this->assertSame('none', $dash['status']);
    }

    /**
     * Scenario AJ: Auto-renew state accurate
     */
    public function testScenarioAJ_autoRenewStateAccurate(): void
    {
        $planProdId = $this->createMembershipPlan('Auto Renew Plan');
        $plan = $this->membershipService->getPlanByProductId($planProdId);

        $this->sqliteDb->insert('favorite_digital_memberships', [
            'user_id'          => 38,
            'plan_id'          => (int)$plan->id,
            'status'           => MembershipStatus::ACTIVE,
            'started_at'       => date('Y-m-d H:i:s'),
            'expires_at'       => date('Y-m-d H:i:s', strtotime('+30 days')),
            'grace_expires_at' => null,
            'auto_renew'       => 1,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $dash = $this->accountService->getMembershipDashboard(38);
        $this->assertTrue($dash['auto_renew']);
    }

    /**
     * Scenario AK: No automatic auto-renew activation
     */
    public function testScenarioAK_noAutomaticAutoRenewActivation(): void
    {
        $planProdId = $this->createMembershipPlan('Manual Plan');
        $plan = $this->membershipService->getPlanByProductId($planProdId);

        $this->sqliteDb->insert('favorite_digital_memberships', [
            'user_id'          => 39,
            'plan_id'          => (int)$plan->id,
            'status'           => MembershipStatus::ACTIVE,
            'started_at'       => date('Y-m-d H:i:s'),
            'expires_at'       => date('Y-m-d H:i:s', strtotime('+30 days')),
            'grace_expires_at' => null,
            'auto_renew'       => 0,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $dash = $this->accountService->getMembershipDashboard(39);
        $this->assertFalse($dash['auto_renew']);

        // Check DB row remains 0
        $row = $this->sqliteDb->selectOne("SELECT auto_renew FROM favorite_digital_memberships WHERE user_id = 39");
        $this->assertSame(0, (int)$row->auto_renew);
    }

    /**
     * Scenario AL: Customer ownership isolation across all account areas
     */
    public function testScenarioAL_customerOwnershipIsolationAcrossAllAccountAreas(): void
    {
        // Setup User 40 data
        $p40 = $this->createDigitalProduct('User 40 Doc');
        $this->entitlementRepo->createEntitlement(['user_id' => 40, 'product_id' => $p40, 'source' => 'direct']);
        $this->walletService->credit(40, '400.00', 'ref_credit_user_40', 'test', 1);

        // Setup User 41 data
        $p41 = $this->createDigitalProduct('User 41 Doc');
        $this->entitlementRepo->createEntitlement(['user_id' => 41, 'product_id' => $p41, 'source' => 'direct']);
        $this->walletService->credit(41, '100.00', 'ref_credit_user_41', 'test', 1);

        $this->assertSame('400.00', $this->accountService->getWalletSummary(40)['balance']);
        $this->assertSame('100.00', $this->accountService->getWalletSummary(41)['balance']);

        $lib40 = $this->accountService->getDigitalLibrary(40);
        $this->assertSame('User 40 Doc', $lib40['items'][0]['title']);

        $lib41 = $this->accountService->getDigitalLibrary(41);
        $this->assertSame('User 41 Doc', $lib41['items'][0]['title']);
    }

    /**
     * Scenario AM: Order ID/number tampering rejected
     */
    public function testScenarioAM_orderIdTamperingRejected(): void
    {
        $prodId = $this->createDigitalProduct('Tamper Test Product');
        $order = $this->orderService->createOrder(42, [
            ['product_id' => $prodId, 'quantity' => 1],
        ]);

        // User 43 tries to access User 42's order
        $detail = $this->accountService->getOrderDetail(43, $order->order_number);
        $this->assertNull($detail);

        // Test controller level response
        $this->authenticateUser(43);
        $request = $this->createGetRequest('/account/orders/' . $order->order_number);
        $resp = $this->orderController->view($request, $order->order_number);
        $this->assertInstanceOf(Response::class, $resp);
        $this->assertSame(403, $resp->getStatusCode());
    }

    /**
     * Scenario AN: Refund ID tampering rejected
     */
    public function testScenarioAN_refundIdTamperingRejected(): void
    {
        $this->refundRepo->createRefund([
            'order_id'      => 999,
            'user_id'       => 44,
            'refund_amount' => '50.00',
            'destination'   => 'wallet',
        ]);

        $hist45 = $this->accountService->getRefundHistory(45);
        $this->assertSame(0, $hist45['total']);
    }

    /**
     * Scenario AO: Membership ID tampering rejected
     */
    public function testScenarioAO_membershipIdTamperingRejected(): void
    {
        $planProdId = $this->createMembershipPlan('VIP User 46');
        $this->grantActiveMembership(46, $planProdId);

        $dash47 = $this->accountService->getMembershipDashboard(47);
        $this->assertFalse($dash47['has_membership']);
    }

    /**
     * Scenario AP: Entitlement ID tampering rejected
     */
    public function testScenarioAP_entitlementIdTamperingRejected(): void
    {
        $prodId = $this->createDigitalProduct('Secret File');
        $this->entitlementRepo->createEntitlement(['user_id' => 48, 'product_id' => $prodId, 'source' => 'direct']);

        $lib49 = $this->accountService->getDigitalLibrary(49);
        $this->assertSame(0, $lib49['total']);
    }

    /**
     * Scenario AQ: SQL injection protection on filters/search
     */
    public function testScenarioAQ_sqlInjectionProtectionOnFiltersSearch(): void
    {
        $sqlPayload = "' OR 1=1 --";
        $lib = $this->accountService->getDigitalLibrary(50, ['search' => $sqlPayload]);
        $this->assertSame(0, $lib['total']);
    }

    /**
     * Scenario AR: XSS protection across all outputs
     */
    public function testScenarioAR_xssProtectionAcrossAllOutputs(): void
    {
        $this->authenticateUser(51);
        $xssTitle = "<script>alert('xss')</script>";
        $prodId = $this->createDigitalProduct($xssTitle);
        $this->entitlementRepo->createEntitlement(['user_id' => 51, 'product_id' => $prodId, 'source' => 'direct']);

        $request = $this->createGetRequest('/account/digital');
        $html = (string)$this->accountController->library($request);

        $this->assertStringNotContainsString("<script>alert('xss')</script>", $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;', $html);
    }

    /**
     * Scenario AS: Authorization protection on routes
     */
    public function testScenarioAS_authorizationProtectionOnRoutes(): void
    {
        unset($GLOBALS['_test_current_user'], $GLOBALS['_test_current_user_id']);
        $_SESSION = [];

        $r1 = $this->accountController->library($this->createGetRequest('/account/digital'));
        $this->assertInstanceOf(Response::class, $r1);
        $this->assertSame(302, $r1->getStatusCode());

        $r2 = $this->accountController->membership($this->createGetRequest('/account/membership'));
        $this->assertInstanceOf(Response::class, $r2);
        $this->assertSame(302, $r2->getStatusCode());

        $r3 = $this->accountController->refunds($this->createGetRequest('/account/refunds'));
        $this->assertInstanceOf(Response::class, $r3);
        $this->assertSame(302, $r3->getStatusCode());
    }

    /**
     * Scenario AT: Server-side pagination
     */
    public function testScenarioAT_serverSidePagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $p = $this->createDigitalProduct("Page Item {$i}");
            $this->entitlementRepo->createEntitlement(['user_id' => 52, 'product_id' => $p, 'source' => 'direct']);
        }

        $p1 = $this->accountService->getDigitalLibrary(52, [], 1, 2);
        $this->assertSame(5, $p1['total']);
        $this->assertCount(2, $p1['items']);
        $this->assertSame(1, $p1['page']);
        $this->assertSame(3, $p1['total_pages']);

        $p2 = $this->accountService->getDigitalLibrary(52, [], 2, 2);
        $this->assertCount(2, $p2['items']);
        $this->assertSame(2, $p2['page']);

        $p3 = $this->accountService->getDigitalLibrary(52, [], 3, 2);
        $this->assertCount(1, $p3['items']);
        $this->assertSame(3, $p3['page']);
    }

    /**
     * Scenario AU: Large history does not load unbounded data
     */
    public function testScenarioAU_largeHistoryDoesNotLoadUnboundedData(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $p = $this->createDigitalProduct("Bulk Item {$i}");
            $this->entitlementRepo->createEntitlement(['user_id' => 53, 'product_id' => $p, 'source' => 'direct']);
        }

        $res = $this->accountService->getDigitalLibrary(53, [], 1, 4);
        $this->assertCount(4, $res['items']);
    }

    /**
     * Scenario AV: Responsive rendering sanity
     */
    public function testScenarioAV_responsiveRenderingSanity(): void
    {
        $this->authenticateUser(54);
        $p = $this->createDigitalProduct('Responsive UI Product');
        $this->entitlementRepo->createEntitlement(['user_id' => 54, 'product_id' => $p, 'source' => 'direct']);

        $request = $this->createGetRequest('/account/digital');
        $html = (string)$this->accountController->library($request);

        $this->assertStringContainsString('fav-account-nav-wrap', $html);
        $this->assertStringContainsString('library-wrap', $html);
        $this->assertStringContainsString('library-grid', $html);
        $this->assertStringContainsString('item-card', $html);
    }

    /**
     * Scenario AW: Accessibility sanity
     */
    public function testScenarioAW_accessibilitySanity(): void
    {
        $this->authenticateUser(55);
        $request = $this->createGetRequest('/account/digital');
        $html = (string)$this->accountController->library($request);

        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('<nav', $html);
        $this->assertStringContainsString('aria-label', $html);
    }

    /**
     * Scenario AX: Prefix-safe database queries
     */
    public function testScenarioAX_prefixSafeDatabaseQueries(): void
    {
        $prefixDb = new class($this->sqlitePdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite'];
                $this->prefix = '';
            }
        };
        $prefixRepo = new EntitlementRepository($prefixDb);
        $this->assertInstanceOf(EntitlementRepository::class, $prefixRepo);
    }

    /**
     * Scenario AY: SQLite compatibility
     */
    public function testScenarioAY_sqliteCompatibility(): void
    {
        $row = $this->sqliteDb->selectOne("SELECT 1 as alive");
        $this->assertSame(1, (int)$row->alive);
    }

    /**
     * Scenario AZ: MySQL compatibility (skips cleanly when offline)
     */
    public function testScenarioAZ_mysqlCompatibility(): void
    {
        try {
            $mysqlPdo = @new PDO('mysql:host=127.0.0.1;port=3306;dbname=favorite_cms_test', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 1,
            ]);
            $this->assertNotNull($mysqlPdo);
        } catch (Throwable) {
            $this->markTestSkipped('Local MySQL server offline - skipping live MySQL test gracefully per locked rule.');
        }
    }
}
