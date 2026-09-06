<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Services;

use DateTimeImmutable;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Exceptions\FulfillmentException;
use FavoriteCMS\Digital\Repositories\EntitlementRepository;
use FavoriteCMS\Digital\Repositories\OrderRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Support\OrderLifecycleState;
use Throwable;

/**
 * FulfillmentService
 *
 * Orchestrates order fulfillment and entitlement granting for Favorite Digital.
 * Strictly adheres to Phase 5C architecture:
 * - Authoritative fulfillment only after payment_status = 'paid'
 * - Grants digital product & service entitlements (source_type = 'purchase')
 * - Grants package-derived child entitlements (source_type = 'package', source_id = package order_item_id)
 * - Activates/extends memberships through MembershipLifecycleService
 * - Completely idempotent on repeated execution
 * - Supports partial fulfillment and safe failure recovery
 */
class FulfillmentService
{
    protected OrderRepository $orderRepo;
    protected EntitlementRepository $entitlementRepo;
    protected ProductRepository $productRepo;
    protected MembershipLifecycleService $membershipService;
    protected ?Database $db;

    public function __construct(
        OrderRepository $orderRepo,
        EntitlementRepository $entitlementRepo,
        ProductRepository $productRepo,
        MembershipLifecycleService $membershipService,
        ?Database $db = null
    ) {
        $this->orderRepo = $orderRepo;
        $this->entitlementRepo = $entitlementRepo;
        $this->productRepo = $productRepo;
        $this->membershipService = $membershipService;
        $this->db = $db ?? $orderRepo->getDatabase();
    }

    public function getOrderRepository(): OrderRepository
    {
        return $this->orderRepo;
    }

    public function getEntitlementRepository(): EntitlementRepository
    {
        return $this->entitlementRepo;
    }

    public function getProductRepository(): ProductRepository
    {
        return $this->productRepo;
    }

    public function getMembershipService(): MembershipLifecycleService
    {
        return $this->membershipService;
    }

    /**
     * Fulfill an entire order.
     *
     * @param int $orderId
     * @return object Updated order with items and payments
     * @throws FulfillmentException
     */
    public function fulfillOrder(int $orderId): object
    {
        $order = $this->orderRepo->findOrderWithItems($orderId);
        if (!$order) {
            throw FulfillmentException::orderNotFound($orderId);
        }

        // 1. Validate payment status: strictly 'paid'
        if ($order->payment_status !== OrderLifecycleState::PAYMENT_PAID) {
            throw FulfillmentException::orderNotEligible(
                $order->order_number,
                "Payment status is '{$order->payment_status}'. Only 'paid' orders may be fulfilled"
            );
        }

        // 2. Validate order status: not cancelled or refunded
        if (in_array($order->status, [OrderLifecycleState::STATUS_CANCELLED, OrderLifecycleState::STATUS_REFUNDED], true)) {
            throw FulfillmentException::orderNotEligible(
                $order->order_number,
                "Order status is '{$order->status}'. Cancelled or refunded orders cannot be fulfilled"
            );
        }

        // 3. Idempotency check: if already fully fulfilled, return cleanly without side-effects
        if ($order->fulfillment_status === OrderLifecycleState::FULFILLMENT_FULFILLED) {
            return $order;
        }

        $items = $order->items ?? $this->orderRepo->getOrderItems($orderId);
        if (empty($items)) {
            throw FulfillmentException::orderNotEligible($order->order_number, "Order contains no items");
        }

        $fulfilledCount = 0;
        $failedCount = 0;
        $totalItems = count($items);
        $errors = [];

        foreach ($items as $item) {
            try {
                $this->fulfillItem($order, $item);
                $fulfilledCount++;
            } catch (Throwable $e) {
                $failedCount++;
                $errors[] = "Item #{$item->id} ({$item->product_type}): " . $e->getMessage();
            }
        }

        // Determine new fulfillment status
        if ($failedCount === 0 && $fulfilledCount === $totalItems) {
            $this->orderRepo->updateFulfillmentStatus($orderId, OrderLifecycleState::FULFILLMENT_FULFILLED);
            $this->orderRepo->updateOrderStatus($orderId, OrderLifecycleState::STATUS_COMPLETED);
        } elseif ($fulfilledCount > 0) {
            $this->orderRepo->updateFulfillmentStatus($orderId, OrderLifecycleState::FULFILLMENT_PARTIALLY_FULFILLED);
            $this->orderRepo->updateOrderStatus($orderId, OrderLifecycleState::STATUS_PROCESSING);
        } else {
            $this->orderRepo->updateFulfillmentStatus($orderId, OrderLifecycleState::FULFILLMENT_UNFULFILLED);
            $this->orderRepo->updateOrderStatus($orderId, OrderLifecycleState::STATUS_PROCESSING);
        }

        $updatedOrder = $this->orderRepo->findOrderWithItems($orderId);

        if ($failedCount > 0) {
            throw FulfillmentException::itemFulfillmentFailed(
                (int)$items[0]->id,
                "Fulfillment incomplete ({$fulfilledCount}/{$totalItems} fulfilled). Errors: " . implode('; ', $errors)
            );
        }

        return $updatedOrder;
    }

