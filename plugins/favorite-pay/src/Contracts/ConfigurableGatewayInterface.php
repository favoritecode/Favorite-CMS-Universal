<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

/**
 * Capability contract for payment gateways declaring configuration schemas and validation.
 */
interface ConfigurableGatewayInterface
{
    /**
     * Get declaration of configuration schema fields.
     * @return array<string, array{type: string, label: string, required?: bool, secret?: bool, description?: string, default?: mixed, options?: array}>
     */
    public function getConfigSchema(): array;

    /**
     * Validate configuration parameters.
     * @throws \InvalidArgumentException on validation failure.
     */
    public function validateConfig(array $config): array;

    public function getConfig(): array;

    public function setConfig(array $config): void;
}
