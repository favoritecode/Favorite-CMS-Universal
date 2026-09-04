<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Contracts\RefundServiceInterface;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\RefundService;
use FavoriteCMS\Pay\Services\WalletService;

final class FavoritePayPlugin
{
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
        // Bind Gateway Registry with default Manual Bangladesh Gateway drivers
        $this->app->singleton(GatewayRegistry::class, function () {
            $registry = new GatewayRegistry();

            // Default Manual Bangladesh Gateways
            $manualBd = new ManualBangladeshGateway(
                'manual_bd',
                'Manual Bangladesh Payment',
                PaymentMethodType::MANUAL_BD,
                [
                    'channel'      => 'manual_bd',
                    'instructions' => 'Please transfer to our merchant account and submit your TrxID below.',
                ]
            );

            $manualBkash = new ManualBangladeshGateway(
                'manual_bkash',
                'bKash Manual Payment',
                PaymentMethodType::MANUAL_BKASH,
                [
                    'channel'        => 'bkash',
                    'account_name'   => 'Favorite CMS Merchant',
                    'account_number' => '01700000000',
                    'account_type'   => 'Personal / Merchant',
                    'instructions'   => 'Send money via bKash and submit your 10-digit TrxID.',
                ]
            );

            $manualNagad = new ManualBangladeshGateway(
                'manual_nagad',
                'Nagad Manual Payment',
                PaymentMethodType::MANUAL_NAGAD,
                [
                    'channel'        => 'nagad',
                    'account_name'   => 'Favorite CMS Merchant',
                    'account_number' => '01800000000',
                    'account_type'   => 'Personal / Merchant',
                    'instructions'   => 'Send money via Nagad and submit your TrxID.',
                ]
            );

            $manualBank = new ManualBangladeshGateway(
                'manual_bank',
                'Bank Transfer',
                PaymentMethodType::MANUAL_BANK,
                [
                    'channel'        => 'bank',
                    'bank_name'      => 'City Bank PLC',
                    'account_name'   => 'Favorite CMS',
                    'account_number' => '1100000000000',
                    'branch_name'    => 'Principal Branch, Dhaka',
                    'routing_no'     => '225260000',
                    'instructions'   => 'Transfer the exact amount and submit your bank deposit/EFT reference number.',
                ]
            );

            $registry->register($manualBd);
            $registry->register($manualBkash);
            $registry->register($manualNagad);
            $registry->register($manualBank);

            // Backward compatibility aliases for Phase 1 tests
            $registry->registerAlias('bkash_manual', 'manual_bkash');
            $registry->registerAlias('nagad_manual', 'manual_nagad');

            return $registry;
        });

        // Bind Currency Service
        $this->app->singleton(CurrencyServiceInterface::class, function () {
            return new CurrencyService();
        });

        // Bind Wallet Service
        $this->app->singleton(WalletServiceInterface::class, function ($app) {
            return new WalletService($app->make(CurrencyServiceInterface::class));
        });

        // Bind Payment Service
        $this->app->singleton(PaymentServiceInterface::class, function ($app) {
            $db = $app->has(Database::class) ? $app->make(Database::class) : null;
            return new PaymentService(
                $app->make(CurrencyServiceInterface::class),
                $app->make(GatewayRegistry::class),
                $db
            );
        });

        // Bind Refund Service
        $this->app->singleton(RefundServiceInterface::class, function ($app) {
            return new RefundService($app->make(PaymentServiceInterface::class));
        });

        // Bind Payment Attempt Repository
        $this->app->singleton(\FavoriteCMS\Pay\Repositories\PaymentAttemptRepository::class, function ($app) {
            $db = $app->has(Database::class) ? $app->make(Database::class) : null;
            return new \FavoriteCMS\Pay\Repositories\PaymentAttemptRepository(
                $app->make(PaymentServiceInterface::class),
                $db
            );
        });

        // Bind Payment Admin Controller
        $this->app->singleton(\FavoriteCMS\Pay\Controllers\PaymentAdminController::class, function ($app) {
            return new \FavoriteCMS\Pay\Controllers\PaymentAdminController(
                $app,
                $app->make(PaymentServiceInterface::class),
                $app->make(\FavoriteCMS\Pay\Repositories\PaymentAttemptRepository::class)
            );
        });
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        // Register Admin Menu
        if (function_exists('add_admin_menu')) {
            $handler = function (\FavoriteCMS\Core\Request $request) {
                $controller = $this->app->make(\FavoriteCMS\Pay\Controllers\PaymentAdminController::class);
                return $controller->handle($request);
            };

            add_admin_menu(
                'favorite-pay',
                'Favorite Pay',
                '💳',
                $handler,
                \FavoriteCMS\Pay\Permissions\PaymentPermission::VIEW,
                55
            );

            if (function_exists('add_admin_submenu')) {
                add_admin_submenu(
                    'favorite-pay',
                    'favorite-pay-payments',
                    'Payments',
                    $handler,
                    \FavoriteCMS\Pay\Permissions\PaymentPermission::VIEW
                );
            }
        }

        // Hook lifecycle actions
        if (function_exists('add_action')) {
            add_action('plugin.activated', function (string $pluginId): void {
                if ($pluginId === 'favorite-pay') {
                    $this->onActivate();
                }
            });

            add_action('plugin.deactivated', function (string $pluginId): void {
                if ($pluginId === 'favorite-pay') {
                    $this->onDeactivate();
                }
            });
        }

        $this->booted = true;
    }

    public function onActivate(): void
    {
        try {
            $this->runMigrations();
        } catch (\Throwable $e) {
            if (function_exists('cms_log')) {
                cms_log("Favorite Pay migration failed on activation: " . $e->getMessage(), 'error', ['plugin' => 'favorite-pay']);
            }
        }

        // Seed default permissions
        if ($this->app->has(Database::class)) {
            \FavoriteCMS\Pay\Permissions\PaymentPermission::registerDefaultPermissions($this->app->make(Database::class));
        }

        if (function_exists('cms_log')) {
            cms_log('Favorite Pay plugin activated successfully.', 'info', ['plugin' => 'favorite-pay']);
        }
    }

    public function onDeactivate(): void
    {
        if (function_exists('cms_log')) {
            cms_log('Favorite Pay plugin deactivated.', 'info', ['plugin' => 'favorite-pay']);
        }
    }

    public function runMigrations(): array
    {
        if (!$this->app->has(Database::class)) {
            return [];
        }

        $db = $this->app->make(Database::class);
        $migrator = new Migrator($db);
        $migrationsPath = __DIR__ . '/../database/migrations';
        return $migrator->migrate($migrationsPath);
    }
}
