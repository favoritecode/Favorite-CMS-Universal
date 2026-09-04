<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

use FavoriteCMS\Pay\Domain\VerifiedWebhookResult;

/**
 * Capability contract for payment gateways supporting incoming asynchronous webhooks/callbacks.
 */
interface WebhookGatewayInterface
{
    /**
     * Verify incoming provider webhook request and parse verified payment result.
     * Must return VerifiedWebhookResult with isVerified() = false if verification fails.
     *
     * @param array<string, string|string[]> $headers HTTP request headers
     * @param string|array $payload Raw or parsed webhook payload
     */
    public function verifyWebhook(array $headers, string|array $payload): VerifiedWebhookResult;
}
