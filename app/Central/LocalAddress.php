<?php
declare(strict_types=1);
namespace FavoriteCMS\Central;
/** Classifies private development addresses only; never a public ownership proof. */
final class LocalAddress {
 static function canonical(string $url):?string{
  $p=parse_url(trim($url));if(!is_array($p))return null;$host=strtolower(trim((string)($p['host']??''),'[]'));
  $local=$host==='localhost'||str_ends_with($host,'.localhost')||str_ends_with($host,'.local')||str_ends_with($host,'.test')||$host==='::1';
  if(filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){$b=array_values(unpack('C4',inet_pton($host)));$local=$b[0]===127||$b[0]===10||$b[0]===192&&$b[1]===168||$b[0]===172&&$b[1]>=16&&$b[1]<=31;}
  if(filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)){$b=inet_pton($host);$local=$host==='::1'||(ord($b[0])&254)===252;}
  if(!$local)return null;
  $scheme=strtolower((string)($p['scheme']??''));$path=rtrim((string)($p['path']??''),'/');$port=(int)($p['port']??($scheme==='https'?443:80));
  if(strlen($url)>480||!in_array($scheme,['http','https'],true)||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||$port<1||$port>65535||preg_match('/[\x00-\x20\x7f\\\\]/',$url)||str_contains(rawurldecode($path),'..'))throw new \InvalidArgumentException('Use a valid private development site URL.');
  return $scheme.'://'.(str_contains($host,':')?'['.$host.']':$host).(($scheme==='https'&&$port===443||$scheme==='http'&&$port===80)?'':':'.$port).$path;
 }
}
