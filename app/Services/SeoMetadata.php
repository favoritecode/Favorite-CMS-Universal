<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
use FavoriteCMS\Models\{Post,Page,Setting};
use FavoriteCMS\Core\Url;

/** Read-time defaults: curated metadata is never written over by automatic values. */
final class SeoMetadata
{
    public static function text(string $html, bool $stripShortcodes = false): string
    {
        if (!mb_check_encoding($html, 'UTF-8')) $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        if ($stripShortcodes) $html = preg_replace('/\[(?:\/?[a-z][a-z0-9_-]*)(?:\s[^\]]*)?\]/i', ' ', $html) ?? $html;
        $html = preg_replace('#<(script|style|template|noscript)\b[^>]*>.*?(?:</\1\s*>|$)#is', ' ', $html) ?? $html;
        $html = preg_replace('#</?(?:p|div|h[1-6]|li|br|section|article|blockquote|td|tr)\b[^>]*>#i', ' ', $html) ?? $html;
        return trim(preg_replace('/[\s\x{00a0}]+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
    public static function summary(string $html, int $limit = 160): string
    {
        $text = self::text($html, true);
        if (mb_strlen($text) <= $limit) return $text;
        $short = mb_substr($text, 0, $limit + 1);
        $lastSpace = mb_strrpos($short, ' ');
        if ($lastSpace !== false && $lastSpace >= (int)($limit * .55)) return rtrim(mb_substr($short, 0, $lastSpace), ' ,;:') . '…';
        preg_match_all('/\X/u', $text, $clusters); $short = '';
        foreach ($clusters[0] as $cluster) { if (mb_strlen($short . $cluster) > $limit) break; $short .= $cluster; }
        return rtrim($short) . '…';
    }
    public static function resolve(array $context): array
    {
        $item = ($context['post'] ?? null) instanceof Post ? $context['post'] : (($context['page'] ?? null) instanceof Page ? $context['page'] : null);
        $seo = $item?->getSeoMeta();
        $site = self::text((string)Setting::get('general','site_name','Favorite CMS'));
        $sep = (string)Setting::get('seo','title_separator','|');
        $title = self::text((string)($seo->meta_title ?? ''));
        if ($title === '') $title = $item ? self::text((string)$item->title) . ($site !== '' ? ' ' . $sep . ' ' . $site : '') : self::text((string)($context['metaTitle'] ?? (($context['archiveTitle'] ?? '') !== '' ? $context['archiveTitle'] . ' ' . $sep . ' ' . $site : $site)));
        $desc = self::text((string)($seo->meta_description ?? ''));
        if ($desc === '' && $item) $desc = self::summary((string)($item->excerpt ?: $item->content));
        if ($desc === '' && !$item) $desc = self::text((string)($context['metaDescription'] ?? ''));
        if ($desc === '') $desc = self::summary((string)Setting::get('seo','meta_description',Setting::get('general','site_description','')));
        if ($desc === '' && $item) $desc = self::text((string)$item->title);
        $source = !empty($seo->meta_description) ? 'Custom' : ($item && trim((string)$item->excerpt) !== '' ? 'Excerpt' : ($item && self::text((string)$item->content) !== '' ? 'Content' : 'Site default'));
        return ['title'=>$title,'description'=>$desc,'og_title'=>self::text((string)($seo->og_title ?? '')) ?: $title,'og_description'=>self::text((string)($seo->og_description ?? '')) ?: $desc,'source'=>$source];
    }
    public static function httpUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[\x00-\x20\x7f]/', $value)) return '';
        if (str_starts_with($value,'/') && !str_starts_with($value,'//')) {
            $base = Url::base(); $path = rtrim((string)parse_url($base, PHP_URL_PATH), '/');
            if ($path !== '' && ($value === $path || str_starts_with($value, $path . '/'))) {
                $port = parse_url($base, PHP_URL_PORT);
                $value = parse_url($base, PHP_URL_SCHEME) . '://' . parse_url($base, PHP_URL_HOST) . ($port ? ':' . $port : '') . $value;
            } else $value = Url::to($value);
        }
        if (!filter_var($value,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($value,PHP_URL_SCHEME)),['http','https'],true) || parse_url($value,PHP_URL_USER) !== null) return '';
        return $value;
    }
    public static function issues(Post|Page $item): array
    {
        $seo = $item->getSeoMeta(); $meta = self::resolve([$item instanceof Post ? 'post':'page'=>$item]); $issues=[];
        if (trim((string)$item->title)==='') $issues[]='Missing content title';
        if (self::text((string)$item->content)==='') $issues[]='No readable text: review image/embed-only content';
        if (mb_strlen($meta['title'])>70) $issues[]='Long search title: review preview';
        if (str_contains(strtolower((string)($seo->robots ?? '')),'noindex') || preg_match('/(?:^|[ ,])none(?:$|[ ,])/i',(string)($seo->robots ?? ''))) $issues[]='Intentionally excluded from indexing';
        if (!empty($seo->canonical_url) && self::httpUrl((string)$seo->canonical_url)==='') $issues[]='Invalid canonical override';
        if (!empty($seo->canonical_url) && ($canonical = self::httpUrl((string)$seo->canonical_url)) !== '' && rtrim($canonical, '/') !== rtrim(Url::to($item->url()), '/')) $issues[]='Canonical points to another URL: review intended destination';
        preg_match_all('#<img\b[^>]*>#i',(string)$item->content,$images);
        foreach ($images[0] as $image) if (!preg_match('/\balt\s*=/i',$image)) { $issues[]='Image missing alt text'; break; }
        if (substr_count(strtolower((string)$item->content),'<h1')>1) $issues[]='Review multiple H1 headings';
        return $issues;
    }
}