<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Contracts;

/**
 * EntitlementCheckerInterface
 *
 * Boundary interface for checking active entitlements and duplicate purchase rules.
 * This establishes the hook for Phase 6 Entitlements without premature coupling.
 */
interface EntitlementCheckerInterface
{
    /**
     * Check if a user currently holds an active entitlement for a product.
     */
    public function hasActiveEntitlement(int $userId, int $productId): bool;

    /**
     * Determine if a user is allowed to repurchase a product they already own.
     */
    public function allowDuplicatePurchase(int $userId, int $productId): bool;
}
