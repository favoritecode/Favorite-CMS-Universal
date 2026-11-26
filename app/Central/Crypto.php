<?php
declare(strict_types=1);
namespace FavoriteCMS\Central;
final class Crypto {
 static function canonical(array $data):string{
  $sort=static function(mixed $v)use(&$sort):mixed{if($v instanceof \stdClass)$v=(array)$v;if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as$k=>$x)$v[$k]=$sort($x);return $v;};
  return json_encode($sort($data),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 }
 static function envelope(array $data,array $config):array{return ['payload'=>$data,'key_id'=>$config['signing_key_id'],'signature'=>base64_encode(sodium_crypto_sign_detached(self::canonical($data),base64_decode($config['signing_secret'],true)))];}
 static function verify(array $envelope,string $publicKey):bool{
  try{$sig=base64_decode((string)($envelope['signature']??''),true);$key=base64_decode($publicKey,true);return is_array($envelope['payload']??null)&&is_string($sig)&&strlen($sig)===SODIUM_CRYPTO_SIGN_BYTES&&is_string($key)&&strlen($key)===SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES&&sodium_crypto_sign_verify_detached($sig,self::canonical($envelope['payload']),$key);}catch(\Throwable){return false;}
 }
 static function seal(string $value,string $key):string{$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);return base64_encode($nonce.sodium_crypto_secretbox($value,$nonce,base64_decode($key,true)));}
 static function open(string $value,string $key):string{
  $bytes=base64_decode($value,true);if($bytes===false||strlen($bytes)<SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new \RuntimeException('Protected configuration is invalid.');
  $plain=sodium_crypto_secretbox_open(substr($bytes,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($bytes,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),base64_decode($key,true));
  if($plain===false)throw new \RuntimeException('Protected configuration cannot be read.');return $plain;
 }
 static function message(int $timestamp,string $nonce,string $body):string{return "FCP1\nPOST\n".$timestamp."\n".$nonce."\n".$body;}
}
