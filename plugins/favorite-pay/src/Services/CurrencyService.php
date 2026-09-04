<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Services;

use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Domain\ConversionSnapshot;
use FavoriteCMS\Pay\Domain\Money;
use InvalidArgumentException;

/**
 * Currency Service
 *
 * Implements Locked Decision:
 * 1. Hybrid Operator + Automated Sync: Operator rate is authoritative.
 * 2. Automated sync must NEVER silently overwrite an operator lock.
 */
class CurrencyService implements CurrencyServiceInterface
{
    private const BASE_CURRENCY = 'BDT';

    /** @var array<string, ConversionSnapshot> */
    private array $rates = [];

    public function __construct()
    {
        // Initialize standard default fallback rates (BDT base)
        $this->rates['USD_BDT'] = ConversionSnapshot::create('USD', 'BDT', '117.50', false);
        $this->rates['EUR_BDT'] = ConversionSnapshot::create('EUR', 'BDT', '128.20', false);
        $this->rates['GBP_BDT'] = ConversionSnapshot::create('GBP', 'BDT', '150.00', false);
        $this->rates['BDT_BDT'] = ConversionSnapshot::create('BDT', 'BDT', '1.00', true);
    }

    public function getBaseCurrency(): string
    {
        return self::BASE_CURRENCY;
    }

    public function getSupportedCurrencies(): array
    {
        return ['BDT', 'USD', 'EUR', 'GBP'];
    }

    public function getRate(string $fromCurrency, string $toCurrency = 'BDT'): ConversionSnapshot
    {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));

        if ($from === $to) {
            return ConversionSnapshot::create($from, $to, '1.00', true);
        }

        $key = "{$from}_{$to}";
        if (isset($this->rates[$key])) {
            return $this->rates[$key];
        }

        throw new InvalidArgumentException("Exchange rate not configured for currency pair: {$key}");
    }

    public function setOperatorRate(
        string $fromCurrency,
        string $rateMajorString,
        int $operatorUserId,
        string $toCurrency = 'BDT'
    ): ConversionSnapshot {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));

        $snapshot = ConversionSnapshot::create($from, $to, $rateMajorString, true);
        $this->rates["{$from}_{$to}"] = $snapshot;

        // Trigger Hook
        if (function_exists('do_action')) {
            do_action('favorite.pay.rate.operator_locked', [
                'from' => $from,
                'to' => $to,
                'rate' => $rateMajorString,
                'operator_id' => $operatorUserId,
            ]);
        }

        return $snapshot;
    }

    public function syncAutomatedRate(
        string $fromCurrency,
        string $rateMajorString,
        string $toCurrency = 'BDT'
    ): bool {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));
        $key = "{$from}_{$to}";

        // If rate already exists and is locked authoritatively by operator, DO NOT overwrite!
        if (isset($this->rates[$key]) && $this->rates[$key]->isAuthoritative()) {
            return false;
        }

        $this->rates[$key] = ConversionSnapshot::create($from, $to, $rateMajorString, false);
        return true;
    }

    public function convert(Money $money, string $targetCurrency): Money
    {
        $target = strtoupper(trim($targetCurrency));
        if ($money->getCurrency() === $target) {
            return $money;
        }

        $snapshot = $this->getRate($money->getCurrency(), $target);
        return $snapshot->convert($money);
    }

    public function createLockedSnapshot(string $fromCurrency, string $toCurrency = 'BDT'): ConversionSnapshot
    {
        return $this->getRate($fromCurrency, $toCurrency);
    }
}
