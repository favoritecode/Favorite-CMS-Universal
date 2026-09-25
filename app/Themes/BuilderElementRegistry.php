<?php

declare(strict_types=1);

namespace FavoriteCMS\Themes;

use FavoriteCMS\Core\Hook;

/**
 * Generic Visual Builder Element Registry
 *
 * Provides a generic extensible registry for visual builder elements.
 * Themes and plugins can register custom elements via `BuilderElementRegistry::register()`
 * or by listening to the `builder_register_elements` action hook.
 *
 * Core provides built-in elements:
 * - heading
 * - text
 * - image
 * - video
 * - button
 * - divider
 * - spacer
 * - html
 *
 * Core contains ZERO theme-specific or plugin-specific business logic.
 */
class BuilderElementRegistry
{
    private static ?self $instance = null;

    /**
     * @var array<string, array{
     *     id: string,
     *     name: string,
     *     icon: string,
     *     category: string,
     *     description: string,
     *     defaultSettings: array,
     *     renderCallback: ?callable
     * }>
     */
    protected array $elements = [];

    protected bool $bootstrapped = false;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function __construct()
    {
        $this->bootstrapBuiltInElements();
    }

    /**
     * Bootstrap the generic built-in elements.
     */
    protected function bootstrapBuiltInElements(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        $this->register('heading', [
            'name'            => 'Heading',
            'icon'            => 'heading',
            'category'        => 'basic',
            'description'     => 'Structured headline with customizable level, typography, color, and alignment.',
            'defaultSettings' => [
                'text'       => 'Headline Title',
                'tag'        => 'h2', // h1, h2, h3, h4, h5, h6
                'alignment'  => 'left', // left, center, right
                'color'      => '',
                'size'       => '2rem',
                'weight'     => '700',
                'margin_top' => '0px',
                'margin_bottom' => '12px',
            ],
            'renderCallback'  => [$this, 'renderHeading'],
        ]);

        $this->register('text', [
            'name'            => 'Text Block',
            'icon'            => 'align-left',
            'category'        => 'basic',
            'description'     => 'Rich paragraph text with typography, color, spacing, and alignment controls.',
            'defaultSettings' => [
                'content'    => 'Add your text description here. Supports paragraphs and inline emphasis.',
                'alignment'  => 'left',
                'color'      => '',
                'size'       => '1rem',
                'line_height'=> '1.6',
                'margin_bottom' => '16px',
            ],
            'renderCallback'  => [$this, 'renderText'],
        ]);

        $this->register('image', [
            'name'            => 'Image',
            'icon'            => 'image',
            'category'        => 'media',
            'description'     => 'Responsive media image with alt text, aspect ratio, radius, and optional link.',
            'defaultSettings' => [
                'url'        => '',
                'alt'        => '',
                'link'       => '',
                'width'      => '100%',
                'height'     => 'auto',
                'object_fit' => 'cover', // cover, contain, fill
                'border_radius' => '8px',
                'alignment'  => 'center',
            ],
            'renderCallback'  => [$this, 'renderImage'],
        ]);

        $this->register('video', [
            'name'            => 'Video',
            'icon'            => 'video',
            'category'        => 'media',
            'description'     => 'Structured video embed (YouTube, Vimeo, or direct MP4) with playback controls.',
            'defaultSettings' => [
                'source'     => 'youtube', // youtube, vimeo, mp4
                'url'        => '',
                'video_id'   => '',
                'poster'     => '',
                'autoplay'   => false,
                'muted'      => false,
                'loop'       => false,
                'controls'   => true,
                'aspect_ratio' => '16:9',
            ],
            'renderCallback'  => [$this, 'renderVideo'],
        ]);

        $this->register('button', [
            'name'            => 'Button',
            'icon'            => 'mouse-pointer',
            'category'        => 'basic',
            'description'     => 'Interactive call-to-action button with hover states, icon, and styling.',
            'defaultSettings' => [
                'text'         => 'Click Here',
                'url'          => '#',
                'target'       => '_self', // _self, _blank
                'icon'         => '',
                'icon_position'=> 'left', // left, right
                'variant'      => 'primary', // primary, secondary, outline, text
                'bg_color'     => '',
                'text_color'   => '',
                'hover_bg'     => '',
                'hover_color'  => '',
                'border_radius'=> '6px',
                'padding'      => '10px 20px',
                'alignment'    => 'left',
            ],
            'renderCallback'  => [$this, 'renderButton'],
        ]);

        $this->register('divider', [
            'name'            => 'Divider',
            'icon'            => 'minus',
            'category'        => 'layout',
            'description'     => 'Visual separator line with custom style, width, thickness, and color.',
            'defaultSettings' => [
                'style'     => 'solid', // solid, dashed, dotted
                'width'     => '100%',
                'thickness' => '1px',
                'color'     => '#e2e8f0',
                'spacing'   => '24px',
                'alignment' => 'center',
            ],
            'renderCallback'  => [$this, 'renderDivider'],
        ]);

        $this->register('spacer', [
            'name'            => 'Spacer',
            'icon'            => 'maximize-2',
            'category'        => 'layout',
            'description'     => 'Responsive vertical spacing gap between layout elements.',
            'defaultSettings' => [
                'height_desktop' => '32px',
                'height_tablet'  => '24px',
                'height_mobile'  => '16px',
            ],
            'renderCallback'  => [$this, 'renderSpacer'],
        ]);

        $this->register('html', [
            'name'            => 'Custom HTML',
            'icon'            => 'code',
            'category'        => 'advanced',
            'description'     => 'Sanitized custom HTML block. Unsafe scripts and executable content are blocked.',
            'defaultSettings' => [
                'html' => '',
            ],
            'renderCallback'  => [$this, 'renderHtml'],
        ]);

        $this->bootstrapped = true;

        if (function_exists('do_action')) {
            do_action('builder_register_elements', $this);
        }
    }

