<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Services;

use DateTimeImmutable;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\Domain\MembershipStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Repositories\EntitlementRepository;
use FavoriteCMS\Digital\Repositories\OrderRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Repositories\RefundRepository;
use FavoriteCMS\Digital\Repositories\WalletRepository;
use Throwable;

/**
 * CustomerAccountService
 *
 * Core service orchestrating the Customer Account & Digital Library experience:
 * - Customer Digital Library with UI-level entitlement deduplication
 * - Multi-type catalog access (digital, service, package, membership perks)
 * - Authoritative access states (accessible, revoked, expired, membership_expired)
 * - Secure download integration reusing Phase 5D download tokens and limits
 * - Customer Membership Dashboard (active, grace, auto-renewal, covered perks)
 * - Customer Refund History with strict Wallet destination
 * - Customer Digital Wallet summary
 * - Customer Order History & Detail with ownership verification
 */
class CustomerAccountService
{
    protected EntitlementRepository $entitlementRepo;
    protected ProductRepository $productRepo;
    protected OrderRepository $orderRepo;
    protected OrderService $orderService;
    protected MembershipLifecycleService $membershipService;
    protected DownloadService $downloadService;
    protected RefundRepository $refundRepo;
    protected WalletService $walletService;
    protected ?Database $db;

    public function __construct(
        EntitlementRepository $entitlementRepo,
        ProductRepository $productRepo,
        OrderRepository $orderRepo,
        OrderService $orderService,
        MembershipLifecycleService $membershipService,
        DownloadService $downloadService,
        RefundRepository $refundRepo,
        WalletService $walletService,
        ?Database $db = null
    ) {
        $this->entitlementRepo = $entitlementRepo;
        $this->productRepo = $productRepo;
        $this->orderRepo = $orderRepo;
        $this->orderService = $orderService;
        $this->membershipService = $membershipService;
        $this->downloadService = $downloadService;
        $this->refundRepo = $refundRepo;
        $this->walletService = $walletService;
        $this->db = $db ?? $orderRepo->getDatabase();
    }

    public function getEntitlementRepository(): EntitlementRepository
    {
        return $this->entitlementRepo;
    }

    public function getProductRepository(): ProductRepository
    {
        return $this->productRepo;
    }

    public function getOrderRepository(): OrderRepository
    {
        return $this->orderRepo;
    }

    public function getOrderService(): OrderService
    {
        return $this->orderService;
    }

    public function getMembershipService(): MembershipLifecycleService
    {
        return $this->membershipService;
    }

    public function getDownloadService(): DownloadService
    {
        return $this->downloadService;
    }

    public function getRefundRepository(): RefundRepository
    {
        return $this->refundRepo;
    }

    public function getWalletService(): WalletService
    {
        return $this->walletService;
    }

    /**
     * Compile the customer's digital library with UI-level entitlement deduplication.
     */
    public function getDigitalLibrary(int $userId, array $filters = [], int $page = 1, int $perPage = 12): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $nowStr = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        // 1. Fetch raw entitlements belonging to user
        $rawEntitlements = $this->entitlementRepo->getEntitlementsByUser($userId);

        // 2. Check active membership status
        $activeMembership = $this->membershipService->getActiveMembership($userId);
        $hasActiveMembership = ($activeMembership !== null);

        // 3. If user has active membership, fetch published membership-eligible digital goods
        $membershipProducts = [];
        if ($hasActiveMembership) {
            $memCatalog = $this->productRepo->listStorefrontProducts([
                'product_type' => ProductType::DIGITAL,
                'membership'   => 'eligible',
            ], 1, 500);
            $membershipProducts = $memCatalog['items'] ?? [];
        }

        // 4. Group and deduplicate items by product_id
        /** @var array<int, array> $grouped */
        $grouped = [];

