<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Support;

use FavoriteCMS\Models\Setting;
use Throwable;

/**
 * Places customer-facing Favorite Pay views inside the active CMS theme shell
 * dynamically resolved from Core CMS settings.
 */
final class CustomerThemeShell
{
    /**
     * Render the given view HTML within the active theme shell, or return raw HTML if no theme exists.
     */
    public static function render(string $innerHtml, string $pageTitle = 'Wallet & Balance', string $viewName = 'wallet'): string
    {
        $theme = self::resolveActiveTheme();
        if ($theme === null) {
            return $innerHtml;
        }

        [$content, $headHtml] = self::splitDocument($innerHtml);
        $siteName = 'Favorite CMS';
        try {
            if (class_exists(Setting::class)) {
                $siteName = (string)Setting::get('general', 'site_name', $siteName);
            }
        } catch (Throwable) {
        }

        $metaTitle = $pageTitle . ' — ' . $siteName;
        $metaDescription = '';
        $fwHasSidebar = false;
        $fcdHasSidebar = false;
        $bodyClass = 'favorite-customer-shell favorite-pay-customer-page';
        $additionalHeadHtml = $headHtml . self::themeModeStyles();

        $startObLevel = ob_get_level();
        ob_start();
        try {
            include $theme['header'];
            echo '<main class="site-main site-main--customer" id="main-content" tabindex="-1">';
            echo $content;
            echo '</main>';
            include $theme['footer'];
            $html = (string)ob_get_clean();
            // Favorite Multimedia intercepts these routes after the Pay handler.
            // Use its existing canonical-shell bypass only after a full render.
            if (!empty($GLOBALS['fm_theme_shell_wrapping'])) {
                $html = '<!-- fm-canonical_header_rendered -->' . $html;
            }
            return $html;
        } catch (Throwable $e) {
            while (ob_get_level() > $startObLevel) {
                ob_end_clean();
            }
            return $innerHtml;
        }
    }

