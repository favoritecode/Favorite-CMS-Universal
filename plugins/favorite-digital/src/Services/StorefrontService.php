<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Services;

use DateTimeImmutable;
use FavoriteCMS\Core\Currency;
use FavoriteCMS\Digital\Contracts\EntitlementCheckerInterface;
use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Exceptions\OrderValidationException;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use InvalidArgumentException;
use Throwable;

/**
 * StorefrontService
 *
 * Customer storefront product discovery, search, filtering, sorting,
 * pricing presentation, ownership state resolution, and checkout flow integration.
 */
class StorefrontService
{
    protected ProductRepository $productRepo;
    protected EntitlementCheckerInterface $entitlementChecker;
    protected MembershipLifecycleService $membershipService;
    protected OrderService $orderService;
    protected CheckoutService $checkoutService;

    public function __construct(
        ProductRepository $productRepo,
        EntitlementCheckerInterface $entitlementChecker,
        MembershipLifecycleService $membershipService,
        OrderService $orderService,
        CheckoutService $checkoutService
    ) {
        $this->productRepo = $productRepo;
        $this->entitlementChecker = $entitlementChecker;
        $this->membershipService = $membershipService;
        $this->orderService = $orderService;
        $this->checkoutService = $checkoutService;
    }

    public function getProductRepository(): ProductRepository
    {
        return $this->productRepo;
    }

    public function getEntitlementChecker(): EntitlementCheckerInterface
    {
        return $this->entitlementChecker;
    }

    public function getMembershipService(): MembershipLifecycleService
    {
        return $this->membershipService;
    }

    public function getOrderService(): OrderService
    {
        return $this->orderService;
    }

    public function getCheckoutService(): CheckoutService
    {
        return $this->checkoutService;
    }

    /**
     * Browse published storefront products with search, filtering, whitelisted sorting, and pagination.
     * Each product is decorated with authoritative financial formatting and customer-specific ownership state.
     *
     * @param array<string, mixed> $filters
     */
    public function browseProducts(array $filters = [], int $page = 1, int $perPage = 12, ?int $userId = null): array
    {
        $result = $this->productRepo->listStorefrontProducts($filters, $page, $perPage);

        $decoratedItems = [];
        foreach ($result['items'] as $product) {
            $decoratedItems[] = $this->decorateProductListing($product, $userId);
        }

        $result['items'] = $decoratedItems;
        $result['site_currency'] = $this->getSiteCurrency();

        return $result;
    }

