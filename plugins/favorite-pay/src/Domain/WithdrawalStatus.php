<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Domain;

enum WithdrawalStatus: string
{
    case PENDING    = 'pending';
    case APPROVED   = 'approved';
    case PROCESSING = 'processing';
    case PAID       = 'paid';
    case REJECTED   = 'rejected';
    case FAILED     = 'failed';
    case CANCELLED  = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [
            self::PAID,
            self::REJECTED,
            self::FAILED,
            self::CANCELLED,
        ], true);
    }

    public function canTransitionTo(WithdrawalStatus $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::PENDING => in_array($target, [
                self::APPROVED,
                self::REJECTED,
                self::CANCELLED,
            ], true),

            self::APPROVED => in_array($target, [
                self::PROCESSING,
                self::REJECTED,
                self::CANCELLED,
            ], true),

            self::PROCESSING => in_array($target, [
                self::PAID,
                self::FAILED,
            ], true),

            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING    => 'Pending Review',
            self::APPROVED   => 'Approved',
            self::PROCESSING => 'Processing',
            self::PAID       => 'Paid',
            self::REJECTED   => 'Rejected',
            self::FAILED     => 'Failed',
            self::CANCELLED  => 'Cancelled',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING    => 'fpay-badge-warning',
            self::APPROVED   => 'fpay-badge-info',
            self::PROCESSING => 'fpay-badge-info',
            self::PAID       => 'fpay-badge-success',
            self::REJECTED   => 'fpay-badge-danger',
            self::FAILED     => 'fpay-badge-danger',
            self::CANCELLED  => 'fpay-badge-secondary',
        };
    }
}
