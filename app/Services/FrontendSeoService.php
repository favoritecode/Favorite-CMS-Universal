<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Page;

class FrontendSeoService
{
    /**
     * Render all SEO and tracking tags for the HTML <head>.
     */
    public static function renderHeadTags(array $context = []): string
    {
        $siteTitle = (string)Setting::get('general', 'site_name', 'Favorite CMS');
        $siteDesc  = (string)Setting::get('general', 'site_description', '');
        $sep       = (string)Setting::get('seo', 'title_separator', '—');

        $post = $context['post'] ?? null;
        $page = $context['page'] ?? null;
        $isHome = !empty($context['isHome']);

        $seoMeta = null;
        if ($post instanceof Post) {
            $seoMeta = $post->getSeoMeta();
        } elseif ($page instanceof Page) {
            $seoMeta = $page->getSeoMeta();
        }

        // 1. Resolve Canonical URL
        $canonicalUrl = self::resolveCanonicalUrl($context, $seoMeta, $post, $page);

        // 2. Resolve Robots Meta
        $robots = self::resolveRobots($context, $seoMeta);

        // 3. Resolve Title & Description
        $resolvedTitle = self::resolveTitle($context, $seoMeta, $siteTitle, $sep, $post, $page);
        $resolvedDesc  = self::resolveDescription($context, $seoMeta, $siteDesc, $post, $page);

        // 4. Resolve Open Graph / Social Image
        $resolvedImage = self::resolveImage($context, $seoMeta, $post, $page);

        // 5. Resolve Open Graph Type
        $ogType = $isHome ? 'website' : ($post instanceof Post ? 'article' : ($context['ogType'] ?? 'website'));

        $html = [];

        // Canonical Tag
        if ($canonicalUrl !== '') {
            $html[] = '<link rel="canonical" href="' . htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') . '">';
        }

        // Robots Meta Tag
        if ($robots !== '') {
            $html[] = '<meta name="robots" content="' . htmlspecialchars($robots, ENT_QUOTES, 'UTF-8') . '">';
        }

        // Open Graph Tags
        $html[] = '<meta property="og:site_name" content="' . htmlspecialchars($siteTitle, ENT_QUOTES, 'UTF-8') . '">';
        $html[] = '<meta property="og:type" content="' . htmlspecialchars($ogType, ENT_QUOTES, 'UTF-8') . '">';
        $html[] = '<meta property="og:title" content="' . htmlspecialchars($resolvedTitle, ENT_QUOTES, 'UTF-8') . '">';
        if ($resolvedDesc !== '') {
            $html[] = '<meta property="og:description" content="' . htmlspecialchars($resolvedDesc, ENT_QUOTES, 'UTF-8') . '">';
        }
        if ($canonicalUrl !== '') {
            $html[] = '<meta property="og:url" content="' . htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') . '">';
        }
        if ($resolvedImage !== '') {
            $html[] = '<meta property="og:image" content="' . htmlspecialchars($resolvedImage, ENT_QUOTES, 'UTF-8') . '">';
        }

        // Twitter Card Tags
        $html[] = '<meta name="twitter:card" content="summary_large_image">';
        $html[] = '<meta name="twitter:title" content="' . htmlspecialchars($resolvedTitle, ENT_QUOTES, 'UTF-8') . '">';
        if ($resolvedDesc !== '') {
            $html[] = '<meta name="twitter:description" content="' . htmlspecialchars($resolvedDesc, ENT_QUOTES, 'UTF-8') . '">';
        }
        if ($resolvedImage !== '') {
            $html[] = '<meta name="twitter:image" content="' . htmlspecialchars($resolvedImage, ENT_QUOTES, 'UTF-8') . '">';
        }

        // Search Engine Site Verification Meta Tags
        $gsc = trim((string)Setting::get('seo', 'google_site_verification', ''));
        if ($gsc !== '') {
            $html[] = '<meta name="google-site-verification" content="' . htmlspecialchars($gsc, ENT_QUOTES, 'UTF-8') . '">';
        }

        $bing = trim((string)Setting::get('seo', 'bing_site_verification', ''));
        if ($bing !== '') {
            $html[] = '<meta name="msvalidate.01" content="' . htmlspecialchars($bing, ENT_QUOTES, 'UTF-8') . '">';
        }

        // JSON-LD Structured Data Schema
        $jsonLd = self::generateJsonLd($context, $resolvedTitle, $resolvedDesc, $canonicalUrl, $resolvedImage, $siteTitle, $post, $page, $isHome);
        if ($jsonLd !== '') {
            $html[] = '<script type="application/ld+json">' . "\n" . $jsonLd . "\n" . '</script>';
        }

        // Analytics & Tracking Tags (Only on public frontend, never if request is under /admin)
        if (!self::isAdminRequest()) {
            $gtmEnabled = (int)Setting::get('seo', 'gtm_enabled', 0);
            $rawGtmId = trim((string)Setting::get('seo', 'gtm_container_id', ''));
            $hasValidGtm = ($gtmEnabled === 1 && preg_match('/^GTM-[A-Z0-9]+$/i', $rawGtmId));

            if ($hasValidGtm) {
                $gtmId = htmlspecialchars(strtoupper($rawGtmId), ENT_QUOTES, 'UTF-8');
                $html[] = "<!-- Google Tag Manager -->\n"
                    . "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':\n"
                    . "new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],\n"
                    . "j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=\n"
                    . "'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);\n"
                    . "})(window,document,'script','dataLayer','{$gtmId}');</script>\n"
                    . "<!-- End Google Tag Manager -->";
            } else {
                // If GTM is not enabled, inject standalone GA4 if enabled
                $ga4Enabled = (int)Setting::get('seo', 'ga4_enabled', 0);
                $rawGa4Id = trim((string)Setting::get('seo', 'ga4_measurement_id', ''));
                if ($ga4Enabled === 1 && preg_match('/^G-[A-Z0-9]+$/i', $rawGa4Id)) {
                    $ga4Id = htmlspecialchars(strtoupper($rawGa4Id), ENT_QUOTES, 'UTF-8');
                    $html[] = "<!-- Google tag (gtag.js) -->\n"
                        . "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$ga4Id}\"></script>\n"
                        . "<script>\n"
                        . "  window.dataLayer = window.dataLayer || [];\n"
                        . "  function gtag(){dataLayer.push(arguments);}\n"
                        . "  gtag('js', new Date());\n"
                        . "  gtag('config', '{$ga4Id}');\n"
                        . "</script>";
                }
            }
        }

        return implode("\n    ", $html);
    }

