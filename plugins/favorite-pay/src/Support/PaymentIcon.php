<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Support;

/**
 * PaymentIcon — Self-contained, lightweight SVG icon renderer for Favorite Pay.
 *
 * Emits pure vector SVG markup compliant with Favorite CMS design tokens.
 * Requires zero external CDNs, zero third-party font libraries, and operates safely
 * in all hosting environments (root domains, subdirectories, HTTP/HTTPS).
 */
class PaymentIcon
{
    /**
     * Built-in vector SVG definitions (viewBox 0 0 24 24, stroke="currentColor", stroke-width="2").
     */
    private const ICONS = [
        'wallet' => '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line>',
        'balance' => '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line>',
        'wallet-balance' => '<path d="M19 10H5a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2z"></path><circle cx="12" cy="6" r="3.5"></circle><line x1="12" y1="4.5" x2="12" y2="7.5"></line><path d="M17 15h4"></path><circle cx="17.5" cy="15" r="0.75" fill="currentColor"></circle>',
        'recharge' => '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line>',
        'deposit' => '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line>',
        'withdraw' => '<line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>',
        'payout' => '<line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>',
        'payments' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
        'receipt' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
        'payment-history' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>',
        'notifications' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path>',
        'bell' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path>',
        'notification' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path>',
        'transactions' => '<line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line>',
        'exchange' => '<line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line>',
        'transaction' => '<line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line>',
        'payment-confirmed' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline>',
        'payment-failed' => '<circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line>',
        'payment-processing' => '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>',
        'refund' => '<polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>',
        'credit-card' => '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line>',
    ];

    /**
     * Render a safe inline SVG element by icon identifier.
     *
     * @param string $name Icon name or alias (e.g. 'wallet', 'recharge', 'withdraw')
     * @param array<string, string|int|bool> $attributes Optional HTML/SVG attributes (e.g. ['width' => 16, 'height' => 16])
     * @return string Valid SVG markup, or empty string if icon identifier is unknown.
     */
    public static function render(string $name, array $attributes = []): string
    {
        $cleanName = strtolower(trim($name));
        $cleanName = str_replace(['fas fa-', 'fa-', ' '], ['', '', '-'], $cleanName);

        if (!isset(self::ICONS[$cleanName])) {
            // Attempt to load from plugin asset directory if available
            $assetInner = self::loadFromAssets($cleanName);
            if ($assetInner === null) {
                return '';
            }
            $innerPaths = $assetInner;
        } else {
            $innerPaths = self::ICONS[$cleanName];
        }

        // Build sanitized attributes
        $defaultAttrs = [
            'viewBox'         => '0 0 24 24',
            'fill'            => 'none',
            'stroke'          => 'currentColor',
            'stroke-width'    => '2',
            'stroke-linecap'  => 'round',
            'stroke-linejoin' => 'round',
            'aria-hidden'     => 'true',
        ];

        $merged = array_merge($defaultAttrs, $attributes);

        // Security check: reject event handlers or unsafe attributes
        $attrString = '';
        foreach ($merged as $key => $value) {
            $keyLower = strtolower(trim((string)$key));
            if (!preg_match('/^[a-z0-9_\-]+$/', $keyLower)) {
                continue;
            }
            if (str_starts_with($keyLower, 'on') || in_array($keyLower, ['src', 'href', 'xlink:href'], true)) {
                continue;
            }
            $attrName = ($keyLower === 'viewbox') ? 'viewBox' : $keyLower;
            if (is_bool($value)) {
                if ($value) {
                    $attrString .= ' ' . htmlspecialchars($attrName, ENT_QUOTES, 'UTF-8') . '="true"';
                }
            } else {
                $valStr = (string)$value;
                if (preg_match('/<script|javascript:/i', $valStr)) {
                    continue;
                }
                $attrString .= ' ' . htmlspecialchars($attrName, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars($valStr, ENT_QUOTES, 'UTF-8') . '"';
            }
        }

        return '<svg' . $attrString . '>' . $innerPaths . '</svg>';
    }

    /**
     * Check if an icon identifier is supported.
     */
    public static function has(string $name): bool
    {
        $cleanName = strtolower(trim($name));
        $cleanName = str_replace(['fas fa-', 'fa-', ' '], ['', '', '-'], $cleanName);
        if (isset(self::ICONS[$cleanName])) {
            return true;
        }
        return self::loadFromAssets($cleanName) !== null;
    }

    /**
     * Return list of all built-in icon names.
     *
     * @return list<string>
     */
    public static function getSupportedIcons(): array
    {
        return array_keys(self::ICONS);
    }

    /**
     * Attempt to load inner SVG paths from plugin asset directory.
     */
    private static function loadFromAssets(string $name): ?string
    {
        $assetFile = dirname(__DIR__, 2) . '/assets/icons/' . $name . '.svg';
        if (!file_exists($assetFile) || !is_readable($assetFile)) {
            return null;
        }

        $content = (string)file_get_contents($assetFile);
        if ($content === '' || preg_match('/<script|onload|onerror|javascript:/i', $content)) {
            return null;
        }

        // Extract inner elements between <svg...> and </svg>
        if (preg_match('/<svg[^>]*>(.*?)<\/svg>/is', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
