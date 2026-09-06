<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Exceptions\CheckoutException;
use FavoriteCMS\Digital\Exceptions\WalletException;
use FavoriteCMS\Digital\Services\CheckoutService;
use FavoriteCMS\Digital\Support\OrderLifecycleState;
use Throwable;

class CustomerCheckoutController
{
    protected Application $app;
    protected CheckoutService $checkoutService;

    protected ?\FavoriteCMS\Digital\Services\DigitalFileStorageService $fileStorageService = null;

    public function __construct(
        Application $app,
        CheckoutService $checkoutService,
        ?\FavoriteCMS\Digital\Services\DigitalFileStorageService $fileStorageService = null
    ) {
        $this->app = $app;
        $this->checkoutService = $checkoutService;
        $this->fileStorageService = $fileStorageService;
    }

    public function getCheckoutService(): CheckoutService
    {
        return $this->checkoutService;
    }

    public function getFileStorageService(): \FavoriteCMS\Digital\Services\DigitalFileStorageService
    {
        if ($this->fileStorageService === null) {
            if ($this->app->has(\FavoriteCMS\Digital\Services\DigitalFileStorageService::class)) {
                $this->fileStorageService = $this->app->make(\FavoriteCMS\Digital\Services\DigitalFileStorageService::class);
            } else {
                $this->fileStorageService = new \FavoriteCMS\Digital\Services\DigitalFileStorageService();
            }
        }
        return $this->fileStorageService;
    }

    public function handle(Request $request, string $orderNumber): Response|string
    {
        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            return Response::redirect('/login');
        }

        if ($request->method() === 'POST') {
            return $this->handlePost($request, $orderNumber, $userId);
        }

