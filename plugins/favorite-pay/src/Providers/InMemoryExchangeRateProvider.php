<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Providers;

use FavoriteCMS\Pay\Contracts\ExchangeRateProviderInterface;
use FavoriteCMS\Pay\Domain\ConversionSnapshot;

/**
 * In-memory deterministic rate provider for automated tests and simulations.
 * Isolated from production initialization.
 */
class InMemoryExchangeRateProvider implements ExchangeRateProviderInterface
{
    /** @var array<string, ConversionSnapshot> */
    private array $rates = [];
    private string $providerId;

    public function __construct(string $providerId = 'in_memory')
    {
        $this->providerId = $providerId;
    }

    public function setRate(
        string $fromCurrency,
        string $toCurrency,
        string $rateDecimalString,
        bool $isAuthoritative = true,
        ?string $expiresAt = null,
        string $source = 'test_fixture'
    ): ConversionSnapshot {
        $snapshot = ConversionSnapshot::create(
            $fromCurrency,
            $toCurrency,
            $rateDecimalString,
            $isAuthoritative,
            ConversionSnapshot::DEFAULT_SCALE,
            $expiresAt,
            $source
        );
        $key = strtoupper(trim($fromCurrency)) . '_' . strtoupper(trim($toCurrency));
        $this->rates[$key] = $snapshot;
        return $snapshot;
    }

    public function setSnapshot(ConversionSnapshot $snapshot): void
    {
        $key = $snapshot->getFromCurrency() . '_' . $snapshot->getToCurrency();
        $this->rates[$key] = $snapshot;
    }

    public function getRate(string $fromCurrency, string $toCurrency): ?ConversionSnapshot
    {
        $key = strtoupper(trim($fromCurrency)) . '_' . strtoupper(trim($toCurrency));
        return $this->rates[$key] ?? null;
    }

    public function hasRate(string $fromCurrency, string $toCurrency): bool
    {
        $key = strtoupper(trim($fromCurrency)) . '_' . strtoupper(trim($toCurrency));
        return isset($this->rates[$key]);
    }

    public function clear(): void
    {
        $this->rates = [];
    }

    public function getProviderId(): string
    {
        return $this->providerId;
    }
}
