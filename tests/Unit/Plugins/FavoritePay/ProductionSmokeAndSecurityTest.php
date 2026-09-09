<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Core\AccountMenu;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Migrator;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\AuditLogServiceInterface;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Contracts\NotificationServiceInterface;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
use FavoriteCMS\Pay\Contracts\WithdrawalServiceInterface;
use FavoriteCMS\Pay\Controllers\AuditLogAdminController;
use FavoriteCMS\Pay\Controllers\CustomerAccountController;
use FavoriteCMS\Pay\Controllers\FinancialDashboardAdminController;
use FavoriteCMS\Pay\Controllers\PaymentAdminController;
use FavoriteCMS\Pay\Controllers\PaymentRateController;
use FavoriteCMS\Pay\Controllers\WithdrawalAdminController;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Domain\Withdrawal;
use FavoriteCMS\Pay\Domain\WithdrawalStatus;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Gateways\Binance\BinancePayGateway;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
use FavoriteCMS\Pay\Permissions\PaymentPermission;
use FavoriteCMS\Pay\Repositories\PaymentAttemptRepository;
use FavoriteCMS\Pay\Services\AuditLogService;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\NotificationService;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\WalletService;
use FavoriteCMS\Pay\Services\WithdrawalService;
use FavoriteCMS\Pay\Support\SafeLogger;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

class SecurityTestUserStub extends User
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

class ProductionSmokeAndSecurityTest extends TestCase
{
    private Application $app;
    private Database $db;
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
        $_SESSION['_token'] = 'valid-test-csrf-token';
        $_SESSION['_csrf_token'] = 'valid-test-csrf-token';
        unset($GLOBALS['_test_current_user'], $GLOBALS['_test_current_user_id']);

        $this->app = new Application();
        FavoritePayPlugin::reset();
        AccountMenu::reset();

