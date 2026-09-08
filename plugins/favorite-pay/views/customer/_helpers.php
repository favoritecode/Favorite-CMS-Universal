<?php
if (!function_exists('fpay_format_money')) {
    function fpay_format_money(int $minorUnits, string $currency = 'BDT'): string {
        $decimals = in_array(strtoupper($currency), ['JPY', 'KRW'], true) ? 0 : 2;
        $major = $minorUnits / (10 ** $decimals);
        $symbol = match (strtoupper($currency)) {
            'BDT' => '৳',
            'USD', 'USDT', 'USDC' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => strtoupper($currency) . ' ',
        };
        $formatted = number_format($major, $decimals);
        return $symbol . $formatted . ($symbol === '$' && in_array(strtoupper($currency), ['USDT', 'USDC'], true) ? ' ' . $currency : '');
    }
}
?>
