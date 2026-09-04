<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Services;

use FavoriteCMS\Pay\Contracts\PaymentGatewayInterface;
use InvalidArgumentException;

final class GatewayRegistry
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    public function register(PaymentGatewayInterface $gateway): void
    {
        $this->gateways[$gateway->getId()] = $gateway;
    }

    public function get(string $id): PaymentGatewayInterface
    {
        if (!isset($this->gateways[$id])) {
            throw new InvalidArgumentException("Payment gateway not registered: {$id}");
        }
        return $this->gateways[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->gateways[$id]);
    }

    /**
     * @return PaymentGatewayInterface[]
     */
    public function all(): array
    {
        return array_values($this->gateways);
    }

    /**
     * @return PaymentGatewayInterface[]
     */
    public function enabled(): array
    {
        return array_values(array_filter($this->gateways, fn($gw) => $gw->isEnabled()));
    }
}
