<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Domain;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case AWAITING_VERIFICATION = 'awaiting_verification';
    case PROCESSING = 'processing';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';
    case PARTIALLY_REFUNDED = 'partially_refunded';

    public function isFinal(): bool
    {
        return in_array($this, [
            self::SUCCEEDED,
            self::FAILED,
            self::CANCELLED,
            self::REFUNDED,
        ], true);
    }

    public function canTransitionTo(PaymentStatus $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::PENDING => in_array($target, [
                self::AWAITING_VERIFICATION,
                self::PROCESSING,
                self::CANCELLED,
                self::FAILED,
                self::SUCCEEDED,
            ], true),

            self::AWAITING_VERIFICATION => in_array($target, [
                self::PROCESSING,
                self::SUCCEEDED,
                self::FAILED,
                self::CANCELLED,
            ], true),

            self::PROCESSING => in_array($target, [
                self::SUCCEEDED,
                self::FAILED,
                self::AWAITING_VERIFICATION,
                self::CANCELLED,
            ], true),

            self::SUCCEEDED => in_array($target, [
                self::PARTIALLY_REFUNDED,
                self::REFUNDED,
            ], true),

            self::PARTIALLY_REFUNDED => in_array($target, [
                self::REFUNDED,
                self::PARTIALLY_REFUNDED,
            ], true),

            self::FAILED, self::CANCELLED, self::REFUNDED => false,
        };
    }
}
