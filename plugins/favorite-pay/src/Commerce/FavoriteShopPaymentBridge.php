<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Commerce;

/**
 * Narrow integration contract for the separate Favorite Shop plugin.
 * COD is an order-collection method, not an online payment gateway.
 * It must not create a successful Favorite Pay transaction or credit a wallet.
 */
final class FavoriteShopPaymentBridge
{
    public const METHOD_ID = 'cash_on_delivery';

    /** @return list<array{id:string,label:string,kind:string,settlement:string,marks_paid:bool,requires_delivery_address:bool}> */
    public static function methods(): array
    {
        return [[
            'id' => self::METHOD_ID,
            'label' => 'Cash on Delivery (COD)',
            'kind' => 'offline_collection',
            'settlement' => 'on_delivery',
            'marks_paid' => false,
            'requires_delivery_address' => true,
        ]];
    }

    public static function isCashOnDelivery(string $methodId): bool
    {
        return strtolower(trim($methodId)) === self::METHOD_ID;
    }

    /** COD must remain pending/unpaid until Favorite Shop records collected payment. */
    public static function initialOrderPaymentState(string $methodId): ?array
    {
        if (!self::isCashOnDelivery($methodId)) {
            return null;
        }
        return [
            'payment_method' => self::METHOD_ID,
            'payment_status' => 'pending',
            'order_status' => 'pending',
            'paid_amount' => 0,
            'settlement_due' => true,
            'wallet_credit' => 0,
        ];
    }
}