    /**
     * Register a new element type.
     */
    public function register(string $id, array $definition): void
    {
        $id = strtolower(trim($id));
        if ($id === '' || !preg_match('/^[a-z0-9_-]+$/', $id)) {
            throw new \InvalidArgumentException("Invalid element ID: {$id}");
        }

        $this->elements[$id] = [
            'id'              => $id,
            'name'            => (string)($definition['name'] ?? ucfirst($id)),
            'icon'            => (string)($definition['icon'] ?? 'box'),
            'category'        => (string)($definition['category'] ?? 'general'),
            'description'     => (string)($definition['description'] ?? ''),
            'defaultSettings' => (array)($definition['defaultSettings'] ?? []),
            'renderCallback'  => isset($definition['renderCallback']) && is_callable($definition['renderCallback'])
                ? $definition['renderCallback']
                : null,
        ];
    }

    public function get(string $id): ?array
    {
        $id = strtolower(trim($id));
        return $this->elements[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->elements[strtolower(trim($id))]);
    }

    /**
     * @return array<string, array>
     */
    public function all(): array
    {
        return $this->elements;
    }

    /**
     * Render an individual element via its registered callback.
     */
    public function renderElement(array $elementData, array $context = []): string
    {
        $type = (string)($elementData['type'] ?? '');
        $settings = (array)($elementData['settings'] ?? []);
        $responsive = (array)($elementData['responsive'] ?? []);
        $element = $this->get($type);

        if (!$element || !$element['renderCallback']) {
            return '';
        }

        // Merge defaults with saved settings
        $mergedSettings = array_merge($element['defaultSettings'], $settings);

        return (string)call_user_func($element['renderCallback'], $mergedSettings, $responsive, $elementData, $context);
    }

    protected function getResponsiveClasses(array $responsive): string
    {
        $classes = [];
        if (!empty($responsive['hide_desktop'])) $classes[] = 'builder-hide-desktop';
        if (!empty($responsive['hide_tablet']))  $classes[] = 'builder-hide-tablet';
        if (!empty($responsive['hide_mobile']))  $classes[] = 'builder-hide-mobile';
        return $classes !== [] ? ' ' . implode(' ', $classes) : '';
    }

    // Built-in renderers

    public function renderHeading(array $settings, array $responsive = []): string
    {
        $tag = strtolower((string)($settings['tag'] ?? 'h2'));
        if (!in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            $tag = 'h2';
        }

        $text = htmlspecialchars((string)($settings['text'] ?? ''), ENT_QUOTES, 'UTF-8');
        $styles = [];
        if (!empty($settings['color'])) $styles[] = 'color:' . htmlspecialchars((string)$settings['color'], ENT_QUOTES, 'UTF-8');
        if (!empty($settings['alignment'])) $styles[] = 'text-align:' . htmlspecialchars((string)$settings['alignment'], ENT_QUOTES, 'UTF-8');
        if (!empty($settings['size'])) $styles[] = 'font-size:' . htmlspecialchars((string)$settings['size'], ENT_QUOTES, 'UTF-8');
        if (!empty($settings['weight'])) $styles[] = 'font-weight:' . htmlspecialchars((string)$settings['weight'], ENT_QUOTES, 'UTF-8');
        if (!empty($settings['margin_top'])) $styles[] = 'margin-top:' . htmlspecialchars((string)$settings['margin_top'], ENT_QUOTES, 'UTF-8');
        if (!empty($settings['margin_bottom'])) $styles[] = 'margin-bottom:' . htmlspecialchars((string)$settings['margin_bottom'], ENT_QUOTES, 'UTF-8');

        $styleAttr = !empty($styles) ? ' style="' . implode(';', $styles) . '"' : '';
        $rc = $this->getResponsiveClasses($responsive);
        return "<{$tag} class=\"builder-element builder-heading{$rc}\"{$styleAttr}>{$text}</{$tag}>";
    }