    /**
     * Render tags immediately after <body> (e.g. GTM noscript iframe).
     */
    public static function renderBodyTags(): string
    {
        if (self::isAdminRequest()) {
            return '';
        }

        $gtmEnabled = (int)Setting::get('seo', 'gtm_enabled', 0);
        $rawGtmId = trim((string)Setting::get('seo', 'gtm_container_id', ''));
        if ($gtmEnabled === 1 && preg_match('/^GTM-[A-Z0-9]+$/i', $rawGtmId)) {
            $gtmId = htmlspecialchars(strtoupper($rawGtmId), ENT_QUOTES, 'UTF-8');
            return "<!-- Google Tag Manager (noscript) -->\n"
                . "<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id={$gtmId}\"\n"
                . "height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>\n"
                . "<!-- End Google Tag Manager (noscript) -->";
        }

        return '';
    }

    protected static function isAdminRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        return str_starts_with($path, '/admin');
    }

    /**
     * Resolve base site URL dynamically from settings, request, or config without hardcoding.
     */
    public static function resolveBaseUrl(): string
    {
        $server = $_SERVER;
        $scheme = (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off')
            || (($server['SERVER_PORT'] ?? null) === '443')
            || (($server['HTTP_X_FORWARDED_PROTO'] ?? null) === 'https')
            ? 'https' : 'http';
        $host = (string)($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '');

        $basePath = (string)($GLOBALS['favorite_cms_base_path'] ?? '');
        if ($basePath === '' && PHP_SAPI !== 'cli' && !empty($server['SCRIPT_NAME'])) {
            $dir = dirname($server['SCRIPT_NAME']);
            $dir = str_replace('\\', '/', $dir);
            if ($dir !== '/' && $dir !== '.' && str_starts_with($dir, '/')) {
                $basePath = rtrim($dir, '/');
            }
        }
        if ($basePath !== '') {
            $basePath = '/' . ltrim($basePath, '/');
        }

        $siteUrlSetting = trim((string)Setting::get('general', 'site_url', ''));
        if ($siteUrlSetting !== '' && !str_contains($siteUrlSetting, 'favorite-cms.local') && !str_contains($siteUrlSetting, 'localhost')) {
            return rtrim($siteUrlSetting, '/');
        }

        if ($host !== '' && $host !== 'localhost') {
            return rtrim($scheme . '://' . $host . $basePath, '/');
        }

        if ($siteUrlSetting !== '') {
            return rtrim($siteUrlSetting, '/');
        }

        $configUrl = rtrim((string)config('app.url', 'http://favorite-cms.local'), '/');
        if ($host !== '') {
            return rtrim($scheme . '://' . $host . $basePath, '/');
        }

        return $configUrl;
    }

    protected static function resolveCanonicalUrl(array $context, ?object $seoMeta, ?Post $post, ?Page $page): string
    {
        // 1. Explicit canonical override on post/page
        if ($seoMeta && !empty($seoMeta->canonical_url)) {
            return (string)$seoMeta->canonical_url;
        }

        if (!empty($context['canonicalUrl'])) {
            return (string)$context['canonicalUrl'];
        }

        // 2. Post or page permalink
        $baseSiteUrl = self::resolveBaseUrl();
        if ($post instanceof Post) {
            $path = method_exists($post, 'url') ? $post->url() : ('/post/' . $post->slug);
            return rtrim($baseSiteUrl, '/') . '/' . ltrim($path, '/');
        }

        if ($page instanceof Page) {
            $path = method_exists($page, 'url') ? $page->url() : ('/page/' . $page->slug);
            return rtrim($baseSiteUrl, '/') . '/' . ltrim($path, '/');
        }

        // 3. Current public request path (sanitized without query string/fragments for default canonical)
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $cleanPath = parse_url($uri, PHP_URL_PATH) ?: '/';
        $subPath = parse_url($baseSiteUrl, PHP_URL_PATH) ?: '';
        if ($subPath !== '' && ($cleanPath === $subPath || str_starts_with($cleanPath, $subPath . '/'))) {
            $rel = substr($cleanPath, strlen($subPath));
            return rtrim($baseSiteUrl, '/') . ($rel === '' || $rel === '/' ? '/' : '/' . ltrim($rel, '/'));
        }

        return rtrim($baseSiteUrl, '/') . ($cleanPath === '/' ? '/' : '/' . ltrim($cleanPath, '/'));
    }

    protected static function resolveRobots(array $context, ?object $seoMeta): string
    {
        if ($seoMeta && !empty($seoMeta->robots)) {
            return (string)$seoMeta->robots;
        }

        if (!empty($context['robots'])) {
            return (string)$context['robots'];
        }

        return (string)Setting::get('seo', 'robots_meta', 'index,follow');
    }

    protected static function resolveTitle(array $context, ?object $seoMeta, string $siteTitle, string $sep, ?Post $post = null, ?Page $page = null): string
    {
        if ($seoMeta && !empty($seoMeta->og_title)) {
            return (string)$seoMeta->og_title;
        }

        if ($seoMeta && !empty($seoMeta->meta_title)) {
            return (string)$seoMeta->meta_title;
        }

        if (!empty($context['metaTitle'])) {
            return (string)$context['metaTitle'];
        }

        if ($post instanceof Post && !empty($post->title)) {
            return (string)$post->title . " {$sep} " . $siteTitle;
        }

        if ($page instanceof Page && !empty($page->title)) {
            return (string)$page->title . " {$sep} " . $siteTitle;
        }

        if (!empty($context['archiveTitle'])) {
            return (string)$context['archiveTitle'] . " {$sep} " . $siteTitle;
        }

        return $siteTitle;
    }

    protected static function resolveDescription(array $context, ?object $seoMeta, string $siteDesc, ?Post $post = null, ?Page $page = null): string
    {
        if ($seoMeta && !empty($seoMeta->og_description)) {
            return (string)$seoMeta->og_description;
        }

        if ($seoMeta && !empty($seoMeta->meta_description)) {
            return (string)$seoMeta->meta_description;
        }

        if (!empty($context['metaDescription'])) {
            return (string)$context['metaDescription'];
        }

        if ($post instanceof Post) {
            $excerpt = trim((string)($post->excerpt ?: strip_tags((string)$post->content)));
            if ($excerpt !== '') {
                return mb_substr($excerpt, 0, 160);
            }
        }

        if ($page instanceof Page) {
            $excerpt = trim(strip_tags((string)$page->content));
            if ($excerpt !== '') {
                return mb_substr($excerpt, 0, 160);
            }
        }

        $globalMetaDesc = (string)Setting::get('seo', 'meta_description', '');
        if ($globalMetaDesc !== '') {
            return $globalMetaDesc;
        }

        return $siteDesc;
    }

    protected static function resolveImage(array $context, ?object $seoMeta, ?Post $post, ?Page $page): string
    {
        if (!empty($context['ogImage'])) {
            return (string)$context['ogImage'];
        }

        // Featured image of post/page
        if ($post instanceof Post) {
            $featImg = $post->getFeaturedImage();
            if ($featImg && !empty($featImg->url)) {
                return self::toAbsoluteUrl((string)$featImg->url);
            }
        } elseif ($page instanceof Page) {
            $featImg = $page->getFeaturedImage();
            if ($featImg && !empty($featImg->url)) {
                return self::toAbsoluteUrl((string)$featImg->url);
            }
        }

        // Default SEO open graph image
        $defaultOg = trim((string)Setting::get('seo', 'og_image', ''));
        if ($defaultOg !== '') {
            return self::toAbsoluteUrl($defaultOg);
        }

        return '';
    }

    protected static function toAbsoluteUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $baseSiteUrl = self::resolveBaseUrl();
        return $baseSiteUrl . '/' . ltrim($url, '/');
    }

    protected static function generateJsonLd(
        array $context,
        string $title,
        string $description,
        string $canonicalUrl,
        string $image,
        string $siteTitle,
        ?Post $post,
        ?Page $page,
        bool $isHome
    ): string {
        $baseSiteUrl = self::resolveBaseUrl();

        $graphs = [];

        if ($isHome) {
            $graphs[] = [
                '@type'       => 'WebSite',
                '@id'         => $baseSiteUrl . '/#website',
                'url'         => $baseSiteUrl . '/',
                'name'        => $siteTitle,
                'description' => $description,
                'potentialAction' => [
                    '@type'       => 'SearchAction',
                    'target'      => $baseSiteUrl . '/search?q={search_term_string}',
                    'query-input' => 'required name=search_term_string',
                ],
            ];
        } elseif ($post instanceof Post) {
            $author = $post->getAuthor();
            $authorName = $author ? (string)($author->name ?: ($author->username ?: 'Author')) : $siteTitle;
            $pubDate = !empty($post->published_at) ? format_date($post->published_at, 'c') : format_date($post->created_at, 'c');
            $modDate = !empty($post->updated_at) ? format_date($post->updated_at, 'c') : $pubDate;

            $articleData = [
                '@type'            => 'Article',
                '@id'              => $canonicalUrl . '#article',
                'isPartOf'         => ['@id' => $baseSiteUrl . '/#website'],
                'headline'         => $title,
                'description'      => $description,
                'datePublished'    => $pubDate,
                'dateModified'     => $modDate,
                'mainEntityOfPage' => $canonicalUrl,
                'author'           => [
                    '@type' => 'Person',
                    'name'  => $authorName,
                ],
                'publisher'        => [
                    '@type' => 'Organization',
                    'name'  => $siteTitle,
                ],
            ];

            if ($image !== '') {
                $articleData['image'] = $image;
            }

            $graphs[] = $articleData;

            // BreadcrumbList for post
            $graphs[] = [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    [
                        '@type'    => 'ListItem',
                        'position' => 1,
                        'name'     => 'Home',
                        'item'     => $baseSiteUrl . '/',
                    ],
                    [
                        '@type'    => 'ListItem',
                        'position' => 2,
                        'name'     => $post->title,
                        'item'     => $canonicalUrl,
                    ],
                ],
            ];
        } elseif ($page instanceof Page) {
            $graphs[] = [
                '@type'       => 'WebPage',
                '@id'         => $canonicalUrl . '#webpage',
                'url'         => $canonicalUrl,
                'name'        => $title,
                'description' => $description,
                'isPartOf'    => ['@id' => $baseSiteUrl . '/#website'],
            ];

            // BreadcrumbList for page
            $graphs[] = [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    [
                        '@type'    => 'ListItem',
                        'position' => 1,
                        'name'     => 'Home',
                        'item'     => $baseSiteUrl . '/',
                    ],
                    [
                        '@type'    => 'ListItem',
                        'position' => 2,
                        'name'     => $page->title,
                        'item'     => $canonicalUrl,
                    ],
                ],
            ];
        }

        if (empty($graphs)) {
            return '';
        }

        $payload = [
            '@context' => 'https://schema.org',
            '@graph'   => $graphs,
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_PRETTY_PRINT) ?: '';
    }
}