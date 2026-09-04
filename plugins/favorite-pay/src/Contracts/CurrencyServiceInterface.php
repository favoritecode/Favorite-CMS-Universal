<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

use FavoriteCMS\Pay\Domain\ConversionSnapshot;
use FavoriteCMS\Pay\Domain\Money;

interface CurrencyServiceInterface
{
    public function getBaseCurrency(): string;

    public function getSupportedCurrencies(): array;

    /**
     * Retrieve authoritative, non-expired rate snapshot between fromCurrency and toCurrency.
     * Throws UnauthoritativeRateException if no valid authoritative rate exists.
     */
    public function getRate(string $fromCurrency, ?string $toCurrency = null): ConversionSnapshot;

    /**
     * Check if a valid, authoritative, non-expired rate exists between fromCurrency and toCurrency.
     */
    public function hasRate(string $fromCurrency, ?string $toCurrency = null): bool;

    /**
     * Lock an operator authoritative exchange rate.
     */
    public function setOperatorRate(
        string $fromCurrency,
        string $rateMajorString,
        int $operatorUserId,
        ?string $toCurrency = null,
        ?string $expiresAt = null,
        ?string $notes = null
    ): ConversionSnapshot;

    /**
     * Sync an automated exchange rate from an external feed.
     */
    public function syncAutomatedRate(
        string $fromCurrency,
        string $rateMajorString,
        ?string $toCurrency = null,
        ?string $expiresAt = null,
        string $source = 'automated'
    ): bool;

    public function convert(Money $money, string $targetCurrency): Money;

    public function createLockedSnapshot(string $fromCurrency, ?string $toCurrency = null): ConversionSnapshot;

    public function setProvider(?ExchangeRateProviderInterface $provider): void;

    public function getProvider(): ?ExchangeRateProviderInterface;
}
