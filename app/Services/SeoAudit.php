<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
use FavoriteCMS\Core\{Container,Database,Url};
use FavoriteCMS\Models\{Post,Page};
final class SeoAudit {
 public static function issues(Post|Page $item): array {
  $issues=SeoMetadata::issues($item);$db=Container::getInstance()->get(Database::class);$type=$item instanceof Post?'post':'page';$meta=$item->getSeoMeta();
  $title=trim((string)(($meta->meta_title??'')?:$item->title));
  $duplicates=$db->rememberSelect("SELECT effective,COUNT(*) AS cnt FROM (SELECT COALESCE(NULLIF(TRIM(s.meta_title),''),p.title) AS effective FROM `posts` p LEFT JOIN `seo_meta` s ON s.object_type='post' AND s.object_id=p.id WHERE p.status='published' UNION ALL SELECT COALESCE(NULLIF(TRIM(s.meta_title),''),p.title) AS effective FROM `pages` p LEFT JOIN `seo_meta` s ON s.object_type='page' AND s.object_id=p.id WHERE p.status='published') items GROUP BY effective HAVING COUNT(*)>1");
  if($item->status==='published')foreach($duplicates as$row)if($row->effective===$title){$issues[]='Duplicate search title';break;}
  if(!empty($meta->meta_description)){
   $duplicates=$db->rememberSelect("SELECT meta_description FROM `seo_meta` s WHERE EXISTS (SELECT 1 FROM `posts` p WHERE s.object_type='post' AND s.object_id=p.id AND p.status='published') OR EXISTS (SELECT 1 FROM `pages` p WHERE s.object_type='page' AND s.object_id=p.id AND p.status='published') GROUP BY meta_description HAVING COUNT(*)>1");
   foreach($duplicates as$row)if($row->meta_description===$meta->meta_description){$issues[]='Duplicate custom description';break;}
  }
  if(preg_match_all('#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1#is',(string)$item->content,$links)){
   $base=Url::base();$basePath=rtrim((string)parse_url($base,PHP_URL_PATH),'/');$lookups=[];
   foreach(array_slice($links[2],0,100)as$link){
    $link=html_entity_decode($link,ENT_QUOTES,'UTF-8');
    if(str_starts_with($link,'http') && strtolower((string)parse_url($link,PHP_URL_HOST))!==strtolower((string)parse_url($base,PHP_URL_HOST)))continue;
    $path=(string)parse_url($link,PHP_URL_PATH);
    if(!preg_match('#^(?:'.preg_quote($basePath,'#').')?/(post|page)/([^/]+)/?$#',$path,$match))continue;
    $kind=$match[1];$slug=rawurldecode($match[2]);
    if(!isset($lookups[$kind])){$table=$kind==='post'?'posts':'pages';$lookups[$kind]=[];foreach($db->rememberSelect("SELECT slug FROM `{$table}` WHERE status='published'")as$row)$lookups[$kind][$row->slug]=true;}
    if(!isset($lookups[$kind][$slug]) && !SeoRedirects::resolve($kind,$slug)){$issues[]='Broken internal content link: '.$path;break;}
   }
  }
  return $issues;
 }
 public static function related(Post|Page $item): array {
  $db=Container::getInstance()->get(Database::class);
  if($item instanceof Post){$rows=$db->select("SELECT DISTINCT p.id,p.title,p.slug FROM `posts` p JOIN `post_taxonomies` theirs ON theirs.post_id=p.id JOIN `post_taxonomies` mine ON mine.taxonomy_id=theirs.taxonomy_id LEFT JOIN `seo_meta` s ON s.object_type='post' AND s.object_id=p.id WHERE mine.post_id=? AND p.id<>? AND p.status='published' AND LOWER(COALESCE(s.robots,'')) NOT LIKE '%noindex%' ORDER BY p.id DESC LIMIT 3",[$item->id,$item->id]);}
  else$rows=[];
  return array_map(static fn($row)=>['title'=>$row->title,'url'=>Url::to('/post/'.$row->slug)],$rows);
 }
}