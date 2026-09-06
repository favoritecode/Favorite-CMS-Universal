<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Services;

use DateTimeImmutable;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\Contracts\EntitlementCheckerInterface;
use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Exceptions\OrderValidationException;
use FavoriteCMS\Digital\Repositories\OrderRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Support\OrderLifecycleState;
use FavoriteCMS\Digital\Support\ProductPricingCalculator;
use Throwable;

class OrderService
{
    protected OrderRepository $orderRepo;
    protected ProductRepository $productRepo;
    protected ?MembershipLifecycleService $membershipService;
    protected ?EntitlementCheckerInterface $entitlementChecker;
    protected ?Database $db;

    public function __construct(
        OrderRepository $orderRepo,
        ProductRepository $productRepo,
        ?MembershipLifecycleService $membershipService = null,
        ?EntitlementCheckerInterface $entitlementChecker = null,
        ?Database $db = null
    ) {
        $this->orderRepo = $orderRepo;
        $this->productRepo = $productRepo;
        $this->membershipService = $membershipService;
        $this->entitlementChecker = $entitlementChecker ?? new DefaultEntitlementChecker($db);
        $this->db = $db ?? $orderRepo->getDatabase();
    }

    public function getOrderRepository(): OrderRepository
    {
        return $this->orderRepo;
    }

    public function getProductRepository(): ProductRepository
    {
        return $this->productRepo;
    }

    public function getMembershipService(): ?MembershipLifecycleService
    {
        return $this->membershipService;
    }

    public function getEntitlementChecker(): ?EntitlementCheckerInterface
    {
        return $this->entitlementChecker;
    }

