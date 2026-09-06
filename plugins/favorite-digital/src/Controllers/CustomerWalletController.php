<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Exceptions\WalletException;
use FavoriteCMS\Digital\Repositories\WalletRepository;
use FavoriteCMS\Digital\Services\WalletRechargeService;
use FavoriteCMS\Digital\Services\WalletService;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Exceptions\UnauthoritativeRateException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class CustomerWalletController
{
    protected Application $app;
    protected WalletService $walletService;
    protected WalletRechargeService $rechargeService;
    protected WalletRepository $walletRepo;
    protected ?PaymentServiceInterface $paymentService;

    public function __construct(
        Application $app,
        WalletService $walletService,
        WalletRechargeService $rechargeService,
        ?PaymentServiceInterface $paymentService = null
    ) {
        $this->app = $app;
        $this->walletService = $walletService;
        $this->rechargeService = $rechargeService;
        $this->walletRepo = $walletService->getWalletRepository();
        $this->paymentService = $paymentService ?? $rechargeService->getPaymentService();
    }

    public function getWalletService(): WalletService
    {
        return $this->walletService;
    }

    public function getRechargeService(): WalletRechargeService
    {
        return $this->rechargeService;
    }

    /**
     * Customer Digital Wallet Hub (/account/wallet).
     */
    public function index(Request $request): Response|string
    {
        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            return Response::redirect('/login?redirect=' . urlencode('/account/wallet'));
        }

        $wallet = $this->walletRepo->getOrCreateWallet($userId);
        $balance = $this->walletService->getBalance($userId);
        $currency = (string)($wallet->currency ?? 'BDT');

        // Limits preview
        $regularLimits = $this->rechargeService->getRechargeLimits('');
        $binanceLimits = null;
        try {
            $binanceLimits = $this->rechargeService->getRechargeLimits('binance_pay');
        } catch (Throwable) {
            // Foreign rate unavailable or expired; remains null
        }

        // Fetch available gateways from Favorite Pay
        $availableGateways = [];
        if ($this->paymentService !== null) {
            try {
                $availableGateways = $this->paymentService->getAvailablePaymentMethods($currency);
            } catch (Throwable) {
            }
        }

        // Paginated ledger
        $page = max(1, (int)$request->get('page', 1));
        $txData = $this->walletRepo->getTransactionsPaginated((int)$wallet->id, $page, 15);

        // Paginated customer recharge history
        $rechargeData = $this->rechargeService->getRechargeHistory($userId, 1, 10);

        $flashError = $_SESSION['flash_error'] ?? null;
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);

        // Generate CSRF token if session exists
        if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['_token'])) {
            $_SESSION['_token'] = bin2hex(random_bytes(16));
        }
        $csrfToken = $_SESSION['_token'] ?? '';

        return $this->renderView('wallet/index', [
            'wallet'            => $wallet,
            'balance'           => $balance,
            'currency'          => $currency,
            'regularLimits'     => $regularLimits,
            'binanceLimits'     => $binanceLimits,
            'availableGateways' => $availableGateways,
            'transactions'      => $txData['data'],
            'totalTransactions' => $txData['total'],
            'page'              => $txData['page'],
            'perPage'           => $txData['per_page'],
            'totalPages'        => $txData['total_pages'],
            'recharges'         => $rechargeData['data'],
            'csrfToken'         => $csrfToken,
            'userId'            => $userId,
            'activeTab'         => 'wallet',
            'flashError'        => $flashError,
            'flashSuccess'      => $flashSuccess,
        ]);
    }

    /**
     * Start wallet recharge (POST /account/wallet/recharge).
     */
    public function recharge(Request $request): Response
    {
        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            return Response::redirect('/login?redirect=' . urlencode('/account/wallet'));
        }

        if (!$this->validateCsrf($request)) {
            $_SESSION['flash_error'] = 'Invalid or expired CSRF token. Please retry.';
            return Response::redirect('/account/wallet');
        }

        $amount = (string)$request->post('amount', '');
        $gatewayId = trim((string)$request->post('gateway_id', ''));

        if ($gatewayId === '') {
            $_SESSION['flash_error'] = 'Please select a payment method.';
            return Response::redirect('/account/wallet');
        }

        try {
            $result = $this->rechargeService->createRecharge($userId, $amount, $gatewayId, $request->post());

            if (!empty($result['is_manual'])) {
                return Response::redirect('/account/wallet/recharge/manual?intent_id=' . urlencode($result['intent']->getId()));
            }

            $attempt = $result['attempt'] ?? null;
            if ($attempt !== null && method_exists($attempt, 'getRedirectUrl') && $attempt->getRedirectUrl()) {
                return Response::redirect($attempt->getRedirectUrl());
            }

            return Response::redirect('/account/wallet/recharge/callback?intent_id=' . urlencode($result['intent']->getId()));
        } catch (WalletException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return Response::redirect('/account/wallet');
        } catch (UnauthoritativeRateException $e) {
            $_SESSION['flash_error'] = 'Exchange rate unavailable: ' . $e->getMessage();
            return Response::redirect('/account/wallet');
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return Response::redirect('/account/wallet');
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Recharge initiation failed: ' . $e->getMessage();
            return Response::redirect('/account/wallet');
        }
    }

    /**
     * Manual recharge submission form (/account/wallet/recharge/manual).
     */
    public function showManual(Request $request): Response|string
    {
        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            return Response::redirect('/login?redirect=' . urlencode('/account/wallet'));
        }

        $intentId = trim((string)$request->get('intent_id', ''));
        if ($intentId === '' || $this->paymentService === null) {
            return Response::redirect('/account/wallet');
        }

        $intent = $this->paymentService->getIntent($intentId);
        if (!$intent || (int)$intent->getCustomerId() !== $userId) {
            return Response::make("<h1>Unauthorized</h1><p>You cannot access this payment intent.</p>", 403);
        }

        $gatewayId = $intent->getGatewayId() ?? ($intent->getMetadata()['gateway_id'] ?? '');
        $instructions = [];
        $available = $this->paymentService->getAvailablePaymentMethods($intent->getBaseAmount()->getCurrency());
        foreach ($available as $method) {
            if ($method['id'] === $gatewayId) {
                $instructions = $method['instructions'] ?? [];
                break;
            }
        }

        $amount = number_format($intent->getBaseAmount()->getAmount() / 100, 2, '.', '');
        $currency = $intent->getBaseAmount()->getCurrency();

        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        $csrfToken = $_SESSION['_token'] ?? '';

        return $this->renderView('wallet/manual', [
            'intent'       => $intent,
            'intentId'     => $intentId,
            'gatewayId'    => $gatewayId,
            'amount'       => $amount,
            'currency'     => $currency,
            'instructions' => $instructions,
            'csrfToken'    => $csrfToken,
            'flashError'   => $flashError,
            'activeTab'    => 'wallet',
        ]);
    }

    /**
     * Submit manual recharge TrxID (POST /account/wallet/recharge/manual).
     */
    public function submitManual(Request $request): Response
    {
        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            return Response::redirect('/login?redirect=' . urlencode('/account/wallet'));
        }

        if (!$this->validateCsrf($request)) {
            $_SESSION['flash_error'] = 'Invalid or expired CSRF token.';
            return Response::redirect('/account/wallet');
        }

        $intentId = trim((string)$request->post('intent_id', ''));
        $gatewayId = trim((string)$request->post('gateway_id', ''));
        $trxId = trim((string)$request->post('trx_id', ''));
        $senderAccount = trim((string)$request->post('sender_account', ''));
        $notes = trim((string)$request->post('notes', ''));

        if ($trxId === '') {
            $_SESSION['flash_error'] = 'Transaction reference (TrxID) is required.';
            return Response::redirect('/account/wallet/recharge/manual?intent_id=' . urlencode($intentId));
        }

        try {
            $this->rechargeService->submitManualRecharge(
                $userId,
                $intentId,
                $gatewayId,
                $trxId,
                [
                    'sender_account'  => $senderAccount,
                    'notes'           => $notes,
                    'idempotency_key' => $request->post('idempotency_key'),
                ]
            );

            $_SESSION['flash_success'] = 'Transaction reference submitted successfully. Your wallet will be credited once verified by an administrator.';
            return Response::redirect('/account/wallet');
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Submission error: ' . $e->getMessage();
            return Response::redirect('/account/wallet/recharge/manual?intent_id=' . urlencode($intentId));
        }
    }

    /**
     * Automatic gateway callback return (GET /account/wallet/recharge/callback).
     * Server-side authoritative verification — never trust query status alone.
     */
    public function callback(Request $request): Response
    {
        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            return Response::redirect('/login?redirect=' . urlencode('/account/wallet'));
        }

        $intentId = trim((string)$request->get('intent_id', ''));
        if ($intentId === '' || $this->paymentService === null) {
            return Response::redirect('/account/wallet');
        }

        $intent = $this->paymentService->getIntent($intentId);
        if (!$intent || (int)$intent->getCustomerId() !== $userId) {
            return Response::redirect('/account/wallet');
        }

        // Server-side authoritative verification
        $status = $intent->getStatus();

        if ($status === PaymentStatus::SUCCEEDED) {
            $this->rechargeService->settleRecharge($intentId);
            $_SESSION['flash_success'] = 'Wallet recharge verified and credited successfully!';
            return Response::redirect('/account/wallet');
        }

        if ($status === PaymentStatus::AWAITING_VERIFICATION || $status === PaymentStatus::PENDING) {
            $_SESSION['flash_success'] = 'Payment received and awaiting confirmation.';
            return Response::redirect('/account/wallet');
        }

        if ($status === PaymentStatus::FAILED) {
            $_SESSION['flash_error'] = 'Payment failed or was declined. No funds were credited.';
            return Response::redirect('/account/wallet');
        }

        if ($status === PaymentStatus::EXPIRED) {
            $_SESSION['flash_error'] = 'Payment session expired. Please start a new recharge.';
            return Response::redirect('/account/wallet');
        }

        return Response::redirect('/account/wallet');
    }

    /**
     * Retry a failed or expired recharge (POST /account/wallet/recharge/retry).
     */
    public function retry(Request $request): Response
    {
        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            return Response::redirect('/login?redirect=' . urlencode('/account/wallet'));
        }

        if (!$this->validateCsrf($request)) {
            $_SESSION['flash_error'] = 'Invalid CSRF security token.';
            return Response::redirect('/account/wallet');
        }

        $intentId = trim((string)$request->post('intent_id', ''));
        $gatewayId = trim((string)$request->post('gateway_id', ''));

        try {
            $result = $this->rechargeService->retryRecharge($userId, $intentId, $gatewayId);

            if (!empty($result['is_manual'])) {
                return Response::redirect('/account/wallet/recharge/manual?intent_id=' . urlencode($result['intent']->getId()));
            }

            $attempt = $result['attempt'] ?? null;
            if ($attempt !== null && method_exists($attempt, 'getRedirectUrl') && $attempt->getRedirectUrl()) {
                return Response::redirect($attempt->getRedirectUrl());
            }

            return Response::redirect('/account/wallet/recharge/callback?intent_id=' . urlencode($result['intent']->getId()));
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Retry failed: ' . $e->getMessage();
            return Response::redirect('/account/wallet');
        }
    }

    protected function validateCsrf(Request $request): bool
    {
        $submittedToken = (string)$request->post('_token', '');
        $sessionToken = (string)($_SESSION['_token'] ?? '');

        if ($submittedToken === '' || $sessionToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $submittedToken);
    }

    protected function resolveCurrentUserId(): int
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
        if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
            return (int)$_SESSION['user_id'];
        }
        return (int)($_SESSION['auth_user_id'] ?? 0);
    }

    protected function renderView(string $viewName, array $data = []): string
    {
        $viewPath = __DIR__ . '/../../views/customer/' . $viewName . '.php';
        if (!file_exists($viewPath)) {
            return "<div class='notice notice-error'>View not found: " . htmlspecialchars($viewName, ENT_QUOTES, 'UTF-8') . "</div>";
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $viewPath;
        return (string)ob_get_clean();
    }
}

