<?php

declare(strict_types=1);

namespace FavoriteCMS\Rendering;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Models\Setting;

class Engine
{
    protected Application $app;
    protected string $activeTheme = 'default';

    public function __construct(Application $app)
    {
        $this->app = $app;
        try {
            $this->activeTheme = (new \FavoriteCMS\Themes\ThemeManager($this->app))->getActiveTheme();
        } catch (\Throwable) {
            $this->activeTheme = 'default';
        }
    }

    public function getActiveTheme(): string
    {
        return $this->activeTheme;
    }

    public function setActiveTheme(string $theme): void
    {
        $this->activeTheme = $theme;
    }

    protected static array $customTemplatePaths = [];

    public static function addTemplatePath(string $path): void
    {
        if (is_dir($path) && !in_array($path, static::$customTemplatePaths, true)) {
            static::$customTemplatePaths[] = rtrim($path, '/\\');
        }
    }

    /**
     * Render a template with data, resolving across Theme, Plugin, and Core.
     */
    public function render(string $template, array $data = []): string
    {
        $automaticSeo = \FavoriteCMS\Services\SeoMetadata::resolve($data);
        $data['metaTitle'] = $automaticSeo['title'];
        $data['metaDescription'] = $automaticSeo['description'];
        if (in_array($template, ['404','search'], true)) $data['robots'] = 'noindex,follow';
        $templatePath = $this->resolveTemplate($template);

        // Allow plugins to filter template file path
        $templatePath = \FavoriteCMS\Core\Hook::applyFilters('template_include', $templatePath, $template, $data);

        if (!$templatePath || !file_exists($templatePath)) {
            // Fallback to 404 or basic error
            $fallback = $this->resolveTemplate('404');
            if ($fallback && file_exists($fallback)) {
                $html = $this->evaluateTemplate($fallback, $data);
                return $this->injectBackToTop($html);
            }
            throw new \RuntimeException("Template not found: {$template}");
        }

        $record = $template === 'single' ? ($data['post'] ?? null) : ($template === 'page' ? ($data['page'] ?? null) : null);
        $html = is_object($record)
            ? \FavoriteCMS\Services\ContentLayout::render($record, $template === 'single' ? 'post' : 'page', $this->activeTheme, fn() => $this->evaluateTemplate($templatePath, $data))
            : $this->evaluateTemplate($templatePath, $data);
        // Optional, fail-open public chrome integration. With no plugin/filter the
        // rendered output is byte-for-byte unchanged from previous versions.
        try {
            $filtered = \FavoriteCMS\Core\Hook::applyFilters('favorite_cms_public_html', $html, $template, $data);
            if (is_string($filtered) && $filtered !== '') $html = $filtered;
        } catch (\Throwable $e) {
            error_log('Favorite CMS public HTML filter: ' . $e->getMessage());
        }
        $html = $this->injectSingleFeaturedImageStyles($html, $template);
        return $this->injectBackToTop($html);
    }

    /** Preserve the entire main post image in the bundled/official theme shells. */
    protected function injectSingleFeaturedImageStyles(string $html, string $template): string
    {
        if ($template !== 'single' || !in_array($this->activeTheme, ['default', 'favorite-web'], true)
            || stripos($html, '</head>') === false || str_contains($html, 'cms-single-featured-image-fit')) {
            return $html;
        }
        $style = '<style id="cms-single-featured-image-fit">'
            . '.site-main--singular .entry--post > .entry-media{height:auto;max-height:none;aspect-ratio:auto}'
            . '.site-main--singular .entry--post > .entry-media > img{display:block;width:100%;height:auto;max-width:100%;max-height:none;object-fit:contain}'
            . '</style>';
        return (string)preg_replace('/<\/head>/i', $style . "\n</head>", $html, 1);
    }

    /**
     * Inject generic Core Back-to-Top component and styles before </body> if not already present.
     */
    protected function injectBackToTop(string $html): string
    {
        if (stripos($html, 'cms-back-to-top') === false && stripos($html, '</body>') !== false) {
            $btt = BackToTop::render();
            $html = (string)preg_replace('/<\/body>/i', $btt . "\n</body>", $html, 1);
        }
        return $html;
    }

    /**
     * Resolve template location in precedence order:
     * 1. Theme Override (themes/{activeTheme}/templates/{template}.php or themes/{activeTheme}/{template}.php)
     * 2. Custom Plugin Paths registered via Engine::addTemplatePath()
     * 3. Active Plugins (plugins/{pluginId}/templates/{template}.php)
     * 4. Core / System Default (resources/views/{template}.php)
     */
    public function resolveTemplate(string $template): ?string
    {
        $template = trim($template, '/');
        $themeDir = APP_ROOT . '/themes/' . $this->activeTheme;

        // 1. Theme direct template: themes/{theme}/{template}.php
        if (file_exists("{$themeDir}/{$template}.php")) {
            return "{$themeDir}/{$template}.php";
        }

        // Theme templates subfolder: themes/{theme}/templates/{template}.php
        if (file_exists("{$themeDir}/templates/{template}.php")) {
            return "{$themeDir}/templates/{template}.php";
        }

        // 2. Custom template paths registered by plugins
        foreach (static::$customTemplatePaths as $customDir) {
            if (file_exists("{$customDir}/{$template}.php")) {
                return "{$customDir}/{$template}.php";
            }
        }

        // 3. Active plugins templates
        try {
            $pluginMgr = new \FavoriteCMS\Plugins\PluginManager($this->app);
            foreach ($pluginMgr->getActivePlugins() as $pluginId) {
                $pluginTpl = APP_ROOT . "/plugins/{$pluginId}/templates/{$template}.php";
                if (file_exists($pluginTpl)) {
                    return $pluginTpl;
                }
                $pluginTplDirect = APP_ROOT . "/plugins/{$pluginId}/{$template}.php";
                if (file_exists($pluginTplDirect)) {
                    return $pluginTplDirect;
                }
            }
        } catch (\Throwable) {
            // Ignore plugin lookup failure in early bootstrap
        }

        // 4. Core / System views: resources/views/{template}.php
        $corePath = APP_ROOT . "/resources/views/{$template}.php";
        if (file_exists($corePath)) {
            return $corePath;
        }

        return null;
    }

    /**
     * Evaluate a template file in an isolated scope with helper functions available.
     */
    protected function evaluateTemplate(string $path, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $path;
            return (string)ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}

