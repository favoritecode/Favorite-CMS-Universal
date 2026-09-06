<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\Exceptions\CheckoutException;
use FavoriteCMS\Digital\Repositories\OrderRepository;
use FavoriteCMS\Digital\Support\OrderLifecycleState;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use Throwable;

class CheckoutService
{
    protected OrderRepository $orderRepo;
    protected WalletService $walletService;
    protected ?PaymentServiceInterface $favoritePayService;
    protected ?Database $db;

    protected ?FulfillmentService $fulfillmentService;

    public function __construct(
        OrderRepository $orderRepo,
        WalletService $walletService,
        ?PaymentServiceInterface $favoritePayService = null,
        ?Database $db = null,
        ?FulfillmentService $fulfillmentService = null
    ) {
        $this->orderRepo = $orderRepo;
        $this->walletService = $walletService;
        $this->favoritePayService = $favoritePayService;
        $this->db = $db ?? $orderRepo->getDatabase();
        $this->fulfillmentService = $fulfillmentService;
    }

    public function getFulfillmentService(): ?FulfillmentService
    {
        return $this->fulfillmentService;
    }

    public function setFulfillmentService(?FulfillmentService $service): void
    {
        $this->fulfillmentService = $service;
    }

    protected function triggerFulfillmentIfPaid(int $orderId): void
    {
        if ($this->fulfillmentService !== null) {
            try {
                $this->fulfillmentService->fulfillOrder($orderId);
            } catch (Throwable) {
                // Legitimate payment is preserved; fulfillment can be retried
            }
        }
    }

    public function getOrderRepository(): OrderRepository
    {
        return $this->orderRepo;
    }

    public function getWalletService(): WalletService
    {
        return $this->walletService;
    }

    public function getFavoritePayService(): ?PaymentServiceInterface
    {
        return $this->favoritePayService;
    }

    public function setFavoritePayService(?PaymentServiceInterface $service): void
    {
        $this->favoritePayService = $service;
    }

    /**
     * Load order and assert that it can be checked out by the given customer.
     */
    public function getOrderForCheckout(string|int $orderIdentifier, int $userId): object
    {
        $order = is_numeric($orderIdentifier)
            ? $this->orderRepo->findOrderWithItems((int)$orderIdentifier)
            : $this->orderRepo->findOrderWithItemsByOrderNumber((string)$orderIdentifier);

        if (!$order) {
            throw CheckoutException::orderNotPayable((string)$orderIdentifier, "Order does not exist");
        }

        $this->assertCustomerOwnership($order, $userId);
        $this->assertOrderPayable($order);

        return $order;
    }

    public function assertCustomerOwnership(object $order, int $userId): void
    {
        if ((int)$order->user_id !== $userId) {
            throw CheckoutException::unauthorizedOrderAccess($order->order_number, $userId);
        }
    }

    public function assertOrderPayable(object $order): void
    {
        if ($order->payment_status === OrderLifecycleState::PAYMENT_PAID || $order->status === OrderLifecycleState::STATUS_COMPLETED) {
            throw CheckoutException::orderNotPayable($order->order_number, "Order is already paid");
        }

        if ($order->status === OrderLifecycleState::STATUS_CANCELLED || $order->fulfillment_status === OrderLifecycleState::FULFILLMENT_CANCELLED) {
            throw CheckoutException::orderNotPayable($order->order_number, "Order has been cancelled");
        }

        if ($order->payment_status === OrderLifecycleState::PAYMENT_REFUNDED || $order->status === OrderLifecycleState::STATUS_REFUNDED) {
            throw CheckoutException::orderNotPayable($order->order_number, "Order has been refunded");
        }
    }

    /**
     * Calculate remaining payable balance in BDT as a 2-decimal string.
     */
    public function calculateRemainingPayable(object $order): string
    {
        $totalMinor = $this->parseAmountMinor($order->total_amount);
        $payments = $this->orderRepo->getOrderPayments((int)$order->id);

        $settledMinor = 0;
        foreach ($payments as $payment) {
            if ($payment->status === 'completed' || $payment->status === 'paid') {
                $settledMinor += $this->parseAmountMinor($payment->amount_paid);
            }
        }

        $remainingMinor = max(0, $totalMinor - $settledMinor);
        return $this->minorToDecimal($remainingMinor);
    }

