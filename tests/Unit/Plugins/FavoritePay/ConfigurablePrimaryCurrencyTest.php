<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use CreateFavoritePayTables;
use CreateSettingsTable;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Currency;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\WalletService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ConfigurablePrimaryCurrencyTest extends TestCase
{
    private Database $db;
    private PDO $pdo;
    private Application $app;
    private CurrencyService $currencyService;
    private GatewayRegistry $registry;
    private PaymentService $paymentService;
    private WalletService $walletService;
    private FavoritePayPlugin $plugin;

    protected function setUp(): void
    {
        $_SESSION = [];
        Setting::clearCache();

        // 1. In-memory SQLite for complete relational isolation
        $this->pdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);

        $this->db = new class($this->pdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite'];
                $this->prefix = '';
            }
            public function getConnection(): PDO
            {
                return $this->pdo;
            }
        };

        // Run Core settings migration
        require_once APP_ROOT . '/database/migrations/009_create_settings_table.php';
        $coreMigration = new CreateSettingsTable($this->db);
        $coreMigration->up();

        // Run Favorite Pay tables migration
        require_once APP_ROOT . '/plugins/favorite-pay/database/migrations/001_create_favorite_pay_tables.php';
        $payMigration = new CreateFavoritePayTables($this->db);
        $payMigration->up();

        // 2. Setup Application container & services
        $this->app = Application::getInstance();
        $this->app->instance(Database::class, $this->db);

        $this->currencyService = new CurrencyService();
        $this->registry = new GatewayRegistry();

        $gateway = new ManualBangladeshGateway(
            'manual_bkash',
            'bKash Manual Payment',
            PaymentMethodType::MANUAL_BKASH,
            [
                'channel'        => 'bkash',
                'account_number' => '01700000000',
            ]
        );
        $this->registry->register($gateway);

        $this->paymentService = new PaymentService(
            $this->currencyService,
            $this->registry,
            $this->db
        );

        $this->walletService = new WalletService(
            $this->currencyService,
            $this->paymentService,
            $this->db
        );

        $this->app->instance(PaymentService::class, $this->paymentService);
        $this->app->instance(WalletService::class, $this->walletService);
        $this->app->instance(\FavoriteCMS\Pay\Contracts\WalletServiceInterface::class, $this->walletService);
        $this->app->instance(\FavoriteCMS\Pay\Contracts\PaymentServiceInterface::class, $this->paymentService);

        $this->plugin = new FavoritePayPlugin($this->app);
        $this->plugin->boot();
    }

    protected function tearDown(): void
    {
        try {
            Currency::setPrimaryCurrency('BDT');
        } catch (\Throwable) {
        }
        Setting::clearCache();
    }

    public function testWalletServiceReadsCorePrimaryCurrency(): void
    {
        // Default is BDT
        $this->assertSame('BDT', $this->walletService->getPrimaryCurrency());
        $this->assertSame('BDT', $this->currencyService->getBaseCurrency());

        // Change site setting to USD
        Currency::setPrimaryCurrency('USD');
        $this->assertSame('USD', $this->walletService->getPrimaryCurrency());
        $this->assertSame('USD', $this->currencyService->getBaseCurrency());

        // Change site setting to INR
        Currency::setPrimaryCurrency('INR');
        $this->assertSame('INR', $this->walletService->getPrimaryCurrency());
        $this->assertSame('INR', $this->currencyService->getBaseCurrency());
    }

    public function testNewWalletUsesConfiguredPrimaryCurrencyOnBdtSite(): void
    {
        Currency::setPrimaryCurrency('BDT');
        $userId = 1001;

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_bdt',
            Money::bdt(35000), // 350.00 BDT
            ['customer_id' => $userId]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        $entry = $this->walletService->settleSuccessfulPayment($intent->getId());
        $this->assertSame('BDT', $entry->getAmount()->getCurrency());
        $this->assertSame(35000, $entry->getAmount()->getAmount());

        $balance = $this->walletService->getBalance($userId);
        $this->assertSame('BDT', $balance->getCurrency());
        $this->assertSame(35000, $balance->getAmount());

        $walletRow = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
        $this->assertSame('BDT', $walletRow->currency);
        $this->assertSame(35000, (int)$walletRow->balance);
    }

    public function testNewWalletUsesConfiguredPrimaryCurrencyOnUsdSite(): void
    {
        Currency::setPrimaryCurrency('USD');
        $userId = 1002;

        $usdAmount = Money::usd(5000); // $50.00 USD = 5,000 cents

        $intent = $this->paymentService->createIntent(
            'favorite_digital',
            'sub_usd',
            $usdAmount,
            ['customer_id' => $userId]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        $entry = $this->walletService->settleSuccessfulPayment($intent->getId());
        $this->assertSame('USD', $entry->getAmount()->getCurrency());
        $this->assertSame(5000, $entry->getAmount()->getAmount());

        $balance = $this->walletService->getBalance($userId);
        $this->assertSame('USD', $balance->getCurrency());
        $this->assertSame(5000, $balance->getAmount());

        $walletRow = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
        $this->assertSame('USD', $walletRow->currency);
        $this->assertSame(5000, (int)$walletRow->balance);
    }

    public function testNewWalletUsesConfiguredPrimaryCurrencyOnInrSite(): void
    {
        Currency::setPrimaryCurrency('INR');
        $userId = 1003;

        $inrAmount = Money::inr(125000); // ₹1,250.00 INR = 125,000 paise

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_inr',
            $inrAmount,
            ['customer_id' => $userId]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        $entry = $this->walletService->settleSuccessfulPayment($intent->getId());
        $this->assertSame('INR', $entry->getAmount()->getCurrency());
        $this->assertSame(125000, $entry->getAmount()->getAmount());

        $balance = $this->walletService->getBalance($userId);
        $this->assertSame('INR', $balance->getCurrency());
        $this->assertSame(125000, $balance->getAmount());

        $walletRow = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
        $this->assertSame('INR', $walletRow->currency);
        $this->assertSame(125000, (int)$walletRow->balance);
    }

    public function testForeignPaymentCurrencyDoesNotReplacePrimaryAccountingCurrency(): void
    {
        // Site Primary Currency is BDT
        Currency::setPrimaryCurrency('BDT');
        $this->currencyService->setOperatorRate('BDT', '0.01', 1, 'USD');

        $userId = 1004;
        $baseBdt = Money::bdt(20000); // 200.00 BDT

        // Charge in foreign USD
        $intent = $this->paymentService->createIntent(
            'favorite_digital',
            'foreign_dl',
            $baseBdt,
            [
                'customer_id'     => $userId,
                'charge_currency' => 'USD',
            ]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        $entry = $this->walletService->settleSuccessfulPayment($intent->getId());

        // Credited wallet amount must strictly be the Primary Accounting Currency (BDT), not USD
        $this->assertSame('BDT', $entry->getAmount()->getCurrency());
        $this->assertSame(20000, $entry->getAmount()->getAmount());

        $balance = $this->walletService->getBalance($userId);
        $this->assertSame('BDT', $balance->getCurrency());
        $this->assertSame(20000, $balance->getAmount());
    }

    public function testHistoricalSafetyChangingPrimaryCurrencyDoesNotMutateExistingWalletOrTransactions(): void
    {
        // 1. Initially site is BDT
        Currency::setPrimaryCurrency('BDT');
        $userId = 2001;

        $intent1 = $this->paymentService->createIntent(
            'favorite_shop',
            'hist_order_1',
            Money::bdt(80000), // 800.00 BDT
            ['customer_id' => $userId]
        );
        $this->paymentService->updateIntentStatus($intent1->getId(), PaymentStatus::SUCCEEDED);
        $this->walletService->settleSuccessfulPayment($intent1->getId());

        // Verify initial state
        $walletBefore = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
        $this->assertSame('BDT', $walletBefore->currency);
        $this->assertSame(80000, (int)$walletBefore->balance);

        $txBefore = $this->db->selectOne("SELECT * FROM favorite_pay_transactions WHERE transaction_id = ?", [$intent1->getId()]);
        $this->assertSame('BDT', $txBefore->base_currency);
        $this->assertSame(80000, (int)$txBefore->base_amount);

        $ledgerBefore = $this->db->selectOne("SELECT * FROM favorite_pay_wallet_entries WHERE reference_id = ?", [$intent1->getId()]);
        $this->assertSame(80000, (int)$ledgerBefore->amount);
        $this->assertSame(80000, (int)$ledgerBefore->balance_after);

        // 2. Administrator changes site Primary Currency to USD
        Currency::setPrimaryCurrency('USD');
        $this->assertSame('USD', Currency::getPrimaryCurrency());

        // 3. Verify that existing wallet, balance, transaction, and ledger entry are UNCHANGED
        $walletAfter = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
        $this->assertSame('BDT', $walletAfter->currency, "Existing wallet currency must remain BDT.");
        $this->assertSame(80000, (int)$walletAfter->balance, "Existing wallet balance must remain 80000 Poisha.");

        $balanceAfter = $this->walletService->getBalance($userId);
        $this->assertSame('BDT', $balanceAfter->getCurrency());
        $this->assertSame(80000, $balanceAfter->getAmount());

        $txAfter = $this->db->selectOne("SELECT * FROM favorite_pay_transactions WHERE transaction_id = ?", [$intent1->getId()]);
        $this->assertSame('BDT', $txAfter->base_currency, "Historical transaction base_currency must remain BDT.");
        $this->assertSame(80000, (int)$txAfter->base_amount, "Historical transaction base_amount must remain 80000.");

        $ledgerAfter = $this->db->selectOne("SELECT * FROM favorite_pay_wallet_entries WHERE reference_id = ?", [$intent1->getId()]);
        $this->assertSame(80000, (int)$ledgerAfter->amount);
        $this->assertSame(80000, (int)$ledgerAfter->balance_after);

        // 4. Financial safety guard: Trying to settle a new USD payment to the existing BDT wallet
        // must fail with an explicit exception rather than corrupting the wallet balance
        $intent2 = $this->paymentService->createIntent(
            'favorite_shop',
            'new_usd_order',
            Money::usd(5000),
            ['customer_id' => $userId]
        );
        $this->paymentService->updateIntentStatus($intent2->getId(), PaymentStatus::SUCCEEDED);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("wallet currency 'BDT' does not match transaction base currency 'USD'");
        $this->walletService->settleSuccessfulPayment($intent2->getId());
    }
}
