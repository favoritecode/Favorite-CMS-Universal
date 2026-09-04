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
        // Bind Gateway Registry
        $this->app->singleton(GatewayRegistry::class, function () {
            return new GatewayRegistry();
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
            return new PaymentService(
                $app->make(CurrencyServiceInterface::class),
                $app->make(GatewayRegistry::class)
            );
        });

        // Bind Refund Service
        $this->app->singleton(RefundServiceInterface::class, function ($app) {
            return new RefundService($app->make(PaymentServiceInterface::class));
        });
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
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
