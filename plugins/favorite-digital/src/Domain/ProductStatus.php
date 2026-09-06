<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Domain;

/**
 * Product Lifecycle Statuses for Favorite Digital.
 */
final class ProductStatus
{
    public const DRAFT     = 'draft';
    public const PUBLISHED = 'published';
    public const ARCHIVED  = 'archived';

    public const ALL = [
        self::DRAFT,
        self::PUBLISHED,
        self::ARCHIVED,
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}

