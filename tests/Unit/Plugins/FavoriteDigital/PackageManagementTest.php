<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteDigital;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Controllers\AdminPackageController;
use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Services\DigitalFileStorageService;
use FavoriteCMS\Digital\Services\ProductManagementService;
use FavoriteCMS\Models\User;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Throwable;

class PackageManagementTest extends TestCase
{
    private Application $app;
    private Database $sqliteDb;
    private PDO $sqlitePdo;
    private ProductRepository $repository;
    private DigitalFileStorageService $storageService;
    private ProductManagementService $service;
    private string $tempStorageDir;

    protected function setUp(): void
    {
        if (!defined('PHPUNIT_RUNNING')) {
            define('PHPUNIT_RUNNING', true);
        }

        $this->app = new Application();

        $this->sqlitePdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
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

        $this->tempStorageDir = sys_get_temp_dir() . '/fd_pkg_test_' . uniqid('', true);
        @mkdir($this->tempStorageDir, 0755, true);

        $this->storageService = new DigitalFileStorageService($this->tempStorageDir);
        $this->repository = new ProductRepository($this->sqliteDb);
        $this->service = new ProductManagementService($this->repository, $this->storageService);

        $this->app->singleton(ProductRepository::class, fn () => $this->repository);
        $this->app->singleton(DigitalFileStorageService::class, fn () => $this->storageService);
        $this->app->singleton(ProductManagementService::class, fn () => $this->service);

        $_SESSION = [
            'auth_user_id'   => 1,
            'auth_user_name' => 'Admin User',
            '_token'         => 'valid_package_csrf_token',
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
        if (is_dir($this->tempStorageDir)) {
            $files = glob($this->tempStorageDir . '/*');
            if ($files) {
                foreach ($files as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
            }
            @rmdir($this->tempStorageDir);
        }

        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);
    }

    private function assertPrice(string $expected, mixed $actualPrice): void
    {
        $this->assertSame($expected, number_format((float)$actualPrice, 2, '.', ''));
    }

    private function createDigitalProduct(string $title = 'E-Book PDF', string $price = '50.00', string $status = ProductStatus::DRAFT): int
    {
        return $this->service->createDigitalProduct([
            'title'          => $title,
            'original_price' => $price,
            'status'         => $status,
        ], [
            'version' => '1.0.0',
        ]);
    }

    private function createServiceProduct(string $title = 'Consulting Hour', string $price = '100.00', string $status = ProductStatus::PUBLISHED): int
    {
        return $this->service->createService([
            'title'          => $title,
            'original_price' => $price,
            'status'         => $status,
        ], [
            'delivery_time_days' => 2,
        ]);
    }

    // =========================================================================
    // 1. Package creation with proper product record
    // =========================================================================
    public function testPackageCreationProductRecord(): void
    {
        $pkgId = $this->service->createPackage([
            'title'            => 'Starter Suite',
            'description'      => 'A great bundle',
            'original_price'   => '150.00',
            'discount_percent' => '20.00',
            'status'           => ProductStatus::DRAFT,
        ]);

        $this->assertGreaterThan(0, $pkgId);
        $product = $this->repository->findProduct($pkgId);
        $this->assertNotNull($product);
        $this->assertSame('Starter Suite', $product->title);
        $this->assertSame(ProductType::PACKAGE, $product->product_type);
        $this->assertSame(ProductStatus::DRAFT, $product->status);
        $this->assertPrice('150.00', $product->original_price);
        $this->assertPrice('20.00', $product->discount_percent);
        $this->assertPrice('120.00', $product->final_price);
        $this->assertSame(0, (int)$product->is_free);
    }

    // =========================================================================
    // 2. Package detail record creation in favorite_digital_packages
    // =========================================================================
    public function testPackageDetailRecordCreation(): void
    {
        $pkgId = $this->service->createPackage([
            'title' => 'Design Pack',
        ], [
            'package_type' => 'bundle',
        ]);

        $pkgDetail = $this->repository->findPackageByProductId($pkgId);
        $this->assertNotNull($pkgDetail);
        $this->assertSame($pkgId, (int)$pkgDetail->product_id);
        $this->assertSame('bundle', $pkgDetail->package_type);
        $this->assertSame(0, (int)$pkgDetail->total_items_count);
    }

    // =========================================================================
    // 3. Add valid digital product to package
    // =========================================================================
    public function testAddValidDigitalProductToPackage(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Web Dev Kit']);
        $digitalId = $this->createDigitalProduct('PHP Video Course', '80.00');

        $itemId = $this->service->addPackageItem($pkgId, $digitalId);
        $this->assertGreaterThan(0, $itemId);

        $package = $this->repository->findPackageByProductId($pkgId);
        $this->assertSame(1, (int)$package->total_items_count);

        $items = $this->repository->getPackageItems((int)$package->id);
        $this->assertCount(1, $items);
        $this->assertSame($digitalId, (int)$items[0]->included_product_id);
        $this->assertSame(1, (int)$items[0]->sort_order);
    }

    // =========================================================================
    // 4. Add valid service to package
    // =========================================================================
    public function testAddValidServiceToPackage(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Agency Starter']);
        $serviceId = $this->createServiceProduct('Code Review', '120.00');

        $itemId = $this->service->addPackageItem($pkgId, $serviceId);
        $this->assertGreaterThan(0, $itemId);

        $package = $this->repository->findPackageByProductId($pkgId);
        $this->assertSame(1, (int)$package->total_items_count);

        $items = $this->repository->getPackageItems((int)$package->id);
        $this->assertSame($serviceId, (int)$items[0]->included_product_id);
    }

    // =========================================================================
    // 5. Reject nested package inclusion
    // =========================================================================
    public function testRejectNestedPackageInclusion(): void
    {
        $parentPkg = $this->service->createPackage(['title' => 'Parent Bundle']);
        $childPkg = $this->service->createPackage(['title' => 'Child Bundle']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot include another package');

        $this->service->addPackageItem($parentPkg, $childPkg);
    }

    // =========================================================================
    // 6. Reject membership product inclusion
    // =========================================================================
    public function testRejectMembershipProductInclusion(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Mega Pack']);

        // Insert a membership product manually into products table
        $membershipId = $this->repository->createProduct([
            'title'          => 'VIP Monthly Membership',
            'slug'           => 'vip-monthly-membership',
            'product_type'   => ProductType::MEMBERSHIP,
            'original_price' => '29.99',
            'status'         => ProductStatus::PUBLISHED,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be included in a package');

        $this->service->addPackageItem($pkgId, $membershipId);
    }

    // =========================================================================
    // 7. Reject non-existent product ID
    // =========================================================================
    public function testRejectNonExistentProductId(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Test Pack']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $this->service->addPackageItem($pkgId, 999999);
    }

    // =========================================================================
    // 8. Reject self-inclusion
    // =========================================================================
    public function testRejectSelfInclusion(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Self Reference Pack']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot include itself');

        $this->service->addPackageItem($pkgId, $pkgId);
    }

    // =========================================================================
    // 9. Reject duplicate item addition (service level)
    // =========================================================================
    public function testRejectDuplicateItemAdditionAtServiceLevel(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Duplicate Test Pack']);
        $digitalId = $this->createDigitalProduct('E-Book', '25.00');

        $this->service->addPackageItem($pkgId, $digitalId);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is already included in this package');

        $this->service->addPackageItem($pkgId, $digitalId);
    }

    // =========================================================================
    // 10. Database unique constraint rejects duplicate
    // =========================================================================
    public function testDatabaseUniqueConstraintRejectsDuplicateItem(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'DB Unique Constraint Pack']);
        $digitalId = $this->createDigitalProduct('Icon Pack', '15.00');

        $package = $this->repository->findPackageByProductId($pkgId);
        $this->repository->addPackageItem((int)$package->id, $digitalId, 1);

        $this->expectException(PDOException::class);
        // Direct DB insertion bypassing service layer must fail on unique index
        $this->repository->addPackageItem((int)$package->id, $digitalId, 2);
    }

    // =========================================================================
    // 11. Add multiple items sequentially with ascending sort order
    // =========================================================================
    public function testAddMultipleItemsWithAscendingSortOrder(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Multi Item Pack']);
        $p1 = $this->createDigitalProduct('Item 1', '10.00');
        $p2 = $this->createServiceProduct('Item 2', '20.00');
        $p3 = $this->createDigitalProduct('Item 3', '30.00');

        $this->service->addPackageItem($pkgId, $p1);
        $this->service->addPackageItem($pkgId, $p2);
        $this->service->addPackageItem($pkgId, $p3);

        $package = $this->repository->findPackageByProductId($pkgId);
        $this->assertSame(3, (int)$package->total_items_count);

        $items = $this->repository->getPackageItems((int)$package->id);
        $this->assertCount(3, $items);
        $this->assertSame(1, (int)$items[0]->sort_order);
        $this->assertSame($p1, (int)$items[0]->included_product_id);
        $this->assertSame(2, (int)$items[1]->sort_order);
        $this->assertSame($p2, (int)$items[1]->included_product_id);
        $this->assertSame(3, (int)$items[2]->sort_order);
        $this->assertSame($p3, (int)$items[2]->included_product_id);
    }

    // =========================================================================
    // 12. Remove item from package
    // =========================================================================
    public function testRemoveItemFromPackage(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Remove Item Pack']);
        $p1 = $this->createDigitalProduct('Product A', '15.00');
        $p2 = $this->createDigitalProduct('Product B', '25.00');

        $this->service->addPackageItem($pkgId, $p1);
        $this->service->addPackageItem($pkgId, $p2);

        $removed = $this->service->removePackageItem($pkgId, $p1);
        $this->assertTrue($removed);

        $package = $this->repository->findPackageByProductId($pkgId);
        $this->assertSame(1, (int)$package->total_items_count);

        $items = $this->repository->getPackageItems((int)$package->id);
        $this->assertCount(1, $items);
        $this->assertSame($p2, (int)$items[0]->included_product_id);
    }

    // =========================================================================
    // 13. Reorder package items
    // =========================================================================
    public function testReorderPackageItems(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Reorder Pack']);
        $p1 = $this->createDigitalProduct('P1', '10.00');
        $p2 = $this->createDigitalProduct('P2', '20.00');
        $p3 = $this->createDigitalProduct('P3', '30.00');

        $this->service->addPackageItem($pkgId, $p1);
        $this->service->addPackageItem($pkgId, $p2);
        $this->service->addPackageItem($pkgId, $p3);

        // Reverse order: [p3, p1, p2]
        $this->service->reorderPackageItems($pkgId, [$p3, $p1, $p2]);

        $package = $this->repository->findPackageByProductId($pkgId);
        $items = $this->repository->getPackageItems((int)$package->id);

        $this->assertSame($p3, (int)$items[0]->included_product_id);
        $this->assertSame(1, (int)$items[0]->sort_order);
        $this->assertSame($p1, (int)$items[1]->included_product_id);
        $this->assertSame(2, (int)$items[1]->sort_order);
        $this->assertSame($p2, (int)$items[2]->included_product_id);
        $this->assertSame(3, (int)$items[2]->sort_order);
    }

    // =========================================================================
    // 14. Empty package CANNOT be published
    // =========================================================================
    public function testEmptyPackageCannotBePublished(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Empty Package']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be published without at least one valid included item');

        $this->service->publishProduct($pkgId);
    }

