<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

use FavoriteCMS\Pay\Domain\PaymentAttempt;

/**
 * Capability contract for payment gateways requiring off-site customer redirection.
 */
interface RedirectPaymentGatewayInterface
{
    public function getRedirectUrl(PaymentAttempt $attempt): ?string;

    public function getRedirectMethod(): string;

    public function getRedirectPayload(PaymentAttempt $attempt): array;
}
