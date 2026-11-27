<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

use FavoriteCMS\Pay\Domain\WebhookHandlingResult;

interface WebhookServiceInterface
{
    /**
     * Handle incoming gateway webhook request securely.
     */
    public function handle(string $gatewayId, array $headers, string|array $payload): WebhookHandlingResult;
}