        foreach ($rawEntitlements as $ent) {
            $pid = (int)$ent->product_id;
            $product = $this->productRepo->findProduct($pid);
            if (!$product) {
                continue;
            }

            if (!isset($grouped[$pid])) {
                $grouped[$pid] = [
                    'product_id'             => $pid,
                    'title'                  => (string)$product->title,
                    'slug'                   => (string)$product->slug,
                    'product_type'           => (string)$product->product_type,
                    'description'            => (string)($product->description ?? ''),
                    'sources'                => [],
                    'source_types'           => [],
                    'entitlements'           => [],
                    'has_active'             => false,
                    'has_revoked'            => false,
                    'has_expired'            => false,
                    'is_membership_covered'  => false,
                    'primary_entitlement_id' => (int)$ent->id,
                    'granted_at'             => $ent->granted_at,
                    'expires_at'             => $ent->expires_at,
                ];
            }

            $grouped[$pid]['entitlements'][] = $ent;
            $srcType = (string)($ent->source_type ?? 'direct');
            $canonicalType = match ($srcType) {
                'purchase', 'order', 'direct' => 'direct',
                'package'                     => 'package',
                'membership'                  => 'membership',
                default                       => $srcType,
            };
            if (!in_array($canonicalType, $grouped[$pid]['source_types'], true)) {
                $grouped[$pid]['source_types'][] = $canonicalType;
            }

            // Derive source label
            $sourceLabel = match ($canonicalType) {
                'direct'     => 'Direct Purchase',
                'package'    => 'Included in Package',
                'membership' => 'Membership Access',
                default      => 'Granted Access',
            };
            $hasSrc = false;
            foreach ($grouped[$pid]['sources'] as $sItem) {
                if (is_array($sItem) && ($sItem['type'] ?? '') === $canonicalType) {
                    $hasSrc = true;
                    break;
                }
            }
            if (!$hasSrc) {
                $grouped[$pid]['sources'][] = [
                    'type'  => $canonicalType,
                    'label' => $sourceLabel,
                ];
            }

            // Determine access status of this entitlement
            if ($ent->status === 'active') {
                if (!empty($ent->expires_at) && $ent->expires_at <= $nowStr) {
                    $grouped[$pid]['has_expired'] = true;
                } else {
                    $grouped[$pid]['has_active'] = true;
                    $grouped[$pid]['primary_entitlement_id'] = (int)$ent->id;
                    $grouped[$pid]['expires_at'] = $ent->expires_at;
                }
            } elseif ($ent->status === 'revoked') {
                $grouped[$pid]['has_revoked'] = true;
            } elseif ($ent->status === 'expired') {
                $grouped[$pid]['has_expired'] = true;
            }
        }

        // Merge membership-covered digital goods (if active member)
        foreach ($membershipProducts as $mp) {
            $pid = (int)$mp->id;
            if (!isset($grouped[$pid])) {
                $grouped[$pid] = [
                    'product_id'             => $pid,
                    'title'                  => (string)$mp->title,
                    'slug'                   => (string)$mp->slug,
                    'product_type'           => (string)$mp->product_type,
                    'description'            => (string)($mp->description ?? ''),
                    'sources'                => [['type' => 'membership', 'label' => 'Included with Membership']],
                    'source_types'           => ['membership'],
                    'entitlements'           => [],
                    'has_active'             => true,
                    'has_revoked'            => false,
                    'has_expired'            => false,
                    'is_membership_covered'  => true,
                    'primary_entitlement_id' => 0,
                    'granted_at'             => $nowStr,
                    'expires_at'             => $activeMembership->expires_at ?? null,
                ];
            } else {
                $grouped[$pid]['is_membership_covered'] = true;
                if (!in_array('membership', $grouped[$pid]['source_types'], true)) {
                    $grouped[$pid]['source_types'][] = 'membership';
                }
                $hasMemSrc = false;
                foreach ($grouped[$pid]['sources'] as $sItem) {
                    if (is_array($sItem) && ($sItem['type'] ?? '') === 'membership') {
                        $hasMemSrc = true;
                        break;
                    }
                }
                if (!$hasMemSrc) {
                    $grouped[$pid]['sources'][] = ['type' => 'membership', 'label' => 'Included with Membership'];
                }
                $grouped[$pid]['has_active'] = true;
            }
        }

        // 5. Enrich entries with authoritative state and type-specific specifications
        $allItems = [];
        $typeCounts = [
            'all'        => 0,
            'digital'    => 0,
            'service'    => 0,
            'package'    => 0,
            'membership' => 0,
        ];

