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
 * 3. Freshness and expiration validation.
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
    private string $source;

    public function __construct(
        string $fromCurrency,
        string $toCurrency,
        int $rateFactor,
        int $rateScale = self::DEFAULT_SCALE,
        bool $isAuthoritative = true,
        ?string $lockedAt = null,
        ?string $expiresAt = null,
        string $source = 'operator'
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
        $this->source = $source;
    }

    public static function create(
        string $fromCurrency,
        string $toCurrency,
        string $rateDecimalString,
        bool $isAuthoritative = true,
        int $scale = self::DEFAULT_SCALE,
        ?string $expiresAt = null,
        string $source = 'operator'
    ): self {
        $raw = trim($rateDecimalString);
        if ($raw === '' || !preg_match('/^\d+(\.\d+)?$/', $raw)) {
            throw new InvalidArgumentException("Invalid rate string: '{$rateDecimalString}'");
        }

        $parts = explode('.', $raw, 2);
        $whole = (int)$parts[0];
        $fraction = $parts[1] ?? '';
        $fracLen = strlen($fraction);

        if ($fracLen > 0) {
            $pow10 = 10 ** $fracLen;
            $fractionVal = (int)$fraction;
            $numerator = $whole * $pow10 + $fractionVal;
            $rateFactor = intdiv($numerator * $scale + intdiv($pow10, 2), $pow10);
        } else {
            $rateFactor = $whole * $scale;
        }

        if ($rateFactor <= 0) {
            throw new InvalidArgumentException("Calculated rate factor must be positive: {$rateFactor}");
        }

        return new self($fromCurrency, $toCurrency, $rateFactor, $scale, $isAuthoritative, null, $expiresAt, $source);
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

    public function getSource(): string
    {
        return $this->source;
    }

    public function isExpired(?int $nowTimestamp = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        $now = $nowTimestamp ?? time();
        return strtotime($this->expiresAt) < $now;
    }

    public function isEffective(?int $nowTimestamp = null): bool
    {
        $now = $nowTimestamp ?? time();
        return strtotime($this->lockedAt) <= $now;
    }

    public function isValidForPayment(?int $nowTimestamp = null): bool
    {
        if (!$this->isAuthoritative) {
            return false;
        }
        if ($this->rateFactor <= 0) {
            return false;
        }
        if ($this->isExpired($nowTimestamp)) {
            return false;
        }
        return true;
    }

    public function getRateDecimalString(): string
    {
        $whole = intdiv($this->rateFactor, $this->rateScale);
        $fraction = $this->rateFactor % $this->rateScale;
        $fracStr = rtrim(str_pad((string)$fraction, 6, '0', STR_PAD_LEFT), '0');
        return $fracStr === '' ? (string)$whole : "{$whole}.{$fracStr}";
    }

    public function toArray(): array
    {
        return [
            'from_currency'    => $this->fromCurrency,
            'to_currency'      => $this->toCurrency,
            'rate_factor'      => $this->rateFactor,
            'rate_scale'       => $this->rateScale,
            'rate_decimal'     => $this->getRateDecimalString(),
            'is_authoritative' => $this->isAuthoritative,
            'locked_at'        => $this->lockedAt,
            'expires_at'       => $this->expiresAt,
            'source'           => $this->source,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string)$data['from_currency'],
            (string)$data['to_currency'],
            (int)$data['rate_factor'],
            (int)($data['rate_scale'] ?? self::DEFAULT_SCALE),
            (bool)($data['is_authoritative'] ?? true),
            isset($data['locked_at']) ? (string)$data['locked_at'] : null,
            isset($data['expires_at']) ? (string)$data['expires_at'] : null,
            (string)($data['source'] ?? 'operator')
        );
    }

    /**
     * Convert source Money into target Money using locked integer rate.
     * Accurately adjusts for different subunit decimal counts without float math.
     */
    public function convert(Money $source): Money
    {
        if ($source->getCurrency() !== $this->fromCurrency) {
            throw new InvalidArgumentException(
                "Source currency mismatch: expected {$this->fromCurrency}, got {$source->getCurrency()}"
            );
        }

        if ($this->fromCurrency === $this->toCurrency) {
            return new Money($source->getAmount(), $this->toCurrency);
        }

        if ($source->getAmount() === 0) {
            return new Money(0, $this->toCurrency);
        }

        $fromDecimals = Money::getCurrencyDecimals($this->fromCurrency);
        $toDecimals = Money::getCurrencyDecimals($this->toCurrency);

        if ($toDecimals >= $fromDecimals) {
            $subDiff = $toDecimals - $fromDecimals;
            $scaledSourceMinor = $source->getAmount() * (10 ** $subDiff);
            $convertedMinor = intdiv($scaledSourceMinor * $this->rateFactor + intdiv($this->rateScale, 2), $this->rateScale);
        } else {
            $subDiff = $fromDecimals - $toDecimals;
            $effectiveScale = $this->rateScale * (10 ** $subDiff);
            $convertedMinor = intdiv($source->getAmount() * $this->rateFactor + intdiv($effectiveScale, 2), $effectiveScale);
        }

        return new Money($convertedMinor, $this->toCurrency);
    }
}
