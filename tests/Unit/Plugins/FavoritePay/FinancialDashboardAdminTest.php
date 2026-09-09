<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Controllers\FinancialDashboardAdminController;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Domain\WithdrawalStatus;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
use FavoriteCMS\Pay\Permissions\PaymentPermission;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\NotificationService;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\WalletService;
use FavoriteCMS\Pay\Services\WithdrawalService;
use PHPUnit\Framework\TestCase;

if (!class_exists(\FavoriteCMS\Tests\Unit\Plugins\FavoritePay\FinancialDashboardTestUserStub::class)) {
    class FinancialDashboardTestUserStub extends User
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
    }
}

class FinancialDashboardAdminTest extends TestCase
{
    private Application $app;
    private WalletService $walletService;
    private WithdrawalService $withdrawalService;
    private PaymentService $paymentService;
    private CurrencyService $currencyService;
    private NotificationService $notificationService;
    private FinancialDashboardAdminController $controller;

    protected function setUp(): void
    {
        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);

        $this->app = new Application(dirname(__DIR__, 3));
        $this->currencyService = new CurrencyService();
        $this->walletService = new WalletService($this->currencyService, null);
        $this->notificationService = new NotificationService(null);
        $this->withdrawalService = new WithdrawalService(
            $this->walletService,
            $this->currencyService,
            null,
            $this->notificationService
        );
        $this->withdrawalService->updateSettings(['enabled' => true]);

        $registry = new GatewayRegistry();
        $manualBkash = new ManualBangladeshGateway(
            'manual_bkash',
            'bKash Manual Payment',
            PaymentMethodType::MANUAL_BKASH,
            [
                'channel'        => 'bkash',
                'account_number' => '01700000000',
                'account_name'   => 'Merchant Ltd',
            ]
        );
        $registry->register($manualBkash);

        $this->paymentService = new PaymentService($this->currencyService, $registry);

