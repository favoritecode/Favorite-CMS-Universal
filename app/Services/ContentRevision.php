<?php
declare(strict_types=1);

namespace FavoriteCMS\Services;

use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use RuntimeException;

/** Keep existing trusted embeds immutable for editors without raw-HTML permission. */
final class ContentRevision
{
    public static function trusted(object $record, string $type): bool
    {
        $hash = Setting::get('content_trust', $type . '_' . (int)$record->id);
        if (is_string($hash)) return hash_equals($hash, hash('sha256', (string)$record->content));
        // Legacy content predates write provenance. Only privileged authors qualify.
        return ContentSanitizer::userCanPostRawHtml((int)$record->author_id);
    }

    public static function record(object $record, string $type): void
    {
        Setting::set('content_trust', $type . '_' . (int)$record->id, hash('sha256', (string)$record->content));
    }

    public static function display(object $record, string $type): string
    {
        if ((int)$record->id === 0 && ($record->_preview_content_verified ?? false) === true) return (string)$record->content;
        return self::trusted($record, $type) ? (string)$record->content : ContentSanitizer::sanitizeMarkup((string)$record->content);
    }

    public static function prepare(?object $record, string $type, ?User $actor): array
    {
        if (!$record) return ['content' => '', 'token' => ''];
        $content = self::display($record, $type);
        if (ContentSanitizer::userCanPostRawHtml($actor)) return ['content' => $content, 'visual' => $content, 'token' => ''];
        $blocks = [];
        $token = bin2hex(random_bytes(24));
        $editable = '';
        foreach (self::fragments($content) as $fragment) {
            if (preg_match('/<(?:script|style|iframe|object|embed|form|input|button|textarea|select|svg|math)\b|\bon[a-z]+\s*=|(?:javascript|vbscript)\s*:/i', html_entity_decode($fragment, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
                $marker = '<div contenteditable="false" data-favorite-protected="' . $token . '-' . count($blocks) . '">Protected custom code — an administrator can edit this block.</div>';
                $blocks[$marker] = $fragment;
                $editable .= $marker;
            } else {
                $editable .= ContentSanitizer::sanitizeMarkup($fragment);
            }
        }
        if (!$blocks) return ['content' => $content, 'token' => ''];
        $sessions = $_SESSION['content_revisions'] ?? [];
        foreach ($sessions as $key => $value) {
            if (($value['expires'] ?? 0) < time()) unset($sessions[$key]);
        }
        // Bounded, multi-tab edit sessions. No originals are sent to lower-trust clients.
        if (count($sessions) >= 40) array_shift($sessions);
        $sessions[$token] = ['actor' => (int)$actor->id, 'type' => $type, 'id' => (int)$record->id,
            'hash' => hash('sha256', (string)$record->content), 'blocks' => $blocks, 'expires' => time() + 86400];
        $_SESSION['content_revisions'] = $sessions;
        return ['content' => $editable, 'token' => $token];
    }

    public static function clean(string $submitted, ?object $original, string $type, ?User $actor, string $token = ''): string
    {
        if (ContentSanitizer::userCanPostRawHtml($actor)) return ContentSanitizer::clean($submitted, $actor);
        if ($token === '') {
            // API clients may update metadata while keeping the exact stored content.
            if ($original && $submitted === (string)$original->content && self::trusted($original, $type)) return $submitted;
            if ($original && self::trusted($original, $type)) {
                foreach (self::fragments((string)$original->content) as $fragment) {
                    if (preg_match('/<(?:script|style|iframe|object|embed|form|input|button|textarea|select|svg|math)\b|\bon[a-z]+\s*=|(?:javascript|vbscript)\s*:/i', html_entity_decode($fragment, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
                        throw new RuntimeException('This content includes protected custom code. Reopen the editor before saving; your existing content has not changed.');
                    }
                }
            }
            return ContentSanitizer::clean($submitted, $actor);
        }
        $session = $_SESSION['content_revisions'][$token] ?? null;
        if (!$original || !$session || $session['expires'] < time() || $session['actor'] !== (int)$actor->id
            || $session['id'] !== (int)$original->id || $session['type'] !== $type
            || !hash_equals($session['hash'], hash('sha256', (string)$original->content))) {
            throw new RuntimeException('The content changed or this editing session expired. Copy your unsaved text and reload before saving.');
        }
        $result = '';
        foreach ($session['blocks'] as $marker => $raw) {
            if (substr_count($submitted, $marker) !== 1) throw new RuntimeException('Keep each protected custom-code block unchanged. An administrator can edit or remove it.');
            [$before, $submitted] = explode($marker, $submitted, 2);
            if (str_contains($before, 'data-favorite-protected')) throw new RuntimeException('Protected blocks must remain in their original order.');
            // Each editable segment is independently balanced by the HTML parser,
            // so it cannot wrap a trusted block in an attacker-controlled element.
            $result .= ContentSanitizer::sanitizeMarkup($before) . $raw;
        }
        if (str_contains($submitted, 'data-favorite-protected')) throw new RuntimeException('Invalid protected block.');
        return $result . ContentSanitizer::sanitizeMarkup($submitted);
    }

    /** Split stored HTML at balanced top-level boundaries, preserving original bytes. */
    public static function fragments(string $html): array
    {
        $out = []; $stack = []; $start = 0; $offset = 0; $length = strlen($html);
        $void = ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'];
        $pattern = '~<!--.*?(?:-->|$)|<![^>]*>|</?([a-z][a-z0-9:-]*)\b(?:"[^"]*"|\x27[^\x27]*\x27|[^\x27">])*>~is';
        while ($offset < $length && preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $tag = $match[0][0]; $position = $match[0][1]; $end = $position + strlen($tag);
            if (!$stack && $position > $start) { $out[] = substr($html, $start, $position - $start); $start = $position; }
            $name = strtolower($match[1][0] ?? '');
            if ($name !== '' && !in_array($name, $void, true)) {
                if (str_starts_with($tag, '</')) {
                    if (!$stack || array_pop($stack) !== $name) return [$html];
                } elseif (in_array($name, ['script','style','textarea','title'], true)) {
                    if (!preg_match('~</' . $name . '\s*>~i', $html, $close, PREG_OFFSET_CAPTURE, $end)) return [$html];
                    $end = $close[0][1] + strlen($close[0][0]);
                } else {
                    $stack[] = $name;
                }
            }
            $offset = $end;
            if (!$stack) { $out[] = substr($html, $start, $end - $start); $start = $end; }
        }
        if ($stack) return [$html];
        if ($start < $length) $out[] = substr($html, $start);
        return $out;
    }
}
