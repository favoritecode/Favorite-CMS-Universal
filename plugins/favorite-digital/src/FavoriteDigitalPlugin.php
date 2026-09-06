<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Digital\Controllers\AdminMembershipController;
use FavoriteCMS\Digital\Controllers\AdminOrderController;
use FavoriteCMS\Digital\Controllers\AdminPackageController;
use FavoriteCMS\Digital\Controllers\AdminProductController;
use FavoriteCMS\Digital\Controllers\AdminServiceController;
use FavoriteCMS\Digital\Controllers\CustomerOrderController;
use FavoriteCMS\Digital\Contracts\EntitlementCheckerInterface;
use FavoriteCMS\Digital\Repositories\OrderRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Services\DefaultEntitlementChecker;
use FavoriteCMS\Digital\Services\DigitalFileStorageService;
use FavoriteCMS\Digital\Services\MembershipLifecycleService;
use FavoriteCMS\Digital\Services\OrderService;
use FavoriteCMS\Digital\Services\ProductManagementService;

final class FavoriteDigitalPlugin
{
    public const TABLES = [
        'favorite_digital_products',
        'favorite_digital_product_details',
        'favorite_digital_service_details',
        'favorite_digital_packages',
        'favorite_digital_package_items',
        'favorite_digital_membership_plans',
        'favorite_digital_memberships',
        'favorite_digital_orders',
        'favorite_digital_order_items',
        'favorite_digital_order_payments',
        'favorite_digital_entitlements',
        'favorite_digital_downloads',
        'favorite_digital_wallets',
        'favorite_digital_wallet_transactions',
        'favorite_digital_refunds',
    ];

