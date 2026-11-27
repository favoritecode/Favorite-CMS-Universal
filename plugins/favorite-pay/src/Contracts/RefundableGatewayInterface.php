<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

use FavoriteCMS\Pay\Domain\GatewayRefundResult;
use FavoriteCMS\Pay\Domain\Money;

/**
 * Capability contract for payment gateways supporting automated provider refunds.
 */
interface RefundableGatewayInterface
{
    /**
     * Execute an automated refund with the payment gateway provider.
     */
    public function refund(string $transactionId, Money $amount, string $reason = ''): GatewayRefundResult;
}
