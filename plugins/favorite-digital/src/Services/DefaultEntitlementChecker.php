<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\Contracts\EntitlementCheckerInterface;
use Throwable;

/**
 * DefaultEntitlementChecker
 *
 * Default entitlement checker implementation.
 * Defensively queries favorite_digital_entitlements if present, or allows repurchases.
 */
class DefaultEntitlementChecker implements EntitlementCheckerInterface
{
    protected ?Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db;
    }

    public function hasActiveEntitlement(int $userId, int $productId): bool
    {
        if ($this->db === null) {
            return false;
        }

        try {
            $row = $this->db->selectOne(
                "SELECT `id`, `status` FROM `favorite_digital_entitlements` WHERE `user_id` = ? AND `product_id` = ? AND `status` = 'active' LIMIT 1",
                [$userId, $productId]
            );
            return $row !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public function allowDuplicatePurchase(int $userId, int $productId): bool
    {
        // Default behavior: allow unless specific business rules forbid it
        return true;
    }
}
