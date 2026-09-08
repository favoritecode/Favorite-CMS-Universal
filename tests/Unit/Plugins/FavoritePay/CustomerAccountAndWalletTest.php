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
}
