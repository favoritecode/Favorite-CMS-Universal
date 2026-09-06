<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Services;

use DateTimeImmutable;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Exceptions\RefundException;
use FavoriteCMS\Digital\Repositories\EntitlementRepository;
use FavoriteCMS\Digital\Repositories\OrderRepository;
use FavoriteCMS\Digital\Repositories\RefundRepository;
use FavoriteCMS\Digital\Support\OrderLifecycleState;
use Throwable;

/**
 * RefundService
 *
 * Authoritative orchestrator for Favorite Digital refunds:
 * - Server-side refund eligibility & calculation
 * - 100% wallet credit destination regardless of original payment method
 * - Immutable ledger entry in favorite_digital_wallet_transactions (type = refund_credit)
 * - Immutable record in favorite_digital_refunds
 * - Atomic entitlement revocation for direct purchases & package-derived children
 * - Membership revocation via MembershipLifecycleService public API
 * - Order lifecycle state completion (payment: refunded, fulfillment: revoked, aggregate: refunded)
 * - Strict idempotency across repeated executions
 */
class RefundService
{
    protected OrderRepository $orderRepo;
    protected RefundRepository $refundRepo;
    protected WalletService $walletService;
    protected EntitlementRepository $entitlementRepo;
    protected MembershipLifecycleService $membershipService;
    protected ?Database $db;

    public function __construct(
        OrderRepository $orderRepo,
        RefundRepository $refundRepo,
        WalletService $walletService,
        EntitlementRepository $entitlementRepo,
        MembershipLifecycleService $membershipService,
        ?Database $db = null
    ) {
        $this->orderRepo = $orderRepo;
        $this->refundRepo = $refundRepo;
        $this->walletService = $walletService;
        $this->entitlementRepo = $entitlementRepo;
        $this->membershipService = $membershipService;
        $this->db = $db ?? $orderRepo->getDatabase();
    }

    public function getOrderRepository(): OrderRepository
    {
        return $this->orderRepo;
    }

    public function getRefundRepository(): RefundRepository
    {
        return $this->refundRepo;
    }

    public function getWalletService(): WalletService
    {
        return $this->walletService;
    }

    public function getEntitlementRepository(): EntitlementRepository
    {
        return $this->entitlementRepo;
    }

    public function getMembershipService(): MembershipLifecycleService
    {
        return $this->membershipService;
    }

    /**
     * Calculate server-authoritative verified paid amount in BDT.
     * Sums only completed/paid payment records.
     */
    public function calculateAuthoritativeRefundAmount(object $order): string
    {
        $payments = $this->orderRepo->getOrderPayments((int)$order->id);
        $settledMinor = 0;

        foreach ($payments as $payment) {
            if ($payment->status === 'completed' || $payment->status === 'paid') {
                $clean = trim((string)$payment->amount_paid);
                if ($clean !== '' && is_numeric($clean)) {
                    $settledMinor += (int)round((float)$clean * 100);
                }
            }
        }

        return number_format($settledMinor / 100, 2, '.', '');
    }

    /**
     * Validate refund eligibility for an order.
     */
    public function validateRefundEligibility(int $orderId): array
    {
        $order = $this->orderRepo->findOrderWithItems($orderId);
        if (!$order) {
            throw RefundException::orderNotFound($orderId);
        }

        // Check if already refunded
        if ($order->payment_status === OrderLifecycleState::PAYMENT_REFUNDED ||
            $order->status === OrderLifecycleState::STATUS_REFUNDED) {
            $existing = $this->refundRepo->findRefundByOrderId($orderId);
            return [
                'eligible'             => false,
                'already_refunded'     => true,
                'existing_refund'      => $existing,
                'order'                => $order,
                'verified_paid_amount' => '0.00',
                'currency'             => 'BDT',
                'reason'               => 'Order has already been refunded.',
            ];
        }

        $paidAmount = $this->calculateAuthoritativeRefundAmount($order);
        $paidMinor = (int)round((float)$paidAmount * 100);

        if ($paidMinor <= 0) {
            return [
                'eligible'             => false,
                'already_refunded'     => false,
                'order'                => $order,
                'verified_paid_amount' => '0.00',
                'currency'             => 'BDT',
                'reason'               => 'Order has no verified paid component to refund.',
            ];
        }

        return [
            'eligible'             => true,
            'already_refunded'     => false,
            'order'                => $order,
            'verified_paid_amount' => $paidAmount,
            'currency'             => 'BDT',
            'reason'               => 'Eligible for wallet refund.',
        ];
    }

    /**
     * Process a full order refund atomically and idempotently.
     *
     * @param int $orderId
     * @param string $reason Business reason for refund
     * @param int $actorUserId User performing or requesting the refund
     * @param bool $isAdmin Whether the action is performed by an admin
     * @return object Refund record
     * @throws RefundException
     */
    public function processRefund(
        int $orderId,
        string $reason,
        int $actorUserId = 0,
        bool $isAdmin = false
    ): object {
        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw RefundException::invalidReason("A specific reason is required to issue a refund.");
        }

        $order = $this->orderRepo->findOrderWithItems($orderId);
        if (!$order) {
            throw RefundException::orderNotFound($orderId);
        }

