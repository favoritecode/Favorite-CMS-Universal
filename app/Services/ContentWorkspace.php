<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\User;

/** Private durable drafts and bounded title/content revision history. */
final class ContentWorkspace
{
    public static function db(): Database { return Container::getInstance()->get(Database::class); }
    public static function available(): bool { return self::db()->tableExists('content_drafts') && self::db()->tableExists('content_history'); }
    public static function fingerprint(?object $record): string {
        return hash('sha256', $record ? json_encode([(int)$record->id, (string)$record->title, (string)$record->content, (string)($record->excerpt ?? ''), (string)$record->updated_at]) : 'new');
    }
    public static function capture(object $record, string $type): void {
        try { self::captureSnapshot($record, $type); } catch (\Throwable $error) { error_log('Favorite CMS revision could not be recorded: ' . $error->getMessage()); $_SESSION['flash_error'] = 'Revision history could not be recorded. Normal saving remains available; check the server log.'; }
    }
    private static function captureSnapshot(object $record, string $type): void {
        $db = self::db();
        if (!$db->tableExists('content_history')) return; // Older installations continue saving until migrations run.
        $payload = ['title'=>(string)$record->title, 'content'=>ContentRevision::display($record, $type), 'excerpt'=>(string)($record->excerpt ?? '')];
        $hash = hash('sha256', json_encode($payload));
        $last = $db->selectOne('SELECT fingerprint FROM `content_history` WHERE content_type=? AND content_id=? ORDER BY id DESC LIMIT 1', [$type,(int)$record->id]);
        if ($last && hash_equals((string)$last->fingerprint,$hash)) return;
        $db->insert('content_history', ['user_id'=>(int)($_SESSION['auth_user_id'] ?? 0),'content_type'=>$type,'content_id'=>(int)$record->id,'fingerprint'=>$hash,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'created_at'=>date('Y-m-d H:i:s')]);
        $old = $db->select('SELECT id FROM `content_history` WHERE content_type=? AND content_id=? ORDER BY id DESC LIMIT 1000 OFFSET 30', [$type,(int)$record->id]);
        foreach ($old as $row) $db->delete('content_history',['id'=>$row->id]);
    }
    /** Convert a validated server draft/history snapshot back into this actor's protected editor representation. */
    public static function editable(array $payload, ?object $original, string $type, User $actor): array {
        $content = (string)($payload['content'] ?? '');
        $prepared = ContentRevision::prepare($original,$type,$actor);
        if (!ContentSanitizer::userCanPostRawHtml($actor)) {
            if ($prepared['token'] !== '') {
                $blocks = $_SESSION['content_revisions'][$prepared['token']]['blocks'] ?? [];
                foreach ($blocks as $marker=>$raw) {
                    if (substr_count($content,$raw) !== 1) throw new \RuntimeException('This version changes protected custom code. An administrator must restore it.');
                    $content = str_replace($raw,$marker,$content);
                }
            }
            // This rejects added active code and altered protected blocks; same write protections as normal saves.
            $clean = ContentRevision::clean($content,$original,$type,$actor,$prepared['token']);
            if ($prepared['token'] === '') $content = $clean;
        }
        return ['title'=>(string)($payload['title'] ?? ''),'content'=>$content,'excerpt'=>(string)($payload['excerpt'] ?? ''),'token'=>$prepared['token']];
    }
    /** Remove only the exact tab snapshot accepted by the normal save, preserving any newer autosave. */
    public static function acknowledge(\FavoriteCMS\Core\Request $request, string $type): void {
        $client = (string)$request->post('_workspace_client','');
        $hash = (string)$request->post('_workspace_hash','');
        if ($client === '' || $hash === '' || !self::db()->tableExists('content_drafts')) return;
        $where=['user_id'=>(int)($_SESSION['auth_user_id'] ?? 0),'content_type'=>$type,'content_id'=>(int)$request->post('id',0),'client_id'=>$client];
        $row=self::db()->selectOne('SELECT id,payload FROM `content_drafts` WHERE user_id=? AND content_type=? AND content_id=? AND client_id=?',array_values($where));
        if ($row && hash_equals(hash('sha256',(string)$row->payload),$hash)) self::db()->delete('content_drafts',['id'=>$row->id]);
    }
}