    /**
     * Create a new order with immutable line-item price snapshots.
     *
     * @param int $userId ID of the purchasing customer
     * @param array $items Array of item descriptors, each with 'product_id' and optional 'quantity'
     * @param string|null $notes Optional customer/order notes
     * @return object Complete order object with hydrated items
     *
     * @throws OrderValidationException If validation fails
     */
    public function createOrder(int $userId, array $items, ?string $notes = null): object
    {
        // 1. Validate User
        $this->validateUser($userId);

        // 2. Validate Items structure
        if (empty($items)) {
            throw new OrderValidationException("Order must contain at least one item.");
        }

        $preparedItems = [];
        $totalSubtotal = 0.00;
        $totalDiscount = 0.00;
        $totalAmount   = 0.00;
        $orderCurrency = 'BDT';

        // 3. Authoritative item validation and pricing calculation
        foreach ($items as $index => $itemRaw) {
            $productId = (int)($itemRaw['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new OrderValidationException("Invalid product ID at index {$index}.");
            }

            $quantity = isset($itemRaw['quantity']) ? (int)$itemRaw['quantity'] : 1;
            if ($quantity < 1) {
                throw new OrderValidationException("Item quantity must be at least 1.");
            }

            // Fetch authoritative catalog record
            $product = $this->productRepo->findProduct($productId);
            if (!$product) {
                throw new OrderValidationException("Product with ID {$productId} not found.");
            }

            // Validate status: ONLY published products can be ordered
            if ($product->status !== ProductStatus::PUBLISHED) {
                throw new OrderValidationException(
                    "Product '{$product->title}' is not available for purchase (status: {$product->status})."
                );
            }

            // Validate membership requirement
            if ($this->isMembershipRequiredForProduct($product)) {
                $hasActiveMembership = false;
                if ($this->membershipService !== null) {
                    $hasActiveMembership = $this->membershipService->hasActiveMembership($userId);
                }

                if (!$hasActiveMembership) {
                    throw new OrderValidationException(
                        "Product '{$product->title}' requires an active membership to purchase."
                    );
                }
            }

            // Validate duplicate purchase via entitlement checker interface
            if ($this->entitlementChecker !== null) {
                $hasEntitlement = $this->entitlementChecker->hasActiveEntitlement($userId, (int)$product->id);
                if ($hasEntitlement && !$this->entitlementChecker->allowDuplicatePurchase($userId, (int)$product->id)) {
                    throw new OrderValidationException(
                        "User already holds an active entitlement for '{$product->title}'. Duplicate purchase is not permitted."
                    );
                }
            }

            // Authoritative server-side pricing derivation (completely ignoring any client-sent price)
            $origPriceFloat   = (float)$product->original_price;
            $discountPctFloat = (float)$product->discount_percent;
            $isFreeBool       = (bool)$product->is_free;
            $currency         = !empty($product->currency) ? strtoupper(trim((string)$product->currency)) : 'BDT';
            $orderCurrency    = $currency;

            $finalPriceStr = ProductPricingCalculator::deriveFinalPrice(
                $origPriceFloat,
                $discountPctFloat,
                $isFreeBool
            );
            $finalPriceFloat = (float)$finalPriceStr;
            $unitPriceStr = number_format($origPriceFloat, 2, '.', '');
            $discountPctStr = number_format($discountPctFloat, 2, '.', '');

            // Freeze immutable snapshot of product attributes
            $attributesSnapshot = $this->buildAttributesSnapshot($product);

            $snapshot = [
                'id'               => (int)$product->id,
                'title'            => (string)$product->title,
                'slug'             => (string)$product->slug,
                'product_type'     => (string)$product->product_type,
                'original_price'   => $unitPriceStr,
                'discount_percent' => $discountPctStr,
                'final_price'      => $finalPriceStr,
                'currency'         => $currency,
                'is_free'          => $isFreeBool,
                'quantity'         => $quantity,
                'captured_at'      => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'attributes'       => $attributesSnapshot,
            ];

            // Calculate financial line items
            $lineSubtotal = round($origPriceFloat * $quantity, 2);
            $lineTotal    = round($finalPriceFloat * $quantity, 2);
            $lineDiscount = round(($origPriceFloat - $finalPriceFloat) * $quantity, 2);

            $totalSubtotal += $lineSubtotal;
            $totalDiscount += $lineDiscount;
            $totalAmount   += $lineTotal;

            $preparedItems[] = [
                'product'          => $product,
                'product_id'       => (int)$product->id,
                'product_type'     => (string)$product->product_type,
                'unit_price'       => $unitPriceStr,
                'discount_percent' => $discountPctStr,
                'final_price'      => $finalPriceStr,
                'currency'         => $currency,
                'quantity'         => $quantity,
                'snapshot_data'    => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        // 4. Generate unique collision-resistant order number
        $orderNumber = $this->generateOrderNumber();

        // 5. Atomic persistence of order and order items
        $orderData = [
            'order_number'       => $orderNumber,
            'user_id'            => $userId,
            'status'             => OrderLifecycleState::STATUS_PENDING,
            'payment_status'     => OrderLifecycleState::PAYMENT_UNPAID,
            'fulfillment_status' => OrderLifecycleState::FULFILLMENT_UNFULFILLED,
            'subtotal_amount'    => number_format($totalSubtotal, 2, '.', ''),
            'discount_amount'    => number_format(max(0.00, $totalDiscount), 2, '.', ''),
            'total_amount'       => number_format(max(0.00, $totalAmount), 2, '.', ''),
            'currency'           => $orderCurrency,
            'notes'              => $notes !== null ? trim($notes) : null,
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ];

        return $this->db->transaction(function () use ($orderData, $preparedItems) {
            $orderId = $this->orderRepo->createOrder($orderData);

            foreach ($preparedItems as $pItem) {
                $qty = $pItem['quantity'];
                for ($q = 0; $q < $qty; $q++) {
                    $this->orderRepo->createOrderItem([
                        'order_id'         => $orderId,
                        'product_id'       => $pItem['product_id'],
                        'product_type'     => $pItem['product_type'],
                        'unit_price'       => $pItem['unit_price'],
                        'discount_percent' => $pItem['discount_percent'],
                        'final_price'      => $pItem['final_price'],
                        'currency'         => $pItem['currency'],
                        'snapshot_data'    => $pItem['snapshot_data'],
                        'created_at'       => date('Y-m-d H:i:s'),
                    ]);
                }
            }

            return $this->orderRepo->findOrderWithItems($orderId);
        });
    }

    /**
     * Generate a customer-facing, collision-resistant order number.
     * E.g. ORD-20260907-3F9A1B
     */
    public function generateOrderNumber(): string
    {
        $prefix = 'ORD-' . date('Ymd') . '-';
        for ($i = 0; $i < 10; $i++) {
            $randomHex = strtoupper(bin2hex(random_bytes(3))); // 6 hex chars
            $candidate = $prefix . $randomHex;
            if (!$this->orderRepo->isOrderNumberExists($candidate)) {
                return $candidate;
            }
        }

        // Fallback with microtime if intense collision
        return $prefix . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
    }

    public function updateStatus(int $orderId, string $status): bool
    {
        if (!OrderLifecycleState::isValidStatus($status)) {
            throw new OrderValidationException("Invalid order status: {$status}");
        }
        return $this->orderRepo->updateOrderStatus($orderId, $status);
    }

    public function updatePaymentStatus(int $orderId, string $paymentStatus): bool
    {
        if (!OrderLifecycleState::isValidPaymentStatus($paymentStatus)) {
            throw new OrderValidationException("Invalid payment status: {$paymentStatus}");
        }
        return $this->orderRepo->updatePaymentStatus($orderId, $paymentStatus);
    }

    public function updateFulfillmentStatus(int $orderId, string $fulfillmentStatus): bool
    {
        if (!OrderLifecycleState::isValidFulfillmentStatus($fulfillmentStatus)) {
            throw new OrderValidationException("Invalid fulfillment status: {$fulfillmentStatus}");
        }
        return $this->orderRepo->updateFulfillmentStatus($orderId, $fulfillmentStatus);
    }

    public function getOrder(int $orderId): ?object
    {
        return $this->orderRepo->findOrderWithItems($orderId);
    }

    public function getOrderByNumber(string $orderNumber): ?object
    {
        return $this->orderRepo->findOrderWithItemsByOrderNumber($orderNumber);
    }

    public function listOrders(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        return $this->orderRepo->listOrders($filters, $page, $perPage);
    }

    public function listUserOrders(int $userId, int $page = 1, int $perPage = 20): array
    {
        return $this->orderRepo->listOrdersForUser($userId, $page, $perPage);
    }

    protected function validateUser(int $userId): void
    {
        if ($userId <= 0) {
            throw new OrderValidationException("Invalid user ID specified for order.");
        }

        // Defensive check if user exists in database
        try {
            $userTable = null;
            if ($this->db->tableExists('users')) {
                $userTable = 'users';
            } elseif ($this->db->tableExists('cms_users')) {
                $userTable = 'cms_users';
            }

            if ($userTable !== null) {
                $userRow = $this->db->selectOne("SELECT * FROM `{$userTable}` WHERE `id` = ? LIMIT 1", [$userId]);
                if (!$userRow) {
                    throw new OrderValidationException("User with ID {$userId} not found.");
                }

                // Check active status if field exists
                if (isset($userRow->status) && strtolower((string)$userRow->status) !== 'active') {
                    throw new OrderValidationException("User with ID {$userId} is inactive or suspended.");
                }
                if (isset($userRow->is_active) && (int)$userRow->is_active === 0) {
                    throw new OrderValidationException("User with ID {$userId} is inactive or suspended.");
                }
            }
        } catch (OrderValidationException $e) {
            throw $e;
        } catch (Throwable) {
            // If checking user fails due to schema quirks, allow valid positive user ID
        }
    }

    /** @var callable|null */
    protected $membershipRequirementChecker = null;

    public function setMembershipRequirementChecker(?callable $checker): void
    {
        $this->membershipRequirementChecker = $checker;
    }

    public function isMembershipRequiredForProduct(object $product): bool
    {
        if ($this->membershipRequirementChecker !== null) {
            return (bool)call_user_func($this->membershipRequirementChecker, $product);
        }

        if (!empty($product->is_membership_required)) {
            return true;
        }

        if ((string)($product->product_type ?? '') === ProductType::DIGITAL) {
            $detail = $this->productRepo->findProductDetails((int)$product->id);
            if ($detail && (!empty($detail->is_membership_required) || !empty($detail->requires_membership))) {
                return true;
            }
        }

        return false;
    }

    protected function buildAttributesSnapshot(object $product): array
    {
        $type = (string)$product->product_type;
        $productId = (int)$product->id;
        $attrs = [];

        try {
            if ($type === ProductType::DIGITAL) {
                $detail = $this->productRepo->findProductDetails($productId);
                if ($detail) {
                    $attrs = [
                        'max_downloads'          => $detail->max_downloads ?? 0,
                        'download_expiry_days'   => $detail->download_expiry_days ?? 0,
                        'file_name'              => $detail->file_name ?? '',
                        'mime_type'              => $detail->mime_type ?? '',
                        'file_size'              => $detail->file_size ?? 0,
                        'is_membership_eligible' => (bool)($detail->is_membership_eligible ?? false),
                    ];
                }
            } elseif ($type === ProductType::SERVICE) {
                $detail = $this->productRepo->findServiceDetails($productId);
                if ($detail) {
                    $attrs = [
                        'delivery_time_days'  => $detail->delivery_time_days ?? 1,
                        'service_scope'       => $detail->service_scope ?? '',
                        'requirements_prompt' => $detail->requirements_prompt ?? '',
                    ];
                }
            } elseif ($type === ProductType::PACKAGE) {
                $package = $this->productRepo->findPackageByProductId($productId);
                if ($package) {
                    $items = $this->productRepo->getPackageItemsWithProducts((int)$package->id);
                    $itemSnapshots = [];
                    foreach ($items as $pItem) {
                        $itemSnapshots[] = [
                            'child_product_id'    => (int)($pItem->included_product_id ?? $pItem->child_product_id ?? 0),
                            'included_product_id' => (int)($pItem->included_product_id ?? $pItem->child_product_id ?? 0),
                            'title'               => $pItem->title ?? $pItem->child_title ?? '',
                            'product_type'        => $pItem->product_type ?? $pItem->child_product_type ?? '',
                            'unit_price'          => $pItem->final_price ?? $pItem->child_price ?? '0.00',
                        ];
                    }
                    $attrs = [
                        'package_id'        => $package->id,
                        'total_items_count' => $package->total_items_count ?? count($items),
                        'items'             => $itemSnapshots,
                    ];
                }
            } elseif ($type === ProductType::MEMBERSHIP) {
                $plan = $this->productRepo->findMembershipPlanByProductId($productId);
                if ($plan) {
                    $attrs = [
                        'plan_id'             => $plan->id,
                        'plan_type'           => $plan->plan_type,
                        'duration_count'      => $plan->duration_count ?? 1,
                        'duration_unit'       => $plan->duration_unit ?? 'month',
                        'grace_period_days'   => $plan->grace_period_days,
                        'allows_auto_renewal' => (bool)$plan->allows_auto_renewal,
                    ];
                }
            }
        } catch (Throwable) {
            // Defensive: attribute capture failure should not block snapshotting base catalog data
        }

        return $attrs;
    }
}
