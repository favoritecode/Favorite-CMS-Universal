<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\NotificationServiceInterface;
use FavoriteCMS\Pay\Controllers\CustomerAccountController;
use FavoriteCMS\Pay\Controllers\WithdrawalAdminController;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Domain\Withdrawal;
use FavoriteCMS\Pay\Domain\WithdrawalStatus;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
use FavoriteCMS\Pay\Services\AuditLogService;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\NotificationService;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\WalletService;
use FavoriteCMS\Pay\Services\WithdrawalService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class HardeningTestUserStub extends User
{
    private array $rolesList;
    private array $permissionsList;

    public function __construct(array $attributes = [], array $roles = [], array $permissions = [])
    {
        $this->attributes = array_merge([
            'id'       => 1,
            'username' => 'testuser',
            'email'    => 'test@example.com',
            'status'   => 'active',
        ], $attributes);
        $this->rolesList = $roles;
        $this->permissionsList = $permissions;
    }

    public function hasRole(string $roleSlug): bool
    {
        return in_array($roleSlug, $this->rolesList, true);
    }

    public function hasPermission(string $permissionSlug): bool
    {
        if ($this->hasRole('super-admin')) {
            return true;
        }
        return in_array($permissionSlug, $this->permissionsList, true);
    }

    public function isBanned(): bool
    {
        return strtolower((string)($this->attributes['status'] ?? '')) === 'banned';
    }

    public function isSuspended(): bool
    {
        return strtolower((string)($this->attributes['status'] ?? '')) === 'suspended';
    }
}

class FailureScenarioAndHardeningTest extends TestCase
{
    private PDO $pdo;
    private Database $db;
    private Application $app;
    private CurrencyService $currencyService;
    private GatewayRegistry $registry;
    private PaymentService $paymentService;
    private WalletService $walletService;
    private WithdrawalService $withdrawalService;
    private NotificationService $notificationService;
    private AuditLogService $auditService;
    private CustomerAccountController $customerController;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_SESSION['_token'] = 'valid-token';
        $_SESSION['_csrf_token'] = 'valid-token';
        unset($GLOBALS['_test_current_user'], $GLOBALS['_test_current_user_id']);

        $this->app = new Application(dirname(__DIR__, 4));
        FavoritePayPlugin::reset();

        $this->pdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
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

        // Execute schema via PDO::exec to ensure all tables are created
        $this->pdo->exec("
            CREATE TABLE favorite_pay_wallets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER UNIQUE NOT NULL,
                balance INTEGER NOT NULL DEFAULT 0,
                currency VARCHAR(10) NOT NULL DEFAULT 'BDT',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            );