    /**
     * Fetch complete details for a single published product by its URL slug.
     * Returns null if product does not exist or is unpublished (draft/archived).
     */
    public function getProductDetail(string $slug, ?int $userId = null): ?array
    {
        $cleanSlug = trim($slug);
        if ($cleanSlug === '') {
            return null;
        }

        $product = $this->productRepo->findPublishedProductBySlug($cleanSlug);
        if (!$product) {
            return null;
        }

        $type = (string)$product->product_type;
        $productId = (int)$product->id;
        $typeDetails = [];
        $packageItems = [];

        if ($type === ProductType::DIGITAL) {
            $details = $this->productRepo->findProductDetails($productId);
            if ($details) {
                // Safe customer-facing metadata ONLY. No internal storage paths or private tokens!
                $typeDetails = [
                    'version'                => (string)($details->version ?? '1.0.0'),
                    'file_name'              => (string)($details->file_name ?? 'Downloadable File'),
                    'file_size'              => (int)($details->file_size ?? 0),
                    'formatted_file_size'    => $this->formatFileSize((int)($details->file_size ?? 0)),
                    'mime_type'              => (string)($details->mime_type ?? 'application/octet-stream'),
                    'max_downloads'          => (int)($details->max_downloads ?? 0),
                    'download_expiry_days'   => (int)($details->download_expiry_days ?? 0),
                    'is_membership_eligible' => !empty($details->is_membership_eligible),
                ];
            }
        } elseif ($type === ProductType::SERVICE) {
            $details = $this->productRepo->findServiceDetails($productId);
            if ($details) {
                $typeDetails = [
                    'delivery_time_days'  => (int)($details->delivery_time_days ?? 1),
                    'service_scope'       => (string)($details->service_scope ?? ''),
                    'requirements_prompt' => (string)($details->requirements_prompt ?? ''),
                ];
            }
        } elseif ($type === ProductType::PACKAGE) {
            $package = $this->productRepo->findPackageByProductId($productId);
            if ($package) {
                $packageId = (int)$package->id;
                $rawItems = $this->productRepo->getPackageItemsWithProducts($packageId);
                foreach ($rawItems as $rawItem) {
                    $packageItems[] = [
                        'id'             => (int)($rawItem->included_product_id ?? $rawItem->id),
                        'title'          => (string)($rawItem->title ?? ''),
                        'product_type'   => (string)($rawItem->product_type ?? ''),
                        'description'    => (string)($rawItem->description ?? ''),
                        'price'          => (string)($rawItem->final_price ?? '0.00'),
                        'formatted_price'=> $this->formatPrice((string)($rawItem->final_price ?? '0.00'), $product->currency ?? null),
                        'status'         => (string)($rawItem->status ?? 'published'),
                    ];
                }
                $typeDetails = [
                    'package_id'        => $packageId,
                    'package_type'      => (string)($package->package_type ?? 'bundle'),
                    'total_items_count' => count($packageItems),
                    'items'             => $packageItems,
                ];
            }
        } elseif ($type === ProductType::MEMBERSHIP) {
            $plan = $this->productRepo->findMembershipPlanByProductId($productId);
            if ($plan) {
                $typeDetails = [
                    'plan_id'             => (int)$plan->id,
                    'plan_type'           => (string)($plan->plan_type ?? 'monthly'),
                    'duration_count'      => (int)($plan->duration_count ?? 1),
                    'duration_unit'       => (string)($plan->duration_unit ?? 'months'),
                    'grace_period_days'   => (int)($plan->grace_period_days ?? 0),
                    'allows_auto_renewal' => !empty($plan->allows_auto_renewal),
                    // Auto-renewal is strictly OFF by default per locked rules
                    'auto_renewal_default'=> false,
                ];
            }
        }

        $customerState = $this->resolveCustomerState($product, $userId);
        $pricing = $this->buildPricingSummary($product);

        return [
            'product'        => $product,
            'pricing'        => $pricing,
            'type_details'   => $typeDetails,
            'package_items'  => $packageItems,
            'customer_state' => $customerState,
            'site_currency'  => $this->getSiteCurrency(),
        ];
    }

