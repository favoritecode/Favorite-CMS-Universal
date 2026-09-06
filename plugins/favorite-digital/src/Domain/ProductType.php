<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Domain;

/**
 * Supported Product Types for Favorite Digital.
 */
final class ProductType
{
    public const DIGITAL    = 'digital';
    public const SERVICE    = 'service';
    public const PACKAGE    = 'package';
    public const MEMBERSHIP = 'membership';

    /**
     * Currently supported product types in Phase 3.
     */
    public const ACTIVE_TYPES = [
        self::DIGITAL,
        self::SERVICE,
        self::PACKAGE,
        self::MEMBERSHIP,
    ];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ACTIVE_TYPES, true);
    }

    /**
     * Determine if a product type is eligible to be included inside a package/bundle.
     * Packages and memberships are strictly forbidden from being included.
     */
    public static function canBeIncludedInPackage(string $type): bool
    {
        return in_array($type, [self::DIGITAL, self::SERVICE], true);
    }
}