    public function renderText(array $settings, array $responsive = []): string
    {
        $content = nl2br(htmlspecialchars((string)($settings['content'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $styles = [];
        if (!empty($settings['color'])) $styles[] = 'color:' . htmlspecialchars((string)$settings['color'], ENT_QUOTES, 'UTF-8');
        if (!empty($settings['alignment'])) $styles[] = 'text-align:' . htmlspecialchars((string)$settings['alignment'], ENT_QUOTES, 'UTF-8');
        if (!empty($settings['size'])) $styles[] = 'font-size:' . htmlspecialchars((string)$settings['size'], ENT_QUOTES, 'UTF-8');
        if (!empty($settings['line_height'])) $styles[] = 'line-height:' . htmlspecialchars((string)$settings['line_height'], ENT_QUOTES, 'UTF-8');
        if (!empty($settings['margin_bottom'])) $styles[] = 'margin-bottom:' . htmlspecialchars((string)$settings['margin_bottom'], ENT_QUOTES, 'UTF-8');

        $styleAttr = !empty($styles) ? ' style="' . implode(';', $styles) . '"' : '';
        $rc = $this->getResponsiveClasses($responsive);
        return "<div class=\"builder-element builder-text{$rc}\"{$styleAttr}>{$content}</div>";
    }

    public function renderImage(array $settings, array $responsive = []): string
    {
        $url = filter_var((string)($settings['url'] ?? ''), FILTER_VALIDATE_URL) ? (string)$settings['url'] : '';
        if ($url === '' && !empty($settings['url']) && str_starts_with((string)$settings['url'], '/')) {
            $url = htmlspecialchars((string)$settings['url'], ENT_QUOTES, 'UTF-8');
        }
        if ($url === '') {
            return '';
        }

        $alt = htmlspecialchars((string)($settings['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
        $styles = [];
        if (!empty($settings['width'])) $styles[] = 'width:' . htmlspecialchars((string)$settings['width'], ENT_QUOTES, 'UTF-8');
        if (!empty($settings['height'])) $styles[] = 'height:' . htmlspecialchars((string)$settings['height'], ENT_QUOTES, 'UTF-8');
        if (!empty($settings['object_fit'])) $styles[] = 'object-fit:' . htmlspecialchars((string)$settings['object_fit'], ENT_QUOTES, 'UTF-8');
        if (!empty($settings['border_radius'])) $styles[] = 'border-radius:' . htmlspecialchars((string)$settings['border_radius'], ENT_QUOTES, 'UTF-8');

        $styleAttr = !empty($styles) ? ' style="' . implode(';', $styles) . '"' : '';
        $rc = $this->getResponsiveClasses($responsive);
        $img = "<img src=\"{$url}\" alt=\"{$alt}\" class=\"builder-element-img\"{$styleAttr} loading=\"lazy\">";

        $link = filter_var((string)($settings['link'] ?? ''), FILTER_VALIDATE_URL) ? (string)$settings['link'] : '';
        if ($link !== '') {
            return "<a href=\"{$link}\" class=\"builder-element builder-image{$rc}\">{$img}</a>";
        }
        return "<div class=\"builder-element builder-image{$rc}\">{$img}</div>";
    }

    public function renderVideo(array $settings, array $responsive = []): string
    {
        $source = (string)($settings['source'] ?? 'youtube');
        $url = (string)($settings['url'] ?? '');
        $videoId = (string)($settings['video_id'] ?? '');
        $rc = $this->getResponsiveClasses($responsive);

        if ($source === 'youtube') {
            if ($videoId === '' && $url !== '') {
                preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i', $url, $m);
                $videoId = $m[1] ?? '';
            }
            if ($videoId === '') return '';
            $embedUrl = "https://www.youtube-nocookie.com/embed/" . urlencode($videoId);
            return "<div class=\"builder-element builder-video builder-video--youtube{$rc}\"><iframe src=\"{$embedUrl}\" frameborder=\"0\" allowfullscreen loading=\"lazy\" style=\"width:100%;aspect-ratio:16/9;\"></iframe></div>";
        }

        if ($source === 'vimeo') {
            if ($videoId === '' && $url !== '') {
                preg_match('/(?:vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/[^\/]*\/videos\/|album\/(?:\d+\/)?video\/|video\/|)(\d+))/i', $url, $m);
                $videoId = $m[1] ?? '';
            }
            if ($videoId === '') return '';
            $embedUrl = "https://player.vimeo.com/video/" . urlencode($videoId);
            return "<div class=\"builder-element builder-video builder-video--vimeo{$rc}\"><iframe src=\"{$embedUrl}\" frameborder=\"0\" allowfullscreen loading=\"lazy\" style=\"width:100%;aspect-ratio:16/9;\"></iframe></div>";
        }

        if ($source === 'mp4' && $url !== '') {
            $cleanUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $autoplay = !empty($settings['autoplay']) ? ' autoplay' : '';
            $muted = !empty($settings['muted']) ? ' muted' : '';
            $loop = !empty($settings['loop']) ? ' loop' : '';
            $controls = !empty($settings['controls']) ? ' controls' : '';
            return "<div class=\"builder-element builder-video builder-video--mp4{$rc}\"><video src=\"{$cleanUrl}\"{$controls}{$autoplay}{$muted}{$loop} playsinline style=\"width:100%;max-height:600px;\"></video></div>";
        }

        return '';
    }

    public function renderButton(array $settings, array $responsive = []): string
    {
        $text = htmlspecialchars((string)($settings['text'] ?? 'Button'), ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars((string)($settings['url'] ?? '#'), ENT_QUOTES, 'UTF-8');
        $target = ($settings['target'] ?? '_self') === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '';

        $styles = [];
        if (!empty($settings['bg_color'])) $styles[] = 'background-color:' . htmlspecialchars((string)$settings['bg_color'], ENT_QUOTES, 'UTF-8');
        if (!empty($settings['text_color'])) $styles[] = 'color:' . htmlspecialchars((string)$settings['text_color'], ENT_QUOTES, 'UTF-8');
        if (!empty($settings['border_radius'])) $styles[] = 'border-radius:' . htmlspecialchars((string)$settings['border_radius'], ENT_QUOTES, 'UTF-8');
        if (!empty($settings['padding'])) $styles[] = 'padding:' . htmlspecialchars((string)$settings['padding'], ENT_QUOTES, 'UTF-8');

        $styleAttr = !empty($styles) ? ' style="' . implode(';', $styles) . '"' : '';
        $rc = $this->getResponsiveClasses($responsive);
        return "<a href=\"{$url}\" class=\"builder-element builder-btn{$rc}\"{$target}{$styleAttr}>{$text}</a>";
    }

    public function renderDivider(array $settings, array $responsive = []): string
    {
        $style = in_array($settings['style'] ?? 'solid', ['solid', 'dashed', 'dotted'], true) ? $settings['style'] : 'solid';
        $color = htmlspecialchars((string)($settings['color'] ?? '#e2e8f0'), ENT_QUOTES, 'UTF-8');
        $thickness = htmlspecialchars((string)($settings['thickness'] ?? '1px'), ENT_QUOTES, 'UTF-8');
        $width = htmlspecialchars((string)($settings['width'] ?? '100%'), ENT_QUOTES, 'UTF-8');
        $spacing = htmlspecialchars((string)($settings['spacing'] ?? '24px'), ENT_QUOTES, 'UTF-8');

        $hrStyle = "border:none; border-top:{$thickness} {$style} {$color}; width:{$width}; margin:{$spacing} auto;";
        $rc = $this->getResponsiveClasses($responsive);
        return "<div class=\"builder-element builder-divider{$rc}\"><hr style=\"{$hrStyle}\"></div>";
    }

    public function renderSpacer(array $settings, array $responsive = []): string
    {
        $hDesktop = htmlspecialchars((string)($settings['height_desktop'] ?? '32px'), ENT_QUOTES, 'UTF-8');
        $rc = $this->getResponsiveClasses($responsive);
        return "<div class=\"builder-element builder-spacer{$rc}\" style=\"height:{$hDesktop};\"></div>";
    }

    public function renderHtml(array $settings, array $responsive = []): string
    {
        $rawHtml = (string)($settings['html'] ?? '');
        $sanitized = self::sanitizeHtml($rawHtml);
        $rc = $this->getResponsiveClasses($responsive);
        return "<div class=\"builder-element builder-custom-html{$rc}\">{$sanitized}</div>";
    }

    /**
     * Strict HTML sanitization blocking script, event handlers, javascript:, object, embed, etc.
     */
    public static function sanitizeHtml(string $html): string
    {
        // 1. Remove script tags and contents
        $html = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $html);
        // 2. Remove style tags and contents
        $html = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $html);
        // 3. Remove object, embed, applet, meta, link tags
        $html = preg_replace('#<(object|embed|applet|meta|link)(.*?)>#is', '', $html);
        // 4. Remove all on* event handler attributes
        $html = preg_replace('#\s+on[a-zA-Z]+\s*=\s*(["\'])(.*?)\1#is', '', $html);
        $html = preg_replace('#\s+on[a-zA-Z]+\s*=\s*[^ >]+#is', '', $html);
        // 5. Remove javascript:, vbscript:, data: protocols
        $html = preg_replace('#(href|src|action)\s*=\s*(["\'])\s*(javascript|vbscript|data):.*?\2#is', '', $html);
        return $html;
    }
}

