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

    /** @var array<string, bool> Gateway ID => is default placeholder */
    private array $defaults = [];

    public function __construct(bool $loadDefaults = true)
    {
        if ($loadDefaults) {
            $this->loadDefaults();
        }
    }

    private function loadDefaults(): void
    {
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

        $this->gateways[$bd->getId()] = $bd;
        $this->defaults[$bd->getId()] = true;

        $this->gateways[$bkash->getId()] = $bkash;
        $this->defaults[$bkash->getId()] = true;

        $this->gateways[$nagad->getId()] = $nagad;
        $this->defaults[$nagad->getId()] = true;

        $this->gateways[$bank->getId()] = $bank;
        $this->defaults[$bank->getId()] = true;

        $this->aliases['bkash_manual'] = 'manual_bkash';
        $this->aliases['nagad_manual'] = 'manual_nagad';
        $this->aliases['bank_manual'] = 'manual_bank';
    }

    public function register(PaymentGatewayInterface $gateway, array $aliases = [], bool $overwrite = false): void
    {
        $id = $gateway->getId();
        if (isset($this->gateways[$id])) {
            if (!$overwrite && empty($this->defaults[$id])) {
                throw new InvalidArgumentException("Payment gateway '{$id}' is already registered.");
            }
        }

        $this->gateways[$id] = $gateway;
        unset($this->defaults[$id]);

        foreach ($aliases as $alias) {
            $this->registerAlias($alias, $id);
        }
    }

    public function registerAlias(string $alias, string $targetId): void
    {
        $trimmedAlias = trim($alias);
        if ($trimmedAlias === '') {
            throw new InvalidArgumentException("Gateway alias cannot be empty.");
        }

        if (isset($this->gateways[$trimmedAlias])) {
            throw new InvalidArgumentException("Cannot register alias '{$trimmedAlias}': a primary gateway with this ID already exists.");
        }

        $this->aliases[$trimmedAlias] = $targetId;
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

    public function unregister(string $id): bool
    {
        if (!isset($this->gateways[$id])) {
            return false;
        }

        unset($this->gateways[$id]);
        foreach ($this->aliases as $alias => $target) {
            if ($target === $id) {
                unset($this->aliases[$alias]);
            }
        }
        return true;
    }

    public function clear(): void
    {
        $this->gateways = [];
        $this->aliases = [];
    }

    /**
     * Inspect gateway capability interfaces.
     *
     * @return array{
     *     gateway_id: string,
     *     title: string,
     *     type: string,
     *     is_enabled: bool,
     *     supports_webhook: bool,
     *     supports_refund: bool,
     *     supports_redirect: bool,
     *     supports_query: bool,
     *     is_configurable: bool
     * }
     */
    public function getCapabilities(string $id): array
    {
        $gateway = $this->get($id);

        return [
            'gateway_id'        => $gateway->getId(),
            'title'             => $gateway->getTitle(),
            'type'              => $gateway->getType()->value,
            'is_enabled'        => $gateway->isEnabled(),
            'supports_webhook'  => $gateway instanceof \FavoriteCMS\Pay\Contracts\WebhookGatewayInterface,
            'supports_refund'   => $gateway instanceof \FavoriteCMS\Pay\Contracts\RefundableGatewayInterface,
            'supports_redirect' => $gateway instanceof \FavoriteCMS\Pay\Contracts\RedirectPaymentGatewayInterface,
            'supports_query'    => $gateway instanceof \FavoriteCMS\Pay\Contracts\StatusQueryableGatewayInterface,
            'is_configurable'   => $gateway instanceof \FavoriteCMS\Pay\Contracts\ConfigurableGatewayInterface,
        ];
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
