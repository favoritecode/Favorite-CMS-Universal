<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Core\AccountMenu;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
use FavoriteCMS\Pay\Contracts\NotificationServiceInterface;
use FavoriteCMS\Pay\Contracts\WithdrawalServiceInterface;
use FavoriteCMS\Pay\Controllers\CustomerAccountController;
use FavoriteCMS\Pay\Controllers\WithdrawalAdminController;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\Withdrawal;
use FavoriteCMS\Pay\Domain\WithdrawalStatus;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Permissions\PaymentPermission;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\WalletService;
use FavoriteCMS\Pay\Services\WithdrawalService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WithdrawalTestUserStub extends User
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

class WithdrawalTest extends TestCase
{
    private Application $app;
    private CurrencyService $currencyService;
    private GatewayRegistry $registry;
    private PaymentService $paymentService;
    private WalletService $walletService;
    private WithdrawalService $withdrawalService;
    private CustomerAccountController $customerController;
    private WithdrawalAdminController $adminController;
    private FavoritePayPlugin $plugin;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_SESSION['_csrf_token'] = 'valid-test-csrf-token';
        $_SESSION['_token'] = 'valid-test-csrf-token';
        unset($GLOBALS['_test_current_user'], $GLOBALS['_test_current_user_id']);

        $this->app = new Application(dirname(__DIR__, 3));
        $this->currencyService = new CurrencyService();
        $this->registry = new GatewayRegistry();

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

        $this->paymentService = new PaymentService($this->currencyService, $this->registry);
        $this->walletService = new WalletService($this->currencyService, $this->paymentService);
        $this->withdrawalService = new WithdrawalService($this->walletService, $this->currencyService);

        $this->app->singleton(NotificationServiceInterface::class, fn () => $this->withdrawalService->getNotificationService());

        $this->customerController = new CustomerAccountController(
            $this->app,
            $this->walletService,
            $this->paymentService,
            $this->registry,
            $this->currencyService,
            null,
            $this->withdrawalService,
            $this->withdrawalService->getNotificationService()
        );

        $this->adminController = new WithdrawalAdminController(
            $this->app,
            $this->withdrawalService,
            $this->walletService
        );

