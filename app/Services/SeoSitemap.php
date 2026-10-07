<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
use FavoriteCMS\Core\{Container,Database,Request,Response,Url};
use FavoriteCMS\Models\Setting;
final class SeoSitemap {
 public const CHUNK=1000;
 private static function rowsSql(Database $db,string $base): string {
  $sqlite=$db->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite';
  $global=(string)Setting::get('seo','robots_meta','index,follow');
  $fallback="'".str_replace("'","''",$global)."'";
  $robotValue="LOWER(REPLACE(COALESCE(NULLIF(s.robots,''),{$fallback}),' ',''))";
  $robotList=$sqlite?"',' || ".$robotValue." || ','":"CONCAT(',',".$robotValue.",',')";
  $parts=preg_match('/(?:^|[ ,])(noindex|none)(?:$|[ ,])/i',$global)?[]:["SELECT 'home' AS kind, 0 AS id, '' AS slug, NULL AS modified"];
  foreach(['posts'=>'post','pages'=>'page'] as $table=>$type){
   $path="'".str_replace("'","''",$base.'/'.$type.'/')."'";
   $canonical=$sqlite?$path.' || c.`slug`':'CONCAT('.$path.',c.`slug`)';
   $contentType=$type==='post'?" AND c.`type`='post'":'';
   $parts[]="SELECT '{$type}' AS kind,c.`id`,c.`slug`,c.`updated_at` AS modified FROM `{$table}` c LEFT JOIN `seo_meta` s ON s.`object_type`='{$type}' AND s.`object_id`=c.`id` WHERE c.`status`='published'{$contentType} AND {$robotList} NOT LIKE '%,noindex,%' AND {$robotList} NOT LIKE '%,none,%' AND (TRIM(COALESCE(s.`canonical_url`,''))='' OR s.`canonical_url`={$canonical})";
  }
  $parts[]="SELECT 'category' AS kind,t.`id`,t.`slug`,NULL AS modified FROM `taxonomies` t WHERE t.`taxonomy`='category' AND EXISTS (SELECT 1 FROM `post_taxonomies` pt JOIN `posts` p ON p.`id`=pt.`post_id` WHERE pt.`taxonomy_id`=t.`id` AND p.`status`='published')";
  return implode(' UNION ALL ',$parts);
 }
 public static function render(Request $request,?int $chunk=null): Response {
  $base=rtrim(Url::base($request),'/');$db=Container::getInstance()->get(Database::class);$sql=self::rowsSql($db,$base);
  $total=(int)$db->selectOne('SELECT COUNT(*) AS cnt FROM ('.$sql.') entries')->cnt;
  $chunks=max(1,(int)ceil($total/self::CHUNK));
  $escape=static fn(string $value)=>htmlspecialchars($value,ENT_QUOTES|ENT_XML1,'UTF-8');
  $xml='<?xml version="1.0" encoding="UTF-8"?>'."\n";
  if($chunk===null && $chunks>1){
   $xml.='<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
   for($i=1;$i<=$chunks;$i++)$xml.='<sitemap><loc>'.$escape($base.'/sitemap-'.$i.'.xml').'</loc></sitemap>';
   $xml.='</sitemapindex>';
  }else{
   $number=$chunk??1;if($number<1||$number>$chunks)return Response::make('Sitemap not found.',404);
   $rows=$db->select($sql.' ORDER BY kind,id LIMIT ? OFFSET ?',[self::CHUNK,($number-1)*self::CHUNK]);
   $xml.='<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
   foreach($rows as $row){
    $url=$base.($row->kind==='home'?'/':'/'.$row->kind.'/'.rawurlencode((string)$row->slug));
    $xml.='<url><loc>'.$escape($url).'</loc>';
    if(!empty($row->modified)){try{$date=(new \DateTimeImmutable((string)$row->modified))->format(DATE_W3C);$xml.='<lastmod>'.$escape($date).'</lastmod>';}catch(\Throwable){}}
    $xml.='</url>';
   }
   $xml.='</urlset>';
  }
  return Response::make($xml,200)->header('Content-Type','application/xml; charset=utf-8');
 }
}