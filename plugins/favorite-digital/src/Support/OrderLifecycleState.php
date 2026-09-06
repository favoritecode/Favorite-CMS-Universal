<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Support;

/**
 * OrderLifecycleState
 *
 * Defines the orthogonal payment and fulfillment states for Favorite Digital orders.
 *
 * Progression:
 * 1. Pending Payment:
 *    payment_status = pending, fulfillment_status = unfulfilled, status = pending
 * 2. Verified Successful Payment:
 *    payment_status = paid, fulfillment_status = unfulfilled, status = processing
 * 3. Access Granted / Fulfilled:
 *    payment_status = paid, fulfillment_status = fulfilled, status = completed
 * 4. Failed Payment:
 *    payment_status = failed, fulfillment_status = unfulfilled, status = failed
 * 5. Refunded / Revoked:
 *    payment_status = refunded, fulfillment_status = revoked, status = refunded
 */
final class OrderLifecycleState
{
    // Payment Statuses
    public const PAYMENT_UNPAID         = 'unpaid';
    public const PAYMENT_PENDING        = 'pending';
    public const PAYMENT_PARTIALLY_PAID = 'partially_paid';
    public const PAYMENT_PAID           = 'paid';
    public const PAYMENT_FAILED         = 'failed';
    public const PAYMENT_REFUNDED       = 'refunded';

    // Fulfillment Statuses
    public const FULFILLMENT_UNFULFILLED         = 'unfulfilled';
    public const FULFILLMENT_PARTIALLY_FULFILLED = 'partially_fulfilled';
    public const FULFILLMENT_FULFILLED           = 'fulfilled';
    public const FULFILLMENT_CANCELLED           = 'cancelled';
    public const FULFILLMENT_REVOKED             = 'revoked';

    // Overall Aggregate Statuses
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';
    public const STATUS_REFUNDED   = 'refunded';

    public static function allStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_REFUNDED,
        ];
    }

    public static function allPaymentStatuses(): array
    {
        return [
            self::PAYMENT_UNPAID,
            self::PAYMENT_PENDING,
            self::PAYMENT_PARTIALLY_PAID,
            self::PAYMENT_PAID,
            self::PAYMENT_FAILED,
            self::PAYMENT_REFUNDED,
        ];
    }

    public static function allFulfillmentStatuses(): array
    {
        return [
            self::FULFILLMENT_UNFULFILLED,
            self::FULFILLMENT_PARTIALLY_FULFILLED,
            self::FULFILLMENT_FULFILLED,
            self::FULFILLMENT_CANCELLED,
            self::FULFILLMENT_REVOKED,
        ];
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::allStatuses(), true);
    }

    public static function isValidPaymentStatus(string $status): bool
    {
        return in_array($status, self::allPaymentStatuses(), true);
    }

    public static function isValidFulfillmentStatus(string $status): bool
    {
        return in_array($status, self::allFulfillmentStatuses(), true);
    }

    /**
     * Build state payload on payment success.
     * Note: fulfillment remains unfulfilled until access/entitlements are created.
     */
    public static function onPaymentSuccess(): array
    {
        return [
            'payment_status'     => self::PAYMENT_PAID,
            'fulfillment_status' => self::FULFILLMENT_UNFULFILLED,
            'status'             => self::STATUS_PROCESSING,
        ];
    }

    /**
     * Build state payload on fulfillment completion.
     */
    public static function onFulfillmentSuccess(): array
    {
        return [
            'payment_status'     => self::PAYMENT_PAID,
            'fulfillment_status' => self::FULFILLMENT_FULFILLED,
            'status'             => self::STATUS_COMPLETED,
        ];
    }

    /**
     * Build state payload on payment failure.
     */
    public static function onPaymentFailure(): array
    {
        return [
            'payment_status'     => self::PAYMENT_FAILED,
            'fulfillment_status' => self::FULFILLMENT_UNFULFILLED,
            'status'             => self::STATUS_FAILED,
        ];
    }

    /**
     * Build state payload on refund execution (access revoked, wallet credited).
     */
    public static function onRefundExecuted(): array
    {
        return [
            'payment_status'     => self::PAYMENT_REFUNDED,
            'fulfillment_status' => self::FULFILLMENT_REVOKED,
            'status'             => self::STATUS_REFUNDED,
        ];
    }
}
