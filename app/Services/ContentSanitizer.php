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
        if (trim($content) === '') return '';
        if ($content === strip_tags($content)) {
            return '<p>' . nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
        }
        // Parse entities, attributes and CSS before checking URI schemes. The bundled
        // parser also works on supported hosts without the optional DOM extension.
        static $purifier = null;
        if ($purifier === null) {
            require_once dirname(__DIR__, 2) . '/vendor/htmlpurifier/library/HTMLPurifier.auto.php';
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('Cache.DefinitionImpl', null);
            $config->set('Core.LexerImpl', 'DirectLex');
            $config->set('Attr.EnableID', true);
            $config->set('Attr.AllowedFrameTargets', ['_blank', '_self']);
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true, 'data' => true]);
            $config->set('HTML.AllowedElements', explode(',', trim(str_replace(['><','<','>'], [',','',''], self::ALLOWED_TAGS), ',')));
            $definition = $config->getHTMLDefinition(true);
            $definition->addElement('figure', 'Block', 'Flow', 'Common');
            $definition->addElement('figcaption', 'Block', 'Flow', 'Common');
            $definition->addElement('source', 'Inline', 'Empty', 'Common', ['src' => 'URI', 'type' => 'Text', 'media' => 'Text']);
            $mediaAttributes = ['src' => 'URI', 'controls' => 'Bool', 'autoplay' => 'Bool', 'loop' => 'Bool', 'muted' => 'Bool', 'preload' => 'Enum#none,metadata,auto'];
            $definition->addElement('audio', 'Block', 'Flow', 'Common', $mediaAttributes);
            $definition->addElement('video', 'Block', 'Flow', 'Common', $mediaAttributes + ['poster' => 'URI', 'width' => 'Length', 'height' => 'Length', 'playsinline' => 'Bool']);
            $purifier = new \HTMLPurifier($config);
        }
        return $purifier->purify(self::stripDangerousRawBytes($content));
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
