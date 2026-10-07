<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
use FavoriteCMS\Core\{Container,Database,Response};
use FavoriteCMS\Models\{Post,Page};
final class SeoRedirects {
 public static function remember(string $type,int $id,string $old,string $new): void {
  if($old===''||$new===''||$old===$new)return;
  try {
   $db=Container::getInstance()->get(Database::class);
   $row=$db->selectOne('SELECT `id` FROM `seo_redirects` WHERE `object_type`=? AND `old_slug`=?',[$type,$old]);
   $data=['object_type'=>$type,'object_id'=>$id,'old_slug'=>$old,'created_at'=>date('Y-m-d H:i:s')];
   if($row)$db->update('seo_redirects',$data,['id'=>$row->id]);else$db->insert('seo_redirects',$data);
  }catch(\Throwable $e){error_log('SEO redirect capture: '.$e->getMessage());}
 }
 public static function resolve(string $type,string $slug): ?Response {
  try {
   $db=Container::getInstance()->get(Database::class);
   $row=$db->selectOne('SELECT `object_id` FROM `seo_redirects` WHERE `object_type`=? AND `old_slug`=?',[$type,$slug]);
   $item=$row?($type==='post'?Post::find((int)$row->object_id):Page::find((int)$row->object_id)):null;
   if($item && $item->status==='published' && $item->slug!==$slug)return Response::redirect($item->url(),301);
  }catch(\Throwable){}
  return null;
 }
}