    /**
     * Resolve the authoritative customer-facing purchase & ownership state.
     */
    public function resolveCustomerState(object $product, ?int $userId = null): array
    {
        $productId = (int)$product->id;
        $productType = (string)$product->product_type;
        $isFree = !empty($product->is_free) || (float)$product->final_price === 0.0;

        // 1. Guest customer
        if ($userId === null || $userId <= 0) {
            return [
                'state'               => 'guest',
                'label'               => 'Guest',
                'badge'               => null,
                'button_text'         => 'Sign in to Buy',
                'button_class'        => 'btn-primary',
                'action_url'          => '/login?redirect=' . urlencode('/store/' . $product->slug),
                'is_purchasable'      => false,
                'is_owned'            => false,
                'requires_membership' => $this->orderService->isMembershipRequiredForProduct($product),
            ];
        }

        // 2. Authenticated: check if customer already holds an active entitlement
        $hasEntitlement = $this->entitlementChecker->hasActiveEntitlement($userId, $productId);
        if ($hasEntitlement) {
            $isDigital = ($productType === ProductType::DIGITAL);
            return [
                'state'               => 'owned',
                'label'               => 'Already Owned',
                'badge'               => 'Owned',
                'button_text'         => $isDigital ? 'Download File' : 'View Access',
                'button_class'        => 'btn-success',
                'action_url'          => $isDigital ? '/account/downloads' : '/account/orders',
                'is_purchasable'      => false,
                'is_owned'            => true,
                'requires_membership' => false,
            ];
        }

        // 3. Authenticated: if this product IS a membership plan
        if ($productType === ProductType::MEMBERSHIP) {
            $activeMembership = $this->membershipService->getActiveMembership($userId);
            if ($activeMembership !== null) {
                return [
                    'state'               => 'active_member',
                    'label'               => 'Active Member',
                    'badge'               => 'Active Member',
                    'button_text'         => 'Extend / Renew Plan',
                    'button_class'        => 'btn-primary',
                    'action_url'          => null, // POST to buy
                    'is_purchasable'      => true,
                    'is_owned'            => false,
                    'membership_expires'  => $activeMembership->expires_at ?? null,
                    'requires_membership' => false,
                ];
            }

            return [
                'state'               => $isFree ? 'free' : 'purchasable',
                'label'               => $isFree ? 'Free Plan' : 'Join Membership',
                'badge'               => $isFree ? 'Free' : null,
                'button_text'         => $isFree ? 'Join for Free' : 'Join Membership',
                'button_class'        => 'btn-primary',
                'action_url'          => null,
                'is_purchasable'      => true,
                'is_owned'            => false,
                'requires_membership' => false,
            ];
        }

        // 4. Authenticated: check if product requires an active membership
        $requiresMembership = $this->orderService->isMembershipRequiredForProduct($product);
        if ($requiresMembership) {
            $hasActiveMem = $this->membershipService->hasActiveMembership($userId);
            if (!$hasActiveMem) {
                return [
                    'state'               => 'membership_required',
                    'label'               => 'Membership Required',
                    'badge'               => 'Members Only',
                    'button_text'         => 'Active Membership Required',
                    'button_class'        => 'btn-warning',
                    'action_url'          => '/store?product_type=membership',
                    'is_purchasable'      => false,
                    'is_owned'            => false,
                    'requires_membership' => true,
                ];
            }

            // User HAS an active membership: check if content is included under membership
            if ($this->membershipService->isEligibleForMembershipContent($userId, $productId)) {
                $isDigital = ($productType === ProductType::DIGITAL);
                return [
                    'state'               => 'membership_access',
                    'label'               => 'Included with Membership',
                    'badge'               => 'Membership Perk',
                    'button_text'         => $isDigital ? 'Download File' : 'Access Service',
                    'button_class'        => 'btn-success',
                    'action_url'          => $isDigital ? '/account/downloads' : '/account/orders',
                    'is_purchasable'      => false,
                    'is_owned'            => true,
                    'requires_membership' => true,
                ];
            }
        }

        // 5. Free product state
        if ($isFree) {
            return [
                'state'               => 'free',
                'label'               => 'Free',
                'badge'               => 'Free',
                'button_text'         => 'Get for Free',
                'button_class'        => 'btn-primary',
                'action_url'          => null, // POST to buy triggers zero-value order
                'is_purchasable'      => true,
                'is_owned'            => false,
                'requires_membership' => false,
            ];
        }

        // 6. Standard purchasable product
        return [
            'state'               => 'purchasable',
            'label'               => 'Available',
            'badge'               => null,
            'button_text'         => 'Buy Now',
            'button_class'        => 'btn-primary',
            'action_url'          => null, // POST to buy creates order and redirects to checkout
            'is_purchasable'      => true,
            'is_owned'            => false,
            'requires_membership' => false,
        ];
    }

