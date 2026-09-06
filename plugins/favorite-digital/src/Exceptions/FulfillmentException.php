<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Exceptions;

use RuntimeException;
use Throwable;

class FulfillmentException extends RuntimeException
{
    public static function orderNotFound(int|string $orderId): self
    {
        return new self("Order not found: '{$orderId}'.");
    }

    public static function orderNotEligible(string $orderNumber, string $reason): self
    {
        return new self("Order '{$orderNumber}' is not eligible for fulfillment: {$reason}.");
    }

    public static function unauthorized(string $orderNumber, int $userId): self
    {
        return new self("User {$userId} is not authorized to fulfill order '{$orderNumber}'.");
    }

    public static function itemFulfillmentFailed(int $itemId, string $reason, ?Throwable $prev = null): self
    {
        return new self("Failed to fulfill order item #{$itemId}: {$reason}.", 0, $prev);
    }

    public static function alreadyFulfilled(string $orderNumber): self
    {
        return new self("Order '{$orderNumber}' is already fully fulfilled.");
    }
}
