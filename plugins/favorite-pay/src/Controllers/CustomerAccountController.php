<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
use FavoriteCMS\Pay\Contracts\WithdrawalServiceInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Support\DecimalFormatter;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class CustomerAccountController
{
    protected Application $app;
    protected WalletServiceInterface $walletService;
    protected PaymentServiceInterface $paymentService;
    protected GatewayRegistry $gatewayRegistry;
    protected CurrencyServiceInterface $currencyService;
    protected ?Database $db;
    protected ?WithdrawalServiceInterface $withdrawalService;

    public function __construct(
        Application $app,
        WalletServiceInterface $walletService,
        PaymentServiceInterface $paymentService,
        GatewayRegistry $gatewayRegistry,
        CurrencyServiceInterface $currencyService,
        ?Database $db = null,
        ?WithdrawalServiceInterface $withdrawalService = null
    ) {
        $this->app = $app;
        $this->walletService = $walletService;
        $this->paymentService = $paymentService;
        $this->gatewayRegistry = $gatewayRegistry;
        $this->currencyService = $currencyService;
        $this->db = $db;
        $this->withdrawalService = $withdrawalService;

        if ($this->withdrawalService === null && method_exists($this->app, 'has') && $this->app->has(WithdrawalServiceInterface::class)) {
            $this->withdrawalService = $this->app->make(WithdrawalServiceInterface::class);
        }
    }

    /**
     * Resolve the current authenticated user.
     * Returns null if unauthenticated.
     */
    public function resolveCurrentUser(): ?User
    {
        // Test mocking hooks
        if (isset($GLOBALS['_test_current_user']) && $GLOBALS['_test_current_user'] instanceof User) {
            return $GLOBALS['_test_current_user'];
        }

        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            return null;
        }

        if (class_exists(User::class)) {
            try {
                $user = User::find($userId);
                if ($user instanceof User) {
                    return $user;
                }
            } catch (Throwable) {
            }
        }

        // Mock object for testing environments without User model table
        if (isset($GLOBALS['_test_mock_user'])) {
            return $GLOBALS['_test_mock_user'];
        }

        return null;
    }

    /**
     * Resolve current user ID strictly from server session / identity.
     * Never trusts user_id or customer_id from request parameters.
     */
    public function resolveCurrentUserId(): int
    {
        if (isset($GLOBALS['_test_current_user_id']) && (int)$GLOBALS['_test_current_user_id'] > 0) {
            return (int)$GLOBALS['_test_current_user_id'];
        }
        if (isset($GLOBALS['_test_current_user']) && isset($GLOBALS['_test_current_user']->id)) {
            return (int)$GLOBALS['_test_current_user']->id;
        }
        if (function_exists('current_user_id')) {
            $id = current_user_id();
            if ($id !== null && (int)$id > 0) {
                return (int)$id;
            }
        }
        if (function_exists('current_user')) {
            $u = current_user();
            if ($u !== null && isset($u->id) && (int)$u->id > 0) {
                return (int)$u->id;
            }
        }
        if (isset($_SESSION['auth_user_id']) && (int)$_SESSION['auth_user_id'] > 0) {
            return (int)$_SESSION['auth_user_id'];
        }
        if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
            return (int)$_SESSION['user_id'];
        }

        return 0;
    }

    /**
     * Ensure user is authenticated. If not, redirect to login.
     * If user is banned, block immediately.
     */
    protected function requireAuth(Request $request): User|Response
    {
        $user = $this->resolveCurrentUser();
        if (!$user) {
            return Response::redirect('/login');
        }

        if (method_exists($user, 'isBanned') && $user->isBanned()) {
            return new Response('Access denied: Your account is suspended or banned.', 403);
        }

        $status = strtolower((string)($user->status ?? 'active'));
        if ($status === 'banned') {
            return new Response('Access denied: Your account is suspended or banned.', 403);
        }

        return $user;
    }

    /**
     * Customer Digital Wallet Hub (/account/wallet).
     */
    public function wallet(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        $user = $auth;
        $userId = (int)$user->id;

        $currency = $this->walletService->getWalletCurrency($userId);
        $balance = $this->walletService->getBalance($userId);
        $recentLedger = $this->walletService->getLedgerHistory($userId, 10, 0);

        // Fetch wallet status from database if available
        $walletStatus = 'active';
        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallets')) {
            $walletRow = $this->db->selectOne("SELECT status FROM favorite_pay_wallets WHERE user_id = ?", [$userId]);
            if ($walletRow && !empty($walletRow->status)) {
                $walletStatus = (string)$walletRow->status;
            }
        }

        $html = $this->renderView('wallet', [
            'pageTitle'    => 'My Wallet & Balance',
            'activeTab'    => 'wallet',
            'user'         => $user,
            'userId'       => $userId,
            'balance'      => $balance,
            'currency'     => $currency,
            'walletStatus' => $walletStatus,
            'recentLedger' => $recentLedger,
            'isSuspended'  => method_exists($user, 'isSuspended') && $user->isSuspended(),
        ]);

        return Response::make($html, 200);
    }

    /**
     * Customer Recharge Page (/account/recharge).
     * GET: Displays amount input and payment method selection.
     * POST: Validates input, creates PaymentIntent, delegates to gateway.
     */
    public function recharge(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        $user = $auth;
        $userId = (int)$user->id;

        $isSuspended = method_exists($user, 'isSuspended') && $user->isSuspended();

        if ($request->method() === 'POST') {
            // Security: Suspended accounts cannot perform financial recharge actions
            if ($isSuspended) {
                return new Response('Access restricted: Your account is suspended. Wallet and payment history are accessible in read-only mode, but new recharge and payment attempts are blocked.', 403);
            }

            // Security: CSRF Validation
            if (!$this->validateCsrf($request)) {
                $_SESSION['flash_error'] = 'Invalid or expired security token. Please try again.';
                return Response::redirect('/account/recharge');
            }

            return $this->handleRechargeSubmit($request, $user);
        }

        // Security: Suspended accounts cannot view recharge form
        if ($isSuspended) {
            return new Response('Access restricted: Your account is suspended. Wallet and payment history are accessible in read-only mode, but new recharge and payment attempts are blocked.', 403);
        }

        // GET: Discover active, configured gateways
        $primaryCurrency = $this->walletService->getPrimaryCurrency();
        $gateways = $this->getAvailableGateways($primaryCurrency);
        $balance = $this->walletService->getBalance($userId);

        $html = $this->renderView('recharge', [
            'pageTitle'       => 'Recharge Wallet Balance',
            'activeTab'       => 'recharge',
            'user'            => $user,
            'userId'          => $userId,
            'balance'         => $balance,
            'primaryCurrency' => $primaryCurrency,
            'gateways'        => $gateways,
            'csrfToken'       => $this->getCsrfToken(),
            'isSuspended'     => $isSuspended,
        ]);

        return Response::make($html, 200);
    }

    /**
     * Handle submission of recharge form.
     */
    protected function handleRechargeSubmit(Request $request, User $user): Response
    {
        $userId = (int)$user->id;
        $primaryCurrency = $this->walletService->getPrimaryCurrency();

        // 1. Amount validation
        $rawAmount = trim((string)$request->post('amount', ''));
        $cleanAmount = str_replace([',', ' '], '', $rawAmount);

        if ($cleanAmount === '' || !is_numeric($cleanAmount) || (float)$cleanAmount <= 0) {
            $_SESSION['flash_error'] = 'Please enter a valid positive recharge amount.';
            return Response::redirect('/account/recharge');
        }

        $numericAmount = (float)$cleanAmount;
        if ($numericAmount < 1.0) {
            $_SESSION['flash_error'] = 'Minimum recharge amount is 1 ' . $primaryCurrency . '.';
            return Response::redirect('/account/recharge');
        }
        if ($numericAmount > 1000000.0) {
            $_SESSION['flash_error'] = 'Recharge amount exceeds maximum allowed limit.';
            return Response::redirect('/account/recharge');
        }

        // Convert to minor units (integers only)
        $minorUnits = (int)round($numericAmount * 100);
        $baseMoney = new Money($minorUnits, $primaryCurrency);

        // 2. Gateway selection & validation
        $gatewayId = trim((string)$request->post('gateway_id', ''));
        $gatewayId = trim((string)($request->post('gateway_id') ?: $request->post('gateway', '')));
        if ($gatewayId === '') {
            $_SESSION['flash_error'] = 'Please select a payment method.';
            return Response::redirect('/account/recharge');
        }

        if (!$this->gatewayRegistry->has($gatewayId)) {
            $_SESSION['flash_error'] = 'The selected payment method is not recognized.';
            return Response::redirect('/account/recharge');
        }

        $gateway = $this->gatewayRegistry->get($gatewayId);
        if (!$gateway->isEnabled()) {
            $_SESSION['flash_error'] = 'The selected payment method is currently unavailable.';
            return Response::redirect('/account/recharge');
        }

        // 3. Create payment intent using existing PaymentService
        try {
            $intent = $this->paymentService->createIntent(
                'favorite-pay',
                'recharge_' . $userId . '_' . bin2hex(random_bytes(6)),
                $baseMoney,
                [
                    'customer_id' => $userId,
                    'gateway_id'  => $gatewayId,
                    'metadata'    => [
                        'type'        => 'wallet_recharge',
                        'user_id'     => $userId,
                        'gateway_id'  => $gatewayId,
                        'description' => "Wallet balance recharge for " . ($user->name ?? $user->username ?? "User #{$userId}"),
                    ],
                ]
            );
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to create payment: ' . $e->getMessage();
            return Response::redirect('/account/recharge');
        }

        $intentId = $intent->getId();

        // 4. Branch by gateway type
        // Manual Gateways
        if ($gateway instanceof ManualBangladeshGateway || str_starts_with($gatewayId, 'manual_')) {
            return Response::redirect('/account/recharge/manual?intent=' . urlencode($intentId));
        }

        // Automatic Gateways (Binance Pay, bKash Merchant, etc.)
        try {
            $isBinance = in_array($gatewayId, ['binance', 'binance_pay'], true)
                || $gateway instanceof \FavoriteCMS\Pay\Gateways\Binance\BinancePayGateway;

            $attempt = $this->paymentService->initiatePayment($intentId, $gatewayId, [
                'terminal_type' => 'WEB',
                'return_url'    => $this->appUrl('/account/recharge/binance/' . urlencode($intentId)),
                'cancel_url'    => $this->appUrl('/account/recharge'),
            ]);

            // For Binance Pay, direct to dedicated customer QR checkout screen
            if ($isBinance) {
                return Response::redirect('/account/recharge/binance/' . urlencode($intentId));
            }

            $metadata = $attempt->getMetadata();
            $checkoutUrl = $metadata['checkout_url'] ?? null;

            if (!empty($checkoutUrl) && filter_var($checkoutUrl, FILTER_VALIDATE_URL)) {
                return Response::redirect($checkoutUrl);
            }

            // If no immediate redirect URL, direct to payment receipt / status page
            return Response::redirect('/account/payments/' . urlencode($intentId));
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Gateway initiation failed: ' . $e->getMessage();
            return Response::redirect('/account/payments/' . urlencode($intentId));
        }
    }

    /**
     * Customer Manual Payment Instructions & TrxID Submission (/account/recharge/manual).
     */
    public function showManual(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        $user = $auth;
        $userId = (int)$user->id;

        $intentId = trim((string)$request->get('intent_id', ''));
        if (method_exists($user, 'isSuspended') && $user->isSuspended()) {
            return new Response('Access restricted: Your account is suspended. Protected financial and recharge actions are restricted.', 403);
        }

        $intentId = trim((string)($request->get('intent') ?: $request->get('intent_id', '')));
        if ($intentId === '') {
            $_SESSION['flash_error'] = 'No payment specified.';
            return Response::redirect('/account/wallet');
        }

        $intent = $this->paymentService->getIntent($intentId);
        if (!$intent) {
            $_SESSION['flash_error'] = 'Payment record not found.';
            return Response::redirect('/account/wallet');
        }

        // Security / IDOR: Ensure current user owns this intent
        if ($intent->getUserId() !== null && $intent->getUserId() !== $userId) {
            $_SESSION['flash_error'] = 'Access denied: You do not have permission to view this payment.';
            return Response::redirect('/account/wallet');
        }

        $gatewayId = $intent->getMetadata()['gateway_id'] ?? 'manual_bd';
        $gateway = $this->gatewayRegistry->has($gatewayId) ? $this->gatewayRegistry->get($gatewayId) : null;
        $gatewayConfig = ($gateway instanceof ManualBangladeshGateway) ? $gateway->getConfig() : [];

        $html = $this->renderView('manual', [
            'pageTitle'     => 'Complete Manual Payment',
            'activeTab'     => 'recharge',
            'user'          => $user,
            'userId'        => $userId,
            'intent'        => $intent,
            'gateway'       => $gateway,
            'gatewayConfig' => $gatewayConfig,
            'csrfToken'     => $this->getCsrfToken(),
            'isSuspended'   => method_exists($user, 'isSuspended') && $user->isSuspended(),
            'isSuspended'   => true,
        ]);

        return Response::make($html, 200);
    }

    /**
     * Submit Manual Payment TrxID (POST /account/recharge/manual).
     */
    public function submitManual(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        $user = $auth;
        $userId = (int)$user->id;

        if (method_exists($user, 'isSuspended') && $user->isSuspended()) {
            $_SESSION['flash_error'] = 'Your account is suspended. Protected financial submissions are restricted.';
            return Response::redirect('/account/wallet');
            return new Response('Access restricted: Your account is suspended. Protected financial and recharge actions are restricted.', 403);
        }

        if (!$this->validateCsrf($request)) {
            $_SESSION['flash_error'] = 'Invalid or expired security token. Please try again.';
            return Response::redirect('/account/recharge');
        }

        $intentId = trim((string)$request->post('intent_id', ''));
        $intentId = trim((string)($request->post('intent') ?: $request->post('intent_id', '')));
        $trxId = trim((string)$request->post('trx_id', ''));
        $senderNumber = trim((string)$request->post('sender_number', ''));

        if ($intentId === '') {
            $_SESSION['flash_error'] = 'Payment intent ID is missing.';
            return Response::redirect('/account/recharge');
        }

        if ($trxId === '') {
            $_SESSION['flash_error'] = 'Transaction Reference / TrxID is required.';
            return Response::redirect('/account/recharge/manual?intent_id=' . urlencode($intentId));
        }

        $intent = $this->paymentService->getIntent($intentId);
        if (!$intent) {
            $_SESSION['flash_error'] = 'Payment intent not found.';
            return Response::redirect('/account/wallet');
        }

        // Security / IDOR: Verify ownership
        if ($intent->getUserId() !== null && $intent->getUserId() !== $userId) {
            $_SESSION['flash_error'] = 'Access denied: unauthorized transaction submission.';
            return Response::redirect('/account/wallet');
        }

        $gatewayId = $intent->getMetadata()['gateway_id'] ?? 'manual_bd';

        try {
            $this->paymentService->submitManualVerification($intentId, $gatewayId, $trxId, [
                'sender_number' => $senderNumber,
            ]);

            $_SESSION['flash_success'] = 'Payment submitted successfully! Your transaction is in verification queue and will be credited once verified.';
            return Response::redirect('/account/payments/' . urlencode($intentId));
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Submission failed: ' . $e->getMessage();
            return Response::redirect('/account/recharge/manual?intent_id=' . urlencode($intentId));
        }
    }

    /**
     * Customer Payment History (/account/payments).
     */
    public function payments(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        $user = $auth;
        $userId = (int)$user->id;

        $page = max(1, (int)$request->get('page', 1));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $paymentsData = $this->getCustomerPayments($userId, $page, $perPage, $offset);

        $html = $this->renderView('payments', [
            'pageTitle'    => 'Payment History',
            'activeTab'    => 'payments',
            'user'         => $user,
            'userId'       => $userId,
            'payments'     => $paymentsData['items'],
            'total'        => $paymentsData['total'],
            'page'         => $page,
            'perPage'      => $perPage,
            'totalPages'   => max(1, (int)ceil($paymentsData['total'] / $perPage)),
            'isSuspended'  => method_exists($user, 'isSuspended') && $user->isSuspended(),
        ]);

        return Response::make($html, 200);
    }

    /**
     * Customer Payment Details (/account/payments/{id}).
     */
    public function paymentDetail(Request $request, string $id): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        $user = $auth;
        $userId = (int)$user->id;

        // Security / IDOR: Explicitly check if payment belongs to someone else
        $intent = $this->paymentService->getIntent($id);
        if ($intent !== null && $intent->getUserId() !== null && (int)$intent->getUserId() !== $userId) {
            return new Response('Access denied: You do not have permission to view this payment.', 403);
        }
        if ($this->db !== null && $this->db->tableExists('favorite_pay_transactions')) {
            $row = $this->db->selectOne("SELECT user_id FROM favorite_pay_transactions WHERE transaction_id = ?", [$id]);
            if ($row && (int)$row->user_id !== $userId) {
                return new Response('Access denied: You do not have permission to view this payment.', 403);
            }
        }

        $payment = $this->getCustomerPaymentDetail($id, $userId);
        if (!$payment) {
            $_SESSION['flash_error'] = 'Payment record not found or access denied.';
            return Response::redirect('/account/payments');
        }

        $html = $this->renderView('payment_detail', [
            'pageTitle'   => 'Payment #' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8'),
            'activeTab'   => 'payments',
            'user'        => $user,
            'userId'      => $userId,
            'payment'     => $payment,
            'isSuspended' => method_exists($user, 'isSuspended') && $user->isSuspended(),
        ]);

        return Response::make($html, 200);
    }

    /**
     * Customer Wallet Transactions / Ledger (/account/transactions).
     */
    public function transactions(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        $user = $auth;
        $userId = (int)$user->id;

        $page = max(1, (int)$request->get('page', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $currency = $this->walletService->getWalletCurrency($userId);
        $balance = $this->walletService->getBalance($userId);
        $entries = $this->walletService->getLedgerHistory($userId, $perPage, $offset);

        // Calculate total entries if database is available
        $total = count($entries);
        if ($this->db !== null && $this->db->tableExists('favorite_pay_wallet_entries')) {
            $cntRow = $this->db->selectOne(
                "SELECT COUNT(*) as cnt FROM favorite_pay_wallet_entries WHERE user_id = ?",
                [$userId]
            );
            if ($cntRow) {
                $total = (int)$cntRow->cnt;
            }
        }

        $html = $this->renderView('transactions', [
            'pageTitle'   => 'Wallet Transactions',
            'activeTab'   => 'transactions',
            'user'        => $user,
            'userId'      => $userId,
            'balance'     => $balance,
            'currency'    => $currency,
            'entries'     => $entries,
            'total'       => $total,
            'page'        => $page,
            'perPage'     => $perPage,
            'totalPages'  => max(1, (int)ceil($total / $perPage)),
            'isSuspended' => method_exists($user, 'isSuspended') && $user->isSuspended(),
        ]);

        return Response::make($html, 200);
    }

    /**
     * Query available active and configured payment gateways.
     */
    public function getAvailableGateways(string $currency): array
    {
        $all = $this->gatewayRegistry->all();
        $available = [];

        foreach ($all as $id => $gateway) {
            if (!$gateway->isEnabled()) {
                continue;
            }

            // Check if gateway is properly configured
            if ($id === 'binance_pay' || $id === 'binance') {
                $config = method_exists($gateway, 'getConfig') ? $gateway->getConfig() : [];
                if (empty($config['certificate_sn']) || empty($config['api_secret'])) {
                    continue; // Skip unconfigured Binance
                }
            }

            $available[$id] = [
                'id'          => $gateway->getId(),
                'title'       => $gateway->getTitle(),
                'type'        => $gateway->getType()->value,
                'description' => $this->getGatewayDescription($gateway),
                'is_manual'   => $gateway instanceof ManualBangladeshGateway || str_starts_with($gateway->getId(), 'manual_'),
            ];
        }

        return $available;
    }

    protected function getGatewayDescription(object $gateway): string
    {
        $id = $gateway->getId();
        return match ($id) {
            'manual_bkash' => 'Send Money from your bKash mobile account and submit the 10-digit TrxID.',
            'manual_nagad' => 'Send Money from your Nagad account and submit the transaction TrxID.',
            'manual_rocket' => 'Transfer via Rocket mobile banking and submit the reference number.',
            'manual_bank' => 'Direct transfer to our bank account via EFT, NPSB or online deposit.',
            'binance_pay', 'binance' => 'Instant cryptocurrency payment via Binance Pay (USDT, USDC, BTC, etc.).',
            default => 'Fast and secure payment processing.',
        };
    }

    /**
     * Retrieve paginated payments for customer.
     */
    protected function getCustomerPayments(int $userId, int $page, int $perPage, int $offset): array
    {
        if ($this->db !== null && $this->db->tableExists('favorite_pay_transactions')) {
            $totalRow = $this->db->selectOne(
                "SELECT COUNT(*) as cnt FROM favorite_pay_transactions WHERE user_id = ?",
                [$userId]
            );
            $total = (int)($totalRow->cnt ?? 0);

            $rows = $this->db->select(
                "SELECT * FROM favorite_pay_transactions 
                 WHERE user_id = ? 
                 ORDER BY id DESC 
                 LIMIT {$perPage} OFFSET {$offset}",
                [$userId]
            );

            return [
                'items' => array_map(fn($r) => (array)$r, $rows),
                'total' => $total,
            ];
        }

        // In-memory reflection fallback for isolated test environments
        if ($this->paymentService instanceof \FavoriteCMS\Pay\Services\PaymentService) {
            $ref = new \ReflectionClass($this->paymentService);
            if ($ref->hasProperty('intents')) {
                $prop = $ref->getProperty('intents');
                $prop->setAccessible(true);
                $allIntents = $prop->getValue($this->paymentService);
                $userIntents = [];
                foreach ($allIntents as $intent) {
                    if ($intent instanceof \FavoriteCMS\Pay\Domain\PaymentIntent && (int)$intent->getUserId() === $userId) {
                        $metadata = $intent->getMetadata();
                        $gwId = $metadata['gateway_id'] ?? '';
                        $gwTitle = 'Online Payment';
                        if ($gwId !== '' && $this->gatewayRegistry->has($gwId)) {
                            $gwTitle = $this->gatewayRegistry->get($gwId)->getTitle();
                        } elseif ($gwId !== '') {
                            $gwTitle = ucwords(str_replace('_', ' ', $gwId));
                        }

                        $userIntents[] = [
                            'transaction_id'      => $intent->getId(),
                            'source_plugin'       => $intent->getSourcePlugin(),
                            'source_reference'    => $intent->getSourceReference(),
                            'user_id'             => $userId,
                            'base_amount'         => $intent->getBaseAmount()->getAmount(),
                            'base_currency'       => $intent->getBaseAmount()->getCurrency(),
                            'charge_amount'       => $intent->getChargeAmount()->getAmount(),
                            'charge_currency'     => $intent->getChargeAmount()->getCurrency(),
                            'status'              => $intent->getStatus()->value,
                            'payment_method_type' => $intent->getMethodType()?->value,
                            'gateway_id'          => $gwId,
                            'gateway_title'       => $gwTitle,
                            'created_at'          => date('Y-m-d H:i:s'),
                            'wallet_settled'      => $intent->getStatus() === \FavoriteCMS\Pay\Domain\PaymentStatus::SUCCEEDED,
                        ];
                    }
                }
                return [
                    'items' => array_slice(array_reverse($userIntents), $offset, $perPage),
                    'total' => count($userIntents),
                ];
            }
        }

        return [
            'items' => [],
            'total' => 0,
        ];
    }

    /**
     * Retrieve single payment details strictly verifying user ownership.
     */
    protected function getCustomerPaymentDetail(string $transactionId, int $userId): ?array
    {
        if ($this->db !== null && $this->db->tableExists('favorite_pay_transactions')) {
            $row = $this->db->selectOne(
                "SELECT * FROM favorite_pay_transactions WHERE transaction_id = ? LIMIT 1",
                [$transactionId]
            );

            if (!$row) {
                return null;
            }

            // Security: IDOR protection
            if ((int)$row->user_id !== $userId) {
                return null;
            }

            $detail = (array)$row;

            // Fetch gateway title
            $gwId = $detail['gateway_id'] ?? '';
            $detail['gateway_title'] = $this->gatewayRegistry->has($gwId)
                ? $this->gatewayRegistry->get($gwId)->getTitle()
                : ($gwId !== '' ? ucwords(str_replace('_', ' ', $gwId)) : 'Online Payment');

            // Fetch safe attempt records (without secrets)
            $detail['attempts'] = [];
            if ($this->db->tableExists('favorite_pay_attempts')) {
                $attempts = $this->db->select(
                    "SELECT attempt_id, gateway_id, amount, currency, status, provider_reference, created_at, verified_at 
                     FROM favorite_pay_attempts 
                     WHERE transaction_id = ? 
                     ORDER BY id DESC",
                    [$transactionId]
                );
                $detail['attempts'] = array_map(fn($a) => (array)$a, $attempts);
            }

            // Check wallet settlement status
            $detail['wallet_settled'] = false;
            if ($this->db->tableExists('favorite_pay_wallet_entries')) {
                $settleRow = $this->db->selectOne(
                    "SELECT entry_id, created_at, balance_after FROM favorite_pay_wallet_entries 
                     WHERE reference_type = 'payment' AND reference_id = ? LIMIT 1",
                    [$transactionId]
                );
                if ($settleRow) {
                    $detail['wallet_settled'] = true;
                    $detail['settlement_entry'] = (array)$settleRow;
                }
            }

            return $detail;
        }

        // In-memory fallback for isolated tests
        $intent = $this->paymentService->getIntent($transactionId);
        if ($intent && (int)$intent->getUserId() === $userId) {
            $metadata = $intent->getMetadata();
            $gwId = $metadata['gateway_id'] ?? '';
            $gwTitle = 'Online Payment';
            if ($gwId !== '' && $this->gatewayRegistry->has($gwId)) {
                $gwTitle = $this->gatewayRegistry->get($gwId)->getTitle();
            } elseif ($gwId !== '') {
                $gwTitle = ucwords(str_replace('_', ' ', $gwId));
            }

            $rawAttempts = [];
            if (method_exists($this->paymentService, 'getAttemptsForTransaction')) {
                $rawAttempts = $this->paymentService->getAttemptsForTransaction($transactionId);
            } else {
                try {
                    $refClass = new \ReflectionClass($this->paymentService);
                    if ($refClass->hasProperty('attempts')) {
                        $prop = $refClass->getProperty('attempts');
                        $prop->setAccessible(true);
                        $allAttempts = $prop->getValue($this->paymentService) ?: [];
                        foreach ($allAttempts as $att) {
                            if ($att instanceof \FavoriteCMS\Pay\Domain\PaymentAttempt && $att->getIntentId() === $transactionId) {
                                $rawAttempts[] = $att;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $rawAttempts = [];
                }
            }
            $formattedAttempts = [];
            foreach ($rawAttempts as $att) {
                $formattedAttempts[] = [
                    'attempt_id'         => $att->getId(),
                    'gateway_id'         => $att->getGatewayId(),
                    'amount'             => $att->getAmount()->getAmount(),
                    'currency'           => $att->getAmount()->getCurrency(),
                    'status'             => $att->getStatus()->value,
                    'provider_reference' => $att->getTransactionReference(),
                    'created_at'         => $att->getCreatedAt(),
                    'verified_at'        => $att->getVerifiedAt(),
                ];
            }

            return [
                'transaction_id'      => $intent->getId(),
                'source_plugin'       => $intent->getSourcePlugin(),
                'source_reference'    => $intent->getSourceReference(),
                'user_id'             => $userId,
                'base_amount'         => $intent->getBaseAmount()->getAmount(),
                'base_currency'       => $intent->getBaseAmount()->getCurrency(),
                'charge_amount'       => $intent->getChargeAmount()->getAmount(),
                'charge_currency'     => $intent->getChargeAmount()->getCurrency(),
                'status'              => $intent->getStatus()->value,
                'payment_method_type' => $intent->getMethodType()?->value,
                'gateway_id'          => $gwId,
                'gateway_title'       => $gwTitle,
                'created_at'          => date('Y-m-d H:i:s'),
                'attempts'            => $formattedAttempts,
                'wallet_settled'      => $intent->getStatus() === PaymentStatus::SUCCEEDED,
            ];
        }

        return null;
    }

    /**
     * Render customer-facing HTML view.
     */
    protected function renderView(string $viewName, array $data = []): string
    {
        $viewFile = __DIR__ . '/../../views/customer/' . $viewName . '.php';
        $layoutFile = __DIR__ . '/../../views/customer/layout.php';

        if (!file_exists($viewFile)) {
            return "<div class='alert alert-danger'>View file not found: " . htmlspecialchars($viewName, ENT_QUOTES, 'UTF-8') . "</div>";
        }

        if (!isset($data['withdrawEnabled'])) {
            $data['withdrawEnabled'] = $this->withdrawalService !== null && $this->withdrawalService->isWithdrawalEnabled();
        }

        $data['contentView'] = $viewFile;
        extract($data, EXTR_SKIP);

        ob_start();
        try {
            if (file_exists($layoutFile)) {
                include $layoutFile;
            } else {
                include $viewFile;
            }
            return (string)ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    /**
     * Customer withdrawal request & history area (/account/withdraw).
     */
    public function withdraw(Request $request): Response
    {
        // 1. Feature flag check
        if ($this->withdrawalService === null || !$this->withdrawalService->isWithdrawalEnabled()) {
            return new Response('Withdrawals are currently disabled.', 403, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        // 2. Authentication check
        $auth = $this->requireAuth($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        $user = $auth;
        $userId = (int)$user->id;

        // 3. Suspended check
        $isSuspended = method_exists($user, 'isSuspended') && $user->isSuspended();
        if ($isSuspended) {
            return new Response('Access restricted: Your account is suspended. Withdrawals are disabled.', 403, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $status = strtolower((string)($user->status ?? 'active'));
        if (in_array($status, ['suspended', 'banned', 'inactive'], true)) {
            return new Response('Access restricted: Your account is inactive or suspended. Withdrawals are disabled.', 403, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        // 4. Handle POST (Create withdrawal request)
        if ($request->isPost()) {
            if (!$this->validateCsrf($request)) {
                $_SESSION['flash_error'] = 'Invalid or expired security token. Please try again.';
                return Response::redirect('/account/withdraw');
            }

            $amountStr = trim((string)$request->post('amount', ''));
            $currency = strtoupper(trim((string)$request->post('currency', 'BDT')));
            $payoutMethod = trim((string)$request->post('payout_method', ''));
            $destination = trim((string)$request->post('destination', ''));
            $accountName = trim((string)$request->post('account_name', ''));
            $userNote = trim((string)$request->post('user_note', ''));
            $idempotencyKey = trim((string)$request->post('idempotency_key', ''));

            if (!is_numeric($amountStr) || (float)$amountStr <= 0) {
                $_SESSION['flash_error'] = 'Please enter a valid withdrawal amount greater than zero.';
                return Response::redirect('/account/withdraw');
            }

            try {
                $minorAmount = DecimalFormatter::decimalToMinorUnit($amountStr, 2);
                $money = new Money($minorAmount, $currency);

                $withdrawal = $this->withdrawalService->createWithdrawal(
                    userId: $userId,
                    amount: $money,
                    payoutMethod: $payoutMethod,
                    destination: $destination,
                    accountName: $accountName !== '' ? $accountName : null,
                    userNote: $userNote !== '' ? $userNote : null,
                    idempotencyKey: $idempotencyKey !== '' ? $idempotencyKey : null
                );

                $_SESSION['flash_success'] = 'Withdrawal request submitted successfully.';
                return Response::redirect('/account/withdrawals/' . $withdrawal->getId());
            } catch (InvalidArgumentException $e) {
                $_SESSION['flash_error'] = $e->getMessage();
                return Response::redirect('/account/withdraw');
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = 'Failed to submit withdrawal: ' . $e->getMessage();
                return Response::redirect('/account/withdraw');
            }
        }

        // 5. Handle GET (Form + History)
        $primaryCurrency = $this->walletService->getPrimaryCurrency();
        $wallet = $this->walletService->getOrCreateWallet($userId, $primaryCurrency);
        $withdrawals = $this->withdrawalService->getCustomerWithdrawals($userId, 20, 0);
        $methods = $this->withdrawalService->getSupportedPayoutMethods();

        $html = $this->renderView('withdraw', [
            'pageTitle'       => 'Withdraw Balance',
            'activeTab'       => 'withdraw',
            'user'            => $user,
            'userId'          => $userId,
            'wallet'          => $wallet,
            'currency'        => $primaryCurrency,
            'withdrawEnabled' => true,
            'csrfToken'       => $this->getCsrfToken(),
            'methods'         => $methods,
            'withdrawals'     => $withdrawals,
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Customer withdrawal detail screen (/account/withdrawals/{id}).
     */
    public function withdrawalDetail(Request $request, string $id): Response
    {
        // 1. Authentication check
        $auth = $this->requireAuth($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        $user = $auth;
        $userId = (int)$user->id;

        if ($this->withdrawalService === null) {
            return new Response('Withdrawal system is unavailable.', 404, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $withdrawal = $this->withdrawalService->getWithdrawal(trim($id));
        if ($withdrawal === null) {
            return new Response('Withdrawal not found.', 404, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        // IDOR Prevention: Users can only view their own withdrawals
        if ($withdrawal->getUserId() !== $userId) {
            return new Response('Access denied: You do not have permission to view this withdrawal.', 403, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        // Customer Cancel action
        if ($request->isPost() && $request->post('action') === 'cancel') {
            if (!$this->validateCsrf($request)) {
                $_SESSION['flash_error'] = 'Invalid or expired security token.';
                return Response::redirect('/account/withdrawals/' . $withdrawal->getId());
            }

            try {
                $this->withdrawalService->cancelWithdrawal($withdrawal->getId(), $userId, 'Cancelled by customer');
                $_SESSION['flash_success'] = 'Withdrawal request has been cancelled and hold funds restored to your wallet.';
                return Response::redirect('/account/withdrawals/' . $withdrawal->getId());
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = 'Unable to cancel withdrawal: ' . $e->getMessage();
                return Response::redirect('/account/withdrawals/' . $withdrawal->getId());
            }
        }

        $html = $this->renderView('withdrawal_detail', [
            'pageTitle'       => 'Withdrawal #' . substr($withdrawal->getId(), 0, 8),
            'activeTab'       => 'withdraw',
            'user'            => $user,
            'userId'          => $userId,
            'withdrawal'      => $withdrawal,
            'withdrawEnabled' => $this->withdrawalService->isWithdrawalEnabled(),
            'csrfToken'       => $this->getCsrfToken(),
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Dedicated customer Binance Pay QR checkout screen (/account/recharge/binance/{intent_id}).
     */
    public function binanceCheckout(Request $request, ?string $intentId = null): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        $user = $auth;
        $userId = (int)$user->id;

        if (method_exists($user, 'isBanned') && $user->isBanned()) {
            return new Response('Access restricted: Your account has been banned.', 403);
        }
        $isSuspended = method_exists($user, 'isSuspended') && $user->isSuspended();

        if ($intentId === null || trim($intentId) === '') {
            $intentId = trim((string)($request->get('intent') ?: $request->get('intent_id', '')));
        }

        if ($intentId === '') {
            return Response::redirect('/account/recharge');
        }

        $intent = $this->paymentService->getIntent($intentId);
        if (!$intent) {
            return new Response('Payment intent not found.', 404);
        }

        // Strict Anti-IDOR: verify payment ownership
        if ((int)$intent->getUserId() !== $userId) {
            return new Response('Access denied: You do not have permission to view this payment.', 403);
        }

        // Suspended accounts: can view status of their own existing payment in read-only mode
        $attempts = [];
        if (method_exists($this->paymentService, 'getAttemptsForTransaction')) {
            $attempts = $this->paymentService->getAttemptsForTransaction($intentId);
        }

        $binanceAttempt = null;
        foreach ($attempts as $att) {
            if (in_array($att->getGatewayId(), ['binance', 'binance_pay'], true)) {
                $binanceAttempt = $att;
                break;
            }
        }

        if (!$binanceAttempt && $this->db !== null && $this->db->tableExists('favorite_pay_attempts')) {
            $row = $this->db->selectOne(
                "SELECT * FROM favorite_pay_attempts WHERE transaction_id = ? AND gateway_id IN ('binance', 'binance_pay') ORDER BY id DESC LIMIT 1",
                [$intentId]
            );
            if ($row) {
                $binanceAttempt = $this->paymentService->getAttempt((string)$row->attempt_id);
            }
        }

        // If still pending and no attempt exists, initiate one if gateway is registered and user not suspended
        if (!$binanceAttempt && $intent->getStatus() === PaymentStatus::PENDING) {
            if ($isSuspended) {
                return new Response('Access restricted: Your account is suspended. New payment attempts are blocked.', 403);
            }
            $gwId = $this->gatewayRegistry->has('binance_pay') ? 'binance_pay' : ($this->gatewayRegistry->has('binance') ? 'binance' : null);
            if ($gwId !== null) {
                try {
                    $binanceAttempt = $this->paymentService->initiatePayment($intentId, $gwId, [
                        'terminal_type' => 'WEB',
                        'return_url'    => $this->appUrl('/account/recharge/binance/' . urlencode($intentId)),
                        'cancel_url'    => $this->appUrl('/account/recharge'),
                    ]);
                } catch (\Throwable) {
                    // Gateway initiation failure caught cleanly
                }
            }
        }

        $meta = $binanceAttempt ? $binanceAttempt->getMetadata() : ($intent->getMetadata() ?? []);
        $checkoutUrl = $meta['checkout_url'] ?? null;
        $qrcodeLink = $meta['qrcode_link'] ?? null;
        $qrContent = $meta['qr_content'] ?? null;

        // Strict URL validation: ensure checkout URL belongs to Binance
        $validatedCheckoutUrl = null;
        if (!empty($checkoutUrl) && filter_var($checkoutUrl, FILTER_VALIDATE_URL)) {
            $parsedHost = strtolower((string)(parse_url($checkoutUrl, PHP_URL_HOST) ?? ''));
            if (
                $parsedHost === 'binance.com' 
                || str_ends_with($parsedHost, '.binance.com') 
                || $parsedHost === 'binanceapi.com' 
                || str_ends_with($parsedHost, '.binanceapi.com')
            ) {
                $validatedCheckoutUrl = $checkoutUrl;
            }
        }

        // Check wallet settlement status
        $walletSettled = false;
        if ($intent->getStatus() === PaymentStatus::SUCCEEDED) {
            if ($this->db !== null && $this->db->tableExists('favorite_pay_wallet_entries')) {
                $entry = $this->db->selectOne(
                    "SELECT 1 FROM favorite_pay_wallet_entries WHERE reference_type = 'payment' AND reference_id = ? LIMIT 1",
                    [$intentId]
                );
                $walletSettled = ($entry !== null);
            } else {
                $walletSettled = true;
            }
        }

        $html = $this->renderView('binance_checkout', [
            'pageTitle'            => 'Binance Pay Checkout',
            'activeTab'            => 'recharge',
            'user'                 => $user,
            'userId'               => $userId,
            'intent'               => $intent,
            'binanceAttempt'       => $binanceAttempt,
            'qrcodeLink'           => $qrcodeLink,
            'qrContent'            => $qrContent,
            'checkoutUrl'          => $validatedCheckoutUrl,
            'status'               => $intent->getStatus()->value,
            'walletSettled'        => $walletSettled,
            'isSuspended'          => $isSuspended,
        ]);

        return Response::make($html, 200);
    }

    /**
     * Minimal authenticated customer-facing status endpoint (/account/payments/{intent_id}/status).
     */
    public function paymentStatus(Request $request, string $intentId): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth instanceof Response) {
            if ($auth->getStatusCode() === 403) {
                return Response::json(['error' => 'Account access restricted.'], 403);
            }
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        $user = $auth;
        $userId = (int)$user->id;

        if (method_exists($user, 'isBanned') && $user->isBanned()) {
            return Response::json(['error' => 'Account access restricted.'], 403);
        }

        $trimmedId = trim($intentId);
        $intent = $this->paymentService->getIntent($trimmedId);
        if (!$intent) {
            return Response::json(['error' => 'Payment intent not found.'], 404);
        }

        // Strict Anti-IDOR: verify payment ownership
        if ((int)$intent->getUserId() !== $userId) {
            return Response::json(['error' => 'Access denied.'], 403);
        }

        // Check if gateway is Binance and still pending, attempt safe status query synchronization
        if ($intent->getStatus() === PaymentStatus::PENDING) {
            $metadata = $intent->getMetadata();
            $gwId = $metadata['gateway_id'] ?? 'binance_pay';
            if (($gwId === 'binance_pay' || $gwId === 'binance') && $this->gatewayRegistry->has($gwId)) {
                $gateway = $this->gatewayRegistry->get($gwId);
                if (
                    $gateway instanceof \FavoriteCMS\Pay\Contracts\StatusQueryableGatewayInterface
                    && method_exists($gateway, 'isConfigured')
                    && $gateway->isConfigured()
                ) {
                    $attempts = method_exists($this->paymentService, 'getAttemptsForTransaction')
                        ? $this->paymentService->getAttemptsForTransaction($trimmedId)
                        : [];
                    $binanceAttempt = !empty($attempts) ? end($attempts) : null;
                    if ($binanceAttempt instanceof \FavoriteCMS\Pay\Domain\PaymentAttempt) {
                        try {
                            $queriedStatus = $gateway->queryStatus($binanceAttempt);
                            if ($queriedStatus === PaymentStatus::SUCCEEDED) {
                                $this->paymentService->updateIntentStatus($trimmedId, PaymentStatus::SUCCEEDED);
                                $intent = $this->paymentService->getIntent($trimmedId) ?? $intent;
                            } elseif ($queriedStatus === PaymentStatus::FAILED || $queriedStatus === PaymentStatus::CANCELLED) {
                                $this->paymentService->updateIntentStatus($trimmedId, $queriedStatus);
                                $intent = $this->paymentService->getIntent($trimmedId) ?? $intent;
                            }
                        } catch (\Throwable) {
                            // Query failure is caught cleanly without failing the endpoint
                        }
                    }
                }
            }
        }

        // Check wallet settlement state
        $walletSettled = false;
        if ($intent->getStatus() === PaymentStatus::SUCCEEDED) {
            if ($this->db !== null && $this->db->tableExists('favorite_pay_wallet_entries')) {
                $settleRow = $this->db->selectOne(
                    "SELECT 1 FROM favorite_pay_wallet_entries WHERE reference_type = 'payment' AND reference_id = ? LIMIT 1",
                    [$trimmedId]
                );
                $walletSettled = ($settleRow !== null);
            } else {
                $walletSettled = true;
            }
        }

        $baseAmount = $intent->getBaseAmount();
        $chargeAmount = $intent->getChargeAmount();
        $status = $intent->getStatus();

        $statusLabel = match ($status) {
            PaymentStatus::SUCCEEDED => 'Payment Successful',
            PaymentStatus::FAILED => 'Payment Failed',
            PaymentStatus::CANCELLED => 'Payment Cancelled',
            PaymentStatus::REFUNDED => 'Payment Refunded',
            PaymentStatus::PARTIALLY_REFUNDED => 'Partially Refunded',
            PaymentStatus::AWAITING_VERIFICATION => 'Awaiting Verification',
            default => 'Waiting for payment...',
        };

        return Response::json([
            'success'          => true,
            'payment_id'       => $intent->getId(),
            'status'           => $status->value,
            'status_label'     => $statusLabel,
            'is_final'         => $status->isFinal(),
            'is_success'       => ($status === PaymentStatus::SUCCEEDED),
            'amount'           => DecimalFormatter::minorUnitToDecimal($baseAmount->getAmount(), 2),
            'currency'         => $baseAmount->getCurrency(),
            'binance_amount'   => DecimalFormatter::minorUnitToDecimal($chargeAmount->getAmount(), 2),
            'binance_currency' => $chargeAmount->getCurrency(),
            'wallet_settled'   => $walletSettled,
        ]);
    }

    protected function validateCsrf(Request $request): bool
    {
        $submittedToken = (string)$request->post('_token', '');
        $sessionToken = (string)($_SESSION['_token'] ?? '');
        $submittedToken = (string)($request->post('_token') ?: $request->post('_csrf_token', ''));
        $sessionToken = (string)($_SESSION['_token'] ?? $_SESSION['_csrf_token'] ?? '');

        if ($submittedToken === '' || $sessionToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $submittedToken);
    }

    protected function getCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        if (empty($_SESSION['_token'])) {
            $_SESSION['_token'] = bin2hex(random_bytes(16));
        }
        return (string)$_SESSION['_token'];
    }

    protected function appUrl(string $path = ''): string
    {
        $base = 'http://localhost';
        if (class_exists(\FavoriteCMS\Models\Setting::class)) {
            try {
                $siteUrl = \FavoriteCMS\Models\Setting::get('general', 'site_url');
                if (!empty($siteUrl)) {
                    $base = rtrim((string)$siteUrl, '/');
                }
            } catch (\Throwable) {
                $base = 'http://localhost';
            }
        }
        return $base . '/' . ltrim($path, '/');
    }
}
