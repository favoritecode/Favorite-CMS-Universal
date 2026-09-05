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
    public const TABLES = [
        'favorite_pay_gateways',
        'favorite_pay_rates',
        'favorite_pay_transactions',
        'favorite_pay_attempts',
        'favorite_pay_refunds',
        'favorite_pay_wallets',
        'favorite_pay_wallet_entries',
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
        if (class_exists(\FavoriteCMS\Core\Hook::class)) {
            \FavoriteCMS\Core\Hook::removeFilter('currency.can_change_primary');
            \FavoriteCMS\Core\Hook::removeFilter('currency.is_primary_locked');
        }
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
        // Register prefixable tables with Database
        if ($this->app->has(Database::class)) {
            $db = $this->app->make(Database::class);
            if (method_exists($db, 'registerPrefixableTables')) {
                $db->registerPrefixableTables(self::TABLES);
            }
        }

        // Bind Gateway Registry with default Manual Bangladesh Gateway drivers
        $this->app->singleton(GatewayRegistry::class, function ($app) {
            $registry = new GatewayRegistry();

            // Default Manual Bangladesh Gateways
            $manualBd = new ManualBangladeshGateway(
                'manual_bd',
                'Manual Bangladesh Payment',
                PaymentMethodType::MANUAL_BD,
                $this->loadGatewayConfig('favorite_pay_manual_bd', [
                    'channel'      => 'manual_bd',
                    'instructions' => 'Please transfer to our merchant account and submit your TrxID below.',
                ]),
                $this->loadGatewayEnabled('favorite_pay_manual_bd', true)
            );

            $manualBkash = new ManualBangladeshGateway(
                'manual_bkash',
                'bKash Manual Payment',
                PaymentMethodType::MANUAL_BKASH,
                $this->loadGatewayConfig('favorite_pay_manual_bkash', [
                    'channel'        => 'bkash',
                    'account_name'   => '',
                    'account_number' => '',
                    'account_type'   => 'Personal / Merchant',
                    'instructions'   => 'Send money via bKash and submit your 10-digit TrxID.',
                ]),
                $this->loadGatewayEnabled('favorite_pay_manual_bkash', true)
            );

            $manualNagad = new ManualBangladeshGateway(
                'manual_nagad',
                'Nagad Manual Payment',
                PaymentMethodType::MANUAL_NAGAD,
                $this->loadGatewayConfig('favorite_pay_manual_nagad', [
                    'channel'        => 'nagad',
                    'account_name'   => '',
                    'account_number' => '',
                    'account_type'   => 'Personal / Merchant',
                    'instructions'   => 'Send money via Nagad and submit your TrxID.',
                ]),
                $this->loadGatewayEnabled('favorite_pay_manual_nagad', true)
            );

            $manualRocket = new ManualBangladeshGateway(
                'manual_rocket',
                'Rocket Manual Payment',
                PaymentMethodType::MANUAL_ROCKET,
                $this->loadGatewayConfig('favorite_pay_manual_rocket', [
                    'channel'        => 'rocket',
                    'account_name'   => '',
                    'account_number' => '',
                    'account_type'   => 'Personal / Merchant',
                    'instructions'   => 'Send money via Rocket and submit your transaction reference.',
                ]),
                $this->loadGatewayEnabled('favorite_pay_manual_rocket', true)
            );

            $manualBank = new ManualBangladeshGateway(
                'manual_bank',
                'Bank Transfer',
                PaymentMethodType::MANUAL_BANK,
                $this->loadGatewayConfig('favorite_pay_manual_bank', [
                    'channel'        => 'bank',
                    'bank_name'      => '',
                    'account_name'   => '',
                    'account_number' => '',
                    'branch_name'    => '',
                    'routing_no'     => '',
                    'instructions'   => 'Transfer the exact amount and submit your bank deposit/EFT reference number.',
                ]),
                $this->loadGatewayEnabled('favorite_pay_manual_bank', true)
            );

            $registry->register($manualBd);
            $registry->register($manualBkash);
            $registry->register($manualNagad);
            $registry->register($manualRocket);
            $registry->register($manualBank);

            $db = $app->has(Database::class) ? $app->make(Database::class) : null;
            $currencyService = $app->has(CurrencyServiceInterface::class) ? $app->make(CurrencyServiceInterface::class) : null;

            // Automatic Gateways
            $bkashDirect = new \FavoriteCMS\Pay\Gateways\Bkash\BkashMerchantGateway([], null, $db);
            $registry->register($bkashDirect, ['bkash_auto', 'bkash_merchant']);

            $binancePay = new \FavoriteCMS\Pay\Gateways\Binance\BinancePayGateway([], null, $db, $currencyService);
            $registry->register($binancePay, ['binance']);

            // Backward compatibility aliases
            $registry->registerAlias('bkash_manual', 'manual_bkash');
            $registry->registerAlias('nagad_manual', 'manual_nagad');
            $registry->registerAlias('rocket_manual', 'manual_rocket');
            $registry->registerAlias('rocket', 'manual_rocket');
            $registry->registerAlias('bank_manual', 'manual_bank');

            return $registry;
        });

        // Bind Currency Service with Database Rate Provider support
        $this->app->singleton(CurrencyServiceInterface::class, function ($app) {
            $db = $app->has(\FavoriteCMS\Core\Database::class) ? $app->make(\FavoriteCMS\Core\Database::class) : null;
            return new CurrencyService(null, $db);
        });

        // Bind Wallet Service
        $this->app->singleton(WalletServiceInterface::class, function ($app) {
            $db = $app->has(Database::class) ? $app->make(Database::class) : null;
            $paymentService = $app->has(PaymentServiceInterface::class) ? $app->make(PaymentServiceInterface::class) : null;
            return new WalletService(
                $app->make(CurrencyServiceInterface::class),
                $paymentService,
                $db
            );
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
            $db = $app->has(Database::class) ? $app->make(Database::class) : null;
            return new RefundService(
                $app->make(PaymentServiceInterface::class),
                $app->make(GatewayRegistry::class),
                $db
            );
        });

        // Bind Webhook Service
        $this->app->singleton(\FavoriteCMS\Pay\Contracts\WebhookServiceInterface::class, function ($app) {
            return new \FavoriteCMS\Pay\Services\WebhookService(
                $app->make(GatewayRegistry::class),
                $app->make(PaymentServiceInterface::class)
            );
        });
        $this->app->singleton(\FavoriteCMS\Pay\Services\WebhookService::class, function ($app) {
            return $app->make(\FavoriteCMS\Pay\Contracts\WebhookServiceInterface::class);
        });

        // Bind Payment Webhook Controller
        $this->app->singleton(\FavoriteCMS\Pay\Controllers\PaymentWebhookController::class, function ($app) {
            return new \FavoriteCMS\Pay\Controllers\PaymentWebhookController(
                $app,
                $app->make(\FavoriteCMS\Pay\Contracts\WebhookServiceInterface::class)
            );
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

        // Bind Payment Gateway Settings Controller
        $this->app->singleton(\FavoriteCMS\Pay\Controllers\PaymentGatewaySettingsController::class, function ($app) {
            return new \FavoriteCMS\Pay\Controllers\PaymentGatewaySettingsController(
                $app,
                $app->make(GatewayRegistry::class)
            );
        });

        // Bind Payment Rate Controller
        $this->app->singleton(\FavoriteCMS\Pay\Controllers\PaymentRateController::class, function ($app) {
            return new \FavoriteCMS\Pay\Controllers\PaymentRateController(
                $app,
                $app->make(CurrencyServiceInterface::class)
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

                add_admin_submenu(
                    'favorite-pay',
                    'favorite-pay-manual',
                    'Manual Payments',
                    function (\FavoriteCMS\Core\Request $request) {
                        $controller = $this->app->make(\FavoriteCMS\Pay\Controllers\PaymentGatewaySettingsController::class);
                        return $controller->handleManual($request);
                    },
                    'manage_settings'
                );

                add_admin_submenu(
                    'favorite-pay',
                    'favorite-pay-gateways',
                    'Automatic Gateways',
                    function (\FavoriteCMS\Core\Request $request) {
                        $controller = $this->app->make(\FavoriteCMS\Pay\Controllers\PaymentGatewaySettingsController::class);
                        return $controller->handleAutomatic($request);
                    },
                    'manage_settings'
                );

                add_admin_submenu(
                    'favorite-pay',
                    'favorite-pay-rates',
                    'Exchange Rates',
                    function (\FavoriteCMS\Core\Request $request) {
                        $controller = $this->app->make(\FavoriteCMS\Pay\Controllers\PaymentRateController::class);
                        return $controller->handle($request);
                    },
                    'manage_settings'
                );
            }
        }

        // Register Webhook Endpoint Route
        if (function_exists('add_route')) {
            add_route(['POST'], '/api/favorite-pay/webhook/{gateway}', function (\FavoriteCMS\Core\Request $request, string $gateway) {
                $controller = $this->app->make(\FavoriteCMS\Pay\Controllers\PaymentWebhookController::class);
                return $controller->handle($request, $gateway);
            });
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

            // Hook payment succeeded event for wallet settlement
            add_action('favorite.pay.payment.succeeded', function ($data): void {
                $transactionId = is_array($data) ? ($data['transaction_id'] ?? null) : (string) $data;
                if (!$transactionId) {
                    return;
                }
                try {
                    $walletService = $this->app->make(WalletServiceInterface::class);
                    $walletService->settleSuccessfulPayment($transactionId);
                } catch (\Throwable $e) {
                    if (function_exists('cms_log')) {
                        cms_log("Wallet auto-settlement failed for transaction {$transactionId}: " . $e->getMessage(), 'error', [
                            'plugin' => 'favorite-pay',
                            'transaction_id' => $transactionId,
                            'exception' => $e,
                        ]);
                    }
                }
            });
        }

        // Filter hook: Block Primary Currency change if financial activity exists
        if (function_exists('add_filter')) {
            add_filter('currency.can_change_primary', function ($allowed, string $newCurrency, string $oldCurrency) {
                if ($newCurrency === $oldCurrency) {
                    return true;
                }
                if ($this->hasFinancialActivity()) {
                    return [
                        'allowed' => false,
                        'reason'  => 'Primary Currency cannot be changed after financial activity has started. Existing wallets, transactions, and ledger records use the current accounting currency.',
                    ];
                }
                return $allowed;
            }, 10, 3);

            add_filter('currency.is_primary_locked', function ($locked) {
                if ($locked) {
                    return true;
                }
                if ($this->hasFinancialActivity()) {
                    return [
                        'locked' => true,
                        'reason' => 'Primary Currency cannot be changed after financial activity has started. Existing wallets, transactions, and ledger records use the current accounting currency.',
                    ];
                }
                return false;
            }, 10, 1);
        }

        // Ensure default permissions and role mappings are registered if database is ready
        if ($this->app->has(Database::class)) {
            \FavoriteCMS\Pay\Permissions\PaymentPermission::registerDefaultPermissions($this->app->make(Database::class));
        }

        $this->booted = true;
    }

    /**
     * Check if any financial records or transactions exist within Favorite Pay.
     * Checks database tables (transactions, attempts, refunds, wallets, wallet entries)
     * and in-memory services.
     * Note: Gateway configuration alone does NOT count as financial activity.
     */
    public function hasFinancialActivity(): bool
    {
        // 1. Check database tables if database is available
        if ($this->app->has(Database::class)) {
            $db = $this->app->make(Database::class);
            if (method_exists($db, 'registerPrefixableTables')) {
                $db->registerPrefixableTables(self::TABLES);
            }
            $tables = [
                'favorite_pay_transactions',
                'favorite_pay_attempts',
                'favorite_pay_refunds',
                'favorite_pay_wallets',
                'favorite_pay_wallet_entries',
            ];

            foreach ($tables as $table) {
                if ($db->tableExists($table)) {
                    $row = $db->selectOne("SELECT 1 FROM {$table} LIMIT 1");
                    if ($row !== null) {
                        return true;
                    }
                }
            }
        }

        // 2. Check in-memory services if registered
        if ($this->app->has(PaymentServiceInterface::class)) {
            $paymentService = $this->app->make(PaymentServiceInterface::class);
            if (method_exists($paymentService, 'hasFinancialActivity') && $paymentService->hasFinancialActivity()) {
                return true;
            }
        }

        if ($this->app->has(WalletServiceInterface::class)) {
            $walletService = $this->app->make(WalletServiceInterface::class);
            if (method_exists($walletService, 'hasActivity') && $walletService->hasActivity()) {
                return true;
            }
        }

        if ($this->app->has(RefundServiceInterface::class)) {
            $refundService = $this->app->make(RefundServiceInterface::class);
            if (method_exists($refundService, 'hasRefunds') && $refundService->hasRefunds()) {
                return true;
            }
        }

        return false;
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
        if (method_exists($db, 'registerPrefixableTables')) {
            $db->registerPrefixableTables(self::TABLES);
        }
        $migrator = new Migrator($db);
        $migrationsPath = __DIR__ . '/../database/migrations';
        return $migrator->migrate($migrationsPath);
    }

    private function loadGatewayConfig(string $group, array $default = []): array
    {
        if (class_exists(\FavoriteCMS\Models\Setting::class)) {
            try {
                $saved = \FavoriteCMS\Models\Setting::getGroup($group);
                if (!empty($saved)) {
                    return array_merge($default, $saved);
                }
            } catch (\Throwable) {
                // Ignore DB error during boot
            }
        }
        return $default;
    }

    private function loadGatewayEnabled(string $group, bool $default = true): bool
    {
        if (class_exists(\FavoriteCMS\Models\Setting::class)) {
            try {
                $saved = \FavoriteCMS\Models\Setting::getGroup($group);
                if (isset($saved['enabled'])) {
                    return !empty($saved['enabled']);
                }
            } catch (\Throwable) {
                // Ignore DB error during boot
            }
        }
        return $default;
    }
}
