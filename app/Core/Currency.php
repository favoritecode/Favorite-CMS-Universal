<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

use FavoriteCMS\Models\Setting;
use InvalidArgumentException;
use RuntimeException;

/**
 * Currency Central Definition & Site Primary Currency Service
 *
 * Provides a single source of truth for:
 * - Supported ISO 4217 currency metadata (names, symbols, minor-unit scale).
 * - Site-level Primary Currency resolution from Core Settings.
 * - Global currency validation and normalization.
 */
class Currency
{
    public const DEFAULT_CURRENCY = 'BDT';

    /**
     * Authoritative supported currency registry.
     * Centralized to eliminate duplicates across Core, Pay, Shop, and Themes.
     */
    protected static array $supportedCurrencies = [
        'BDT' => [
            'code'     => 'BDT',
            'name'     => 'Bangladeshi Taka',
            'symbol'   => '৳',
            'decimals' => 2,
        ],
        'USD' => [
            'code'     => 'USD',
            'name'     => 'US Dollar',
            'symbol'   => '$',
            'decimals' => 2,
        ],
        'EUR' => [
            'code'     => 'EUR',
            'name'     => 'Euro',
            'symbol'   => '€',
            'decimals' => 2,
        ],
        'GBP' => [
            'code'     => 'GBP',
            'name'     => 'British Pound',
            'symbol'   => '£',
            'decimals' => 2,
        ],
        'INR' => [
            'code'     => 'INR',
            'name'     => 'Indian Rupee',
            'symbol'   => '₹',
            'decimals' => 2,
        ],
        'PKR' => [
            'code'     => 'PKR',
            'name'     => 'Pakistani Rupee',
            'symbol'   => '₨',
            'decimals' => 2,
        ],
        'AED' => [
            'code'     => 'AED',
            'name'     => 'UAE Dirham',
            'symbol'   => 'د.إ',
            'decimals' => 2,
        ],
        'SAR' => [
            'code'     => 'SAR',
            'name'     => 'Saudi Riyal',
            'symbol'   => '﷼',
            'decimals' => 2,
        ],
        'CAD' => [
            'code'     => 'CAD',
            'name'     => 'Canadian Dollar',
            'symbol'   => '$',
            'decimals' => 2,
        ],
        'AUD' => [
            'code'     => 'AUD',
            'name'     => 'Australian Dollar',
            'symbol'   => '$',
            'decimals' => 2,
        ],
        'JPY' => [
            'code'     => 'JPY',
            'name'     => 'Japanese Yen',
            'symbol'   => '¥',
            'decimals' => 0,
        ],
        'CNY' => [
            'code'     => 'CNY',
            'name'     => 'Chinese Yuan',
            'symbol'   => '¥',
            'decimals' => 2,
        ],
        'USDT' => [
            'code'     => 'USDT',
            'name'     => 'Tether USD',
            'symbol'   => '₮',
            'decimals' => 2,
        ],
        'USDC' => [
            'code'     => 'USDC',
            'name'     => 'USD Coin',
            'symbol'   => '$',
            'decimals' => 2,
        ],
    ];

    /**
     * Get all supported currency metadata.
     *
     * @return array<string, array{code: string, name: string, symbol: string, decimals: int}>
     */
    public static function getSupportedCurrencies(): array
    {
        return self::$supportedCurrencies;
    }

    /**
     * Get list of supported 3-letter ISO currency codes.
     *
     * @return list<string>
     */
    public static function getSupportedCodes(): array
    {
        return array_keys(self::$supportedCurrencies);
    }

    /**
     * Check if a given currency code is supported.
     */
    public static function isSupported(string $code): bool
    {
        $normalized = self::normalize($code);
        return isset(self::$supportedCurrencies[$normalized]);
    }

    /**
     * Normalize a currency code string (trims, uppercase).
     */
    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * Get metadata for a specific currency.
     *
     * @return array{code: string, name: string, symbol: string, decimals: int}|null
     */
    public static function get(string $code): ?array
    {
        $normalized = self::normalize($code);
        return self::$supportedCurrencies[$normalized] ?? null;
    }

    /**
     * Get minor-unit decimal precision for a currency (default: 2, 0 for JPY, etc.).
     */
    public static function getDecimals(string $code): int
    {
        $info = self::get($code);
        return $info['decimals'] ?? 2;
    }

