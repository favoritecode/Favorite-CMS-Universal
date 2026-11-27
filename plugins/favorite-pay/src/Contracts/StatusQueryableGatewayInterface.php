<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentStatus;

/**
 * Capability contract for payment gateways supporting active status polling/reconciliation.
 */
interface StatusQueryableGatewayInterface
{
    public function queryStatus(PaymentAttempt $attempt): PaymentStatus;
}
