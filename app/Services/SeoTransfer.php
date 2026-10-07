<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
use FavoriteCMS\Core\{Container,Database,Url};
use FavoriteCMS\Models\{Post,Page};
final class SeoTransfer {
 public const FIELDS=['meta_title','meta_description','og_title','og_description','og_image_url','canonical_url','robots'];
 public static function clean(array $row): array {
  $out=[];
  foreach(self::FIELDS as $field){
   if(!array_key_exists($field,$row))continue;
   if(!is_scalar($row[$field]) && $row[$field]!==null)throw new \InvalidArgumentException('Metadata values must be text.');
   $value=trim((string)$row[$field]);if($value==='')continue;
   if(!mb_check_encoding($value,'UTF-8'))throw new \InvalidArgumentException('Use UTF-8 text.');
   if(in_array($field,['canonical_url','og_image_url'],true)){
    $value=SeoMetadata::httpUrl($value);if($value==='')throw new \InvalidArgumentException('Invalid '.$field.': use an HTTP(S) URL.');
   }elseif($field==='robots'){
    $value=strtolower($value);
    foreach(explode(',',$value)as$directive)if(!preg_match('/^(index|noindex|follow|nofollow|all|none|noarchive|nosnippet|noimageindex|notranslate|max-snippet:-?\d+|max-image-preview:(none|standard|large)|max-video-preview:-?\d+)$/',trim($directive)))throw new \InvalidArgumentException('Invalid robots directive.');
   }else$value=SeoMetadata::text($value);
   $max=in_array($field,['meta_title','og_title'],true)?500:(in_array($field,['canonical_url','og_image_url'],true)?1000:($field==='robots'?100:5000));
   if(mb_strlen($value)>$max)throw new \InvalidArgumentException($field.' is too long.');
   if($value!=='')$out[$field]=$value;
  }
  return $out;
 }
 public static function fingerprint(Post|Page $item): string {
  return hash('sha256',json_encode([$item->id,$item->slug,$item->updated_at,$item->getSeoMeta()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
 }
 public static function write(Post|Page $item,array $values,bool $overwrite=false): int {
  $values=self::clean($values);$existing=$item->getSeoMeta();$changes=[];
  foreach($values as$key=>$value)if(($overwrite||trim((string)($existing->$key??''))==='') && (string)($existing->$key??'')!==$value)$changes[$key]=$value;
  if(!$changes)return 0;
  $db=Container::getInstance()->get(Database::class);$type=$item instanceof Post?'post':'page';
  if($existing)$db->update('seo_meta',$changes,['object_type'=>$type,'object_id'=>$item->id]);
  else$db->insert('seo_meta',$changes+['object_type'=>$type,'object_id'=>$item->id]);
  return count($changes);
 }
 public static function parse(string $contents,string $extension): array {
  if(strlen($contents)>5*1024*1024)throw new \InvalidArgumentException('SEO import limit is 5 MB.');
  $contents=preg_replace('/^\xEF\xBB\xBF/','',$contents)??$contents;
  if(!mb_check_encoding($contents,'UTF-8'))throw new \InvalidArgumentException('Save the file as UTF-8.');
  if($extension==='json'){
   $rows=json_decode($contents,true,64,JSON_THROW_ON_ERROR);$rows=$rows['items']??$rows;
   if(!is_array($rows)||!array_is_list($rows))throw new \InvalidArgumentException('JSON must contain an array of metadata rows.');
  }elseif($extension==='csv'){
   $stream=fopen('php://temp','w+');fwrite($stream,$contents);rewind($stream);$header=fgetcsv($stream,0,',','"','');
   if(!$header)throw new \InvalidArgumentException('CSV header is missing.');
   $header=array_map(static fn($key)=>strtolower(trim((string)$key)),$header);
   if(count(array_unique($header))!==count($header))throw new \InvalidArgumentException('Duplicate CSV columns.');
   $rows=[];while(($values=fgetcsv($stream,0,',','"',''))!==false){if($values===[null])continue;if(count($values)!==count($header))throw new \InvalidArgumentException('CSV row does not match its header.');$rows[]=array_combine($header,$values);if(count($rows)>500)break;}fclose($stream);
  }else throw new \InvalidArgumentException('Upload CSV or JSON.');
  if(count($rows)>500)throw new \InvalidArgumentException('Import up to 500 items at a time.');
  return $rows;
 }
 private static function matchItem(array $row): Post|Page {
  $type=$row['object_type']??'';if(!in_array($type,['post','page'],true))throw new \InvalidArgumentException('object_type must be post or page.');
  $class=$type==='post'?Post::class:Page::class;$matches=[];
  if(!empty($row['id'])){if(!ctype_digit((string)$row['id']))throw new \InvalidArgumentException('Invalid content ID.');$matches[]=$class::find((int)$row['id']);}
  if(!empty($row['slug']))$matches[]=$class::findBySlug((string)$row['slug']);
  if(!empty($row['url'])){
   $url=(string)$row['url'];$base=Url::base();
   if(str_starts_with($url,'/'))$url=SeoMetadata::httpUrl($url);
   if(strtolower((string)parse_url($url,PHP_URL_HOST))!==strtolower((string)parse_url($base,PHP_URL_HOST)) || parse_url($url,PHP_URL_PORT)!==parse_url($base,PHP_URL_PORT))throw new \InvalidArgumentException('URL is not from this site.');
   $path=(string)parse_url($url,PHP_URL_PATH);$basePath=rtrim((string)parse_url($base,PHP_URL_PATH),'/');
   if(!preg_match('#^'.preg_quote($basePath.'/'.$type.'/','#').'([^/]+)/?$#',$path,$m))throw new \InvalidArgumentException('URL does not match its content type.');
   $matches[]=$class::findBySlug(rawurldecode($m[1]));
  }
  if(!$matches||!$matches[0])throw new \InvalidArgumentException('Content was not found.');
  foreach($matches as$item)if(!$item || (int)$item->id!==(int)$matches[0]->id)throw new \InvalidArgumentException('ID, slug and URL identify different content.');
  return $matches[0];
 }
 public static function preview(array $rows,bool $overwrite=false): array {
  $plan=[];$errors=[];$seen=[];
  foreach($rows as$index=>$row){try{
   if(!is_array($row))throw new \InvalidArgumentException('Expected a metadata object.');
   $item=self::matchItem($row);$type=$item instanceof Post?'post':'page';$key=$type.':'.$item->id;
   if(isset($seen[$key]))throw new \InvalidArgumentException('Duplicate content target.');$seen[$key]=true;
   $values=self::clean(is_array($row['seo']??null)?$row['seo']:$row);$old=$item->getSeoMeta();$changes=[];
   foreach($values as$field=>$value)if(($overwrite||trim((string)($old->$field??''))==='')&&(string)($old->$field??'')!==$value)$changes[$field]=['before'=>(string)($old->$field??''),'after'=>$value];
   $plan[]=['type'=>$type,'id'=>(int)$item->id,'title'=>(string)$item->title,'fingerprint'=>self::fingerprint($item),'changes'=>$changes];
  }catch(\Throwable $e){$errors[]='Row '.($index+1).': '.$e->getMessage();}}
  return ['plan'=>$plan,'errors'=>$errors,'overwrite'=>$overwrite];
 }
 public static function apply(array $preview): int {
  if(!empty($preview['errors']))throw new \RuntimeException('Fix all import errors before applying.');
  $db=Container::getInstance()->get(Database::class);
  return $db->transaction(function()use($preview){$count=0;
   foreach($preview['plan'] as$row){$item=$row['type']==='post'?Post::find($row['id']):Page::find($row['id']);if(!$item||!hash_equals($row['fingerprint'],self::fingerprint($item)))throw new \RuntimeException('Content or metadata changed. Preview the import again.');
    $values=[];foreach($row['changes']as$field=>$change)$values[$field]=$change['after'];
    if(self::write($item,$values,(bool)$preview['overwrite'])>0)$count++;
   }return $count;
  });
 }
 public static function csv(array $items): string {
  $stream=fopen('php://temp','w+');fwrite($stream,"\xEF\xBB\xBF");fputcsv($stream,array_merge(['object_type','id','slug'],self::FIELDS),',','"','');
  foreach($items as$item){$seo=$item->getSeoMeta();$row=[$item instanceof Post?'post':'page',(string)$item->id,(string)$item->slug];foreach(self::FIELDS as$field){$value=(string)($seo->$field??'');if(preg_match('/^[\s]*[=+@-]/u',$value))$value="'".$value;$row[]=$value;}fputcsv($stream,$row,',','"','');}
  rewind($stream);$csv=stream_get_contents($stream);fclose($stream);return $csv;
 }
}