        foreach ($grouped as $pid => $item) {
            // Determine unified access state
            if ($item['has_active']) {
                $item['state'] = 'accessible';
                $item['state_label'] = 'Active Access';
                $item['state_class'] = 'state-accessible';
            } elseif ($item['has_revoked'] && !$item['has_expired']) {
                $item['state'] = 'revoked';
                $item['state_label'] = 'Access Revoked';
                $item['state_class'] = 'state-revoked';
            } elseif ($item['has_expired']) {
                $item['state'] = 'expired';
                $item['state_label'] = 'Access Expired';
                $item['state_class'] = 'state-expired';
            } else {
                $item['state'] = 'unavailable';
                $item['state_label'] = 'Unavailable';
                $item['state_class'] = 'state-unavailable';
            }

            // If product type was membership-covered but user no longer has active membership
            if (!$hasActiveMembership && in_array('membership', $item['source_types'], true) && !$item['has_active']) {
                $item['state'] = 'membership_expired';
                $item['state_label'] = 'Membership Expired';
                $item['state_class'] = 'state-expired';
            }

            // State aliases for robust access
            $item['access_state'] = $item['state'];
            $item['status_label'] = $item['state_label'];
            $item['is_downloadable'] = false;
            $item['download_url'] = null;
            $item['download_token'] = null;
            $item['download_count'] = 0;
            $item['download_remaining'] = null;

            // Type-specific enrichment
            $pType = $item['product_type'];

            if ($pType === ProductType::DIGITAL) {
                $details = $this->productRepo->findProductDetails($pid);
                $resourceType = (string)($details->resource_type ?? 'file');
                $resourceUrl = (string)($details->resource_url ?? '');
                $hasUrlResource = in_array($resourceType, ['url', 'both'], true) && !empty($resourceUrl);
                $hasFileResource = in_array($resourceType, ['file', 'both'], true);

                $item['resource_type'] = $resourceType;
                $item['resource_url'] = $hasUrlResource ? $resourceUrl : null;
                $item['has_url_resource'] = $hasUrlResource;
                $item['has_file_resource'] = $hasFileResource;
                $item['external_resource_url'] = $hasUrlResource ? ('/account/resource/' . $pid) : null;

                $isUnlimited = ($hasActiveMembership && !empty($details->is_membership_eligible)) || $item['is_membership_covered'];
                $item['file_size_formatted'] = $details && !empty($details->file_size) ? $this->formatBytes((int)$details->file_size) : null;
                $item['file_extension'] = $details && !empty($details->file_name) ? strtoupper(pathinfo($details->file_name, PATHINFO_EXTENSION)) : ($resourceType === 'url' ? 'LINK' : 'FILE');
                $item['version'] = $details->version ?? null;
                $item['is_unlimited'] = $isUnlimited;

                if ($item['state'] === 'accessible') {
                    if ($hasFileResource) {
                        $tokenRecord = $this->downloadService->getOrCreateDownloadToken(
                            $userId,
                            $pid,
                            $item['primary_entitlement_id'] > 0 ? $item['primary_entitlement_id'] : null
                        );
                        $downloadCount = (int)$tokenRecord->download_count;
                        $maxLimit = DownloadService::MAX_PURCHASE_DOWNLOADS;
                        $remaining = max(0, $maxLimit - $downloadCount);

                        $item['download_token'] = (string)$tokenRecord->download_token;
                        $item['download_url'] = '/download/' . urlencode((string)$tokenRecord->download_token);
                        $item['download_count'] = $downloadCount;
                        $item['max_limit'] = $maxLimit;
                        $item['remaining'] = $remaining;
                        $item['download_remaining'] = $isUnlimited ? null : $remaining;

                        if (!$isUnlimited && $remaining <= 0) {
                            $item['is_exhausted'] = true;
                            $item['can_download'] = false;
                            $item['is_downloadable'] = false;
                            $item['action_label'] = 'Download Limit Reached';
                        } else {
                            $item['is_exhausted'] = false;
                            $item['can_download'] = true;
                            $item['is_downloadable'] = true;
                            $item['action_label'] = 'Download File';
                        }
                    } else {
                        // URL only: no file download
                        $item['download_url'] = null;
                        $item['download_token'] = null;
                        $item['download_count'] = 0;
                        $item['download_remaining'] = null;
                        $item['is_exhausted'] = false;
                        $item['can_download'] = false;
                        $item['is_downloadable'] = false;
                        $item['action_label'] = 'Access Online Resource';
                    }
                } else {
                    $item['download_url'] = null;
                    $item['download_token'] = null;
                    $item['can_download'] = false;
                    $item['is_downloadable'] = false;
                    $item['is_exhausted'] = false;
                    $item['action_label'] = match ($item['state']) {
                        'revoked'            => 'Access Revoked (Refunded)',
                        'expired'            => 'Access Expired',
                        'membership_expired' => 'Membership Expired',
                        default              => 'Access Unavailable',
                    };
                }
            } elseif ($pType === ProductType::SERVICE) {
                $serviceDetails = $this->productRepo->findServiceDetails($pid);
                $item['deliverables'] = $serviceDetails->deliverables ?? null;
                $item['delivery_time_days'] = $serviceDetails->delivery_time_days ?? null;
                $item['turnaround_days'] = isset($serviceDetails->delivery_time_days) ? (int)$serviceDetails->delivery_time_days : null;
                $item['service_scope'] = $serviceDetails->service_scope ?? $serviceDetails->deliverables ?? null;
                $item['requirements'] = $serviceDetails->requirements ?? null;
                $item['is_downloadable'] = false;
                $item['action_label'] = $item['state'] === 'accessible' ? 'Active Service' : 'Service ' . ucfirst($item['state']);
            } elseif ($pType === ProductType::PACKAGE) {
                $package = $this->productRepo->findPackageByProductId($pid);
                $packageId = $package ? (int)$package->id : $pid;
                $packageItems = $this->productRepo->getPackageItemsWithProducts($packageId);
                if (empty($packageItems) && $packageId !== $pid) {
                    $packageItems = $this->productRepo->getPackageItemsWithProducts($pid);
                }
                $item['included_items'] = $packageItems;
                $item['is_downloadable'] = false;
                $item['action_label'] = $item['state'] === 'accessible' ? 'Package Access' : 'Package ' . ucfirst($item['state']);
            } elseif ($pType === ProductType::MEMBERSHIP) {
                $plan = $this->productRepo->findMembershipPlanByProductId($pid);
                $item['plan'] = $plan;
                $item['is_downloadable'] = false;
                $item['action_label'] = $item['state'] === 'accessible' ? 'Membership Active' : 'Membership ' . ucfirst($item['state']);
            }

            // Update category counts
            $typeCounts['all']++;
            if (isset($typeCounts[$pType])) {
                $typeCounts[$pType]++;
            }
            if (!empty($item['is_membership_covered'])) {
                $typeCounts['membership']++;
            }

            $allItems[] = $item;
        }