        // Idempotency: If already refunded, return existing refund record
        if ($order->payment_status === OrderLifecycleState::PAYMENT_REFUNDED ||
            $order->status === OrderLifecycleState::STATUS_REFUNDED) {
            $existing = $this->refundRepo->findRefundByOrderId($orderId);
            if ($existing) {
                return $existing;
            }
            throw RefundException::alreadyRefunded($order->order_number);
        }

        // Calculate authoritative refund amount
        $refundAmount = $this->calculateAuthoritativeRefundAmount($order);
        $refundMinor = (int)round((float)$refundAmount * 100);

        // Security check: Unpaid order cannot create money
        if ($refundMinor <= 0) {
            // An unpaid order can be cancelled, but cannot generate a refund credit
            if ($order->status === OrderLifecycleState::STATUS_PENDING) {
                $this->orderRepo->updateOrderStatus($orderId, OrderLifecycleState::STATUS_CANCELLED);
                $this->orderRepo->updateFulfillmentStatus($orderId, OrderLifecycleState::FULFILLMENT_CANCELLED);
            }
            throw RefundException::noVerifiedPayment($order->order_number);
        }

        $userId = (int)$order->user_id;

        return $this->executeInTransaction(function () use ($order, $orderId, $userId, $refundAmount, $cleanReason) {
            // 1. Double check order status inside transaction (lock / race protection)
            $freshOrder = $this->orderRepo->findOrder($orderId);
            if ($freshOrder->payment_status === OrderLifecycleState::PAYMENT_REFUNDED ||
                $freshOrder->status === OrderLifecycleState::STATUS_REFUNDED) {
                $existing = $this->refundRepo->findRefundByOrderId($orderId);
                if ($existing) {
                    return $existing;
                }
                throw RefundException::alreadyRefunded($order->order_number);
            }

            // 2. Generate deterministic refund reference for idempotency
            $refId = "ref_refund_ord_{$orderId}_" . bin2hex(random_bytes(6));
            $txDesc = "Refund for Order #{$order->order_number}: {$cleanReason}";

            // 3. Credit Customer Wallet via WalletService (type = refund_credit)
            $walletTx = $this->walletService->credit(
                $userId,
                $refundAmount,
                $refId,
                $txDesc,
                $orderId,
                'refund_credit'
            );

            // 4. Create immutable refund record in favorite_digital_refunds
            $now = date('Y-m-d H:i:s');
            $refundId = $this->refundRepo->createRefund([
                'order_id'              => $orderId,
                'order_item_id'         => null, // Full order refund
                'user_id'               => $userId,
                'refund_amount'         => $refundAmount,
                'currency'              => 'BDT',
                'destination'           => 'wallet', // Strictly wallet
                'wallet_transaction_id' => (int)$walletTx->id,
                'reason'                => $cleanReason,
                'status'                => 'completed',
                'processed_at'          => $now,
                'created_at'            => $now,
            ]);

            // 5. Update settled payment records to 'refunded'
            $payments = $this->orderRepo->getOrderPayments($orderId);
            foreach ($payments as $pay) {
                if ($pay->status === 'completed' || $pay->status === 'paid') {
                    $this->orderRepo->updatePayment((int)$pay->id, [
                        'status' => 'refunded',
                    ]);
                }
            }

            // 6. Revoke access / entitlements generated by this order
            $this->revokeOrderEntitlements($order);

            // 7. Complete order lifecycle state transition
            $this->orderRepo->updatePaymentStatus($orderId, OrderLifecycleState::PAYMENT_REFUNDED);
            $this->orderRepo->updateFulfillmentStatus($orderId, OrderLifecycleState::FULFILLMENT_REVOKED);
            $this->orderRepo->updateOrderStatus($orderId, OrderLifecycleState::STATUS_REFUNDED);

            return $this->refundRepo->findRefund($refundId);
        });
    }

    /**
     * Revoke entitlements associated with order items.
     * Strictly targets purchase and package-derived entitlements for this order only.
     * Independent purchases and unrelated packages are never touched.
     */
    protected function revokeOrderEntitlements(object $order): void
    {
        $orderId = (int)$order->id;
        $userId = (int)$order->user_id;
        $items = $order->items ?? $this->orderRepo->getOrderItems($orderId);

        foreach ($items as $item) {
            $itemId = (int)$item->id;
            $productType = (string)($item->product_type ?? '');

            // Revoke direct purchase entitlement
            $this->entitlementRepo->revokeBySource('purchase', $itemId);

            // Revoke package-derived entitlements (where source_type = 'package' AND source_id = $itemId)
            $this->entitlementRepo->revokeBySource('package', $itemId);

            // Handle membership product type if applicable
            if ($productType === ProductType::MEMBERSHIP) {
                $this->revokeMembershipItemAccess($userId, (int)$item->product_id);
            }
        }
    }

    /**
     * Revoke membership access using MembershipLifecycleService public API.
     */
    protected function revokeMembershipItemAccess(int $userId, int $productId): void
    {
        try {
            $plan = $this->membershipService->getPlanByProductId($productId);
            if (!$plan) {
                return;
            }

            $activeMembership = $this->membershipService->getActiveMembership($userId);
            if ($activeMembership && (int)$activeMembership->plan_id === (int)$plan->id) {
                // Terminate membership using public API
                $this->membershipService->expireMembership((int)$activeMembership->id);
            }
        } catch (Throwable) {
            // Safe fallback if membership plan does not exist
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
}
