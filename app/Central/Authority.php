<?php
declare(strict_types=1);
namespace FavoriteCMS\Central;
/** Official distribution authority. Site settings and environment cannot replace it. */
final class Authority {
 public const ORIGIN='https://favoriteweb.net';
 public const ENDPOINT=self::ORIGIN.'/favorite-central-api';
 public const PUBLISHER_PUBLIC='vLAT9ZGSIoqUt0XRndW87gY2J0vP9ew/piPKBWywo64=';
 public static function endpoint(string $url):bool{return rtrim($url,'/')===self::ENDPOINT;}
 /** Verify the publisher's delegated server key independently of editable connection/cache files. */
 public static function delegatedKey(string $keyId,array $certificate,array $transitions=[],?int $at=null):string{
  if(!Crypto::verify($certificate,self::PUBLISHER_PUBLIC))throw new RemoteError('authority_certificate_required','The official server authority certificate is missing or invalid.',403);
  $p=(array)($certificate['payload']??[]);$at??=time();
  if(($p['origin']??'')!==self::ORIGIN||($p['purpose']??'')!=='favorite-central-authority-1'||(int)($p['not_before']??PHP_INT_MAX)>$at+30||(int)($p['expires_at']??0)<=$at)throw new RemoteError('authority_certificate_required','The official server authority certificate needs renewal.',403);
  $trusted=(string)($p['server_public_key']??'');$id=(string)($p['server_key_id']??'');
  if(strlen((string)base64_decode($trusted,true))!==32)throw new RemoteError('untrusted_authority','Invalid authority key.',403);
  if($keyId===$id)return $trusted;
  foreach($transitions as$t){if(!is_array($t)||!Crypto::verify($t,$trusted))continue;$q=(array)($t['payload']??[]);if(($q['previous_key_id']??'')!==$id)continue;$next=(string)($q['new_public_key']??'');if(strlen((string)base64_decode($next,true))!==32)continue;$trusted=$next;$id=(string)($q['new_key_id']??'');if($keyId===$id)return $trusted;}
  throw new RemoteError('untrusted_authority','The response key is not delegated by the publisher.',403);
 }
}
