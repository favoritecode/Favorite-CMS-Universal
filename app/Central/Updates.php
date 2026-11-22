<?php
declare(strict_types=1);
namespace FavoriteCMS\Central;
final class Updates {
 public function __construct(public Client $client){}
 public function metadata(string $product):array{
  $response=$this->client->request(['action'=>'release','product_id'=>$product]);$release=$response['release']??null;
  if(!is_array($release))throw new \RuntimeException('No central release is currently published.');
  $c=$this->client->config();$keys=($c['public_keys']??[])+[$c['key_id']=>$c['public_key']];
  $key=$keys[$release['key_id']??'']??null;
  if(!$key||!Crypto::verify($release,$key))throw new \RuntimeException('Release publisher signature could not be verified.');
  $p=$release['payload'];if(($p['product_id']??'')!==$product||!preg_match('/^[a-f0-9]{64}$/',(string)($p['sha256']??''))||!preg_match('/^[a-f0-9]{32}$/',(string)($p['release_id']??''))||(int)($p['size']??0)<1||(int)$p['size']>104857600)throw new \RuntimeException('Release metadata is invalid.');
  if($product==='favorite-cms-universal'&&defined('APP_VERSION')&&version_compare((string)$p['version'],APP_VERSION,'<='))throw new \RuntimeException('This CMS is already up to date.');
  return $release;
 }
 public function download(string $product):array{
  $release=$this->metadata($product);$m=$release['payload'];$ticket=$this->client->request(['action'=>'download_ticket','release_id'=>$m['release_id']]);
  $token=(string)($ticket['token']??'');if(!preg_match('/^[a-f0-9]{64}$/',$token))throw new \RuntimeException('Download authorization is invalid.');
  $c=$this->client->config();$url=rtrim($c['server_url'],'/').'/?download='.rawurlencode($token);
  $dir=APP_ROOT.'/storage/temp';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new \RuntimeException('Update storage is not writable.');
  $file=$dir.'/central-'.bin2hex(random_bytes(16)).'.zip';
  try{
   $local=APP_ROOT.'/favorite-server-license/bootstrap.php';$base=preg_replace('~/favorite-central-api/?$~','',$c['server_url']);
   if(is_file($local)&&rtrim((string)config('app.url'),'/')===rtrim($base,'/')){
    require_once $local;$store=\FavoriteCentral\Runtime::store();$vault=new \FavoriteCentral\Vault(APP_ROOT);
    $asset=(new \FavoriteCentral\Releases($store,$vault->directory(),new \FavoriteCentral\Licenses($store,\FavoriteCentral\Runtime::pay())))->consume($token);
    if(!copy($asset['path'],$file))throw new \RuntimeException('Release could not be staged.');
   }else $this->stream($url,$file,(int)$m['size']);
   if(filesize($file)!==(int)$m['size']||!hash_equals($m['sha256'],hash_file('sha256',$file)))throw new \RuntimeException('Downloaded release checksum or size does not match the signed manifest.');
   @chmod($file,0600);return ['path'=>$file,'sha256'=>$m['sha256'],'version'=>$m['version'],'manifest'=>$release];
  }catch(\Throwable $e){if(is_file($file))unlink($file);throw $e;}
 }
 private function stream(string $url,string $file,int $size):void{
  if(!function_exists('curl_init'))throw new \RuntimeException('PHP curl is required for signed downloads.');
  $p=parse_url($url);$host=(string)($p['host']??'');if(($p['scheme']??'')!=='https'||!$host||isset($p['user'])||isset($p['pass'])||isset($p['port'])&&(int)$p['port']!==443)throw new \RuntimeException('Only verified HTTPS downloads are allowed.');
  $ips=[];foreach(@dns_get_record($host,DNS_A|DNS_AAAA)?:[]as$r){$ip=$r['ip']??$r['ipv6']??null;if(!$ip)continue;if(!Transport::publicIp($ip))throw new \RuntimeException('Non-public download destination rejected.');$ips[]=$ip;}if(!$ips)throw new \RuntimeException('Download host cannot be verified.');
  $handle=fopen($file,'x+b');if(!$handle)throw new \RuntimeException('Download staging failed.');$ch=curl_init($url);$written=0;$ip=str_contains($ips[0],':')?'['.$ips[0].']':$ips[0];
  try{
   $opts=[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>180,CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_WRITEFUNCTION=>static function($ch,string $bytes)use($handle,$size,&$written):int{if($written+strlen($bytes)>$size)return 0;$n=fwrite($handle,$bytes);$written+=$n;return $n;}];
   if(defined('CURLOPT_PROTOCOLS_STR'))$opts[CURLOPT_PROTOCOLS_STR]='https';else $opts[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
   curl_setopt_array($ch,$opts);if(curl_exec($ch)===false||(int)curl_getinfo($ch,CURLINFO_HTTP_CODE)!==200)throw new \RuntimeException('Signed download could not complete. Retry with a fresh download authorization.');
  }finally{fclose($handle);unset($ch);}
 }
}
