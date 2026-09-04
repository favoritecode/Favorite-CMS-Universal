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
        $this->rates['USD_USD'] = ConversionSnapshot::create('USD', 'USD', '1.00', true);
        $this->rates['EUR_EUR'] = ConversionSnapshot::create('EUR', 'EUR', '1.00', true);
        $this->rates['GBP_GBP'] = ConversionSnapshot::create('GBP', 'GBP', '1.00', true);
        $this->rates['INR_INR'] = ConversionSnapshot::create('INR', 'INR', '1.00', true);
    }

    public function getBaseCurrency(): string
    {
        if (class_exists(\FavoriteCMS\Core\Currency::class)) {
            return \FavoriteCMS\Core\Currency::getPrimaryCurrency();
        }

        if (class_exists(\FavoriteCMS\Models\Setting::class)) {
            $setting = \FavoriteCMS\Models\Setting::get('general', 'primary_currency', self::BASE_CURRENCY);
            if (is_string($setting) && trim($setting) !== '') {
                return strtoupper(trim($setting));
            }
        }

        return self::BASE_CURRENCY;
    }

    public function getSupportedCurrencies(): array
    {
        if (class_exists(\FavoriteCMS\Core\Currency::class)) {
            return \FavoriteCMS\Core\Currency::getSupportedCodes();
        }

        return ['BDT', 'USD', 'EUR', 'GBP', 'INR', 'PKR', 'AED', 'SAR', 'CAD', 'AUD', 'JPY', 'CNY'];
    }

    public function getRate(string $fromCurrency, ?string $toCurrency = null): ConversionSnapshot
    {
        $from = strtoupper(trim($fromCurrency));
        $to = $toCurrency !== null ? strtoupper(trim($toCurrency)) : $this->getBaseCurrency();

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
        ?string $toCurrency = null
    ): ConversionSnapshot {
        $from = strtoupper(trim($fromCurrency));
        $to = $toCurrency !== null ? strtoupper(trim($toCurrency)) : $this->getBaseCurrency();

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
        ?string $toCurrency = null
    ): bool {
        $from = strtoupper(trim($fromCurrency));
        $to = $toCurrency !== null ? strtoupper(trim($toCurrency)) : $this->getBaseCurrency();
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

    public function createLockedSnapshot(string $fromCurrency, ?string $toCurrency = null): ConversionSnapshot
    {
        return $this->getRate($fromCurrency, $toCurrency);
    }
}
