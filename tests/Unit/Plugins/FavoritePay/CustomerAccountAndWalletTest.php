<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Core\AccountMenu;
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Csrf;
use FavoriteCMS\Core\Hook;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\PaymentGatewayInterface;
use FavoriteCMS\Pay\Controllers\CustomerAccountController;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\PaymentService;
use FavoriteCMS\Pay\Services\WalletService;
use PHPUnit\Framework\TestCase;

class CustomerTestUserStub extends User
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

class CustomerAccountAndWalletTest extends TestCase
{
    private Application $app;
    private CurrencyService $currencyService;
    private GatewayRegistry $registry;
    private PaymentService $paymentService;
    private WalletService $walletService;
    private CustomerAccountController $controller;
    private FavoritePayPlugin $plugin;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_SESSION['_csrf_token'] = 'valid-test-csrf-token';
        unset($GLOBALS['_test_current_user']);

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
        $manualNagad = new ManualBangladeshGateway(
            'manual_nagad',
            'Nagad Manual Payment',
            PaymentMethodType::MANUAL_NAGAD,
            [
                'channel'        => 'nagad',
                'account_number' => '01800000000',
                'account_name'   => 'Merchant Ltd',
                'instructions'   => 'Send money to 01800000000 and enter TrxID below.',
            ],
            true
        );
        $this->registry->register($manualBkash);
        $this->registry->register($manualNagad);

        $this->paymentService = new PaymentService($this->currencyService, $this->registry);
        $this->walletService = new WalletService($this->currencyService, $this->paymentService);

        $this->controller = new CustomerAccountController(
            $this->app,
            $this->walletService,
            $this->paymentService,
            $this->registry,
            $this->currencyService
        );

