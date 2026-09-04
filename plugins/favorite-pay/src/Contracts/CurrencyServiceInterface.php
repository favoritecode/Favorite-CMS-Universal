<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Contracts;

use FavoriteCMS\Pay\Domain\ConversionSnapshot;
use FavoriteCMS\Pay\Domain\Money;

interface CurrencyServiceInterface
{
    public function getBaseCurrency(): string;

    public function getSupportedCurrencies(): array;

    public function getRate(string $fromCurrency, string $toCurrency = 'BDT'): ConversionSnapshot;

    /**
     * Admin/Operator sets authoritative exchange rate.
     */
    public function setOperatorRate(
        string $fromCurrency,
        string $rateMajorString,
        int $operatorUserId,
        string $toCurrency = 'BDT'
    ): ConversionSnapshot;

    /**
     * Sync automated rates without overwriting operator-authoritative locks.
     */
    public function syncAutomatedRate(
        string $fromCurrency,
        string $rateMajorString,
        string $toCurrency = 'BDT'
    ): bool;

    public function convert(Money $money, string $targetCurrency): Money;

    public function createLockedSnapshot(string $fromCurrency, string $toCurrency = 'BDT'): ConversionSnapshot;
}
