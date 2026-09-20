<?php
/**
 * Favorite Default Theme — presentation helpers.
 *
 * Theme-only helpers (prefixed fcd_) shared by templates and partials. Generic behavior that every
 * theme needs (base-path URLs, theme asset URLs, current-URL matching) lives in Core helpers.
 */

if (!function_exists('fcd_e')) {
    /** Escape a scalar value for HTML output. */
    function fcd_e(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string)$value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('fcd_url')) {
    /** Base-path-aware URL for a site path (falls back gracefully if Core helpers are unavailable). */
    function fcd_url(string $path): string
    {
        return function_exists('site_path') ? site_path($path) : $path;
    }
}

if (!function_exists('fcd_partial')) {
    /**
     * Render a theme partial (themes/default/partials/{name}.php) with an isolated variable scope.
     */
    function fcd_partial(string $name, array $vars = []): void
    {
        if (preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)?$#', $name) !== 1) {
            return;
        }
        $file = __DIR__ . '/partials/' . $name . '.php';
        if (!is_file($file)) {
            return;
        }
        (static function (string $__partialFile, array $__partialVars): void {
            extract($__partialVars, EXTR_SKIP);
            include $__partialFile;
        })($file, $vars);
    }
}

if (!function_exists('fcd_initial')) {
    /** First character of a name (multibyte and grapheme safe), uppercased where the script has case. */
    function fcd_initial(?string $name, string $fallback = 'A'): string
    {
        $name = trim((string)$name);
        if ($name === '') {
            $name = $fallback;
        }
        if (function_exists('grapheme_substr')) {
            $first = grapheme_substr($name, 0, 1);
            if (is_string($first) && $first !== '') {
                return mb_strtoupper($first, 'UTF-8');
            }
        }
        return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
    }
}

if (!function_exists('fcd_display_name')) {
    /** Display name of a user object (name, then username, then fallback). */
    function fcd_display_name(?object $user, string $fallback = 'Admin'): string
    {
        if (!$user) {
            return $fallback;
        }
        $name = trim((string)($user->name ?? ''));
        if ($name !== '') {
            return $name;
        }
        $username = trim((string)($user->username ?? ''));
        return $username !== '' ? $username : $fallback;
    }
}

if (!function_exists('fcd_read_time')) {
    /**
     * Estimated reading time in minutes (200 words per minute).
     * ASCII text keeps the original str_word_count() behavior; other scripts (e.g. Bangla) use Unicode word tokens.
     */
    function fcd_read_time(?string $content): int
    {
        $text = strip_tags((string)$content);
        if (trim($text) === '') {
            return 1;
        }

        if (preg_match('/[^\x00-\x7F]/', $text) === 1) {
            $count = preg_match_all('/[\p{L}\p{M}\p{N}]+/u', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $words = is_int($count) ? $count : str_word_count($text);
        } else {
            $words = str_word_count($text);
        }

        return max(1, (int)ceil($words / 200));
    }
}

if (!function_exists('fcd_excerpt')) {
    /** Manual excerpt, or a plain-text summary of the content trimmed to $length characters. */
    function fcd_excerpt(object $post, int $length = 170): string
    {
        $excerpt = trim((string)($post->excerpt ?? ''));
        if ($excerpt !== '') {
            return $excerpt;
        }

        $plain = html_entity_decode(strip_tags((string)($post->content ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim((string)(preg_replace('/\s+/u', ' ', $plain) ?? $plain));
        if (mb_strlen($plain, 'UTF-8') <= $length) {
            return $plain;
        }
        return rtrim(mb_substr($plain, 0, $length, 'UTF-8')) . '…';
    }
}

if (!function_exists('fcd_image_dimensions')) {
    /** width/height attributes for a media record, only when both dimensions are known. */
    function fcd_image_dimensions(?object $media): string
    {
        if (!$media) {
            return '';
        }
        $width = (int)($media->width ?? 0);
        $height = (int)($media->height ?? 0);
        return ($width > 0 && $height > 0) ? ' width="' . $width . '" height="' . $height . '"' : '';
    }
}

if (!function_exists('fcd_prepare_content')) {
    /**
     * Presentation-only adjustments to already sanitized post/page HTML:
     * tables are wrapped in a keyboard-focusable horizontal scroll region (table semantics stay intact).
     */
    function fcd_prepare_content(string $html): string
    {
        if ($html === '' || stripos($html, '<table') === false) {
            return $html;
        }

        $opening = preg_match_all('/<table\b/i', $html);
        $closing = preg_match_all('/<\/table\s*>/i', $html);
        if ($opening === false || $opening !== $closing) {
            return $html;
        }

        $html = preg_replace('/<table\b/i', '<div class="table-scroll" role="region" aria-label="Scrollable table" tabindex="0"><table', $html) ?? $html;
        return preg_replace('/<\/table\s*>/i', '</table></div>', $html) ?? $html;
    }
}

if (!function_exists('fcd_accent_color')) {
    /** The Customizer accent color, only when it is a valid hex color (#rgb or #rrggbb). */
    function fcd_accent_color(): string
    {
        $raw = function_exists('get_theme_mod') ? get_theme_mod('accent_color', '') : '';
        $raw = is_string($raw) ? trim($raw) : '';
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $raw) === 1 ? strtolower($raw) : '';
    }
}

if (!function_exists('fcd_site_layout')) {
    /** Customizer sidebar layout: right, left or none. */
    function fcd_site_layout(): string
    {
        $layout = function_exists('get_theme_mod') ? get_theme_mod('site_layout', 'right') : 'right';
        return in_array($layout, ['right', 'left', 'none'], true) ? $layout : 'right';
    }
}

if (!function_exists('fcd_registration_enabled')) {
    function fcd_registration_enabled(): bool
    {
        try {
            return (bool)(int)\FavoriteCMS\Models\Setting::get('general', 'allow_registration', 1);
        } catch (\Throwable) {
            return false;
        }
    }
}

if (!function_exists('fcd_menu_items')) {
    /**
     * Items for a menu location. When no menu is assigned, falls back to a bounded list of published pages.
     *
     * @return array{assigned: bool, items: array<int, object>}
     */
    function fcd_menu_items(string $location, int $fallbackPageLimit = 4): array
    {
        try {
            $menu = \FavoriteCMS\Models\Menu::findByLocation($location);
            if ($menu) {
                return ['assigned' => true, 'items' => $menu->getItems()];
            }
        } catch (\Throwable) {
        }

        $items = [];
        try {
            foreach (\FavoriteCMS\Models\Page::published($fallbackPageLimit) as $page) {
                $items[] = (object)['title' => $page->title, 'url' => '/page/' . $page->slug, 'target' => '', 'children' => []];
            }
        } catch (\Throwable) {
        }

        return ['assigned' => false, 'items' => $items];
    }
}

if (!function_exists('fcd_menu_links_home')) {
    /** Whether any top-level menu item already points to the homepage. */
    function fcd_menu_links_home(array $items): bool
    {
        foreach ($items as $item) {
            $url = trim((string)($item->url ?? ''));
            if ($url === '' || (!str_starts_with($url, '/') && !preg_match('#^https?://#i', $url))) {
                continue;
            }
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && strtolower($host) !== strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0])) {
                continue;
            }
            $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
            if (function_exists('site_request_path') && site_request_path($path) === '/') {
                return true;
            }
        }
        return false;
    }
}
