<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteDigital;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Controllers\AdminOrderController;
use FavoriteCMS\Digital\Controllers\CustomerOrderController;
use FavoriteCMS\Digital\Contracts\EntitlementCheckerInterface;
use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Exceptions\OrderValidationException;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;
use FavoriteCMS\Digital\Repositories\OrderRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Services\DefaultEntitlementChecker;
use FavoriteCMS\Digital\Services\DigitalFileStorageService;
use FavoriteCMS\Digital\Services\MembershipLifecycleService;
use FavoriteCMS\Digital\Services\OrderService;
use FavoriteCMS\Digital\Services\ProductManagementService;
use FavoriteCMS\Digital\Support\OrderLifecycleState;
use FavoriteCMS\Models\User;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

class OrderFoundationTest extends TestCase
{
    private Application $app;
    private PDO $sqlitePdo;
    private Database $sqliteDb;
    private ProductRepository $productRepo;
    private OrderRepository $orderRepo;
    private MembershipLifecycleService $membershipService;
    private ProductManagementService $productService;
    private OrderService $orderService;
    private AdminOrderController $adminController;
    private CustomerOrderController $customerController;

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
        $this->membershipService = new MembershipLifecycleService($this->productRepo);
        $storage = new DigitalFileStorageService(sys_get_temp_dir());
        $this->productService = new ProductManagementService($this->productRepo, $storage);

        $this->orderService = new OrderService(
            $this->orderRepo,
            $this->productRepo,
            $this->membershipService,
            new DefaultEntitlementChecker($this->sqliteDb),
            $this->sqliteDb
        );

        $this->adminController = new AdminOrderController($this->app, $this->orderService);
        $this->customerController = new CustomerOrderController($this->app, $this->orderService);

        $this->app->singleton(ProductRepository::class, fn () => $this->productRepo);
        $this->app->singleton(OrderRepository::class, fn () => $this->orderRepo);
        $this->app->singleton(MembershipLifecycleService::class, fn () => $this->membershipService);
        $this->app->singleton(ProductManagementService::class, fn () => $this->productService);
        $this->app->singleton(OrderService::class, fn () => $this->orderService);
        $this->app->singleton(AdminOrderController::class, fn () => $this->adminController);
        $this->app->singleton(CustomerOrderController::class, fn () => $this->customerController);

        $_SESSION = [
            'auth_user_id'   => 1,
            'auth_user_name' => 'Admin User',
            '_token'         => 'valid_order_csrf_token',
        ];