        $this->app->singleton(WithdrawalServiceInterface::class, fn () => $this->withdrawalService);
        $this->app->singleton(WalletServiceInterface::class, fn () => $this->walletService);
        $this->app->singleton(\FavoriteCMS\Pay\Contracts\WalletServiceInterface::class, fn () => $this->walletService);
        $this->plugin = new FavoritePayPlugin($this->app);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($GLOBALS['_test_current_user'], $GLOBALS['_test_current_user_id']);
        if (class_exists(AccountMenu::class)) {
            AccountMenu::removeByPlugin('favorite-pay');
        }
    }

    private function setLoggedInUser(int $id = 1, string $status = 'active', string $username = 'testuser', array $roles = [], array $permissions = []): WithdrawalTestUserStub
    {
        $_SESSION['auth_user_id'] = $id;
        $_SESSION['auth_user_name'] = $username;
        $_SESSION['user_id'] = $id;
        $user = new WithdrawalTestUserStub([
            'id'       => $id,
            'username' => $username,
            'email'    => $username . '@example.com',
            'status'   => $status,
        ], $roles, $permissions);
        $GLOBALS['_test_current_user'] = $user;
        $GLOBALS['_test_current_user_id'] = $id;
        return $user;
    }

    private function fundWallet(int $userId, int $majorAmount, string $currency = 'BDT'): void
    {
        $money = new Money($majorAmount * 100, $currency);
        $this->walletService->deposit($userId, $money, 'test_ref_' . bin2hex(random_bytes(4)), 'Initial funding for test');
    }

    // =========================================================================
    // 1. FEATURE FLAG & MASTER SWITCH TESTS (Requirements 1 - 4)
    // =========================================================================

    public function testWithdrawalDisabledByDefault(): void
    {
        $this->assertFalse($this->withdrawalService->isWithdrawalEnabled());
    }

    public function testAdminCanEnableAndDisableWithdrawal(): void
    {
        $this->assertFalse($this->withdrawalService->isWithdrawalEnabled());

        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->assertTrue($this->withdrawalService->isWithdrawalEnabled());

        $this->withdrawalService->updateSettings(['enabled' => false]);
        $this->assertFalse($this->withdrawalService->isWithdrawalEnabled());
    }

    public function testAccountMenuHidesWithdrawalWhenDisabled(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => false]);
        $this->plugin->registerAccountMenuItems();

        $withdrawItem = AccountMenu::getItem('pay_withdraw');
        $this->assertNull($withdrawItem, 'Withdraw item must not be registered when feature is disabled');

        $this->assertNotNull(AccountMenu::getItem('pay_balance'));
        $this->assertNotNull(AccountMenu::getItem('pay_recharge'));
        $this->assertNotNull(AccountMenu::getItem('pay_payments'));
        $this->assertNotNull(AccountMenu::getItem('pay_transactions'));
    }

    public function testAccountMenuShowsWithdrawalWhenEnabled(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->plugin->registerAccountMenuItems();

        $withdrawItem = AccountMenu::getItem('pay_withdraw');
        $this->assertNotNull($withdrawItem, 'Withdraw item must be registered when feature is enabled');
        $this->assertSame('Withdraw', $withdrawItem['label']);
        $this->assertSame('/account/withdraw', $withdrawItem['url']);
        $this->assertSame('fas fa-money-bill-wave', $withdrawItem['icon']);
        $this->assertSame(18, $withdrawItem['order'], 'Conceptual order must be 18 (between Recharge 16 and Payment History 20)');
        $this->assertSame('favorite-pay', $withdrawItem['plugin']);
    }

    // =========================================================================
    // 2. ROUTE & CONTROLLER PROTECTION WHEN DISABLED (Requirements 5 - 8)
    // =========================================================================

    public function testCustomerWithdrawGetReturns403WhenDisabled(): void
    {
        $this->setLoggedInUser(1);
        $this->withdrawalService->updateSettings(['enabled' => false]);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/withdraw']);
        $response = $this->customerController->withdraw($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('disabled', (string)$response->getContent());
    }

    public function testCustomerWithdrawPostReturns403WhenDisabled(): void
    {
        $this->setLoggedInUser(1);
        $this->withdrawalService->updateSettings(['enabled' => false]);

        $request = new Request([], [
            '_csrf_token'   => 'valid-test-csrf-token',
            'amount'        => '500',
            'payout_method' => 'bkash',
            'destination'   => '01712345678',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/withdraw']);
        $response = $this->customerController->withdraw($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('disabled', (string)$response->getContent());
    }

    public function testServiceCreateWithdrawalThrowsWhenDisabled(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Withdrawals are currently disabled');

        $this->withdrawalService->createWithdrawal(
            1,
            new Money(50000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );
    }

    // =========================================================================
    // 3. AUTH & USER LIFECYCLE CHECKS (Requirements 9 - 12)
    // =========================================================================

    public function testGuestRedirectedToLoginOnWithdrawArea(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/withdraw']);
        $response = $this->customerController->withdraw($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/login', $response->getHeader('Location') ?? '');
    }

    public function testSuspendedCustomerBlockedFromWithdrawalArea(): void
    {
        $this->setLoggedInUser(1, 'suspended');
        $this->withdrawalService->updateSettings(['enabled' => true]);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/withdraw']);
        $response = $this->customerController->withdraw($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('suspended', (string)$response->getContent());

        $postRequest = new Request([], ['amount' => '500'], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/withdraw']);
        $postResponse = $this->customerController->withdraw($postRequest);
        $this->assertSame(403, $postResponse->getStatusCode());
    }

    public function testBannedCustomerBlockedFromWithdrawalArea(): void
    {
        $this->setLoggedInUser(1, 'banned');
        $this->withdrawalService->updateSettings(['enabled' => true]);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/withdraw']);
        $response = $this->customerController->withdraw($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    // =========================================================================
    // 4. FINANCIAL SAFETY INVARIANT & HOLD MECHANICS (Requirements 13 - 16)
    // =========================================================================

    public function testWithdrawalPlacesHoldWithoutDebitingLedger(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT'); // 10,000 BDT

        $this->assertSame(1000000, $this->walletService->getBalance(1)->getAmount());
        $this->assertSame(1000000, $this->walletService->getAvailableBalance(1)->getAmount());

        // Create withdrawal for 2,000 BDT
        $withdrawal = $this->withdrawalService->createWithdrawal(
            1,
            new Money(200000, 'BDT'),
            'bkash',
            ['account' => '01712345678', 'account_name' => 'John Doe']
        );

        $this->assertSame(WithdrawalStatus::PENDING, $withdrawal->getStatus());
        $this->assertSame(200000, $withdrawal->getAmount()->getAmount());
        $this->assertNotEmpty($withdrawal->getHoldReference());

        // FINANCIAL INVARIANT VERIFICATION:
        // 1. Available balance MUST immediately decrease to 8,000 BDT
        $available = $this->walletService->getAvailableBalance(1);
        $this->assertSame(800000, $available->getAmount());

        // 2. Exactly 1 active hold of 2,000 BDT exists in ledger
        $history = $this->walletService->getLedgerHistory(1);
        $holds = array_filter($history, fn ($e) => $e->getType() === 'hold');
        $this->assertCount(1, $holds);
        $holdEntry = reset($holds);
        $this->assertSame(200000, $holdEntry->getAmount()->getAmount());

        // 3. No permanent debit yet!
        $debits = array_filter($history, fn ($e) => $e->getType() === 'debit');
        $this->assertCount(0, $debits);
    }

    public function testInsufficientBalanceThrowsException(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 1000, 'BDT'); // 1,000 BDT

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient available balance');

        // Request 2,000 BDT
        $this->withdrawalService->createWithdrawal(
            1,
            new Money(200000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );
    }

    public function testZeroOrNegativeAmountThrowsException(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        try {
            $this->withdrawalService->createWithdrawal(
                1,
                new Money(0, 'BDT'),
                'bkash',
                ['account' => '01712345678']
            );
            $this->fail('Expected exception for zero amount');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('positive', $e->getMessage());
        }

        try {
            $this->withdrawalService->createWithdrawal(
                1,
                new Money(-5000, 'BDT'),
                'bkash',
                ['account' => '01712345678']
            );
            $this->fail('Expected exception for negative amount');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('positive', $e->getMessage());
        }
    }

    public function testCurrencyMismatchThrowsException(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('denominated in BDT');

        $this->withdrawalService->createWithdrawal(
            1,
            new Money(5000, 'USD'),
            'bkash',
            ['account' => '01712345678']
        );
    }

    public function testUnsupportedMethodThrowsException(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("method 'crypto' is not supported");

        $this->withdrawalService->createWithdrawal(
            1,
            new Money(50000, 'BDT'),
            'crypto',
            ['account' => '0x123']
        );
    }

    public function testEmptyDestinationThrowsException(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Destination account details are required');

        $this->withdrawalService->createWithdrawal(
            1,
            new Money(50000, 'BDT'),
            'bkash',
            []
        );
    }

    public function testMinimumWithdrawalEnforcedWithoutMaxAmountLimit(): void
    {
        $this->withdrawalService->updateSettings([
            'enabled'    => true,
            'min_amount' => 500.0,
        ]);
        $this->fundWallet(1, 200000, 'BDT'); // 200,000 BDT

        // Below min (400 BDT)
        try {
            $this->withdrawalService->createWithdrawal(
                1,
                new Money(40000, 'BDT'),
                'bkash',
                ['account' => '01712345678']
            );
            $this->fail('Expected min amount violation');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Minimum withdrawal amount', $e->getMessage());
        }

        // Exactly at min (500 BDT)
        $wExact = $this->withdrawalService->createWithdrawal(
            1,
            new Money(50000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );
        $this->assertSame(50000, $wExact->getAmount()->getAmount());

        // Very large withdrawal (e.g. 150,000 BDT) allowed without max limit
        $wLarge = $this->withdrawalService->createWithdrawal(
            1,
            new Money(15000000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );
        $this->assertSame(15000000, $wLarge->getAmount()->getAmount());
    }

    // =========================================================================
    // 5. IDOR & ACCESS CONTROL TESTS (Requirements 17 - 19)
    // =========================================================================

    public function testCustomerCanViewTheirOwnWithdrawalDetail(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 5000, 'BDT');
        $this->setLoggedInUser(1);

        $withdrawal = $this->withdrawalService->createWithdrawal(
            1,
            new Money(100000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/withdrawals/' . $withdrawal->getId()]);
        $response = $this->customerController->withdrawalDetail($request, $withdrawal->getId());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Destination Details', (string)$response->getContent());
        $this->assertStringContainsString('Requested Amount', (string)$response->getContent());
    }

    public function testCustomerCannotViewOtherUsersWithdrawalIdorProtection(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 5000, 'BDT');

        // Created by User 1
        $withdrawal = $this->withdrawalService->createWithdrawal(
            1,
            new Money(100000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );

        // User 2 logs in
        $this->setLoggedInUser(2, 'active', 'user2');

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/withdrawals/' . $withdrawal->getId()]);
        $response = $this->customerController->withdrawalDetail($request, $withdrawal->getId());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Access denied', (string)$response->getContent());
    }

    public function testViewingNonExistentWithdrawalReturns404(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->setLoggedInUser(1);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/withdrawals/wd_nonexistent']);
        $response = $this->customerController->withdrawalDetail($request, 'wd_nonexistent');

        $this->assertSame(404, $response->getStatusCode());
    }

    // =========================================================================
    // 6. STATE MACHINE TRANSITIONS & SETTLEMENT LIFECYCLE (Requirements 20 - 30)
    // =========================================================================

    public function testCompleteSuccessfulWithdrawalLifecycle(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        // 1. Creation -> PENDING
        $w = $this->withdrawalService->createWithdrawal(
            1,
            new Money(300000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );
        $this->assertSame(WithdrawalStatus::PENDING, $w->getStatus());

        // 2. Admin Approve -> APPROVED
        $w = $this->withdrawalService->approve($w->getId(), 99, 'Approved for batch 42');
        $this->assertSame(WithdrawalStatus::APPROVED, $w->getStatus());
        $this->assertSame(99, $w->getAdminUserId());
        $this->assertStringContainsString('Approved for batch 42', $w->getOperatorNotes());

        // Available balance is STILL 7,000 BDT
        $this->assertSame(700000, $this->walletService->getAvailableBalance(1)->getAmount());

        // 3. Admin Processing -> PROCESSING
        $w = $this->withdrawalService->startProcessing($w->getId(), 99, 'Sent to payment team');
        $this->assertSame(WithdrawalStatus::PROCESSING, $w->getStatus());

        // 4. Admin Mark Paid -> PAID
        $w = $this->withdrawalService->markPaid($w->getId(), 99, 'TRX_BKASH_998877', 'Payment completed via bKash Merchant portal');
        $this->assertSame(WithdrawalStatus::PAID, $w->getStatus());
        $this->assertSame('TRX_BKASH_998877', $w->getTransactionReference());
        $this->assertNotNull($w->getProcessedAt());

        // 5. FINANCIAL FINALIZATION VERIFICATION:
        // Hold finalized to permanent debit!
        $this->assertSame(700000, $this->walletService->getBalance(1)->getAmount());
        $this->assertSame(700000, $this->walletService->getAvailableBalance(1)->getAmount());

        // Exactly 1 debit ledger entry exists with reference
        $history = $this->walletService->getLedgerHistory(1);
        $debits = array_filter($history, fn ($e) => $e->getType() === 'debit');
        $this->assertCount(1, $debits);
        $debitEntry = reset($debits);
        $this->assertSame(300000, $debitEntry->getAmount()->getAmount());
        $this->assertSame($w->getId(), $debitEntry->getReferenceId());
    }

    public function testMarkPaidIdempotency(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        $w = $this->withdrawalService->createWithdrawal(
            1,
            new Money(200000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );
        $w = $this->withdrawalService->approve($w->getId(), 99);
        $w = $this->withdrawalService->startProcessing($w->getId(), 99);
        $w = $this->withdrawalService->markPaid($w->getId(), 99, 'TRX_123');

        $this->assertSame(800000, $this->walletService->getBalance(1)->getAmount());

        // Call markPaid again
        $wRepeat = $this->withdrawalService->markPaid($w->getId(), 99, 'TRX_123_REPEAT');
        $this->assertSame(WithdrawalStatus::PAID, $wRepeat->getStatus());

        // Balance MUST NOT be debited twice!
        $this->assertSame(800000, $this->walletService->getBalance(1)->getAmount());

        $history = $this->walletService->getLedgerHistory(1);
        $debits = array_filter($history, fn ($e) => $e->getType() === 'debit');
        $this->assertCount(1, $debits, 'Only one debit entry must exist despite repeated markPaid');
    }

    public function testAdminRejectionReleasesHold(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        $w = $this->withdrawalService->createWithdrawal(
            1,
            new Money(400000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );

        // Before reject: Available = 6,000 BDT
        $this->assertSame(600000, $this->walletService->getAvailableBalance(1)->getAmount());

        // Admin rejects
        $w = $this->withdrawalService->reject($w->getId(), 99, 'Invalid mobile number provided');
        $this->assertSame(WithdrawalStatus::REJECTED, $w->getStatus());
        $this->assertStringContainsString('Invalid mobile number', $w->getOperatorNotes());

        // Hold MUST BE RELEASED: Available restored to 10,000 BDT
        $this->assertSame(1000000, $this->walletService->getAvailableBalance(1)->getAmount());
        $this->assertSame(1000000, $this->walletService->getBalance(1)->getAmount());

        $history = $this->walletService->getLedgerHistory(1);
        $releases = array_filter($history, fn ($e) => $e->getType() === 'release');
        $this->assertCount(1, $releases);
    }

    public function testAdminFailureReleasesHold(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        $w = $this->withdrawalService->createWithdrawal(
            1,
            new Money(250000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );
        $w = $this->withdrawalService->approve($w->getId(), 99);
        $w = $this->withdrawalService->startProcessing($w->getId(), 99);

        // Admin marks as failed
        $w = $this->withdrawalService->markFailed($w->getId(), 99, 'Bank gateway EFT timeout');
        $this->assertSame(WithdrawalStatus::FAILED, $w->getStatus());

        // Hold MUST BE RELEASED
        $this->assertSame(1000000, $this->walletService->getAvailableBalance(1)->getAmount());
        $this->assertSame(1000000, $this->walletService->getBalance(1)->getAmount());
    }

    public function testCustomerCanCancelPendingWithdrawal(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        $w = $this->withdrawalService->createWithdrawal(
            1,
            new Money(150000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );

        $this->assertSame(850000, $this->walletService->getAvailableBalance(1)->getAmount());

        // Customer cancels
        $w = $this->withdrawalService->cancel($w->getId(), 1, 'Decided to keep funds');
        $this->assertSame(WithdrawalStatus::CANCELLED, $w->getStatus());

        // Available balance restored!
        $this->assertSame(1000000, $this->walletService->getAvailableBalance(1)->getAmount());
    }

    public function testCustomerCannotCancelApprovedOrProcessingWithdrawal(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        $w = $this->withdrawalService->createWithdrawal(
            1,
            new Money(150000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );
        $this->withdrawalService->approve($w->getId(), 99);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot');

        $this->withdrawalService->cancel($w->getId(), 1);
    }

    public function testInvalidTransitionsRejected(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        $w = $this->withdrawalService->createWithdrawal(
            1,
            new Money(100000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );

        // PENDING cannot jump directly to PAID without approval/processing
        try {
            $this->withdrawalService->markPaid($w->getId(), 99, 'TRX_INVALID');
            $this->fail('Direct transition from PENDING to PAID must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot', $e->getMessage());
        }

        // Reject withdrawal
        $w = $this->withdrawalService->reject($w->getId(), 99, 'Rejected test');

        // Cannot approve a rejected withdrawal
        try {
            $this->withdrawalService->approve($w->getId(), 99);
            $this->fail('Transition from REJECTED to APPROVED must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot', $e->getMessage());
        }
    }

    // =========================================================================
    // 7. CONCURRENCY, IDEMPOTENCY & PRIVACY TESTS (Requirements 31 - 36)
    // =========================================================================

    public function testDuplicateSubmissionIdempotency(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        $key = 'idem_test_key_123';
        $w1 = $this->withdrawalService->createWithdrawal(
            1,
            new Money(200000, 'BDT'),
            'bkash',
            ['account' => '01712345678'],
            $key
        );

        // Second call with same key
        $w2 = $this->withdrawalService->createWithdrawal(
            1,
            new Money(200000, 'BDT'),
            'bkash',
            ['account' => '01712345678'],
            $key
        );

        $this->assertSame($w1->getId(), $w2->getId());
        $this->assertSame(800000, $this->walletService->getAvailableBalance(1)->getAmount(), 'Hold must not be placed twice');
    }

    public function testConcurrencyOverdrawProtection(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 3000, 'BDT'); // 3,000 BDT

        // First tab reserves 2,000 BDT -> OK (1,000 BDT left)
        $w1 = $this->withdrawalService->createWithdrawal(
            1,
            new Money(200000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );
        $this->assertNotNull($w1);

        // Second tab tries to reserve 2,000 BDT -> FAILS (only 1,000 available)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient available balance');

        $this->withdrawalService->createWithdrawal(
            1,
            new Money(200000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );
    }

    public function testDestinationMaskingForPrivacy(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        $w = $this->withdrawalService->createWithdrawal(
            1,
            new Money(100000, 'BDT'),
            'bkash',
            ['account' => '01712345678', 'account_name' => 'John Doe']
        );

        $masked = $w->getDestinationMasked();
        $this->assertStringContainsString('****', $masked);
        $this->assertStringStartsWith('017', $masked);
        $this->assertStringEndsWith('5678', $masked);
    }

    public function testHistoricalWithdrawalsRetainedWhenDisabled(): void
    {
        // 1. Enabled: create withdrawal
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 5000, 'BDT');

        $w = $this->withdrawalService->createWithdrawal(
            1,
            new Money(100000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );

        // 2. Disabled
        $this->withdrawalService->updateSettings(['enabled' => false]);

        // Historical query must STILL work!
        $retrieved = $this->withdrawalService->getWithdrawal($w->getId());
        $this->assertNotNull($retrieved);
        $this->assertSame($w->getId(), $retrieved->getId());

        $userList = $this->withdrawalService->getUserWithdrawals(1);
        $this->assertCount(1, $userList);

        $adminList = $this->withdrawalService->listWithdrawals();
        $this->assertSame(1, $adminList['total']);
    }

    public function testHasFinancialActivityIncludesWithdrawals(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 5000, 'BDT');

        $this->withdrawalService->createWithdrawal(
            1,
            new Money(100000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );

        $this->assertTrue($this->plugin->hasFinancialActivity());
    }

    public function testCustomFeeCalculation(): void
    {
        $this->withdrawalService->updateSettings([
            'enabled'    => true,
            'fee_fixed'  => 10.0, // 10 BDT fixed
            'fee_pct'    => 2.0,  // 2%
        ]);
        $this->fundWallet(1, 10000, 'BDT');

        // Request 1,000 BDT
        // Fee = 10 BDT + 20 BDT = 30 BDT (3,000 minor units)
        // Net = 970 BDT (97,000 minor units)
        $w = $this->withdrawalService->createWithdrawal(
            1,
            new Money(100000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );

        $this->assertSame(3000, $w->getFee()->getAmount());
        $this->assertSame(97000, $w->getNetAmount()->getAmount());
    }

    // =========================================================================
    // 8. ADMIN CONTROLLER PERMISSIONS & ACTIONS (Requirements 37 - 40)
    // =========================================================================

    public function testAdminControllerRequiresViewPermission(): void
    {
        // User without permission
        $this->setLoggedInUser(1, 'active', 'regular_user');

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/favorite-pay-withdrawals']);
        $response = $this->adminController->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAdminControllerAllowedWithViewPermission(): void
    {
        $this->setLoggedInUser(99, 'active', 'admin_user', [], [PaymentPermission::VIEW_WITHDRAWALS]);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/favorite-pay-withdrawals']);
        $response = $this->adminController->handle($request);

        $html = is_string($response) ? $response : $response->getContent();
        $this->assertStringContainsString('Payout Settings', $html);
    }

    public function testAdminControllerActionRequiresManagePermission(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 5000, 'BDT');
        $w = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01712345678']);

        // User has only VIEW, not MANAGE, and no admin/super-admin role
        $this->setLoggedInUser(98, 'active', 'viewer_admin', [], [PaymentPermission::VIEW_WITHDRAWALS]);

        $request = new Request([], [
            '_csrf_token'   => 'valid-test-csrf-token',
            'action'        => 'approve',
            'withdrawal_id' => $w->getId(),
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/favorite-pay-withdrawals']);
        $response = $this->adminController->handle($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAdminControllerApproveAndPayActions(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 5000, 'BDT');
        $w = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01712345678']);

        // Super Admin with MANAGE
        $this->setLoggedInUser(99, 'active', 'super_admin', ['super-admin'], [PaymentPermission::MANAGE_WITHDRAWALS, PaymentPermission::VIEW_WITHDRAWALS]);

        // 1. Approve
        $reqApprove = new Request([], [
            '_csrf_token'   => 'valid-test-csrf-token',
            'action'        => 'approve',
            'withdrawal_id' => $w->getId(),
            'notes'         => 'Batch 12',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/favorite-pay-withdrawals']);
        $resApprove = $this->adminController->handle($reqApprove);
        $this->assertInstanceOf(Response::class, $resApprove);
        $this->assertSame(302, $resApprove->getStatusCode());

        $wFresh = $this->withdrawalService->getWithdrawal($w->getId());
        $this->assertSame(WithdrawalStatus::APPROVED, $wFresh->getStatus());

        // 2. Start Processing
        $reqProcess = new Request([], [
            '_csrf_token'   => 'valid-test-csrf-token',
            'action'        => 'process',
            'withdrawal_id' => $w->getId(),
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/favorite-pay-withdrawals']);
        $resProcess = $this->adminController->handle($reqProcess);
        $this->assertInstanceOf(Response::class, $resProcess);
        $this->assertSame(302, $resProcess->getStatusCode());

        $wFresh = $this->withdrawalService->getWithdrawal($w->getId());
        $this->assertSame(WithdrawalStatus::PROCESSING, $wFresh->getStatus());

        // 3. Mark Paid
        $reqPaid = new Request([], [
            '_csrf_token'           => 'valid-test-csrf-token',
            'action'                => 'paid',
            'withdrawal_id'         => $w->getId(),
            'transaction_reference' => 'TRX_ADM_556677',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/favorite-pay-withdrawals']);
        $resPaid = $this->adminController->handle($reqPaid);
        $this->assertInstanceOf(Response::class, $resPaid);
        $this->assertSame(302, $resPaid->getStatusCode());

        $wFresh = $this->withdrawalService->getWithdrawal($w->getId());
        $this->assertSame(WithdrawalStatus::PAID, $wFresh->getStatus());
        $this->assertSame('TRX_ADM_556677', $wFresh->getTransactionReference());
    }

    public function testAdminControllerToggleFeatureSettings(): void
    {
        $this->setLoggedInUser(99, 'active', 'super_admin', ['super-admin']);

        $request = new Request([], [
            '_csrf_token'        => 'valid-test-csrf-token',
            'action'             => 'update_settings',
            'enabled'            => '1',
            'min_amount'         => '250',
            'max_monthly_count'  => '8',
            'fee_fixed'          => '15',
            'fee_pct'            => '1.5',
            'allowed_methods'    => ['bkash', 'nagad', 'bank'],
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/favorite-pay-withdrawals']);

        $response = $this->adminController->handle($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());

        $this->assertTrue($this->withdrawalService->isWithdrawalEnabled());
        $settings = $this->withdrawalService->getSettings();
        $this->assertSame(250.0, (float)$settings['min_amount']);
        $this->assertSame(8, (int)$settings['max_monthly_count']);
        $this->assertSame(15.0, (float)$settings['fee_fixed']);
        $this->assertSame(1.5, (float)$settings['fee_pct']);
        $this->assertContains('bkash', $settings['allowed_methods']);
        $this->assertNotContains('rocket', $settings['allowed_methods']);
    }

    // =========================================================================
    // 8. PHASE 4: FINAL WITHDRAWAL SETTINGS & LIMIT ENFORCEMENT TESTS
    // =========================================================================

    public function testCustomerCanWithdrawFullAvailableBalanceWithoutLeavingMinimumBehind(): void
    {
        $this->withdrawalService->updateSettings([
            'enabled'    => true,
            'min_amount' => 500.0,
        ]);
        $this->fundWallet(1, 1000, 'BDT'); // 1,000 BDT in wallet

        // Customer withdraws exactly 1,000 BDT
        $w = $this->withdrawalService->createWithdrawal(
            1,
            new Money(100000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );

        $this->assertSame(100000, $w->getAmount()->getAmount());
        $this->assertSame(0, $this->walletService->getAvailableBalance(1)->getAmount());
    }

    public function testExistingWalletHoldReducesAvailableBalanceCorrectly(): void
    {
        $this->withdrawalService->updateSettings([
            'enabled'    => true,
            'min_amount' => 500.0,
        ]);
        $this->fundWallet(1, 1000, 'BDT'); // 1,000 BDT

        // Place an active hold of 600 BDT
        $this->walletService->hold(1, new Money(60000, 'BDT'), 'custom_hold_123');
        $this->assertSame(40000, $this->walletService->getAvailableBalance(1)->getAmount()); // 400 BDT available

        // Minimum withdrawal is 500 BDT -> Request must be rejected
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient available balance');

        $this->withdrawalService->createWithdrawal(
            1,
            new Money(50000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );
    }

    public function testMonthlyWithdrawalCountLimitEnforced(): void
    {
        $this->withdrawalService->updateSettings([
            'enabled'           => true,
            'min_amount'        => 100.0,
            'max_monthly_count' => 3,
        ]);
        $this->fundWallet(1, 100000, 'BDT');

        // Request #1
        $w1 = $this->withdrawalService->createWithdrawal(1, new Money(10000, 'BDT'), 'bkash', ['account' => '01712345678']);
        $this->assertNotNull($w1);
        $this->assertSame(1, $this->withdrawalService->getMonthlyWithdrawalCount(1));
        $this->assertSame(2, $this->withdrawalService->getRemainingMonthlyWithdrawals(1));

        // Request #2
        $w2 = $this->withdrawalService->createWithdrawal(1, new Money(20000, 'BDT'), 'bkash', ['account' => '01712345678']);
        $this->assertNotNull($w2);
        $this->assertSame(2, $this->withdrawalService->getMonthlyWithdrawalCount(1));
        $this->assertSame(1, $this->withdrawalService->getRemainingMonthlyWithdrawals(1));

        // Request #3
        $w3 = $this->withdrawalService->createWithdrawal(1, new Money(30000, 'BDT'), 'bkash', ['account' => '01712345678']);
        $this->assertNotNull($w3);
        $this->assertSame(3, $this->withdrawalService->getMonthlyWithdrawalCount(1));
        $this->assertSame(0, $this->withdrawalService->getRemainingMonthlyWithdrawals(1));

        // Request #4 -> Exceeds monthly limit of 3
        try {
            $this->withdrawalService->createWithdrawal(1, new Money(10000, 'BDT'), 'bkash', ['account' => '01712345678']);
            $this->fail('Expected monthly count limit violation');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Monthly withdrawal limit of 3 requests reached', $e->getMessage());
        }
    }

    public function testMonthlyCountResetsInNewCalendarMonth(): void
    {
        $this->withdrawalService->updateSettings([
            'enabled'           => true,
            'min_amount'        => 100.0,
            'max_monthly_count' => 2,
        ]);
        $this->fundWallet(1, 100000, 'BDT');

        // Current calendar month withdrawals
        $this->withdrawalService->createWithdrawal(1, new Money(10000, 'BDT'), 'bkash', ['account' => '01712345678']);
        $this->withdrawalService->createWithdrawal(1, new Money(10000, 'BDT'), 'bkash', ['account' => '01712345678']);

        $this->assertSame(2, $this->withdrawalService->getMonthlyWithdrawalCount(1));
        $this->assertSame(0, $this->withdrawalService->getRemainingMonthlyWithdrawals(1));

        // Next calendar month count should be 0
        $nextMonth = date('Y-m', strtotime('+1 month'));
        $this->assertSame(0, $this->withdrawalService->getMonthlyWithdrawalCount(1, $nextMonth));
        $this->assertSame(2, $this->withdrawalService->getRemainingMonthlyWithdrawals(1, $nextMonth));
    }

    public function testNoMonthlyAmountLimitImposed(): void
    {
        $this->withdrawalService->updateSettings([
            'enabled'           => true,
            'min_amount'        => 500.0,
            'max_monthly_count' => 3,
        ]);
        $this->fundWallet(1, 500000, 'BDT'); // 500,000 BDT

        // Three very large withdrawals totaling 400,000 BDT
        $w1 = $this->withdrawalService->createWithdrawal(1, new Money(10000000, 'BDT'), 'bkash', ['account' => '01712345678']); // 100k
        $w2 = $this->withdrawalService->createWithdrawal(1, new Money(15000000, 'BDT'), 'bkash', ['account' => '01712345678']); // 150k
        $w3 = $this->withdrawalService->createWithdrawal(1, new Money(15000000, 'BDT'), 'bkash', ['account' => '01712345678']); // 150k

        $this->assertSame(10000000, $w1->getAmount()->getAmount());
        $this->assertSame(15000000, $w2->getAmount()->getAmount());
        $this->assertSame(15000000, $w3->getAmount()->getAmount());
        $this->assertSame(3, $this->withdrawalService->getMonthlyWithdrawalCount(1));
    }

    public function testFeeDoesNotInvalidateRequestAtMinimumAmount(): void
    {
        $this->withdrawalService->updateSettings([
            'enabled'    => true,
            'min_amount' => 500.0,
            'fee_fixed'  => 20.0, // 20 BDT fee
        ]);
        $this->fundWallet(1, 5000, 'BDT');

        // Request exactly 500 BDT. Fee is 20 BDT, net amount is 480 BDT.
        // Gross amount = 500 BDT which meets minimum withdrawal requirement.
        $w = $this->withdrawalService->createWithdrawal(1, new Money(50000, 'BDT'), 'bkash', ['account' => '01712345678']);

        $this->assertSame(50000, $w->getAmount()->getAmount());
        $this->assertSame(2000, $w->getFee()->getAmount());
        $this->assertSame(48000, $w->getNetAmount()->getAmount());
    }

    public function testRejectedFailedAndCancelledRequestsReleaseMonthlySlot(): void
    {
        $this->withdrawalService->updateSettings([
            'enabled'           => true,
            'min_amount'        => 100.0,
            'max_monthly_count' => 1,
        ]);
        $this->fundWallet(1, 10000, 'BDT');

        // 1. Create request #1 -> consumes only slot
        $w1 = $this->withdrawalService->createWithdrawal(1, new Money(10000, 'BDT'), 'bkash', ['account' => '01712345678']);
        $this->assertSame(1, $this->withdrawalService->getMonthlyWithdrawalCount(1));
        $this->assertSame(0, $this->withdrawalService->getRemainingMonthlyWithdrawals(1));

        // Attempting another request while w1 is PENDING fails
        try {
            $this->withdrawalService->createWithdrawal(1, new Money(10000, 'BDT'), 'bkash', ['account' => '01712345678']);
            $this->fail('Expected limit reached');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Monthly withdrawal limit', $e->getMessage());
        }

        // 2. Reject w1 -> slot is released!
        $this->withdrawalService->reject($w1->getId(), 99, 'Incorrect account number');
        $this->assertSame(0, $this->withdrawalService->getMonthlyWithdrawalCount(1));
        $this->assertSame(1, $this->withdrawalService->getRemainingMonthlyWithdrawals(1));

        // 3. Now user can create a replacement request!
        $w2 = $this->withdrawalService->createWithdrawal(1, new Money(10000, 'BDT'), 'bkash', ['account' => '01712345679']);
        $this->assertSame(1, $this->withdrawalService->getMonthlyWithdrawalCount(1));

        // 4. Cancel w2 -> slot released again
        $this->withdrawalService->cancel($w2->getId(), 1, 'Changed mind');
        $this->assertSame(0, $this->withdrawalService->getMonthlyWithdrawalCount(1));
        $this->assertSame(1, $this->withdrawalService->getRemainingMonthlyWithdrawals(1));

        // 5. Create w3, approve, move to processing, then fail it -> slot released
        $w3 = $this->withdrawalService->createWithdrawal(1, new Money(10000, 'BDT'), 'bkash', ['account' => '01712345679']);
        $this->withdrawalService->approve($w3->getId(), 99);
        $this->withdrawalService->startProcessing($w3->getId(), 99);
        $this->assertSame(1, $this->withdrawalService->getMonthlyWithdrawalCount(1));

        $this->withdrawalService->markFailed($w3->getId(), 99, 'Gateway timeout');
        $this->assertSame(0, $this->withdrawalService->getMonthlyWithdrawalCount(1));
        $this->assertSame(1, $this->withdrawalService->getRemainingMonthlyWithdrawals(1));
    }

    public function testDuplicatePostWithIdempotencyKeyDoesNotConsumeMultipleSlots(): void
    {
        $this->withdrawalService->updateSettings([
            'enabled'           => true,
            'min_amount'        => 100.0,
            'max_monthly_count' => 2,
        ]);
        $this->fundWallet(1, 10000, 'BDT');

        $key = 'idem_unique_test_key_123';
        $w1 = $this->withdrawalService->createWithdrawal(1, new Money(10000, 'BDT'), 'bkash', ['account' => '01712345678'], $key);
        $w2 = $this->withdrawalService->createWithdrawal(1, new Money(10000, 'BDT'), 'bkash', ['account' => '01712345678'], $key);

        $this->assertSame($w1->getId(), $w2->getId());
        $this->assertSame(1, $this->withdrawalService->getMonthlyWithdrawalCount(1));
        $this->assertSame(1, $this->withdrawalService->getRemainingMonthlyWithdrawals(1));
    }

    public function testCustomerWithdrawGetDisplaysMonthlyCountersAndLimits(): void
    {
        $this->setLoggedInUser(1);
        $this->withdrawalService->updateSettings([
            'enabled'           => true,
            'min_amount'        => 500.0,
            'max_monthly_count' => 5,
        ]);
        $this->fundWallet(1, 2000, 'BDT');

        // Make 1 withdrawal
        $this->withdrawalService->createWithdrawal(1, new Money(50000, 'BDT'), 'bkash', ['account' => '01712345678']);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/withdraw']);
        $response = $this->customerController->withdraw($request);

        $this->assertSame(200, $response->getStatusCode());
        $html = (string)$response->getContent();

        $this->assertStringContainsString('Minimum withdrawal:', $html);
        $this->assertStringContainsString('500.00', $html);
        $this->assertStringContainsString('Withdrawals this month:', $html);
        $this->assertStringContainsString('1 / 5', $html);
        $this->assertStringContainsString('Remaining this month:', $html);
        $this->assertStringContainsString('4', $html);
    }

    public function testCustomerWithdrawShowsNoticeWhenMonthlyLimitReached(): void
    {
        $this->setLoggedInUser(1);
        $this->withdrawalService->updateSettings([
            'enabled'           => true,
            'min_amount'        => 100.0,
            'max_monthly_count' => 1,
        ]);
        $this->fundWallet(1, 5000, 'BDT');

        // Exhaust monthly count
        $this->withdrawalService->createWithdrawal(1, new Money(10000, 'BDT'), 'bkash', ['account' => '01712345678']);

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/withdraw']);
        $response = $this->customerController->withdraw($request);

        $this->assertSame(200, $response->getStatusCode());
        $html = (string)$response->getContent();
        $this->assertStringContainsString('Your monthly withdrawal limit has been reached', $html);
        $this->assertStringContainsString('disabled', $html);
    }

    public function testCustomerWithdrawShowsNoticeWhenAvailableBalanceBelowMinimum(): void
    {
        $this->setLoggedInUser(1);
        $this->withdrawalService->updateSettings([
            'enabled'           => true,
            'min_amount'        => 1000.0,
            'max_monthly_count' => 5,
        ]);
        $this->fundWallet(1, 400, 'BDT'); // 400 BDT in wallet, min is 1000 BDT

        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/withdraw']);
        $response = $this->customerController->withdraw($request);

        $this->assertSame(200, $response->getStatusCode());
        $html = (string)$response->getContent();
        $this->assertStringContainsString('Your available balance is below the minimum withdrawal amount of ৳1,000.00', $html);
        $this->assertStringContainsString('disabled', $html);
    }

    /* =========================================================================
     * PHASE 5 TESTS: WITHDRAWAL PROCESSING, ADMIN ACTIONS & AUDIT TRAIL
     * ========================================================================= */

    public function testPhase5FsmAllValidTransitions(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true, 'max_monthly_count' => 10]);
        $this->fundWallet(1, 50000, 'BDT');

        // 1. PENDING -> APPROVED -> PROCESSING -> PAID
        $w1 = $this->withdrawalService->createWithdrawal(1, new Money(50000, 'BDT'), 'bkash', ['account' => '01711111111']);
        $this->assertSame(WithdrawalStatus::PENDING, $w1->getStatus());

        $w1 = $this->withdrawalService->approve($w1->getId(), 99, 'Approved by supervisor');
        $this->assertSame(WithdrawalStatus::APPROVED, $w1->getStatus());

        $w1 = $this->withdrawalService->startProcessing($w1->getId(), 99, 'Processing initiated');
        $this->assertSame(WithdrawalStatus::PROCESSING, $w1->getStatus());

        $w1 = $this->withdrawalService->markPaid($w1->getId(), 99, 'BKASH-TRX-12345', 'Disbursed successfully');
        $this->assertSame(WithdrawalStatus::PAID, $w1->getStatus());
        $this->assertSame('BKASH-TRX-12345', $w1->getTransactionReference());
        $this->assertTrue($w1->getStatus()->isFinal());

        // 2. PENDING -> REJECTED
        $w2 = $this->withdrawalService->createWithdrawal(1, new Money(50000, 'BDT'), 'nagad', ['account' => '01811111111']);
        $w2 = $this->withdrawalService->reject($w2->getId(), 99, 'Invalid account details');
        $this->assertSame(WithdrawalStatus::REJECTED, $w2->getStatus());
        $this->assertTrue($w2->getStatus()->isFinal());

        // 3. PENDING -> CANCELLED (by customer)
        $w3 = $this->withdrawalService->createWithdrawal(1, new Money(50000, 'BDT'), 'rocket', ['account' => '01911111111']);
        $w3 = $this->withdrawalService->cancel($w3->getId(), 1, 'Customer cancelled request');
        $this->assertSame(WithdrawalStatus::CANCELLED, $w3->getStatus());
        $this->assertTrue($w3->getStatus()->isFinal());

        // 4. APPROVED -> REJECTED
        $w4 = $this->withdrawalService->createWithdrawal(1, new Money(50000, 'BDT'), 'bkash', ['account' => '01722222222']);
        $w4 = $this->withdrawalService->approve($w4->getId(), 99);
        $w4 = $this->withdrawalService->reject($w4->getId(), 99, 'Compliance block after approval');
        $this->assertSame(WithdrawalStatus::REJECTED, $w4->getStatus());

        // 5. APPROVED -> CANCELLED (by admin)
        $w5 = $this->withdrawalService->createWithdrawal(1, new Money(50000, 'BDT'), 'bkash', ['account' => '01733333333']);
        $w5 = $this->withdrawalService->approve($w5->getId(), 99);
        $w5 = $this->withdrawalService->cancel($w5->getId(), 99, 'Admin cancelled on customer phone request', true);
        $this->assertSame(WithdrawalStatus::CANCELLED, $w5->getStatus());

        // 6. PROCESSING -> FAILED
        $w6 = $this->withdrawalService->createWithdrawal(1, new Money(50000, 'BDT'), 'bank_transfer', ['bank_name' => 'City Bank', 'account_number' => '123456789']);
        $w6 = $this->withdrawalService->approve($w6->getId(), 99);
        $w6 = $this->withdrawalService->startProcessing($w6->getId(), 99);
        $w6 = $this->withdrawalService->markFailed($w6->getId(), 99, 'Bank gateway returned routing error');
        $this->assertSame(WithdrawalStatus::FAILED, $w6->getStatus());
        $this->assertTrue($w6->getStatus()->isFinal());
    }

    public function testPhase5FsmAllInvalidTransitionsForbidden(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true, 'max_monthly_count' => 10]);
        $this->fundWallet(1, 50000, 'BDT');

        // PENDING cannot jump directly to PAID
        $w = $this->withdrawalService->createWithdrawal(1, new Money(50000, 'BDT'), 'bkash', ['account' => '01711111111']);
        try {
            $this->withdrawalService->markPaid($w->getId(), 99, 'TRX123');
            $this->fail('Expected RuntimeException when jumping PENDING -> PAID');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot transition', $e->getMessage());
        }

        // PENDING cannot jump directly to PROCESSING
        try {
            $this->withdrawalService->startProcessing($w->getId(), 99);
            $this->fail('Expected RuntimeException when jumping PENDING -> PROCESSING');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot transition', $e->getMessage());
        }

        // APPROVED cannot jump directly to PAID
        $w = $this->withdrawalService->approve($w->getId(), 99);
        try {
            $this->withdrawalService->markPaid($w->getId(), 99, 'TRX123');
            $this->fail('Expected RuntimeException when jumping APPROVED -> PAID');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot transition', $e->getMessage());
        }

        // Move to PROCESSING
        $w = $this->withdrawalService->startProcessing($w->getId(), 99);

        // PROCESSING cannot transition to APPROVED or CANCELLED or REJECTED
        try {
            $this->withdrawalService->approve($w->getId(), 99);
            $this->fail('Expected RuntimeException when jumping PROCESSING -> APPROVED');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot transition', $e->getMessage());
        }

        try {
            $this->withdrawalService->cancel($w->getId(), 99, 'Reason', true);
            $this->fail('Expected RuntimeException when jumping PROCESSING -> CANCELLED');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot transition', $e->getMessage());
        }

        try {
            $this->withdrawalService->reject($w->getId(), 99, 'Reason');
            $this->fail('Expected RuntimeException when jumping PROCESSING -> REJECTED');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot transition', $e->getMessage());
        }

        // Mark PAID (terminal)
        $w = $this->withdrawalService->markPaid($w->getId(), 99, 'TRX999');

        // PAID (terminal) cannot transition to any other status
        try {
            $this->withdrawalService->markFailed($w->getId(), 99, 'Fail paid');
            $this->fail('Expected RuntimeException when transitioning from PAID');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot transition', $e->getMessage());
        }
    }

    public function testPhase5AccountingFinalizeHoldDebitsOnceAndIsIdempotent(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT'); // 10000 BDT = 1,000,000 minor
        $this->assertSame(1000000, $this->walletService->getAvailableBalance(1)->getAmount());

        // 1. Create withdrawal: Hold is placed
        $w = $this->withdrawalService->createWithdrawal(1, new Money(300000, 'BDT'), 'bkash', ['account' => '01712345678']);
        $this->assertSame(700000, $this->walletService->getAvailableBalance(1)->getAmount());

        // 2. Advance to PROCESSING
        $w = $this->withdrawalService->approve($w->getId(), 99);
        $w = $this->withdrawalService->startProcessing($w->getId(), 99);
        $this->assertSame(700000, $this->walletService->getAvailableBalance(1)->getAmount());

        // 3. Mark PAID -> finalizes hold
        $w = $this->withdrawalService->markPaid($w->getId(), 99, 'BKASH-REF-777', 'Paid out');
        $this->assertSame(WithdrawalStatus::PAID, $w->getStatus());
        $this->assertSame(700000, $this->walletService->getAvailableBalance(1)->getAmount());

        // 4. Repeated markPaid calls are idempotent and DO NOT double debit
        $wRepeat = $this->withdrawalService->markPaid($w->getId(), 99, 'BKASH-REF-777');
        $this->assertSame(WithdrawalStatus::PAID, $wRepeat->getStatus());
        $this->assertSame(700000, $this->walletService->getAvailableBalance(1)->getAmount());
    }

    public function testPhase5AccountingReleaseHoldRestoresBalanceOnceAndIsIdempotent(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT'); // 1,000,000 minor

        // Test Reject restores hold
        $w1 = $this->withdrawalService->createWithdrawal(1, new Money(400000, 'BDT'), 'bkash', ['account' => '01711111111']);
        $this->assertSame(600000, $this->walletService->getAvailableBalance(1)->getAmount());

        $w1 = $this->withdrawalService->reject($w1->getId(), 99, 'Rejected test');
        $this->assertSame(WithdrawalStatus::REJECTED, $w1->getStatus());
        $this->assertSame(1000000, $this->walletService->getAvailableBalance(1)->getAmount());

        // Calling reject again does NOT double-restore
        $w1Repeat = $this->withdrawalService->reject($w1->getId(), 99, 'Rejected test again');
        $this->assertSame(WithdrawalStatus::REJECTED, $w1Repeat->getStatus());
        $this->assertSame(1000000, $this->walletService->getAvailableBalance(1)->getAmount());

        // Test Mark Failed restores hold
        $w2 = $this->withdrawalService->createWithdrawal(1, new Money(500000, 'BDT'), 'nagad', ['account' => '01811111111']);
        $this->assertSame(500000, $this->walletService->getAvailableBalance(1)->getAmount());
        $w2 = $this->withdrawalService->approve($w2->getId(), 99);
        $w2 = $this->withdrawalService->startProcessing($w2->getId(), 99);

        $w2 = $this->withdrawalService->markFailed($w2->getId(), 99, 'Provider failure');
        $this->assertSame(WithdrawalStatus::FAILED, $w2->getStatus());
        $this->assertSame(1000000, $this->walletService->getAvailableBalance(1)->getAmount());

        // Calling markFailed again does NOT double-restore
        $w2Repeat = $this->withdrawalService->markFailed($w2->getId(), 99, 'Provider failure duplicate');
        $this->assertSame(WithdrawalStatus::FAILED, $w2Repeat->getStatus());
        $this->assertSame(1000000, $this->walletService->getAvailableBalance(1)->getAmount());
    }

    public function testPhase5AuditTrailRecordsCompleteLifecycle(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        // 1. Create -> action: created
        $w = $this->withdrawalService->createWithdrawal(1, new Money(200000, 'BDT'), 'bkash', ['account' => '01712345678']);
        $trail1 = $w->getAuditTrail();
        $this->assertCount(1, $trail1);
        $this->assertSame('created', $trail1[0]['action']);
        $this->assertSame(1, $trail1[0]['actor_id']);
        $this->assertNull($trail1[0]['prev_status']);
        $this->assertSame('pending', $trail1[0]['new_status']);
        $this->assertNotEmpty($trail1[0]['timestamp']);

        // 2. Approve -> action: approved
        $w = $this->withdrawalService->approve($w->getId(), 99, 'Initial review pass');
        $trail2 = $w->getAuditTrail();
        $this->assertCount(2, $trail2);
        $this->assertSame('approved', $trail2[1]['action']);
        $this->assertSame(99, $trail2[1]['actor_id']);
        $this->assertSame('pending', $trail2[1]['prev_status']);
        $this->assertSame('approved', $trail2[1]['new_status']);

        // 3. Start Processing -> action: processing_started
        $w = $this->withdrawalService->startProcessing($w->getId(), 99);
        $trail3 = $w->getAuditTrail();
        $this->assertCount(3, $trail3);
        $this->assertSame('processing_started', $trail3[2]['action']);
        $this->assertSame('approved', $trail3[2]['prev_status']);
        $this->assertSame('processing', $trail3[2]['new_status']);

        // 4. Update internal note -> action: note_updated
        $w = $this->withdrawalService->updateProcessingNotes($w->getId(), 99, 'Contacted customer to confirm bank routing');
        $trail4 = $w->getAuditTrail();
        $this->assertCount(4, $trail4);
        $this->assertSame('note_updated', $trail4[3]['action']);
        $this->assertSame('Contacted customer to confirm bank routing', $trail4[3]['metadata']['operator_notes']);

        // 5. Update reference -> action: reference_updated
        $w = $this->withdrawalService->updateTransactionReference($w->getId(), 99, 'TRX-REF-PENDING-001');
        $trail5 = $w->getAuditTrail();
        $this->assertCount(5, $trail5);
        $this->assertSame('reference_updated', $trail5[4]['action']);
        $this->assertSame('TRX-REF-PENDING-001', $trail5[4]['metadata']['transaction_reference']);

        // 6. Mark Paid -> action: paid
        $w = $this->withdrawalService->markPaid($w->getId(), 99, 'BKASH-FINAL-TRX-999', 'Disbursement confirmed');
        $trail6 = $w->getAuditTrail();
        $this->assertCount(6, $trail6);
        $this->assertSame('paid', $trail6[5]['action']);
        $this->assertSame('processing', $trail6[5]['prev_status']);
        $this->assertSame('paid', $trail6[5]['new_status']);
        $this->assertSame('BKASH-FINAL-TRX-999', $trail6[5]['metadata']['transaction_reference']);

        // Also query via service getAuditTrail
        $retrievedTrail = $this->withdrawalService->getAuditTrail($w->getId());
        $this->assertCount(6, $retrievedTrail);
    }

    public function testPhase5AdminSecurityAndPermissions(): void
    {
        // 1. Non-admin user cannot access admin queue
        $this->setLoggedInUser(2); // Regular customer
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-withdrawals']);
        $res = $this->adminController->handle($req);
        $this->assertInstanceOf(Response::class, $res);
        $this->assertSame(403, $res->getStatusCode());

        // 2. Admin action requires valid CSRF
        $this->setLoggedInUser(99, 'active', 'super_admin', ['super-admin'], [PaymentPermission::MANAGE_WITHDRAWALS, PaymentPermission::VIEW_WITHDRAWALS]);
        $reqCsrf = new Request([], [
            'action'        => 'approve',
            'withdrawal_id' => 'wd_fake',
            '_csrf_token'   => 'invalid_token',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-pay-withdrawals']);
        $resCsrf = $this->adminController->handle($reqCsrf);
        $this->assertInstanceOf(Response::class, $resCsrf);
        $this->assertSame(302, $resCsrf->getStatusCode());
        $this->assertSame('Invalid security token. Please try again.', $_SESSION['flash_error'] ?? '');
    }

    public function testPhase5AdminCanCancelApprovedWithdrawal(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        $w = $this->withdrawalService->createWithdrawal(1, new Money(300000, 'BDT'), 'bkash', ['account' => '01712345678']);
        $this->withdrawalService->approve($w->getId(), 99);
        $this->assertSame(700000, $this->walletService->getAvailableBalance(1)->getAmount());

        // Admin cancels the approved withdrawal
        $wCancelled = $this->withdrawalService->cancel($w->getId(), 99, 'Admin cancellation override', true);
        $this->assertSame(WithdrawalStatus::CANCELLED, $wCancelled->getStatus());

        // Funds released back to customer
        $this->assertSame(1000000, $this->walletService->getAvailableBalance(1)->getAmount());

        // Audit entry recorded
        $trail = $wCancelled->getAuditTrail();
        $lastEntry = end($trail);
        $this->assertSame('cancelled', $lastEntry['action']);
        $this->assertSame('admin', $lastEntry['metadata']['cancelled_by']);
    }

    public function testPhase5CustomerCannotSeeAdminNotes(): void
    {
        $this->setLoggedInUser(1);
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        $w = $this->withdrawalService->createWithdrawal(1, new Money(200000, 'BDT'), 'bkash', ['account' => '01712345678']);
        $this->withdrawalService->updateProcessingNotes($w->getId(), 99, 'SECRET_ADMIN_RISK_CHECK_PASSED');

        // Customer views own detail
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/withdrawals/' . $w->getId()]);
        $response = $this->customerController->withdrawalDetail($req, $w->getId());

        $this->assertSame(200, $response->getStatusCode());
        $html = (string)$response->getContent();

        // Customer CANNOT see secret admin notes
        $this->assertStringNotContainsString('SECRET_ADMIN_RISK_CHECK_PASSED', $html);
        $this->assertStringNotContainsString('Processing Notes', $html);

        // Customer CAN see timeline and financial summary
        $this->assertStringContainsString('Status Timeline', $html);
        $this->assertStringContainsString('Requested Amount', $html);
        $this->assertStringContainsString('Net Payable', $html);
    }

    public function testPhase5CustomerAntiIdorProtection(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        // User 1 creates withdrawal
        $w = $this->withdrawalService->createWithdrawal(1, new Money(200000, 'BDT'), 'bkash', ['account' => '01712345678']);

        // User 2 logs in and tries to access User 1's withdrawal
        $this->setLoggedInUser(2);
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/withdrawals/' . $w->getId()]);
        $response = $this->customerController->withdrawalDetail($req, $w->getId());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Access denied', (string)$response->getContent());
    }

    public function testPhase5AdminFiltersByMethodAndDateRange(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true, 'max_monthly_count' => 20]);
        $this->fundWallet(1, 50000, 'BDT');

        $wBkash = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01711111111']);
        $wNagad = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'nagad', ['account' => '01811111111']);
        $wBank  = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bank_transfer', ['bank_name' => 'City Bank', 'account_number' => '123456789']);

        // Filter by method bkash
        $bkashList = $this->withdrawalService->listWithdrawals(['method' => 'bkash']);
        $this->assertCount(1, $bkashList['items']);
        $this->assertSame('bkash', $bkashList['items'][0]->getMethod());

        // Filter by method nagad
        $nagadList = $this->withdrawalService->listWithdrawals(['method' => 'nagad']);
        $this->assertCount(1, $nagadList['items']);
        $this->assertSame('nagad', $nagadList['items'][0]->getMethod());

        // Filter by date range covering today
        $today = date('Y-m-d');
        $dateList = $this->withdrawalService->listWithdrawals(['date_from' => $today, 'date_to' => $today]);
        $this->assertGreaterThanOrEqual(3, count($dateList['items']));
    }

    public function testPhase5MonthlySlotReleasesOnTerminalFailure(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true, 'max_monthly_count' => 2]);
        $this->fundWallet(1, 20000, 'BDT');

        // Request 1: Active
        $w1 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01711111111']);
        $this->assertSame(1, $this->withdrawalService->getMonthlyWithdrawalCount(1));
        $this->assertSame(1, $this->withdrawalService->getRemainingMonthlyWithdrawals(1));

        // Request 2: Active -> limit reached
        $w2 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01722222222']);
        $this->assertSame(2, $this->withdrawalService->getMonthlyWithdrawalCount(1));
        $this->assertSame(0, $this->withdrawalService->getRemainingMonthlyWithdrawals(1));

        // Request 3 blocked
        try {
            $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01733333333']);
            $this->fail('Expected monthly limit reached exception');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Monthly withdrawal limit', $e->getMessage());
        }

        // Now move w2 to PROCESSING, then mark FAILED -> slot must be RELEASED!
        $this->withdrawalService->approve($w2->getId(), 99);
        $this->withdrawalService->startProcessing($w2->getId(), 99);
        $this->withdrawalService->markFailed($w2->getId(), 99, 'Provider down');

        $this->assertSame(1, $this->withdrawalService->getMonthlyWithdrawalCount(1));
        $this->assertSame(1, $this->withdrawalService->getRemainingMonthlyWithdrawals(1));

        // Now request 3 can proceed because slot was released!
        $w3 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01733333333']);
        $this->assertSame(WithdrawalStatus::PENDING, $w3->getStatus());
        $this->assertSame(2, $this->withdrawalService->getMonthlyWithdrawalCount(1));
    }


    // =========================================================================
    // 12. PHASE 6 — WITHDRAWAL NOTIFICATIONS & CUSTOMER ACTIVITY TESTS
    // =========================================================================

    public function testPhase6WithdrawalCreatedNotification(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        $notifService = $this->withdrawalService->getNotificationService();
        $this->assertNotNull($notifService);

        $w = $this->withdrawalService->createWithdrawal(
            1,
            new Money(150000, 'BDT'),
            'bkash',
            ['account' => '01712345678']
        );

        $userNotifs = $notifService->listUserNotifications(1);
        $this->assertCount(1, $userNotifs['items']);

        $notif = $userNotifs['items'][0];
        $this->assertSame(NotificationServiceInterface::TYPE_WITHDRAWAL_CREATED, $notif['type']);
        $this->assertSame('Withdrawal Request Submitted', $notif['title']);
        $this->assertStringContainsString($w->getId(), $notif['message']);
        $this->assertSame($w->getId(), $notif['withdrawal_id']);
        $this->assertFalse((bool)$notif['is_read']);
        $this->assertSame(1, $notifService->getUnreadCount(1));
    }

    public function testPhase6AllWithdrawalLifecycleTransitionsEmitNotifications(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true, 'max_monthly_count' => 10]);
        $this->fundWallet(1, 50000, 'BDT');
        $notifService = $this->withdrawalService->getNotificationService();

        // 1. Full flow: Created -> Approved -> Processing -> Paid
        $w1 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01711111111']);
        $w1 = $this->withdrawalService->approve($w1->getId(), 99);
        $w1 = $this->withdrawalService->startProcessing($w1->getId(), 99);
        $w1 = $this->withdrawalService->markPaid($w1->getId(), 99, 'BKASH-TRX-101');

        $w1Notifs = $notifService->getNotificationsForWithdrawal($w1->getId());
        $this->assertCount(4, $w1Notifs);

        $types1 = array_column($w1Notifs, 'type');
        $this->assertSame([
            NotificationServiceInterface::TYPE_WITHDRAWAL_CREATED,
            NotificationServiceInterface::TYPE_WITHDRAWAL_APPROVED,
            NotificationServiceInterface::TYPE_WITHDRAWAL_PROCESSING,
            NotificationServiceInterface::TYPE_WITHDRAWAL_PAID,
        ], $types1);

        // 2. Flow: Created -> Rejected
        $w2 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01722222222']);
        $w2 = $this->withdrawalService->reject($w2->getId(), 99, 'Invalid details');

        $w2Notifs = $notifService->getNotificationsForWithdrawal($w2->getId());
        $this->assertCount(2, $w2Notifs);
        $this->assertSame(NotificationServiceInterface::TYPE_WITHDRAWAL_REJECTED, $w2Notifs[1]['type']);
        $this->assertSame('Withdrawal Request Rejected', $w2Notifs[1]['title']);

        // 3. Flow: Created -> Cancelled
        $w3 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01733333333']);
        $w3 = $this->withdrawalService->cancel($w3->getId(), 1, 'User changed mind');

        $w3Notifs = $notifService->getNotificationsForWithdrawal($w3->getId());
        $this->assertCount(2, $w3Notifs);
        $this->assertSame(NotificationServiceInterface::TYPE_WITHDRAWAL_CANCELLED, $w3Notifs[1]['type']);
        $this->assertSame('Withdrawal Request Cancelled', $w3Notifs[1]['title']);

        // 4. Flow: Created -> Approved -> Processing -> Failed
        $w4 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01744444444']);
        $w4 = $this->withdrawalService->approve($w4->getId(), 99);
        $w4 = $this->withdrawalService->startProcessing($w4->getId(), 99);
        $w4 = $this->withdrawalService->markFailed($w4->getId(), 99, 'Provider Gateway Timeout');

        $w4Notifs = $notifService->getNotificationsForWithdrawal($w4->getId());
        $this->assertCount(4, $w4Notifs);
        $this->assertSame(NotificationServiceInterface::TYPE_WITHDRAWAL_FAILED, $w4Notifs[3]['type']);
        $this->assertSame('Withdrawal Could Not Be Completed', $w4Notifs[3]['title']);
    }

    public function testPhase6NotificationStrictIdempotency(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');
        $notifService = $this->withdrawalService->getNotificationService();

        $w = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01712345678']);

        // Calling notify again with identical withdrawal_id and type
        $duplicate = $notifService->notify(
            1,
            NotificationServiceInterface::TYPE_WITHDRAWAL_CREATED,
            'Withdrawal Request Submitted',
            'Duplicate attempt',
            $w->getId()
        );

        $notifs = $notifService->getNotificationsForWithdrawal($w->getId());
        $this->assertCount(1, $notifs, 'Duplicate notification for identical withdrawal and type must be prevented');
        $this->assertSame($notifs[0]['id'], $duplicate['id']);
    }

    public function testPhase6CustomerNotificationSafetyNoInternalNotesLeaked(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');
        $notifService = $this->withdrawalService->getNotificationService();

        $w = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01712345678']);
        $this->withdrawalService->updateProcessingNotes($w->getId(), 99, 'SUPER_SECRET_COMPLIANCE_INTERNAL_NOTE');
        $this->withdrawalService->reject($w->getId(), 99, 'Mismatched NID information');

        $notifs = $notifService->listUserNotifications(1)['items'];
        foreach ($notifs as $n) {
            $this->assertStringNotContainsString('SUPER_SECRET_COMPLIANCE_INTERNAL_NOTE', $n['title']);
            $this->assertStringNotContainsString('SUPER_SECRET_COMPLIANCE_INTERNAL_NOTE', $n['message']);
            $this->assertStringNotContainsString('SUPER_SECRET_COMPLIANCE_INTERNAL_NOTE', json_encode($n['data'] ?? []));
        }
    }

    public function testPhase6CustomerNotificationReadStatusManagement(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true, 'max_monthly_count' => 10]);
        $this->fundWallet(1, 30000, 'BDT');
        $notifService = $this->withdrawalService->getNotificationService();

        $w1 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01711111111']);
        $w2 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01722222222']);
        $w3 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01733333333']);

        $this->assertSame(3, $notifService->getUnreadCount(1));

        $list = $notifService->listUserNotifications(1)['items'];
        $this->assertCount(3, $list);

        // Mark single as read
        $firstId = $list[0]['id'];
        $ok = $notifService->markAsRead($firstId, 1);
        $this->assertTrue($ok);
        $this->assertSame(2, $notifService->getUnreadCount(1));

        $item = $notifService->getNotification($firstId, 1);
        $this->assertNotNull($item);
        $this->assertTrue((bool)$item['is_read']);
        $this->assertNotNull($item['read_at']);

        // Mark all as read
        $count = $notifService->markAllAsRead(1);
        $this->assertSame(2, $count);
        $this->assertSame(0, $notifService->getUnreadCount(1));
    }

    public function testPhase6AntiIdorNotificationSecurity(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');
        $notifService = $this->withdrawalService->getNotificationService();

        $w = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01712345678']);
        $notifsUser1 = $notifService->listUserNotifications(1)['items'];
        $notifId = $notifsUser1[0]['id'];

        // User 2 cannot access User 1's notification
        $this->assertNull($notifService->getNotification($notifId, 2));

        // User 2 cannot mark User 1's notification as read
        $this->assertFalse($notifService->markAsRead($notifId, 2));

        // User 2 listing returns empty
        $this->assertEmpty($notifService->listUserNotifications(2)['items']);
    }

    public function testPhase6CustomerNotificationsControllerGetAndFilter(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true, 'max_monthly_count' => 10]);
        $this->fundWallet(1, 20000, 'BDT');
        $notifService = $this->withdrawalService->getNotificationService();

        $w1 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01711111111']);
        $w2 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01722222222']);

        $list = $notifService->listUserNotifications(1)['items'];
        // Mark first one as read
        $notifService->markAsRead($list[0]['id'], 1);

        $this->setLoggedInUser(1);

        // GET all notifications
        $reqAll = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/notifications']);
        $resAll = $this->customerController->notifications($reqAll);
        $this->assertSame(200, $resAll->getStatusCode());
        $htmlAll = (string)$resAll->getContent();
        $this->assertStringContainsString('Activity &amp; Notifications', $htmlAll);
        $this->assertStringContainsString($w1->getId(), $htmlAll);
        $this->assertStringContainsString($w2->getId(), $htmlAll);

        // GET filtered by unread
        $reqUnread = new Request(['filter' => 'unread'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/notifications?filter=unread']);
        $resUnread = $this->customerController->notifications($reqUnread);
        $this->assertSame(200, $resUnread->getStatusCode());
        $htmlUnread = (string)$resUnread->getContent();
        $this->assertStringContainsString($w2->getId(), $htmlUnread);
        $this->assertStringNotContainsString($w1->getId(), $htmlUnread);
    }

    public function testPhase6CustomerNotificationsControllerPostMarkReadAndMarkAllReadWithCsrf(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true, 'max_monthly_count' => 10]);
        $this->fundWallet(1, 20000, 'BDT');
        $notifService = $this->withdrawalService->getNotificationService();

        $w1 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01711111111']);
        $w2 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01722222222']);
        $this->assertSame(2, $notifService->getUnreadCount(1));

        $list = $notifService->listUserNotifications(1)['items'];
        $firstId = $list[0]['id'];

        $this->setLoggedInUser(1);

        // 1. Invalid CSRF -> 302 with flash error
        $reqBadCsrf = new Request([], [
            'action'          => 'mark_read',
            'notification_id' => $firstId,
            '_csrf_token'     => 'invalid-token',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/notifications']);

        $resBadCsrf = $this->customerController->notifications($reqBadCsrf);
        $this->assertSame(302, $resBadCsrf->getStatusCode());
        $this->assertSame('Invalid or expired security token.', $_SESSION['flash_error'] ?? '');
        $this->assertSame(2, $notifService->getUnreadCount(1));

        // 2. Valid CSRF mark single as read
        $reqMarkRead = new Request([], [
            'action'          => 'mark_read',
            'notification_id' => $firstId,
            '_csrf_token'     => 'valid-test-csrf-token',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/notifications']);

        $resMarkRead = $this->customerController->notifications($reqMarkRead);
        $this->assertSame(302, $resMarkRead->getStatusCode());
        $this->assertSame(1, $notifService->getUnreadCount(1));

        // 3. Valid CSRF mark all as read
        $reqMarkAll = new Request([], [
            'action'      => 'mark_all_read',
            '_csrf_token' => 'valid-test-csrf-token',
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/notifications']);

        $resMarkAll = $this->customerController->notifications($reqMarkAll);
        $this->assertSame(302, $resMarkAll->getStatusCode());
        $this->assertSame(0, $notifService->getUnreadCount(1));
    }

    public function testPhase6CustomerWithdrawalDetailShowsWithdrawalSpecificNotifications(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true, 'max_monthly_count' => 10]);
        $this->fundWallet(1, 30000, 'BDT');

        $w1 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01711111111']);
        $this->withdrawalService->approve($w1->getId(), 99);

        $w2 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01722222222']);

        $this->setLoggedInUser(1);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/withdrawals/' . $w1->getId()]);
        $res = $this->customerController->withdrawalDetail($req, $w1->getId());

        $this->assertSame(200, $res->getStatusCode());
        $html = (string)$res->getContent();

        $this->assertStringContainsString('Activity &amp; Updates', $html);
        $this->assertStringContainsString('Withdrawal Request Submitted', $html);
        $this->assertStringContainsString('Withdrawal Request Approved', $html);
        $this->assertStringContainsString($w1->getId(), $html);
        $this->assertStringNotContainsString($w2->getId(), $html);
    }

    // =========================================================================
    // 13. PHASE 7 — WITHDRAWAL REPORTING, SEARCH & CSV EXPORT TESTS
    // =========================================================================

    public function testPhase7FilteringByStatusAndInvalidStatusValidation(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true, 'max_monthly_count' => 20]);
        $this->fundWallet(1, 50000, 'BDT');

        $w1 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01711111111']);
        $w2 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01722222222']);
        $this->withdrawalService->approve($w2->getId(), 99);

        $w3 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01733333333']);
        $this->withdrawalService->approve($w3->getId(), 99);
        $this->withdrawalService->startProcessing($w3->getId(), 99);
        $this->withdrawalService->markPaid($w3->getId(), 99, 'TRX-PAID-001');

        // 1. Filter by status = pending
        $resPending = $this->withdrawalService->listWithdrawals(['status' => 'pending']);
        $this->assertCount(1, $resPending['items']);
        $this->assertSame($w1->getId(), $resPending['items'][0]->getId());

        // 2. Filter by status = paid
        $resPaid = $this->withdrawalService->listWithdrawals(['status' => 'paid']);
        $this->assertCount(1, $resPaid['items']);
        $this->assertSame($w3->getId(), $resPaid['items'][0]->getId());

        // 3. Filter by invalid/untrusted status string -> 0 results
        $resInvalid = $this->withdrawalService->listWithdrawals(['status' => 'malicious_or_unknown_status']);
        $this->assertCount(0, $resInvalid['items']);

        // 4. Filter by status = all -> returns all
        $resAll = $this->withdrawalService->listWithdrawals(['status' => 'all']);
        $this->assertGreaterThanOrEqual(3, count($resAll['items']));
    }

    public function testPhase7FilteringByMethodAndAliases(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true, 'max_monthly_count' => 20]);
        $this->fundWallet(1, 50000, 'BDT');

        $wBkash = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01711111111']);
        $wNagad = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'nagad', ['account' => '01811111111']);
        $wBank  = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bank_transfer', ['bank_name' => 'BRAC Bank', 'account_number' => '123456789']);

        // Filter by bkash_personal matches bkash
        $resBkash = $this->withdrawalService->listWithdrawals(['method' => 'bkash_personal']);
        $this->assertCount(1, $resBkash['items']);
        $this->assertSame('bkash', $resBkash['items'][0]->getMethod());

        // Filter by nagad_personal matches nagad
        $resNagad = $this->withdrawalService->listWithdrawals(['method' => 'nagad_personal']);
        $this->assertCount(1, $resNagad['items']);
        $this->assertSame('nagad', $resNagad['items'][0]->getMethod());

        // Filter by bank_transfer matches bank_transfer
        $resBank = $this->withdrawalService->listWithdrawals(['method' => 'bank_transfer']);
        $this->assertCount(1, $resBank['items']);
        $this->assertSame('bank_transfer', $resBank['items'][0]->getMethod());
    }

    public function testPhase7FilteringByDateRange(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true, 'max_monthly_count' => 20]);
        $this->fundWallet(1, 50000, 'BDT');

        $w = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01711111111']);

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        // 1. Same-day range covering today
        $resToday = $this->withdrawalService->listWithdrawals(['date_from' => $today, 'date_to' => $today]);
        $this->assertGreaterThanOrEqual(1, count($resToday['items']));

        // 2. From date only
        $resFrom = $this->withdrawalService->listWithdrawals(['date_from' => $today]);
        $this->assertGreaterThanOrEqual(1, count($resFrom['items']));

        // 3. To date only
        $resTo = $this->withdrawalService->listWithdrawals(['date_to' => $today]);
        $this->assertGreaterThanOrEqual(1, count($resTo['items']));

        // 4. Past date range returning 0
        $resPast = $this->withdrawalService->listWithdrawals(['date_from' => '2020-01-01', 'date_to' => '2020-01-02']);
        $this->assertCount(0, $resPast['items']);
    }

    public function testPhase7SearchByCustomerAndWithdrawalIdAndRef(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true, 'max_monthly_count' => 20]);
        $this->fundWallet(1, 50000, 'BDT');

        $w = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01799887766']);
        $this->withdrawalService->updateTransactionReference($w->getId(), 99, 'SPECIAL-PAYOUT-TRX-777');

        // 1. Search by exact withdrawal ID
        $resId = $this->withdrawalService->listWithdrawals(['search' => $w->getId()]);
        $this->assertCount(1, $resId['items']);
        $this->assertSame($w->getId(), $resId['items'][0]->getId());

        // 2. Search by transaction reference
        $resRef = $this->withdrawalService->listWithdrawals(['search' => 'SPECIAL-PAYOUT-TRX-777']);
        $this->assertCount(1, $resRef['items']);
        $this->assertSame($w->getId(), $resRef['items'][0]->getId());

        // 3. Search by destination masked snippet
        $resDest = $this->withdrawalService->listWithdrawals(['search' => '7766']);
        $this->assertCount(1, $resDest['items']);

        // 4. Search by numeric user ID
        $resUser = $this->withdrawalService->listWithdrawals(['search' => '1']);
        $this->assertGreaterThanOrEqual(1, count($resUser['items']));
    }

    public function testPhase7CombinedFiltersStrictAndLogic(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true, 'max_monthly_count' => 20]);
        $this->fundWallet(1, 50000, 'BDT');

        $wMatch = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01711111111']);
        $this->withdrawalService->approve($wMatch->getId(), 99);
        $this->withdrawalService->startProcessing($wMatch->getId(), 99);
        $this->withdrawalService->markPaid($wMatch->getId(), 99, 'BKASH-COMBINED-01');

        $wOtherStatus = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01722222222']);
        $wOtherMethod = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bank_transfer', ['bank_name' => 'EBL', 'account_number' => '999']);
        $this->withdrawalService->approve($wOtherMethod->getId(), 99);
        $this->withdrawalService->startProcessing($wOtherMethod->getId(), 99);
        $this->withdrawalService->markPaid($wOtherMethod->getId(), 99, 'EBL-TRX-02');

        // Combine: status=paid AND method=bkash_personal AND search=COMBINED
        $res = $this->withdrawalService->listWithdrawals([
            'status' => 'paid',
            'method' => 'bkash_personal',
            'search' => 'COMBINED',
        ]);

        $this->assertCount(1, $res['items']);
        $this->assertSame($wMatch->getId(), $res['items'][0]->getId());
    }

    public function testPhase7SummaryStatisticsCountsAndMonetaryTotals(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true, 'max_monthly_count' => 20]);
        $this->fundWallet(1, 100000, 'BDT');

        // W1: 1,000 BDT pending (100,000 cents)
        $w1 = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01711111111']);

        // W2: 2,000 BDT paid (200,000 cents)
        $w2 = $this->withdrawalService->createWithdrawal(1, new Money(200000, 'BDT'), 'bkash', ['account' => '01722222222']);
        $this->withdrawalService->approve($w2->getId(), 99);
        $this->withdrawalService->startProcessing($w2->getId(), 99);
        $this->withdrawalService->markPaid($w2->getId(), 99, 'PAID-REF-1');

        // W3: 1,500 BDT rejected (150,000 cents)
        $w3 = $this->withdrawalService->createWithdrawal(1, new Money(150000, 'BDT'), 'bkash', ['account' => '01733333333']);
        $this->withdrawalService->reject($w3->getId(), 99, 'Invalid account');

        $summary = $this->withdrawalService->getSummary();

        // 1. Verify counts
        $this->assertSame(3, $summary['counts']['total']);
        $this->assertSame(1, $summary['counts']['pending']);
        $this->assertSame(1, $summary['counts']['paid']);
        $this->assertSame(1, $summary['counts']['rejected']);
        $this->assertSame(0, $summary['counts']['approved']);
        $this->assertSame(0, $summary['counts']['processing']);
        $this->assertSame(0, $summary['counts']['cancelled']);
        $this->assertSame(0, $summary['counts']['failed']);

        // 2. Verify monetary totals
        $this->assertSame(450000, $summary['totals']['gross_cents']);
        $this->assertSame(0, $summary['totals']['fee_cents']);
        $this->assertSame(450000, $summary['totals']['net_cents']);
        $this->assertSame(200000, $summary['totals']['paid_gross_cents']);
        $this->assertSame(200000, $summary['totals']['paid_net_cents']);
        $this->assertSame('BDT', $summary['totals']['currency']);
    }

    public function testPhase7ExportCsvColumnsContentAndSanitization(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 20000, 'BDT');

        // Potentially malicious destination trying CSV formula injection
        $w = $this->withdrawalService->createWithdrawal(1, new Money(150000, 'BDT'), 'bkash', ['account' => '01712345678']);
        $this->withdrawalService->updateTransactionReference($w->getId(), 99, '=cmd|\' /C calc\'!A0');

        $csv = $this->withdrawalService->exportWithdrawalsCsv();

        // 1. Check UTF-8 BOM present
        $this->assertTrue(str_starts_with($csv, "\xEF\xBB\xBF"));

        // 2. Check header columns
        $this->assertStringContainsString('"Withdrawal ID"', $csv);
        $this->assertStringContainsString('"User ID"', $csv);
        $this->assertStringContainsString('Username', $csv);
        $this->assertStringContainsString('Email', $csv);
        $this->assertStringContainsString('"Gross Amount"', $csv);
        $this->assertStringContainsString('Fee', $csv);
        $this->assertStringContainsString('"Net Amount"', $csv);
        $this->assertStringContainsString('Currency', $csv);
        $this->assertStringContainsString('Method', $csv);
        $this->assertStringContainsString('"Masked Destination"', $csv);
        $this->assertStringContainsString('Status', $csv);
        $this->assertStringContainsString('"Payout Reference"', $csv);
        $this->assertStringContainsString('"Created At"', $csv);
        $this->assertStringContainsString('"Updated At"', $csv);
        $this->assertStringContainsString('"Paid At"', $csv);

        // 3. Check data columns
        $this->assertStringContainsString($w->getId(), $csv);
        $this->assertStringContainsString('1500.00', $csv);
        $this->assertStringContainsString('0.00', $csv);
        $this->assertStringContainsString('BDT', $csv);
        $this->assertStringContainsString('017****5678', $csv);
        $this->assertStringContainsString('Pending Review', $csv);

        // 4. Formula injection neutralized: '=cmd' is prefixed with single quote
        $this->assertStringContainsString("'=cmd|", $csv);
    }

    public function testPhase7AdminControllerCsvExportActionAndCsrfSecurity(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');
        $w = $this->withdrawalService->createWithdrawal(1, new Money(100000, 'BDT'), 'bkash', ['account' => '01711111111']);

        // 1. Unauthorized admin (regular customer) blocked -> 403
        $this->setLoggedInUser(2);
        $reqNonAdmin = new Request([], ['action' => 'export_csv', '_csrf_token' => 'valid-test-csrf-token'], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-pay-withdrawals']);
        $resNonAdmin = $this->adminController->handle($reqNonAdmin);
        $this->assertSame(403, $resNonAdmin->getStatusCode());

        // 2. Admin with view permission but invalid CSRF on POST -> 302 with flash error
        $this->setLoggedInUser(99, 'active', 'admin', ['admin'], [PaymentPermission::VIEW_WITHDRAWALS]);
        $reqBadCsrf = new Request([], ['action' => 'export_csv', '_csrf_token' => 'invalid-token'], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-pay-withdrawals']);
        $resBadCsrf = $this->adminController->handle($reqBadCsrf);
        $this->assertSame(302, $resBadCsrf->getStatusCode());
        $this->assertSame('Invalid security token. Please try again.', $_SESSION['flash_error'] ?? '');

        // 3. Admin with view permission valid POST export -> 200 CSV Response
        $reqValidPost = new Request([], ['action' => 'export_csv', '_csrf_token' => 'valid-test-csrf-token'], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/page/favorite-pay-withdrawals']);
        $resValidPost = $this->adminController->handle($reqValidPost);
        $this->assertInstanceOf(Response::class, $resValidPost);
        $this->assertSame(200, $resValidPost->getStatusCode());
        $this->assertSame('text/csv; charset=UTF-8', $resValidPost->getHeader('Content-Type'));
        $this->assertStringContainsString('attachment; filename=', $resValidPost->getHeader('Content-Disposition') ?? '');
        $this->assertStringContainsString($w->getId(), $resValidPost->getContent());

        // 4. Admin with view permission GET export -> 200 CSV Response
        $reqValidGet = new Request(['action' => 'export_csv'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-withdrawals?action=export_csv']);
        $resValidGet = $this->adminController->handle($reqValidGet);
        $this->assertInstanceOf(Response::class, $resValidGet);
        $this->assertSame(200, $resValidGet->getStatusCode());
        $this->assertSame('text/csv; charset=UTF-8', $resValidGet->getHeader('Content-Type'));
    }

    public function testPhase7AccountingSafetyZeroFinancialSideEffects(): void
    {
        $this->withdrawalService->updateSettings(['enabled' => true]);
        $this->fundWallet(1, 10000, 'BDT');

        // Create 2,000 BDT withdrawal -> 8,000 available, 2,000 held
        $w = $this->withdrawalService->createWithdrawal(1, new Money(200000, 'BDT'), 'bkash', ['account' => '01712345678']);

        $initAvailable = $this->walletService->getAvailableBalance(1)->getAmount();
        $initTotal = $this->walletService->getBalance(1)->getAmount();
        $initMonthlyCount = $this->withdrawalService->getMonthlyWithdrawalCount(1);
        $initNotifCount = $this->withdrawalService->getNotificationService()->getUnreadCount(1);

        // Perform reporting and CSV export actions
        $this->withdrawalService->getSummary();
        $this->withdrawalService->exportWithdrawalsCsv();
        $this->withdrawalService->listWithdrawals(['status' => 'all']);

        $this->setLoggedInUser(99, 'active', 'super_admin', ['super-admin'], [PaymentPermission::MANAGE_WITHDRAWALS, PaymentPermission::VIEW_WITHDRAWALS]);
        $req = new Request(['action' => 'export_csv'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/page/favorite-pay-withdrawals?action=export_csv']);
        $this->adminController->handle($req);

        // 1. Wallet balances completely untouched
        $this->assertSame($initAvailable, $this->walletService->getAvailableBalance(1)->getAmount(), 'Available balance must remain completely unchanged');
        $this->assertSame($initTotal, $this->walletService->getBalance(1)->getAmount(), 'Total balance must remain completely unchanged');

        // 2. Monthly count untouched
        $this->assertSame($initMonthlyCount, $this->withdrawalService->getMonthlyWithdrawalCount(1), 'Monthly withdrawal quota must remain unchanged');

        // 3. Notification count untouched
        $this->assertSame($initNotifCount, $this->withdrawalService->getNotificationService()->getUnreadCount(1), 'Notification count must remain unchanged');

        // 4. Withdrawal status untouched
        $refreshed = $this->withdrawalService->getWithdrawal($w->getId());
        $this->assertSame(WithdrawalStatus::PENDING, $refreshed->getStatus());
    }
}