    private static ?self $instance = null;
    private Application $app;
    private bool $booted = false;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public static function getInstance(?Application $app = null): ?self
    {
        if (self::$instance === null && $app !== null) {
            self::$instance = new self($app);
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function bootstrap(Application $app): self
    {
        $plugin = new self($app);
        $plugin->register();
        $plugin->boot();
        self::$instance = $plugin;
        return $plugin;
    }

    public function register(): void
    {
        // 1. Register prefixable tables with Database
        if ($this->app->has(Database::class)) {
            $db = $this->app->make(Database::class);
            if (method_exists($db, 'registerPrefixableTables')) {
                $db->registerPrefixableTables(self::TABLES);
            }
        }

        // 2. Bind Services and Repositories in Container
        $this->app->singleton(ProductRepository::class, function ($app): ProductRepository {
            return new ProductRepository($app->make(Database::class));
        });

        $this->app->singleton(DigitalFileStorageService::class, function (): DigitalFileStorageService {
            return new DigitalFileStorageService();
        });

        $this->app->singleton(ProductManagementService::class, function ($app): ProductManagementService {
            return new ProductManagementService(
                $app->make(ProductRepository::class),
                $app->make(DigitalFileStorageService::class)
            );
        });

        $this->app->singleton(AdminProductController::class, function ($app): AdminProductController {
            return new AdminProductController(
                $app,
                $app->make(ProductManagementService::class)
            );
        });

        $this->app->singleton(AdminServiceController::class, function ($app): AdminServiceController {
            return new AdminServiceController(
                $app,
                $app->make(ProductManagementService::class)
            );
        });

        $this->app->singleton(AdminPackageController::class, function ($app): AdminPackageController {
            return new AdminPackageController(
                $app,
                $app->make(ProductManagementService::class)
            );
        });

        $this->app->singleton(MembershipLifecycleService::class, function ($app): MembershipLifecycleService {
            return new MembershipLifecycleService(
                $app->make(ProductRepository::class)
            );
        });

        $this->app->singleton(AdminMembershipController::class, function ($app): AdminMembershipController {
            return new AdminMembershipController(
                $app,
                $app->make(MembershipLifecycleService::class),
                $app->make(ProductManagementService::class)
            );
        });

        $this->app->singleton(OrderRepository::class, function ($app): OrderRepository {
            return new OrderRepository($app->make(Database::class));
        });

        $this->app->singleton(EntitlementCheckerInterface::class, function ($app): EntitlementCheckerInterface {
            $db = $app->has(Database::class) ? $app->make(Database::class) : null;
            return new DefaultEntitlementChecker($db);
        });

        $this->app->singleton(OrderService::class, function ($app): OrderService {
            return new OrderService(
                $app->make(OrderRepository::class),
                $app->make(ProductRepository::class),
                $app->make(MembershipLifecycleService::class),
                $app->make(EntitlementCheckerInterface::class),
                $app->has(Database::class) ? $app->make(Database::class) : null
            );
        });

        $this->app->singleton(AdminOrderController::class, function ($app): AdminOrderController {
            return new AdminOrderController(
                $app,
                $app->make(OrderService::class)
            );
        });

        $this->app->singleton(CustomerOrderController::class, function ($app): CustomerOrderController {
            return new CustomerOrderController(
                $app,
                $app->make(OrderService::class)
            );
        });

        $this->app->singleton(Repositories\WalletRepository::class, function ($app): Repositories\WalletRepository {
            return new Repositories\WalletRepository($app->make(Database::class));
        });

        $this->app->singleton(Services\WalletService::class, function ($app): Services\WalletService {
            return new Services\WalletService(
                $app->make(Repositories\WalletRepository::class),
                $app->has(Database::class) ? $app->make(Database::class) : null
            );
        });

        $this->app->singleton(Services\CheckoutService::class, function ($app): Services\CheckoutService {
            $favPay = null;
            if ($app->has(\FavoriteCMS\Pay\Contracts\PaymentServiceInterface::class)) {
                $favPay = $app->make(\FavoriteCMS\Pay\Contracts\PaymentServiceInterface::class);
            }

            return new Services\CheckoutService(
                $app->make(Repositories\OrderRepository::class),
                $app->make(Services\WalletService::class),
                $favPay,
                $app->has(Database::class) ? $app->make(Database::class) : null
            );
        });

        $this->app->singleton(Controllers\CustomerCheckoutController::class, function ($app): Controllers\CustomerCheckoutController {
            return new Controllers\CustomerCheckoutController(
                $app,
                $app->make(Services\CheckoutService::class)
            );
        });
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        // Register Admin Menus
        if (function_exists('add_admin_menu')) {
            $productHandler = function (Request $request) {
                $controller = $this->app->make(AdminProductController::class);
                return $controller->handle($request);
            };

            $serviceHandler = function (Request $request) {
                $controller = $this->app->make(AdminServiceController::class);
                return $controller->handle($request);
            };

            $packageHandler = function (Request $request) {
                $controller = $this->app->make(AdminPackageController::class);
                return $controller->handle($request);
            };

            $membershipHandler = function (Request $request) {
                $controller = $this->app->make(AdminMembershipController::class);
                return $controller->handle($request);
            };

            $orderHandler = function (Request $request) {
                $controller = $this->app->make(AdminOrderController::class);
                return $controller->handle($request);
            };

            add_admin_menu(
                'favorite-digital',
                'Digital Store',
                '📦',
                $productHandler,
                'manage_options',
                56
            );

            if (function_exists('add_admin_submenu')) {
                add_admin_submenu(
                    'favorite-digital',
                    'favorite-digital-products',
                    'Digital Products',
                    $productHandler,
                    'manage_options'
                );

                add_admin_submenu(
                    'favorite-digital',
                    'favorite-digital-services',
                    'Services',
                    $serviceHandler,
                    'manage_options'
                );

                add_admin_submenu(
                    'favorite-digital',
                    'favorite-digital-packages',
                    'Packages',
                    $packageHandler,
                    'manage_options'
                );

                add_admin_submenu(
                    'favorite-digital',
                    'favorite-digital-memberships',
                    'Memberships',
                    $membershipHandler,
                    'manage_options'
                );

                add_admin_submenu(
                    'favorite-digital',
                    'favorite-digital-orders',
                    'Orders',
                    $orderHandler,
                    'manage_options'
                );
            }
        }

        // Register Customer Frontend Routes
        if (function_exists('add_route')) {
            add_route('GET', '/account/orders', function (Request $request) {
                $controller = $this->app->make(CustomerOrderController::class);
                return $controller->index($request);
            });

            add_route('GET', '/account/orders/{orderNumber}', function (Request $request, string $orderNumber) {
                $controller = $this->app->make(CustomerOrderController::class);
                return $controller->view($request, $orderNumber);
            });

            add_route('GET', '/checkout/{orderNumber}', function (Request $request, string $orderNumber) {
                $controller = $this->app->make(Controllers\CustomerCheckoutController::class);
                return $controller->handle($request, $orderNumber);
            });

            add_route('POST', '/checkout/{orderNumber}', function (Request $request, string $orderNumber) {
                $controller = $this->app->make(Controllers\CustomerCheckoutController::class);
                return $controller->handle($request, $orderNumber);
            });
        }

        // Lifecycle and Payment hooks
        if (function_exists('add_action')) {
            add_action('favorite.pay.payment.succeeded', function (array $data): void {
                if (($data['source_plugin'] ?? '') === 'favorite-digital') {
                    $txId = (string)($data['transaction_id'] ?? '');
                    $orderId = (int)($data['source_reference'] ?? 0);
                    if ($txId !== '' && $orderId > 0 && $this->app->has(Services\CheckoutService::class)) {
                        try {
                            $this->app->make(Services\CheckoutService::class)->verifyAndSettlePayment($orderId, $txId);
                        } catch (\Throwable) {
                        }
                    }
                }
            });

            add_action('favorite.pay.manual.approved', function (array $data): void {
                $attemptId = (string)($data['attempt_id'] ?? '');
                if ($attemptId !== '' && $this->app->has(Services\CheckoutService::class) && $this->app->has(\FavoriteCMS\Pay\Contracts\PaymentServiceInterface::class)) {
                    try {
                        $payService = $this->app->make(\FavoriteCMS\Pay\Contracts\PaymentServiceInterface::class);
                        if (method_exists($payService, 'getAttempt')) {
                            $attempt = $payService->getAttempt($attemptId);
                            if ($attempt) {
                                $intent = $payService->getIntent($attempt->getIntentId());
                                if ($intent && $intent->getSourcePlugin() === 'favorite-digital') {
                                    $orderId = (int)$intent->getSourceReference();
                                    $this->app->make(Services\CheckoutService::class)->verifyAndSettlePayment($orderId, $intent->getId());
                                }
                            }
                        }
                    } catch (\Throwable) {
                    }
                }
            });

            add_action('plugin.activated', function (string $pluginId): void {
                if ($pluginId === 'favorite-digital') {
                    $this->onActivate();
                }
            });

            add_action('plugin.deactivated', function (string $pluginId): void {
                if ($pluginId === 'favorite-digital') {
                    $this->onDeactivate();
                }
            });
        }

        $this->booted = true;
    }

    public function isFavoritePayAvailable(): bool
    {
        return class_exists(\FavoriteCMS\Pay\FavoritePayPlugin::class);
    }

    public function onActivate(): void
    {
        try {
            $this->runMigrations();
        } catch (\Throwable $e) {
            if (function_exists('cms_log')) {
                cms_log("Favorite Digital migration failed on activation: " . $e->getMessage(), 'error', ['plugin' => 'favorite-digital']);
            }
        }

        if (function_exists('cms_log')) {
            cms_log('Favorite Digital plugin activated successfully.', 'info', ['plugin' => 'favorite-digital']);
        }
    }

    public function onDeactivate(): void
    {
        if (function_exists('cms_log')) {
            cms_log('Favorite Digital plugin deactivated.', 'info', ['plugin' => 'favorite-digital']);
        }
    }

    public function runMigrations(): array
    {
        if (!$this->app->has(Database::class)) {
            return [];
        }

        $db = $this->app->make(Database::class);
        if (method_exists($db, 'registerPrefixableTables')) {
            $db->registerPrefixableTables(self::TABLES);
        }

        $migrator = new Migrator($db);
        $migrationsPath = __DIR__ . '/../database/migrations';
        return $migrator->migrate($migrationsPath);
    }
}