    /**
     * Fulfill a single order item idempotently.
     */
    public function fulfillItem(object $order, object $item): bool
    {
        $userId = (int)$order->user_id;
        $productId = (int)$item->product_id;
        $orderItemId = (int)$item->id;
        $productType = (string)$item->product_type;
        $nowStr = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        switch ($productType) {
            case ProductType::DIGITAL:
                return $this->fulfillDigitalProduct($userId, $productId, $orderItemId, $nowStr);

            case ProductType::SERVICE:
                return $this->fulfillService($userId, $productId, $orderItemId, $nowStr);

            case ProductType::PACKAGE:
                return $this->fulfillPackage($userId, $productId, $orderItemId, $nowStr);

            case ProductType::MEMBERSHIP:
                return $this->fulfillMembership($userId, $productId, $orderItemId, $nowStr);

            default:
                throw FulfillmentException::itemFulfillmentFailed(
                    $orderItemId,
                    "Unsupported product type '{$productType}'"
                );
        }
    }

    /**
     * Fulfill a direct digital product item.
     */
    protected function fulfillDigitalProduct(int $userId, int $productId, int $orderItemId, string $nowStr): bool
    {
        // Check if active entitlement already exists for this exact purchase order item
        $existing = $this->entitlementRepo->findEntitlementBySource($userId, $productId, 'purchase', $orderItemId);
        if ($existing && $existing->status === 'active') {
            return true; // Already fulfilled idempotently
        }

        $expiresAt = null;
        $details = $this->productRepo->findProductDetails($productId);
        if ($details && (int)$details->download_expiry_days > 0) {
            $days = (int)$details->download_expiry_days;
            $expiresAt = (new DateTimeImmutable($nowStr))->modify("+{$days} days")->format('Y-m-d H:i:s');
        }

        if ($existing) {
            $this->entitlementRepo->updateEntitlement((int)$existing->id, [
                'status'     => 'active',
                'granted_at' => $nowStr,
                'expires_at' => $expiresAt,
            ]);
            return true;
        }

        $this->entitlementRepo->createEntitlement([
            'user_id'     => $userId,
            'product_id'  => $productId,
            'source_type' => 'purchase',
            'source_id'   => $orderItemId,
            'status'      => 'active',
            'granted_at'  => $nowStr,
            'expires_at'  => $expiresAt,
        ]);

        return true;
    }

    /**
     * Fulfill a direct digital service item.
     */
    protected function fulfillService(int $userId, int $productId, int $orderItemId, string $nowStr): bool
    {
        $existing = $this->entitlementRepo->findEntitlementBySource($userId, $productId, 'purchase', $orderItemId);
        if ($existing && $existing->status === 'active') {
            return true;
        }

        if ($existing) {
            $this->entitlementRepo->updateEntitlement((int)$existing->id, [
                'status'     => 'active',
                'granted_at' => $nowStr,
                'expires_at' => null,
            ]);
            return true;
        }

        $this->entitlementRepo->createEntitlement([
            'user_id'     => $userId,
            'product_id'  => $productId,
            'source_type' => 'purchase',
            'source_id'   => $orderItemId,
            'status'      => 'active',
            'granted_at'  => $nowStr,
            'expires_at'  => null,
        ]);

        return true;
    }

