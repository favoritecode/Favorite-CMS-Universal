<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Exceptions;

use Exception;

class RefundException extends Exception
{
    public static function orderNotFound(int|string $orderId): self
    {
        return new self("Order #{$orderId} was not found.");
    }

    public static function orderNotEligible(string $orderNumber, string $reason): self
    {
        return new self("Order #{$orderNumber} is not eligible for refund: {$reason}");
    }

    public static function alreadyRefunded(string $orderNumber): self
    {
        return new self("Order #{$orderNumber} has already been refunded.");
    }

    public static function noVerifiedPayment(string $orderNumber): self
    {
        return new self("Order #{$orderNumber} has no verified payments to refund.");
    }

    public static function invalidAmount(string $amount): self
    {
        return new self("Invalid refund amount '{$amount}'. Amount must be a positive numeric value.");
    }

    public static function invalidReason(string $reason): self
    {
        return new self("A valid business reason is required to process a refund: {$reason}");
    }

    public static function unauthorized(string $orderNumber): self
    {
        return new self("Unauthorized refund attempt on Order #{$orderNumber}.");
    }

    public static function partialRefundNotSupported(): self
    {
        return new self("Partial item-level refunds are not currently supported. Only full order refunds are allowed.");
    }

    public static function membershipPolicyRestriction(string $message): self
    {
        return new self("Membership refund restriction: {$message}");
    }
}
