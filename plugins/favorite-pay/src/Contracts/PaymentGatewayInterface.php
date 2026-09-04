<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

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
     * Currencies supported by this gateway driver (e.g. ['BDT']).
     * @return string[]
     */
    public function getSupportedCurrencies(): array;

    /**
     * Expose structured payment instructions to the customer (receiver account, instructions, etc.).
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function getInstructions(array $context = []): array;

    public function createAttempt(PaymentIntent $intent, array $params = []): PaymentAttempt;

    public function verifyAttempt(PaymentAttempt $attempt, array $verificationData = []): PaymentAttempt;
}