        return $this->handleGet($request, $orderNumber, $userId);
    }

    protected function handleGet(Request $request, string $orderNumber, int $userId): Response|string
    {
        $action = (string)$request->get('action', 'index');

        if ($action === 'callback') {
            return $this->handleCallback($request, $orderNumber, $userId);
        }

        return $this->showCheckout($request, $orderNumber, $userId);
    }

    protected function handlePost(Request $request, string $orderNumber, int $userId): Response
    {
        // CSRF validation
        $submittedToken = (string)$request->post('_token', '');
        $sessionToken   = (string)($_SESSION['_token'] ?? '');

        if ($submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
            $_SESSION['flash_error'] = 'Invalid or expired CSRF security token. Please retry.';
            return Response::redirect("/checkout/{$orderNumber}");
        }

        $action = (string)$request->post('action', 'pay');

        if ($action === 'manual') {
            return $this->handleManualSubmit($request, $orderNumber, $userId);
        }

        return $this->processPayment($request, $orderNumber, $userId);
    }

    public function showCheckout(Request $request, string $orderNumber, int $userId): Response|string
    {
        try {
            $order = $this->checkoutService->getOrderForCheckout($orderNumber, $userId);
        } catch (CheckoutException $e) {
            return Response::make("<h1>Checkout Error</h1><p>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>", 403);
        } catch (Throwable $e) {
            return Response::make("<h1>Checkout Error</h1><p>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>", 400);
        }

        $walletBalance = $this->checkoutService->getWalletService()->getBalance($userId);
        $remainingPayable = $this->checkoutService->calculateRemainingPayable($order);

        // Fetch available payment gateways from Favorite Pay
        $availableGateways = [];
        $favPayService = $this->checkoutService->getFavoritePayService();
        if ($favPayService !== null) {
            try {
                $availableGateways = $favPayService->getAvailablePaymentMethods($order->currency ?? 'BDT');
            } catch (Throwable) {
            }
        }

        return $this->renderView('checkout/index', [
            'order'             => $order,
            'walletBalance'     => $walletBalance,
            'remainingPayable'  => $remainingPayable,
            'availableGateways' => $availableGateways,
            'userId'            => $userId,
            'flashError'        => $_SESSION['flash_error'] ?? null,
            'flashSuccess'      => $_SESSION['flash_success'] ?? null,
        ]);
    }

    public function processPayment(Request $request, string $orderNumber, int $userId): Response
    {
        try {
            $order = $this->checkoutService->getOrderForCheckout($orderNumber, $userId);
            $paymentMethod = (string)$request->post('payment_method', 'wallet');
            $remaining = $this->checkoutService->calculateRemainingPayable($order);

            // 1. Zero-value order handling
            if ((float)$order->total_amount <= 0.00 || (float)$remaining <= 0.00) {
                $this->checkoutService->processZeroValueOrder((int)$order->id, $userId);
                $_SESSION['flash_success'] = 'Order completed successfully!';
                return Response::redirect("/account/orders/{$orderNumber}");
            }

            // 2. Wallet-only payment
            if ($paymentMethod === 'wallet') {
                $this->checkoutService->processWalletPayment((int)$order->id, $userId);
                $_SESSION['flash_success'] = 'Order paid successfully with your wallet balance!';
                return Response::redirect("/account/orders/{$orderNumber}");
            }

            // 3. Favorite Pay-only payment
            if ($paymentMethod === 'favorite_pay') {
                $gatewayId = (string)$request->post('gateway_id', '');
                if ($gatewayId === '') {
                    throw CheckoutException::gatewayError('Please select a valid payment gateway.');
                }

                $isManual = $this->isManualGateway($gatewayId);
                $params = [];
                if ($isManual) {
                    $params = $this->resolveManualPaymentParams($request);
                }

                $result = $this->checkoutService->processFavoritePayPayment((int)$order->id, $userId, $gatewayId, $params);
                $attempt = $result['attempt'];

                if ($isManual || (method_exists($attempt, 'getStatus') && $attempt->getStatus()->value === 'awaiting_verification')) {
                    $trx = htmlspecialchars($params['trx_id'] ?? '', ENT_QUOTES, 'UTF-8');
                    $_SESSION['flash_success'] = "Payment reference ({$trx}) submitted successfully. Your order is awaiting administrator verification.";
                    return Response::redirect("/account/orders/{$orderNumber}");
                }

                // If gateway returns redirect URL, forward customer to gateway
                if (method_exists($attempt, 'getRedirectUrl') && $attempt->getRedirectUrl()) {
                    return Response::redirect($attempt->getRedirectUrl());
                }

                $_SESSION['flash_success'] = 'Payment initiated. Please complete the transaction.';
                return Response::redirect("/checkout/{$orderNumber}?action=callback&intent_id=" . urlencode($result['intent']->getId()));
            }

            // 4. Mixed payment: Wallet + Favorite Pay
            if ($paymentMethod === 'mixed') {
                $walletAmount = (string)$request->post('wallet_amount', '0.00');
                $gatewayId = (string)$request->post('gateway_id', '');
                if ($gatewayId === '') {
                    throw CheckoutException::gatewayError('Please select a valid payment gateway for the remaining balance.');
                }

                $isManual = $this->isManualGateway($gatewayId);
                $params = [];
                if ($isManual) {
                    $params = $this->resolveManualPaymentParams($request);
                }

                $result = $this->checkoutService->processMixedPayment(
                    (int)$order->id,
                    $userId,
                    $walletAmount,
                    $gatewayId,
                    $params
                );

                $attempt = $result['attempt'];

                if ($isManual || (method_exists($attempt, 'getStatus') && $attempt->getStatus()->value === 'awaiting_verification')) {
                    $trx = htmlspecialchars($params['trx_id'] ?? '', ENT_QUOTES, 'UTF-8');
                    $_SESSION['flash_success'] = "Wallet amount deducted. Manual payment reference ({$trx}) submitted. Remaining balance is awaiting administrator verification.";
                    return Response::redirect("/account/orders/{$orderNumber}");
                }

                if (method_exists($attempt, 'getRedirectUrl') && $attempt->getRedirectUrl()) {
                    return Response::redirect($attempt->getRedirectUrl());
                }

                $_SESSION['flash_success'] = 'Wallet amount reserved. Please complete the remaining payment.';
                return Response::redirect("/checkout/{$orderNumber}?action=callback&intent_id=" . urlencode($result['intent']->getId()));
            }

            throw CheckoutException::invalidPaymentMethod($paymentMethod);
        } catch (WalletException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return Response::redirect("/checkout/{$orderNumber}");
        } catch (CheckoutException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return Response::redirect("/checkout/{$orderNumber}");
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Payment processing error: ' . $e->getMessage();
            return Response::redirect("/checkout/{$orderNumber}");
        }
    }

    public function handleCallback(Request $request, string $orderNumber, int $userId): Response|string
    {
        $intentId = (string)$request->get('intent_id', '');
        if ($intentId === '') {
            return Response::redirect("/checkout/{$orderNumber}");
        }

        try {
            $order = $this->checkoutService->getOrderForCheckout($orderNumber, $userId);

            // Server-side authoritative verification (NEVER trust query status alone)
            $settledOrder = $this->checkoutService->verifyAndSettlePayment((int)$order->id, $intentId);

            if ($settledOrder->payment_status === OrderLifecycleState::PAYMENT_PAID) {
                $_SESSION['flash_success'] = 'Payment verified and order completed successfully!';
                return Response::redirect("/account/orders/{$orderNumber}");
            }

            if ($settledOrder->payment_status === OrderLifecycleState::PAYMENT_PARTIALLY_PAID) {
                $_SESSION['flash_success'] = 'Partial payment received. Remaining balance is payable.';
                return Response::redirect("/checkout/{$orderNumber}");
            }

            if ($settledOrder->payment_status === OrderLifecycleState::PAYMENT_PENDING) {
                $_SESSION['flash_success'] = 'Payment is awaiting manual verification/confirmation.';
                return Response::redirect("/account/orders/{$orderNumber}");
            }

            $_SESSION['flash_error'] = 'Payment verification did not confirm a successful payment.';
            return Response::redirect("/checkout/{$orderNumber}");
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Verification error: ' . $e->getMessage();
            return Response::redirect("/checkout/{$orderNumber}");
        }
    }

    public function handleManualSubmit(Request $request, string $orderNumber, int $userId): Response
    {
        $intentId = (string)$request->post('intent_id', '');
        $gatewayId = (string)$request->post('gateway_id', '');
        if ($gatewayId === '') {
            $gatewayId = (string)$request->post('gateway', '');
        }

        try {
            $details = $this->resolveManualPaymentParams($request);
            $trxId = $details['trx_id'];

            $order = $this->checkoutService->getOrderForCheckout($orderNumber, $userId);

            if ($intentId === '') {
                $payments = $this->checkoutService->getOrderRepository()->getOrderPayments((int)$order->id);
                foreach ($payments as $p) {
                    if (!empty($p->favorite_pay_tx_id)) {
                        $intentId = (string)$p->favorite_pay_tx_id;
                        break;
                    }
                }

                if ($intentId === '') {
                    $initResult = $this->checkoutService->processFavoritePayPayment((int)$order->id, $userId, $gatewayId, $details);
                    $intentId = $initResult['intent']->getId();
                }
            }

            $this->checkoutService->submitManualPayment(
                (int)$order->id,
                $userId,
                $intentId,
                $gatewayId,
                $trxId,
                $details
            );

            $_SESSION['flash_success'] = 'Manual transaction reference submitted. Awaiting operator review.';
            return Response::redirect("/account/orders/{$orderNumber}");
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Manual submission error: ' . $e->getMessage();
            return Response::redirect("/checkout/{$orderNumber}");
        }
    }

    protected function isManualGateway(string $gatewayId): bool
    {
        if (str_starts_with($gatewayId, 'manual_')) {
            return true;
        }

        $favPayService = $this->checkoutService->getFavoritePayService();
        if ($favPayService !== null) {
            try {
                if ($favPayService->getGatewayRegistry()->has($gatewayId)) {
                    $gw = $favPayService->getGatewayRegistry()->get($gatewayId);
                    return $gw->getType()->requiresManualVerification();
                }
            } catch (Throwable) {
            }
        }

        return false;
    }

    protected function resolveManualPaymentParams(Request $request): array
    {
        $trxId = trim((string)$request->post('trx_id', ''));
        if ($trxId === '') {
            throw CheckoutException::gatewayError('Transaction ID (TrxID) is required for manual payment.');
        }

        $senderAccount = trim((string)$request->post('sender_account', ''));
        $notes = trim((string)$request->post('notes', ''));

        $params = [
            'transaction_reference' => $trxId,
            'trx_id'                => $trxId,
            'sender_account'        => $senderAccount !== '' ? $senderAccount : null,
            'notes'                 => $notes !== '' ? $notes : null,
            'idempotency_key'       => $request->post('idempotency_key'),
        ];

        if (isset($_FILES['payment_proof']) && !empty($_FILES['payment_proof']['tmp_name'])) {
            $storage = $this->getFileStorageService();
            $storedProof = $storage->storeProofUpload($_FILES['payment_proof']);
            $params['proof_path'] = $storedProof['file_path'];
            $params['proof_name'] = $storedProof['file_name'];
            $params['proof_hash'] = $storedProof['file_hash'];
            $params['proof_size'] = $storedProof['file_size'];
        }

        return $params;
    }

    protected function resolveCurrentUserId(): int
    {
        if (isset($GLOBALS['_test_current_user']) && isset($GLOBALS['_test_current_user']->id)) {
            return (int)$GLOBALS['_test_current_user']->id;
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

