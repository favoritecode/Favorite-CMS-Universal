<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

use FavoriteCMS\Pay\Domain\ConversionSnapshot;

interface ExchangeRateProviderInterface
{
    /**
     * Retrieve exchange rate snapshot between fromCurrency and toCurrency.
     * Returns null if no rate exists.
     */
    public function getRate(string $fromCurrency, string $toCurrency): ?ConversionSnapshot;

    /**
     * Check if a rate exists between fromCurrency and toCurrency.
     */
    public function hasRate(string $fromCurrency, string $toCurrency): bool;

    /**
     * Get unique provider identifier.
     */
    public function getProviderId(): string;
}
