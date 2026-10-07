<?php
declare(strict_types=1);
namespace FavoriteCMS\Http\Controllers\Admin;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Page;
use FavoriteCMS\Models\User;
use FavoriteCMS\Services\ContentWorkspace;
use FavoriteCMS\Services\ContentRevision;

final class ContentWorkspaceController
{
    public function handle(Request $request, string $type, string $action, string $method): Response {
        $actor = User::find((int)($_SESSION['auth_user_id'] ?? 0));
        $id = (int)($method === 'POST' ? $request->post('id',0) : $request->get('id',0));
        $record = $id > 0 ? ($type === 'post' ? Post::find($id) : Page::find($id)) : null;
        if (!$actor || !$actor->isActive() || ($id > 0 && !$record)
            || ($type === 'post' ? ($record ? !$actor->canEditPost($record) : !$actor->canCreatePosts()) : !$actor->canManagePages())) {
            return Response::json(['success'=>false,'error'=>'You do not have permission to edit this content.'],403);
        }
        if (($action === 'autosave' && $method !== 'POST') || ($action !== 'autosave' && $method !== 'GET')) return Response::json(['success'=>false,'error'=>'Method not allowed.'],405);
        if (!ContentWorkspace::available()) return Response::json(['success'=>false,'error'=>'Run the core database update to enable server drafts and history.'],503);
        $db = ContentWorkspace::db();
        $baseline = ContentWorkspace::fingerprint($record);
        try {
            if ($action === 'autosave') {
                if (!hash_equals($baseline,(string)$request->post('baseline',''))) return Response::json(['success'=>false,'error'=>'This content changed in another tab or account. Reopen the editor before saving.'],409);
                $client=(string)$request->post('client_id','');
                if (!preg_match('/^[a-zA-Z0-9_-]{8,64}$/D',$client)) return Response::json(['success'=>false,'error'=>'Invalid draft tab.'],400);
                $content=ContentRevision::clean((string)$request->post('content',''),$record,$type,$actor,(string)$request->post('_content_revision',''));
                $payload=json_encode(['title'=>(string)$request->post('title',''),'content'=>$content,'excerpt'=>(string)$request->post('excerpt','')],JSON_THROW_ON_ERROR);
                if (strlen($payload)>4*1024*1024) return Response::json(['success'=>false,'error'=>'Server draft exceeds 4 MB. You can still use the normal save.'],413);
                $bindings=[(int)$actor->id,$type,$id,$client];
                $row=$db->selectOne('SELECT id FROM `content_drafts` WHERE user_id=? AND content_type=? AND content_id=? AND client_id=?',$bindings);
                $data=['user_id'=>(int)$actor->id,'content_type'=>$type,'content_id'=>$id,'client_id'=>$client,'baseline'=>$baseline,'payload'=>$payload,'updated_at'=>date('Y-m-d H:i:s')];
                if ($row) $db->update('content_drafts',$data,['id'=>$row->id]); else $db->insert('content_drafts',$data);
                // At most ten recoverable tabs/new drafts per user and type. Never prune another account's drafts.
                $old=$db->select('SELECT id FROM `content_drafts` WHERE user_id=? AND content_type=? ORDER BY updated_at DESC,id DESC LIMIT 1000 OFFSET 10',[(int)$actor->id,$type]);
                foreach($old as $item) $db->delete('content_drafts',['id'=>$item->id]);
                return Response::json(['success'=>true,'hash'=>hash('sha256',$payload),'saved_at'=>$data['updated_at']]);
            }
            if ($action === 'workspace') {
                $drafts=$db->select('SELECT id,updated_at,baseline FROM `content_drafts` WHERE user_id=? AND content_type=? AND content_id=? ORDER BY updated_at DESC,id DESC LIMIT 10',[(int)$actor->id,$type,$id]);
                $history=$id>0?$db->select('SELECT id,created_at FROM `content_history` WHERE content_type=? AND content_id=? ORDER BY id DESC LIMIT 30',[$type,$id]):[];
                return Response::json(['success'=>true,'baseline'=>$baseline,'drafts'=>$drafts,'history'=>$history]);
            }
            if ($action === 'snapshot') {
                $kind=(string)$request->get('kind','draft'); $snapshotId=(int)$request->get('snapshot_id',0);
                $row=$kind==='history'?$db->selectOne('SELECT payload FROM `content_history` WHERE id=? AND content_type=? AND content_id=?',[$snapshotId,$type,$id])
                    :$db->selectOne('SELECT payload FROM `content_drafts` WHERE id=? AND user_id=? AND content_type=? AND content_id=?',[$snapshotId,(int)$actor->id,$type,$id]);
                if (!$row) return Response::json(['success'=>false,'error'=>'Snapshot not found.'],404);
                $fields=ContentWorkspace::editable(json_decode($row->payload,true,512,JSON_THROW_ON_ERROR),$record,$type,$actor);
                return Response::json(['success'=>true,'fields'=>$fields]);
            }
            return Response::json(['success'=>false,'error'=>'Not found.'],404);
        } catch (\RuntimeException $error) {
            return Response::json(['success'=>false,'error'=>$error->getMessage()],409);
        }
    }
}