        $this->plugin = new FavoritePayPlugin($this->app);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($GLOBALS['_test_current_user']);
        if (class_exists(AccountMenu::class)) {
            AccountMenu::removeByPlugin('favorite-pay');
        }
    }

    private function setLoggedInUser(int $id = 1, string $status = 'active', string $username = 'testuser'): CustomerTestUserStub
    {
        $_SESSION['auth_user_id'] = $id;
        $_SESSION['auth_user_name'] = $username;
        $user = new CustomerTestUserStub([
            'id'       => $id,
            'username' => $username,
            'email'    => $username . '@example.com',
            'status'   => $status,
        ]);
        $GLOBALS['_test_current_user'] = $user;
        return $user;
    }

    // =========================================================================
    // 1. ACCOUNT MENU REGISTRATION & LIFECYCLE TESTS
    // =========================================================================

    public function testAccountMenuItemsRegisteredWithCorrectOrdering(): void
    {
        $this->plugin->registerAccountMenuItems();

        $balanceItem = AccountMenu::getItem('pay_balance');
        $this->assertNotNull($balanceItem);
        $this->assertSame('Balance', $balanceItem['label']);
        $this->assertSame('/account/wallet', $balanceItem['url']);
        $this->assertSame(14, $balanceItem['order']);
        $this->assertSame('favorite-pay', $balanceItem['plugin']);

        $rechargeItem = AccountMenu::getItem('pay_recharge');
        $this->assertNotNull($rechargeItem);
        $this->assertSame('Recharge', $rechargeItem['label']);
        $this->assertSame('/account/recharge', $rechargeItem['url']);
        $this->assertSame(16, $rechargeItem['order']);
        $this->assertSame('favorite-pay', $rechargeItem['plugin']);

        $paymentsItem = AccountMenu::getItem('pay_payments');
        $this->assertNotNull($paymentsItem);
        $this->assertSame('Payment History', $paymentsItem['label']);
        $this->assertSame('/account/payments', $paymentsItem['url']);
        $this->assertSame(20, $paymentsItem['order']);
        $this->assertSame('favorite-pay', $paymentsItem['plugin']);

        $transactionsItem = AccountMenu::getItem('pay_transactions');
        $this->assertNotNull($transactionsItem);
        $this->assertSame('Transactions', $transactionsItem['label']);
        $this->assertSame('/account/transactions', $transactionsItem['url']);
        $this->assertSame(24, $transactionsItem['order']);
        $this->assertSame('favorite-pay', $transactionsItem['plugin']);
    }

    public function testConceptualMenuOrderSequenceMatchesDesign(): void
    {
        AccountMenu::registerCoreItems();
        $this->plugin->registerAccountMenuItems();

        $user = $this->setLoggedInUser(1);
        $items = AccountMenu::getItems($user);

        $orderKeys = array_keys($items);

        // Core Profile (10) must come before Pay Balance (14)
        $this->assertContains('profile', $orderKeys);
        $this->assertContains('pay_balance', $orderKeys);
        $this->assertContains('pay_recharge', $orderKeys);
        $this->assertContains('pay_payments', $orderKeys);
        $this->assertContains('pay_transactions', $orderKeys);

        $profilePos = array_search('profile', $orderKeys, true);
        $balancePos = array_search('pay_balance', $orderKeys, true);
        $rechargePos = array_search('pay_recharge', $orderKeys, true);
        $paymentsPos = array_search('pay_payments', $orderKeys, true);
        $transactionsPos = array_search('pay_transactions', $orderKeys, true);

        $this->assertLessThan($balancePos, $profilePos, 'Profile (10) must come before Balance (14)');
        $this->assertLessThan($rechargePos, $balancePos, 'Balance (14) must come before Recharge (16)');
        $this->assertLessThan($paymentsPos, $rechargePos, 'Recharge (16) must come before Payments (20)');
        $this->assertLessThan($transactionsPos, $paymentsPos, 'Payments (20) must come before Transactions (24)');
    }

    public function testAccountMenuCleanupOnDeactivation(): void
    {
        AccountMenu::registerCoreItems();
        $this->plugin->registerAccountMenuItems();

        $this->assertTrue(AccountMenu::hasItem('pay_balance'));
        $this->assertTrue(AccountMenu::hasItem('pay_recharge'));

        // Deactivate plugin
        $this->plugin->onDeactivate();

        // Favorite Pay items must be cleanly removed
        $this->assertFalse(AccountMenu::hasItem('pay_balance'));
        $this->assertFalse(AccountMenu::hasItem('pay_recharge'));
        $this->assertFalse(AccountMenu::hasItem('pay_payments'));
        $this->assertFalse(AccountMenu::hasItem('pay_transactions'));

        // Core items remain intact
        $this->assertTrue(AccountMenu::hasItem('profile'));
    }

    // =========================================================================
    // 2. GUEST REDIRECT & IDOR PROTECTION TESTS
    // =========================================================================

    public function testGuestRedirectedToLoginFromWallet(): void
    {
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']);
        $response = $this->controller->wallet($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location'] ?? '');
    }

    public function testGuestRedirectedToLoginFromRecharge(): void
    {
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge']);
        $response = $this->controller->recharge($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location'] ?? '');
    }

    public function testGuestRedirectedToLoginFromPayments(): void
    {
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments']);
        $response = $this->controller->payments($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location'] ?? '');
    }

    public function testGuestRedirectedToLoginFromTransactions(): void
    {
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions']);
        $response = $this->controller->transactions($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location'] ?? '');
    }

    public function testStrictIdorSessionResolutionUserIdentityCannotBeSpoofed(): void
    {
        // Logged in as user 5
        $this->setLoggedInUser(5);

        // Attacker attempts to pass ?user_id=999 or in post
        $request = new Request(['user_id' => '999'], ['user_id' => '999'], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']);
        $response = $this->controller->wallet($request);

        $this->assertSame(200, $response->getStatusCode());
        // Wallet should be rendered for user 5, not user 999
        $content = $response->getContent();
        $this->assertStringContainsString('My Wallet', $content);
    }

    // =========================================================================
    // 3. ACCOUNT STATUS INTEGRATION (ACTIVE, SUSPENDED, BANNED)
    // =========================================================================

    public function testActiveUserHasFullAccess(): void
    {
        $this->setLoggedInUser(1, 'active');

        $reqWallet = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']);
        $this->assertSame(200, $this->controller->wallet($reqWallet)->getStatusCode());

        $reqRecharge = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge']);
        $this->assertSame(200, $this->controller->recharge($reqRecharge)->getStatusCode());

        $reqPayments = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments']);
        $this->assertSame(200, $this->controller->payments($reqPayments)->getStatusCode());

        $reqTransactions = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions']);
        $this->assertSame(200, $this->controller->transactions($reqTransactions)->getStatusCode());
    }

    public function testSuspendedUserHasReadOnlyHistoryAccessButRechargeIsBlocked(): void
    {
        $this->setLoggedInUser(2, 'suspended');

        // Can view wallet
        $reqWallet = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']);
        $respWallet = $this->controller->wallet($reqWallet);
        $this->assertSame(200, $respWallet->getStatusCode());
        $this->assertStringContainsString('suspended', strtolower($respWallet->getContent()));

        // Can view payment history
        $reqPayments = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments']);
        $this->assertSame(200, $this->controller->payments($reqPayments)->getStatusCode());

        // Can view transaction ledger
        $reqTransactions = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions']);
        $this->assertSame(200, $this->controller->transactions($reqTransactions)->getStatusCode());

        // CANNOT view recharge form (403 Forbidden)
        $reqRechargeGet = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge']);
        $respRechargeGet = $this->controller->recharge($reqRechargeGet);
        $this->assertSame(403, $respRechargeGet->getStatusCode());
        $this->assertStringContainsString('suspended', strtolower($respRechargeGet->getContent()));

        // CANNOT submit recharge (403 Forbidden)
        $reqRechargePost = new Request([], ['amount' => '100', 'gateway' => 'manual_bkash', '_csrf_token' => 'valid-test-csrf-token'], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/recharge']);
        $respRechargePost = $this->controller->recharge($reqRechargePost);
        $this->assertSame(403, $respRechargePost->getStatusCode());

        // CANNOT view manual recharge
        $reqManualGet = new Request(['intent' => 'pi_test'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge/manual']);
        $respManualGet = $this->controller->showManual($reqManualGet);
        $this->assertSame(403, $respManualGet->getStatusCode());
    }

    public function testBannedUserDeniedAllAccess(): void
    {
        $this->setLoggedInUser(3, 'banned');

        $reqWallet = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']);
        $this->assertSame(403, $this->controller->wallet($reqWallet)->getStatusCode());

        $reqRecharge = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge']);
        $this->assertSame(403, $this->controller->recharge($reqRecharge)->getStatusCode());

        $reqPayments = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments']);
        $this->assertSame(403, $this->controller->payments($reqPayments)->getStatusCode());

        $reqTransactions = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions']);
        $this->assertSame(403, $this->controller->transactions($reqTransactions)->getStatusCode());
    }

    // =========================================================================
    // 4. WALLET DISPLAY & PRIMARY ACCOUNTING CURRENCY TESTS
    // =========================================================================

    public function testWalletPageDisplaysBalanceInPrimaryCurrency(): void
    {
        $this->setLoggedInUser(10, 'active');

        // Deposit 25000 Poisha = 250.00 BDT
        $this->walletService->deposit(10, Money::bdt(25000), 'DEP-101', 'Initial Deposit');

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']);
        $resp = $this->controller->wallet($req);

        $this->assertSame(200, $resp->getStatusCode());
        $content = $resp->getContent();
        $this->assertStringContainsString('BDT', $content);
        $this->assertStringContainsString('250.00', $content);
        $this->assertStringContainsString('Recharge Balance', $content);
        $this->assertStringContainsString('Initial Deposit', $content);
    }

    // =========================================================================
    // 5. RECHARGE INTENT CREATION & WALLET SAFETY (NO PRE-CREDIT)
    // =========================================================================

    public function testRechargePostRequiresCsrfProtection(): void
    {
        $this->setLoggedInUser(11, 'active');

        $postData = [
            'amount'       => '500.00',
            'gateway'      => 'manual_bkash',
            '_csrf_token'  => 'invalid-token',
        ];
        $req = new Request([], $postData, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/recharge']);
        $resp = $this->controller->recharge($req);

        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('/account/recharge', $resp->getHeaders()['Location'] ?? '');
        $this->assertStringContainsString('Invalid or expired security token', $_SESSION['flash_error'] ?? '');
    }

    public function testRechargePostValidatesInvalidAmount(): void
    {
        $this->setLoggedInUser(12, 'active');

        $postData = [
            'amount'       => '-50.00',
            'gateway'      => 'manual_bkash',
            '_csrf_token'  => 'valid-test-csrf-token',
        ];
        $req = new Request([], $postData, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/recharge']);
        $resp = $this->controller->recharge($req);

        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('/account/recharge', $resp->getHeaders()['Location'] ?? '');
        $this->assertStringContainsString('Please enter a valid positive recharge amount', $_SESSION['flash_error'] ?? '');
    }

    public function testRechargePostValidatesUnknownGateway(): void
    {
        $this->setLoggedInUser(13, 'active');

        $postData = [
            'amount'       => '100.00',
            'gateway'      => 'nonexistent_gateway',
            '_csrf_token'  => 'valid-test-csrf-token',
        ];
        $req = new Request([], $postData, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/recharge']);
        $resp = $this->controller->recharge($req);

        $this->assertSame(302, $resp->getStatusCode());
        $this->assertSame('/account/recharge', $resp->getHeaders()['Location'] ?? '');
        $this->assertStringContainsString('The selected payment method is not recognized', $_SESSION['flash_error'] ?? '');
    }

    public function testRechargeIntentCreationDoesNotCreditWallet(): void
    {
        $this->setLoggedInUser(14, 'active');

        // Initial wallet balance must be 0
        $initialBalance = $this->walletService->getBalance(14);
        $this->assertSame(0, $initialBalance->getAmount());

        $postData = [
            'amount'       => '500.00',
            'gateway'      => 'manual_bkash',
            '_csrf_token'  => 'valid-test-csrf-token',
        ];
        $req = new Request([], $postData, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/recharge']);
        $resp = $this->controller->recharge($req);

        // Must redirect to manual payment instruction screen with canonical intent ID
        $this->assertSame(302, $resp->getStatusCode());
        $location = $resp->getHeaders()['Location'] ?? '';
        $this->assertStringStartsWith('/account/recharge/manual?intent=pi_', $location);

        // Extract intent ID
        parse_str(parse_url($location, PHP_URL_QUERY) ?? '', $queryParams);
        $intentId = $queryParams['intent'] ?? '';
        $this->assertNotEmpty($intentId);
        $this->assertStringStartsWith('pi_', $intentId);

        // CRITICAL INVARIANT: Wallet balance is NOT credited on intent creation!
        $balanceAfterIntent = $this->walletService->getBalance(14);
        $this->assertSame(0, $balanceAfterIntent->getAmount(), 'Wallet must NOT be credited upon recharge intent creation');

        // Settle the payment via event hook
        Hook::doAction('favorite.pay.payment.succeeded', ['transaction_id' => $intentId]);
        // Settle the payment
        $this->paymentService->updateIntentStatus($intentId, PaymentStatus::SUCCEEDED);
        $this->walletService->settleSuccessfulPayment($intentId);

        // Now wallet is credited with 500.00 BDT (50,000 Poisha)
        $balanceAfterSettlement = $this->walletService->getBalance(14);
        $this->assertSame(50000, $balanceAfterSettlement->getAmount());
    }

    // =========================================================================
    // 6. MANUAL RECHARGE INSTRUCTIONS & SUBMISSION
    // =========================================================================

    public function testManualRechargePageDisplaysGatewayInstructions(): void
    {
        $this->setLoggedInUser(15, 'active');

        // Create an intent
        $intent = $this->paymentService->createIntent('favorite-pay', 'recharge_test_15', Money::bdt(30000), [
            'user_id'    => 15,
            'gateway_id' => 'manual_bkash',
        ]);

        $req = new Request(['intent' => $intent->getId()], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge/manual']);
        $resp = $this->controller->showManual($req);

        $this->assertSame(200, $resp->getStatusCode());
        $content = $resp->getContent();
        $this->assertStringContainsString('bKash Manual Payment', $content);
        $this->assertStringContainsString('01700000000', $content);
        $this->assertStringContainsString('TrxID', $content);
        $this->assertStringContainsString($intent->getId(), $content);
    }

    public function testManualRechargePostRequiresTrxId(): void
    {
        $this->setLoggedInUser(16, 'active');
        $intent = $this->paymentService->createIntent('favorite-pay', 'recharge_test_16', Money::bdt(20000), [
            'user_id'    => 16,
            'gateway_id' => 'manual_bkash',
        ]);

        $postData = [
            'intent'       => $intent->getId(),
            'trx_id'       => '',
            '_csrf_token'  => 'valid-test-csrf-token',
        ];
        $req = new Request([], $postData, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/recharge/manual']);
        $resp = $this->controller->submitManual($req);

        $this->assertSame(302, $resp->getStatusCode());
        $this->assertStringContainsString('required', strtolower($_SESSION['flash_error'] ?? ''));
    }

    public function testManualRechargePostRecordsAttemptAndRedirects(): void
    {
        $this->setLoggedInUser(17, 'active');
        $intent = $this->paymentService->createIntent('favorite-pay', 'recharge_test_17', Money::bdt(20000), [
            'user_id'    => 17,
            'gateway_id' => 'manual_bkash',
        ]);

        $postData = [
            'intent'        => $intent->getId(),
            'trx_id'        => 'BKASH998877AA',
            'sender_number' => '01711223344',
            '_csrf_token'   => 'valid-test-csrf-token',
        ];
        $req = new Request([], $postData, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/recharge/manual']);
        $resp = $this->controller->submitManual($req);

        $this->assertSame(302, $resp->getStatusCode());
        $location = $resp->getHeaders()['Location'] ?? '';
        $this->assertSame('/account/payments/' . $intent->getId(), $location);

        // Verify attempt was created with awaiting_verification status
        $attempts = $this->paymentService->getAttemptsForTransaction($intent->getId());
        $this->assertNotEmpty($attempts);
        $latest = end($attempts);
        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $latest->getStatus());
        $this->assertSame('BKASH998877AA', $latest->getTransactionReference());
    }

    // =========================================================================
    // 7. BINANCE PAY RECHARGE INTEGRATION
    // =========================================================================

    public function testBinanceGatewayPreservesCheckoutMetadataOnRecharge(): void
    {
        $this->setLoggedInUser(18, 'active');

        // Create mock Binance gateway with canonical ID binance_pay and alias binance
        $binanceMock = new class implements PaymentGatewayInterface {
            public function getId(): string { return 'binance_pay'; }
            public function getTitle(): string { return 'Binance Pay'; }
            public function getType(): PaymentMethodType { return PaymentMethodType::AUTOMATIC; }
            public function isEnabled(): bool { return true; }
            public function getSupportedCurrencies(): array { return ['USDT', 'USD', 'BDT']; }
            public function getInstructions(array $context = []): array { return []; }
            public function createAttempt(PaymentIntent $intent, array $params = []): PaymentAttempt {
                return new PaymentAttempt(
                    'att_' . bin2hex(random_bytes(6)),
                    $intent->getId(),
                    'binance_pay',
                    $intent->getChargeAmount(),
                    PaymentStatus::PENDING,
                    'binance_order_998811',
                    null, null, null, null, null, null,
                    [
                        'checkout_url'      => 'https://pay.binance.com/checkout/order_998811',
                        'qrcode_link'       => 'https://pay.binance.com/qr/order_998811.png',
                        'merchant_trade_no' => 'trade_998811',
                        'prepay_id'         => 'prepay_998811',
                    ]
                );
            }
            public function verifyAttempt(PaymentAttempt $attempt, array $verificationData = []): PaymentAttempt {
                return $attempt;
            }
        };

        $this->registry->register($binanceMock, ['binance'], true);

        $postData = [
            'amount'       => '1000.00',
            'gateway'      => 'binance',
            '_csrf_token'  => 'valid-test-csrf-token',
        ];
        $req = new Request([], $postData, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/recharge']);
        $resp = $this->controller->recharge($req);

        // Redirects to dedicated Binance customer checkout page
        $this->assertSame(302, $resp->getStatusCode());
        $location = $resp->getHeaders()['Location'] ?? '';
        $this->assertStringStartsWith('/account/recharge/binance/pi_', $location);
    }

    // =========================================================================
    // 8. PAYMENT HISTORY & DETAIL IDOR & LEAK PREVENTION TESTS
    // =========================================================================

    public function testPaymentHistoryOnlyListsCurrentUserPayments(): void
    {
        // User 20 creates a payment
        $this->setLoggedInUser(20, 'active', 'user20');
        $intent20 = $this->paymentService->createIntent('favorite-pay', 'order_20', Money::bdt(10000), [
            'user_id'     => 20,
            'customer_id' => 20,
            'gateway_id'  => 'manual_bkash',
        ]);

        // User 21 creates a payment
        $this->setLoggedInUser(21, 'active', 'user21');
        $intent21 = $this->paymentService->createIntent('favorite-pay', 'order_21', Money::bdt(20000), [
            'user_id'     => 21,
            'customer_id' => 21,
            'gateway_id'  => 'manual_nagad',
        ]);

        // Switch back to User 20
        $this->setLoggedInUser(20, 'active', 'user20');
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments']);
        $resp = $this->controller->payments($req);

        $this->assertSame(200, $resp->getStatusCode());
        $content = $resp->getContent();
        $this->assertStringContainsString($intent20->getId(), $content);
        $this->assertStringNotContainsString($intent21->getId(), $content, 'User 20 must NOT see User 21 payment in history');
    }

    public function testPaymentDetailIdorProtectionBlocksOtherUsers(): void
    {
        // Payment belongs to user 30
        $this->setLoggedInUser(30, 'active', 'user30');
        $intent30 = $this->paymentService->createIntent('favorite-pay', 'order_30', Money::bdt(15000), [
            'user_id'     => 30,
            'customer_id' => 30,
            'gateway_id'  => 'manual_bkash',
        ]);

        // Attacker logged in as user 31 tries to view user 30\'s payment detail
        $this->setLoggedInUser(31, 'active', 'attacker');
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments/' . $intent30->getId()]);
        $resp = $this->controller->paymentDetail($req, $intent30->getId());

        // Must return 403 Forbidden
        $this->assertSame(403, $resp->getStatusCode());
        $this->assertStringContainsString('Access denied', $resp->getContent());
    }

    public function testPaymentDetailNeverLeaksApiSecrets(): void
    {
        $this->setLoggedInUser(40, 'active', 'user40');
        $intent40 = $this->paymentService->createIntent('favorite-pay', 'order_40', Money::bdt(15000), [
            'user_id'     => 40,
            'customer_id' => 40,
            'gateway_id'  => 'manual_bkash',
        ]);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments/' . $intent40->getId()]);
        $resp = $this->controller->paymentDetail($req, $intent40->getId());

        $this->assertSame(200, $resp->getStatusCode());
        $content = $resp->getContent();
        $this->assertStringContainsString($intent40->getId(), $content);
        $this->assertStringContainsString('bKash Manual Payment', $content);

        // Security check: No internal keys or secrets leaked
        $this->assertStringNotContainsString('api_secret', $content);
        $this->assertStringNotContainsString('webhook_secret', $content);
        $this->assertStringNotContainsString('private_key', $content);
    }

    // =========================================================================
    // 9. TRANSACTION LEDGER TESTS
    // =========================================================================

    public function testTransactionLedgerDisplaysUserEntriesAndPreventsIdor(): void
    {
        // User 50 entries
        $this->setLoggedInUser(50, 'active', 'user50');
        $this->walletService->deposit(50, Money::bdt(10000), 'REF-50-A', 'Top-up');
        $this->walletService->deposit(50, Money::bdt(5000), 'REF-50-B', 'Bonus');

        // User 51 entries
        $this->setLoggedInUser(51, 'active', 'user51');
        $this->walletService->deposit(51, Money::bdt(99900), 'SECRET-REF-51', 'User 51 Secret');

        // View transactions as User 50
        $this->setLoggedInUser(50, 'active', 'user50');
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions']);
        $resp = $this->controller->transactions($req);

        $this->assertSame(200, $resp->getStatusCode());
        $content = $resp->getContent();
        $this->assertStringContainsString('REF-50-A', $content);
        $this->assertStringContainsString('REF-50-B', $content);
        $this->assertStringNotContainsString('SECRET-REF-51', $content, 'User 50 must NOT see User 51 ledger records');
    }

    // =========================================================================
    // 10. PHASE 8 TESTS: WALLET & TRANSACTION EXPERIENCE
    // =========================================================================

    public function testWalletDashboardDisplaysAvailableHeldAndTotalBalances(): void
    {
        $userId = 60;
        $this->setLoggedInUser($userId, 'active', 'user60');

        // Deposit 50,000 BDT
        $this->walletService->deposit($userId, Money::bdt(50000), 'DEP-60', 'Initial Deposit');

        // Hold 10,000 BDT for a withdrawal
        $this->walletService->hold($userId, Money::bdt(10000), 'WTH-60', 'Withdrawal Hold');

        // Invariants:
        // Balance (spendable) = 40,000 BDT
        // Held Balance = 10,000 BDT
        // Total Balance = 50,000 BDT
        $avail = $this->walletService->getBalance($userId);
        $held = $this->walletService->getHeldBalance($userId);
        $total = $this->walletService->getTotalBalance($userId);

        $this->assertSame(40000, $avail->getAmount());
        $this->assertSame(10000, $held->getAmount());
        $this->assertSame(50000, $total->getAmount());

        // Controller /account/wallet view
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']);
        $resp = $this->controller->wallet($req);

        $this->assertSame(200, $resp->getStatusCode());
        $content = $resp->getContent();

        // Must display spendable available balance, held balance, and total balance
        $this->assertStringContainsString('Spendable Available Balance', $content);
        $this->assertStringContainsString('Held in Withdrawals', $content);
        $this->assertStringContainsString('Total Wallet Balance', $content);
        $this->assertStringContainsString('400.00', $content); // 40,000 cents = 400.00
        $this->assertStringContainsString('100.00', $content); // 10,000 cents = 100.00
        $this->assertStringContainsString('500.00', $content); // 50,000 cents = 500.00
    }

    public function testWalletSummaryCalculatesLifetimeMetrics(): void
    {
        $userId = 61;
        $this->setLoggedInUser($userId, 'active', 'user61');

        // Deposits
        $this->walletService->deposit($userId, Money::bdt(30000), 'PAY-61-1', 'Recharge 1');
        $this->walletService->deposit($userId, Money::bdt(20000), 'PAY-61-2', 'Recharge 2');

        // Hold & finalize withdrawal
        $this->walletService->hold($userId, Money::bdt(15000), 'WTH-61', 'Withdrawal hold');
        $this->walletService->finalizeHold($userId, Money::bdt(15000), 'WTH-61', 'Withdrawal finalized');

        // Summary metrics
        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']);
        $resp = $this->controller->wallet($req);

        $this->assertSame(200, $resp->getStatusCode());
        $content = $resp->getContent();

        // Check lifetime metrics sections are present
        $this->assertStringContainsString('Total Recharges', $content);
        $this->assertStringContainsString('Total Withdrawals', $content);
        $this->assertStringContainsString('Lifetime Wallet Activity Summary', $content);
    }

    public function testTransactionLedgerFilteredByDirectionAndType(): void
    {
        $userId = 62;
        $this->setLoggedInUser($userId, 'active', 'user62');

        // 1. Credit (deposit)
        $this->walletService->deposit($userId, Money::bdt(10000), 'REF-62-DEP', 'Initial Deposit');
        // 2. Hold (debit)
        $this->walletService->hold($userId, Money::bdt(3000), 'REF-62-WTH', 'Withdrawal Hold');
        // 3. Release (credit)
        $this->walletService->releaseHold($userId, Money::bdt(1000), 'REF-62-WTH', 'Partial Release');

        // Filter direction=credit -> should return deposit and release (2 entries)
        $reqCredit = new Request(['direction' => 'credit'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions?direction=credit']);
        $respCredit = $this->controller->transactions($reqCredit);
        $this->assertSame(200, $respCredit->getStatusCode());
        $contentCredit = $respCredit->getContent();
        $this->assertStringContainsString('Initial Deposit', $contentCredit);
        $this->assertStringContainsString('Hold released back to wallet', $contentCredit);
        $this->assertStringNotContainsString('Withdrawal Hold', $contentCredit);

        // Filter direction=debit -> should return only hold (1 entry)
        $reqDebit = new Request(['direction' => 'debit'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions?direction=debit']);
        $respDebit = $this->controller->transactions($reqDebit);
        $this->assertSame(200, $respDebit->getStatusCode());
        $contentDebit = $respDebit->getContent();
        $this->assertStringContainsString('Funds placed on hold', $contentDebit);
        $this->assertStringNotContainsString('Initial Deposit', $contentDebit);
        $this->assertStringNotContainsString('Partial Release', $contentDebit);

        // Filter type=hold -> should return only hold
        $reqHold = new Request(['type' => 'hold'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions?type=hold']);
        $respHold = $this->controller->transactions($reqHold);
        $this->assertSame(200, $respHold->getStatusCode());
        $this->assertStringContainsString('Funds placed on hold', $respHold->getContent());
        $this->assertStringContainsString('REF-62-WTH', $respHold->getContent());
        $this->assertStringNotContainsString('Initial Deposit', $respHold->getContent());
    }

    public function testTransactionLedgerFilteredBySearchAndDate(): void
    {
        $userId = 63;
        $this->setLoggedInUser($userId, 'active', 'user63');

        $this->walletService->deposit($userId, Money::bdt(10000), 'REF-SALARY', 'Monthly salary payment');
        $this->walletService->deposit($userId, Money::bdt(5000), 'REF-BINANCE', 'Crypto recharge via Binance');

        // Search: 'salary'
        $reqSearch = new Request(['search' => 'salary'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions?search=salary']);
        $respSearch = $this->controller->transactions($reqSearch);
        $this->assertSame(200, $respSearch->getStatusCode());
        $contentSearch = $respSearch->getContent();
        $this->assertStringContainsString('REF-SALARY', $contentSearch);
        $this->assertStringNotContainsString('REF-BINANCE', $contentSearch);

        // Search: 'BINANCE'
        $reqBin = new Request(['search' => 'BINANCE'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions?search=BINANCE']);
        $respBin = $this->controller->transactions($reqBin);
        $this->assertSame(200, $respBin->getStatusCode());
        $this->assertStringContainsString('REF-BINANCE', $respBin->getContent());
        $this->assertStringNotContainsString('REF-SALARY', $respBin->getContent());

        // Search non-existent
        $reqNone = new Request(['search' => 'NonExistentRef999'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions?search=NonExistentRef999']);
        $respNone = $this->controller->transactions($reqNone);
        $this->assertSame(200, $respNone->getStatusCode());
        $this->assertStringContainsString('No ledger entries found', $respNone->getContent());
    }

    public function testTransactionDetailEndpointAndIdorProtection(): void
    {
        $userA = 64;
        $userB = 65;

        // User A creates a transaction
        $this->setLoggedInUser($userA, 'active', 'user64');
        $this->walletService->deposit($userA, Money::bdt(10000), 'REF-64', 'User A Deposit');

        $entriesA = $this->walletService->getLedgerHistory($userA);
        $this->assertNotEmpty($entriesA);
        $entryIdA = $entriesA[0]->getId();

        // User A views own transaction detail -> 200 OK
        $reqA = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions/' . $entryIdA]);
        $respA = $this->controller->transactionDetail($reqA, $entryIdA);

        $this->assertSame(200, $respA->getStatusCode());
        $contentA = $respA->getContent();
        $this->assertStringContainsString($entryIdA, $contentA);
        $this->assertStringContainsString('User A Deposit', $contentA);
        $this->assertStringContainsString('Transaction Details', $contentA);

        // User B attempts to access User A's transaction detail -> 403 Forbidden
        $this->setLoggedInUser($userB, 'active', 'user65');
        $reqB = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions/' . $entryIdA]);
        $respB = $this->controller->transactionDetail($reqB, $entryIdA);

        $this->assertSame(403, $respB->getStatusCode());
        $this->assertStringContainsString('Access denied', $respB->getContent());
    }

    public function testPaymentHistoryFilteringByStatusAndDate(): void
    {
        $userId = 66;
        $this->setLoggedInUser($userId, 'active', 'user66');

        $intentSuccess = $this->paymentService->createIntent('favorite-pay', 'order_success', Money::bdt(10000), [
            'user_id'     => $userId,
            'customer_id' => $userId,
            'gateway_id'  => 'manual_bkash',
        ]);

        $intentPending = $this->paymentService->createIntent('favorite-pay', 'order_pending', Money::bdt(20000), [
            'user_id'     => $userId,
            'customer_id' => $userId,
            'gateway_id'  => 'manual_nagad',
        ]);

        // Update in-memory intent status using reflection
        $ref = new \ReflectionClass($this->paymentService);
        $prop = $ref->getProperty('intents');
        $prop->setAccessible(true);
        $intents = $prop->getValue($this->paymentService);
        $intents[$intentSuccess->getId()] = $intentSuccess->withStatus(PaymentStatus::SUCCEEDED);
        $prop->setValue($this->paymentService, $intents);

        // Filter status=succeeded
        $reqSuccess = new Request(['status' => 'succeeded'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments?status=succeeded']);
        $respSuccess = $this->controller->payments($reqSuccess);
        $this->assertSame(200, $respSuccess->getStatusCode());
        $contentSuccess = $respSuccess->getContent();
        $this->assertStringContainsString($intentSuccess->getId(), $contentSuccess);
        $this->assertStringNotContainsString($intentPending->getId(), $contentSuccess);

        // Filter status=pending
        $reqPending = new Request(['status' => 'pending'], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments?status=pending']);
        $respPending = $this->controller->payments($reqPending);
        $this->assertSame(200, $respPending->getStatusCode());
        $contentPending = $respPending->getContent();
        $this->assertStringContainsString($intentPending->getId(), $contentPending);
        $this->assertStringNotContainsString($intentSuccess->getId(), $contentPending);
    }

    public function testRechargeViewDisplaysRecentRechargesWithLockedConversionSnapshot(): void
    {
        $userId = 67;
        $this->setLoggedInUser($userId, 'active', 'user67');

        // Create an intent in primary currency BDT
        $intent = $this->paymentService->createIntent('favorite-pay', 'recharge_order_67', Money::bdt(50000), [
            'user_id'             => $userId,
            'customer_id'         => $userId,
            'gateway_id'          => 'manual_bkash',
            'type'                => 'recharge',
            'accounting_amount'   => 50000,
            'accounting_currency' => 'BDT',
            'exchange_rate'       => 1.0,
        ]);

        $ref = new \ReflectionClass($this->paymentService);
        $prop = $ref->getProperty('intents');
        $prop->setAccessible(true);
        $intents = $prop->getValue($this->paymentService);
        $intents[$intent->getId()] = $intent->withStatus(PaymentStatus::SUCCEEDED);
        $prop->setValue($this->paymentService, $intents);

        $req = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge']);
        $resp = $this->controller->recharge($req);

        $this->assertSame(200, $resp->getStatusCode());
        $content = $resp->getContent();

        // Must display the recent recharge section
        $this->assertStringContainsString('Recent Recharge Activity', $content);
        $this->assertStringContainsString($intent->getId(), $content);
    }

    public function testReadEndpointsCauseZeroFinancialMutations(): void
    {
        $userId = 68;
        $this->setLoggedInUser($userId, 'active', 'user68');

        $this->walletService->deposit($userId, Money::bdt(25000), 'DEP-68', 'Initial deposit');
        $this->walletService->hold($userId, Money::bdt(5000), 'WTH-68', 'Withdrawal hold');

        $balanceBefore = $this->walletService->getBalance($userId)->getAmount();
        $heldBefore = $this->walletService->getHeldBalance($userId)->getAmount();
        $totalBefore = $this->walletService->getTotalBalance($userId)->getAmount();
        $ledgerCountBefore = count($this->walletService->getLedgerHistory($userId));

        // Perform read requests
        $entries = $this->walletService->getLedgerHistory($userId);
        $entryId = $entries[0]->getId();

        $this->controller->wallet(new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']));
        $this->controller->transactions(new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions']));
        $this->controller->transactionDetail(new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions/' . $entryId]), $entryId);
        $this->controller->payments(new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments']));
        $this->controller->recharge(new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge']));

        // Assert zero financial mutation
        $this->assertSame($balanceBefore, $this->walletService->getBalance($userId)->getAmount());
        $this->assertSame($heldBefore, $this->walletService->getHeldBalance($userId)->getAmount());
        $this->assertSame($totalBefore, $this->walletService->getTotalBalance($userId)->getAmount());
        $this->assertSame($ledgerCountBefore, count($this->walletService->getLedgerHistory($userId)));
    }

    public function testSuspendedCustomerCanViewWalletAndLedgerReadOnly(): void
    {
        $userId = 69;
        $this->setLoggedInUser($userId, 'suspended', 'user69');

        // Suspended customer viewing wallet -> 200 OK
        $reqWallet = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']);
        $respWallet = $this->controller->wallet($reqWallet);
        $this->assertSame(200, $respWallet->getStatusCode());

        // Suspended customer viewing transactions -> 200 OK
        $reqTx = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions']);
        $respTx = $this->controller->transactions($reqTx);
        $this->assertSame(200, $respTx->getStatusCode());

        // Suspended customer viewing recharge -> 403 Forbidden (recharging is blocked)
        $reqRecharge = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge']);
        $respRecharge = $this->controller->recharge($reqRecharge);
        $this->assertSame(403, $respRecharge->getStatusCode());
        $this->assertStringContainsString('suspended', strtolower($respRecharge->getContent()));

        // Suspended customer submitting recharge -> blocked 403
        $reqRechargePost = new Request([], ['amount' => 100, 'gateway_id' => 'manual_bkash', '_csrf_token' => 'valid-test-csrf-token'], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/account/recharge']);
        $respRechargePost = $this->controller->recharge($reqRechargePost);
        $this->assertSame(403, $respRechargePost->getStatusCode());
    }

    public function testBannedCustomerIsBlockedFromAllAccountPages(): void
    {
        $userId = 70;
        $this->setLoggedInUser($userId, 'banned', 'user70');

        $reqWallet = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/wallet']);
        $respWallet = $this->controller->wallet($reqWallet);
        $this->assertSame(403, $respWallet->getStatusCode());

        $reqTx = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/transactions']);
        $respTx = $this->controller->transactions($reqTx);
        $this->assertSame(403, $respTx->getStatusCode());

        $reqPay = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/payments']);
        $respPay = $this->controller->payments($reqPay);
        $this->assertSame(403, $respPay->getStatusCode());

        $reqRecharge = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/account/recharge']);
        $respRecharge = $this->controller->recharge($reqRecharge);
        $this->assertSame(403, $respRecharge->getStatusCode());
    }

}
