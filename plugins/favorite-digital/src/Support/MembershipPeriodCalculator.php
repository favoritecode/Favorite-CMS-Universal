<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Support;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * MembershipPeriodCalculator
 *
 * Provides deterministic calendar-aware duration calculations and membership extensions.
 * Guarantees that "1 Month" evaluates to exactly 1 calendar month with strict month-end clamping,
 * preventing PHP's native DateTime::modify('+1 month') overflow anomalies (e.g. Jan 31 -> Mar 3).
 */
final class MembershipPeriodCalculator
{
    /**
     * Add exact calendar months with deterministic month-end clamping.
     *
     * Examples:
     * - Jan 15 -> Feb 15
     * - Jan 31 (2026) -> Feb 28 (2026)
     * - Jan 31 (2028 leap) -> Feb 29 (2028)
     * - Mar 31 -> Apr 30
     * - Aug 31 -> Sep 30
     * - Dec 31 -> Jan 31 (following year)
     */
    public static function addCalendarMonths(DateTimeInterface $from, int $months = 1): DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromInterface($from);
        $day = (int)$dt->format('j');
        $month = (int)$dt->format('n');
        $year = (int)$dt->format('Y');

        $totalMonths = ($year * 12 + ($month - 1)) + $months;
        $targetYear = intdiv($totalMonths, 12);
        $targetMonth = ($totalMonths % 12) + 1;

        // Determine maximum days in target month
        $daysInTargetMonth = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $targetYear, $targetMonth)))->format('t');
        $targetDay = min($day, $daysInTargetMonth);

        $time = $dt->format('H:i:s');
        return new DateTimeImmutable(
            sprintf('%04d-%02d-%02d %s', $targetYear, $targetMonth, $targetDay, $time),
            $dt->getTimezone()
        );
    }

    /**
     * Calculate expiry from a start time based on duration unit and count.
     *
     * Supported units:
     * - 'month': Uses deterministic calendar month calculation (count * 1 calendar month)
     * - 'day': Adds count * 1 day
     * - 'week': Adds count * 7 days
     */
    public static function calculatePeriodExpiry(DateTimeInterface $from, string $unit, int $count = 1): DateTimeImmutable
    {
        $count = max(1, $count);
        $normalizedUnit = strtolower(trim($unit));

        return match ($normalizedUnit) {
            'month' => self::addCalendarMonths($from, $count),
            'week'  => DateTimeImmutable::createFromInterface($from)->modify('+' . ($count * 7) . ' days'),
            'day'   => DateTimeImmutable::createFromInterface($from)->modify('+' . $count . ' days'),
            default => DateTimeImmutable::createFromInterface($from)->modify('+' . $count . ' days'),
        };
    }

    /**
     * Calculate new membership expiry time preserving any remaining active time.
     *
     * Extension rule:
     * If the current membership is still active (currentExpiry > now), the new duration
     * is appended onto currentExpiry so zero paid time is lost.
     * If already expired or null, new duration begins from now.
     */
    public static function calculateExtensionExpiry(
        ?DateTimeInterface $currentExpiry,
        DateTimeInterface $now,
        string $unit,
        int $count = 1
    ): DateTimeImmutable {
        $nowImmutable = DateTimeImmutable::createFromInterface($now);

        if ($currentExpiry !== null) {
            $expiryImmutable = DateTimeImmutable::createFromInterface($currentExpiry);
            if ($expiryImmutable > $nowImmutable) {
                // Member has active remaining time: append onto existing expiration
                return self::calculatePeriodExpiry($expiryImmutable, $unit, $count);
            }
        }

        // Fresh or expired membership: start from now
        return self::calculatePeriodExpiry($nowImmutable, $unit, $count);
    }
}
