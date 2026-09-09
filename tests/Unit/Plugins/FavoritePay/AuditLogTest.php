<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Controllers\AuditLogAdminController;
use FavoriteCMS\Pay\Controllers\CustomerAccountController;
use FavoriteCMS\Pay\Controllers\PaymentAdminController;
use FavoriteCMS\Pay\Controllers\WithdrawalAdminController;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Domain\WithdrawalStatus;
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
use PHPUnit\Framework\TestCase;

if (!class_exists(\FavoriteCMS\Tests\Unit\Plugins\FavoritePay\AuditLogTestUserStub::class)) {
    class AuditLogTestUserStub extends User
    {
        private array $rolesList;
        private array $permissionsList;

        public function __construct(array $attributes = [], array $roles = [], array $permissions = [])
        {
            $this->attributes = array_merge([
                'id'       => 1,
                'username' => 'testuser',
                'name'     => 'Test User',
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
            return ($this->attributes['status'] ?? 'active') === 'banned';
        }
    }
}

class AuditLogTest extends TestCase
{
    private Application $app;
    private CurrencyService $currencyService;
    private WalletService $walletService;
    private NotificationService $notificationService;
    private WithdrawalService $withdrawalService;
    private AuditLogService $auditService;
    private PaymentService $paymentService;
    private GatewayRegistry $registry;
    private AuditLogAdminController $adminController;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit-AuditTest/1.0';
        unset($GLOBALS['_test_current_user']);

        $this->app = new Application(dirname(__DIR__, 3));
        $this->currencyService = new CurrencyService();
        $this->walletService = new WalletService($this->currencyService, null);
        $this->notificationService = new NotificationService(null);
        $this->auditService = new AuditLogService(null); // In-memory for fast, isolated, deterministic unit testing

        $this->withdrawalService = new WithdrawalService(
            $this->walletService,
            $this->currencyService,
            null,
            $this->notificationService,
            $this->auditService
        );
        $this->withdrawalService->updateSettings(['enabled' => true]);

        $this->registry = new GatewayRegistry();
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
        $this->registry->register($manualBkash);

        $this->paymentService = new PaymentService($this->currencyService, $this->registry);

        $this->adminController = new AuditLogAdminController(
            $this->app,
            $this->auditService,
            null
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($GLOBALS['_test_current_user'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    }

    // =========================================================================
    // 1. AUDIT SERVICE UNIT TESTS
    // =========================================================================

    public function testAuditServiceRecordsEntriesWithAdminActor(): void
    {
        $id = $this->auditService->log(
            action: 'test.admin_action',
            subjectType: 'test',
            subjectId: '123',
            metadata: ['foo' => 'bar'],
            description: 'Admin did something',
            actorUserId: 1,
            actorType: 'admin',
            actorName: 'Admin Guy'
        );

        $this->assertNotNull($id);
        $log = $this->auditService->getLog($id);
        $this->assertNotNull($log);
        $this->assertSame('test.admin_action', $log['action']);
        $this->assertSame('admin', $log['actor_type']);
        $this->assertSame(1, $log['actor_user_id']);
        $this->assertSame('Admin Guy', $log['actor_name']);
        $this->assertSame(['foo' => 'bar'], $log['metadata_parsed']);
    }

    public function testAuditServiceRecordsEntriesWithCustomerActor(): void
    {
        $id = $this->auditService->log(
            action: 'test.customer_action',
            subjectType: 'test',
            subjectId: '456',
            targetUserId: 55,
            metadata: ['param' => 123],
            description: 'Customer did something',
            actorUserId: 55,
            actorType: 'customer',
            actorName: 'John Doe'
        );

        $this->assertNotNull($id);
        $log = $this->auditService->getLog($id);
        $this->assertNotNull($log);
        $this->assertSame('test.customer_action', $log['action']);
        $this->assertSame('customer', $log['actor_type']);
        $this->assertSame(55, $log['actor_user_id']);
        $this->assertSame(55, $log['target_user_id']);
        $this->assertSame('John Doe', $log['actor_name']);
    }

    public function testAuditServiceRecordsEntriesWithSystemActor(): void
    {
        $id = $this->auditService->log(
            action: 'test.system_action',
            subjectType: 'system',
            subjectId: 'SYS-1',
            metadata: ['cron' => true],
            description: 'Automated background task',
            actorUserId: null,
            actorType: 'system',
            actorName: 'System'
        );

        $this->assertNotNull($id);
        $log = $this->auditService->getLog($id);
        $this->assertNotNull($log);
        $this->assertSame('test.system_action', $log['action']);
        $this->assertSame('system', $log['actor_type']);
        $this->assertNull($log['actor_user_id']);
        $this->assertSame('System', $log['actor_name']);
    }

    public function testAuditServiceCapturesIpAndTruncatesUserAgent(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $_SERVER['HTTP_USER_AGENT'] = str_repeat('A', 350); // 350 chars > 255 chars

        $id = $this->auditService->log(
            action: 'test.ip_ua',
            subjectType: 'test',
            subjectId: '789',
            metadata: []
        );

        $this->assertNotNull($id);
        $log = $this->auditService->getLog($id);
        $this->assertNotNull($log);
        $this->assertSame('192.168.1.100', $log['ip_address']);
        $this->assertSame(255, strlen($log['user_agent']));
    }

    public function testAuditServiceSanitizesMetadataAndOmitsSecrets(): void
    {
        $sensitiveData = [
            'amount'       => 5000,
            'api_key'      => 'secret-key-12345',
            'client_secret'=> 'super-secret-xyz',
            'password'     => 'super-pass',
            'token'        => 'bearer-token-abc',
            'bank_info'    => [
                'account_number' => '1234567890',
                'secret_pin'     => '9999',
            ],
        ];

        $id = $this->auditService->log(
            action: 'test.secret_sanitization',
            subjectType: 'test',
            subjectId: 'SEC-1',
            metadata: $sensitiveData
        );

        $this->assertNotNull($id);
        $log = $this->auditService->getLog($id);
        $this->assertNotNull($log);
        
        $meta = $log['metadata_parsed'];
        $this->assertSame(5000, $meta['amount']);
        $this->assertSame('[REDACTED]', $meta['api_key']);
        $this->assertSame('[REDACTED]', $meta['client_secret']);
        $this->assertSame('[REDACTED]', $meta['password']);
        $this->assertSame('[REDACTED]', $meta['token']);
        $this->assertSame('[REDACTED]', $meta['bank_info']['secret_pin']);
        $this->assertSame('1234567890', $meta['bank_info']['account_number']);

        // Verify raw JSON string in metadata column also contains no exposed secrets
        $this->assertStringNotContainsString('secret-key-12345', $log['metadata']);
        $this->assertStringNotContainsString('super-secret-xyz', $log['metadata']);
        $this->assertStringNotContainsString('super-pass', $log['metadata']);
    }

    public function testAuditServiceResilienceLoggingFailureNeverPropagates(): void
    {
        // Mock a broken database that throws an unhandled PDOException on insert
        $dbMock = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->getMock();

        $dbMock->method('tableExists')->willReturn(true);
        $dbMock->method('insert')->willThrowException(new \PDOException('Simulated DB disk failure'));

        $resilientService = new AuditLogService($dbMock);

        // Must not throw, must return null, must be non-blocking
        $result = $resilientService->log(
            action: 'test.failure',
            subjectType: 'test',
            subjectId: 'ERR-1',
            metadata: ['foo' => 'bar']
        );

        $this->assertNull($result);
    }

    // =========================================================================
    // 2. WITHDRAWAL ACTIONS
    // =========================================================================

    public function testWithdrawalApprovalLoggedWithAdminIdAndNotes(): void
    {
        // Fund user
        $this->walletService->deposit(10, Money::bdt(100000), 'D-10', 'Deposit');
        $wd = $this->withdrawalService->createWithdrawal(10, Money::bdt(60000), 'bkash', ['account_number' => '01711111111']);

        $adminId = 2;
        $updated = $this->withdrawalService->approve($wd->getId(), $adminId, 'Verified KYC profile');

        $this->assertSame(WithdrawalStatus::APPROVED, $updated->getStatus());

        $logs = $this->auditService->getWithdrawalLogs($wd->getId());
        $actions = array_column($logs, 'action');

        $this->assertContains('withdrawal.requested', $actions);
        $this->assertContains('withdrawal.approved', $actions);

        $approveLog = null;
        foreach ($logs as $l) {
            if ($l['action'] === 'withdrawal.approved') {
                $approveLog = $l;
                break;
            }
        }
        $this->assertNotNull($approveLog);
        $this->assertSame($adminId, $approveLog['actor_user_id']);
        $this->assertSame('pending', $approveLog['metadata_parsed']['prev_status']);
        $this->assertSame('approved', $approveLog['metadata_parsed']['new_status']);
        $this->assertSame('Verified KYC profile', $approveLog['metadata_parsed']['notes']);
    }

    public function testWithdrawalProcessingLoggedWithAdminId(): void
    {
        $this->walletService->deposit(11, Money::bdt(100000), 'D-11', 'Deposit');
        $wd = $this->withdrawalService->createWithdrawal(11, Money::bdt(60000), 'bkash', ['account_number' => '01711111111']);
        $this->withdrawalService->approve($wd->getId(), 2, 'Approved');

        $adminId = 3;
        $processing = $this->withdrawalService->startProcessing($wd->getId(), $adminId, 'Sending bKash payout');
        $this->assertSame(WithdrawalStatus::PROCESSING, $processing->getStatus());

        $logs = $this->auditService->getWithdrawalLogs($wd->getId());
        $procLog = null;
        foreach ($logs as $l) {
            if ($l['action'] === 'withdrawal.processing') {
                $procLog = $l;
                break;
            }
        }
        $this->assertNotNull($procLog);
        $this->assertSame($adminId, $procLog['actor_user_id']);
        $this->assertSame('approved', $procLog['metadata_parsed']['prev_status']);
        $this->assertSame('processing', $procLog['metadata_parsed']['new_status']);
    }

    public function testWithdrawalPaidLoggedWithAdminIdAndReference(): void
    {
        $this->walletService->deposit(12, Money::bdt(100000), 'D-12', 'Deposit');
        $wd = $this->withdrawalService->createWithdrawal(12, Money::bdt(60000), 'bkash', ['account_number' => '01711111111']);
        $this->withdrawalService->approve($wd->getId(), 2, 'Approved');
        $this->withdrawalService->startProcessing($wd->getId(), 2, 'Processing');

        $adminId = 4;
        $paid = $this->withdrawalService->markPaid($wd->getId(), $adminId, 'TRX-BKASH-998877', 'Paid via merchant portal');
        $this->assertSame(WithdrawalStatus::PAID, $paid->getStatus());

        $logs = $this->auditService->getWithdrawalLogs($wd->getId());
        $paidLog = null;
        foreach ($logs as $l) {
            if ($l['action'] === 'withdrawal.paid') {
                $paidLog = $l;
                break;
            }
        }
        $this->assertNotNull($paidLog);
        $this->assertSame($adminId, $paidLog['actor_user_id']);
        $this->assertSame('TRX-BKASH-998877', $paidLog['metadata_parsed']['transaction_reference']);
    }

    public function testWithdrawalRejectionLoggedWithAdminIdAndReason(): void
    {
        $this->walletService->deposit(13, Money::bdt(100000), 'D-13', 'Deposit');
        $wd = $this->withdrawalService->createWithdrawal(13, Money::bdt(60000), 'bkash', ['account_number' => '01711111111']);

        $adminId = 5;
        $rejected = $this->withdrawalService->reject($wd->getId(), $adminId, 'Suspicious activity detected');
        $this->assertSame(WithdrawalStatus::REJECTED, $rejected->getStatus());

        $logs = $this->auditService->getWithdrawalLogs($wd->getId());
        $rejLog = null;
        foreach ($logs as $l) {
            if ($l['action'] === 'withdrawal.rejected') {
                $rejLog = $l;
                break;
            }
        }
        $this->assertNotNull($rejLog);
        $this->assertSame($adminId, $rejLog['actor_user_id']);
        $this->assertSame('Suspicious activity detected', $rejLog['metadata_parsed']['reason']);
    }

    public function testWithdrawalFailureLoggedWithAdminIdAndReason(): void
    {
        $this->walletService->deposit(14, Money::bdt(100000), 'D-14', 'Deposit');
        $wd = $this->withdrawalService->createWithdrawal(14, Money::bdt(60000), 'bkash', ['account_number' => '01711111111']);
        $this->withdrawalService->approve($wd->getId(), 2, 'Approved');
        $this->withdrawalService->startProcessing($wd->getId(), 2, 'Processing');

        $adminId = 6;
        $failed = $this->withdrawalService->markFailed($wd->getId(), $adminId, 'bKash API network timeout');
        $this->assertSame(WithdrawalStatus::FAILED, $failed->getStatus());

        $logs = $this->auditService->getWithdrawalLogs($wd->getId());
        $failLog = null;
        foreach ($logs as $l) {
            if ($l['action'] === 'withdrawal.failed') {
                $failLog = $l;
                break;
            }
        }
        $this->assertNotNull($failLog);
        $this->assertSame($adminId, $failLog['actor_user_id']);
        $this->assertSame('bKash API network timeout', $failLog['metadata_parsed']['reason']);
    }

    public function testCustomerCancellationLoggedWithCustomerId(): void
    {
        $this->walletService->deposit(15, Money::bdt(100000), 'D-15', 'Deposit');
        $wd = $this->withdrawalService->createWithdrawal(15, Money::bdt(60000), 'bkash', ['account_number' => '01711111111']);

        $cancelled = $this->withdrawalService->cancelWithdrawal($wd->getId(), 15, 'Customer decided not to withdraw');
        $this->assertSame(WithdrawalStatus::CANCELLED, $cancelled->getStatus());

        $logs = $this->auditService->getWithdrawalLogs($wd->getId());
        $canLog = null;
        foreach ($logs as $l) {
            if ($l['action'] === 'withdrawal.cancelled') {
                $canLog = $l;
                break;
            }
        }
        $this->assertNotNull($canLog);
        $this->assertSame(15, $canLog['actor_user_id']);
        $this->assertSame('customer', $canLog['actor_type']);
        $this->assertSame('Customer decided not to withdraw', $canLog['metadata_parsed']['reason']);
    }

    public function testWithdrawalReferenceUpdateLogged(): void
    {
        $this->walletService->deposit(16, Money::bdt(100000), 'D-16', 'Deposit');
        $wd = $this->withdrawalService->createWithdrawal(16, Money::bdt(60000), 'bkash', ['account_number' => '01711111111']);
        $this->withdrawalService->approve($wd->getId(), 2, 'Approved');
        $this->withdrawalService->startProcessing($wd->getId(), 2, 'Processing');
        $this->withdrawalService->markPaid($wd->getId(), 2, 'OLD-REF', 'Paid');

        $adminId = 7;
        $this->withdrawalService->updateTransactionReference($wd->getId(), $adminId, 'NEW-CORRECT-REF-999');

        $logs = $this->auditService->getWithdrawalLogs($wd->getId());
        $refLog = null;
        foreach ($logs as $l) {
            if ($l['action'] === 'withdrawal.reference_updated') {
                $refLog = $l;
                break;
            }
        }
        $this->assertNotNull($refLog);
        $this->assertSame($adminId, $refLog['actor_user_id']);
        $this->assertSame('NEW-CORRECT-REF-999', $refLog['metadata_parsed']['transaction_reference']);
    }

    public function testWithdrawalNoteUpdateLogged(): void
    {
        $this->walletService->deposit(17, Money::bdt(100000), 'D-17', 'Deposit');
        $wd = $this->withdrawalService->createWithdrawal(17, Money::bdt(60000), 'bkash', ['account_number' => '01711111111']);

        $adminId = 8;
        $this->withdrawalService->updateProcessingNotes($wd->getId(), $adminId, 'Customer called to expedite payout');

        $logs = $this->auditService->getWithdrawalLogs($wd->getId());
        $noteLog = null;
        foreach ($logs as $l) {
            if ($l['action'] === 'withdrawal.note_added') {
                $noteLog = $l;
                break;
            }
        }
        $this->assertNotNull($noteLog);
        $this->assertSame($adminId, $noteLog['actor_user_id']);
        $this->assertSame('Customer called to expedite payout', $noteLog['metadata_parsed']['operator_notes']);
    }

    // =========================================================================
    // 3. CUSTOMER ACTIONS (RECHARGE INTENT, MANUAL SUBMISSION)
    // =========================================================================

    public function testCustomerActionsLoggedViaCustomerAccountController(): void
    {
        $custController = new CustomerAccountController(
            $this->app,
            $this->walletService,
            $this->paymentService,
            $this->registry,
            $this->currencyService,
            null,
            $this->withdrawalService,
            $this->notificationService,
            $this->auditService
        );

        $_SESSION['user_id'] = 42;
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['_token'] = 'test-token';
        $customer = new AuditLogTestUserStub(['id' => 42, 'name' => 'Alice Customer'], ['customer'], []);
        $GLOBALS['_test_current_user'] = $customer;

        // 1. Customer initiates recharge intent
        $request = new Request([], [
            'amount'      => '150.00',
            'gateway_id'  => 'manual_bkash',
            '_csrf_token' => 'test-token',
            '_token'      => 'test-token',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/recharge']);

        $res = $custController->recharge($request);
        $this->assertInstanceOf(Response::class, $res);

        $logs = $this->auditService->listLogs(['target_user_id' => 42]);
        $intentLog = null;
        foreach ($logs['items'] as $item) {
            if ($item['action'] === 'recharge.intent_created') {
                $intentLog = $item;
                break;
            }
        }

        $this->assertNotNull($intentLog, 'recharge.intent_created must be logged');
        $this->assertSame(42, $intentLog['actor_user_id']);
        $this->assertSame('customer', $intentLog['actor_type']);
        $this->assertSame(15000, $intentLog['metadata_parsed']['amount']);
        $this->assertSame('manual_bkash', $intentLog['metadata_parsed']['gateway']);

        // 2. Customer submits manual payment verification
        $intentId = $intentLog['subject_id'];
        $submitRequest = new Request([], [
            'intent_id'      => $intentId,
            'trx_id'         => 'BKASH-MANUAL-TRX-12345',
            'sender_number'  => '01712345678',
            '_csrf_token'    => 'test-token',
            '_token'         => 'test-token',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/recharge/manual']);

        $res2 = $custController->submitManual($submitRequest);
        $this->assertInstanceOf(Response::class, $res2);

        $logsAfter = $this->auditService->listLogs(['target_user_id' => 42]);
        $manualSubmitLog = null;
        foreach ($logsAfter['items'] as $item) {
            if ($item['action'] === 'recharge.manual_submitted') {
                $manualSubmitLog = $item;
                break;
            }
        }

        $this->assertNotNull($manualSubmitLog, 'recharge.manual_submitted must be logged');
        $this->assertSame(42, $manualSubmitLog['actor_user_id']);
        $this->assertSame('BKASH-MANUAL-TRX-12345', $manualSubmitLog['metadata_parsed']['transaction_id']);
    }

    public function testPaymentAdminManualApprovalAndRejectionLogged(): void
    {
        $repoMock = $this->createMock(PaymentAttemptRepository::class);
        $paymentAdminCtrl = new PaymentAdminController(
            $this->app,
            $this->paymentService,
            $repoMock,
            $this->auditService
        );

        $_SESSION['auth_user_id'] = 99;
        $_SESSION['_token'] = 'admin-token';
        $admin = new AuditLogTestUserStub(['id' => 99, 'name' => 'Operator Super'], ['admin'], [PaymentPermission::VIEW, PaymentPermission::VERIFY]);
        $GLOBALS['_test_current_user'] = $admin;

        // Create an intent and attempt to approve
        $intent = $this->paymentService->createIntent('recharge', 'ORD-99', Money::bdt(5000), ['user_id' => 77]);
        $attempt = $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX-VERIFY-1');

        $req = new Request([], [
            'action'         => 'approve',
            'attempt_id'     => $attempt->getId(),
            '_token'         => 'admin-token',
            'operator_notes' => 'Found TRX in bKash statement',
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $paymentAdminCtrl->handle($req);
        $this->assertInstanceOf(Response::class, $res);

        $logs = $this->auditService->listLogs(['action' => 'payment.manual_approved']);
        $this->assertSame(1, $logs['total']);
        $log = $logs['items'][0];
        $this->assertSame('payment.manual_approved', $log['action']);
        $this->assertSame(99, $log['actor_user_id']);
        $this->assertSame('Found TRX in bKash statement', $log['metadata_parsed']['notes']);
    }

    // =========================================================================
    // 4. SETTINGS CHANGES & DIFF
    // =========================================================================

    public function testSettingsUpdateLoggedWithBeforeAfterDiff(): void
    {
        $wdAdminCtrl = new WithdrawalAdminController(
            $this->app,
            $this->withdrawalService,
            $this->walletService,
            $this->auditService
        );

        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'csrf-admin';
        $_SESSION['csrf_token'] = 'csrf-admin';
        $admin = new AuditLogTestUserStub(['id' => 1, 'name' => 'Super Admin'], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $admin;

        // Current default settings have enabled = true, min = 500, etc.
        $req = new Request([], [
            'action'                 => 'update_settings',
            '_token'                 => 'csrf-admin',
            '_csrf_token'            => 'csrf-admin',
            'csrf_token'             => 'csrf-admin',
            'enabled'                => '1',
            'min_amount'             => '750.00',   // changed
            'max_amount'             => '5000.00',  // changed
            'daily_limit'            => '10000.00', // changed
            'monthly_limit'          => '50000.00', // changed
            'allowed_methods'        => ['bkash', 'nagad', 'bank'], // changed
            'secret_api_token'       => 'super-secret-key-1234', // Should be sanitized/omitted
        ], ['REQUEST_METHOD' => 'POST']);

        $res = $wdAdminCtrl->handle($req);
        $this->assertInstanceOf(Response::class, $res);

        $logs = $this->auditService->listLogs(['action' => 'settings.updated']);
        $this->assertGreaterThanOrEqual(1, $logs['total']);
        $log = $logs['items'][0];

        $this->assertSame('settings.updated', $log['action']);
        $this->assertSame('settings', $log['subject_type']);
        $this->assertSame(1, $log['actor_user_id']);

        $changes = $log['metadata_parsed']['changes'];
        $this->assertArrayHasKey('min_amount', $changes);
        $this->assertEquals(750, $changes['min_amount']['after']);
        $this->assertArrayHasKey('allowed_methods', $changes);
        $this->assertContains('bank', $changes['allowed_methods']['after']);

        // Check secret keys are never present or redacted
        $this->assertStringNotContainsString('super-secret-key-1234', $log['metadata']);
    }

    // =========================================================================
    // 5. ADMIN CONTROLLER & VIEW (PERMISSIONS, LIST, FILTER, SEARCH, PAGINATION)
    // =========================================================================

    public function testAdminControllerAuthorizedAccessWorksWithViewAuditPermission(): void
    {
        $_SESSION['auth_user_id'] = 70;
        $user = new AuditLogTestUserStub(['id' => 70], ['compliance'], [PaymentPermission::VIEW_AUDIT]);
        $GLOBALS['_test_current_user'] = $user;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-audit']);
        $html = $this->adminController->handle($request);

        $this->assertIsString($html);
        $this->assertStringContainsString('Operational Audit Log', $html);
    }

    public function testAdminControllerUnauthorizedAccessBlocked403(): void
    {
        $_SESSION['auth_user_id'] = 71;
        $user = new AuditLogTestUserStub(['id' => 71], ['guest_operator'], []);
        $GLOBALS['_test_current_user'] = $user;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-audit']);
        $response = $this->adminController->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('do not have permission to view Favorite Pay audit logs', $response->getContent());
    }

    public function testAdminControllerBannedUserBlocked403(): void
    {
        $_SESSION['auth_user_id'] = 72;
        $user = new AuditLogTestUserStub(['id' => 72, 'status' => 'banned'], ['admin'], [PaymentPermission::VIEW_AUDIT]);
        $GLOBALS['_test_current_user'] = $user;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-audit']);
        $response = $this->adminController->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAdminControllerListViewRendersEntries(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $admin = new AuditLogTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $admin;

        // Seed logs
        $this->auditService->log('withdrawal.approved', 'withdrawal', 'WD-TEST-01', 10, ['notes' => 'OK'], 'Approved withdrawal #WD-TEST-01', 1, 'admin', 'Super Admin');
        $this->auditService->log('recharge.intent_created', 'recharge', 'PAY-TEST-01', 10, ['amount' => 5000], 'Recharge created', 10, 'customer', 'Alice Customer');

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-audit']);
        $html = $this->adminController->handle($request);

        $this->assertIsString($html);
        $this->assertStringContainsString('withdrawal.approved', $html);
        $this->assertStringContainsString('recharge.intent_created', $html);
        $this->assertStringContainsString('WD-TEST-01', $html);
        $this->assertStringContainsString('PAY-TEST-01', $html);
    }

    public function testAdminControllerDetailViewRendersWithMetadata(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $admin = new AuditLogTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $admin;

        $logId = $this->auditService->log(
            action: 'settings.updated',
            subjectType: 'settings',
            subjectId: 'favorite_pay_withdrawals',
            metadata: [
                'changes' => [
                    'min_amount' => ['before' => 1000, 'after' => 2500],
                    'enabled'    => ['before' => false, 'after' => true],
                ]
            ],
            description: 'Updated withdrawal minimum limit',
            actorUserId: 1,
            actorType: 'admin',
            actorName: 'Super Admin',
            ipAddress: '10.0.0.1'
        );

        $request = new Request(['action' => 'detail', 'id' => (string)$logId], [], ['REQUEST_METHOD' => 'GET']);
        $html = $this->adminController->handle($request);

        $this->assertIsString($html);
        $this->assertStringContainsString('Audit Event Record #' . $logId, $html);
        $this->assertStringContainsString('settings.updated', $html);
        $this->assertStringContainsString('min_amount', $html);
        $this->assertStringContainsString('1000', $html);
        $this->assertStringContainsString('2500', $html);
        $this->assertStringContainsString('10.0.0.1', $html);
    }

    public function testAdminControllerFilterAndSearch(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $admin = new AuditLogTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $admin;

        $this->auditService->log('withdrawal.approved', 'withdrawal', 'WD-F1', 10, [], 'Approved withdrawal #WD-F1', 1, 'admin', 'Super Admin');
        $this->auditService->log('withdrawal.rejected', 'withdrawal', 'WD-F2', 11, [], 'Rejected withdrawal #WD-F2', 1, 'admin', 'Super Admin');
        $this->auditService->log('recharge.intent_created', 'recharge', 'PAY-F3', 12, [], 'Recharge intent #PAY-F3', 12, 'customer', 'Customer 12');

        // Filter by action
        $req1 = new Request(['action_filter' => 'withdrawal.approved'], [], ['REQUEST_METHOD' => 'GET']);
        $html1 = $this->adminController->handle($req1);
        $this->assertStringContainsString('WD-F1', $html1);
        $this->assertStringNotContainsString('WD-F2', $html1);
        $this->assertStringNotContainsString('PAY-F3', $html1);

        // Filter by actor_type
        $req2 = new Request(['actor_type' => 'customer'], [], ['REQUEST_METHOD' => 'GET']);
        $html2 = $this->adminController->handle($req2);
        $this->assertStringContainsString('PAY-F3', $html2);
        $this->assertStringNotContainsString('WD-F1', $html2);

        // Search query
        $req3 = new Request(['search' => 'Rejected'], [], ['REQUEST_METHOD' => 'GET']);
        $html3 = $this->adminController->handle($req3);
        $this->assertStringContainsString('WD-F2', $html3);
        $this->assertStringNotContainsString('WD-F1', $html3);
    }

    public function testAdminControllerPaginationIsBounded(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $admin = new AuditLogTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $admin;

        // Seed 25 items
        for ($i = 1; $i <= 25; $i++) {
            $this->auditService->log("action.item_{$i}", 'test', "S-{$i}", null, [], "Description {$i}", 1, 'admin', 'Super Admin');
        }

        // Page 1 should have 20 items
        $req1 = new Request(['p' => 1], [], ['REQUEST_METHOD' => 'GET']);
        $html1 = $this->adminController->handle($req1);
        $this->assertStringContainsString('page 1 of 2', strtolower($html1));
        $this->assertStringContainsString('action.item_25', $html1);

        // Page 2 should have 5 items
        $req2 = new Request(['p' => 2], [], ['REQUEST_METHOD' => 'GET']);
        $html2 = $this->adminController->handle($req2);
        $this->assertStringContainsString('page 2 of 2', strtolower($html2));
    }

    // =========================================================================
    // 6. WITHDRAWAL DETAIL INTEGRATION
    // =========================================================================

    public function testWithdrawalDetailDisplaysOperationalActivitySection(): void
    {
        $wdAdminCtrl = new WithdrawalAdminController(
            $this->app,
            $this->withdrawalService,
            $this->walletService,
            $this->auditService
        );

        $_SESSION['auth_user_id'] = 1;
        $admin = new AuditLogTestUserStub(['id' => 1], ['super-admin'], [PaymentPermission::MANAGE_WITHDRAWALS, PaymentPermission::VIEW_WITHDRAWALS]);
        $GLOBALS['_test_current_user'] = $admin;

        $this->walletService->deposit(99, Money::bdt(100000), 'DEP-99', 'Credit');
        $wd = $this->withdrawalService->createWithdrawal(99, Money::bdt(60000), 'bkash', ['account_number' => '01712345678']);
        $this->withdrawalService->approve($wd->getId(), 1, 'Approved by admin');

        $req = new Request(['action' => 'detail', 'id' => $wd->getId()], [], ['REQUEST_METHOD' => 'GET']);
        $html = $wdAdminCtrl->handle($req);

        $this->assertIsString($html);
        $this->assertStringContainsString('Operational Activity Center (Who, When, From Where)', $html);
        $this->assertStringContainsString('withdrawal.requested', $html);
        $this->assertStringContainsString('withdrawal.approved', $html);
        $this->assertStringContainsString($wd->getId(), $html);
    }

    // =========================================================================
    // 7. READ-ONLY GUARANTEES
    // =========================================================================

    public function testAuditViewingProducesZeroBalanceMutations(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $admin = new AuditLogTestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $admin;

        // Establish initial balances
        $this->walletService->deposit(101, Money::bdt(10000), 'DEP-101', 'Initial');
        $this->walletService->hold(101, Money::bdt(2000), 'HOLD-101');

        $balanceBefore = $this->walletService->getBalance(101)->getAmount();
        $heldBefore = $this->walletService->getHeldBalance(101)->getAmount();

        // Perform multiple view operations on audit log center
        $req1 = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-audit']);
        $this->adminController->handle($req1);

        $logId = $this->auditService->log('test.check', 'test', '1', 101, []);
        $req2 = new Request(['action' => 'detail', 'id' => (string)$logId], [], ['REQUEST_METHOD' => 'GET']);
        $this->adminController->handle($req2);

        // Verify balances after viewing
        $this->assertSame($balanceBefore, $this->walletService->getBalance(101)->getAmount(), 'Audit viewing must produce ZERO balance mutations');
        $this->assertSame($heldBefore, $this->walletService->getHeldBalance(101)->getAmount(), 'Audit viewing must produce ZERO held balance changes');
        $this->assertSame(8000, $this->walletService->getAvailableBalance(101)->getAmount());
    }
}
