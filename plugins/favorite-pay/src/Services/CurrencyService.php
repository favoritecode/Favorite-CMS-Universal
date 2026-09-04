<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Contracts\ExchangeRateProviderInterface;
use FavoriteCMS\Pay\Domain\ConversionSnapshot;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Exceptions\UnauthoritativeRateException;
use FavoriteCMS\Pay\Providers\DatabaseRateProvider;
use FavoriteCMS\Pay\Providers\LiveExchangeRateProvider;
use InvalidArgumentException;

/**
 * Currency Service
 *
 * Implements Production-Safe Rate Architecture:
 * 1. ZERO static/seeded FX rates in production constructor.
 * 2. Strict authoritative requirement: non-authoritative rates fail closed.
 * 3. Freshness validation: expired rates fail closed.
 * 4. Inverse rates are derived ONLY from authoritative, fresh source rates.
 * 5. Supports ExchangeRateProviderInterface, LiveExchangeRateProvider, and DatabaseRateProvider.
 */
class CurrencyService implements CurrencyServiceInterface
{
    private const BASE_CURRENCY = 'BDT';

    /** @var array<string, ConversionSnapshot> In-memory operator/manual or synced snapshots */
    private array $rates = [];

    private ?ExchangeRateProviderInterface $provider;
    private ?Database $db;

    /**
     * Production constructor: NO hardcoded seeded rates.
     */
    public function __construct(
        ?ExchangeRateProviderInterface $provider = null,
        ?Database $db = null
    ) {
        $this->db = $db;

        if ($provider !== null) {
            $this->provider = $provider;
        } elseif ($db !== null) {
            $fallback = new DatabaseRateProvider($db);
            $this->provider = new LiveExchangeRateProvider($db, $fallback);
        } else {
            $this->provider = null;
        }


    }

    public function setProvider(?ExchangeRateProviderInterface $provider): void
    {
        $this->provider = $provider;
    }

    public function getProvider(): ?ExchangeRateProviderInterface
    {
        return $this->provider;
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

        return ['BDT', 'USD', 'EUR', 'GBP', 'INR', 'PKR', 'AED', 'SAR', 'CAD', 'AUD', 'JPY', 'CNY', 'USDT', 'USDC'];
    }

    public function hasRate(string $fromCurrency, ?string $toCurrency = null): bool
    {
        $from = strtoupper(trim($fromCurrency));
        $to = $toCurrency !== null ? strtoupper(trim($toCurrency)) : $this->getBaseCurrency();

        if ($from === $to) {
            return true;
        }

        try {
            $snapshot = $this->resolveRateSnapshot($from, $to);
            return $snapshot !== null && $snapshot->isValidForPayment();
        } catch (UnauthoritativeRateException) {
            return false;
        }
    }

    public function getRate(string $fromCurrency, ?string $toCurrency = null): ConversionSnapshot
    {
        $from = strtoupper(trim($fromCurrency));
        $to = $toCurrency !== null ? strtoupper(trim($toCurrency)) : $this->getBaseCurrency();

        // Identical currency is always 1:1, authoritative, and perpetual
        if ($from === $to) {
            return ConversionSnapshot::create($from, $to, '1.00', true, ConversionSnapshot::DEFAULT_SCALE, null, 'identity');
        }

        $snapshot = $this->resolveRateSnapshot($from, $to);
        if ($snapshot === null) {
            throw new UnauthoritativeRateException(
                "No valid authoritative exchange rate is available for conversion from '{$from}' to '{$to}'.",
                $from,
                $to
            );
        }

        if (!$snapshot->isAuthoritative()) {
            throw new UnauthoritativeRateException(
                "Exchange rate for '{$from}' to '{$to}' is not authoritative.",
                $from,
                $to
            );
        }

        if ($snapshot->isExpired()) {
            throw new UnauthoritativeRateException(
                "Exchange rate for '{$from}' to '{$to}' expired at {$snapshot->getExpiresAt()}.",
                $from,
                $to
            );
        }

        if ($snapshot->getRateFactor() <= 0) {
            throw new UnauthoritativeRateException(
                "Exchange rate for '{$from}' to '{$to}' must have a positive rate factor.",
                $from,
                $to
            );
        }

        return $snapshot;
    }