        $this->controller = new FinancialDashboardAdminController(
            $this->app,
            $this->walletService,
            $this->withdrawalService,
            $this->paymentService,
            $this->currencyService,
            null
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);
    }

    // =========================================================================
    // 1. AUTHENTICATION & PERMISSIONS
    // =========================================================================

    public function testUnauthenticatedUserRedirectedToLogin(): void
    {
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/login', $response->getHeaders()['Location'] ?? '');
    }

    public function testBannedUserDeniedAccess(): void
    {
        $_SESSION['auth_user_id'] = 99;
        $bannedUser = new FinancialDashboardTestUserStub(['id' => 99, 'status' => 'banned'], ['admin'], [PaymentPermission::VIEW]);
        $GLOBALS['_test_current_user'] = $bannedUser;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('banned or inactive', $response->getContent());
    }

    public function testUserWithoutViewPermissionDeniedAccess(): void
    {
        $_SESSION['auth_user_id'] = 5;
        $user = new FinancialDashboardTestUserStub(['id' => 5], ['subscriber'], []);
        $GLOBALS['_test_current_user'] = $user;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('do not have permission to view', $response->getContent());
    }

    public function testUserWithViewPermissionCanAccessDashboard(): void
    {
        $_SESSION['auth_user_id'] = 10;
        $user = new FinancialDashboardTestUserStub(['id' => 10], ['operator'], [PaymentPermission::VIEW]);
        $GLOBALS['_test_current_user'] = $user;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $response = $this->controller->handle($request);

        $this->assertIsString($response);
        $this->assertStringContainsString('Financial Dashboard', $response);
    }

    public function testUserWithViewWithdrawalsPermissionCanAccessDashboard(): void
    {
        $_SESSION['auth_user_id'] = 11;
        $user = new FinancialDashboardTestUserStub(['id' => 11], ['finance'], [PaymentPermission::VIEW_WITHDRAWALS]);
        $GLOBALS['_test_current_user'] = $user;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $response = $this->controller->handle($request);

        $this->assertIsString($response);
        $this->assertStringContainsString('Financial Dashboard', $response);
    }

    public function testSuperAdminAlwaysHasAccess(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $superAdmin = new FinancialDashboardTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $superAdmin;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $response = $this->controller->handle($request);

        $this->assertIsString($response);
        $this->assertStringContainsString('Financial Dashboard', $response);
    }

    // =========================================================================
    // 2. GLOBAL LIVE WALLET OVERVIEW (AUTHORITATIVE)
    // =========================================================================

    public function testGlobalWalletOverviewDisplaysAuthoritativeBalances(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $superAdmin = new FinancialDashboardTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $superAdmin;

        // User 101: 50,000 cents deposit, 10,000 cents hold -> available 40,000, total 50,000
        $this->walletService->deposit(101, Money::bdt(50000), 'INIT-1', 'Initial credit');
        $this->walletService->hold(101, Money::bdt(10000), 'HOLD-1');

        // User 102: 30,000 cents deposit -> available 30,000, total 30,000
        $this->walletService->deposit(102, Money::bdt(30000), 'INIT-2', 'Initial credit');

        // Authoritative query
        $overview = $this->walletService->getGlobalWalletOverview();
        $this->assertSame(2, $overview['total_wallets']);
        $this->assertSame(80000, $overview['total_balance']->getAmount());
        $this->assertSame(70000, $overview['available_balance']->getAmount());
        $this->assertSame(10000, $overview['held_balance']->getAmount());

        // Render dashboard and inspect contents
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $html = $this->controller->handle($request);

        $this->assertIsString($html);
        // Total: 800.00 BDT
        $this->assertStringContainsString('800.00', $html);
        // Available: 700.00 BDT
        $this->assertStringContainsString('700.00', $html);
        // Held: 100.00 BDT
        $this->assertStringContainsString('100.00', $html);
    }

    // =========================================================================
    // 3. ACTIONABLE WITHDRAWAL WORK QUEUE
    // =========================================================================

    public function testActionableWithdrawalQueueCountsAndHeldAmount(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $superAdmin = new FinancialDashboardTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $superAdmin;

        // Fund users and create withdrawals
        $this->walletService->deposit(201, Money::bdt(100000), 'F-1', 'Funding');
        $this->walletService->deposit(202, Money::bdt(100000), 'F-2', 'Funding');
        $this->walletService->deposit(203, Money::bdt(100000), 'F-3', 'Funding');

        // W1: Pending (amount 60,000)
        $w1 = $this->withdrawalService->createWithdrawal(201, Money::bdt(60000), 'bkash', ['account_number' => '01711111111']);
        // W2: Approved (amount 70,000)
        $w2 = $this->withdrawalService->createWithdrawal(202, Money::bdt(70000), 'nagad', ['account_number' => '01822222222']);
        $this->withdrawalService->approve($w2->getId(), 1, 'Approved by admin');
        // W3: Processing (amount 80,000)
        $w3 = $this->withdrawalService->createWithdrawal(203, Money::bdt(80000), 'bkash', ['account_number' => '01933333333']);
        $this->withdrawalService->approve($w3->getId(), 1, 'Approved');
        $this->withdrawalService->startProcessing($w3->getId(), 1, 'Sending funds');

        // In-memory queue calculation
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $html = $this->controller->handle($request);

        $this->assertIsString($html);
        $this->assertStringContainsString('Actionable Withdrawal Queue', $html);
        // Total held in queue: 600.00 + 700.00 + 800.00 = 2,100.00 BDT
        $this->assertStringContainsString('2,100.00', $html);
    }

    // =========================================================================
    // 4. PERIOD DATE FILTER ENGINE
    // =========================================================================

    public function testDateRangeParsingPresets(): void
    {
        // 1. Today
        $reqToday = new Request(['period' => 'today'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $rangeToday = $this->controller->parseDateRange($reqToday);
        $this->assertSame('today', $rangeToday['period']);
        $this->assertStringContainsString(date('Y-m-d 00:00:00'), $rangeToday['startDate']);
        $this->assertStringContainsString(date('Y-m-d 23:59:59'), $rangeToday['endDate']);

        // 2. Last 7 Days
        $req7 = new Request(['period' => 'last_7_days'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $range7 = $this->controller->parseDateRange($req7);
        $this->assertSame('last_7_days', $range7['period']);
        $this->assertSame(date('Y-m-d 00:00:00', strtotime('-6 days')), $range7['startDate']);
        $this->assertSame(date('Y-m-d 23:59:59'), $range7['endDate']);

        // 3. Last 30 Days
        $req30 = new Request(['period' => 'last_30_days'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $range30 = $this->controller->parseDateRange($req30);
        $this->assertSame('last_30_days', $range30['period']);
        $this->assertSame(date('Y-m-d 00:00:00', strtotime('-29 days')), $range30['startDate']);

        // 4. This Month
        $reqMonth = new Request(['period' => 'this_month'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $rangeMonth = $this->controller->parseDateRange($reqMonth);
        $this->assertSame('this_month', $rangeMonth['period']);
        $this->assertSame(date('Y-m-01 00:00:00'), $rangeMonth['startDate']);
        $this->assertSame(date('Y-m-t 23:59:59'), $rangeMonth['endDate']);

        // 5. Previous Month
        $reqPrev = new Request(['period' => 'previous_month'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $rangePrev = $this->controller->parseDateRange($reqPrev);
        $this->assertSame('previous_month', $rangePrev['period']);
        $this->assertSame(date('Y-m-01 00:00:00', strtotime('first day of last month')), $rangePrev['startDate']);
        $this->assertSame(date('Y-m-t 23:59:59', strtotime('last day of last month')), $rangePrev['endDate']);
    }

    public function testDateRangeParsingCustomBounds(): void
    {
        // Custom with both dates
        $reqCustom = new Request(['date_from' => '2026-05-01', 'date_to' => '2026-05-15'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $rangeCustom = $this->controller->parseDateRange($reqCustom);
        $this->assertSame('custom', $rangeCustom['period']);
        $this->assertSame('2026-05-01 00:00:00', $rangeCustom['startDate']);
        $this->assertSame('2026-05-15 23:59:59', $rangeCustom['endDate']);
        $this->assertSame('2026-05-01 to 2026-05-15', $rangeCustom['periodLabel']);

        // Single date custom range
        $reqSingle = new Request(['date_from' => '2026-06-10', 'date_to' => '2026-06-10'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $rangeSingle = $this->controller->parseDateRange($reqSingle);
        $this->assertSame('Date: 2026-06-10', $rangeSingle['periodLabel']);
        $this->assertSame('2026-06-10 00:00:00', $rangeSingle['startDate']);
        $this->assertSame('2026-06-10 23:59:59', $rangeSingle['endDate']);
    }

    // =========================================================================
    // 5. CUSTOMER WALLET SEARCH
    // =========================================================================

    public function testCustomerWalletSearchFindsUsers(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $superAdmin = new FinancialDashboardTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $superAdmin;

        // Initialize wallets for different users
        $this->walletService->deposit(301, Money::bdt(25000), 'B-301', 'Bonus');
        $this->walletService->deposit(302, Money::bdt(45000), 'B-302', 'Bonus');

        // Search by User ID
        $resId = $this->walletService->searchCustomerWallets('301');
        $this->assertNotEmpty($resId);
        $this->assertSame(301, $resId[0]['user_id']);

        // Search in Controller
        $reqSearch = new Request(['search' => '301'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard?search=301']);
        $html = $this->controller->handle($reqSearch);
        $this->assertIsString($html);
        $this->assertStringContainsString('#301', $html);
        $this->assertStringContainsString('250.00', $html);
    }

    public function testCustomerWalletSearchEmptyReturnsNoWalletsMatch(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $superAdmin = new FinancialDashboardTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $superAdmin;

        $reqSearch = new Request(['search' => 'non_existent_username_xyz'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard?search=non_existent_username_xyz']);
        $html = $this->controller->handle($reqSearch);
        $this->assertIsString($html);
        $this->assertStringContainsString('No customer wallets found', $html);
    }

    // =========================================================================
    // 6. CUSTOMER WALLET DETAIL VIEW
    // =========================================================================

    public function testCustomerWalletDetailViewRendersDossier(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $superAdmin = new FinancialDashboardTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $superAdmin;

        $targetUserId = 401;
        $this->walletService->deposit($targetUserId, Money::bdt(100000), 'DEP-401', 'Deposit');
        $this->walletService->hold($targetUserId, Money::bdt(20000), 'HOLD-401');

        $req = new Request(['action' => 'customer', 'user_id' => $targetUserId], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard?action=customer&user_id=' . $targetUserId]);
        $html = $this->controller->handle($req);

        $this->assertIsString($html);
        $this->assertStringContainsString('Customer Financial Dossier', $html);
        $this->assertStringContainsString('User #' . $targetUserId, $html);
        // Authoritative balance cards: Total 1,000.00 BDT, Available 800.00 BDT, Held 200.00 BDT
        $this->assertStringContainsString('1,000.00', $html);
        $this->assertStringContainsString('800.00', $html);
        $this->assertStringContainsString('200.00', $html);
        // Ledger entry reference
        $this->assertStringContainsString('HOLD-401', $html);
    }

    public function testCustomerWalletDetailViewMissingUserHandledGracefully(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $superAdmin = new FinancialDashboardTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $superAdmin;

        $req = new Request(['action' => 'customer', 'user_id' => 0], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard?action=customer&user_id=0']);
        $html = $this->controller->handle($req);

        // Should redirect back to dashboard index
        $this->assertInstanceOf(Response::class, $html);
        $this->assertSame(302, $html->getStatusCode());
        $this->assertSame('/admin/page/favorite-pay-dashboard', $html->getHeaders()['Location'] ?? '');
    }

    // =========================================================================
    // 7. READ-ONLY INVARIANT ASSURANCE
    // =========================================================================

    public function testDashboardExecutionIsStrictlyReadOnly(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $superAdmin = new FinancialDashboardTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $superAdmin;

        $userId = 501;
        $this->walletService->deposit($userId, Money::bdt(50000), 'INIT-501', 'Initial balance');

        $availBefore = $this->walletService->getAvailableBalance($userId)->getAmount();
        $heldBefore = $this->walletService->getHeldBalance($userId)->getAmount();
        $totalBefore = $this->walletService->getTotalBalance($userId)->getAmount();
        $ledgerBefore = $this->walletService->getLedgerHistory($userId);
        $overviewBefore = $this->walletService->getGlobalWalletOverview();

        // 1. Visit main dashboard
        $req1 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $this->controller->handle($req1);

        // 2. Visit dashboard with various period filters
        $req2 = new Request(['period' => 'last_7_days'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard?period=last_7_days']);
        $this->controller->handle($req2);

        // 3. Search wallets
        $req3 = new Request(['search' => '501'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard?search=501']);
        $this->controller->handle($req3);

        // 4. View customer detail
        $req4 = new Request(['action' => 'customer', 'user_id' => $userId], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard?action=customer&user_id=' . $userId]);
        $this->controller->handle($req4);

        // Verify zero state mutations
        $availAfter = $this->walletService->getAvailableBalance($userId)->getAmount();
        $heldAfter = $this->walletService->getHeldBalance($userId)->getAmount();
        $totalAfter = $this->walletService->getTotalBalance($userId)->getAmount();
        $ledgerAfter = $this->walletService->getLedgerHistory($userId);
        $overviewAfter = $this->walletService->getGlobalWalletOverview();

        $this->assertSame($availBefore, $availAfter);
        $this->assertSame($heldBefore, $heldAfter);
        $this->assertSame($totalBefore, $totalAfter);
        $this->assertCount(count($ledgerBefore), $ledgerAfter);
        $this->assertSame($overviewBefore['total_balance']->getAmount(), $overviewAfter['total_balance']->getAmount());
        $this->assertSame($overviewBefore['available_balance']->getAmount(), $overviewAfter['available_balance']->getAmount());
        $this->assertSame($overviewBefore['held_balance']->getAmount(), $overviewAfter['held_balance']->getAmount());
    }

    // =========================================================================
    // 8. FINANCIAL FLOW & METRIC SUMMARIES
    // =========================================================================

    public function testPeriodFinancialFlowCalculatesInflowOutflowNet(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $superAdmin = new FinancialDashboardTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $superAdmin;

        // 1. Inbound payment intent (succeeded)
        $intent = $this->paymentService->createIntent('recharge', 'RC-FLOW-1', Money::bdt(50000), ['user_id' => 601]);
        $attempt = $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_FLOW_1');
        $this->paymentService->approveManualPayment($attempt->getId(), 1, 'Approved');

        // 2. Withdrawal (paid)
        $this->walletService->deposit(601, Money::bdt(100000), 'DEP-FLOW-1', 'Funding');
        $w = $this->withdrawalService->createWithdrawal(601, Money::bdt(60000), 'bkash', ['account_number' => '01712345678']);
        $this->withdrawalService->approve($w->getId(), 1);
        $this->withdrawalService->startProcessing($w->getId(), 1);
        $this->withdrawalService->markPaid($w->getId(), 1, 'TXN_PAYOUT_1');

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $html = $this->controller->handle($req);

        $this->assertIsString($html);
        $this->assertStringContainsString('Financial Flow', $html);
        $this->assertStringContainsString('Recharge Volume (Inflow)', $html);
        $this->assertStringContainsString('Paid Withdrawal Volume (Outflow)', $html);
        $this->assertStringContainsString('Net Platform Movement', $html);
        // Recharge volume: 500.00 BDT
        $this->assertStringContainsString('500.00', $html);
    }

    public function testPeriodRechargeSummaryCountsAndAmounts(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $superAdmin = new FinancialDashboardTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $superAdmin;

        // Intent 1: Succeeded
        $i1 = $this->paymentService->createIntent('recharge', 'RC-S1', Money::bdt(20000), ['user_id' => 701]);
        $att1 = $this->paymentService->submitManualVerification($i1->getId(), 'manual_bkash', 'TRX_S1');
        $this->paymentService->approveManualPayment($att1->getId(), 1, 'Approved');

        // Intent 2: Pending
        $i2 = $this->paymentService->createIntent('recharge', 'RC-S2', Money::bdt(15000), ['user_id' => 702]);

        // Intent 3: Failed / Rejected
        $i3 = $this->paymentService->createIntent('recharge', 'RC-S3', Money::bdt(10000), ['user_id' => 703]);
        $att3 = $this->paymentService->submitManualVerification($i3->getId(), 'manual_bkash', 'TRX_F3');
        $this->paymentService->rejectManualPayment($att3->getId(), 1, 'Invalid TrxID');

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $html = $this->controller->handle($req);

        $this->assertIsString($html);
        $this->assertStringContainsString('Recharge & Payment Activity', $html);
        $this->assertStringContainsString('Total Credited Accounting Amount:', $html);
        // Succeeded amount: 200.00 BDT
        $this->assertStringContainsString('200.00', $html);
    }

    public function testPeriodWithdrawalBreakdownShowsAllStatuses(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $superAdmin = new FinancialDashboardTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $superAdmin;

        // Create withdrawals in various states
        $this->walletService->deposit(801, Money::bdt(200000), 'DEP-WD-1', 'Funding');
        $wPending = $this->withdrawalService->createWithdrawal(801, Money::bdt(50000), 'bkash', ['account_number' => '01700000001']);
        
        $wApproved = $this->withdrawalService->createWithdrawal(801, Money::bdt(50000), 'nagad', ['account_number' => '01800000002']);
        $this->withdrawalService->approve($wApproved->getId(), 1);

        $wRejected = $this->withdrawalService->createWithdrawal(801, Money::bdt(50000), 'bkash', ['account_number' => '01700000003']);
        $this->withdrawalService->reject($wRejected->getId(), 1, 'Fraud suspicion');

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $html = $this->controller->handle($req);

        $this->assertIsString($html);
        $this->assertStringContainsString('Withdrawal Activity Breakdown', $html);
        $this->assertStringContainsString('Total Gross Requested:', $html);
        $this->assertStringContainsString('Total Fees Recorded:', $html);
        $this->assertStringContainsString('Total Net Requested:', $html);
    }

    public function testCustomerWalletDetailWithRechargesAndWithdrawals(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $superAdmin = new FinancialDashboardTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $superAdmin;

        $targetUserId = 901;
        $this->walletService->deposit($targetUserId, Money::bdt(150000), 'INIT-901', 'Deposit');

        // Customer recharge intent
        $intent = $this->paymentService->createIntent('recharge', 'RC-901', Money::bdt(50000), ['user_id' => $targetUserId]);

        // Customer withdrawal
        $w = $this->withdrawalService->createWithdrawal($targetUserId, Money::bdt(60000), 'bkash', ['account_number' => '01799999999']);

        $req = new Request(['action' => 'customer', 'user_id' => $targetUserId], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard?action=customer&user_id=' . $targetUserId]);
        $html = $this->controller->handle($req);

        $this->assertIsString($html);
        $this->assertStringContainsString('Customer Financial Dossier', $html);
        $this->assertStringContainsString('Customer Recharges & Inbound Payments', $html);
        $this->assertStringContainsString('Customer Withdrawal Requests', $html);
        $this->assertStringContainsString('017****9999', $html);
    }

    public function testPaymentOnlyUserCannotSeeWithdrawalDataOnDashboardOrCustomerDossier(): void
    {
        $_SESSION['auth_user_id'] = 10;
        $paymentOnlyUser = new FinancialDashboardTestUserStub(['id' => 10], ['operator'], [PaymentPermission::VIEW]);
        $GLOBALS['_test_current_user'] = $paymentOnlyUser;

        // Populate withdrawal and recharge data
        $this->walletService->deposit(902, Money::bdt(200000), 'DEP-902', 'Deposit');
        $this->withdrawalService->createWithdrawal(902, Money::bdt(50000), 'bkash', ['account_number' => '01700000000']);
        $intent = $this->paymentService->createIntent('recharge', 'RC-902', Money::bdt(30000), ['user_id' => 902]);
        $att = $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_902');
        $this->paymentService->approveManualPayment($att->getId(), 1, 'OK');

        // 1. Dashboard View
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $html = $this->controller->handle($req);

        $this->assertIsString($html);
        // Can see payments
        $this->assertStringContainsString('Recharge & Payment Activity', $html);
        // CANNOT see withdrawal queue or breakdown
        $this->assertStringNotContainsString('Actionable Withdrawal Queue', $html);
        $this->assertStringNotContainsString('Withdrawal Activity Breakdown', $html);
        $this->assertStringContainsString('Requires withdrawal view permission', $html);

        // 2. Customer Dossier View
        $reqCustomer = new Request(['action' => 'customer', 'user_id' => 902], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard?action=customer&user_id=902']);
        $htmlCustomer = $this->controller->handle($reqCustomer);

        $this->assertIsString($htmlCustomer);
        $this->assertStringContainsString('Customer Financial Dossier', $htmlCustomer);
        $this->assertStringContainsString('Customer Recharges & Inbound Payments', $htmlCustomer);
        // CANNOT see withdrawals section or filter button
        $this->assertStringNotContainsString('Customer Withdrawal Requests', $htmlCustomer);
        $this->assertStringNotContainsString('Filter Withdrawals Queue', $htmlCustomer);
    }

    public function testWithdrawalOnlyUserCannotSeePaymentDataOnDashboardOrCustomerDossier(): void
    {
        $_SESSION['auth_user_id'] = 11;
        $withdrawalOnlyUser = new FinancialDashboardTestUserStub(['id' => 11], ['finance'], [PaymentPermission::VIEW_WITHDRAWALS]);
        $GLOBALS['_test_current_user'] = $withdrawalOnlyUser;

        // Populate withdrawal and recharge data
        $this->walletService->deposit(903, Money::bdt(200000), 'DEP-903', 'Deposit');
        $this->withdrawalService->createWithdrawal(903, Money::bdt(50000), 'bkash', ['account_number' => '01700000000']);
        $intent = $this->paymentService->createIntent('recharge', 'RC-903', Money::bdt(30000), ['user_id' => 903]);
        $att = $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_903');
        $this->paymentService->approveManualPayment($att->getId(), 1, 'OK');

        // 1. Dashboard View
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $html = $this->controller->handle($req);

        $this->assertIsString($html);
        // Can see withdrawal queue and breakdown
        $this->assertStringContainsString('Actionable Withdrawal Queue', $html);
        $this->assertStringContainsString('Withdrawal Activity Breakdown', $html);
        // CANNOT see payment activity
        $this->assertStringNotContainsString('Recharge & Payment Activity', $html);
        $this->assertStringContainsString('Requires payment view permission', $html);

        // 2. Customer Dossier View
        $reqCustomer = new Request(['action' => 'customer', 'user_id' => 903], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard?action=customer&user_id=903']);
        $htmlCustomer = $this->controller->handle($reqCustomer);

        $this->assertIsString($htmlCustomer);
        $this->assertStringContainsString('Customer Financial Dossier', $htmlCustomer);
        $this->assertStringContainsString('Customer Withdrawal Requests', $htmlCustomer);
        $this->assertStringContainsString('Filter Withdrawals Queue', $htmlCustomer);
        // CANNOT see recharges section
        $this->assertStringNotContainsString('Customer Recharges & Inbound Payments', $htmlCustomer);
    }

    public function testDualPermissionUserSeesAllSections(): void
    {
        $_SESSION['auth_user_id'] = 12;
        $dualUser = new FinancialDashboardTestUserStub(['id' => 12], ['auditor'], [
            PaymentPermission::VIEW,
            PaymentPermission::VIEW_WITHDRAWALS,
        ]);
        $GLOBALS['_test_current_user'] = $dualUser;

        // Populate withdrawal and recharge data
        $this->walletService->deposit(904, Money::bdt(200000), 'DEP-904', 'Deposit');
        $this->withdrawalService->createWithdrawal(904, Money::bdt(50000), 'bkash', ['account_number' => '01700000000']);
        $intent = $this->paymentService->createIntent('recharge', 'RC-904', Money::bdt(30000), ['user_id' => 904]);
        $att = $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_904');
        $this->paymentService->approveManualPayment($att->getId(), 1, 'OK');

        // 1. Dashboard View
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard']);
        $html = $this->controller->handle($req);

        $this->assertIsString($html);
        $this->assertStringContainsString('Actionable Withdrawal Queue', $html);
        $this->assertStringContainsString('Withdrawal Activity Breakdown', $html);
        $this->assertStringContainsString('Recharge & Payment Activity', $html);

        // 2. Customer Dossier View
        $reqCustomer = new Request(['action' => 'customer', 'user_id' => 904], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-dashboard?action=customer&user_id=904']);
        $htmlCustomer = $this->controller->handle($reqCustomer);

        $this->assertIsString($htmlCustomer);
        $this->assertStringContainsString('Customer Financial Dossier', $htmlCustomer);
        $this->assertStringContainsString('Customer Withdrawal Requests', $htmlCustomer);
        $this->assertStringContainsString('Customer Recharges & Inbound Payments', $htmlCustomer);
    }
}

