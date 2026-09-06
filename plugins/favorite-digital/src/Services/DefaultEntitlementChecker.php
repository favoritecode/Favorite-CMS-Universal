<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Services;

use DateTimeImmutable;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\Contracts\EntitlementCheckerInterface;
use FavoriteCMS\Digital\Repositories\EntitlementRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use Throwable;

/**
 * DefaultEntitlementChecker
 *
 * Checks active entitlements and membership-based access rights.
 * Distinguishes active, revoked, and expired entitlement states.
 */
class DefaultEntitlementChecker implements EntitlementCheckerInterface
{
    protected ?Database $db;
    protected ?EntitlementRepository $entitlementRepo;
    protected ?MembershipLifecycleService $membershipService;
    protected ?ProductRepository $productRepo;

    public function __construct(
        ?Database $db = null,
        ?EntitlementRepository $entitlementRepo = null,
        ?MembershipLifecycleService $membershipService = null,
        ?ProductRepository $productRepo = null
    ) {
        $this->db = $db;
        $this->entitlementRepo = $entitlementRepo;
        $this->membershipService = $membershipService;
        $this->productRepo = $productRepo;
    }

    /**
     * Check if a user currently holds an active entitlement for a product
     * (either from direct purchase or package).
     */
    public function hasActiveEntitlement(int $userId, int $productId): bool
    {
        if ($userId <= 0 || $productId <= 0) {
            return false;
        }

        $nowStr = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($this->entitlementRepo !== null) {
            return $this->entitlementRepo->findActiveEntitlement($userId, $productId, $nowStr) !== null;
        }

        if ($this->db === null) {
            return false;
        }

        try {
            $row = $this->db->selectOne(
                "SELECT `id`, `status` FROM `favorite_digital_entitlements`
                 WHERE `user_id` = ? AND `product_id` = ? AND `status` = 'active'
                   AND (`expires_at` IS NULL OR `expires_at` > ?)
                 LIMIT 1",
                [$userId, $productId, $nowStr]
            );
            return $row !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Determine if a user has access to a product.
     * Access is granted if:
     * 1. The user holds an active entitlement (purchase or package), OR
     * 2. The product is membership-eligible and the user holds an active membership.
     */
    public function hasAccess(int $userId, int $productId): bool
    {
        if ($userId <= 0 || $productId <= 0) {
            return false;
        }

        // 1. Check direct or package entitlement first
        if ($this->hasActiveEntitlement($userId, $productId)) {
            return true;
        }

        // 2. Check membership-derived dynamic access
        if ($this->membershipService !== null) {
            return $this->membershipService->isEligibleForMembershipContent($userId, $productId);
        }

        // Fallback direct database check if membershipService was not injected
        if ($this->db !== null) {
            try {
                $nowStr = (new DateTimeImmutable())->format('Y-m-d H:i:s');
                $det = $this->db->selectOne(
                    "SELECT `is_membership_eligible` FROM `favorite_digital_product_details` WHERE `product_id` = ? LIMIT 1",
                    [$productId]
                );
                if ($det && !empty($det->is_membership_eligible)) {
                    $mem = $this->db->selectOne(
                        "SELECT `id` FROM `favorite_digital_memberships`
                         WHERE `user_id` = ?
                           AND `status` IN ('active', 'grace', 'cancelled')
                           AND `expires_at` > ?
                         LIMIT 1",
                        [$userId, $nowStr]
                    );
                    if ($mem) {
                        return true;
                    }
                }
            } catch (Throwable) {
            }
        }

        return false;
    }

    /**
     * Check if an entitlement was revoked for this product.
     */
    public function isRevoked(int $userId, int $productId): bool
    {
        if ($this->db === null) {
            return false;
        }

        try {
            $row = $this->db->selectOne(
                "SELECT `id` FROM `favorite_digital_entitlements`
                 WHERE `user_id` = ? AND `product_id` = ? AND `status` = 'revoked'
                 ORDER BY `id` DESC LIMIT 1",
                [$userId, $productId]
            );
            return $row !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Determine if a user is allowed to repurchase a product they already own.
     */
    public function allowDuplicatePurchase(int $userId, int $productId): bool
    {
        // Default behavior: allow unless specific business rules forbid it
        return true;
    }
}
