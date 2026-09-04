<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Domain;

use InvalidArgumentException;

/**
 * Money Value Object
 *
 * Implements strict integer minor-unit financial arithmetic.
 * Floating-point representation and arithmetic are strictly prohibited.
 *
 * Minor Unit Examples:
 * - 100.50 BDT  -> 10,050 Poisha (Scale: 2)
 * - 10.99 USD   -> 1,099 Cents   (Scale: 2)
 * - 500 JPY     -> 500 Yen       (Scale: 0)
 */
final class Money
{
    private const CURRENCY_SUBUNITS = [
        'BDT' => 2,
        'USD' => 2,
        'EUR' => 2,
        'GBP' => 2,
        'INR' => 2,
        'PKR' => 2,
        'AED' => 2,
        'SAR' => 2,
        'CAD' => 2,
        'AUD' => 2,
        'SGD' => 2,
        'MYR' => 2,
        'JPY' => 0,
        'KRW' => 0,
        'CNY' => 2,
    ];

    private int $amount;
    private string $currency;

    public function __construct(int $amountInMinorUnits, string $currency = 'BDT')
    {
        $cur = strtoupper(trim($currency));
        if ($cur === '' || strlen($cur) !== 3) {
            throw new InvalidArgumentException("Invalid currency code: {$currency}. Must be 3-letter ISO code.");
        }

        $this->amount = $amountInMinorUnits;
        $this->currency = $cur;
    }

    public static function fromMinor(int $amountInMinorUnits, string $currency = 'BDT'): self
    {
        return new self($amountInMinorUnits, $currency);
    }

    public static function bdt(int $poisha): self
    {
        return new self($poisha, 'BDT');
    }

    public static function usd(int $cents): self
    {
        return new self($cents, 'USD');
    }

    public static function eur(int $cents): self
    {
        return new self($cents, 'EUR');
    }

    public static function inr(int $paise): self
    {
        return new self($paise, 'INR');
    }

    public static function gbp(int $pence): self
    {
        return new self($pence, 'GBP');
    }

    public static function zero(string $currency = 'BDT'): self
    {
        return new self(0, $currency);
    }

    /**
     * Safely parse a decimal string into an integer minor-unit Money object.
     * Uses string parsing to completely eliminate float imprecision (e.g. 0.1 + 0.2 bugs).
     */
    public static function fromMajorString(string $majorAmount, string $currency = 'BDT'): self
    {
        $raw = trim($majorAmount);
        if ($raw === '' || !preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
            throw new InvalidArgumentException("Invalid numeric string format: '{$majorAmount}'");
        }

        $isNegative = str_starts_with($raw, '-');
        $clean = ltrim($raw, '-');

        $parts = explode('.', $clean, 2);
        $whole = $parts[0];
        $fraction = $parts[1] ?? '';

        $currencyUpper = strtoupper(trim($currency));
        $decimals = self::CURRENCY_SUBUNITS[$currencyUpper] ?? 2;

        if ($decimals === 0) {
            $minorStr = $whole;
        } else {
            $fraction = str_pad(substr($fraction, 0, $decimals), $decimals, '0');
            $minorStr = $whole . $fraction;
        }

        $minorInt = (int)$minorStr;
        if ($isNegative) {
            $minorInt = -$minorInt;
        }

        return new self($minorInt, $currencyUpper);
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getDecimals(): int
    {
        return self::CURRENCY_SUBUNITS[$this->currency] ?? 2;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int $multiplier): self
    {
        return new self($this->amount * $multiplier, $this->currency);
    }

    /**
     * Integer-scaled multiplication with rounding to nearest minor unit.
     */
    public function multiplyScaled(int $factor, int $scale): self
    {
        if ($scale <= 0) {
            throw new InvalidArgumentException("Scale must be strictly positive: {$scale}");
        }

        // Pure integer math with standard round-half-up
        $scaled = $this->amount * $factor;
        $sign = ($scaled >= 0) ? 1 : -1;
        $abs = abs($scaled);
        $rounded = intdiv($abs + intdiv($scale, 2), $scale) * $sign;

        return new self($rounded, $this->currency);
    }

    public function equals(Money $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function greaterThan(Money $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->amount > $other->amount;
    }

    public function greaterThanOrEqual(Money $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->amount >= $other->amount;
    }

    public function lessThan(Money $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->amount < $other->amount;
    }

    public function lessThanOrEqual(Money $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->amount <= $other->amount;
    }

    /**
     * Convert minor units to a clean decimal string without float arithmetic.
     */
    public function toMajorUnit(): string
    {
        $decimals = $this->getDecimals();
        if ($decimals === 0) {
            return (string)$this->amount;
        }

        $isNeg = $this->amount < 0;
        $absAmount = abs($this->amount);
        $factor = 10 ** $decimals;

        $whole = intdiv($absAmount, $factor);
        $fraction = $absAmount % $factor;

        $fractionStr = str_pad((string)$fraction, $decimals, '0', STR_PAD_LEFT);
        $result = $whole . '.' . $fractionStr;

        return $isNeg ? '-' . $result : $result;
    }

    public function format(): string
    {
        return $this->toMajorUnit() . ' ' . $this->currency;
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Currency mismatch: cannot operate between {$this->currency} and {$other->currency}."
            );
        }
    }
}
