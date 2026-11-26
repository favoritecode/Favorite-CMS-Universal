<?php
declare(strict_types=1);
namespace FavoriteCMS\Central;
/** Outbound key possession, not public-domain verification. Each root keeps its own key. */
final class LocalEnrollment {
 function __construct(private Client $client,private string $root){}
 function sync(bool $force=false):void{
  $c=$this->client->config();$url=LocalAddress::canonical((string)config('app.url'));if($url===null)return;
  $root=hash('sha256',(string)realpath($this->root)."\0".php_uname('n'));
  if(($c['installation_kind']??'')!=='local'||($c['local_root']??'')!==$root){
   $pair=sodium_crypto_sign_keypair();$uuid=random_bytes(16);$uuid[6]=chr((ord($uuid[6])&15)|64);$uuid[8]=chr((ord($uuid[8])&63)|128);$id=bin2hex($uuid);
   $c=['installation_kind'=>'local','installation_id'=>$id,'site_url'=>'local://'.$id,'local_url'=>$url,'local_root'=>$root,'server_url'=>Authority::ENDPOINT,'local_public'=>base64_encode(sodium_crypto_sign_publickey($pair)),'local_private'=>base64_encode(sodium_crypto_sign_secretkey($pair))];$this->client->save($c);
  }
  if(($c['local_url']??'')!==$url){$c['local_url']=$url;$c['local_rebind_pending']=true;$c['next_attempt']=0;$this->client->save($c);}
  if(!empty($c['secret'])&&empty($c['local_rebind_pending'])){
   if(!$force&&(int)($c['registry_check_after']??0)>time())return;
   try{$this->client->request(['action'=>'status']);$fresh=$this->client->config();$fresh['registry_check_after']=time()+300;unset($fresh['registry_last_error']);$this->client->save($fresh);}catch(\Throwable $e){$fresh=$this->client->config();$fresh['registry_check_after']=time()+120;$fresh['registry_last_error']=substr(strip_tags($e->getMessage()),0,240);$this->client->save($fresh);}return;
  }
  if(!$force&&(int)($c['next_attempt']??0)>time())return;
  $c['registration_last_attempt']=time();$c['next_attempt']=time()+120;$this->client->save($c);
  try{
   $nonce=bin2hex(random_bytes(16));$payload=['installation_id'=>$c['installation_id'],'local_url'=>$url,'public_key'=>$c['local_public'],'timestamp'=>time(),'nonce'=>$nonce,'purpose'=>'favorite-local-enrollment-1'];
   $secret=base64_decode((string)$c['local_private'],true);if(!is_string($secret)||strlen($secret)!==64)throw new RemoteError('authentication_failure','Local installation key needs recovery.',403);
   $signature=base64_encode(sodium_crypto_sign_detached(Crypto::canonical($payload),$secret));sodium_memzero($secret);
   $r=$this->client->registrationRequest(['action'=>'register_local','local_identity'=>$payload,'signature'=>$signature]);
   $key=Authority::delegatedKey((string)($r['key_id']??''),(array)($r['authority_certificate']??[]),(array)($r['key_transitions']??[]));$binding=(array)($r['local_binding']??[]);$b=(array)($binding['payload']??[]);
   if(!Crypto::verify($binding,$key)||($b['installation_id']??'')!==$c['installation_id']||($b['local_url']??'')!==$url||($b['request_nonce']??'')!==$nonce||($b['client_key_hash']??'')!==hash('sha256',$c['local_public'])||($b['credential_hash']??'')!==hash('sha256',(string)($r['installation_secret']??''))||abs(time()-(int)($b['issued_at']??0))>120||!preg_match('/^[a-f0-9]{64}$/D',(string)($r['installation_secret']??'')))throw new RemoteError('authentication_failure','Local registration binding is invalid.',403);
   $fresh=$this->client->config();$fresh=array_merge($fresh,['secret'=>$r['installation_secret'],'public_key'=>$key,'key_id'=>$r['key_id'],'connected_at'=>gmdate('c'),'registration_verified_at'=>time()]);unset($fresh['registration_last_error'],$fresh['registration_error_code'],$fresh['next_attempt'],$fresh['local_rebind_pending']);$this->client->save($fresh);$this->client->request(['action'=>'status']);
  }catch(\Throwable $e){$fresh=$this->client->config();$fresh['registration_error_code']=$e instanceof RemoteError?$e->errorCode:'verification_failure';$fresh['registration_last_error']=substr(strip_tags($e->getMessage()),0,240);$fresh['next_attempt']=time()+120;$this->client->save($fresh);}
 }
}
