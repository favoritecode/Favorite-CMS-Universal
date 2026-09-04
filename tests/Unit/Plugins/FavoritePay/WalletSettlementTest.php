<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use CreateFavoritePayTables;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
use FavoriteCMS\Pay\Repositories\PaymentAttemptRepository;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\WalletService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WalletSettlementTest extends TestCase
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
        };

        // Run migrations
        require_once APP_ROOT . '/plugins/favorite-pay/database/migrations/001_create_favorite_pay_tables.php';
        $migration = new CreateFavoritePayTables($this->db);
        $migration->up();

        // 2. Setup Application container & services
        $this->app = Application::getInstance();
        $this->app->instance(Database::class, $this->db);

        $this->currencyService = new CurrencyService();
        // Register BDT_USD rate for foreign currency testing (1 BDT = 0.01 USD)
        $this->currencyService->setOperatorRate('BDT', '0.01', 1, 'USD');

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

        // Bind into container
        $this->app->instance(PaymentService::class, $this->paymentService);
        $this->app->instance(WalletService::class, $this->walletService);
        $this->app->instance(\FavoriteCMS\Pay\Contracts\WalletServiceInterface::class, $this->walletService);
        $this->app->instance(\FavoriteCMS\Pay\Contracts\PaymentServiceInterface::class, $this->paymentService);

        // Initialize plugin for hook testing
        $this->plugin = new FavoritePayPlugin($this->app);
        $this->plugin->boot();
    }

    public function testBasicSettlementCreditsWalletExactlyOnce(): void
    {
        $userId = 101;
        $baseAmount = Money::bdt(50000); // 500.00 BDT = 50,000 Poisha

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_1001',
            $baseAmount,
            [
                'customer_id' => $userId,
                'gateway'     => 'manual_bkash',
            ]
        );

        // Advance to SUCCEEDED
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        // Perform settlement
        $entry = $this->walletService->settleSuccessfulPayment($intent->getId());

        $this->assertNotNull($entry);
        $this->assertSame($userId, $entry->getUserId());
        $this->assertSame('credit', $entry->getType());
        $this->assertSame(50000, $entry->getAmount()->getAmount());
        $this->assertSame('BDT', $entry->getAmount()->getCurrency());
        $this->assertSame(50000, $entry->getBalanceAfter()->getAmount());
        $this->assertSame('payment', $entry->getReferenceType());
        $this->assertSame($intent->getId(), $entry->getReferenceId());

        // Check wallet balance in database
        $balance = $this->walletService->getBalance($userId);
        $this->assertSame(50000, $balance->getAmount());
        $this->assertSame('BDT', $balance->getCurrency());

        // Verify database table
        $dbEntry = $this->db->selectOne(
            "SELECT * FROM favorite_pay_wallet_entries WHERE reference_id = ?",
            [$intent->getId()]
        );
        $this->assertNotNull($dbEntry);
        $this->assertSame('settle:payment:' . $intent->getId(), $dbEntry->idempotency_key);
        $this->assertSame(50000, (int)$dbEntry->amount);
        $this->assertSame(50000, (int)$dbEntry->balance_after);
    }

    public function testCurrencyConversionCreditsAuthoritativeBdtBaseAmount(): void
    {
        $userId = 102;
        // Base BDT amount is 1175.00 BDT (117,500 Poisha).
        // Charge currency is USD ($10.00 USD).
        $baseAmount = Money::bdt(117500);

        $intent = $this->paymentService->createIntent(
            'favorite_digital',
            'sub_999',
            $baseAmount,
            [
                'customer_id'     => $userId,
                'charge_currency' => 'USD',
            ]
        );

        $this->assertSame('BDT', $intent->getBaseAmount()->getCurrency());
        $this->assertSame(117500, $intent->getBaseAmount()->getAmount());
        $this->assertSame('USD', $intent->getChargeAmount()->getCurrency());

        // Advance to SUCCEEDED
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        $entry = $this->walletService->settleSuccessfulPayment($intent->getId());

        // Credited amount must be strictly the BDT base accounting amount (117,500 Poisha)
        $this->assertSame(117500, $entry->getAmount()->getAmount());
        $this->assertSame('BDT', $entry->getAmount()->getCurrency());

        $balance = $this->walletService->getBalance($userId);
        $this->assertSame(117500, $balance->getAmount());
        $this->assertSame('BDT', $balance->getCurrency());
    }

    public function testZeroFloatsExactIntegerPoishaArithmetic(): void
    {
        $userId = 103;

        // Payment 1: 137 Poisha
        $tx1 = $this->paymentService->createIntent('test', 'tx1', Money::bdt(137), ['customer_id' => $userId]);
        $this->paymentService->updateIntentStatus($tx1->getId(), PaymentStatus::SUCCEEDED);
        $this->walletService->settleSuccessfulPayment($tx1->getId());

        // Payment 2: 999 Poisha
        $tx2 = $this->paymentService->createIntent('test', 'tx2', Money::bdt(999), ['customer_id' => $userId]);
        $this->paymentService->updateIntentStatus($tx2->getId(), PaymentStatus::SUCCEEDED);
        $this->walletService->settleSuccessfulPayment($tx2->getId());

        $balance = $this->walletService->getBalance($userId);
        $this->assertSame(1136, $balance->getAmount()); // 137 + 999 = 1136 Poisha
        $this->assertIsInt($balance->getAmount());
    }

    public function testRejectsNonExistentTransaction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Payment transaction not found");
        $this->walletService->settleSuccessfulPayment('non_existent_tx_id');
    }

    public function testRejectsEmptyTransactionId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Transaction ID cannot be empty");
        $this->walletService->settleSuccessfulPayment('   ');
    }

    public function testRejectsGuestTransactionWithoutUserId(): void
    {
        // Guest checkout has no user ID
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'guest_order_1',
            Money::bdt(2000),
            ['customer_id' => null]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("has no associated customer user ID");
        $this->walletService->settleSuccessfulPayment($intent->getId());
    }

    public function testRejectsPendingOrAwaitingVerificationTransaction(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_pending',
            Money::bdt(3000),
            ['customer_id' => 104]
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("must be succeeded");
        $this->walletService->settleSuccessfulPayment($intent->getId());
    }

    public function testRejectsFailedTransaction(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_failed',
            Money::bdt(3000),
            ['customer_id' => 105]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::FAILED);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("must be succeeded");
        $this->walletService->settleSuccessfulPayment($intent->getId());
    }

    public function testIdempotencyRepeatedSettlementReturnsSameEntryWithoutDoubleCrediting(): void
    {
        $userId = 106;
        $intent = $this->paymentService->createIntent(
            'favorite_digital',
            'item_88',
            Money::bdt(25000), // 250.00 BDT
            ['customer_id' => $userId]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        // First settlement call
        $firstEntry = $this->walletService->settleSuccessfulPayment($intent->getId());
        $this->assertSame(25000, $firstEntry->getAmount()->getAmount());
        $this->assertSame(25000, $this->walletService->getBalance($userId)->getAmount());

        // Second settlement call
        $secondEntry = $this->walletService->settleSuccessfulPayment($intent->getId());
        $this->assertSame($firstEntry->getId(), $secondEntry->getId());
        $this->assertSame(25000, $this->walletService->getBalance($userId)->getAmount());

        // Third settlement call
        $thirdEntry = $this->walletService->settleSuccessfulPayment($intent->getId());
        $this->assertSame($firstEntry->getId(), $thirdEntry->getId());
        $this->assertSame(25000, $this->walletService->getBalance($userId)->getAmount());

        // Verify only 1 row exists in the database
        $count = $this->db->selectOne(
            "SELECT COUNT(*) as total FROM favorite_pay_wallet_entries WHERE reference_id = ?",
            [$intent->getId()]
        );
        $this->assertSame(1, (int)$count->total);
    }

    public function testAutoCreatesWalletIfUserHasNoWallet(): void
    {
        $newUserId = 9999;
        // Verify user has no wallet beforehand
        $walletBefore = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$newUserId]);
        $this->assertNull($walletBefore);

        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'first_order',
            Money::bdt(15000),
            ['customer_id' => $newUserId]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        $entry = $this->walletService->settleSuccessfulPayment($intent->getId());

        $walletAfter = $this->db->selectOne("SELECT * FROM favorite_pay_wallets WHERE user_id = ?", [$newUserId]);
        $this->assertNotNull($walletAfter);
        $this->assertSame('active', $walletAfter->status);
        $this->assertSame(15000, (int)$walletAfter->balance);
        $this->assertSame('BDT', $walletAfter->currency);
    }

    public function testDatabaseUniqueConstraintEnforcedOnIdempotencyKey(): void
    {
        $userId = 107;
        $intent = $this->paymentService->createIntent(
            'test',
            'test_unique',
            Money::bdt(10000),
            ['customer_id' => $userId]
        );
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        // Settle once
        $this->walletService->settleSuccessfulPayment($intent->getId());

        // Direct DB attempt to insert duplicate idempotency key must fail
        $this->expectException(\PDOException::class);
        $this->db->insert('favorite_pay_wallet_entries', [
            'entry_id'        => 'led_duplicate_test',
            'wallet_id'       => 1,
            'user_id'         => $userId,
            'type'            => 'credit',
            'amount'          => 10000,
            'balance_after'   => 20000,
            'reference_type'  => 'payment',
            'reference_id'    => $intent->getId(),
            'idempotency_key' => 'settle:payment:' . $intent->getId(),
            'description'     => 'Duplicate test',
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    public function testEventHookAutoSettlesOnPaymentSucceeded(): void
    {
        $userId = 108;
        $intent = $this->paymentService->createIntent(
            'favorite_digital',
            'dl_500',
            Money::bdt(45000),
            ['customer_id' => $userId]
        );

        // Advance to succeeded (which calls do_action('favorite.pay.payment.succeeded'))
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        // The hook in FavoritePayPlugin should have auto-settled the payment into the wallet!
        $balance = $this->walletService->getBalance($userId);
        $this->assertSame(45000, $balance->getAmount());

        // Ledger check
        $entry = $this->db->selectOne(
            "SELECT * FROM favorite_pay_wallet_entries WHERE reference_id = ?",
            [$intent->getId()]
        );
        $this->assertNotNull($entry);
        $this->assertSame(45000, (int)$entry->amount);
    }

    public function testEndToEndManualPaymentApprovalTriggersWalletSettlement(): void
    {
        $userId = 200;
        $amount = Money::bdt(75000); // 750.00 BDT

        // 1. Customer initiates manual bKash payment
        $intent = $this->paymentService->createIntent(
            'favorite_shop',
            'order_777',
            $amount,
            [
                'customer_id' => $userId,
                'gateway'     => 'manual_bkash',
            ]
        );

        // 2. Customer submits transaction reference
        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'manual_bkash',
            'TRX777999888',
            [
                'customer_account' => '01711223344',
                'customer_note'    => 'Paid for order 777',
            ]
        );
        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $attempt->getStatus());

        // Wallet balance before approval must be 0
        $this->assertSame(0, $this->walletService->getBalance($userId)->getAmount());

        // 3. Admin operator approves payment
        $approved = $this->paymentService->approveManualPayment(
            $attempt->getId(),
            1, // admin user ID
            'Transaction verified against bKash merchant portal'
        );
        $this->assertSame(PaymentStatus::SUCCEEDED, $approved->getStatus());

        // 4. Wallet should now be credited with 75,000 Poisha automatically via event hook!
        $balance = $this->walletService->getBalance($userId);
        $this->assertSame(75000, $balance->getAmount());

        // 5. Calling settleSuccessfulPayment manually on already-settled payment is idempotent
        $entryAgain = $this->walletService->settleSuccessfulPayment($intent->getId());
        $this->assertSame(75000, $entryAgain->getAmount()->getAmount());
        $this->assertSame(75000, $this->walletService->getBalance($userId)->getAmount());
    }
}