    /**
     * Get the site's authoritative Primary Accounting Currency.
     *
     * Source of truth: Core Settings (group: 'general', key: 'primary_currency').
     * Default: BDT.
     */
    public static function getPrimaryCurrency(): string
    {
        if (class_exists(Setting::class)) {
            try {
                $setting = Setting::get('general', 'primary_currency', self::DEFAULT_CURRENCY);
                if (is_string($setting) && trim($setting) !== '') {
                    $normalized = self::normalize($setting);
                    if (self::isSupported($normalized)) {
                        return $normalized;
                    }
                }
            } catch (\Throwable) {
                // Fallback to default if database is uninitialized
            }
        }

        return self::DEFAULT_CURRENCY;
    }

    /**
     * Check if the site's primary currency is locked against modifications.
     * Returns true if existing financial activity or system rules prevent changing it.
     */
    public static function isPrimaryCurrencyLocked(?string &$reason = null): bool
    {
        if (function_exists('apply_filters')) {
            $lockResult = apply_filters('currency.is_primary_locked', false);
            if ($lockResult === true) {
                $reason = 'Primary Currency cannot be changed after financial activity has started.';
                return true;
            }
            if (is_array($lockResult) && !empty($lockResult['locked'])) {
                $reason = $lockResult['reason'] ?? 'Primary Currency cannot be changed after financial activity has started.';
                return true;
            }

            // Also probe can_change_primary with an alternative currency
            $current = self::getPrimaryCurrency();
            $probe = ($current === 'USD') ? 'EUR' : 'USD';
            $changeResult = apply_filters('currency.can_change_primary', true, $probe, $current);
            if ($changeResult === false) {
                $reason = 'Primary Currency cannot be changed after financial activity has started.';
                return true;
            }
            if (is_array($changeResult) && isset($changeResult['allowed']) && !$changeResult['allowed']) {
                $reason = $changeResult['reason'] ?? 'Primary Currency cannot be changed after financial activity has started.';
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the site's primary currency can be changed to a new currency code.
     */
    public static function canChangePrimaryCurrency(string $newCurrency, ?string &$reason = null): bool
    {
        $normalized = self::normalize($newCurrency);
        $current = self::getPrimaryCurrency();

        if ($normalized === $current) {
            return true;
        }

        if (!preg_match('/^[A-Z]{3}$/', $normalized) || !self::isSupported($normalized)) {
            $reason = "Unsupported or invalid currency code: '{$newCurrency}'.";
            return false;
        }

        if (function_exists('apply_filters')) {
            $result = apply_filters('currency.can_change_primary', true, $normalized, $current);
            if ($result === false) {
                $reason = $reason ?? 'Primary Currency cannot be changed after financial activity has started.';
                return false;
            }
            if (is_array($result) && isset($result['allowed']) && !$result['allowed']) {
                $reason = $result['reason'] ?? 'Primary Currency cannot be changed after financial activity has started.';
                return false;
            }
        }

        return true;
    }

    /**
     * Set the site's authoritative Primary Accounting Currency.
     *
     * Validates that the code is a valid supported ISO currency.
     * Validates that changing the currency is permitted (no existing financial records).
     * Persists as uppercase string in Core Settings.
     *
     * @throws InvalidArgumentException If the currency code is unsupported or invalid.
     * @throws RuntimeException If primary currency cannot be changed due to financial activity.
     */
    public static function setPrimaryCurrency(string $code): void
    {
        $normalized = self::normalize($code);

        if (!preg_match('/^[A-Z]{3}$/', $normalized) || !self::isSupported($normalized)) {
            throw new InvalidArgumentException(
                "Unsupported or invalid primary currency code: '{$code}'. Must be a supported 3-letter ISO code."
            );
        }

        $oldCurrency = self::getPrimaryCurrency();
        if ($normalized === $oldCurrency) {
            return;
        }

        $reason = null;
        if (!self::canChangePrimaryCurrency($normalized, $reason)) {
            throw new RuntimeException(
                $reason ?? "Cannot change primary currency once financial activity has started."
            );
        }

        Setting::set('general', 'primary_currency', $normalized, 'string');

        if (function_exists('do_action')) {
            do_action('currency.primary_changed', [
                'old' => $oldCurrency,
                'new' => $normalized,
            ]);
        }
    }
}