    /** CSS only: mode state is owned by the active theme toggle/local preference. */
    private static function themeModeStyles(): string
    {
        return <<<'HTML'
<style id="favorite-pay-theme-mode-bridge">
/* Use the same native container as the theme header/footer. */
body.favorite-pay-customer-page .site-main--customer {
  width:100%; max-width:none; min-width:0; margin:0; padding:32px 0 64px;
}
body.favorite-pay-customer-page .fpay-customer-wrapper {
  width:100%; max-width:none; min-width:0; margin:0; padding:0; box-sizing:border-box;
}

.favorite-pay-customer-page { color: var(--text, #253044); }
html[data-theme="dark"] .favorite-pay-customer-page,
body[data-theme="dark"] .favorite-pay-customer-page,
html.dark .favorite-pay-customer-page,
body.dark .favorite-pay-customer-page,
html[data-bs-theme="dark"] .favorite-pay-customer-page,
body[data-bs-theme="dark"] .favorite-pay-customer-page {
  color: var(--text, #cbd5e1) !important;
  color-scheme: dark;
}
html[data-theme="dark"] .favorite-pay-customer-page :is(.fpay-card,.fpay-customer-wrapper .card,.fpay-customer-wrapper .panel,div[style*="background: #fff"],div[style*="background:#fff"],div[style*="background: #ffffff"],div[style*="background:#ffffff"],div[style*="background: white"],div[style*="background:white"]),
body[data-theme="dark"] .favorite-pay-customer-page :is(.fpay-card,.fpay-customer-wrapper .card,.fpay-customer-wrapper .panel,div[style*="background: #fff"],div[style*="background:#fff"],div[style*="background: #ffffff"],div[style*="background:#ffffff"],div[style*="background: white"],div[style*="background:white"]),
html.dark .favorite-pay-customer-page :is(.fpay-card,.fpay-customer-wrapper .card,.fpay-customer-wrapper .panel,div[style*="background: #fff"],div[style*="background:#fff"],div[style*="background: #ffffff"],div[style*="background:#ffffff"],div[style*="background: white"],div[style*="background:white"]),
body.dark .favorite-pay-customer-page :is(.fpay-card,.fpay-customer-wrapper .card,.fpay-customer-wrapper .panel,div[style*="background: #fff"],div[style*="background:#fff"],div[style*="background: #ffffff"],div[style*="background:#ffffff"],div[style*="background: white"],div[style*="background:white"]),
html[data-bs-theme="dark"] .favorite-pay-customer-page :is(.fpay-card,.fpay-customer-wrapper .card,.fpay-customer-wrapper .panel,div[style*="background: #fff"],div[style*="background:#fff"],div[style*="background: #ffffff"],div[style*="background:#ffffff"],div[style*="background: white"],div[style*="background:white"]),
body[data-bs-theme="dark"] .favorite-pay-customer-page :is(.fpay-card,.fpay-customer-wrapper .card,.fpay-customer-wrapper .panel,div[style*="background: #fff"],div[style*="background:#fff"],div[style*="background: #ffffff"],div[style*="background:#ffffff"],div[style*="background: white"],div[style*="background:white"]) {
  background: var(--surface, #111827) !important; border-color: var(--border, #334155) !important; color: var(--text, #cbd5e1) !important;
}
html[data-theme="dark"] .favorite-pay-customer-page :is(h1,h2,h3,h4,h5,h6,.fpay-page-title,.fpay-card-title),
body[data-theme="dark"] .favorite-pay-customer-page :is(h1,h2,h3,h4,h5,h6,.fpay-page-title,.fpay-card-title),
html.dark .favorite-pay-customer-page :is(h1,h2,h3,h4,h5,h6,.fpay-page-title,.fpay-card-title),
body.dark .favorite-pay-customer-page :is(h1,h2,h3,h4,h5,h6,.fpay-page-title,.fpay-card-title),
html[data-bs-theme="dark"] .favorite-pay-customer-page :is(h1,h2,h3,h4,h5,h6,.fpay-page-title,.fpay-card-title),
body[data-bs-theme="dark"] .favorite-pay-customer-page :is(h1,h2,h3,h4,h5,h6,.fpay-page-title,.fpay-card-title) { color: var(--heading, #f8fafc) !important; }
html[data-theme="dark"] .favorite-pay-customer-page :is(input:not([type="checkbox"]):not([type="radio"]),select,textarea),
body[data-theme="dark"] .favorite-pay-customer-page :is(input:not([type="checkbox"]):not([type="radio"]),select,textarea),
html.dark .favorite-pay-customer-page :is(input:not([type="checkbox"]):not([type="radio"]),select,textarea),
body.dark .favorite-pay-customer-page :is(input:not([type="checkbox"]):not([type="radio"]),select,textarea),
html[data-bs-theme="dark"] .favorite-pay-customer-page :is(input:not([type="checkbox"]):not([type="radio"]),select,textarea),
body[data-bs-theme="dark"] .favorite-pay-customer-page :is(input:not([type="checkbox"]):not([type="radio"]),select,textarea) { background: #0b1120 !important; color: #e2e8f0 !important; border-color: #475569 !important; color-scheme: dark; }
html[data-theme="dark"] .favorite-pay-customer-page :is(table,thead,tbody,tr,td,th),
body[data-theme="dark"] .favorite-pay-customer-page :is(table,thead,tbody,tr,td,th),
html.dark .favorite-pay-customer-page :is(table,thead,tbody,tr,td,th),
body.dark .favorite-pay-customer-page :is(table,thead,tbody,tr,td,th),
html[data-bs-theme="dark"] .favorite-pay-customer-page :is(table,thead,tbody,tr,td,th),
body[data-bs-theme="dark"] .favorite-pay-customer-page :is(table,thead,tbody,tr,td,th) { border-color: var(--border, #334155) !important; }
</style>
HTML;
    }

    /**
     * Dynamically resolve the active theme from Core CMS.
     *
     * @return array{header:string,footer:string,theme_id:string,has_customer_shell:bool}|null
     */
    public static function resolveActiveTheme(): ?array
    {
        $activeThemeId = null;
        if (function_exists('active_theme_id')) {
            $activeThemeId = active_theme_id();
        }
        if ($activeThemeId === null || $activeThemeId === '') {
            try {
                if (class_exists(Setting::class)) {
                    $activeThemeId = (string)Setting::get('theme', 'active_theme', 'favorite-web');
                }
            } catch (Throwable) {
                $activeThemeId = 'favorite-web';
            }
        }
        if ($activeThemeId === null || preg_match('/^[a-zA-Z0-9_-]+$/', $activeThemeId) !== 1) {
            $activeThemeId = 'favorite-web';
        }

        $themeIdsToTry = array_unique([$activeThemeId, 'favorite-web', 'default']);

        $appRoot = defined('APP_ROOT') ? APP_ROOT : (function_exists('app_root') ? app_root() : dirname(__DIR__, 4));
        $rootDirs = array_unique([
            rtrim($appRoot, '/\\'),
            dirname(__DIR__, 4),
            'E:/Favorite-CMS-Universal',
            'E:/Favorite-CMS-Assets',
        ]);

        foreach ($themeIdsToTry as $tid) {
            foreach ($rootDirs as $root) {
                $themeDir = $root . '/themes/' . $tid;
                $header = $themeDir . '/header.php';
                $footer = $themeDir . '/footer.php';
                if (is_dir($themeDir) && is_file($header) && is_file($footer)) {
                    $manifestPath = $themeDir . '/theme.json';
                    $hasCustomerShell = false;
                    if (is_file($manifestPath)) {
                        $manifest = json_decode((string)file_get_contents($manifestPath), true);
                        $hasCustomerShell = is_array($manifest) && !empty($manifest['features']['customer_shell']);
                    } else {
                        $hasCustomerShell = true;
                    }
                    return [
                        'header'             => $header,
                        'footer'             => $footer,
                        'theme_id'           => $tid,
                        'has_customer_shell' => $hasCustomerShell,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Backward compatibility alias for existing callers/tests.
     *
     * @return array{header:string,footer:string,theme_id:string}|null
     */
    public static function supportedTheme(): ?array
    {
        $theme = self::resolveActiveTheme();
        if ($theme === null) {
            return null;
        }
        return [
            'header'   => $theme['header'],
            'footer'   => $theme['footer'],
            'theme_id' => $theme['theme_id'],
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function splitDocument(string $html): array
    {
        $headHtml = '';
        if (preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $html, $styles) === 1 || !empty($styles[0])) {
            $cleanedStyles = [];
            foreach ($styles[1] as $css) {
                $css = preg_replace('/(?<![a-zA-Z0-9_-])body\s*\{([^}]*)\}/i', '.site-main--customer { $1 }', $css);
                $css = preg_replace('/background:\s*#f8fafc;?/i', '', $css);
                $cleanedStyles[] = '<style>' . $css . '</style>';
            }
            $headHtml = implode("\n", $cleanedStyles);
            $html = (string)preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);
        }

        if (preg_match('#<body\b[^>]*>(.*)</body>#is', $html, $body) === 1) {
            return [trim($body[1]), $headHtml];
        }

        return [trim($html), $headHtml];
    }
}