            CREATE TABLE favorite_pay_wallet_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entry_id VARCHAR(64) UNIQUE NOT NULL,
                wallet_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                type VARCHAR(20) NOT NULL,
                amount INTEGER NOT NULL,
                balance_after INTEGER NOT NULL,
                reference_type VARCHAR(50) NOT NULL,
                reference_id VARCHAR(100) NOT NULL,
                idempotency_key VARCHAR(100) NULL,
                description TEXT NULL,
                metadata TEXT NULL,
                created_at DATETIME NOT NULL
            );

            CREATE TABLE favorite_pay_withdrawals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                withdrawal_id VARCHAR(64) UNIQUE NOT NULL,
                user_id INTEGER NOT NULL,
                wallet_id INTEGER NULL,
                amount INTEGER NOT NULL,
                currency VARCHAR(10) NOT NULL,
                fee INTEGER NOT NULL DEFAULT 0,
                net_amount INTEGER NOT NULL,
                method VARCHAR(50) NOT NULL,
                destination_data TEXT NOT NULL,
                destination_masked VARCHAR(255) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                hold_reference VARCHAR(100) NOT NULL,
                transaction_reference VARCHAR(100) NULL,
                idempotency_key VARCHAR(100) UNIQUE NULL,
                admin_user_id INTEGER NULL,
                operator_notes TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL,
                processed_at DATETIME NULL,
                audit_trail TEXT NULL
            );

            CREATE TABLE favorite_pay_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                transaction_id VARCHAR(64) UNIQUE NOT NULL,
                source_plugin VARCHAR(50) NOT NULL,
                source_reference VARCHAR(100) NOT NULL,
                user_id INTEGER NULL,
                base_amount INTEGER NOT NULL,
                base_currency VARCHAR(10) NOT NULL,
                charge_amount INTEGER NOT NULL,
                charge_currency VARCHAR(10) NOT NULL,
                status VARCHAR(30) NOT NULL,
                payment_method_type VARCHAR(50) NULL,
                gateway_id VARCHAR(50) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL
            );

            CREATE TABLE favorite_pay_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                attempt_id VARCHAR(64) UNIQUE NOT NULL,
                transaction_id VARCHAR(64) NOT NULL,
                gateway_id VARCHAR(50) NOT NULL,
                amount INTEGER NOT NULL,
                currency VARCHAR(10) NOT NULL,
                status VARCHAR(30) NOT NULL,
                provider_reference VARCHAR(255) NULL,
                raw_response TEXT NULL,
                created_at DATETIME NOT NULL,
                verified_at DATETIME NULL
            );

            CREATE TABLE favorite_pay_notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                notification_id VARCHAR(64) UNIQUE NOT NULL,
                user_id INTEGER NOT NULL,
                type VARCHAR(50) NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                metadata TEXT NULL,
                is_read INTEGER NOT NULL DEFAULT 0,
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL
            );

            CREATE TABLE favorite_pay_audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                log_id VARCHAR(64) UNIQUE NOT NULL,
                action VARCHAR(100) NOT NULL,
                subject_type VARCHAR(50) NOT NULL,
                subject_id VARCHAR(100) NOT NULL,
                target_user_id INTEGER NULL,
                metadata TEXT NULL,
                description TEXT NULL,
                actor_user_id INTEGER NULL,
                actor_type VARCHAR(50) NOT NULL DEFAULT 'system',
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL
            );
        ");

        $this->currencyService = new CurrencyService();
        $this->registry = new GatewayRegistry();
        $this->paymentService = new PaymentService(
            $this->currencyService,
            $this->registry,
            $this->db
        );
        $this->walletService = new WalletService($this->currencyService, $this->paymentService, $this->db);
        $this->notificationService = new NotificationService($this->db);
        $this->auditService = new AuditLogService($this->db);
        $this->withdrawalService = new WithdrawalService(
            $this->walletService,
            $this->currencyService,
            $this->db,
            $this->notificationService,
            $this->auditService
        );
        $this->withdrawalService->updateSettings([
            'enabled'           => true,
            'min_amount'        => 500.0,
            'max_monthly_count' => 5,
            'allowed_methods'   => ['bkash', 'nagad', 'rocket', 'bank_transfer'],
        ]);

        $manualBkash = new ManualBangladeshGateway(
            'manual_bkash',
            'bKash Manual Payment',
            PaymentMethodType::MANUAL_BKASH,
            [
                'channel'        => 'bkash',
                'account_number' => '01700000000',
                'account_name'   => 'Merchant Ltd',
                'instructions'   => 'Send money to 01700000000 and enter TrxID below.',
            ],
            true
        );
        $this->registry->register($manualBkash);

        $this->customerController = new CustomerAccountController(
            $this->app,
            $this->walletService,
            $this->paymentService,
            $this->registry,
            $this->currencyService,
            $this->db,
            $this->withdrawalService,
            $this->notificationService,
            $this->auditService
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_test_current_user'], $GLOBALS['_test_current_user_id']);
        $_SESSION = [];
    }

    public function testAutomaticHoldReleaseWhenWithdrawalDbPersistenceFails(): void
    {
        $userId = 101;
        $this->walletService->deposit($userId, new Money(200000, 'BDT'), 'test_dep_1');
        $this->assertSame(200000, $this->walletService->getAvailableBalance($userId)->getAmount());

        $this->pdo->exec('DROP TABLE IF EXISTS favorite_pay_withdrawals');
        $this->pdo->exec('
            CREATE TABLE favorite_pay_withdrawals (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                withdrawal_id VARCHAR(64) UNIQUE NOT NULL,
                user_id INTEGER NOT NULL,
                fail_trigger INTEGER NOT NULL,
                amount INTEGER NOT NULL,
                currency VARCHAR(10) NOT NULL,
                fee INTEGER NOT NULL DEFAULT 0,
                net_amount INTEGER NOT NULL,
                method VARCHAR(50) NOT NULL,
                destination_data TEXT NOT NULL,
                destination_masked VARCHAR(255) NULL,
                status VARCHAR(30) NOT NULL DEFAULT "pending",
                hold_reference VARCHAR(100) NOT NULL,
                created_at DATETIME NOT NULL
            )
        ');

        try {
            $this->withdrawalService->createWithdrawal(
                $userId,
                new Money(100000, 'BDT'),
                'bkash',
                ['account_number' => '01711111111']
            );
            $this->fail('Expected DB insertion to fail due to NOT NULL violation.');
        } catch (Throwable $e) {
            $this->assertInstanceOf(Throwable::class, $e);
        }

        // CRITICAL INVARIANT: The hold must have been released automatically
        $available = $this->walletService->getAvailableBalance($userId)->getAmount();
        $this->assertSame(200000, $available, 'Customer balance must be restored when withdrawal persistence fails.');
        $held = $this->walletService->getHeldBalance($userId)->getAmount();
        $this->assertSame(0, $held, 'Held balance must be 0 after automatic hold release.');
    }

    public function testConcurrentMonthlyWithdrawalLimitGuardRevertsExcess(): void
    {
        $userId = 102;
        $this->withdrawalService->updateSettings([
            'enabled'           => true,
            'min_amount'        => 500.0,
            'max_monthly_count' => 1,
        ]);

        $this->walletService->deposit($userId, new Money(500000, 'BDT'), 'test_dep_2');

        $w1 = $this->withdrawalService->createWithdrawal(
            $userId,
            new Money(100000, 'BDT'),
            'bkash',
            ['account_number' => '01711111111']
        );
        $this->assertNotNull($w1);
        $this->assertSame(1, $this->withdrawalService->getMonthlyWithdrawalCount($userId));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Monthly withdrawal limit of 1 requests reached');

        $this->withdrawalService->createWithdrawal(
            $userId,
            new Money(100000, 'BDT'),
            'bkash',
            ['account_number' => '01711111111']
        );
    }

    public function testConcurrentWalletHoldGuardsAgainstNegativeBalance(): void
    {
        $userId = 103;
        $this->walletService->deposit($userId, new Money(100000, 'BDT'), 'dep_103');

        $hold1 = $this->walletService->hold($userId, new Money(100000, 'BDT'), 'hold_ref_1');
        $this->assertSame(0, $this->walletService->getAvailableBalance($userId)->getAmount());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insufficient balance');
        $this->walletService->hold($userId, new Money(50000, 'BDT'), 'hold_ref_2');
    }

    public function testConcurrentWalletDebitGuardsAgainstNegativeBalance(): void
    {
        $userId = 104;
        $this->walletService->deposit($userId, new Money(50000, 'BDT'), 'dep_104');

        $entry1 = $this->walletService->debit($userId, new Money(50000, 'BDT'), 'purchase_1');
        $this->assertSame(0, $this->walletService->getAvailableBalance($userId)->getAmount());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insufficient wallet balance');
        $this->walletService->debit($userId, new Money(1000, 'BDT'), 'purchase_2');
    }

    public function testSuspendedCustomerLifecycleEnforcement(): void
    {
        $user = new HardeningTestUserStub(['id' => 105, 'status' => 'suspended']);
        $GLOBALS['_test_current_user'] = $user;
        $GLOBALS['_test_current_user_id'] = 105;

        // 1. Wallet view: allowed (200 OK)
        $reqWallet = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $resWallet = $this->customerController->wallet($reqWallet);
        $this->assertSame(200, $resWallet->getStatusCode());

        // 2. Transactions view: allowed (200 OK)
        $reqTx = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $resTx = $this->customerController->transactions($reqTx);
        $this->assertSame(200, $resTx->getStatusCode());

        // 3. Recharge GET: blocked (403 Forbidden)
        $reqRechargeGet = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $resRechargeGet = $this->customerController->recharge($reqRechargeGet);
        $this->assertSame(403, $resRechargeGet->getStatusCode());

        // 4. Recharge POST: blocked (403 Forbidden)
        $reqRechargePost = new Request([], ['amount' => '500', 'gateway_id' => 'manual_bkash', '_token' => 'valid-token'], ['REQUEST_METHOD' => 'POST']);
        $resRechargePost = $this->customerController->recharge($reqRechargePost);
        $this->assertSame(403, $resRechargePost->getStatusCode());

        // 5. Withdrawal GET: blocked (403 Forbidden)
        $reqWithdrawGet = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $resWithdrawGet = $this->customerController->withdraw($reqWithdrawGet);
        $this->assertSame(403, $resWithdrawGet->getStatusCode());

        // 6. Manual Payment Submit: blocked (redirects with error flash)
        $reqManual = new Request([], ['intent_id' => 'pi_test', 'trx_id' => 'TRX123', '_token' => 'valid-token'], ['REQUEST_METHOD' => 'POST']);
        $resManual = $this->customerController->submitManual($reqManual);
        $this->assertSame(302, $resManual->getStatusCode());
        $this->assertStringContainsString('suspended', $_SESSION['flash_error'] ?? '');
    }

    public function testCustomerWithdrawalFormSubmissionParameterMapping(): void
    {
        $userId = 106;
        $user = new HardeningTestUserStub(['id' => $userId, 'status' => 'active']);
        $GLOBALS['_test_current_user'] = $user;
        $GLOBALS['_test_current_user_id'] = $userId;

        $this->walletService->deposit($userId, new Money(300000, 'BDT'), 'dep_106');

        $req = new Request([], [
            'amount'         => '1500.00',
            'method'         => 'bkash',
            'account_number' => '01799999999',
            'account_name'   => 'Customer Name',
            '_token'         => 'valid-token',
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $this->customerController->withdraw($req);

        $this->assertSame(302, $res->getStatusCode());
        $location = $res->getHeader('Location');
        $this->assertStringStartsWith('/account/withdrawals/wd_', $location, "Failed with flash_error: " . var_export($_SESSION['flash_error'] ?? null, true));
        $this->assertSame('Withdrawal request submitted successfully.', $_SESSION['flash_success']);

        $wdId = substr($location, strlen('/account/withdrawals/'));
        $wd = $this->withdrawalService->getWithdrawal($wdId);
        $this->assertNotNull($wd);
        $this->assertSame(150000, $wd->getAmount()->getAmount());
        $this->assertSame('bkash', $wd->getMethod());
        $this->assertStringContainsString('***', $wd->getDestinationMasked());
    }

    public function testStrictIdorAuthorizationBoundariesOnCustomerEndpoints(): void
    {
        $ownerId = 107;
        $attackerId = 108;

        $this->walletService->deposit($ownerId, new Money(200000, 'BDT'), 'dep_107');
        $withdrawal = $this->withdrawalService->createWithdrawal(
            $ownerId,
            new Money(100000, 'BDT'),
            'bkash',
            ['account_number' => '01700000000']
        );

        $attacker = new HardeningTestUserStub(['id' => $attackerId, 'status' => 'active']);
        $GLOBALS['_test_current_user'] = $attacker;
        $GLOBALS['_test_current_user_id'] = $attackerId;

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET']);
        $res = $this->customerController->withdrawalDetail($req, $withdrawal->getId());
        $this->assertSame(403, $res->getStatusCode());

        $reqCancel = new Request([], ['action' => 'cancel', '_token' => 'valid-token'], ['REQUEST_METHOD' => 'POST']);
        $resCancel = $this->customerController->withdrawalDetail($reqCancel, $withdrawal->getId());
        $this->assertSame(403, $resCancel->getStatusCode());

        $entry = $this->walletService->getLedgerHistory($ownerId, 1)[0];
        $resEntry = $this->customerController->transactionDetail($req, $entry->getId());
        $this->assertSame(403, $resEntry->getStatusCode());
    }

    public function testAdminActionsCsrfProtection(): void
    {
        $adminStub = new HardeningTestUserStub(
            ['id' => 1, 'username' => 'admin', 'status' => 'active'],
            ['super-admin'],
            ['favorite_pay.manage_withdrawals', 'favorite_pay.view_withdrawals']
        );
        $GLOBALS['_test_current_user'] = $adminStub;
        $_SESSION['auth_user_id'] = 1;

        $adminController = new WithdrawalAdminController(
            $this->app,
            $this->withdrawalService,
            $this->walletService,
            $this->auditService
        );

        $req = new Request([], [
            'action'        => 'approve',
            'withdrawal_id' => 'wd_fake',
            '_token'        => 'invalid-or-missing-token',
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $adminController->handle($req);
        $this->assertInstanceOf(Response::class, $res);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('Invalid security token', $_SESSION['flash_error'] ?? '');
    }

    public function testTerminalWithdrawalStatesCannotTransition(): void
    {
        $userId = 109;
        $this->walletService->deposit($userId, new Money(200000, 'BDT'), 'dep_109');
        $w = $this->withdrawalService->createWithdrawal(
            $userId,
            new Money(100000, 'BDT'),
            'bkash',
            ['account_number' => '01700000000']
        );

        $rejected = $this->withdrawalService->reject($w->getId(), 1, 'Rejected reason');
        $this->assertSame(WithdrawalStatus::REJECTED, $rejected->getStatus());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot transition withdrawal');
        $this->withdrawalService->approve($w->getId(), 1);
    }

    public function testCsvSpreadsheetFormulaInjectionSanitization(): void
    {
        $dangerousInputs = [
            '=SUM(A1:A10)'   => "'=SUM(A1:A10)",
            "+cmd|' /C calc" => "'+cmd|' /C calc",
            '-1+1'           => "'-1+1",
            '@SUM(1+1)'      => "'@SUM(1+1)",
            "\tTAB_INJECT"   => "'\tTAB_INJECT",
            "\rCR_INJECT"    => "'\rCR_INJECT",
            'Normal Text'    => 'Normal Text',
            '123.45'         => '123.45',
        ];

        foreach ($dangerousInputs as $input => $expected) {
            $isNumeric = is_numeric($input);
            $sanitized = WithdrawalService::sanitizeCsvValue($input, $isNumeric);
            $this->assertSame($expected, $sanitized, "Failed formula sanitization for '{$input}'");
        }
    }

    public function testZeroBalanceAndExactBalanceWithdrawalValidation(): void
    {
        $userId = 110;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient available balance');
        $this->withdrawalService->createWithdrawal(
            $userId,
            new Money(50000, 'BDT'),
            'bkash',
            ['account_number' => '01700000000']
        );
    }

    public function testExactBalanceWithdrawalLeavesZeroAvailable(): void
    {
        $userId = 111;
        $this->walletService->deposit($userId, new Money(50000, 'BDT'), 'dep_111');
        $this->assertSame(50000, $this->walletService->getAvailableBalance($userId)->getAmount());

        $wd = $this->withdrawalService->createWithdrawal(
            $userId,
            new Money(50000, 'BDT'),
            'bkash',
            ['account_number' => '01700000000']
        );

        $this->assertSame(0, $this->walletService->getAvailableBalance($userId)->getAmount());
        $this->assertSame(50000, $this->walletService->getHeldBalance($userId)->getAmount());
        $this->assertSame(50000, $this->walletService->getTotalBalance($userId)->getAmount());

        $this->withdrawalService->approve($wd->getId(), 1);
        $this->withdrawalService->startProcessing($wd->getId(), 1);
        $paid = $this->withdrawalService->markPaid($wd->getId(), 1, 'TRX_PAID_111');
        $this->assertSame(WithdrawalStatus::PAID, $paid->getStatus());
        $this->assertSame(0, $this->walletService->getAvailableBalance($userId)->getAmount());
        $this->assertSame(0, $this->walletService->getHeldBalance($userId)->getAmount());
        $this->assertSame(0, $this->walletService->getTotalBalance($userId)->getAmount());
    }
}