    /**
     * Fulfill a package item.
     * Grants a purchase entitlement for the package, plus package-derived entitlements
     * for all included digital products and services.
     */
    protected function fulfillPackage(int $userId, int $packageProductId, int $orderItemId, string $nowStr): bool
    {
        // 1. Grant purchase entitlement for the package product itself
        $existingPkg = $this->entitlementRepo->findEntitlementBySource($userId, $packageProductId, 'purchase', $orderItemId);
        if (!$existingPkg) {
            $this->entitlementRepo->createEntitlement([
                'user_id'     => $userId,
                'product_id'  => $packageProductId,
                'source_type' => 'purchase',
                'source_id'   => $orderItemId,
                'status'      => 'active',
                'granted_at'  => $nowStr,
                'expires_at'  => null,
            ]);
        } elseif ($existingPkg->status !== 'active') {
            $this->entitlementRepo->updateEntitlement((int)$existingPkg->id, [
                'status' => 'active',
            ]);
        }

        // 2. Fetch package items
        $package = $this->productRepo->findPackageByProductId($packageProductId);
        $packageId = $package ? (int)$package->id : $packageProductId;
        $packageItems = $this->productRepo->getPackageItemsWithProducts($packageId);
        if (empty($packageItems) && $packageId !== $packageProductId) {
            $packageItems = $this->productRepo->getPackageItemsWithProducts($packageProductId);
        }

        foreach ($packageItems as $item) {
            $childProductId = (int)($item->included_product_id ?? $item->id);
            $childType = (string)($item->product_type ?? '');

            // Strict architecture rule: packages cannot contain nested packages or memberships
            if ($childType === ProductType::PACKAGE || $childType === ProductType::MEMBERSHIP) {
                continue;
            }

            // Check if package-derived entitlement already exists for this order item
            $existingChild = $this->entitlementRepo->findEntitlementBySource($userId, $childProductId, 'package', $orderItemId);
            if ($existingChild && $existingChild->status === 'active') {
                continue; // Already granted
            }

            $childExpiresAt = null;
            if ($childType === ProductType::DIGITAL) {
                $childDetails = $this->productRepo->findProductDetails($childProductId);
                if ($childDetails && (int)$childDetails->download_expiry_days > 0) {
                    $days = (int)$childDetails->download_expiry_days;
                    $childExpiresAt = (new DateTimeImmutable($nowStr))->modify("+{$days} days")->format('Y-m-d H:i:s');
                }
            }

            if ($existingChild) {
                $this->entitlementRepo->updateEntitlement((int)$existingChild->id, [
                    'status'     => 'active',
                    'granted_at' => $nowStr,
                    'expires_at' => $childExpiresAt,
                ]);
            } else {
                $this->entitlementRepo->createEntitlement([
                    'user_id'     => $userId,
                    'product_id'  => $childProductId,
                    'source_type' => 'package',
                    'source_id'   => $orderItemId,
                    'status'      => 'active',
                    'granted_at'  => $nowStr,
                    'expires_at'  => $childExpiresAt,
                ]);
            }
        }

        return true;
    }

    /**
     * Fulfill a membership product item.
     * Activates or extends the membership in favorite_digital_memberships via MembershipLifecycleService,
     * and records the purchase entitlement in favorite_digital_entitlements.
     */
    protected function fulfillMembership(int $userId, int $productId, int $orderItemId, string $nowStr): bool
    {
        // 1. Check if purchase entitlement already exists for this exact order item
        $existing = $this->entitlementRepo->findEntitlementBySource($userId, $productId, 'purchase', $orderItemId);
        if ($existing && $existing->status === 'active') {
            return true; // Already fulfilled idempotently, do not double extend
        }

        // 2. Resolve plan tier
        $plan = $this->membershipService->getPlanByProductId($productId);
        if (!$plan) {
            throw FulfillmentException::itemFulfillmentFailed(
                $orderItemId,
                "No membership plan found for product #{$productId}"
            );
        }

        // 3. Activate/extend membership via MembershipLifecycleService (preserves active time & month clamping)
        $membership = $this->membershipService->activateMembership($userId, (int)$plan->id, false);

        $expiresAt = $membership->expires_at ?? null;

        if ($existing) {
            $this->entitlementRepo->updateEntitlement((int)$existing->id, [
                'status'     => 'active',
                'granted_at' => $nowStr,
                'expires_at' => $expiresAt,
            ]);
        } else {
            $this->entitlementRepo->createEntitlement([
                'user_id'     => $userId,
                'product_id'  => $productId,
                'source_type' => 'purchase',
                'source_id'   => $orderItemId,
                'status'      => 'active',
                'granted_at'  => $nowStr,
                'expires_at'  => $expiresAt,
            ]);
        }

        return true;
    }
}
