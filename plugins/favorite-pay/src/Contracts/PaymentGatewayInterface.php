<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;

interface PaymentGatewayInterface
{
    public function getId(): string;

    public function getTitle(): string;

    public function getType(): PaymentMethodType;

    public function isEnabled(): bool;

    /**
     * Currencies supported by this gateway driver (e.g. ['BDT', 'USD']).
     * @return string[]
     */
    public function getSupportedCurrencies(): array;

    public function createAttempt(PaymentIntent $intent, array $params = []): PaymentAttempt;

    public function verifyAttempt(PaymentAttempt $attempt, array $verificationData = []): PaymentAttempt;
}
