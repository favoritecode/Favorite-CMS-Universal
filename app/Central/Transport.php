<?php
declare(strict_types=1);
namespace FavoriteCMS\Central;
final class Transport {
 public static mixed $testTransport=null;
 static function site(string $url):string{
  $url=rtrim(trim($url),'/');$p=parse_url($url);$host=strtolower((string)($p['host']??''));
  if(strlen($url)>480||!filter_var($url,FILTER_VALIDATE_URL)||($p['scheme']??'')!=='https'||!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/',$host)||isset($p['user'],$p['pass'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||isset($p['port'])&&(int)$p['port']!==443||preg_match('/[\x00-\x20\x7f\\\\]/',$url))throw new \InvalidArgumentException('Use the canonical public HTTPS site URL.');
  $path=rtrim((string)($p['path']??''),'/');if(str_contains(rawurldecode($path),'..'))throw new \InvalidArgumentException('Invalid site path.');
  return 'https://'.$host.$path;
 }
 static function publicIp(string $ip):bool{
  $bytes=@inet_pton($ip);if($bytes===false)return false;
  if(strlen($bytes)===16&&substr($bytes,0,12)===str_repeat(chr(0),10).chr(255).chr(255))return self::publicIp((string)inet_ntop(substr($bytes,12)));
  if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return false;
  if(defined('FILTER_FLAG_GLOBAL_RANGE')&&!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_GLOBAL_RANGE))return false;
  if(strlen($bytes)===4){$p=array_values(unpack('C4',$bytes));if($p[0]>=224||$p[0]===0||$p[0]===127||$p[0]===100&&$p[1]>=64&&$p[1]<=127||$p[0]===198&&in_array($p[1],[18,19],true))return false;}
  return strlen($bytes)!==16||(ord($bytes[0])&224)===32;
 }
 static function request(string $url,?string $body=null,array $headers=[]):array{
  if(is_callable(self::$testTransport))return (self::$testTransport)($url,$body,$headers);
  $p=parse_url($url);$host=strtolower((string)($p['host']??''));if(($p['scheme']??'')!=='https'||!$host||isset($p['user'])||isset($p['pass'])||isset($p['port'])&&(int)$p['port']!==443||!function_exists('curl_init'))throw new \RuntimeException('A verified HTTPS connection is unavailable.');
  $v4=[];$v6=[];
  foreach(@dns_get_record($host,DNS_A|DNS_AAAA)?:[]as$r){
   $ip=$r['ip']??$r['ipv6']??null;if(!$ip)continue;
   if(!self::publicIp($ip))throw new \RuntimeException('Non-public destination rejected.');
   if(str_contains($ip,':'))$v6[]=$ip;else $v4[]=$ip;
  }
  $ips=array_slice(array_values(array_unique(array_merge($v4,$v6))),0,3);
  if(!$ips)throw new RemoteError('temporary_network_failure','Destination cannot be resolved temporarily.',503);
  foreach($ips as $ip){
   $resolved=str_contains($ip,':')?'['.$ip.']':$ip;$ch=curl_init($url);$response='';
   try{
    $options=[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROXY=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT_MS=>2500,CURLOPT_TIMEOUT_MS=>6000,CURLOPT_RESOLVE=>[$host.':443:'.$resolved],CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json','Content-Type: application/json','Accept-Encoding: identity'],$headers),CURLOPT_WRITEFUNCTION=>static function($h,string $s)use(&$response):int{if(strlen($response)+strlen($s)>262144)return 0;$response.=$s;return strlen($s);}];
    if(defined('CURLOPT_PROTOCOLS_STR'))$options[CURLOPT_PROTOCOLS_STR]='https';else $options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
    if($body!==null){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=$body;}
    curl_setopt_array($ch,$options);if(curl_exec($ch)!==false)return ['status'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'body'=>$response];
   }finally{unset($ch);}
  }
  throw new RemoteError('temporary_network_failure','Central service could not be reached.',503);
 }
}
