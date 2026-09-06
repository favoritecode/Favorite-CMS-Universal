<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Domain;

/**
 * Membership Status Domain Constants & Enums.
 *
 * Locked statuses:
 * - active: Membership is currently paid and valid.
 * - grace: Payment/renewal failed; customer is in grace window with pending recovery.
 * - expired: Paid period and grace period have ended; access is revoked.
 * - cancelled: Auto-renewal cancelled; paid time is preserved until expires_at.
 */
final class MembershipStatus
{
    public const ACTIVE    = 'active';
    public const GRACE     = 'grace';
    public const EXPIRED   = 'expired';
    public const CANCELLED = 'cancelled';

    public const ALL = [
        self::ACTIVE,
        self::GRACE,
        self::EXPIRED,
        self::CANCELLED,
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /**
     * Determine if status grants store access to membership-required products.
     * Note: Grace period maintains access while recovery is attempted.
     */
    public static function isAccessible(string $status): bool
    {
        return in_array($status, [self::ACTIVE, self::GRACE, self::CANCELLED], true);
    }
}