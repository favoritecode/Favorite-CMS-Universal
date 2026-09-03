<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

use FavoriteCMS\Models\User;

class ContentSanitizer
{
    /**
     * Whitelist of safe HTML tags for standard/unprivileged content authors.
     */
    protected const ALLOWED_TAGS = '<p><br><hr><h1><h2><h3><h4><h5><h6><strong><b><em><i><u><s><del><strike><blockquote><ul><ol><li><a><img><table><thead><tbody><tfoot><tr><th><td><code><pre><figure><figcaption><video><audio><source><div><span>';

    /**
     * Sanitize post/page HTML content.
     *
     * @param string $content Raw HTML or plain text.
     * @param User|int|null $user The author or logged-in user.
     * @return string Cleaned, safe content.
     */
    public static function clean(string $content, mixed $user = null): string
    {
        if (trim($content) === '') {
            return '';
        }

        // Check if user has permission to post unfiltered HTML
        $canPostUnfiltered = self::userCanPostRawHtml($user);

        if ($canPostUnfiltered) {
            // Even for administrators, strip null bytes and malformed UTF-8 sequences
            return self::stripDangerousRawBytes($content);
        }

        return self::sanitizeMarkup($content);
    }

    /**
     * Determine whether the given user or current session user can publish raw unfiltered HTML.
     */
    public static function userCanPostRawHtml(mixed $user = null): bool
    {
        $userModel = null;

        if ($user instanceof User) {
            $userModel = $user;
        } elseif (is_numeric($user) && (int)$user > 0) {
            $userModel = User::find((int)$user);
        } elseif (isset($_SESSION['auth_user_id'])) {
            $userModel = User::find((int)$_SESSION['auth_user_id']);
        }

        if (!$userModel) {
            return false;
        }

        return $userModel->hasRole('super-admin')
            || $userModel->hasRole('admin')
            || $userModel->hasPermission('unfiltered_html')
            || $userModel->hasPermission('manage_settings');
    }

    /**
     * Sanitize HTML for standard users: strip disallowed tags, script execution, event handlers, and javascript: links.
     */
    public static function sanitizeMarkup(string $content): string
    {
        // 1. If plain text without any HTML tags, wrap nicely
        if ($content === strip_tags($content)) {
            return '<p>' . nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8')) . '</p>';
        }

        // 2. Remove comments and XML junk (often from MS Word paste)
        $cleaned = preg_replace('/<!--.*?-->/s', '', $content);
        $cleaned = preg_replace('/<\?xml.*?\?>/i', '', $cleaned);
        $cleaned = preg_replace('/<o:p>.*?<\/o:p>/i', '', $cleaned);

        // 3. Remove script, style, iframe, object, embed, frame tags and their contents entirely
        $dangerousTags = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'frameset', 'frame', 'meta', 'link', 'base', 'form'];
        foreach ($dangerousTags as $tag) {
            $cleaned = preg_replace('#<' . $tag . '\b[^>]*>.*?</' . $tag . '>#is', '', $cleaned);
            $cleaned = preg_replace('#<' . $tag . '\b[^>]*\/?>#is', '', $cleaned);
        }

        // 4. Strip tags not in whitelist
        $cleaned = strip_tags($cleaned, self::ALLOWED_TAGS);

        // 5. Strip all inline on* event handler attributes (e.g. onclick, onload, onerror)
        $cleaned = preg_replace('/\s*\b(on\w+)\s*=\s*(["\']).*?\2/is', '', $cleaned);
        $cleaned = preg_replace('/\s*\b(on\w+)\s*=\s*[^ >]+/is', '', $cleaned);

        // 6. Strip javascript: and vbscript: protocols in href and src
        $cleaned = preg_replace('/\b(href|src)\s*=\s*(["\'])\s*(?:javascript|vbscript|data(?!\:image\/[a-z0-9\+\-]+;base64,)):.*?\2/is', '$1="#"', $cleaned);
        $cleaned = preg_replace('/\b(href|src)\s*=\s*(?:javascript|vbscript):[^\s>]+/is', '$1="#"', $cleaned);

        // 7. Strip expressions in style attributes
        $cleaned = preg_replace('/style\s*=\s*(["\']).*?(?:expression|behavior|javascript).*?\1/is', '', $cleaned);

        // 8. Clean up trailing spaces before closing tag bracket (e.g. <img src="x" > to <img src="x">)
        $cleaned = preg_replace('/\s+>/', '>', $cleaned);

        return $cleaned;
    }

    /**
     * Clean raw bytes for administrators without altering valid embed tags or scripts they legitimately place.
     */
    protected static function stripDangerousRawBytes(string $content): string
    {
        // Remove null bytes
        $content = str_replace(chr(0), '', $content);

        // Ensure valid UTF-8
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        }

        return $content;
    }
}
