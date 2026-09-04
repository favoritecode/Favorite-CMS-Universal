<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Support;

use InvalidArgumentException;

/**
 * DecimalFormatter
 *
 * Provides exact string-based conversion between integer minor units
 * and decimal strings without binary floating-point arithmetic.
 */
final class DecimalFormatter
{
    /**
     * Convert minor unit integer to exact decimal string.
     * Example: minorUnitToDecimal(1050, 2) => "10.50"
     * Example: minorUnitToDecimal(5, 2) => "0.05"
     * Example: minorUnitToDecimal(100, 0) => "100"
     */
    public static function minorUnitToDecimal(int $minorUnits, int $scale = 2): string
    {
        if ($scale < 0 || $scale > 18) {
            throw new InvalidArgumentException("Scale must be between 0 and 18.");
        }

        $negative = $minorUnits < 0;
        $abs = abs($minorUnits);
        $str = (string)$abs;

        if ($scale === 0) {
            return ($negative ? '-' : '') . $str;
        }

        if (strlen($str) <= $scale) {
            $str = str_pad($str, $scale + 1, '0', STR_PAD_LEFT);
        }

        $intPart = substr($str, 0, strlen($str) - $scale);
        $decPart = substr($str, -$scale);

        return ($negative ? '-' : '') . $intPart . '.' . $decPart;
    }

    /**
     * Convert exact decimal string to integer minor units.
     * Example: decimalToMinorUnits("10.50", 2) => 1050
     * Example: decimalToMinorUnits("0.05", 2) => 5
     * Example: decimalToMinorUnits("100", 2) => 10000
     */
    public static function decimalToMinorUnits(string|int|float $decimal, int $scale = 2): int
    {
        if ($scale < 0 || $scale > 18) {
            throw new InvalidArgumentException("Scale must be between 0 and 18.");
        }

        $decimalStr = trim((string)$decimal);

        if (!preg_match('/^-?\d+(\.\d+)?$/', $decimalStr)) {
            throw new InvalidArgumentException("Invalid decimal string format: '{$decimalStr}'");
        }

        $negative = str_starts_with($decimalStr, '-');
        if ($negative) {
            $decimalStr = substr($decimalStr, 1);
        }

        $parts = explode('.', $decimalStr);
        $intPart = $parts[0];
        $decPart = $parts[1] ?? '';

        if (strlen($decPart) > $scale) {
            $extra = substr($decPart, $scale);
            if (rtrim($extra, '0') !== '') {
                throw new InvalidArgumentException("Decimal precision exceeds scale {$scale}: '{$decimalStr}'");
            }
            $decPart = substr($decPart, 0, $scale);
        } else {
            $decPart = str_pad($decPart, $scale, '0', STR_PAD_RIGHT);
        }

        $combined = ltrim($intPart . $decPart, '0');
        if ($combined === '') {
            return 0;
        }

        $result = (int)$combined;
        return $negative ? -$result : $result;
    }
}
