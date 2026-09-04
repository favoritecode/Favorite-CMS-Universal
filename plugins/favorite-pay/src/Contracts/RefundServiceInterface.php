<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentIntent;

interface RefundServiceInterface
{
    public function createRefund(
        string $intentId,
        ?Money $amount = null,
        string $reason = ''
    ): array;

    public function getRefunds(string $intentId): array;
}