        // 6. Apply Filters
        $filtered = $allItems;

        // Type filter
        $typeFilter = strtolower(trim((string)($filters['product_type'] ?? '')));
        if ($typeFilter !== '' && $typeFilter !== 'all') {
            if ($typeFilter === 'membership') {
                $filtered = array_filter($filtered, fn($i) => !empty($i['is_membership_covered']) || $i['product_type'] === ProductType::MEMBERSHIP);
            } else {
                $filtered = array_filter($filtered, fn($i) => $i['product_type'] === $typeFilter);
            }
        }

        // Status filter
        $statusFilter = strtolower(trim((string)($filters['status'] ?? '')));
        if ($statusFilter !== '' && $statusFilter !== 'all') {
            $filtered = array_filter($filtered, fn($i) => $i['state'] === $statusFilter);
        }

        // Search term filter
        $searchTerm = strtolower(trim((string)($filters['search'] ?? $filters['q'] ?? '')));
        if ($searchTerm !== '') {
            $filtered = array_filter($filtered, function ($i) use ($searchTerm) {
                return str_contains(strtolower($i['title']), $searchTerm)
                    || str_contains(strtolower($i['description']), $searchTerm);
            });
        }

        // Re-index array after filtering
        $filtered = array_values($filtered);
        $total = count($filtered);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        $paginatedItems = array_slice($filtered, $offset, $perPage);

