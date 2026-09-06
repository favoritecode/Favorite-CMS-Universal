<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Support;

/**
 * ProductPricingCalculator
 *
 * Enforces the locked catalog pricing rules:
 * - original_price is the catalog original price.
 * - discount_percent is the percentage discount (0.00 to 100.00).
 * - final_price is the derived selling price: original_price * (1 - discount_percent / 100).
 * - if is_free is true, final_price is 0.00.
 */
final class ProductPricingCalculator
{
    /**
     * Derive final_price strictly from original_price and discount_percent.
     */
    public static function deriveFinalPrice(
        string|float|int $originalPrice,
        string|float|int $discountPercent = 0,
        bool $isFree = false
    ): string {
        if ($isFree) {
            return '0.00';
        }

        $orig = (float)$originalPrice;
        $disc = (float)$discountPercent;

        if ($orig <= 0.0) {
            return '0.00';
        }

        if ($disc <= 0.0) {
            return number_format($orig, 2, '.', '');
        }

        if ($disc >= 100.0) {
            return '0.00';
        }

        $multiplier = 1.0 - ($disc / 100.0);
        $final = round($orig * $multiplier, 2);

        return number_format(max(0.0, $final), 2, '.', '');
    }
}