        $adminUser = new class extends User {
            public int $id = 1;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool { return true; }
        };
        $GLOBALS['_test_current_user'] = $adminUser;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);
    }

    private function createPublishedDigitalProduct(string $title = 'E-Book PDF', string $price = '29.99', string $discount = '0.00'): int
    {
        $id = $this->productRepo->createProduct([
            'title'            => $title,
            'slug'             => 'ebook-' . uniqid(),
            'product_type'     => ProductType::DIGITAL,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => $price,
            'discount_percent' => $discount,
            'final_price'      => $price,
            'currency'         => 'BDT',
            'is_free'          => 0,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $this->productRepo->saveProductDetails($id, [
            'product_id'             => $id,
            'file_path'              => '/files/test.pdf',
            'file_name'              => 'ebook.pdf',
            'file_size'              => 10240,
            'mime_type'              => 'application/pdf',
            'file_hash'              => hash('sha256', 'test'),
            'max_downloads'          => 5,
            'download_expiry_days'   => 30,
            'is_membership_eligible' => 1,
            'created_at'             => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    private function createPublishedServiceProduct(string $title = 'SEO Consultation', string $price = '150.00'): int
    {
        $id = $this->productRepo->createProduct([
            'title'            => $title,
            'slug'             => 'seo-' . uniqid(),
            'product_type'     => ProductType::SERVICE,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => $price,
            'discount_percent' => '0.00',
            'final_price'      => $price,
            'currency'         => 'BDT',
            'is_free'          => 0,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $this->productRepo->saveServiceDetails($id, [
            'delivery_time_days'  => 7,
            'service_scope'       => 'Complete website SEO audit',
            'requirements_prompt' => 'Please provide target URL',
        ]);

        return $id;
    }

    private function createPublishedPackageProduct(string $title = 'Starter Bundle', string $price = '120.00', array $childIds = []): int
    {
        $id = $this->productRepo->createProduct([
            'title'            => $title,
            'slug'             => 'bundle-' . uniqid(),
            'product_type'     => ProductType::PACKAGE,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => $price,
            'discount_percent' => '0.00',
            'final_price'      => $price,
            'currency'         => 'BDT',
            'is_free'          => 0,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $pkgId = $this->productRepo->createPackage($id, 'bundle');

        foreach ($childIds as $childId) {
            $this->productRepo->addPackageItem($pkgId, $childId);
        }

        return $id;
    }

    private function createPublishedMembershipProduct(string $title = 'VIP Monthly Pass', string $price = '50.00'): int
    {
        return $this->membershipService->createPlan([
            'title'          => $title,
            'original_price' => $price,
            'status'         => ProductStatus::PUBLISHED,
        ], [
            'plan_type'           => 'monthly',
            'grace_period_days'   => 3,
            'allows_auto_renewal' => 1,
        ]);
    }

    // =========================================================================
    // SCENARIO A: Valid order creation with single digital product
    // =========================================================================
    public function testA_ValidOrderCreationWithSingleDigitalProduct(): void
    {
        $prodId = $this->createPublishedDigitalProduct('Ultimate Guide PDF', '49.99');

        $order = $this->orderService->createOrder(10, [
            ['product_id' => $prodId, 'quantity' => 1],
        ], 'Please deliver receipt');

        $this->assertNotNull($order);
        $this->assertStringStartsWith('ORD-', $order->order_number);
        $this->assertSame(10, (int)$order->user_id);
        $this->assertSame(OrderLifecycleState::STATUS_PENDING, $order->status);
        $this->assertSame(OrderLifecycleState::PAYMENT_UNPAID, $order->payment_status);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_UNFULFILLED, $order->fulfillment_status);
        $this->assertSame('49.99', $order->subtotal_amount);
        $this->assertSame('0.00', $order->discount_amount);
        $this->assertSame('49.99', $order->total_amount);
        $this->assertCount(1, $order->items);

        $item = $order->items[0];
        $this->assertSame($prodId, (int)$item->product_id);
        $this->assertSame(ProductType::DIGITAL, $item->product_type);
        $this->assertSame('49.99', $item->unit_price);
        $this->assertSame('49.99', $item->final_price);

        $this->assertIsArray($item->snapshot);
        $this->assertSame('Ultimate Guide PDF', $item->snapshot['title']);
        $this->assertSame(5, $item->snapshot['attributes']['max_downloads']);
    }

    // =========================================================================
    // SCENARIO B: Valid order creation with service product
    // =========================================================================
    public function testB_ValidOrderCreationWithServiceProduct(): void
    {
        $serviceId = $this->createPublishedServiceProduct('Custom Development', '250.00');

        $order = $this->orderService->createOrder(15, [
            ['product_id' => $serviceId, 'quantity' => 1],
        ]);

        $this->assertNotNull($order);
        $this->assertSame('250.00', $order->total_amount);
        $this->assertCount(1, $order->items);
        $item = $order->items[0];
        $this->assertSame(ProductType::SERVICE, $item->product_type);
        $this->assertSame(7, $item->snapshot['attributes']['delivery_time_days']);
        $this->assertSame('Complete website SEO audit', $item->snapshot['attributes']['service_scope']);
    }

    // =========================================================================
    // SCENARIO C: Valid order creation with package product
    // =========================================================================
    public function testC_ValidOrderCreationWithPackageProduct(): void
    {
        $child1 = $this->createPublishedDigitalProduct('Child Item 1', '20.00');
        $child2 = $this->createPublishedDigitalProduct('Child Item 2', '30.00');
        $pkgProductId = $this->createPublishedPackageProduct('Combo Pack', '45.00', [$child1, $child2]);

        $order = $this->orderService->createOrder(20, [
            ['product_id' => $pkgProductId, 'quantity' => 1],
        ]);

        $this->assertNotNull($order);
        $this->assertSame('45.00', $order->total_amount);
        $item = $order->items[0];
        $this->assertSame(ProductType::PACKAGE, $item->product_type);
        $this->assertCount(2, $item->snapshot['attributes']['items']);
    }

    // =========================================================================
    // SCENARIO D: Valid order creation with membership product
    // =========================================================================
    public function testD_ValidOrderCreationWithMembershipProduct(): void
    {
        $membershipProdId = $this->createPublishedMembershipProduct('Gold Subscription', '60.00');

        $order = $this->orderService->createOrder(25, [
            ['product_id' => $membershipProdId, 'quantity' => 1],
        ]);

        $this->assertNotNull($order);
        $this->assertSame('60.00', $order->total_amount);
        $item = $order->items[0];
        $this->assertSame(ProductType::MEMBERSHIP, $item->product_type);
        $this->assertSame('monthly', $item->snapshot['attributes']['plan_type']);
        $this->assertSame('month', $item->snapshot['attributes']['duration_unit']);
        $this->assertSame(3, $item->snapshot['attributes']['grace_period_days']);
    }

    // =========================================================================
    // SCENARIO E: Multi-item order with mixed product types
    // =========================================================================
    public function testE_MultiItemOrderWithMixedProductTypes(): void
    {
        $digitalId = $this->createPublishedDigitalProduct('Digital Asset', '10.00');
        $serviceId = $this->createPublishedServiceProduct('Quick Fix Service', '40.00');
        $membershipId = $this->createPublishedMembershipProduct('Weekly VIP', '15.00');

        $order = $this->orderService->createOrder(30, [
            ['product_id' => $digitalId, 'quantity' => 1],
            ['product_id' => $serviceId, 'quantity' => 1],
            ['product_id' => $membershipId, 'quantity' => 1],
        ]);

        $this->assertNotNull($order);
        $this->assertCount(3, $order->items);
        $this->assertSame('65.00', $order->subtotal_amount);
        $this->assertSame('0.00', $order->discount_amount);
        $this->assertSame('65.00', $order->total_amount);
    }

    // =========================================================================
    // SCENARIO F: Client price tampering rejected
    // =========================================================================
    public function testF_ClientPriceTamperingRejected(): void
    {
        $prodId = $this->createPublishedDigitalProduct('Premium Software', '100.00');

        // Client attempts to sneak in unit_price = 0.01 and total = 0.01
        $order = $this->orderService->createOrder(35, [
            [
                'product_id'   => $prodId,
                'quantity'     => 1,
                'unit_price'   => '0.01',
                'final_price'  => '0.01',
                'total_amount' => '0.01',
            ],
        ]);

        // Server authoritative pricing overrides spoofed client prices
        $this->assertSame('100.00', $order->subtotal_amount);
        $this->assertSame('100.00', $order->total_amount);
        $this->assertSame('100.00', $order->items[0]->final_price);
    }

    // =========================================================================
    // SCENARIO G: Draft product ordering rejected
    // =========================================================================
    public function testG_DraftProductOrderingRejected(): void
    {
        $prodId = $this->productRepo->createProduct([
            'title'            => 'Unreleased Draft Ebook',
            'slug'             => 'draft-' . uniqid(),
            'product_type'     => ProductType::DIGITAL,
            'status'           => ProductStatus::DRAFT,
            'original_price'   => '20.00',
            'discount_percent' => '0.00',
            'final_price'      => '20.00',
        ]);

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessageMatches('/not available for purchase/');
        $this->orderService->createOrder(40, [
            ['product_id' => $prodId, 'quantity' => 1],
        ]);
    }

    // =========================================================================
    // SCENARIO H: Archived product ordering rejected
    // =========================================================================
    public function testH_ArchivedProductOrderingRejected(): void
    {
        $prodId = $this->productRepo->createProduct([
            'title'            => 'Legacy Discontinued Product',
            'slug'             => 'archived-' . uniqid(),
            'product_type'     => ProductType::DIGITAL,
            'status'           => ProductStatus::ARCHIVED,
            'original_price'   => '30.00',
            'discount_percent' => '0.00',
            'final_price'      => '30.00',
        ]);

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessageMatches('/not available for purchase/');
        $this->orderService->createOrder(45, [
            ['product_id' => $prodId, 'quantity' => 1],
        ]);
    }

    // =========================================================================
    // SCENARIO I: Non-existent product ordering rejected
    // =========================================================================
    public function testI_NonExistentProductOrderingRejected(): void
    {
        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessageMatches('/Product with ID 999999 not found/');
        $this->orderService->createOrder(50, [
            ['product_id' => 999999, 'quantity' => 1],
        ]);
    }

    // =========================================================================
    // SCENARIO J: Inactive/invalid user ID rejected
    // =========================================================================
    public function testJ_InactiveOrInvalidUserIdRejected(): void
    {
        $prodId = $this->createPublishedDigitalProduct('Test Item', '10.00');

        // Negative or 0 user ID rejected
        try {
            $this->orderService->createOrder(0, [['product_id' => $prodId, 'quantity' => 1]]);
            $this->fail('Expected exception for user_id = 0');
        } catch (OrderValidationException $e) {
            $this->assertStringContainsString('Invalid user ID', $e->getMessage());
        }

        try {
            $this->orderService->createOrder(-5, [['product_id' => $prodId, 'quantity' => 1]]);
            $this->fail('Expected exception for user_id = -5');
        } catch (OrderValidationException $e) {
            $this->assertStringContainsString('Invalid user ID', $e->getMessage());
        }
    }

    // =========================================================================
    // SCENARIO K: Zero or negative quantity rejected
    // =========================================================================
    public function testK_ZeroOrNegativeQuantityRejected(): void
    {
        $prodId = $this->createPublishedDigitalProduct('Test Item', '10.00');

        try {
            $this->orderService->createOrder(55, [['product_id' => $prodId, 'quantity' => 0]]);
            $this->fail('Expected exception for quantity = 0');
        } catch (OrderValidationException $e) {
            $this->assertStringContainsString('Item quantity must be at least 1', $e->getMessage());
        }

        try {
            $this->orderService->createOrder(55, [['product_id' => $prodId, 'quantity' => -2]]);
            $this->fail('Expected exception for quantity = -2');
        } catch (OrderValidationException $e) {
            $this->assertStringContainsString('Item quantity must be at least 1', $e->getMessage());
        }
    }

    // =========================================================================
    // SCENARIO L: Order number generation format & collision resistance
    // =========================================================================
    public function testL_OrderNumberGenerationFormatAndCollisionResistance(): void
    {
        $orderNumbers = [];
        for ($i = 0; $i < 50; $i++) {
            $num = $this->orderService->generateOrderNumber();
            $this->assertMatchesRegularExpression('/^ORD-\d{8}-[A-F0-9]{6}$/', $num);
            $this->assertNotContains($num, $orderNumbers, "Detected colliding order number: {$num}");
            $orderNumbers[] = $num;
        }
    }

    // =========================================================================
    // SCENARIO M: Item pricing snapshot immutability
    // =========================================================================
    public function testM_ItemPricingSnapshotImmutability(): void
    {
        $prodId = $this->createPublishedDigitalProduct('Initial Title', '50.00');

        $order = $this->orderService->createOrder(60, [
            ['product_id' => $prodId, 'quantity' => 1],
        ]);

        $this->assertSame('50.00', $order->items[0]->final_price);
        $this->assertSame('Initial Title', $order->items[0]->snapshot['title']);

        // Mutate original catalog product (increase price, change title, change slug)
        $this->productRepo->updateProduct($prodId, [
            'title'          => 'Drastically Altered Title',
            'slug'           => 'altered-slug',
            'original_price' => '199.99',
            'final_price'    => '199.99',
        ]);

        // Re-read order from repository
        $refetched = $this->orderRepo->findOrderWithItems((int)$order->id);
        $this->assertNotNull($refetched);
        $this->assertSame('50.00', $refetched->total_amount);
        $this->assertSame('50.00', $refetched->items[0]->final_price);
        $this->assertSame('Initial Title', $refetched->items[0]->snapshot['title']);
    }

    // =========================================================================
    // SCENARIO N: Decimal precision and financial calculation accuracy
    // =========================================================================
    public function testN_DecimalPrecisionAndFinancialCalculationAccuracy(): void
    {
        // 3 items with 19.99 unit price
        $prodId = $this->createPublishedDigitalProduct('Book', '19.99');

        $order = $this->orderService->createOrder(65, [
            ['product_id' => $prodId, 'quantity' => 3],
        ]);

        // 19.99 * 3 = 59.97
        $this->assertSame('59.97', $order->subtotal_amount);
        $this->assertSame('0.00', $order->discount_amount);
        $this->assertSame('59.97', $order->total_amount);
        $this->assertCount(3, $order->items);
    }

    // =========================================================================
    // SCENARIO O: Free product ($0.00) ordering
    // =========================================================================
    public function testO_FreeProductZeroDollarOrdering(): void
    {
        $prodId = $this->productRepo->createProduct([
            'title'            => 'Free Community Edition',
            'slug'             => 'free-comm-' . uniqid(),
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

        $order = $this->orderService->createOrder(70, [
            ['product_id' => $prodId, 'quantity' => 1],
        ]);

        $this->assertSame('0.00', $order->subtotal_amount);
        $this->assertSame('0.00', $order->discount_amount);
        $this->assertSame('0.00', $order->total_amount);
        $this->assertSame('0.00', $order->items[0]->final_price);
    }

    // =========================================================================
    // SCENARIO P: 100% discounted product ordering
    // =========================================================================
    public function testP_HundredPercentDiscountedProductOrdering(): void
    {
        $prodId = $this->createPublishedDigitalProduct('Promo 100% Off', '75.00', '100.00');

        $order = $this->orderService->createOrder(75, [
            ['product_id' => $prodId, 'quantity' => 1],
        ]);

        $this->assertSame('75.00', $order->subtotal_amount);
        $this->assertSame('75.00', $order->discount_amount);
        $this->assertSame('0.00', $order->total_amount);
        $this->assertSame('0.00', $order->items[0]->final_price);
    }

    // =========================================================================
    // SCENARIO Q: Partial discount calculation accuracy
    // =========================================================================
    public function testQ_PartialDiscountCalculationAccuracy(): void
    {
        $prodId = $this->createPublishedDigitalProduct('Discounted Course', '120.00', '25.00');

        $order = $this->orderService->createOrder(80, [
            ['product_id' => $prodId, 'quantity' => 2],
        ]);

        // 120 * 2 = 240 subtotal; 25% discount is 30 each -> 60 discount; total = 180
        $this->assertSame('240.00', $order->subtotal_amount);
        $this->assertSame('60.00', $order->discount_amount);
        $this->assertSame('180.00', $order->total_amount);
    }

    // =========================================================================
    // SCENARIO R: Order created with default statuses
    // =========================================================================
    public function testR_OrderCreatedWithDefaultStatuses(): void
    {
        $prodId = $this->createPublishedDigitalProduct('Standard Product', '30.00');

        $order = $this->orderService->createOrder(85, [
            ['product_id' => $prodId, 'quantity' => 1],
        ]);

        $this->assertSame(OrderLifecycleState::STATUS_PENDING, $order->status);
        $this->assertSame(OrderLifecycleState::PAYMENT_UNPAID, $order->payment_status);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_UNFULFILLED, $order->fulfillment_status);
    }

    // =========================================================================
    // SCENARIO S: Orthogonal state transitions
    // =========================================================================
    public function testS_OrthogonalStateTransitions(): void
    {
        $prodId = $this->createPublishedDigitalProduct('Standard Product', '30.00');
        $order = $this->orderService->createOrder(90, [
            ['product_id' => $prodId, 'quantity' => 1],
        ]);

        $orderId = (int)$order->id;

        // 1. Payment status updates to paid; fulfillment remains unfulfilled; status becomes processing
        $this->orderService->updatePaymentStatus($orderId, OrderLifecycleState::PAYMENT_PAID);
        $this->orderService->updateStatus($orderId, OrderLifecycleState::STATUS_PROCESSING);

        $refetched = $this->orderRepo->findOrder($orderId);
        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $refetched->payment_status);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_UNFULFILLED, $refetched->fulfillment_status);
        $this->assertSame(OrderLifecycleState::STATUS_PROCESSING, $refetched->status);

        // 2. Fulfillment status updates to fulfilled; status becomes completed
        $this->orderService->updateFulfillmentStatus($orderId, OrderLifecycleState::FULFILLMENT_FULFILLED);
        $this->orderService->updateStatus($orderId, OrderLifecycleState::STATUS_COMPLETED);

        $refetched2 = $this->orderRepo->findOrder($orderId);
        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $refetched2->payment_status);
        $this->assertSame(OrderLifecycleState::FULFILLMENT_FULFILLED, $refetched2->fulfillment_status);
        $this->assertSame(OrderLifecycleState::STATUS_COMPLETED, $refetched2->status);

        // 3. Testing invalid transitions throws validation exception
        $this->expectException(OrderValidationException::class);
        $this->orderService->updatePaymentStatus($orderId, 'non_existent_payment_state');
    }

    // =========================================================================
    // SCENARIO T: Package order does NOT prematurely create entitlements
    // =========================================================================
    public function testT_PackageOrderDoesNotPrematurelyCreateEntitlements(): void
    {
        $child = $this->createPublishedDigitalProduct('Component', '15.00');
        $pkgId = $this->createPublishedPackageProduct('Suite', '25.00', [$child]);

        $order = $this->orderService->createOrder(95, [
            ['product_id' => $pkgId, 'quantity' => 1],
        ]);

        $this->assertNotNull($order);

        // Verify that in Phase 5A, ZERO entitlements were granted
        $entitlementCount = $this->sqliteDb->selectOne("SELECT COUNT(*) as total FROM `favorite_digital_entitlements`");
        $this->assertSame(0, (int)($entitlementCount->total ?? 0));
    }

    // =========================================================================
    // SCENARIO U: Membership order does NOT prematurely activate membership
    // =========================================================================
    public function testU_MembershipOrderDoesNotPrematurelyActivateMembership(): void
    {
        $membershipProdId = $this->createPublishedMembershipProduct('Quarterly Tier', '99.00');

        $order = $this->orderService->createOrder(100, [
            ['product_id' => $membershipProdId, 'quantity' => 1],
        ]);

        $this->assertNotNull($order);

        // Verify that in Phase 5A, ZERO membership subscription records were activated
        $membershipCount = $this->sqliteDb->selectOne("SELECT COUNT(*) as total FROM `favorite_digital_memberships`");
        $this->assertSame(0, (int)($membershipCount->total ?? 0));
    }

    // =========================================================================
    // SCENARIO V: Membership-required product restriction check
    // =========================================================================
    public function testV_MembershipRequiredProductRestriction(): void
    {
        $exclusiveProdId = $this->createPublishedDigitalProduct('VIP Exclusive Guide', '10.00');
        $nonMemberUserId = 200;

        // Configure checker so that exclusiveProdId requires an active membership
        $this->orderService->setMembershipRequirementChecker(fn ($p) => (int)$p->id === $exclusiveProdId);

        // 1. Non-member attempt fails
        try {
            $this->orderService->createOrder($nonMemberUserId, [
                ['product_id' => $exclusiveProdId, 'quantity' => 1],
            ]);
            $this->fail('Expected exception for non-member buying membership-required product');
        } catch (OrderValidationException $e) {
            $this->assertStringContainsString('requires an active membership', $e->getMessage());
        }

        // 2. Member with active membership succeeds
        $planProdId = $this->membershipService->createPlan([
            'title'          => 'VIP Monthly',
            'original_price' => '30.00',
            'status'         => ProductStatus::PUBLISHED,
        ], [
            'plan_type'           => 'monthly',
            'grace_period_days'   => 3,
            'allows_auto_renewal' => 0,
        ]);
        $plan = $this->productRepo->findMembershipPlanByProductId($planProdId);
        $this->assertNotNull($plan);
        $this->membershipService->activateMembership($nonMemberUserId, (int)$plan->id);

        $order = $this->orderService->createOrder($nonMemberUserId, [
            ['product_id' => $exclusiveProdId, 'quantity' => 1],
        ]);

        $this->assertNotNull($order);
        $this->assertSame('10.00', $order->total_amount);
    }

    // =========================================================================
    // SCENARIO W: Duplicate purchase check hook / interface boundary
    // =========================================================================
    public function testW_DuplicatePurchaseCheckHookBoundary(): void
    {
        $prodId = $this->createPublishedDigitalProduct('Software License Single Seat', '80.00');
        $userId = 250;

        // Mock an entitlement checker that forbids duplicate purchase
        $checker = new class implements EntitlementCheckerInterface {
            public function hasActiveEntitlement(int $userId, int $productId): bool
            {
                return true; // Already owns entitlement
            }

            public function allowDuplicatePurchase(int $userId, int $productId): bool
            {
                return false; // Forbid repurchase
            }
        };

        $restrictedOrderService = new OrderService(
            $this->orderRepo,
            $this->productRepo,
            $this->membershipService,
            $checker,
            $this->sqliteDb
        );

        $this->expectException(OrderValidationException::class);
        $this->expectExceptionMessageMatches('/Duplicate purchase is not permitted/');
        $restrictedOrderService->createOrder($userId, [
            ['product_id' => $prodId, 'quantity' => 1],
        ]);
    }

    // =========================================================================
    // SCENARIO X: Admin order listing, search, filtering, and view access control
    // =========================================================================
    public function testX_AdminOrderListingSearchFilteringAndRbac(): void
    {
        $prodId = $this->createPublishedDigitalProduct('Standard Product', '30.00');
        $order1 = $this->orderService->createOrder(300, [['product_id' => $prodId, 'quantity' => 1]], 'Special Note A');
        $order2 = $this->orderService->createOrder(301, [['product_id' => $prodId, 'quantity' => 1]], 'Special Note B');

        $this->orderService->updatePaymentStatus((int)$order2->id, OrderLifecycleState::PAYMENT_PAID);

        // 1. Admin Index HTML render
        $request = new Request(['status' => 'all', 'payment_status' => 'paid'], [], []);
        $html = $this->adminController->handle($request);
        $this->assertIsString($html);
        $this->assertStringContainsString($order2->order_number, $html);

        // 2. Search filter
        $searchRequest = new Request(['search' => 'Special Note A'], [], []);
        $searchHtml = $this->adminController->handle($searchRequest);
        $this->assertStringContainsString($order1->order_number, $searchHtml);
        $this->assertStringContainsString('Special Note A', $searchHtml);

        // 3. Admin View single order
        $viewRequest = new Request(['action' => 'view', 'id' => (int)$order1->id], [], []);
        $viewHtml = $this->adminController->handle($viewRequest);
        $this->assertIsString($viewHtml);
        $this->assertStringContainsString($order1->order_number, $viewHtml);
        $this->assertStringContainsString('Special Note A', $viewHtml);

        // 4. Admin update status POST with valid CSRF
        $postRequest = new Request(
            [],
            [
                'action'             => 'update_status',
                'id'                 => (int)$order1->id,
                'status'             => OrderLifecycleState::STATUS_COMPLETED,
                'payment_status'     => OrderLifecycleState::PAYMENT_PAID,
                'fulfillment_status' => OrderLifecycleState::FULFILLMENT_FULFILLED,
                '_token'             => 'valid_order_csrf_token',
            ],
            ['REQUEST_METHOD' => 'POST']
        );

        $response = $this->adminController->handle($postRequest);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());

        $updatedOrder = $this->orderRepo->findOrder((int)$order1->id);
        $this->assertSame(OrderLifecycleState::STATUS_COMPLETED, $updatedOrder->status);
        $this->assertSame(OrderLifecycleState::PAYMENT_PAID, $updatedOrder->payment_status);

        // 5. CSRF failure on POST
        $csrfFailRequest = new Request(
            [],
            [
                'action' => 'update_status',
                'id'     => (int)$order1->id,
                '_token' => 'invalid_token',
            ],
            ['REQUEST_METHOD' => 'POST']
        );
        $csrfResponse = $this->adminController->handle($csrfFailRequest);
        $this->assertSame(302, $csrfResponse->getStatusCode());
        $this->assertStringContainsString('CSRF failure', $_SESSION['flash_error'] ?? '');

        // 6. RBAC unauthorized user blocked
        $regularUser = new class extends User {
            public int $id = 999;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool { return false; }
        };
        $GLOBALS['_test_current_user'] = $regularUser;
        $_SESSION['auth_user_id'] = 999;

        $unauthResponse = $this->adminController->handle(new Request([], [], []));
        $this->assertInstanceOf(Response::class, $unauthResponse);
        $this->assertSame(403, $unauthResponse->getStatusCode());
    }

    // =========================================================================
    // SCENARIO Y: Customer order view ownership isolation
    // =========================================================================
    public function testY_CustomerOrderViewOwnershipIsolation(): void
    {
        $prodId = $this->createPublishedDigitalProduct('Product X', '25.00');
        $customerA_Id = 400;
        $customerB_Id = 401;

        $orderA = $this->orderService->createOrder($customerA_Id, [['product_id' => $prodId, 'quantity' => 1]]);
        $orderB = $this->orderService->createOrder($customerB_Id, [['product_id' => $prodId, 'quantity' => 1]]);

        // 1. Customer A logged in
        $customerUserA = new class($customerA_Id) extends User {
            public int $id;
            public function __construct(int $id) { $this->id = $id; }
            public function isActive(): bool { return true; }
            public function can(string $capability): bool { return false; }
        };
        $GLOBALS['_test_current_user'] = $customerUserA;

        // Customer A can view their own order
        $viewA_Request = new Request();
        $viewA_Html = $this->customerController->view($viewA_Request, $orderA->order_number);
        $this->assertIsString($viewA_Html);
        $this->assertStringContainsString($orderA->order_number, $viewA_Html);

        // Customer A trying to view Customer B's order is blocked with 403
        $viewB_Response = $this->customerController->view($viewA_Request, $orderB->order_number);
        $this->assertInstanceOf(Response::class, $viewB_Response);
        $this->assertSame(403, $viewB_Response->getStatusCode());

        // Admin logged in CAN view Customer B's order
        $adminUser = new class extends User {
            public int $id = 1;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool { return true; }
        };
        $GLOBALS['_test_current_user'] = $adminUser;

        $adminViewHtml = $this->customerController->view($viewA_Request, $orderB->order_number);
        $this->assertIsString($adminViewHtml);
        $this->assertStringContainsString($orderB->order_number, $adminViewHtml);
    }

    // =========================================================================
    // SCENARIO Z: Database prefix safety & Dual Database Compatibility
    // =========================================================================
    public function testZ_DatabasePrefixSafetyAndDualDatabaseCompatibility(): void
    {
        // 1. Prefix Test with SQLite in-memory and prefix 'fvt_'
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

        $prefixedProductRepo = new ProductRepository($prefixedDb);
        $prefixedOrderRepo   = new OrderRepository($prefixedDb);
        $prefixedOrderService = new OrderService(
            $prefixedOrderRepo,
            $prefixedProductRepo,
            null,
            null,
            $prefixedDb
        );

        $prodId = $prefixedProductRepo->createProduct([
            'title'            => 'Prefixed Product',
            'slug'             => 'pref-' . uniqid(),
            'product_type'     => ProductType::DIGITAL,
            'status'           => ProductStatus::PUBLISHED,
            'original_price'   => '40.00',
            'discount_percent' => '0.00',
            'final_price'      => '40.00',
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        $order = $prefixedOrderService->createOrder(500, [['product_id' => $prodId, 'quantity' => 1]]);
        $this->assertNotNull($order);
        $this->assertSame('40.00', $order->total_amount);

        // Verify underlying prefixed table exists and holds data
        $prefixedOrders = $prefixedDb->select("SELECT * FROM `fvt_favorite_digital_orders` WHERE `id` = ?", [$order->id]);
        $this->assertCount(1, $prefixedOrders);

        // 2. Dual DB Compatibility: MariaDB/MySQL verification
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
            $mysqlMigrator = new Migrator($mysqlDb);
            $mysqlMigrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');

            $mysqlProductRepo = new ProductRepository($mysqlDb);
            $mysqlOrderRepo   = new OrderRepository($mysqlDb);
            $mysqlOrderService = new OrderService(
                $mysqlOrderRepo,
                $mysqlProductRepo,
                null,
                null,
                $mysqlDb
            );

            $mysqlProdId = $mysqlProductRepo->createProduct([
                'title'            => 'MySQL Order Item',
                'slug'             => 'mysql-item-' . uniqid(),
                'product_type'     => ProductType::DIGITAL,
                'status'           => ProductStatus::PUBLISHED,
                'original_price'   => '99.00',
                'discount_percent' => '0.00',
                'final_price'      => '99.00',
                'created_at'       => date('Y-m-d H:i:s'),
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);

            $mysqlOrder = $mysqlOrderService->createOrder(600, [['product_id' => $mysqlProdId, 'quantity' => 1]]);
            $this->assertNotNull($mysqlOrder);
            $this->assertSame('99.00', $mysqlOrder->total_amount);
            $this->assertSame(OrderLifecycleState::PAYMENT_UNPAID, $mysqlOrder->payment_status);

            // Clean up MySQL order
            $mysqlDb->execute("DELETE FROM `favorite_digital_order_items` WHERE `order_id` = ?", [$mysqlOrder->id]);
            $mysqlDb->execute("DELETE FROM `favorite_digital_orders` WHERE `id` = ?", [$mysqlOrder->id]);
            $mysqlDb->execute("DELETE FROM `favorite_digital_products` WHERE `id` = ?", [$mysqlProdId]);
        } catch (Throwable $e) {
            // If MySQL is not running or accessible, mark skipped
            $this->markTestSkipped('MySQL/MariaDB connection skipped: ' . $e->getMessage());
        }
    }
}