    /**
     * Initiate customer purchase for a published product.
     * Uses existing OrderService::createOrder and CheckoutService.
     *
     * @throws OrderValidationException If order creation or validation fails
     * @throws InvalidArgumentException If product is invalid or unpublished
     */
    public function initiatePurchase(string $slug, int $userId): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException("User must be logged in to purchase.");
        }

        $cleanSlug = trim($slug);
        $product = $this->productRepo->findPublishedProductBySlug($cleanSlug);
        if (!$product) {
            throw new InvalidArgumentException("Product not found or unavailable for purchase.");
        }

        $productId = (int)$product->id;
        $currency = !empty($product->currency) ? (string)$product->currency : $this->getSiteCurrency();

        // 1. Authoritatively create order via OrderService
        $order = $this->orderService->createOrder(
            $userId,
            [['product_id' => $productId, 'quantity' => 1]],
            $currency
        );

        $orderId = (int)$order->id;
        $orderNumber = (string)$order->order_number;

        // 2. If order total amount is 0.00 (free product), immediately settle and fulfill
        if ((float)$order->total_amount === 0.00 || !empty($order->is_free)) {
            $this->checkoutService->processZeroValueOrder($orderId, $userId);

            return [
                'status'       => 'fulfilled',
                'order'        => $order,
                'order_number' => $orderNumber,
                'redirect_url' => "/account/orders/{$orderNumber}",
                'message'      => "Your free access for '{$product->title}' has been activated!",
            ];
        }

        // 3. Paid order: redirect to existing Phase 5B checkout flow
        return [
            'status'       => 'pending_payment',
            'order'        => $order,
            'order_number' => $orderNumber,
            'redirect_url' => "/checkout/{$orderNumber}",
            'message'      => null,
        ];
    }

    /**
     * Decorate a raw product database object for catalog listing view.
     */
    protected function decorateProductListing(object $product, ?int $userId): array
    {
        $pricing = $this->buildPricingSummary($product);
        $customerState = $this->resolveCustomerState($product, $userId);

        $packageCount = 0;
        if ((string)$product->product_type === ProductType::PACKAGE) {
            $pkg = $this->productRepo->findPackageByProductId((int)$product->id);
            if ($pkg) {
                $packageCount = (int)($pkg->total_items_count ?? 0);
            }
        }

        $planSummary = null;
        if ((string)$product->product_type === ProductType::MEMBERSHIP) {
            $plan = $this->productRepo->findMembershipPlanByProductId((int)$product->id);
            if ($plan) {
                $planSummary = [
                    'plan_type'      => (string)($plan->plan_type ?? 'monthly'),
                    'duration_count' => (int)($plan->duration_count ?? 1),
                    'duration_unit'  => (string)($plan->duration_unit ?? 'months'),
                ];
            }
        }

        return [
            'id'                  => (int)$product->id,
            'title'               => (string)$product->title,
            'slug'                => (string)$product->slug,
            'description'         => (string)($product->description ?? ''),
            'product_type'        => (string)$product->product_type,
            'status'              => (string)$product->status,
            'currency'            => (string)($product->currency ?? $this->getSiteCurrency()),
            'is_free'             => !empty($product->is_free),
            'pricing'             => $pricing,
            'customer_state'      => $customerState,
            'package_count'       => $packageCount,
            'plan_summary'        => $planSummary,
        ];
    }

    /**
     * Build authoritative financial pricing summary.
     */
    public function buildPricingSummary(object $product): array
    {
        $currency = !empty($product->currency) ? (string)$product->currency : $this->getSiteCurrency();
        $orig = number_format((float)$product->original_price, 2, '.', '');
        $final = number_format((float)$product->final_price, 2, '.', '');
        $discountPct = number_format((float)$product->discount_percent, 2, '.', '');
        $hasDiscount = ((float)$discountPct > 0.0) && ((float)$orig > (float)$final);
        $isFree = !empty($product->is_free) || ((float)$final === 0.0);

        return [
            'currency'                 => $currency,
            'original_price'           => $orig,
            'final_price'              => $final,
            'discount_percent'         => $discountPct,
            'has_discount'             => $hasDiscount,
            'is_free'                  => $isFree,
            'formatted_original_price' => $this->formatPrice($orig, $currency),
            'formatted_final_price'    => $this->formatPrice($final, $currency),
        ];
    }

    /**
     * Format money with authoritative currency symbol.
     */
    public function formatPrice(string|float $amount, ?string $currency = null): string
    {
        $curr = $currency ? strtoupper(trim($currency)) : $this->getSiteCurrency();
        $symbol = '$';
        $decimals = 2;

        if (class_exists(Currency::class)) {
            try {
                $info = Currency::get($curr);
                $symbol = $info['symbol'] ?? $curr . ' ';
                $decimals = $info['decimals'] ?? 2;
            } catch (Throwable) {
                $symbol = $curr . ' ';
            }
        } elseif ($curr === 'BDT') {
            $symbol = '৳';
        }

        $formattedNum = number_format((float)$amount, $decimals, '.', ',');
        return $symbol . $formattedNum;
    }

    public function getSiteCurrency(): string
    {
        if (class_exists(Currency::class)) {
            try {
                return Currency::getPrimaryCurrency();
            } catch (Throwable) {
                // Fallback
            }
        }
        return 'BDT';
    }

    protected function formatFileSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int)floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        $val = round($bytes / pow(1024, $power), 2);
        return $val . ' ' . $units[$power];
    }
}
