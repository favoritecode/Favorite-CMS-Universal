<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Core\AccountMenu;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
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

        $this->customerController = new CustomerAccountController(
            $this->app,
            $this->walletService,
            $this->paymentService,
            $this->registry,
            $this->currencyService,
            null,
            $this->withdrawalService
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

    public function testAmountLimitsEnforced(): void
    {
        $this->withdrawalService->updateSettings([
            'enabled'    => true,
            'min_amount' => 500.0,
            'max_amount' => 5000.0,
        ]);
        $this->fundWallet(1, 20000, 'BDT');

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

        // Above max (6,000 BDT)
        try {
            $this->withdrawalService->createWithdrawal(
                1,
                new Money(600000, 'BDT'),
                'bkash',
                ['account' => '01712345678']
            );
            $this->fail('Expected max amount violation');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('exceeds maximum limit', $e->getMessage());
        }
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
            '_csrf_token'     => 'valid-test-csrf-token',
            'action'          => 'update_settings',
            'enabled'         => '1',
            'min_amount'      => '250',
            'max_amount'      => '25000',
            'fee_fixed'       => '15',
            'fee_pct'         => '1.5',
            'allowed_methods' => ['bkash', 'nagad', 'bank'],
        ], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/favorite-pay-withdrawals']);

        $response = $this->adminController->handle($request);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());

        $this->assertTrue($this->withdrawalService->isWithdrawalEnabled());
        $settings = $this->withdrawalService->getSettings();
        $this->assertSame(250.0, (float)$settings['min_amount']);
        $this->assertSame(25000.0, (float)$settings['max_amount']);
        $this->assertSame(15.0, (float)$settings['fee_fixed']);
        $this->assertSame(1.5, (float)$settings['fee_pct']);
        $this->assertContains('bkash', $settings['allowed_methods']);
        $this->assertNotContains('rocket', $settings['allowed_methods']);
    }
}
