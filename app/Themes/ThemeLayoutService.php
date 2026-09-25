<?php

declare(strict_types=1);

namespace FavoriteCMS\Themes;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Hook;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Widgets\WidgetInstanceManager;
use FavoriteCMS\Widgets\WidgetRegistry;

class ThemeLayoutService
{
    protected Application $app;
    protected WidgetInstanceManager $instanceManager;
    protected string $themesPath;

    public function __construct(Application $app, ?WidgetInstanceManager $instanceManager = null)
    {
        $this->app = $app;
        $this->instanceManager = $instanceManager ?? new WidgetInstanceManager();
        $this->themesPath = APP_ROOT . '/themes';
    }

    public function getActiveThemeId(): string
    {
        return $this->instanceManager->getActiveThemeId();
    }

    /**
     * Read and parse theme manifest (theme.json).
     */
    public function getThemeManifest(?string $themeId = null): array
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $manifestPath = $this->themesPath . '/' . $tid . '/theme.json';

        if (!file_exists($manifestPath)) {
            return [
                'id'          => $tid,
                'name'        => ucfirst($tid),
                'regions'     => $this->getDefaultFallbackRegions(),
                'sections'    => $this->getDefaultFallbackSections(),
                'settings'    => [],
            ];
        }

        $json = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($json)) {
            $json = [];
        }

        $json['id'] = $tid;
        if (empty($json['regions']) || !is_array($json['regions'])) {
            $json['regions'] = $this->getDefaultFallbackRegions();
        }
        if (empty($json['sections']) || !is_array($json['sections'])) {
            $json['sections'] = $this->getDefaultFallbackSections();
        }

        return $json;
    }

    /**
     * Default regions if theme manifest has none defined.
     */
    protected function getDefaultFallbackRegions(): array
    {
        return [
            [
                'id'          => 'sidebar-primary',
                'name'        => 'Primary Sidebar',
                'description' => 'Main sidebar displayed beside post and page content.',
            ],
            [
                'id'          => 'footer-1',
                'name'        => 'Footer Column 1',
                'description' => 'First column in site footer area.',
            ],
            [
                'id'          => 'footer-2',
                'name'        => 'Footer Column 2',
                'description' => 'Second column in site footer area.',
            ],
            [
                'id'          => 'footer-3',
                'name'        => 'Footer Column 3',
                'description' => 'Third column in site footer area.',
            ],
            [
                'id'          => 'header-right',
                'name'        => 'Header Right',
                'description' => 'Optional widget area located in site header navigation bar.',
            ],
        ];
    }

    /**
     * Default homepage sections if theme manifest has none defined.
     */
    protected function getDefaultFallbackSections(): array
    {
        return [
            [
                'id'          => 'hero',
                'name'        => 'Welcome Hero Banner',
                'description' => 'Top introductory welcome headline and tagline.',
                'enabled'     => true,
            ],
            [
                'id'          => 'featured-posts',
                'name'        => 'Featured Articles Showcase',
                'description' => 'Highlights sticky or selected featured articles.',
                'enabled'     => true,
            ],
            [
                'id'          => 'latest-posts',
                'name'        => 'Latest Posts Feed',
                'description' => 'Standard blog article stream with pagination.',
                'enabled'     => true,
            ],
        ];
    }

    /**
     * Get theme modification setting value.
     */
    public function getThemeMod(string $name, mixed $default = null, ?string $themeId = null): mixed
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_mods_{$tid}";
        return Setting::get($group, $name, $default);
    }

    /**
     * Set theme modification setting value.
     */
    public function setThemeMod(string $name, mixed $value, ?string $themeId = null): void
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_mods_{$tid}";
        $type = is_int($value) ? 'int' : (is_bool($value) ? 'bool' : (is_array($value) ? 'json' : 'string'));
        Setting::set($group, $name, $value, $type);
    }

    /**
     * Get all theme modification settings as an associative array.
     */
    public function getAllThemeMods(?string $themeId = null): array
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_mods_{$tid}";
        return Setting::getGroup($group);
    }

    /**
     * Get ordered homepage layout sections.
     */
    public function getSections(?string $themeId = null): array
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $manifest = $this->getThemeManifest($tid);
        $manifestSections = $manifest['sections'] ?? [];

        $group = "theme_sections_{$tid}";
        $savedOrder = Setting::get($group, '_section_order', []);
        if (is_string($savedOrder)) {
            $savedOrder = json_decode($savedOrder, true);
        }

        $sectionsById = [];
        foreach ($manifestSections as $s) {
            $sid = $s['id'];
            $savedConfig = Setting::get($group, $sid, null);
            if (is_string($savedConfig)) {
                $savedConfig = json_decode($savedConfig, true);
            }

            $sectionsById[$sid] = array_merge($s, is_array($savedConfig) ? $savedConfig : []);
            if (!isset($sectionsById[$sid]['enabled'])) {
                $sectionsById[$sid]['enabled'] = $s['enabled'] ?? true;
            }
        }

        // Order according to saved order
        $ordered = [];
        if (is_array($savedOrder) && !empty($savedOrder)) {
            foreach ($savedOrder as $sid) {
                if (isset($sectionsById[$sid])) {
                    $ordered[] = $sectionsById[$sid];
                    unset($sectionsById[$sid]);
                }
            }
        }

        foreach ($sectionsById as $s) {
            $ordered[] = $s;
        }

        return $ordered;
    }

    /**
     * Update section configuration (e.g. toggle enabled state or custom settings).
     */
    public function updateSection(string $sectionId, array $data, ?string $themeId = null): bool
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_sections_{$tid}";

        $current = Setting::get($group, $sectionId, []);
        if (is_string($current)) {
            $current = json_decode($current, true);
        }
        $updated = array_merge(is_array($current) ? $current : [], $data);

        Setting::set($group, $sectionId, $updated, 'json');
        return true;
    }

    /**
     * Reorder homepage sections.
     */
    public function reorderSections(array $orderedSectionIds, ?string $themeId = null): bool
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_sections_{$tid}";
        Setting::set($group, '_section_order', array_values($orderedSectionIds), 'json');
        return true;
    }

    /**
     * Ensure sensible default widget layout is seeded for a theme on first activation.
     */
    public function ensureDefaultLayout(?string $themeId = null): void
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $isSeeded = Setting::get("widget_seeded_{$tid}", 'is_seeded', false);

        if ($isSeeded) {
            return;
        }

        $manifest = $this->getThemeManifest($tid);
        $defaults = $manifest['default_widgets'] ?? null;

        // Standard sensible default widget placement if theme didn't specify
        if (empty($defaults) || !is_array($defaults)) {
            $defaults = [
                'sidebar-primary' => [
                    ['widget' => 'search', 'settings' => ['title' => 'Search Articles']],
                    ['widget' => 'recent_posts', 'settings' => ['title' => 'Recent Articles', 'number' => 5, 'show_date' => true]],
                    ['widget' => 'categories', 'settings' => ['title' => 'Categories', 'show_count' => true]],
                    ['widget' => 'tags', 'settings' => ['title' => 'Popular Tags', 'limit' => 15]],
                ],
                'footer-1' => [
                    ['widget' => 'nav_menu', 'settings' => ['title' => 'Navigation']],
                ],
                'footer-2' => [
                    ['widget' => 'recent_posts', 'settings' => ['title' => 'Latest Stories', 'number' => 3]],
                ],
                'footer-3' => [
                    ['widget' => 'custom_html', 'settings' => ['title' => 'About Site', 'content' => '<p>A fast, modern website powered by Favorite CMS.</p>']],
                ],
            ];
        }

        foreach ($defaults as $regionId => $widgetConfigs) {
            if (!is_array($widgetConfigs)) continue;
            foreach ($widgetConfigs as $cfg) {
                $wId = $cfg['widget'] ?? '';
                $settings = $cfg['settings'] ?? [];
                if ($wId !== '' && $this->instanceManager->getActiveThemeId() !== '') {
                    try {
                        $this->instanceManager->createInstance($wId, $regionId, $settings, $tid);
                    } catch (\Throwable) {
                        // Ignore if specific widget type is missing during seeding
                    }
                }
            }
        }

        Setting::set("widget_seeded_{$tid}", 'is_seeded', true, 'bool');
    }

    // Visual Builder Security & Validation Limits
    public const MAX_TREE_DEPTH = 10;
    public const MAX_TOTAL_ELEMENTS = 200;
    public const MAX_CONTAINER_CHILDREN = 50;
    public const MAX_STRING_LENGTH = 65535;
    public const MAX_PAYLOAD_BYTES = 2097152; // 2MB
    public const MAX_TEMPLATE_BYTES = 524288; // 512KB
    public const MAX_TEMPLATES_PER_THEME = 50;

    /**
     * Get Visual Builder tree for the active or specified theme.
     * Returns an array of sections/containers/elements.
     */
    public function getBuilderTree(?string $themeId = null): array
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_builder_{$tid}";
        $tree = Setting::get($group, 'tree', null);

        if (is_string($tree)) {
            $tree = json_decode($tree, true);
        }

        if (is_array($tree) && !empty($tree)) {
            return $tree;
        }

        // Return initial tree derived from theme sections
        return $this->getDefaultBuilderTree($tid);
    }

    /**
     * Generate default builder tree from theme sections.
     */
    public function getDefaultBuilderTree(?string $themeId = null): array
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $sections = $this->getSections($tid);
        $tree = [];

        foreach ($sections as $s) {
            $sid = $s['id'];
            $tree[] = [
                'id'       => "sec_{$sid}",
                'type'     => 'section',
                'label'    => $s['name'] ?? ucfirst($sid),
                'enabled'  => !empty($s['enabled']),
                'settings' => [
                    'section_id' => $sid,
                    'padding'    => '60px 0',
                    'background' => '',
                ],
                'responsive' => [
                    'hide_desktop' => false,
                    'hide_tablet'  => false,
                    'hide_mobile'  => false,
                ],
                'children' => [],
            ];
        }

        return $tree;
    }

    /**
     * Save and persist Visual Builder tree.
     */
    public function saveBuilderTree(array $tree, ?string $themeId = null): bool
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_builder_{$tid}";

        // Check serialized payload size
        $json = json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new \InvalidArgumentException("Builder tree payload exceeds maximum size limit of " . (self::MAX_PAYLOAD_BYTES / 1048576) . "MB");
        }

        // Validate tree structure, types, depth, and limits
        $elementCount = 0;
        $validatedTree = $this->validateBuilderTree($tree, 0, $elementCount);

        Setting::set($group, 'tree', $validatedTree, 'json');
        return true;
    }

    /**
     * Validate Visual Builder tree schema recursively.
     * Prevents circular references, limits nesting depth, checks element count, and sanitizes values.
     */
    public function validateBuilderTree(array $nodes, int $depth = 0, int &$elementCount = 0): array
    {
        if ($depth > self::MAX_TREE_DEPTH) {
            throw new \InvalidArgumentException("Builder tree exceeds maximum allowed depth of " . self::MAX_TREE_DEPTH . " levels");
        }

        if (count($nodes) > self::MAX_CONTAINER_CHILDREN) {
            throw new \InvalidArgumentException("Container exceeds maximum allowed children limit of " . self::MAX_CONTAINER_CHILDREN);
        }

        $registry = BuilderElementRegistry::getInstance();
        $validated = [];
        $seenIds = [];

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $elementCount++;
            if ($elementCount > self::MAX_TOTAL_ELEMENTS) {
                throw new \InvalidArgumentException("Builder tree exceeds total element limit of " . self::MAX_TOTAL_ELEMENTS);
            }

            $rawId = (string)($node['id'] ?? 'el_' . bin2hex(random_bytes(4)));
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawId);
            if (isset($seenIds[$id])) {
                $id .= '_' . bin2hex(random_bytes(2));
            }
            $seenIds[$id] = true;

            $type = strtolower(trim((string)($node['type'] ?? 'element')));
            $structuralTypes = ['section', 'container', 'column'];
            if (!in_array($type, $structuralTypes, true) && $registry->get($type) === null) {
                throw new \InvalidArgumentException("Unrecognized or unregistered builder element type: {$type}");
            }
            $label = htmlspecialchars(mb_substr((string)($node['label'] ?? ucfirst($type)), 0, 100), ENT_QUOTES, 'UTF-8');

            // Sanitize settings
            $rawSettings = (array)($node['settings'] ?? []);
            $cleanSettings = [];
            foreach ($rawSettings as $k => $v) {
                $cleanKey = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$k);
                if (is_string($v)) {
                    if ($cleanKey === 'html') {
                        $cleanSettings[$cleanKey] = BuilderElementRegistry::sanitizeHtml($v);
                    } else {
                        $cleanSettings[$cleanKey] = mb_substr($v, 0, self::MAX_STRING_LENGTH);
                    }
                } elseif (is_numeric($v) || is_bool($v)) {
                    $cleanSettings[$cleanKey] = $v;
                } elseif (is_array($v)) {
                    $cleanSettings[$cleanKey] = $v;
                }
            }

            // Sanitize responsive visibility
            $resp = (array)($node['responsive'] ?? []);
            $cleanResponsive = [
                'hide_desktop' => !empty($resp['hide_desktop']),
                'hide_tablet'  => !empty($resp['hide_tablet']),
                'hide_mobile'  => !empty($resp['hide_mobile']),
            ];

            // Validate children recursively if container or section
            $cleanChildren = [];
            if (!empty($node['children']) && is_array($node['children'])) {
                $cleanChildren = $this->validateBuilderTree($node['children'], $depth + 1, $elementCount);
            }

            $validated[] = [
                'id'         => $id,
                'type'       => $type,
                'label'      => $label,
                'enabled'    => !isset($node['enabled']) || !empty($node['enabled']),
                'settings'   => $cleanSettings,
                'responsive' => $cleanResponsive,
                'children'   => $cleanChildren,
            ];
        }

        return $validated;
    }

    /**
     * Model A: Full Visual Builder Frontend Renderer
     * Traverses the validated tree and produces frontend HTML using registered element callbacks.
     */
    public function renderBuilderTree(array $tree, ?string $themeId = null): string
    {
        $registry = BuilderElementRegistry::getInstance();
        $html = '';

        foreach ($tree as $node) {
            if (isset($node['enabled']) && !$node['enabled']) {
                continue;
            }

            $type = $node['type'] ?? '';
            $id = htmlspecialchars((string)($node['id'] ?? ''), ENT_QUOTES, 'UTF-8');
            $resp = $node['responsive'] ?? [];
            $classes = ['builder-node', "builder-node--{$type}"];

            if (!empty($resp['hide_desktop'])) $classes[] = 'builder-hide-desktop';
            if (!empty($resp['hide_tablet']))  $classes[] = 'builder-hide-tablet';
            if (!empty($resp['hide_mobile']))  $classes[] = 'builder-hide-mobile';

            $classAttr = ' class="' . implode(' ', $classes) . '"';

            if ($type === 'section') {
                $bg = !empty($node['settings']['background']) ? 'background:' . htmlspecialchars((string)$node['settings']['background'], ENT_QUOTES, 'UTF-8') . ';' : '';
                $pad = !empty($node['settings']['padding']) ? 'padding:' . htmlspecialchars((string)$node['settings']['padding'], ENT_QUOTES, 'UTF-8') . ';' : '';
                $styleAttr = ($bg || $pad) ? ' style="' . $bg . $pad . '"' : '';

                $inner = '';
                if (!empty($node['children'])) {
                    $inner = $this->renderBuilderTree($node['children'], $themeId);
                }
                $html .= "<section id=\"{$id}\"{$classAttr}{$styleAttr}>\n{$inner}\n</section>\n";
            } elseif ($type === 'container' || $type === 'column') {
                $maxWidth = !empty($node['settings']['max_width']) ? 'max-width:' . htmlspecialchars((string)$node['settings']['max_width'], ENT_QUOTES, 'UTF-8') . ';' : '';
                $styleAttr = $maxWidth ? ' style="' . $maxWidth . '"' : '';

                $inner = '';
                if (!empty($node['children'])) {
                    $inner = $this->renderBuilderTree($node['children'], $themeId);
                }
                $html .= "<div id=\"{$id}\"{$classAttr}{$styleAttr}>\n{$inner}\n</div>\n";
            } else {
                $html .= $registry->renderElement($node, ['themeId' => $themeId ?? $this->getActiveThemeId()]);
            }
        }

        return $html;
    }

    /**
     * Get reusable templates for the specified or active theme.
     */
    public function getTemplates(?string $themeId = null): array
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_templates_{$tid}";
        $list = Setting::get($group, 'items', []);

        if (is_string($list)) {
            $list = json_decode($list, true);
        }

        return is_array($list) ? $list : [];
    }

    /**
     * Save a reusable template.
     */
    public function saveTemplate(string $name, array $data, ?string $themeId = null): array
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_templates_{$tid}";
        $templates = $this->getTemplates($tid);

        if (count($templates) >= self::MAX_TEMPLATES_PER_THEME) {
            throw new \OverflowException("Maximum templates limit (" . self::MAX_TEMPLATES_PER_THEME . ") reached for theme {$tid}");
        }

        $json = json_encode($data);
        if ($json === false || strlen($json) > self::MAX_TEMPLATE_BYTES) {
            throw new \InvalidArgumentException("Template data exceeds maximum size limit of " . (self::MAX_TEMPLATE_BYTES / 1024) . "KB");
        }

        $cleanName = htmlspecialchars(trim(mb_substr($name, 0, 100)), ENT_QUOTES, 'UTF-8');
        if ($cleanName === '') {
            $cleanName = 'Unnamed Template';
        }

        $templateId = 'tpl_' . bin2hex(random_bytes(6));
        $templateItem = [
            'id'         => $templateId,
            'name'       => $cleanName,
            'type'       => (string)($data['type'] ?? 'section'),
            'data'       => $data,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $templates[] = $templateItem;
        Setting::set($group, 'items', $templates, 'json');

        return $templateItem;
    }

    /**
     * Delete a reusable template by ID.
     */
    public function deleteTemplate(string $templateId, ?string $themeId = null): bool
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_templates_{$tid}";
        $templates = $this->getTemplates($tid);

        $filtered = array_values(array_filter($templates, static fn($t) => ($t['id'] ?? '') !== $templateId));
        if (count($filtered) === count($templates)) {
            return false;
        }

        Setting::set($group, 'items', $filtered, 'json');
        return true;
    }

    /**
     * Get Global Design System tokens.
     */
    public function getGlobalDesignTokens(?string $themeId = null): array
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_tokens_{$tid}";
        $tokens = Setting::get($group, 'tokens', null);

        if (is_string($tokens)) {
            $tokens = json_decode($tokens, true);
        }

        if (is_array($tokens) && !empty($tokens)) {
            return $tokens;
        }

        return [
            'colors' => [
                'primary'    => '#2563eb',
                'secondary'  => '#475569',
                'heading'    => '#0f172a',
                'text'       => '#334155',
                'background' => '#ffffff',
                'surface'    => '#f8fafc',
                'border'     => '#e2e8f0',
                'success'    => '#16a34a',
                'danger'     => '#dc2626',
            ],
            'typography' => [
                'body'       => 'ui-sans-serif, system-ui, -apple-system, sans-serif',
                'h1'         => '2.5rem',
                'h2'         => '2rem',
                'h3'         => '1.5rem',
                'button'     => '0.95rem',
                'navigation' => '0.95rem',
                'caption'    => '0.85rem',
            ],
        ];
    }

    /**
     * Save Global Design System tokens.
     */
    public function saveGlobalDesignTokens(array $tokens, ?string $themeId = null): bool
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $group = "theme_tokens_{$tid}";

        $cleanColors = [];
        if (!empty($tokens['colors']) && is_array($tokens['colors'])) {
            foreach ($tokens['colors'] as $k => $v) {
                $cleanKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$k);
                $cleanColors[$cleanKey] = htmlspecialchars(mb_substr((string)$v, 0, 50), ENT_QUOTES, 'UTF-8');
            }
        }

        $cleanTypo = [];
        if (!empty($tokens['typography']) && is_array($tokens['typography'])) {
            foreach ($tokens['typography'] as $k => $v) {
                $cleanKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$k);
                $cleanTypo[$cleanKey] = htmlspecialchars(mb_substr((string)$v, 0, 100), ENT_QUOTES, 'UTF-8');
            }
        }

        Setting::set($group, 'tokens', [
            'colors'     => $cleanColors,
            'typography' => $cleanTypo,
        ], 'json');

        return true;
    }

    /**
     * Reset theme layout to defaults.
     */
    public function resetThemeLayout(?string $themeId = null): void
    {
        $tid = $themeId ?: $this->getActiveThemeId();
        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);

        // Delete all instances and regions for this theme
        $db->delete('settings', ['group_name' => "widget_{$tid}"]);
        $db->delete('settings', ['group_name' => "widget_regions_{$tid}"]);
        $db->delete('settings', ['group_name' => "theme_sections_{$tid}"]);
        $db->delete('settings', ['group_name' => "theme_mods_{$tid}"]);
        $db->delete('settings', ['group_name' => "widget_seeded_{$tid}"]);
        $db->delete('settings', ['group_name' => "theme_builder_{$tid}"]);
        $db->delete('settings', ['group_name' => "theme_templates_{$tid}"]);
        $db->delete('settings', ['group_name' => "theme_tokens_{$tid}"]);

        Setting::clearCache();

        // Re-seed defaults
        $this->ensureDefaultLayout($tid);
    }
}