    /**
     * Resolve a direct or inverse rate snapshot from in-memory cache or provider.
     */
    private function resolveRateSnapshot(string $from, string $to): ?ConversionSnapshot
    {
        $key = "{$from}_{$to}";

        // 1. Check in-memory store for direct rate
        if (isset($this->rates[$key])) {
            return $this->rates[$key];
        }

        // 2. Check provider for direct rate
        if ($this->provider !== null && $this->provider->hasRate($from, $to)) {
            $providerSnap = $this->provider->getRate($from, $to);
            if ($providerSnap !== null) {
                return $providerSnap;
            }
        }

        // 3. Safe Inverse Derivation: Allowed ONLY if source rate is authoritative, non-expired, and positive
        $invKey = "{$to}_{$from}";
        $invSourceSnap = null;

        if (isset($this->rates[$invKey])) {
            $invSourceSnap = $this->rates[$invKey];
        } elseif ($this->provider !== null && $this->provider->hasRate($to, $from)) {
            $invSourceSnap = $this->provider->getRate($to, $from);
        }

        if ($invSourceSnap !== null) {
            if (!$invSourceSnap->isAuthoritative()) {
                throw new UnauthoritativeRateException(
                    "Cannot derive inverse exchange rate for '{$from}' to '{$to}': source rate '{$to}' to '{$from}' is not authoritative.",
                    $from,
                    $to
                );
            }

            if ($invSourceSnap->isExpired()) {
                throw new UnauthoritativeRateException(
                    "Cannot derive inverse exchange rate for '{$from}' to '{$to}': source rate '{$to}' to '{$from}' expired at {$invSourceSnap->getExpiresAt()}.",
                    $from,
                    $to
                );
            }

            $invFactor = $invSourceSnap->getRateFactor();
            if ($invFactor <= 0) {
                throw new UnauthoritativeRateException(
                    "Cannot derive inverse exchange rate for '{$from}' to '{$to}': source rate factor is non-positive.",
                    $from,
                    $to
                );
            }

            $scale = $invSourceSnap->getRateScale();
            $derivedFactor = intdiv(($scale * $scale) + intdiv($invFactor, 2), $invFactor);

            return new ConversionSnapshot(
                $from,
                $to,
                $derivedFactor,
                $scale,
                true,
                $invSourceSnap->getLockedAt(),
                $invSourceSnap->getExpiresAt(),
                'derived_inverse:' . $invSourceSnap->getSource()
            );
        }

        // 4. Safe Triangulation via common reference currency (USD)
        if ($from !== 'USD' && $to !== 'USD') {
            $snapFrom = $this->resolveRateSnapshot('USD', $from);
            $snapTo = $this->resolveRateSnapshot('USD', $to);

            if (
                $snapFrom !== null && $snapFrom->isAuthoritative() && !$snapFrom->isExpired() && $snapFrom->getRateFactor() > 0
                && $snapTo !== null && $snapTo->isAuthoritative() && !$snapTo->isExpired() && $snapTo->getRateFactor() > 0
            ) {
                // Rate math: 1 USD = factorFrom 'from'  => 1 'from' = (factorTo / factorFrom) 'to'
                $scale = $snapFrom->getRateScale();
                $factorFrom = $snapFrom->getRateFactor();
                $factorTo = $snapTo->getRateFactor();

                $derivedFactor = intdiv(($factorTo * $scale) + intdiv($factorFrom, 2), $factorFrom);
                if ($derivedFactor > 0) {
                    return new ConversionSnapshot(
                        $from,
                        $to,
                        $derivedFactor,
                        $scale,
                        true,
                        $snapTo->getLockedAt(),
                        $snapTo->getExpiresAt(),
                        'derived_triangulation:USD'
                    );
                }
            }
        }

        return null;
    }


    public function setOperatorRate(
        string $fromCurrency,
        string $rateMajorString,
        int $operatorUserId,
        ?string $toCurrency = null,
        ?string $expiresAt = null,
        ?string $notes = null
    ): ConversionSnapshot {
        $from = strtoupper(trim($fromCurrency));
        $to = $toCurrency !== null ? strtoupper(trim($toCurrency)) : $this->getBaseCurrency();

        $snapshot = ConversionSnapshot::create(
            $from,
            $to,
            $rateMajorString,
            true,
            ConversionSnapshot::DEFAULT_SCALE,
            $expiresAt,
            'operator'
        );

        $this->rates["{$from}_{$to}"] = $snapshot;

        // Persist to database if table exists
        if ($this->db !== null && $this->db->tableExists('favorite_pay_rates')) {
            try {
                $now = date('Y-m-d H:i:s');
                // First retire overlapping active rate for the same pair
                if ($this->provider instanceof DatabaseRateProvider) {
                    $this->provider->retireActiveRates($from, $to, $now);
                } else {
                    $this->db->execute(
                        "UPDATE favorite_pay_rates 
                         SET status = 'retired', expires_at = ? 
                         WHERE base_currency = ? 
                           AND quote_currency = ? 
                           AND is_authoritative = 1 
                           AND (status = 'active' OR status IS NULL)
                           AND (expires_at IS NULL OR expires_at > ?)",
                        [$now, $from, $to, $now]
                    );
                }

                $insertData = [
                    'base_currency'    => $from,
                    'quote_currency'   => $to,
                    'rate'             => (float)$rateMajorString,
                    'rate_factor'      => $snapshot->getRateFactor(),
                    'rate_scale'       => $snapshot->getRateScale(),
                    'is_authoritative' => 1,
                    'status'           => 'active',
                    'source'           => 'operator',
                    'operator_id'      => $operatorUserId,
                    'effective_at'     => $now,
                    'expires_at'       => $expiresAt,
                    'created_at'       => $now,
                ];
                if ($notes !== null) {
                    $insertData['notes'] = $notes;
                }
                $this->db->insert('favorite_pay_rates', $insertData);
            } catch (\Throwable) {
                // In-memory snapshot still holds
            }
        }

        // Trigger Hook
        if (function_exists('do_action')) {
            do_action('favorite.pay.rate.operator_locked', [
                'from'        => $from,
                'to'          => $to,
                'rate'        => $rateMajorString,
                'operator_id' => $operatorUserId,
                'expires_at'  => $expiresAt,
                'notes'       => $notes,
            ]);
        }

        return $snapshot;
    }

    public function syncAutomatedRate(
        string $fromCurrency,
        string $rateMajorString,
        ?string $toCurrency = null,
        ?string $expiresAt = null,
        string $source = 'automated'
    ): bool {
        $from = strtoupper(trim($fromCurrency));
        $to = $toCurrency !== null ? strtoupper(trim($toCurrency)) : $this->getBaseCurrency();
        $key = "{$from}_{$to}";

        // If rate already exists and is locked authoritatively by operator, DO NOT overwrite!
        if (isset($this->rates[$key]) && $this->rates[$key]->isAuthoritative() && $this->rates[$key]->getSource() === 'operator') {
            return false;
        }

        $this->rates[$key] = ConversionSnapshot::create(
            $from,
            $to,
            $rateMajorString,
            false,
            ConversionSnapshot::DEFAULT_SCALE,
            $expiresAt,
            $source
        );
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
