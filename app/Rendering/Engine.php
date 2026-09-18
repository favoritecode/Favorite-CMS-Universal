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
            $theme = Setting::get('theme', 'active_theme', 'default');
            $this->activeTheme = is_string($theme) && $theme !== '' ? $theme : 'default';
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
        $templatePath = $this->resolveTemplate($template);

        // Allow plugins to filter template file path
        $templatePath = \FavoriteCMS\Core\Hook::applyFilters('template_include', $templatePath, $template, $data);

        if (!$templatePath || !file_exists($templatePath)) {
            // Fallback to 404 or basic error
            $fallback = $this->resolveTemplate('404');
            if ($fallback && file_exists($fallback)) {
                return $this->evaluateTemplate($fallback, $data);
            }
            throw new \RuntimeException("Template not found: {$template}");
        }

        return $this->evaluateTemplate($templatePath, $data);
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

