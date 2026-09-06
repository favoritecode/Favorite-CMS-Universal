<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoriteDigital;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Controllers\AdminProductController;
use FavoriteCMS\Digital\Controllers\AdminServiceController;
use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\FavoriteDigitalPlugin;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Services\DigitalFileStorageService;
use FavoriteCMS\Digital\Services\ProductManagementService;
use FavoriteCMS\Models\User;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

class ProductManagementTest extends TestCase
{
    private Application $app;
    private Database $sqliteDb;
    private PDO $sqlitePdo;
    private ProductRepository $repository;
    private DigitalFileStorageService $storageService;
    private ProductManagementService $service;
    private string $tempStorageDir;
    private array $createdTempFiles = [];

    protected function setUp(): void
    {
        if (!defined('PHPUNIT_RUNNING')) {
            define('PHPUNIT_RUNNING', true);
        }

        $this->app = new Application();

        // In-memory SQLite PDO for isolated test runs
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

        // Temporary storage directory for uploaded test files
        $this->tempStorageDir = sys_get_temp_dir() . '/fd_test_storage_' . uniqid('', true);
        @mkdir($this->tempStorageDir, 0755, true);

        $this->storageService = new DigitalFileStorageService($this->tempStorageDir);
        $this->repository = new ProductRepository($this->sqliteDb);
        $this->service = new ProductManagementService($this->repository, $this->storageService);

        $this->app->singleton(ProductRepository::class, fn () => $this->repository);
        $this->app->singleton(DigitalFileStorageService::class, fn () => $this->storageService);
        $this->app->singleton(ProductManagementService::class, fn () => $this->service);

        // Reset test session
        $_SESSION = [
            'auth_user_id'   => 1,
            'auth_user_name' => 'Admin User',
            '_token'         => 'valid_csrf_token_123',
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
        // Clean up created temporary files
        foreach ($this->createdTempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        // Clean up temp storage dir
        if (is_dir($this->tempStorageDir)) {
            $files = glob($this->tempStorageDir . '/*');
            if ($files) {
                foreach ($files as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
            }
            @unlink($this->tempStorageDir . '/.htaccess');
            @rmdir($this->tempStorageDir);
        }

        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);
    }

    private function assertPrice(string $expected, mixed $actualPrice): void
    {
        $this->assertSame($expected, number_format((float)$actualPrice, 2, '.', ''));
    }

    private function createMockUploadedFile(string $filename, string $content = 'Dummy file content'): array
    {
        $tmpPath = sys_get_temp_dir() . '/fd_mock_' . uniqid('', true) . '_' . basename($filename);
        file_put_contents($tmpPath, $content);
        $this->createdTempFiles[] = $tmpPath;

        return [
            'name'     => $filename,
            'type'     => 'application/octet-stream',
            'tmp_name' => $tmpPath,
            'error'    => UPLOAD_ERR_OK,
            'size'     => strlen($content),
        ];
    }

    // =========================================================================
    // 1. Create digital product with valid data
    // =========================================================================
    public function testCreateDigitalProductWithValidData(): void
    {
        $productInput = [
            'title'            => 'Universal CMS Masterclass',
            'slug'             => 'universal-cms-masterclass',
            'description'      => 'A comprehensive masterclass on Favorite CMS.',
            'original_price'   => '150.00',
            'discount_percent' => '20.00',
            'status'           => ProductStatus::DRAFT,
        ];

        $detailsInput = [
            'version'                => '2.1.0',
            'max_downloads'          => 5,
            'download_expiry_days'   => 30,
            'is_membership_eligible' => 1,
        ];

        $productId = $this->service->createDigitalProduct($productInput, $detailsInput);
        $this->assertGreaterThan(0, $productId);

        $product = $this->repository->findProduct($productId);
        $this->assertNotNull($product);
        $this->assertSame('Universal CMS Masterclass', $product->title);
        $this->assertSame('universal-cms-masterclass', $product->slug);
        $this->assertSame(ProductType::DIGITAL, $product->product_type);
        $this->assertSame(ProductStatus::DRAFT, $product->status);
        $this->assertPrice('150.00', $product->original_price);
        $this->assertPrice('20.00', $product->discount_percent);
        $this->assertPrice('120.00', $product->final_price);

        $details = $this->repository->findProductDetails($productId);
        $this->assertNotNull($details);
        $this->assertSame('2.1.0', $details->version);
        $this->assertSame(5, (int)$details->max_downloads);
        $this->assertSame(30, (int)$details->download_expiry_days);
        $this->assertSame(1, (int)$details->is_membership_eligible);
    }

    // =========================================================================
    // 2. Create service with valid data
    // =========================================================================
    public function testCreateServiceWithValidData(): void
    {
        $productInput = [
            'title'            => 'Full CMS Setup & Security Hardening',
            'slug'             => 'full-cms-setup-security',
            'description'      => 'We will configure and audit your entire CMS.',
            'original_price'   => '500.00',
            'discount_percent' => '10.00',
            'status'           => ProductStatus::DRAFT,
        ];

        $serviceInput = [
            'delivery_time_days'  => 3,
            'service_scope'       => 'Complete installation, SSL setup, firewall config.',
            'requirements_prompt' => 'Provide server SSH and domain DNS access.',
        ];

        $serviceId = $this->service->createService($productInput, $serviceInput);
        $this->assertGreaterThan(0, $serviceId);

        $product = $this->repository->findProduct($serviceId);
        $this->assertNotNull($product);
        $this->assertSame(ProductType::SERVICE, $product->product_type);
        $this->assertPrice('450.00', $product->final_price);

        $details = $this->repository->findServiceDetails($serviceId);
        $this->assertNotNull($details);
        $this->assertSame(3, (int)$details->delivery_time_days);
        $this->assertSame('Complete installation, SSL setup, firewall config.', $details->service_scope);
        $this->assertSame('Provide server SSH and domain DNS access.', $details->requirements_prompt);
    }

    // =========================================================================
    // 3. Update digital product
    // =========================================================================
    public function testUpdateDigitalProduct(): void
    {
        $id = $this->service->createDigitalProduct([
            'title'          => 'Old Product Title',
            'original_price' => '100.00',
        ], [
            'version' => '1.0.0',
        ]);

        $updateSuccess = $this->service->updateDigitalProduct($id, [
            'title'            => 'Updated Product Title',
            'slug'             => 'old-product-title',
            'original_price'   => '200.00',
            'discount_percent' => '25.00',
        ], [
            'version'       => '1.1.0',
            'max_downloads' => 10,
        ]);

        $this->assertTrue($updateSuccess);

        $updated = $this->repository->findProduct($id);
        $this->assertSame('Updated Product Title', $updated->title);
        $this->assertPrice('200.00', $updated->original_price);
        $this->assertPrice('150.00', $updated->final_price);

        $details = $this->repository->findProductDetails($id);
        $this->assertSame('1.1.0', $details->version);
        $this->assertSame(10, (int)$details->max_downloads);
    }

    // =========================================================================
    // 4. Update service
    // =========================================================================
    public function testUpdateService(): void
    {
        $id = $this->service->createService([
            'title'          => 'Initial Service',
            'original_price' => '300.00',
        ], [
            'delivery_time_days' => 2,
            'service_scope'      => 'Initial scope',
        ]);

        $this->service->updateService($id, [
            'title'          => 'Revised Service Scope',
            'original_price' => '350.00',
        ], [
            'delivery_time_days'  => 5,
            'service_scope'       => 'Extended scope with 30-day support',
            'requirements_prompt' => 'New credentials',
        ]);

        $details = $this->repository->findServiceDetails($id);
        $this->assertSame(5, (int)$details->delivery_time_days);
        $this->assertSame('Extended scope with 30-day support', $details->service_scope);
        $this->assertSame('New credentials', $details->requirements_prompt);
    }

    // =========================================================================
    // 5. Publish product without digital file (MUST FAIL for digital products)
    // =========================================================================
    public function testPublishDigitalProductWithoutFileFails(): void
    {
        $id = $this->service->createDigitalProduct([
            'title'          => 'Unattached Digital Guide',
            'original_price' => '25.00',
            'status'         => ProductStatus::DRAFT,
        ], []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot publish digital product: a downloadable digital file or resource must be configured first.');

        $this->service->publishProduct($id);
    }

    // =========================================================================
    // 6. Publish product with valid digital file (MUST SUCCEED)
    // =========================================================================
    public function testPublishDigitalProductWithValidFileSucceeds(): void
    {
        $zipFile = $this->createMockUploadedFile('ebook_bundle.zip', 'Mock ZIP content for testing');

        $id = $this->service->createDigitalProduct([
            'title'          => 'Complete eBook Bundle',
            'original_price' => '45.00',
            'status'         => ProductStatus::DRAFT,
        ], [
            'version' => '1.0.0',
        ], $zipFile);

        $published = $this->service->publishProduct($id);
        $this->assertTrue($published);

        $product = $this->repository->findProduct($id);
        $this->assertSame(ProductStatus::PUBLISHED, $product->status);
    }

    // =========================================================================
    // 7. Publish service without digital file (MUST SUCCEED)
    // =========================================================================
    public function testPublishServiceWithoutFileSucceeds(): void
    {
        $id = $this->service->createService([
            'title'          => 'Code Audit Service',
            'original_price' => '200.00',
            'status'         => ProductStatus::DRAFT,
        ], [
            'delivery_time_days' => 2,
        ]);

        // Services do not require a downloadable file to publish
        $published = $this->service->publishProduct($id);
        $this->assertTrue($published);

        $product = $this->repository->findProduct($id);
        $this->assertSame(ProductStatus::PUBLISHED, $product->status);
    }

    // =========================================================================
    // 8. Status transition draft -> published -> archived
    // =========================================================================
    public function testStatusTransitionFlow(): void
    {
        $zipFile = $this->createMockUploadedFile('course_assets.zip', 'Course ZIP');
        $id = $this->service->createDigitalProduct([
            'title' => 'Transition Test Product',
        ], [], $zipFile);

        $this->assertSame(ProductStatus::DRAFT, $this->repository->findProduct($id)->status);

        // Draft -> Published
        $this->service->publishProduct($id);
        $this->assertSame(ProductStatus::PUBLISHED, $this->repository->findProduct($id)->status);

        // Published -> Archived
        $this->service->archiveProduct($id);
        $this->assertSame(ProductStatus::ARCHIVED, $this->repository->findProduct($id)->status);

        // Archived -> Draft
        $this->service->draftProduct($id);
        $this->assertSame(ProductStatus::DRAFT, $this->repository->findProduct($id)->status);
    }

    // =========================================================================
    // 9. Slug auto-generation from title
    // =========================================================================
    public function testSlugAutoGenerationFromTitle(): void
    {
        $id = $this->service->createDigitalProduct([
            'title' => 'Special UI Component Library (React & Vue)',
            'slug'  => '', // Empty slug should trigger auto-generation
        ], []);

        $product = $this->repository->findProduct($id);
        $this->assertSame('special-ui-component-library-react-vue', $product->slug);
    }

    // =========================================================================
    // 10. Explicit custom slug sanitization
    // =========================================================================
    public function testExplicitCustomSlugSanitization(): void
    {
        $id = $this->service->createDigitalProduct([
            'title' => 'Custom Slug Item',
            'slug'  => '   My---Awesome__Product!   ',
        ], []);

        $product = $this->repository->findProduct($id);
        $this->assertSame('my-awesome-product', $product->slug);
    }

    // =========================================================================
    // 11. Slug uniqueness enforcement (duplicate slug must fail)
    // =========================================================================
    public function testSlugUniquenessEnforcement(): void
    {
        $this->service->createDigitalProduct([
            'title' => 'First Product',
            'slug'  => 'duplicate-slug-test',
        ], []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Slug 'duplicate-slug-test' is already in use by another product.");

        $this->service->createDigitalProduct([
            'title' => 'Second Product with Same Slug',
            'slug'  => 'duplicate-slug-test',
        ], []);
    }

    // =========================================================================
    // 12. Updating product keeping its own slug (must succeed)
    // =========================================================================
    public function testUpdatingProductKeepingOwnSlugSucceeds(): void
    {
        $id = $this->service->createDigitalProduct([
            'title' => 'Existing Product',
            'slug'  => 'unique-existing-slug',
        ], []);

        $updateSuccess = $this->service->updateDigitalProduct($id, [
            'title' => 'Updated Existing Product',
            'slug'  => 'unique-existing-slug', // Same slug on same ID must be allowed
        ], []);

        $this->assertTrue($updateSuccess);
    }

    // =========================================================================
    // 13. Negative price rejected
    // =========================================================================
    public function testNegativePriceRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Original price cannot be negative.');

        $this->service->createDigitalProduct([
            'title'          => 'Negative Price Product',
            'original_price' => '-10.00',
        ], []);
    }

    // =========================================================================
    // 14. Discount percentage 0% (valid, final_price = original_price)
    // =========================================================================
    public function testDiscountPercentageZeroPercent(): void
    {
        $id = $this->service->createDigitalProduct([
            'title'            => 'Full Price Product',
            'original_price'   => '125.50',
            'discount_percent' => '0.00',
        ], []);

        $product = $this->repository->findProduct($id);
        $this->assertPrice('125.50', $product->final_price);
    }

    // =========================================================================
    // 15. Discount percentage 100% (valid, final_price = 0.00)
    // =========================================================================
    public function testDiscountPercentageHundredPercent(): void
    {
        $id = $this->service->createDigitalProduct([
            'title'            => '100% Off Promotion',
            'original_price'   => '299.00',
            'discount_percent' => '100.00',
        ], []);

        $product = $this->repository->findProduct($id);
        $this->assertPrice('0.00', $product->final_price);
    }

    // =========================================================================
    // 16. Discount percentage > 100% rejected
    // =========================================================================
    public function testDiscountPercentageGreaterThanHundredRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Discount percentage must be between 0 and 100.');

        $this->service->createDigitalProduct([
            'title'            => 'Over Discounted',
            'original_price'   => '100.00',
            'discount_percent' => '100.01',
        ], []);
    }

    // =========================================================================
    // 17. Discount percentage < 0% rejected
    // =========================================================================
    public function testDiscountPercentageNegativeRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Discount percentage must be between 0 and 100.');

        $this->service->createDigitalProduct([
            'title'            => 'Negative Discount',
            'original_price'   => '100.00',
            'discount_percent' => '-15.00',
        ], []);
    }

    // =========================================================================
    // 18. Free product flag (৳0.00 selling price, even if original_price is set)
    // =========================================================================
    public function testFreeProductFlagProducesZeroSellingPrice(): void
    {
        $id = $this->service->createDigitalProduct([
            'title'          => 'Free Community Template',
            'original_price' => '49.99',
            'is_free'        => 1,
        ], []);

        $product = $this->repository->findProduct($id);
        $this->assertSame(1, (int)$product->is_free);
        $this->assertPrice('49.99', $product->original_price);
        $this->assertPrice('0.00', $product->final_price);
    }

    // =========================================================================
    // 19. Final price accurately calculated using high precision string/decimal math
    // =========================================================================
    public function testHighPrecisionDecimalMathNeverDrifts(): void
    {
        // 19.99 with 15% discount: 19.99 * 0.85 = 16.9915 -> 16.99
        $id1 = $this->service->createDigitalProduct([
            'title'            => 'Precision Test 1',
            'original_price'   => '19.99',
            'discount_percent' => '15.00',
        ], []);

        $p1 = $this->repository->findProduct($id1);
        $this->assertPrice('16.99', $p1->final_price);

        // 79.95 with 33.33% discount: 79.95 * (1 - 0.3333) = 53.302665 -> 53.30
        $id2 = $this->service->createDigitalProduct([
            'title'            => 'Precision Test 2',
            'original_price'   => '79.95',
            'discount_percent' => '33.33',
        ], []);

        $p2 = $this->repository->findProduct($id2);
        $this->assertPrice('53.30', $p2->final_price);
    }

    // =========================================================================
    // 20. Download limit configuration (0/null = unlimited, integer = cap)
    // =========================================================================
    public function testDownloadLimitConfiguration(): void
    {
        $idUnlimited = $this->service->createDigitalProduct([
            'title' => 'Unlimited Downloads Product',
        ], [
            'max_downloads' => 0,
        ]);

        $d1 = $this->repository->findProductDetails($idUnlimited);
        $this->assertSame(0, (int)$d1->max_downloads);

        $idCapped = $this->service->createDigitalProduct([
            'title' => 'Capped Downloads Product',
        ], [
            'max_downloads' => 3,
        ]);

        $d2 = $this->repository->findProductDetails($idCapped);
        $this->assertSame(3, (int)$d2->max_downloads);
    }

    // =========================================================================
    // 21. Secure file upload: valid .zip file stored with sha256 hash
    // =========================================================================
    public function testSecureFileUploadStoresHashAndMime(): void
    {
        $filePayload = 'Simulated ZIP archive content for binary verification';
        $expectedHash = hash('sha256', $filePayload);
        $mockFile = $this->createMockUploadedFile('package.zip', $filePayload);

        $id = $this->service->createDigitalProduct([
            'title' => 'Zip Archive Product',
        ], [], $mockFile);

        $details = $this->repository->findProductDetails($id);
        $this->assertNotNull($details);
        $this->assertSame('package.zip', $details->file_name);
        $this->assertSame($expectedHash, $details->file_hash);
        $this->assertSame(strlen($filePayload), (int)$details->file_size);
        $this->assertNotEmpty($details->file_path);
    }

    // =========================================================================
    // 22. Dangerous file upload rejected (.php, .exe, .sh, multi-extension .php.zip)
    // =========================================================================
    public function testDangerousFileUploadsRejected(): void
    {
        $dangerousFiles = [
            'webshell.php',
            'malware.exe',
            'script.sh',
            'exploit.php.zip',
            'shell.phtml',
            'batch.bat',
        ];

        foreach ($dangerousFiles as $badName) {
            $mockFile = $this->createMockUploadedFile($badName, 'Dangerous code');
            try {
                $this->service->createDigitalProduct([
                    'title' => 'Dangerous File Test ' . $badName,
                ], [], $mockFile);
                $this->fail("Expected InvalidArgumentException for forbidden file: {$badName}");
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }

    // =========================================================================
    // 23. Path traversal attack in file upload rejected
    // =========================================================================
    public function testPathTraversalSanitization(): void
    {
        $traversalMock = $this->createMockUploadedFile('../../etc/passwd.zip', 'Fake archive');

        $id = $this->service->createDigitalProduct([
            'title' => 'Traversal Product',
        ], [], $traversalMock);

        $details = $this->repository->findProductDetails($id);
        // The filename should have been sanitized with basename
        $this->assertSame('passwd.zip', $details->file_name);
        $this->assertStringNotContainsString('..', $details->file_path);
    }

    // =========================================================================
    // 24. Service delivery days and scope requirements prompt saved and retrieved
    // =========================================================================
    public function testServiceDeliveryDaysAndScopePersisted(): void
    {
        $id = $this->service->createService([
            'title' => 'Speed Optimization Service',
        ], [
            'delivery_time_days'  => 4,
            'service_scope'       => 'Minification, caching, database indexing',
            'requirements_prompt' => 'Provide staging access URL and credentials',
        ]);

        $details = $this->repository->findServiceDetails($id);
        $this->assertSame(4, (int)$details->delivery_time_days);
        $this->assertSame('Minification, caching, database indexing', $details->service_scope);
        $this->assertSame('Provide staging access URL and credentials', $details->requirements_prompt);
    }

    // =========================================================================
    // 25. Membership eligibility flag saved and retrieved
    // =========================================================================
    public function testMembershipEligibilityFlagPersisted(): void
    {
        $idEligible = $this->service->createDigitalProduct([
            'title'                  => 'VIP Included Product',
            'is_membership_eligible' => 1,
        ], []);

        $d1 = $this->repository->findProductDetails($idEligible);
        $this->assertSame(1, (int)$d1->is_membership_eligible);

        $idExcluded = $this->service->createDigitalProduct([
            'title'                  => 'Standalone Only Product',
            'is_membership_eligible' => 0,
        ], []);

        $d2 = $this->repository->findProductDetails($idExcluded);
        $this->assertSame(0, (int)$d2->is_membership_eligible);
    }

    // =========================================================================
    // 26. Authentication and authorization guard: non-admin cannot access or mutate
    // =========================================================================
    public function testAuthenticationAndRbacGuard(): void
    {
        $controller = new AdminProductController($this->app, $this->service);

        // Case A: Unauthenticated user -> redirects to /admin/login
        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);
        $unauthReq = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $respA = $controller->handle($unauthReq);

        $this->assertInstanceOf(Response::class, $respA);
        $this->assertSame(302, $respA->getStatusCode());
        $this->assertSame('/admin/login', $respA->getHeaders()['Location'] ?? null);

        // Case B: Inactive user -> 403
        $bannedUser = new class extends User {
            public int $id = 99;
            public function isActive(): bool { return false; }
            public function can(string $capability): bool { return true; }
        };
        $GLOBALS['_test_current_user'] = $bannedUser;
        $_SESSION['auth_user_id'] = 99;

        $respB = $controller->handle($unauthReq);
        $this->assertInstanceOf(Response::class, $respB);
        $this->assertSame(403, $respB->getStatusCode());

        // Case C: Non-admin user (lacking manage_options) -> 403
        $subscriberUser = new class extends User {
            public int $id = 50;
            public function isActive(): bool { return true; }
            public function can(string $capability): bool { return false; }
        };
        $GLOBALS['_test_current_user'] = $subscriberUser;
        $_SESSION['auth_user_id'] = 50;

        $respC = $controller->handle($unauthReq);
        $this->assertInstanceOf(Response::class, $respC);
        $this->assertSame(403, $respC->getStatusCode());
    }

    // =========================================================================
    // 27. CSRF protection on POST actions
    // =========================================================================
    public function testCsrfProtectionOnPostActions(): void
    {
        $controller = new AdminProductController($this->app, $this->service);

        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'valid_session_token';

        // POST with invalid CSRF token
        $request = new Request(
            [],
            ['_token' => 'invalid_csrf_token', 'action' => 'store', 'title' => 'Test Product'],
            ['REQUEST_METHOD' => 'POST']
        );

        $response = $controller->handle($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotEmpty($_SESSION['flash_error']);
        $this->assertStringContainsString('CSRF failure', $_SESSION['flash_error']);
    }

    // =========================================================================
    // 28. Dual database compatibility: runs cleanly on SQLite and MariaDB/MySQL
    // =========================================================================
    public function testDualDatabaseCompatibilityWithMySQL(): void
    {
        // Check if MariaDB/MySQL is available
        $mysqlHost = '127.0.0.1';
        $mysqlUser = 'root';
        $mysqlPass = '';
        $mysqlDbName = 'favorite_cms_test_' . uniqid();

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
            $this->assertCount(16, $applied);

            $repo = new ProductRepository($mysqlDb);
            $service = new ProductManagementService($repo, $this->storageService);

            // Test digital product creation on MySQL
            $pid = $service->createDigitalProduct([
                'title'            => 'MySQL Digital Item',
                'original_price'   => '89.99',
                'discount_percent' => '10.00',
            ], [
                'version'       => '1.0.0',
                'max_downloads' => 3,
            ]);
            $this->assertGreaterThan(0, $pid);

            $product = $repo->findProduct($pid);
            $this->assertSame('MySQL Digital Item', $product->title);
            $this->assertPrice('80.99', $product->final_price);

            // Test service creation on MySQL
            $sid = $service->createService([
                'title'          => 'MySQL Managed Service',
                'original_price' => '250.00',
            ], [
                'delivery_time_days' => 5,
            ]);
            $this->assertGreaterThan(0, $sid);

            $s = $repo->findProduct($sid);
            $this->assertSame(ProductType::SERVICE, $s->product_type);
        } finally {
            $pdo->exec("DROP DATABASE IF EXISTS `{$mysqlDbName}`");
        }
    }
}