        $this->db = new Database([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->app->singleton(Database::class, fn() => $this->db);

        // Core stub tables
        $this->db->query("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(255),
            email VARCHAR(255),
            status VARCHAR(50) DEFAULT 'active'
        )");

        // Run migrations to ensure full 10 tables schema
        $migrator = new Migrator($this->db);
        $migrator->migrate(APP_ROOT . '/plugins/favorite-pay/database/migrations');

        $this->currencyService = new CurrencyService();
        $this->registry = new GatewayRegistry();

        $manualBkash = new ManualBangladeshGateway(
            'manual_bkash',
            'bKash Manual',
            PaymentMethodType::MANUAL_BKASH,
            ['channel' => 'bkash', 'account_number' => '01700000000', 'account_name' => 'Merchant Ltd'],
            true
        );
        $this->registry->register($manualBkash);

        $this->paymentService = new PaymentService($this->currencyService, $this->registry, $this->db);
        $this->walletService = new WalletService($this->currencyService, $this->paymentService, $this->db);
        $this->withdrawalService = new WithdrawalService($this->walletService, $this->currencyService, $this->db);
        $this->withdrawalService->setSettingsOverride([
            'enabled'         => true,
            'allowed_methods' => ['bkash', 'nagad', 'bank'],
            'min_amount'      => 1.0,
            'max_amount'      => 50000.0,
        ]);

        $this->notificationService = new NotificationService($this->db);
        $this->auditService = new AuditLogService($this->db);

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
        FavoritePayPlugin::reset();
        AccountMenu::reset();
        $_SESSION = [];
        unset($GLOBALS['_test_current_user'], $GLOBALS['_test_current_user_id']);
    }

    private function setLoggedInUser(int $id, string $username = 'user', array $roles = [], array $perms = []): SecurityTestUserStub
    {
        $_SESSION['auth_user_id'] = $id;
        $_SESSION['auth_user_name'] = $username;
        $user = new SecurityTestUserStub([
            'id'       => $id,
            'username' => $username,
            'email'    => $username . '@example.com',
            'status'   => 'active',
        ], $roles, $perms);
        $GLOBALS['_test_current_user'] = $user;
        return $user;
    }

    /**
     * 1. IDOR Prevention: Customer A cannot see Customer B wallet balance.
     */
    public function testIdorCustomerCannotAccessAnotherCustomersWallet(): void
    {
        // Setup Customer A (id 10) with 5,000 BDT
        $this->walletService->deposit(10, Money::fromMajorString('50.00', 'BDT'), 'deposit', 'dep_10', null, 'Test 10');
        // Setup Customer B (id 20) with 12,500 BDT
        $this->walletService->deposit(20, Money::fromMajorString('125.00', 'BDT'), 'deposit', 'dep_20', null, 'Test 20');

        // Logged in as Customer A
        $this->setLoggedInUser(10, 'cust_a');

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']);
        $resp = $this->customerController->wallet($req);

        $this->assertSame(200, $resp->getStatusCode());
        $content = $resp->getContent();

        // Customer A must see 50.00
        $this->assertStringContainsString('50.00', $content);
        // Customer A MUST NOT see Customer B balance (125.00)
        $this->assertStringNotContainsString('125.00', $content);
    }

    /**
     * 2. IDOR Prevention: Customer A cannot view Customer B transaction detail.
     */
    public function testIdorCustomerCannotAccessAnotherCustomersTransactionDetail(): void
    {
        $this->walletService->deposit(10, Money::fromMajorString('50.00', 'BDT'), 'deposit', 'dep_10', null, 'Cust 10 deposit');
        $this->walletService->deposit(20, Money::fromMajorString('75.00', 'BDT'), 'deposit', 'dep_20', null, 'Cust 20 deposit');

        $entriesB = $this->walletService->getLedgerHistory(20);
        $this->assertNotEmpty($entriesB);
        $entryB = $entriesB[0];

        // Customer A tries to view Customer B transaction
        $this->setLoggedInUser(10, 'cust_a');

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions/' . $entryB->getId()]);
        $resp = $this->customerController->transactionDetail($req, $entryB->getId());

        $this->assertSame(403, $resp->getStatusCode());
        $this->assertStringContainsString('Access denied', $resp->getContent());
    }

    /**
     * 3. IDOR Prevention: Customer A cannot view Customer B payment detail.
     */
    public function testIdorCustomerCannotAccessAnotherCustomersPaymentDetail(): void
    {
        $intentB = $this->paymentService->createIntent(
            'favorite-pay',
            'rec_20',
            Money::fromMajorString('100.00', 'BDT'),
            ['user_id' => 20]
        );

        // Customer A tries to view Customer B payment
        $this->setLoggedInUser(10, 'cust_a');

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments/' . $intentB->getId()]);
        $resp = $this->customerController->paymentDetail($req, $intentB->getId());

        $this->assertSame(403, $resp->getStatusCode());
        $this->assertStringContainsString('Access denied', $resp->getContent());
    }

    /**
     * 4. IDOR Prevention: Customer A cannot view Customer B withdrawal detail.
     */
    public function testIdorCustomerCannotAccessAnotherCustomersWithdrawalDetail(): void
    {
        $this->walletService->deposit(20, Money::fromMajorString('200.00', 'BDT'), 'deposit', 'dep_20', null, 'Fund for wd');

        $wdB = $this->withdrawalService->createWithdrawal(
            20,
            Money::fromMajorString('50.00', 'BDT'),
            'bkash',
            ['account_number' => '01799999999']
        );

        // Customer A attempts to view Customer B withdrawal
        $this->setLoggedInUser(10, 'cust_a');

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/withdrawals/' . $wdB->getId()]);
        $resp = $this->customerController->withdrawalDetail($req, $wdB->getId());

        $this->assertSame(403, $resp->getStatusCode());
        $this->assertStringContainsString('Access denied', $resp->getContent());
    }

    /**
     * 5. IDOR Prevention: Customer A cannot mark read Customer B notifications.
     */
    public function testIdorCustomerCannotMarkReadAnotherCustomersNotifications(): void
    {
        $notif = $this->notificationService->notify(20, 'withdrawal_submitted', 'Withdrawal Submitted', '50.00 BDT to bkash', 'wd_b_99');
        $notifId = $notif['id'] ?? null;
        $this->assertNotNull($notifId);

        // Customer A tries to mark read Customer B notification
        $this->setLoggedInUser(10, 'cust_a');

        $req = new Request([], [
            'action'          => 'mark_read',
            'notification_id' => $notifId,
            '_csrf_token'     => 'valid-test-csrf-token',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/notifications']);
        $resp = $this->customerController->notifications($req);

        $this->assertSame(302, $resp->getStatusCode());

        // Check DB: notification must still be unread
        $row = $this->db->selectOne("SELECT is_read FROM favorite_pay_notifications WHERE id = ?", [$notifId]);
        $this->assertNotNull($row);
        $this->assertSame(0, (int)$row->is_read, 'Customer A should not be able to mark Customer B notification as read');
    }

    /**
     * 6. CSRF Enforcement on Customer and Admin POST routes.
     */
    public function testCsrfEnforcementAcrossCustomerAndAdminPostRoutes(): void
    {
        $this->walletService->deposit(10, Money::fromMajorString('200.00', 'BDT'), 'deposit', 'dep_10', null, 'Funds');
        $this->setLoggedInUser(10, 'cust_a');

        // Customer POST /account/recharge with invalid CSRF token
        $reqRecharge = new Request([], [
            'amount'      => '50.00',
            'currency'    => 'BDT',
            'gateway'     => 'manual_bkash',
            '_csrf_token' => 'invalid-token-12345',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/recharge']);
        $respRecharge = $this->customerController->recharge($reqRecharge);
        $this->assertSame(302, $respRecharge->getStatusCode());
        $this->assertStringContainsString('Invalid or expired security token', $_SESSION['flash_error'] ?? '');

        // Customer POST /account/withdraw with missing CSRF token
        $reqWithdraw = new Request([], [
            'amount'         => '30.00',
            'method'         => 'bkash',
            'account_number' => '01700000000',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/withdraw']);
        $respWithdraw = $this->customerController->withdraw($reqWithdraw);
        $this->assertSame(302, $respWithdraw->getStatusCode());
        $this->assertStringContainsString('Invalid or expired security token', $_SESSION['flash_error'] ?? '');

        // Customer POST /account/notifications with missing CSRF token
        $reqNotif = new Request([], [
            'action' => 'mark_all_read',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/notifications']);
        $respNotif = $this->customerController->notifications($reqNotif);
        $this->assertSame(302, $respNotif->getStatusCode());
        $this->assertStringContainsString('Invalid or expired security token', $_SESSION['flash_error'] ?? '');

        // Admin POST /admin/page/favorite-pay-withdrawals with missing CSRF token
        $adminUser = $this->setLoggedInUser(1, 'superadmin', ['super-admin']);
        $wd = $this->withdrawalService->createWithdrawal(
            10,
            Money::fromMajorString('30.00', 'BDT'),
            'bkash',
            ['account_number' => '01700000000']
        );

        $adminWdCtrl = new WithdrawalAdminController(
            $this->app,
            $this->withdrawalService,
            $this->walletService,
            $this->auditService
        );

        $reqApprove = new Request([], [
            'action'        => 'approve',
            'withdrawal_id' => $wd->getId(),
            // no CSRF token
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-pay-withdrawals']);
        $respApprove = $adminWdCtrl->handle($reqApprove);
        $this->assertInstanceOf(Response::class, $respApprove);
        $this->assertSame(302, $respApprove->getStatusCode());
        $this->assertStringContainsString('Invalid security token', $_SESSION['flash_error'] ?? '');

        // Ensure withdrawal remains PENDING
        $reloadedWd = $this->withdrawalService->getWithdrawal($wd->getId());
        $this->assertSame(WithdrawalStatus::PENDING, $reloadedWd->getStatus());
    }

    /**
     * 7. XSS Sanitization in views, operator notes, and transaction references.
     */
    public function testXssSanitizationInOperatorNotesAndInputs(): void
    {
        $xssPayload = '<script>alert("xss")</script><img src="x" onerror="alert(1)">';

        // Credit customer with XSS reference
        $this->walletService->deposit(10, Money::fromMajorString('100.00', 'BDT'), 'deposit', $xssPayload, null, 'Deposit note');

        // Admin views customer wallet
        $adminCtrl = new FinancialDashboardAdminController(
            $this->app,
            $this->walletService,
            $this->withdrawalService,
            $this->paymentService,
            $this->currencyService,
            $this->db
        );
        $adminUser = $this->setLoggedInUser(1, 'superadmin', ['super-admin']);

        $req = new Request(['action' => 'customer', 'user_id' => 10], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard?action=customer&user_id=10']);
        $html = $adminCtrl->customerDetail($req, $adminUser);

        $this->assertIsString($html);
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringNotContainsString('<img src="x" onerror="alert(1)">', $html);

        // Withdrawal with XSS operator note updated through service
        $wd = $this->withdrawalService->createWithdrawal(
            10,
            Money::fromMajorString('20.00', 'BDT'),
            'bkash',
            ['account_number' => '01700000000']
        );
        $this->withdrawalService->updateProcessingNotes($wd->getId(), 1, $xssPayload);

        $adminWdCtrl = new WithdrawalAdminController(
            $this->app,
            $this->withdrawalService,
            $this->walletService,
            $this->auditService
        );

        $wdDetailHtml = $adminWdCtrl->detail(new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/pay/withdrawals/' . $wd->getId()]), $wd->getId(), $adminUser);

        $this->assertStringNotContainsString('<script>alert("xss")</script>', $wdDetailHtml);
        $this->assertStringNotContainsString('<img src="x" onerror="alert(1)">', $wdDetailHtml);
        $this->assertStringContainsString('&lt;script&gt;', $wdDetailHtml);
    }

    /**
     * 8. SQL Injection Immunity across queries, filters, and route IDs.
     */
    public function testSqlInjectionImmunityInFiltersAndRoutes(): void
    {
        $sqliPayloads = [
            "' OR '1'='1",
            "1; DROP TABLE favorite_pay_wallets; --",
            "' UNION SELECT 1, 'admin', 'pass' --",
            "' AND 1=SLEEP(1) --",
        ];

        foreach ($sqliPayloads as $payload) {
            // Test 1: AuditLog list with SQLi payload in action, date, subject_type
            $result = $this->auditService->listLogs([
                'action'       => $payload,
                'subject_type' => $payload,
                'from'         => $payload,
                'to'           => $payload,
            ]);
            $this->assertIsArray($result);
            $this->assertIsArray($result['items']);

            // Test 2: WithdrawalService list with SQLi payload in status, currency, method
            $wdList = $this->withdrawalService->listWithdrawals([
                'status'   => $payload,
                'currency' => $payload,
                'method'   => $payload,
                'date_from'=> $payload,
            ]);
            $this->assertIsArray($wdList);
            $this->assertIsArray($wdList['items']);

            // Test 3: Customer transaction detail route with SQLi payload
            $this->setLoggedInUser(10, 'cust_a');
            $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions/' . urlencode($payload)]);
            $resp = $this->customerController->transactionDetail($req, $payload);
            $this->assertTrue(in_array($resp->getStatusCode(), [403, 404], true));

            // Verify the wallets table was NOT dropped and remains intact
            $this->assertTrue($this->db->tableExists('favorite_pay_wallets'));
        }
    }

    /**
     * 9. HTTP Method Safety: GET requests must NEVER mutate balances or transition states.
     */
    public function testHttpMethodSafetyGetRequestsNeverMutateState(): void
    {
        $this->walletService->deposit(10, Money::fromMajorString('100.00', 'BDT'), 'deposit', 'dep_10', null, 'Funds');
        $initialBalance = $this->walletService->getAvailableBalance(10, 'BDT')->getAmount();

        $this->setLoggedInUser(10, 'cust_a');

        // GET /account/recharge with parameters
        $reqRecharge = new Request(['amount' => '50.00', 'gateway' => 'manual_bkash'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge']);
        $respRecharge = $this->customerController->recharge($reqRecharge);
        $this->assertSame(200, $respRecharge->getStatusCode());

        // Balance must remain strictly unchanged
        $this->assertSame($initialBalance, $this->walletService->getAvailableBalance(10, 'BDT')->getAmount());

        // GET /account/withdraw with parameters
        $reqWithdraw = new Request(['amount' => '25.00', 'method' => 'bkash'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/withdraw']);
        $respWithdraw = $this->customerController->withdraw($reqWithdraw);
        $this->assertSame(200, $respWithdraw->getStatusCode());

        // Balance & holds must remain strictly unchanged
        $this->assertSame($initialBalance, $this->walletService->getAvailableBalance(10, 'BDT')->getAmount());
        $this->assertSame(0, $this->walletService->getHeldBalance(10)->getAmount());

        // Admin GET on approve must not change pending withdrawal
        $wd = $this->withdrawalService->createWithdrawal(
            10,
            Money::fromMajorString('20.00', 'BDT'),
            'bkash',
            ['account_number' => '01700000000']
        );

        $adminWdCtrl = new WithdrawalAdminController(
            $this->app,
            $this->withdrawalService,
            $this->walletService,
            $this->auditService
        );
        $this->setLoggedInUser(1, 'superadmin', ['super-admin']);

        $reqApproveGet = new Request(['action' => 'approve', 'withdrawal_id' => $wd->getId()], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-withdrawals']);
        $respApproveGet = $adminWdCtrl->handle($reqApproveGet);
        // GET returns HTML string (index page), does not approve
        $this->assertIsString($respApproveGet);

        // Verify withdrawal status remains strictly PENDING
        $reloadedWd = $this->withdrawalService->getWithdrawal($wd->getId());
        $this->assertSame(WithdrawalStatus::PENDING, $reloadedWd->getStatus());
    }

    /**
     * 10. Binance Pay Gateway Zero Secrets Leakage & Logging Sanitization.
     */
    public function testBinancePayGatewayZeroSecretsLeakage(): void
    {
        $binanceGateway = new BinancePayGateway([
            'api_key'        => 'binance-live-key-xyz123',
            'api_secret'     => 'super-secret-binance-key-shhh',
            'merchant_id'    => '100000001',
            'sandbox'        => true,
            'certificate_sn' => 'CERT-SN-999',
        ]);

        // 1. getPublicConfig() must NEVER expose api_secret
        $publicConfig = $binanceGateway->getPublicConfig();
        $this->assertArrayNotHasKey('api_secret', $publicConfig);
        $this->assertArrayNotHasKey('api_key', $publicConfig);
        $this->assertTrue($publicConfig['has_api_secret']);
        $this->assertSame('CERT-SN-999', $publicConfig['certificate_sn']);

        // 2. SafeLogger masking check
        $sensitiveData = [
            'username'     => 'merchant_user',
            'api_secret'   => 'super-secret-binance-key-shhh',
            'secret'       => 'confidential-token-abc',
            'bearer'       => 'bearer-jwt-token-xyz',
            'password'     => 'P@ssw0rd123!',
            'card_number'  => '4111111111111111',
            'safe_field'   => 'normal-value',
        ];

        $sanitized = SafeLogger::sanitize($sensitiveData);
        $this->assertSame('normal-value', $sanitized['safe_field']);
        $this->assertSame('[REDACTED]', $sanitized['api_secret']);
        $this->assertSame('[REDACTED]', $sanitized['secret']);
        $this->assertSame('[REDACTED]', $sanitized['bearer']);
        $this->assertSame('[REDACTED]', $sanitized['password']);
    }

    /**
     * 11. Withdrawal Concurrency & Double-Spend / Overdraft Protection.
     */
    public function testWithdrawalConcurrencyAndDoubleSpendProtection(): void
    {
        // Setup customer with exactly 100.00 BDT (10,000 cents)
        $this->walletService->deposit(10, Money::fromMajorString('100.00', 'BDT'), 'deposit', 'dep_100', null, 'Starting balance');
        $this->assertSame(10000, $this->walletService->getAvailableBalance(10, 'BDT')->getAmount());

        // First withdrawal for 60.00 BDT (6,000 cents)
        $wd1 = $this->withdrawalService->createWithdrawal(
            10,
            Money::fromMajorString('60.00', 'BDT'),
            'bkash',
            ['account_number' => '01700000000']
        );
        $this->assertInstanceOf(Withdrawal::class, $wd1);

        // Balance decrements by 6,000 cents to 4,000 cents available, Total balance remains 10,000 cents
        $this->assertSame(4000, $this->walletService->getBalance(10, 'BDT')->getAmount());
        $this->assertSame(10000, $this->walletService->getTotalBalance(10)->getAmount());
        $this->assertSame(6000, $this->walletService->getHeldBalance(10)->getAmount());
        $this->assertSame(4000, $this->walletService->getAvailableBalance(10, 'BDT')->getAmount());

        // Attempt second concurrent withdrawal for 60.00 BDT (exceeds available 4,000 cents)
        $caughtException = false;
        try {
            $this->withdrawalService->createWithdrawal(
                10,
                Money::fromMajorString('60.00', 'BDT'),
                'bkash',
                ['account_number' => '01700000000']
            );
        } catch (\Throwable $e) {
            $caughtException = true;
            $this->assertStringContainsString('Insufficient', $e->getMessage());
        }

        $this->assertTrue($caughtException, 'Second withdrawal exceeding available balance must fail');

        // Verify available balance remains strictly 40.00 BDT (4,000 cents) and hold remains 6,000 cents
        $this->assertSame(4000, $this->walletService->getAvailableBalance(10, 'BDT')->getAmount());
        $this->assertSame(6000, $this->walletService->getHeldBalance(10)->getAmount());
    }
}
