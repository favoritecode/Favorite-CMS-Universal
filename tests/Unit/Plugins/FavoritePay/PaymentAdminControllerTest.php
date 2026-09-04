<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Controllers\PaymentAdminController;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
use FavoriteCMS\Pay\Permissions\PaymentPermission;
use FavoriteCMS\Pay\Repositories\PaymentAttemptRepository;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\PaymentService;
use PHPUnit\Framework\TestCase;

class TestUserStub extends User
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

class PaymentAdminControllerTest extends TestCase
{
    private Application $app;
    private PaymentService $paymentService;
    private PaymentAttemptRepository $repository;
    private PaymentAdminController $controller;

    protected function setUp(): void
    {
        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);

        $this->app = new Application(dirname(__DIR__, 3));
        $currencyService = new CurrencyService();
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
        $manualNagad = new ManualBangladeshGateway(
            'manual_nagad',
            'Nagad Manual Payment',
            PaymentMethodType::MANUAL_NAGAD,
            [
                'channel'        => 'nagad',
                'account_number' => '01800000000',
                'account_name'   => 'Merchant Ltd',
            ]
        );
        $registry->register($manualBkash);
        $registry->register($manualNagad);

        $this->paymentService = new PaymentService($currencyService, $registry);
        $this->repository = new PaymentAttemptRepository($this->paymentService, null);
        $this->controller = new PaymentAdminController($this->app, $this->paymentService, $this->repository);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);
    }

    // =========================================================================
    // A. AUTHORIZATION TESTS
    // =========================================================================

    public function testUnauthenticatedUserRedirectedToLogin(): void
    {
        // No session auth_user_id
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay']);
        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/login', $response->getHeaders()['Location'] ?? '');
    }

    public function testBannedUserDeniedAccess(): void
    {
        $_SESSION['auth_user_id'] = 99;
        $bannedUser = new TestUserStub(['id' => 99, 'status' => 'banned'], ['admin'], [PaymentPermission::VIEW]);
        $GLOBALS['_test_current_user'] = $bannedUser;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay']);
        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('banned or inactive', $response->getContent());
    }

    public function testUserWithoutViewPermissionDeniedAccess(): void
    {
        $_SESSION['auth_user_id'] = 5;
        // User with subscriber role, no view permission
        $user = new TestUserStub(['id' => 5], ['subscriber'], []);
        $GLOBALS['_test_current_user'] = $user;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay']);
        $response = $this->controller->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('do not have permission to view', $response->getContent());
    }

    public function testUserWithViewPermissionCanAccessQueue(): void
    {
        $_SESSION['auth_user_id'] = 10;
        $user = new TestUserStub(['id' => 10], ['operator'], [PaymentPermission::VIEW]);
        $GLOBALS['_test_current_user'] = $user;

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay']);
        $response = $this->controller->handle($request);

        // String returned for layout wrapper
        $this->assertIsString($response);
        $this->assertStringContainsString('Manual Payment Verification Queue', $response);
    }

    public function testSuperAdminAlwaysHasViewAndVerifyPermission(): void
    {
        $superAdmin = new TestUserStub(['id' => 1], ['super-admin'], []);
        $this->assertTrue(PaymentPermission::canView($superAdmin));
        $this->assertTrue(PaymentPermission::canVerify($superAdmin));
    }

    public function testUserWithViewOnlyCannotApprove(): void
    {
        $_SESSION['auth_user_id'] = 12;
        $_SESSION['_token'] = 'valid_token_123';
        $user = new TestUserStub(['id' => 12], ['operator'], [PaymentPermission::VIEW]); // No VERIFY
        $GLOBALS['_test_current_user'] = $user;

        $intent = $this->paymentService->createIntent('test', 'ORD-1', Money::bdt(10000));
        $attempt = $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_TEST_VIEW_ONLY');

        $request = new Request(
            [],
            [
                'action'     => 'approve',
                'attempt_id' => $attempt->getId(),
                '_token'     => 'valid_token_123',
            ],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-pay']
        );

        $response = $this->controller->handle($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertStringContainsString('permission to verify', $_SESSION['flash_error'] ?? '');

        // Verify attempt remained AWAITING_VERIFICATION
        $refreshed = $this->paymentService->getAttempt($attempt->getId());
        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $refreshed->getStatus());
    }

    // =========================================================================
    // B. CSRF PROTECTION TESTS
    // =========================================================================

    public function testApprovalWithoutCsrfTokenFails(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'real_session_token';
        $user = new TestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $user;

        $intent = $this->paymentService->createIntent('test', 'ORD-2', Money::bdt(20000));
        $attempt = $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_CSRF_TEST');

        // Missing _token in post
        $request = new Request(
            [],
            [
                'action'     => 'approve',
                'attempt_id' => $attempt->getId(),
            ],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-pay']
        );

        $response = $this->controller->handle($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertStringContainsString('CSRF failure', $_SESSION['flash_error'] ?? '');

        // Attempt remains unaffected
        $refreshed = $this->paymentService->getAttempt($attempt->getId());
        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $refreshed->getStatus());
    }

    public function testApprovalWithInvalidCsrfTokenFails(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $_SESSION['_token'] = 'real_session_token';
        $user = new TestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $user;

        $intent = $this->paymentService->createIntent('test', 'ORD-3', Money::bdt(20000));
        $attempt = $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_CSRF_INVALID');

        // Invalid _token in post
        $request = new Request(
            [],
            [
                'action'     => 'approve',
                'attempt_id' => $attempt->getId(),
                '_token'     => 'forged_fake_token',
            ],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-pay']
        );

        $response = $this->controller->handle($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertStringContainsString('CSRF failure', $_SESSION['flash_error'] ?? '');
    }

    public function testStateChangingActionViaGetIsIgnored(): void
    {
        $_SESSION['auth_user_id'] = 1;
        $user = new TestUserStub(['id' => 1], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $user;

        $intent = $this->paymentService->createIntent('test', 'ORD-GET', Money::bdt(15000));
        $attempt = $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_GET_ATTEMPT');

        // Attempting to approve via GET request
        $request = new Request(
            ['action' => 'approve', 'attempt_id' => $attempt->getId()],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay?action=approve']
        );

        $response = $this->controller->handle($request);
        // Does not approve; renders index queue string
        $this->assertIsString($response);
        $refreshed = $this->paymentService->getAttempt($attempt->getId());
        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $refreshed->getStatus());
    }

    // =========================================================================
    // C. QUEUE LISTING, FILTERING & SEARCH TESTS
    // =========================================================================

    public function testQueueDisplaysAwaitingVerificationByDefault(): void
    {
        $intent1 = $this->paymentService->createIntent('test', 'O1', Money::bdt(10000));
        $att1 = $this->paymentService->submitManualVerification($intent1->getId(), 'manual_bkash', 'TRX_QUEUE_1');

        $intent2 = $this->paymentService->createIntent('test', 'O2', Money::bdt(20000));
        $att2 = $this->paymentService->submitManualVerification($intent2->getId(), 'manual_nagad', 'TRX_QUEUE_2');

        // Approve att2 so it is succeeded
        $this->paymentService->approveManualPayment($att2->getId(), 1, 'Approved');

        $data = $this->repository->listAttempts(['status' => 'awaiting_verification']);
        $this->assertSame(1, $data['total']);
        $this->assertSame($att1->getId(), $data['items'][0]['attempt_id']);
        $this->assertSame(1, $data['counts']['awaiting_verification']);
        $this->assertSame(1, $data['counts']['succeeded']);
        $this->assertSame(2, $data['counts']['all']);
    }

    public function testQueueFiltersByGateway(): void
    {
        $intent1 = $this->paymentService->createIntent('test', 'O10', Money::bdt(10000));
        $this->paymentService->submitManualVerification($intent1->getId(), 'manual_bkash', 'TRX_GW_BKASH');

        $intent2 = $this->paymentService->createIntent('test', 'O20', Money::bdt(20000));
        $this->paymentService->submitManualVerification($intent2->getId(), 'manual_nagad', 'TRX_GW_NAGAD');

        $data = $this->repository->listAttempts(['gateway_id' => 'manual_nagad', 'status' => 'all']);
        $this->assertSame(1, $data['total']);
        $this->assertSame('manual_nagad', $data['items'][0]['gateway_id']);
    }

    public function testQueueSearchesByTrxId(): void
    {
        $intent1 = $this->paymentService->createIntent('test', 'O30', Money::bdt(10000));
        $this->paymentService->submitManualVerification($intent1->getId(), 'manual_bkash', 'UNIQUE_TRX_99999');

        $intent2 = $this->paymentService->createIntent('test', 'O40', Money::bdt(20000));
        $this->paymentService->submitManualVerification($intent2->getId(), 'manual_bkash', 'OTHER_TRX_11111');

        $data = $this->repository->listAttempts(['search' => '99999', 'status' => 'all']);
        $this->assertSame(1, $data['total']);
        $this->assertSame('UNIQUE_TRX_99999', $data['items'][0]['provider_reference']);
    }

    // =========================================================================
    // D. APPROVAL FLOW TESTS
    // =========================================================================

    public function testValidApprovalSucceeds(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['_token'] = 'token_abc_123';
        $user = new TestUserStub(['id' => 42, 'username' => 'operator_john'], ['operator'], [PaymentPermission::VIEW, PaymentPermission::VERIFY]);
        $GLOBALS['_test_current_user'] = $user;

        $intent = $this->paymentService->createIntent('digital', 'DIG-100', Money::bdt(50000));
        $attempt = $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_APP_VALID');

        $request = new Request(
            [],
            [
                'action'         => 'approve',
                'attempt_id'     => $attempt->getId(),
                'operator_notes' => 'Checked against statement',
                '_token'         => 'token_abc_123',
            ],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-pay']
        );

        $response = $this->controller->handle($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('successfully approved', $_SESSION['flash_success'] ?? '');

        // Verify domain models updated
        $refreshedAttempt = $this->paymentService->getAttempt($attempt->getId());
        $this->assertSame(PaymentStatus::SUCCEEDED, $refreshedAttempt->getStatus());
        $this->assertSame(42, $refreshedAttempt->getVerifiedBy());
        $this->assertSame('Checked against statement', $refreshedAttempt->getVerificationNotes());

        $refreshedIntent = $this->paymentService->getIntent($intent->getId());
        $this->assertSame(PaymentStatus::SUCCEEDED, $refreshedIntent->getStatus());
    }

    public function testDoubleApprovalFailsGracefully(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['_token'] = 'token_double';
        $user = new TestUserStub(['id' => 42], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $user;

        $intent = $this->paymentService->createIntent('digital', 'DIG-101', Money::bdt(30000));
        $attempt = $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_DOUBLE_APP');

        // Admin B approves first
        $this->paymentService->approveManualPayment($attempt->getId(), 99, 'Admin B approval');

        // Admin A tries to approve the same attempt
        $request = new Request(
            [],
            [
                'action'         => 'approve',
                'attempt_id'     => $attempt->getId(),
                'operator_notes' => 'Admin A attempt',
                '_token'         => 'token_double',
            ],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-pay']
        );

        $response = $this->controller->handle($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertStringContainsString('Cannot approve payment: Cannot approve payment attempt', $_SESSION['flash_error'] ?? '');
    }

    // =========================================================================
    // E. REJECTION FLOW TESTS
    // =========================================================================

    public function testRejectionRequiresNonEmptyReason(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['_token'] = 'token_rej_empty';
        $user = new TestUserStub(['id' => 42], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $user;

        $intent = $this->paymentService->createIntent('digital', 'DIG-200', Money::bdt(15000));
        $attempt = $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_EMPTY_REASON');

        // Submitting with empty reason
        $request = new Request(
            [],
            [
                'action'     => 'reject',
                'attempt_id' => $attempt->getId(),
                'reason'     => '   ',
                '_token'     => 'token_rej_empty',
            ],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-pay']
        );

        $response = $this->controller->handle($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertStringContainsString('Rejection reason is required', $_SESSION['flash_error'] ?? '');

        $refreshed = $this->paymentService->getAttempt($attempt->getId());
        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $refreshed->getStatus());
    }

    public function testValidRejectionSucceeds(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['_token'] = 'token_rej_valid';
        $user = new TestUserStub(['id' => 42], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $user;

        $intent = $this->paymentService->createIntent('digital', 'DIG-201', Money::bdt(25000));
        $attempt = $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_REJ_VALID');

        $request = new Request(
            [],
            [
                'action'     => 'reject',
                'attempt_id' => $attempt->getId(),
                'reason'     => 'TrxID not found in statement',
                '_token'     => 'token_rej_valid',
            ],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-pay']
        );

        $response = $this->controller->handle($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertStringContainsString('has been rejected', $_SESSION['flash_success'] ?? '');

        // Verify domain models updated
        $refreshedAttempt = $this->paymentService->getAttempt($attempt->getId());
        $this->assertSame(PaymentStatus::FAILED, $refreshedAttempt->getStatus());
        $this->assertSame(42, $refreshedAttempt->getVerifiedBy());
        $this->assertSame('TrxID not found in statement', $refreshedAttempt->getRejectionReason());

        $refreshedIntent = $this->paymentService->getIntent($intent->getId());
        $this->assertSame(PaymentStatus::FAILED, $refreshedIntent->getStatus());
    }

    public function testDoubleRejectionFailsGracefully(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['_token'] = 'token_double_rej';
        $user = new TestUserStub(['id' => 42], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $user;

        $intent = $this->paymentService->createIntent('digital', 'DIG-202', Money::bdt(25000));
        $attempt = $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_DOUBLE_REJ');

        // First rejection
        $this->paymentService->rejectManualPayment($attempt->getId(), 42, 'First rejection');

        // Second rejection
        $request = new Request(
            [],
            [
                'action'     => 'reject',
                'attempt_id' => $attempt->getId(),
                'reason'     => 'Second rejection attempt',
                '_token'     => 'token_double_rej',
            ],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-pay']
        );

        $response = $this->controller->handle($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertStringContainsString('Cannot reject payment: Cannot reject payment attempt', $_SESSION['flash_error'] ?? '');
    }

    // =========================================================================
    // F. SECURITY & WALLET SAFETY TESTS
    // =========================================================================

    public function testClientCannotOverrideStatusParameterInAdminRequest(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $_SESSION['_token'] = 'token_tamper';
        $user = new TestUserStub(['id' => 42], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $user;

        $intent = $this->paymentService->createIntent('digital', 'DIG-300', Money::bdt(10000));
        $attempt = $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_TAMPER');

        // Tamper request attempting to inject status=succeeded without valid approve action
        $request = new Request(
            [],
            [
                'action'     => 'invalid_action',
                'status'     => 'succeeded',
                'attempt_id' => $attempt->getId(),
                '_token'     => 'token_tamper',
            ],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-pay']
        );

        $response = $this->controller->handle($request);
        // Unrecognized action defaults to redirect, no state change
        $refreshed = $this->paymentService->getAttempt($attempt->getId());
        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $refreshed->getStatus());
    }

    public function testReviewPageShowsDetail(): void
    {
        $_SESSION['auth_user_id'] = 42;
        $user = new TestUserStub(['id' => 42], ['super-admin'], []);
        $GLOBALS['_test_current_user'] = $user;

        $intent = $this->paymentService->createIntent('digital', 'DIG-VIEW', Money::bdt(45000));
        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'manual_bkash',
            'TRX_VIEW_PAGE_123',
            ['sender_account' => '01711223344', 'notes' => 'Paid via app']
        );

        $request = new Request(
            ['action' => 'view', 'id' => $attempt->getId()],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay?action=view&id=' . $attempt->getId()]
        );

        $response = $this->controller->handle($request);
        $this->assertIsString($response);
        $this->assertStringContainsString('TRX_VIEW_PAGE_123', $response);
        $this->assertStringContainsString('01711223344', $response);
        $this->assertStringContainsString('Paid via app', $response);
        $this->assertStringContainsString('450.00 BDT', $response);
    }
}