    // =========================================================================
    // 15. Valid package with items CAN be published
    // =========================================================================
    public function testValidPackageWithItemsPublishesSuccessfully(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Ready Package']);
        $p1 = $this->createDigitalProduct('Ready Item', '50.00');
        $this->service->addPackageItem($pkgId, $p1);

        $published = $this->service->publishProduct($pkgId);
        $this->assertTrue($published);

        $product = $this->repository->findProduct($pkgId);
        $this->assertSame(ProductStatus::PUBLISHED, $product->status);
    }

    // =========================================================================
    // 16. Package with archived included item CANNOT be published
    // =========================================================================
    public function testPackageWithArchivedIncludedItemCannotBePublished(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Archived Item Pack']);
        $p1 = $this->createDigitalProduct('Archived Item', '40.00', ProductStatus::DRAFT);

        $this->service->addPackageItem($pkgId, $p1);

        // Archive item after inclusion
        $this->service->archiveProduct($p1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is archived');

        $this->service->publishProduct($pkgId);
    }

    // =========================================================================
    // 17. Published package cannot have its last item removed
    // =========================================================================
    public function testPublishedPackageCannotLoseLastItem(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Single Item Published Pack']);
        $p1 = $this->createDigitalProduct('Sole Item', '30.00');
        $this->service->addPackageItem($pkgId, $p1);
        $this->service->publishProduct($pkgId);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot remove the last item from a published package');

        $this->service->removePackageItem($pkgId, $p1);
    }

    // =========================================================================
    // 18. Lifecycle status transitions (draft, publish, archive)
    // =========================================================================
    public function testPackageLifecycleTransitions(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Lifecycle Pack']);
        $p1 = $this->createDigitalProduct('Item 1', '20.00');
        $this->service->addPackageItem($pkgId, $p1);

        $p = $this->repository->findProduct($pkgId);
        $this->assertSame(ProductStatus::DRAFT, $p->status);

        $this->service->publishProduct($pkgId);
        $p = $this->repository->findProduct($pkgId);
        $this->assertSame(ProductStatus::PUBLISHED, $p->status);

        $this->service->archiveProduct($pkgId);
        $p = $this->repository->findProduct($pkgId);
        $this->assertSame(ProductStatus::ARCHIVED, $p->status);

        $this->service->draftProduct($pkgId);
        $p = $this->repository->findProduct($pkgId);
        $this->assertSame(ProductStatus::DRAFT, $p->status);
    }

    // =========================================================================
    // 19. Package pricing calculation: original_price + discount_percent
    // =========================================================================
    public function testPackagePricingCalculation(): void
    {
        $pkgId = $this->service->createPackage([
            'title'            => 'Discounted Bundle',
            'original_price'   => '200.00',
            'discount_percent' => '25.00',
        ]);

        $product = $this->repository->findProduct($pkgId);
        $this->assertPrice('200.00', $product->original_price);
        $this->assertPrice('25.00', $product->discount_percent);
        $this->assertPrice('150.00', $product->final_price);
    }

    // =========================================================================
    // 20. Package pricing with zero discount
    // =========================================================================
    public function testPackagePricingZeroDiscount(): void
    {
        $pkgId = $this->service->createPackage([
            'title'            => 'Full Price Bundle',
            'original_price'   => '99.99',
            'discount_percent' => '0.00',
        ]);

        $product = $this->repository->findProduct($pkgId);
        $this->assertPrice('99.99', $product->final_price);
    }

    // =========================================================================
    // 21. Free package (is_free = 1)
    // =========================================================================
    public function testFreePackagePricing(): void
    {
        $pkgId = $this->service->createPackage([
            'title'            => 'Free Community Bundle',
            'original_price'   => '50.00',
            'discount_percent' => '0.00',
            'is_free'          => 1,
        ]);

        $product = $this->repository->findProduct($pkgId);
        $this->assertSame(1, (int)$product->is_free);
        $this->assertPrice('0.00', $product->final_price);
    }

    // =========================================================================
    // 22. Package update modifies fields and recalculates pricing
    // =========================================================================
    public function testPackageUpdate(): void
    {
        $pkgId = $this->service->createPackage([
            'title'          => 'Old Title',
            'original_price' => '100.00',
        ]);

        $this->service->updatePackage($pkgId, [
            'title'            => 'Updated Title',
            'original_price'   => '180.00',
            'discount_percent' => '50.00',
            'description'      => 'Updated description',
        ]);

        $product = $this->repository->findProduct($pkgId);
        $this->assertSame('Updated Title', $product->title);
        $this->assertSame('Updated description', $product->description);
        $this->assertPrice('180.00', $product->original_price);
        $this->assertPrice('90.00', $product->final_price);
    }

    // =========================================================================
    // 23. Package slug uniqueness enforcement
    // =========================================================================
    public function testPackageSlugUniquenessEnforcement(): void
    {
        $id1 = $this->service->createPackage([
            'title' => 'Web Suite Bundle',
            'slug'  => 'web-suite-bundle',
        ]);
        $p1 = $this->repository->findProduct($id1);
        $this->assertSame('web-suite-bundle', $p1->slug);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Slug 'web-suite-bundle' is already in use by another product.");

        $this->service->createPackage([
            'title' => 'Another Web Suite Bundle',
            'slug'  => 'web-suite-bundle',
        ]);
    }

    // =========================================================================
    // 24. getPackageItemsWithProducts returns joined details
    // =========================================================================
    public function testGetPackageItemsWithProducts(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Joined Items Pack']);
        $p1 = $this->createDigitalProduct('Digital Asset', '35.00');
        $s1 = $this->createServiceProduct('Consulting Gig', '75.00');

        $this->service->addPackageItem($pkgId, $p1);
        $this->service->addPackageItem($pkgId, $s1);

        $package = $this->repository->findPackageByProductId($pkgId);
        $joined = $this->repository->getPackageItemsWithProducts((int)$package->id);

        $this->assertCount(2, $joined);
        $this->assertSame('Digital Asset', $joined[0]->title);
        $this->assertSame(ProductType::DIGITAL, $joined[0]->product_type);
        $this->assertPrice('35.00', $joined[0]->final_price);

        $this->assertSame('Consulting Gig', $joined[1]->title);
        $this->assertSame(ProductType::SERVICE, $joined[1]->product_type);
        $this->assertPrice('75.00', $joined[1]->final_price);
    }

    // =========================================================================
    // 25. getAvailableProductsForPackage excludes bundled products & invalid types
    // =========================================================================
    public function testGetAvailableProductsForPackage(): void
    {
        $pkg1 = $this->service->createPackage(['title' => 'Package One']);
        $d1 = $this->createDigitalProduct('Available Digital', '20.00');
        $d2 = $this->createDigitalProduct('Already Included Digital', '30.00');
        $s1 = $this->createServiceProduct('Available Service', '50.00');

        $this->service->addPackageItem($pkg1, $d2);

        $available = $this->repository->getAvailableProductsForPackage($pkg1);
        $availableIds = array_map(fn ($p) => (int)$p->id, $available);

        $this->assertContains($d1, $availableIds);
        $this->assertContains($s1, $availableIds);
        $this->assertNotContains($d2, $availableIds); // Already bundled
        $this->assertNotContains($pkg1, $availableIds); // Cannot bundle itself
    }

    // =========================================================================
    // 26. Controller index action lists packages
    // =========================================================================
    public function testControllerIndexListsPackages(): void
    {
        $this->service->createPackage(['title' => 'Index Package A']);
        $this->service->createPackage(['title' => 'Index Package B']);

        $controller = new AdminPackageController($this->app, $this->service);
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-digital-packages']);

        $html = $controller->index($request);
        $this->assertStringContainsString('Index Package A', $html);
        $this->assertStringContainsString('Index Package B', $html);
        $this->assertStringContainsString('Packages', $html);
    }

    // =========================================================================
    // 27. Controller create action renders form
    // =========================================================================
    public function testControllerCreateRendersForm(): void
    {
        $this->createDigitalProduct('Selectable Item', '45.00');

        $controller = new AdminPackageController($this->app, $this->service);
        $request = new Request(['action' => 'create'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-digital-packages?action=create']);

        $html = $controller->create($request);
        $this->assertStringContainsString('Create Package / Bundle', $html);
        $this->assertStringContainsString('Selectable Item', $html);
    }

    // =========================================================================
    // 28. Controller store handles POST submission
    // =========================================================================
    public function testControllerStoreCreatesPackage(): void
    {
        $d1 = $this->createDigitalProduct('Item to Bundle', '30.00');

        $controller = new AdminPackageController($this->app, $this->service);
        $request = new Request([], [
            '_token'         => 'valid_package_csrf_token',
            'action'         => 'store',
            'title'          => 'POST Created Bundle',
            'original_price' => '100.00',
            'status'         => 'draft',
            'included_items' => [$d1],
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-digital-packages']);

        $response = $controller->handle($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotEmpty($_SESSION['flash_success']);

        $pkg = $this->repository->findProductBySlug('post-created-bundle');
        $this->assertNotNull($pkg);
        $this->assertSame('POST Created Bundle', $pkg->title);

        $detail = $this->repository->findPackageByProductId((int)$pkg->id);
        $this->assertSame(1, (int)$detail->total_items_count);
    }

    // =========================================================================
    // 29. Controller edit action renders edit view
    // =========================================================================
    public function testControllerEditRendersView(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Editable Package']);
        $controller = new AdminPackageController($this->app, $this->service);
        $request = new Request(['action' => 'edit', 'id' => (string)$pkgId], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-digital-packages?action=edit&id=' . $pkgId]);

        $html = $controller->edit($request, $pkgId);
        $this->assertIsString($html);
        $this->assertStringContainsString('Edit Package / Bundle #' . $pkgId, $html);
        $this->assertStringContainsString('Editable Package', $html);
    }

    // =========================================================================
    // 30. Controller update handles POST update
    // =========================================================================
    public function testControllerUpdateSavesChanges(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Original Pkg Name']);
        $controller = new AdminPackageController($this->app, $this->service);

        $request = new Request([], [
            '_token'           => 'valid_package_csrf_token',
            'action'           => 'update',
            'id'               => $pkgId,
            'title'            => 'Updated Pkg Name',
            'slug'             => 'updated-pkg-name',
            'original_price'   => '120.00',
            'discount_percent' => '10.00',
            'status'           => 'draft',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-digital-packages']);

        $response = $controller->handle($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());

        $p = $this->repository->findProduct($pkgId);
        $this->assertSame('Updated Pkg Name', $p->title);
        $this->assertPrice('108.00', $p->final_price);
    }

    // =========================================================================
    // 31. Controller item manipulation (addItem, removeItem, reorderItems)
    // =========================================================================
    public function testControllerItemActions(): void
    {
        $pkgId = $this->service->createPackage(['title' => 'Interactive Items Pack']);
        $d1 = $this->createDigitalProduct('Bundle Item 1', '40.00');
        $d2 = $this->createDigitalProduct('Bundle Item 2', '60.00');

        $controller = new AdminPackageController($this->app, $this->service);

        // Add Item 1
        $reqAdd1 = new Request([], [
            '_token'              => 'valid_package_csrf_token',
            'action'              => 'add_item',
            'id'                  => $pkgId,
            'included_product_id' => $d1,
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-digital-packages']);
        $resp1 = $controller->handle($reqAdd1);
        $this->assertSame(302, $resp1->getStatusCode());

        // Add Item 2
        $reqAdd2 = new Request([], [
            '_token'              => 'valid_package_csrf_token',
            'action'              => 'add_item',
            'id'                  => $pkgId,
            'included_product_id' => $d2,
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-digital-packages']);
        $resp2 = $controller->handle($reqAdd2);
        $this->assertSame(302, $resp2->getStatusCode());

        $detail = $this->repository->findPackageByProductId($pkgId);
        $this->assertSame(2, (int)$detail->total_items_count);

        // Reorder Items: swap d2 and d1
        $reqReorder = new Request([], [
            '_token'   => 'valid_package_csrf_token',
            'action'   => 'reorder_items',
            'id'       => $pkgId,
            'item_ids' => [$d2, $d1],
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-digital-packages']);
        $resp3 = $controller->handle($reqReorder);
        $this->assertSame(302, $resp3->getStatusCode());

        $items = $this->repository->getPackageItems((int)$detail->id);
        $this->assertSame($d2, (int)$items[0]->included_product_id);

        // Remove Item 1
        $reqRemove = new Request([], [
            '_token'              => 'valid_package_csrf_token',
            'action'              => 'remove_item',
            'id'                  => $pkgId,
            'included_product_id' => $d1,
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-digital-packages']);
        $resp4 = $controller->handle($reqRemove);
        $this->assertSame(302, $resp4->getStatusCode());

        $detailAfter = $this->repository->findPackageByProductId($pkgId);
        $this->assertSame(1, (int)$detailAfter->total_items_count);
    }

    // =========================================================================
    // 32. Controller Security Guards: Auth, RBAC, and CSRF Protection
    // =========================================================================
    public function testControllerSecurityGuards(): void
    {
        $controller = new AdminPackageController($this->app, $this->service);

        // A. Auth Guard: unauthenticated user
        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);

        $reqUnauth = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-digital-packages']);
        $respUnauth = $controller->handle($reqUnauth);
        $this->assertInstanceOf(Response::class, $respUnauth);
        $this->assertSame(302, $respUnauth->getStatusCode());
        $this->assertSame('/admin/login', $respUnauth->getHeaders()['Location'] ?? null);

        // B. RBAC Guard: user without manage_options capability
        $_SESSION = ['auth_user_id' => 99];
        $nonAdmin = new class extends User {
            public int $id = 99;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool { return false; }
        };
        $GLOBALS['_test_current_user'] = $nonAdmin;

        $reqRbac = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-digital-packages']);
        $respRbac = $controller->handle($reqRbac);
        $this->assertSame(403, $respRbac->getStatusCode());

        // C. CSRF Protection on POST
        $admin = new class extends User {
            public int $id = 1;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool { return true; }
        };
        $GLOBALS['_test_current_user'] = $admin;
        $_SESSION = ['auth_user_id' => 1, '_token' => 'real_token'];

        $reqCsrfFail = new Request([], [
            '_token' => 'invalid_or_stolen_token',
            'action' => 'store',
            'title'  => 'Hacked Bundle',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-digital-packages']);
        $respCsrf = $controller->handle($reqCsrfFail);
        $this->assertSame(302, $respCsrf->getStatusCode());
        $this->assertNotEmpty($_SESSION['flash_error']);
        $this->assertStringContainsString('CSRF failure', $_SESSION['flash_error']);
    }

    // =========================================================================
    // 33. Dual Database Compatibility with live MySQL/MariaDB
    // =========================================================================
    public function testDualDatabaseCompatibilityWithMySQL(): void
    {
        $mysqlHost = '127.0.0.1';
        $mysqlUser = 'root';
        $mysqlPass = '';
        $mysqlDbName = 'favorite_cms_test_pkg_' . uniqid();

        try {
            $pdo = new PDO("mysql:host={$mysqlHost};charset=utf8mb4", $mysqlUser, $mysqlPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$mysqlDbName}`");
            $pdo->exec("USE `{$mysqlDbName}`");
        } catch (Throwable) {
            $this->markTestSkipped('Local MySQL/MariaDB server not accessible; skipping MySQL-specific verification.');
            return;
        }

        try {
            $mysqlDb = new class($pdo) extends Database {
                public function __construct(PDO $pdo)
                {
                    $this->pdo = $pdo;
                    $this->config = ['driver' => 'mysql'];
                    $this->prefix = 'fvt_';
                }
            };

            $mysqlDb->registerPrefixableTables(FavoriteDigitalPlugin::TABLES);

            // Run migrations on MySQL
            $migrator = new Migrator($mysqlDb);
            $applied = $migrator->migrate(APP_ROOT . '/plugins/favorite-digital/database/migrations');
            $this->assertCount(15, $applied);

            $repo = new ProductRepository($mysqlDb);
            $service = new ProductManagementService($repo, $this->storageService);

            // Create digital and service products on MySQL
            $d1 = $service->createDigitalProduct([
                'title'          => 'MySQL Digital Item',
                'original_price' => '60.00',
            ], ['version' => '1.0.0']);

            $s1 = $service->createService([
                'title'          => 'MySQL Service Item',
                'original_price' => '140.00',
            ], ['delivery_time_days' => 3]);

            // Create package on MySQL
            $pkgId = $service->createPackage([
                'title'            => 'MySQL Complete Bundle',
                'original_price'   => '160.00',
                'discount_percent' => '20.00',
            ], [
                'package_type' => 'bundle',
            ]);
            $this->assertGreaterThan(0, $pkgId);

            // Add items on MySQL
            $service->addPackageItem($pkgId, $d1);
            $service->addPackageItem($pkgId, $s1);

            $pkgDetail = $repo->findPackageByProductId($pkgId);
            $this->assertSame(2, (int)$pkgDetail->total_items_count);

            $items = $repo->getPackageItemsWithProducts((int)$pkgDetail->id);
            $this->assertCount(2, $items);
            $this->assertSame('MySQL Digital Item', $items[0]->title);
            $this->assertSame('MySQL Service Item', $items[1]->title);

            // Publish on MySQL
            $published = $service->publishProduct($pkgId);
            $this->assertTrue($published);

            $pkgProduct = $repo->findProduct($pkgId);
            $this->assertSame(ProductStatus::PUBLISHED, $pkgProduct->status);
            $this->assertPrice('128.00', $pkgProduct->final_price);

            // Verify unique index on MySQL
            $thrown = false;
            try {
                $repo->addPackageItem((int)$pkgDetail->id, $d1, 3);
            } catch (PDOException) {
                $thrown = true;
            }
            $this->assertTrue($thrown, 'MySQL unique index on (package_id, included_product_id) must reject duplicate');
        } finally {
            $pdo->exec("DROP DATABASE IF EXISTS `{$mysqlDbName}`");
        }
    }
}
