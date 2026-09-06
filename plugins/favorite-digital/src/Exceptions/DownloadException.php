<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Exceptions;

use RuntimeException;
use Throwable;

class DownloadException extends RuntimeException
{
    public static function unauthenticated(): self
    {
        return new self("Authentication required to access download.");
    }

    public static function accessDenied(string $reason = "Access denied."): self
    {
        return new self($reason);
    }

    public static function invalidToken(): self
    {
        return new self("Invalid or expired download token.");
    }

    public static function entitlementNotFound(): self
    {
        return new self("No valid entitlement found for this product.");
    }

    public static function entitlementRevoked(): self
    {
        return new self("Entitlement for this product has been revoked.");
    }

    public static function entitlementExpired(): self
    {
        return new self("Entitlement for this product has expired.");
    }

    public static function downloadLimitReached(int $max = 3): self
    {
        return new self("Download limit reached. Maximum allowed downloads: {$max}.");
    }

    public static function membershipRequired(): self
    {
        return new self("An active membership is required to access this file.");
    }

    public static function membershipExpired(): self
    {
        return new self("Your membership has expired. Please renew to access this file.");
    }

    public static function fileNotFound(string $reason = "File unavailable."): self
    {
        return new self($reason);
    }

    public static function pathTraversalDetected(): self
    {
        return new self("Invalid file path requested.");
    }
}
