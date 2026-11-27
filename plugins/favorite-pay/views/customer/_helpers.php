<?php
if (!function_exists('fpay_format_money')) {
    function fpay_format_money(int|float|string $minorUnits, ?string $currency = null): string {
        $curr = $currency !== null && trim($currency) !== '' 
            ? strtoupper(trim($currency)) 
            : (class_exists(\FavoriteCMS\Core\Currency::class) ? \FavoriteCMS\Core\Currency::getPrimaryCurrency() : 'BDT');
        $units = (int)round((float)$minorUnits);
        $decimals = class_exists(\FavoriteCMS\Core\Currency::class) 
            ? \FavoriteCMS\Core\Currency::getDecimals($curr) 
            : (in_array($curr, ['JPY', 'KRW'], true) ? 0 : 2);
        $major = $units / (10 ** $decimals);

        if (class_exists(\FavoriteCMS\Core\Currency::class)) {
            $symbol = \FavoriteCMS\Core\Currency::getSymbol($curr);
        } else {
            $symbol = match ($curr) {
                'BDT' => '৳',
                'INR' => '₹',
                'USD', 'USDT', 'USDC' => '$',
                'EUR' => '€',
                'GBP' => '£',
                'JPY' => '¥',
                default => $curr . ' ',
            };
        }

        $formatted = number_format($major, $decimals);
        return $symbol . $formatted . ($symbol === '$' && in_array($curr, ['USDT', 'USDC'], true) ? ' ' . $curr : '');
    }
}
?>