        return [
            'items'       => $paginatedItems,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
            'type_counts' => $typeCounts,
            'filters'     => $filters,
        ];
    }

    /**
     * Retrieve the Customer's Membership Dashboard details.
     */
    public function getMembershipDashboard(int $userId): array
    {
        $nowStr = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $activeMembership = $this->membershipService->getActiveMembership($userId);
        $allMemberships = $this->productRepo->listMembershipsForUser($userId);

        // Fetch membership-covered digital products
        $coveredPerks = [];
        if ($activeMembership !== null) {
            $perksResult = $this->productRepo->listStorefrontProducts([
                'product_type' => ProductType::DIGITAL,
                'membership'   => 'eligible',
            ], 1, 50);
            $coveredPerks = $perksResult['items'] ?? [];
        }

        $planTitle = null;
        if ($activeMembership !== null) {
            $plan = $this->membershipService->getPlan((int)$activeMembership->plan_id);
            if ($plan && !empty($plan->product_id)) {
                $prod = $this->productRepo->findProduct((int)$plan->product_id);
                if ($prod) {
                    $planTitle = (string)$prod->title;
                    $activeMembership->plan_title = $planTitle;
                    $activeMembership->product_title = $planTitle;
                }
            }
        }

        $wallet = $this->getWalletSummary($userId);

        $status = $activeMembership ? (string)$activeMembership->status : 'none';
        $statusLabel = match ($status) {
            'active' => 'Active',
            'grace' => 'Grace Period',
            'expired' => 'Expired',
            'cancelled' => 'Cancelled',
            default => 'None',
        };

        return [
            'active_membership'   => $activeMembership,
            'has_active'          => $activeMembership !== null,
            'has_membership'      => $activeMembership !== null,
            'status'              => $status,
            'status_label'        => $statusLabel,
            'plan_title'          => $activeMembership ? ($activeMembership->plan_title ?? $activeMembership->product_title ?? 'Membership') : null,
            'is_in_grace'         => $activeMembership ? ($status === 'grace') : false,
            'grace_period_ends_at'=> $activeMembership ? ($activeMembership->grace_expires_at ?? null) : null,
            'auto_renew'          => $activeMembership ? !empty($activeMembership->auto_renew) : false,
            'all_memberships'     => $allMemberships,
            'covered_perks'       => $coveredPerks,
            'wallet'              => $wallet,
            'site_currency'       => $wallet['currency'] ?? 'BDT',
        ];
    }

    /**
     * Retrieve customer's refund history with strict Wallet destination.
     */
    public function getRefundHistory(int $userId, int $page = 1, int $perPage = 15): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $refundsData = $this->refundRepo->listRefundsForUser($userId, $page, $perPage);

        $totalRefunded = 0.0;
        foreach ($refundsData['data'] as &$ref) {
            // Guarantee destination is Favorite Digital Wallet per Phase 5E locked rule
            $ref->destination = 'Favorite Digital Wallet';
            $ref->destination_name = 'Favorite Digital Wallet';

            // Enrich with order number
            if (!empty($ref->order_id)) {
                $order = $this->orderRepo->findOrder((int)$ref->order_id);
                $ref->order_number = $order ? (string)$order->order_number : 'Order #' . $ref->order_id;
            } else {
                $ref->order_number = 'N/A';
            }

            if ($ref->status === 'completed') {
                $totalRefunded += (float)$ref->refund_amount;
            }
        }
        unset($ref);

        return [
            'refunds'        => $refundsData['data'],
            'total'          => $refundsData['total'],
            'page'           => $refundsData['page'],
            'per_page'       => $refundsData['per_page'],
            'total_pages'    => $refundsData['total_pages'],
            'total_refunded' => number_format($totalRefunded, 2, '.', ''),
            'wallet'         => $this->getWalletSummary($userId),
        ];
    }

    /**
     * Retrieve Digital Wallet summary.
     */
    public function getWalletSummary(int $userId): array
    {
        if ($userId <= 0) {
            return [
                'balance'  => '0.00',
                'currency' => 'BDT',
            ];
        }

        $balance = $this->walletService->getBalance($userId);
        $wallet = $this->walletService->getWalletRepository()->getOrCreateWallet($userId);

        return [
            'balance'  => $balance,
            'currency' => (string)($wallet->currency ?? 'BDT'),
        ];
    }

    /**
     * Retrieve Customer's Order History.
     */
    public function getOrderHistory(int $userId, int $page = 1, int $perPage = 15): array
    {
        return $this->orderService->listUserOrders($userId, $page, $perPage);
    }

    /**
     * Retrieve Customer's Order Detail with strict ownership validation.
     */
    public function getOrderDetail(int $userId, string $orderNumber): ?object
    {
        $order = $this->orderService->getOrderByNumber($orderNumber);
        if (!$order) {
            return null;
        }

        // Ownership enforcement
        if ((int)$order->user_id !== $userId) {
            return null;
        }

        $refunds = $this->refundRepo->findRefundsByOrderId((int)$order->id);
        foreach ($refunds as &$ref) {
            $ref->destination = 'Favorite Digital Wallet';
            $ref->destination_name = 'Favorite Digital Wallet';
        }
        unset($ref);
        $order->refunds = $refunds;
        $order->entitlements = $this->entitlementRepo->getEntitlementsByOrder((int)$order->id);

        return $order;
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int)floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    /**
     * Authorize access to an external resource URL for a digital product.
     *
     * Validates:
     * 1. User authentication.
     * 2. Digital product existence and resource_type supports external URL ('url' or 'both').
     * 3. Valid safe HTTP/HTTPS URL scheme.
     * 4. User entitlement (direct purchase, package, or active membership coverage).
     * 5. Revocation and expiry status.
     *
     * NOTE: Does NOT exhaust file download quotas.
     *
     * @param int $userId
     * @param int $productId
     * @return string Validated external resource URL
     * @throws \RuntimeException|\InvalidArgumentException If access denied or resource invalid
     */
    public function authorizeExternalResource(int $userId, int $productId): string
    {
        if ($userId <= 0) {
            throw new \RuntimeException('Authentication required to access this resource.', 401);
        }

        if ($productId <= 0) {
            throw new \InvalidArgumentException('Invalid product ID.', 400);
        }

        $product = $this->productRepo->findProduct($productId);
        if (!$product) {
            throw new \RuntimeException('Product not found.', 404);
        }

        if ($product->product_type !== ProductType::DIGITAL) {
            throw new \RuntimeException('Only digital products provide online resources.', 400);
        }

        $details = $this->productRepo->findProductDetails($productId);
        if (!$details) {
            throw new \RuntimeException('Resource details not found.', 404);
        }

        $resourceType = (string)($details->resource_type ?? 'file');
        if (!in_array($resourceType, ['url', 'both'], true) || empty($details->resource_url)) {
            throw new \RuntimeException('No external resource configured for this product.', 404);
        }

        $safeUrl = trim((string)$details->resource_url);
        $parsed = parse_url($safeUrl);
        $scheme = strtolower((string)($parsed['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || empty($parsed['host'])) {
            throw new \RuntimeException('Invalid or unsafe external resource URL.', 400);
        }

        // 1. Check direct/package active entitlement
        $activeEnt = $this->entitlementRepo->findActiveEntitlement($userId, $productId);
        if ($activeEnt) {
            return $safeUrl;
        }

        // 2. Check active membership access if membership-eligible
        $hasActiveMembership = $this->membershipService->hasActiveMembership($userId);
        $isMembershipEligible = !empty($details->is_membership_eligible);
        if ($hasActiveMembership && $isMembershipEligible) {
            return $safeUrl;
        }

        // 3. If no active entitlement or membership, check if revoked, expired, or unauthorized
        $rawEntitlements = $this->entitlementRepo->getEntitlementsByUser($userId);
        $userEntitlementsForProduct = array_filter($rawEntitlements, fn($e) => (int)$e->product_id === $productId);

        if (!empty($userEntitlementsForProduct)) {
            $nowStr = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            foreach ($userEntitlementsForProduct as $ent) {
                if ($ent->status === 'revoked') {
                    throw new \RuntimeException('Access has been revoked for this item (order refunded).', 403);
                }
                if ($ent->status === 'expired' || (!empty($ent->expires_at) && $ent->expires_at <= $nowStr)) {
                    throw new \RuntimeException('Access to this resource has expired.', 403);
                }
            }
        }

        if ($isMembershipEligible && !$hasActiveMembership) {
            throw new \RuntimeException('An active membership is required to access this resource.', 403);
        }

        throw new \RuntimeException('You do not have entitlement to access this resource.', 403);
    }
}