    /**
     * Settle a zero-value (free / 100% discount) order without wallet or gateway charges.
     */
    public function processZeroValueOrder(int $orderId, int $userId): object
    {
        $order = $this->orderRepo->findOrderWithItems($orderId);
        if (!$order) {
            throw CheckoutException::orderNotPayable((string)$orderId, "Order does not exist");
        }

        $this->assertCustomerOwnership($order, $userId);
        $this->assertOrderPayable($order);

        $totalMinor = $this->parseAmountMinor($order->total_amount);
        if ($totalMinor > 0) {
            throw CheckoutException::orderNotPayable($order->order_number, "Order total is not zero");
        }

        $res = $this->executeInTransaction(function () use ($order, $orderId) {
            $this->orderRepo->createOrderPayment([
                'order_id'           => $orderId,
                'payment_method'     => 'free',
                'favorite_pay_tx_id' => null,
                'wallet_tx_id'       => null,
                'amount_paid'        => '0.00',
                'currency'           => 'BDT',
                'status'             => 'completed',
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);

            $this->orderRepo->updatePaymentStatus($orderId, OrderLifecycleState::PAYMENT_PAID);
            $this->orderRepo->updateOrderStatus($orderId, OrderLifecycleState::STATUS_PROCESSING);

            return $this->orderRepo->findOrderWithItems($orderId);
        });

        $this->triggerFulfillmentIfPaid($orderId);
        return $this->orderRepo->findOrderWithItems($orderId) ?? $res;
    }

    /**
     * Process 100% wallet payment atomically.
     */
    public function processWalletPayment(int $orderId, int $userId): object
    {
        $order = $this->orderRepo->findOrderWithItems($orderId);
        if (!$order) {
            throw CheckoutException::orderNotPayable((string)$orderId, "Order does not exist");
        }

        $this->assertCustomerOwnership($order, $userId);
        $this->assertOrderPayable($order);

        $remaining = $this->calculateRemainingPayable($order);
        $remainingMinor = $this->parseAmountMinor($remaining);
        if ($remainingMinor <= 0) {
            return $order;
        }

        $res = $this->executeInTransaction(function () use ($order, $orderId, $userId, $remaining) {
            $refId = "fd_ord_{$orderId}_wal_" . bin2hex(random_bytes(6));
            $walletTx = $this->walletService->debit(
                $userId,
                $remaining,
                $refId,
                "Payment for Order #{$order->order_number}",
                $orderId
            );

            $this->orderRepo->createOrderPayment([
                'order_id'           => $orderId,
                'payment_method'     => 'wallet',
                'favorite_pay_tx_id' => null,
                'wallet_tx_id'       => (string)$walletTx->id,
                'amount_paid'        => $remaining,
                'currency'           => 'BDT',
                'status'             => 'completed',
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);

            $this->orderRepo->updatePaymentStatus($orderId, OrderLifecycleState::PAYMENT_PAID);
            $this->orderRepo->updateOrderStatus($orderId, OrderLifecycleState::STATUS_PROCESSING);

            return $this->orderRepo->findOrderWithItems($orderId);
        });

        $this->triggerFulfillmentIfPaid($orderId);
        return $this->orderRepo->findOrderWithItems($orderId) ?? $res;
    }

    /**
     * Initiate payment attempt through Favorite Pay for the exact remaining order balance.
     */
    public function processFavoritePayPayment(
        int $orderId,
        int $userId,
        string $gatewayId,
        array $params = []
    ): array {
        $order = $this->orderRepo->findOrderWithItems($orderId);
        if (!$order) {
            throw CheckoutException::orderNotPayable((string)$orderId, "Order does not exist");
        }

        $this->assertCustomerOwnership($order, $userId);
        $this->assertOrderPayable($order);

        $remaining = $this->calculateRemainingPayable($order);
        if ($this->parseAmountMinor($remaining) <= 0) {
            throw CheckoutException::orderNotPayable($order->order_number, "No remaining balance payable");
        }

        if ($this->favoritePayService === null) {
            throw CheckoutException::gatewayError("Favorite Pay service is currently unavailable.");
        }

        $money = Money::fromMajorString($remaining, 'BDT');
        $intent = $this->favoritePayService->createIntent(
            'favorite-digital',
            (string)$orderId,
            $money,
            [
                'customer_id' => $userId,
                'gateway_id'  => $gatewayId,
                'metadata'    => [
                    'order_id'     => $orderId,
                    'order_number' => $order->order_number,
                ],
            ]
        );

        $attempt = $this->favoritePayService->initiatePayment($intent->getId(), $gatewayId, $params);

        // Check if an existing payment record exists for this intent to prevent duplicate inserts
        $existing = $this->orderRepo->findPaymentByTxId($intent->getId());
        if (!$existing) {
            $this->orderRepo->createOrderPayment([
                'order_id'           => $orderId,
                'payment_method'     => 'favorite_pay',
                'favorite_pay_tx_id' => $intent->getId(),
                'wallet_tx_id'       => null,
                'amount_paid'        => $remaining,
                'currency'           => 'BDT',
                'status'             => 'pending',
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);
        }

        $this->orderRepo->updatePaymentStatus($orderId, OrderLifecycleState::PAYMENT_PENDING);

        return [
            'intent'  => $intent,
            'attempt' => $attempt,
            'order'   => $this->orderRepo->findOrderWithItems($orderId),
        ];
    }

    /**
     * Process mixed payment: Wallet portion + Favorite Pay remaining portion.
     */
    public function processMixedPayment(
        int $orderId,
        int $userId,
        string $requestedWalletAmount,
        string $gatewayId,
        array $params = []
    ): array {
        $order = $this->orderRepo->findOrderWithItems($orderId);
        if (!$order) {
            throw CheckoutException::orderNotPayable((string)$orderId, "Order does not exist");
        }

        $this->assertCustomerOwnership($order, $userId);
        $this->assertOrderPayable($order);

        $remaining = $this->calculateRemainingPayable($order);
        $remMinor = $this->parseAmountMinor($remaining);
        $wMinor   = $this->parseAmountMinor($requestedWalletAmount);

        if ($wMinor <= 0) {
            throw CheckoutException::amountMismatch($remaining, $requestedWalletAmount);
        }

        if ($wMinor >= $remMinor) {
            throw CheckoutException::amountMismatch("Less than {$remaining}", $requestedWalletAmount);
        }

        // Check wallet balance
        $walletBal = $this->walletService->getBalance($userId);
        $balMinor  = $this->parseAmountMinor($walletBal);
        if ($balMinor < $wMinor) {
            throw \FavoriteCMS\Digital\Exceptions\WalletException::insufficientBalance(
                $walletBal,
                $this->minorToDecimal($wMinor)
            );
        }

        $gwMinor = $remMinor - $wMinor;
        $gwAmount = $this->minorToDecimal($gwMinor);
        $walletAmount = $this->minorToDecimal($wMinor);

        if ($this->favoritePayService === null) {
            throw CheckoutException::gatewayError("Favorite Pay service is currently unavailable.");
        }

        // Step 1: Deduct wallet portion
        $refId = "fd_ord_{$orderId}_mixed_wal_" . bin2hex(random_bytes(6));
        $walletTx = $this->walletService->debit(
            $userId,
            $walletAmount,
            $refId,
            "Mixed payment allocation for Order #{$order->order_number}",
            $orderId
        );

        $this->orderRepo->createOrderPayment([
            'order_id'           => $orderId,
            'payment_method'     => 'wallet',
            'favorite_pay_tx_id' => null,
            'wallet_tx_id'       => (string)$walletTx->id,
            'amount_paid'        => $walletAmount,
            'currency'           => 'BDT',
            'status'             => 'completed',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $this->orderRepo->updatePaymentStatus($orderId, OrderLifecycleState::PAYMENT_PARTIALLY_PAID);

        // Step 2: Initiate Favorite Pay intent for remaining
        try {
            $money = Money::fromMajorString($gwAmount, 'BDT');
            $intent = $this->favoritePayService->createIntent(
                'favorite-digital',
                (string)$orderId,
                $money,
                [
                    'customer_id' => $userId,
                    'gateway_id'  => $gatewayId,
                    'metadata'    => [
                        'order_id'      => $orderId,
                        'order_number'  => $order->order_number,
                        'is_mixed'      => true,
                        'wallet_amount' => $walletAmount,
                    ],
                ]
            );

            $attempt = $this->favoritePayService->initiatePayment($intent->getId(), $gatewayId, $params);

            $this->orderRepo->createOrderPayment([
                'order_id'           => $orderId,
                'payment_method'     => 'favorite_pay',
                'favorite_pay_tx_id' => $intent->getId(),
                'wallet_tx_id'       => null,
                'amount_paid'        => $gwAmount,
                'currency'           => 'BDT',
                'status'             => 'pending',
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);

            return [
                'intent'              => $intent,
                'attempt'             => $attempt,
                'wallet_amount'       => $walletAmount,
                'favorite_pay_amount' => $gwAmount,
                'order'               => $this->orderRepo->findOrderWithItems($orderId),
            ];
        } catch (Throwable $e) {
            // Compensating reversal: restore wallet balance!
            $this->walletService->reverseDebit(
                $userId,
                $walletAmount,
                $refId,
                "Reversal due to gateway initiation failure for Order #{$order->order_number}",
                $orderId
            );

            $this->orderRepo->updatePaymentStatus($orderId, OrderLifecycleState::PAYMENT_FAILED);
            throw $e;
        }
    }

    /**
     * Authoritatively verify and settle a Favorite Pay transaction server-side.
     */
    public function verifyAndSettlePayment(int $orderId, string $favoritePayTxId): object
    {
        $order = $this->orderRepo->findOrderWithItems($orderId);
        if (!$order) {
            throw CheckoutException::orderNotPayable((string)$orderId, "Order does not exist");
        }

        // Idempotency: if already paid, return cleanly
        if ($order->payment_status === OrderLifecycleState::PAYMENT_PAID) {
            return $order;
        }

        if ($this->favoritePayService === null) {
            throw CheckoutException::gatewayError("Favorite Pay service unavailable for verification.");
        }

        $intent = $this->favoritePayService->getIntent($favoritePayTxId);
        if (!$intent) {
            throw CheckoutException::gatewayError("Payment intent '{$favoritePayTxId}' not found.");
        }

        $paymentRecord = $this->orderRepo->findPaymentByTxId($favoritePayTxId);

        if ($intent->getStatus() === PaymentStatus::SUCCEEDED) {
            if ($paymentRecord && $paymentRecord->status !== 'completed') {
                $this->orderRepo->updatePayment((int)$paymentRecord->id, [
                    'status' => 'completed',
                ]);
            }

            // Recalculate total verified payments
            $remaining = $this->calculateRemainingPayable($order);
            if ($this->parseAmountMinor($remaining) === 0) {
                $this->orderRepo->updatePaymentStatus($orderId, OrderLifecycleState::PAYMENT_PAID);
                $this->orderRepo->updateOrderStatus($orderId, OrderLifecycleState::STATUS_PROCESSING);
                $this->triggerFulfillmentIfPaid($orderId);
            } else {
                $this->orderRepo->updatePaymentStatus($orderId, OrderLifecycleState::PAYMENT_PARTIALLY_PAID);
            }
        } elseif ($intent->getStatus() === PaymentStatus::FAILED) {
            if ($paymentRecord && $paymentRecord->status !== 'failed') {
                $this->orderRepo->updatePayment((int)$paymentRecord->id, [
                    'status' => 'failed',
                ]);
            }

            // Failure recovery for mixed payment: reverse wallet deduction
            $this->reconcileMixedPaymentFailure($orderId, "Gateway payment failed for intent {$favoritePayTxId}");
            $this->orderRepo->updatePaymentStatus($orderId, OrderLifecycleState::PAYMENT_FAILED);
        }

        return $this->orderRepo->findOrderWithItems($orderId);
    }

    /**
     * Submit manual payment transaction reference (TrxID) to Favorite Pay.
     */
    public function submitManualPayment(
        int $orderId,
        int $userId,
        string $intentId,
        string $gatewayId,
        string $transactionReference,
        array $details = []
    ): array {
        $order = $this->orderRepo->findOrderWithItems($orderId);
        if (!$order) {
            throw CheckoutException::orderNotPayable((string)$orderId, "Order does not exist");
        }

        $this->assertCustomerOwnership($order, $userId);
        $this->assertOrderPayable($order);

        if ($this->favoritePayService === null) {
            throw CheckoutException::gatewayError("Favorite Pay service unavailable for manual payment.");
        }

        $attempt = $this->favoritePayService->submitManualVerification(
            $intentId,
            $gatewayId,
            $transactionReference,
            $details
        );

        $this->orderRepo->updatePaymentStatus($orderId, OrderLifecycleState::PAYMENT_PENDING);

        return [
            'attempt' => $attempt,
            'order'   => $this->orderRepo->findOrderWithItems($orderId),
        ];
    }

    /**
     * Reconcile and reverse any wallet reservations if a mixed payment gateway fails.
     */
    public function reconcileMixedPaymentFailure(int $orderId, string $reason = ''): void
    {
        $payments = $this->orderRepo->getOrderPayments($orderId);
        $order = $this->orderRepo->findOrder($orderId);
        if (!$order) {
            return;
        }

        $userId = (int)$order->user_id;

        foreach ($payments as $payment) {
            if ($payment->payment_method === 'wallet' && !empty($payment->wallet_tx_id) && $payment->status === 'completed') {
                // Find original transaction
                $txs = $this->walletService->getWalletRepository()->getTransactionsByOrderId($orderId);
                foreach ($txs as $tx) {
                    if ($tx->type === 'debit') {
                        $this->walletService->reverseDebit(
                            $userId,
                            $tx->amount,
                            $tx->reference_id,
                            $reason !== '' ? $reason : "Reversal for Order #{$order->order_number}",
                            $orderId
                        );

                        $this->orderRepo->updatePayment((int)$payment->id, ['status' => 'refunded']);
                    }
                }
            }
        }
    }

    protected function executeInTransaction(callable $callback): mixed
    {
        if ($this->db === null) {
            return $callback();
        }

        $pdo = null;
        try {
            $pdo = $this->db->getConnection();
        } catch (Throwable) {
        }

        if ($pdo !== null && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            try {
                $result = $callback();
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        return $callback();
    }

    protected function parseAmountMinor(string $amount): int
    {
        $clean = trim($amount);
        if ($clean === '' || !is_numeric($clean)) {
            return 0;
        }

        return (int)round((float)$clean * 100);
    }

    protected function minorToDecimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}

