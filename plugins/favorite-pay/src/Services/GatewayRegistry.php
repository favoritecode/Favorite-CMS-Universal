<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Services;

use FavoriteCMS\Pay\Contracts\PaymentGatewayInterface;
use InvalidArgumentException;

final class GatewayRegistry
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    /** @var array<string, string> Alias => Target Gateway ID */
    private array $aliases = [];

    public function __construct(bool $loadDefaults = true)
    {
        if ($loadDefaults) {
            $bd = new \FavoriteCMS\Pay\Gateways\ManualBangladeshGateway();
            $bkash = new \FavoriteCMS\Pay\Gateways\ManualBangladeshGateway(
                'manual_bkash',
                'bKash Manual',
                \FavoriteCMS\Pay\Domain\PaymentMethodType::MANUAL_BKASH
            );
            $nagad = new \FavoriteCMS\Pay\Gateways\ManualBangladeshGateway(
                'manual_nagad',
                'Nagad Manual',
                \FavoriteCMS\Pay\Domain\PaymentMethodType::MANUAL_NAGAD
            );
            $bank = new \FavoriteCMS\Pay\Gateways\ManualBangladeshGateway(
                'manual_bank',
                'Bank Transfer Manual',
                \FavoriteCMS\Pay\Domain\PaymentMethodType::MANUAL_BANK
            );

            $this->register($bd);
            $this->register($bkash, ['bkash_manual']);
            $this->register($nagad, ['nagad_manual']);
            $this->register($bank, ['bank_manual']);
        }
    }

    public function register(PaymentGatewayInterface $gateway, array $aliases = []): void
    {
        $this->gateways[$gateway->getId()] = $gateway;
        foreach ($aliases as $alias) {
            $this->aliases[$alias] = $gateway->getId();
        }
    }

    public function registerAlias(string $alias, string $targetId): void
    {
        $this->aliases[$alias] = $targetId;
    }

    public function get(string $id): PaymentGatewayInterface
    {
        $resolvedId = $this->aliases[$id] ?? $id;
        if (!isset($this->gateways[$resolvedId])) {
            throw new InvalidArgumentException("Payment gateway not registered: {$id}");
        }
        return $this->gateways[$resolvedId];
    }

    public function has(string $id): bool
    {
        $resolvedId = $this->aliases[$id] ?? $id;
        return isset($this->gateways[$resolvedId]);
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
