<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Domain;

use InvalidArgumentException;

/**
 * Immutable snapshot capturing exchange rate at checkout time.
 *
 * Implements the Locked Decision:
 * 1. Hybrid Operator + Automated Sync: Operator rate is authoritative.
 * 2. Scaled integer rate math (no floats).
 */
final class ConversionSnapshot
{
    public const DEFAULT_SCALE = 1000000; // 6 decimal scale for precision

    private string $fromCurrency;
    private string $toCurrency;
    private int $rateFactor;
    private int $rateScale;
    private bool $isAuthoritative;
    private string $lockedAt;
    private ?string $expiresAt;

    public function __construct(
        string $fromCurrency,
        string $toCurrency,
        int $rateFactor,
        int $rateScale = self::DEFAULT_SCALE,
        bool $isAuthoritative = true,
        ?string $lockedAt = null,
        ?string $expiresAt = null
    ) {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));

        if ($from === '' || $to === '') {
            throw new InvalidArgumentException("From and To currency codes are required.");
        }
        if ($rateFactor <= 0) {
            throw new InvalidArgumentException("Rate factor must be positive: {$rateFactor}");
        }
        if ($rateScale <= 0) {
            throw new InvalidArgumentException("Rate scale must be positive: {$rateScale}");
        }

        $this->fromCurrency = $from;
        $this->toCurrency = $to;
        $this->rateFactor = $rateFactor;
        $this->rateScale = $rateScale;
        $this->isAuthoritative = $isAuthoritative;
        $this->lockedAt = $lockedAt ?? date('Y-m-d H:i:s');
        $this->expiresAt = $expiresAt;
    }

    public static function create(
        string $fromCurrency,
        string $toCurrency,
        string $rateDecimalString,
        bool $isAuthoritative = true,
        int $scale = self::DEFAULT_SCALE
    ): self {
        $rateMoney = Money::fromMajorString($rateDecimalString, 'USD'); // parse decimal safely
        // Scale rateMoney to required scale factor
        $decimals = $rateMoney->getDecimals();
        $rateFactor = intdiv($rateMoney->getAmount() * $scale, 10 ** $decimals);

        return new self($fromCurrency, $toCurrency, $rateFactor, $scale, $isAuthoritative);
    }

    public function getFromCurrency(): string
    {
        return $this->fromCurrency;
    }

    public function getToCurrency(): string
    {
        return $this->toCurrency;
    }

    public function getRateFactor(): int
    {
        return $this->rateFactor;
    }

    public function getRateScale(): int
    {
        return $this->rateScale;
    }

    public function isAuthoritative(): bool
    {
        return $this->isAuthoritative;
    }

    public function getLockedAt(): string
    {
        return $this->lockedAt;
    }

    public function getExpiresAt(): ?string
    {
        return $this->expiresAt;
    }

    /**
     * Convert source Money into target Money using locked integer rate.
     */
    public function convert(Money $source): Money
    {
        if ($source->getCurrency() !== $this->fromCurrency) {
            throw new InvalidArgumentException(
                "Source money currency ({$source->getCurrency()}) does not match snapshot fromCurrency ({$this->fromCurrency})."
            );
        }

        if ($this->fromCurrency === $this->toCurrency) {
            return new Money($source->getAmount(), $this->toCurrency);
        }

        // Target amount in minor units = (sourceMinor * rateFactor) / rateScale
        $convertedMinor = $source->multiplyScaled($this->rateFactor, $this->rateScale)->getAmount();
        return new Money($convertedMinor, $this->toCurrency);
    }
}
