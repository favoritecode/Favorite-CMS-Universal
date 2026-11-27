<?php
declare(strict_types=1);
namespace FavoriteCMS\Central;
final class Client {
 private string $dir;
 private string $root;
 public static function missingRequirements():array{
  $missing=[];
  foreach(['sodium_crypto_sign_keypair','sodium_crypto_sign_detached','sodium_crypto_sign_verify_detached','sodium_crypto_sign_publickey','sodium_crypto_sign_secretkey']as$f)if(!is_callable($f)){$missing[]='sodium';break;}
  if(!is_callable('curl_init'))$missing[]='curl';return $missing;
 }
 public function __construct(?string $root=null){$this->root=rtrim($root??APP_ROOT,'/\\');$this->dir=$this->root.'/storage/favorite-central-client';}
 public function config():array{
  $path=$this->dir.'/client.json';if(!is_file($path))return [];
  try{$v=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(\Throwable){return [];}
 }
 public function save(array $config):void{
  if(!is_dir($this->dir)&&!mkdir($this->dir,0700,true))throw new \RuntimeException('Central connection storage is not writable.');
  $base=realpath($this->root);$storage=realpath($this->dir);
  $boundary=str_replace('\\','/',(string)$base);$resolved=str_replace('\\','/',(string)$storage);
  if(DIRECTORY_SEPARATOR==='\\'){$boundary=strtolower($boundary);$resolved=strtolower($resolved);}
  if(!$base||!$storage||!str_starts_with($resolved.'/',$boundary.'/'))throw new RemoteError('shared_storage_path','Connection storage must belong to this installation root.',409);
  $raw=json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$temp=$this->dir.'/'.bin2hex(random_bytes(12)).'.tmp';
  if(file_put_contents($temp,$raw,LOCK_EX)!==strlen($raw))throw new \RuntimeException('Central connection could not be saved.');
  @chmod($temp,0600);if(!rename($temp,$this->dir.'/client.json')){unlink($temp);throw new \RuntimeException('Central connection could not be activated.');}
 }
 public static function localServerPending():bool{
  if(!is_file(APP_ROOT.'/favorite-server-license/bootstrap.php'))return false;
  require_once APP_ROOT.'/favorite-server-license/bootstrap.php';
  try{$private=(new \FavoriteCentral\Vault(APP_ROOT))->read();return empty($private['installed']);}
  catch(\Throwable){return true;} // Keep content/admin recoverable; setup reports the damaged private configuration.
 }
 /**
  * Register a newly installed or upgraded public CMS automatically.
  * Incomplete enrollments are retried without changing the persisted installation ID.
  * A failed attempt is diagnostic only; never discard old credentials or site data.
  */
 public function autoConnect(bool $force=false):void{
  if(!is_dir($this->dir)&&!@mkdir($this->dir,0700,true))return;
  $lock=@fopen($this->dir.'/auto.lock','c');if(!$lock)return;
  if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return;}
  try{$this->autoConnectLocked($force);}finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 private function autoConnectLocked(bool $force=false):void{
  $c=$this->config();
  if(LocalAddress::canonical((string)config('app.url'))!==null){(new LocalEnrollment($this,$this->root))->sync($force);return;}
  if(($c['installation_kind']??'')==='local'){
   if(!empty($c['public_unverified'])){if(!empty($c['secret']))$this->maintainUnverified($c,$force);else(new LocalEnrollment($this,$this->root))->syncPublic($force);return;}
   if(!empty($c['secret'])){try{$this->transfer();$c=$this->config();}catch(\Throwable $e){$c['registry_last_error']=substr(strip_tags($e->getMessage()),0,240);$this->save($c);return;}}
   else{$this->save(['previous_local_id'=>$c['installation_id']??'']);$c=$this->config();}
  }
  if(!empty($c['secret'])){
   try{
    $canonical=Transport::site((string)config('app.url'));
    $bound=empty($c['site_url'])?'':Transport::site((string)$c['site_url']);
    if($bound!==$canonical){
     if(!$force&&(int)($c['identity_retry_after']??0)>time())return;
     $c['identity_retry_after']=time()+300;$this->save($c);
     try{$this->connect(Authority::ORIGIN,true);}
     catch(\Throwable $e){$fresh=$this->config();$fresh['registry_check_after']=time()+300;$fresh['registry_last_error']=substr(strip_tags($e->getMessage()),0,240);$fresh['registry_error_code']=$e instanceof RemoteError?$e->errorCode:'verification_failure';$this->save($fresh);}
     return;
    }
   }catch(\Throwable $e){$this->logDiagnostic('warning','Canonical site URL is invalid',['reason'=>$e->getMessage()]);return;}
   // Normalize legacy trailing-slash server_url (avoids HTTP 301 on front-controller routes).
   $normalizedUrl=rtrim((string)($c['server_url']??''),'/');
   if($normalizedUrl!==''&&$normalizedUrl!==($c['server_url']??'')){
    $c['server_url']=$normalizedUrl;
    try{$this->save($c);}catch(\Throwable){}
   }
   $this->backupInstallationId((string)($c['installation_id']??''));
   // Upgraded legacy sites may carry a valid-looking local connection file
   // even when their installation record is missing from the Central registry.
   // Check once after upgrading and periodically thereafter. Never reset a
   // connection on an outage, invalid signature, suspension or other failure.
   if($force||(int)($c['registry_check_after']??0)<=time()){
    $c['registry_check_after']=time()+21600;
    try{$this->save($c);}catch(\Throwable){}
    try{
     $this->request(['action'=>'status']);
     $fresh=$this->config();unset($fresh['registry_last_error'],$fresh['registration_failures']);
     $fresh['registry_verified_at']=time();$this->save($fresh);
    }catch(\Throwable $e){
     $this->logDiagnostic('warning','Central registry status check failed',['installation_id'=>$c['installation_id']??'','reason'=>$e->getMessage()]);
     if($e instanceof RemoteError&&in_array($e->errorCode,['unknown_installation','authentication_failure','domain_mismatch'],true)||in_array($e->getMessage(),['Central installation is not registered.','Central installation credentials were rejected.'],true)){
      try{
       // Re-register the SAME ID at the SAME canonical site with fresh
       // ownership proof; keep old credentials if any step fails.
       $this->connect(Authority::ORIGIN,true);
       $fresh=$this->config();unset($fresh['registry_last_error'],$fresh['registration_failures']);
       $fresh['registry_verified_at']=time();$this->save($fresh);
       $this->logDiagnostic('info','Central installation recovered and re-verified',['installation_id'=>$fresh['installation_id']??'']);
      }catch(\Throwable $recovery){
       $fresh=$this->config();$fresh['registry_last_error']=substr(strip_tags($recovery->getMessage()),0,240);
       $fresh['registry_check_after']=time()+300;
       try{$this->save($fresh);}catch(\Throwable){}
       $this->logDiagnostic('warning','Central installation recovery attempt failed',['installation_id'=>$fresh['installation_id']??'','reason'=>$recovery->getMessage()]);
      }
     }else{
      $fresh=$this->config();$fresh['registry_last_error']=substr(strip_tags($e->getMessage()),0,240);
      $fresh['registry_check_after']=time()+300;
      try{$this->save($fresh);}catch(\Throwable){}
     }
    }
   }
   return;
  }
  // A local owner/server instance is installed separately and must never
  // enroll as a remote customer site.
  if(is_file($this->root.'/favorite-server-license/bootstrap.php'))return;
  if(!$force&&(int)($c['next_attempt']??0)>time())return;
  $url=Authority::ORIGIN;
  if($url===''){
   $this->recordRegistrationFailure('Central server URL is missing.');
   return;
  }
  $c['next_attempt']=time()+120;
  $c['registration_last_attempt']=time();
  $this->save($c);
  try{
   $this->connect($url);
   $connected=$this->config();
   unset($connected['registration_last_error'],$connected['next_attempt'],$connected['registration_failures']);
   $connected['registry_verified_at']=time();
   $this->save($connected);
   $this->logDiagnostic('info','Central automatic registration succeeded',['installation_id'=>$connected['installation_id']??'','site_url'=>$connected['site_url']??'']);
  }catch(\Throwable $e){
   $code=$e instanceof RemoteError?$e->errorCode:'verification_failure';
   $this->recordRegistrationFailure($e->getMessage(),$code);
   // Hosting blocks the domain proof (browser challenge, read-only public directory ...): connect automatically by key possession instead.
   if(!in_array($code,self::NO_UNVERIFIED_FALLBACK,true)){try{(new LocalEnrollment($this,$this->root))->syncPublic(true);}catch(\Throwable){}}
  }
 }
 /** Failures that are not a domain-proof problem: server/network trouble or a delegation problem that a key-possession registration cannot fix either. */
 private const NO_UNVERIFIED_FALLBACK=['temporary_network_failure','rate_limited','authority_certificate_required','untrusted_authority','verification_in_progress','suspended_license'];
 /** Public site connected without domain proof: keep outbound heartbeats and periodically try to upgrade to a verified domain. */
 private function maintainUnverified(array $c,bool $force):void{
  if($force||(int)($c['transfer_retry_after']??0)<=time()){
   $c['transfer_retry_after']=time()+21600;try{$this->save($c);}catch(\Throwable){}
   try{$this->transfer();return;}catch(\Throwable $e){$c=$this->config();$c['transfer_last_error']=substr(strip_tags($e->getMessage()),0,240);try{$this->save($c);}catch(\Throwable){}}
  }
  (new LocalEnrollment($this,$this->root))->syncPublic($force);
 }
 /** Non-secret diagnostic for the site owner's hosting support/administrator. */
 private function recordRegistrationFailure(string $reason,string $code='temporary_network_failure'):void{
  try{
   $c=$this->config();
   if(!empty($c['secret']))return;
   $failures=min(10,((int)($c['registration_failures']??0))+1);
   $delay=match(true){$failures<=2=>120,$failures<=5=>300,default=>900};
   $c['registration_failures']=$failures;$c['registration_error_code']=$code;
   $c['registration_last_attempt']=time();
   $safe=preg_replace('/[\x00-\x1f\x7f]/u',' ',strip_tags($reason))?:'Registration temporarily unavailable.';
   $c['registration_last_error']=function_exists('mb_substr')?mb_substr($safe,0,300):substr($safe,0,300);
   $c['next_attempt']=time()+$delay;
   $this->save($c);
   $this->logDiagnostic('warning','Central automatic registration deferred',['attempt'=>$failures,'retry_in_seconds'=>$delay,'reason'=>$c['registration_last_error']]);
  }catch(\Throwable){} // Never interrupt public requests or site management.
 }
 /** Structured non-secret logging; never logs secrets, tokens, or signatures. */
 private function logDiagnostic(string $level,string $message,array $context=[]):void{
  try{
   if(!class_exists(\FavoriteCMS\Core\Logger::class))return;
   $safe=[];
   foreach($context as $k=>$v){
    if(preg_match('/secret|token|password|signature|key/i',(string)$k))continue;
    $safe[$k]=is_scalar($v)||$v===null?$v:'[structured]';
   }
   if($level==='info')\FavoriteCMS\Core\Logger::info($message,$safe);
   else \FavoriteCMS\Core\Logger::warning($message,$safe);
  }catch(\Throwable){}
 }
 /** Persist a non-secret backup copy of installation_id in settings so DB migrations/reinstalls keep identity. */
 private function backupInstallationId(string $id):void{
  if(!preg_match('/^[a-f0-9]{32,64}$/D',$id))return;
  try{
   $key='id_'.hash('sha256',Transport::site((string)config('app.url')));
   $existing=(string)\FavoriteCMS\Models\Setting::get('central',$key,'');
   if($existing==='')\FavoriteCMS\Models\Setting::set('central',$key,$id);
  }catch(\Throwable){}
 }
 private function resolveInstallationId(array $old):string{
  if(!empty($old['installation_id'])&&preg_match('/^[a-f0-9]{32,64}$/D',(string)$old['installation_id'])){
   $this->backupInstallationId((string)$old['installation_id']);
   return (string)$old['installation_id'];
  }
  try{
   $scoped='id_'.hash('sha256',Transport::site((string)config('app.url')));
   $fromDb=(string)\FavoriteCMS\Models\Setting::get('central',$scoped,\FavoriteCMS\Models\Setting::get('central','installation_id',''));
   if($fromDb!==''&&preg_match('/^[a-f0-9]{32,64}$/D',$fromDb))return $fromDb;
  }catch(\Throwable){}
  $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$id=bin2hex($bytes);
  $this->backupInstallationId($id);
  return $id;
 }
 /** Clear cached retry timers after an official CMS update without altering identity or credentials. */
 public function resetRetryTimers():void{
  try{
   $c=$this->config();
   if(!$c)return;
   unset($c['next_attempt'],$c['registry_check_after'],$c['policy_next_attempt'],$c['identity_retry_after'],$c['policy_checked_at'],$c['policy_retry_after']);
   if(!empty($c['server_url']))$c['server_url']=rtrim((string)$c['server_url'],'/');
   $this->save($c);
  }catch(\Throwable){}
 }
 /** Verify that this exact installation still serves its registered public domain. */
 public function identityChallenge(string $challenge):array{
  if(!preg_match('/^[a-f0-9]{32,64}$/D',$challenge))return ['error'=>'invalid_challenge'];
  $c=$this->config();$id=(string)($c['installation_id']??'');$secret=(string)($c['secret']??'');
  if(!preg_match('/^[a-f0-9]{32,64}$/D',$id)||!preg_match('/^[a-f0-9]{64}$/D',$secret))return ['error'=>'unregistered'];
  $integrity=\FavoriteCMS\Security\CoreIntegrity::report($this->root);$status=(string)$integrity['status'];
  return ['installation_id'=>$id,'challenge'=>$challenge,'integrity_status'=>$status,'cms_version'=>defined('APP_VERSION')?APP_VERSION:'','proof'=>hash_hmac('sha256',$id.':'.$challenge.':'.$status,$secret),'protocol'=>1];
 }
 /** Authenticated notification for a clean CMS uninstall/retirement. */
 public function retire(string $reason='uninstalled'):array{
  $res=$this->request(['action'=>'retire','reason'=>mb_substr(trim(strip_tags($reason)),0,200)?:'uninstalled']);
  $this->logDiagnostic('info','Central installation retirement reported',['reason'=>$reason]);
  return $res;
 }
 public function transfer():void{
  $c=$this->config();if(empty($c['secret']))throw new \RuntimeException('Connect this installation first.');
  $site=Transport::site((string)config('app.url'));$id=$c['installation_id'];$proof=bin2hex(random_bytes(32));
  $path=$this->root.'/public/.well-known/favorite-central.json';if(is_file($path))throw new \RuntimeException('A site verification file already exists.');
  if(!is_dir(dirname($path))&&!mkdir(dirname($path),0755,true))throw new \RuntimeException('Verification directory is not writable.');
  $raw=Crypto::canonical(['installation_id'=>$id,'site_url'=>$site,'proof'=>$proof,'timestamp'=>time()]);
  $h=fopen($path,'x');if(!$h)throw new \RuntimeException('Ownership proof cannot be created.');fwrite($h,$raw);fclose($h);
  try{$this->request(['action'=>'transfer','site_url'=>$site,'proof'=>$proof]);$fresh=$this->config();$fresh['site_url']=$site;$fresh['installation_kind']='public';unset($fresh['local_url'],$fresh['local_root'],$fresh['local_private'],$fresh['local_public'],$fresh['policy'],$fresh['public_unverified'],$fresh['transfer_retry_after'],$fresh['transfer_last_error']);$this->save($fresh);}
  finally{if(is_file($path)&&hash_equals($raw,(string)file_get_contents($path)))unlink($path);}
 }
 public function connect(string $server,bool $recoverMissing=false):void{
  if(!is_dir($this->dir)&&!mkdir($this->dir,0700,true))throw new \RuntimeException('Central connection storage is not writable.');
  $lock=fopen($this->dir.'/connect.lock','c');if(!$lock)throw new \RuntimeException('Connection lock is unavailable.');
  if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new \RuntimeException('Another connection attempt is already running. Please wait.');}
  try{$this->enroll($server,$recoverMissing);}finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 private function enroll(string $server,bool $recoverMissing=false):void{
  $base=Transport::site($server);if($base!==Authority::ORIGIN)throw new RemoteError('untrusted_authority','Only the official Favorite Central service can authorize this site.',403);$old=$this->config();
  if(!empty($old['secret'])){
   if(!$recoverMissing)throw new \RuntimeException('This installation is already connected. Use a verified transfer or recover its existing credentials.');
   // A recovery can only target the originally bound Central service and
   // domain. Moving a domain uses the separate authenticated transfer flow.
   if(!empty($old['server_url'])&&rtrim((string)$old['server_url'],'/')!==$base.'/favorite-central-api')
    throw new \RuntimeException('Existing site identity differs; verified domain transfer is required.');
  }
  $site=Transport::site((string)config('app.url'));
  if($missing=self::missingRequirements())throw new \RuntimeException('Connection is unavailable because this hosting has disabled PHP '.implode(' and ',$missing).'. Enable the listed extensions in your hosting PHP settings, then retry. Your password was not the problem.');
  $key=sodium_crypto_sign_keypair();$id=$this->resolveInstallationId($old);$proof=bin2hex(random_bytes(32));
  $public=base64_encode(sodium_crypto_sign_publickey($key));$timestamp=time();$nonce=bin2hex(random_bytes(16));
  $data=['installation_id'=>$id,'site_url'=>$site,'proof'=>$proof,'public_key'=>$public,'timestamp'=>$timestamp,'nonce'=>$nonce];
  $apiUrl=$base.'/favorite-central-api';
  $this->save(array_merge($old,['installation_id'=>$id],empty($old['secret'])?['site_url'=>$site,'server_url'=>$apiUrl]:[]));
  $challenge=$this->root.'/public/.well-known/favorite-central.json';$dir=dirname($challenge);
  if(is_file($challenge)){
   $pending=json_decode((string)file_get_contents($challenge),true);
   if(is_array($pending)&&($pending['installation_id']??'')===$id&&($pending['timestamp']??time())<time()-600&&!is_link($challenge))unlink($challenge);
   else throw new RemoteError('verification_in_progress','A site verification is still pending. It will retry automatically.',409);
  }
  if(!is_dir($dir)&&!mkdir($dir,0755,true))throw new \RuntimeException('Site verification directory is not writable.');
  $body=Crypto::canonical(['installation_id'=>$id,'site_url'=>$site,'proof'=>$proof,'public_key'=>$public,'timestamp'=>$timestamp]);
  $handle=fopen($challenge,'x');if(!$handle)throw new \RuntimeException('Cannot create site verification file.');
  fwrite($handle,$body);fclose($handle);
  $input=['action'=>'register','reconcile_identity'=>true]+$data+['registration_signature'=>base64_encode(sodium_crypto_sign_detached(Crypto::canonical($data),sodium_crypto_sign_secretkey($key)))];
  try{
   $result=$this->http($apiUrl,Crypto::canonical($input),[]);
   $delegated=Authority::delegatedKey((string)($result['key_id']??''),(array)($result['authority_certificate']??[]),(array)($result['key_transitions']??[]));
   if(!hash_equals($delegated,(string)($result['server_public_key']??'')))throw new RemoteError('untrusted_authority','Registration key differs from the publisher delegation.',403);
   $assigned=(string)($result['installation_id']??'');$binding=$result['registration_binding']??null;
   if($binding!==null){
    $trusted=!empty($old['public_key'])?(string)$old['public_key']:(string)($result['server_public_key']??'');
    // Recovery pins the previous server key; registration never trusts an arbitrary replacement key.
    $valid=Crypto::verify((array)$binding,$trusted);
    if(!$valid&&!empty($old['public_key'])){
     $keyId=(string)($old['key_id']??'');
     foreach((array)($result['key_transitions']??[])as$transition){if(!Crypto::verify($transition,$trusted))continue;$q=$transition['payload'];if(($q['previous_key_id']??'')!==$keyId)continue;$trusted=(string)($q['new_public_key']??'');$keyId=(string)($q['new_key_id']??'');}
     $valid=Crypto::verify((array)$binding,$trusted);
    }
    $b=(array)($binding['payload']??[]);
    if(!$valid||($b['requested_installation_id']??'')!==$id||($b['installation_id']??'')!==$assigned||($b['site_url']??'')!==$site||($b['registration_nonce']??'')!==$nonce||($b['proof_hash']??'')!==hash('sha256',$proof)||($b['credential_hash']??'')!==hash('sha256',(string)($result['installation_secret']??''))||($b['signing_key_id']??'')!==($result['key_id']??'')||abs(time()-(int)($b['issued_at']??0))>120||($result['server_public_key']??'')!==$trusted)throw new RemoteError('authentication_failure','Registration binding could not be verified.',403);
   }else{
    if($assigned!==$id||!empty($old['secret'])&&$site!==($old['site_url']??''))throw new RemoteError('identity_collision','Central Server needs the identity reconciliation update.',409);
    if(!empty($old['public_key'])&&!hash_equals((string)$old['public_key'],(string)($result['server_public_key']??'')))throw new RemoteError('authentication_failure','Legacy recovery cannot replace a pinned signing key.',403);
   }
   if(!preg_match('/^[a-f0-9]{32,64}$/D',$assigned)||!preg_match('/^[a-f0-9]{64}$/',(string)($result['installation_secret']??'')))throw new \RuntimeException('Central registration response is invalid.');
   $pub=base64_decode((string)($result['server_public_key']??''),true);
   if(!is_string($pub)||strlen($pub)!==32)throw new \RuntimeException('Central signing key is invalid.');
   $fresh=$this->config();
   $fresh=array_merge($fresh,['installation_id'=>$assigned,'site_url'=>$site,'server_url'=>$apiUrl,'secret'=>$result['installation_secret'],'key_id'=>$result['key_id'],'public_key'=>$result['server_public_key'],'connected_at'=>gmdate('c')]);
   unset($fresh['policy'],$fresh['policy_next_attempt'],$fresh['registry_last_error'],$fresh['registration_last_error'],$fresh['next_attempt'],$fresh['registration_error_code'],$fresh['registry_error_code']);
   if($assigned!==$id){$fresh['identity_separation']=['previous_id'=>$id,'site_url'=>$site,'at'=>gmdate('c')];unset($fresh['entitlements'],$fresh['plugin_next_attempt']);}
   $fresh['identity_binding']=['site_url'=>$site,'root_hash'=>hash('sha256',(string)realpath($this->root)."\0".php_uname('n'))];
   $fresh['registry_check_after']=time()+21600;$fresh['registration_verified_at']=time();
   try{\FavoriteCMS\Models\Setting::set('central','id_'.hash('sha256',$site),$assigned);\FavoriteCMS\Models\Setting::set('central','registered_'.hash('sha256',$site),'1');}catch(\Throwable){}
   $this->save($fresh);
   $this->request(['action'=>'status']);
  }finally{if(is_file($challenge)&&hash_equals($body,(string)file_get_contents($challenge)))unlink($challenge);}
 }
 public function request(array $input):array{
  $lock=null;$directory=false;
  if(($input['action']??'')==='status'){$lock=@fopen($this->dir.'/directory.lock','c');if(is_resource($lock))$directory=flock($lock,LOCK_EX|LOCK_NB);}
  try{return $this->authenticatedRequest($input,$directory);}finally{if(is_resource($lock)){if($directory)flock($lock,LOCK_UN);fclose($lock);}}
 }
 private function authenticatedRequest(array $input,bool $syncDirectory):array{
  $c=$this->config();if(empty($c['secret']))throw new \RuntimeException('Central server is not connected.');
  if(!extension_loaded('sodium'))throw new \RuntimeException('PHP sodium is required to verify central responses.');
  if(($input['action']??'')==='status'){
   $profile=['site_name'=>(string)\FavoriteCMS\Models\Setting::get('general','site_name',''),'version'=>defined('APP_VERSION')?APP_VERSION:'','php_version'=>PHP_VERSION];
   $user=current_user();if($user&&$user->isSuperAdmin()){$profile['owner_name']=(string)$user->username;$profile['owner_email']=(string)$user->email;}
   $integrity=\FavoriteCMS\Security\CoreIntegrity::report($this->root);
   $profile['integrity_status']=(string)$integrity['status'];
   $profile['integrity_manifest_sha256']=(string)($integrity['manifest_sha256']??'');
   $profile['integrity_file_count']=(int)($integrity['file_count']??0);
   $input['site_profile']=$profile;$input['required_policy_protocol']='signed-state-1';try{if($syncDirectory){$directory=AccountDirectory::chunk($c);if($directory!==null)$input['user_directory']=$directory;}}catch(\Throwable){}
  }
  $input['canonical_site_url']=($input['action']??'')==='transfer'?Transport::site((string)config('app.url')):$this->canonicalBinding($c);
  $nonce=bin2hex(random_bytes(16));$timestamp=time();$raw=Crypto::canonical($input);
  $auth=['installation_id'=>$c['installation_id'],'timestamp'=>$timestamp,'nonce'=>$nonce,'signature'=>hash_hmac('sha256',Crypto::message($timestamp,$nonce,$raw),$c['secret'])];
  // The private owner instance can use the same authenticated protocol locally.
  $endpoint=rtrim((string)$c['server_url'],'/');if(!Authority::endpoint($endpoint))throw new RemoteError('untrusted_authority','The stored service is not the official Favorite Central authority.',403);
  $local=$this->root.'/favorite-server-license/bootstrap.php';$serverBase=rtrim(preg_replace('~/favorite-central-api/?$~','',$endpoint),'/');
  if(is_file($local)&&rtrim((string)config('app.url'),'/')===$serverBase){
   require_once $local;$vault=new \FavoriteCentral\Vault($this->root);$private=$vault->read();
   if(!$private||empty($private['installed']))throw new \RuntimeException('Private central server is not installed.');
   // Status verification must not boot Pay: loading a licensed plugin itself
   // asks for site policy and otherwise recursively enters this same request.
   $pay=($input['action']??'')==='status'?null:\FavoriteCentral\Runtime::pay();
   $result=(new \FavoriteCentral\Api(\FavoriteCentral\Runtime::store(),$pay,$vault->directory()))->execute($input,$raw,$auth,'local-owner');
  }else $result=$this->http($endpoint,$raw,$auth);
  $delegated=Authority::delegatedKey((string)($result['key_id']??''),(array)($result['authority_certificate']??[]),(array)($result['key_transitions']??[]));
  if(!Crypto::verify($result,$delegated))throw new RemoteError('untrusted_authority','The official response signature is invalid.',403);
  if(($result['key_id']??'')!==$c['key_id']){
   foreach((array)($result['key_transitions']??[])as$transition){
    if(!Crypto::verify($transition,$c['public_key']))continue;$p=$transition['payload'];
    if(($p['previous_key_id']??'')!==$c['key_id'])continue;
    $new=$p['new_public_key']??$p['public_key']??'';$newId=$p['new_key_id']??'';
    if(strlen((string)base64_decode($new,true))!==32||$newId==='')continue;
    $c['public_keys'][$c['key_id']]=$c['public_key'];$c['public_key']=$new;$c['key_id']=$newId;
   }
  }
  if(!Crypto::verify($result,(string)$c['public_key'])||($result['key_id']??'')!==$c['key_id'])throw new \RuntimeException('Central response signature could not be verified.');
  $p=$result['payload'];
  if(($p['installation_id']??'')!==$c['installation_id']||($p['request_nonce']??'')!==$nonce||abs(time()-(int)($p['issued_at']??0))>120||strtotime(($p['valid_until']??'1970-01-01').' UTC')<time())throw new \RuntimeException('Central response identity or time is invalid.');
  // Merge only verified signing keys into the newest on-disk cache.
  $fresh=$this->config();
  $fresh['public_keys']=$c['public_keys']??[];$fresh['public_key']=$c['public_key'];$fresh['key_id']=$c['key_id'];$this->save($fresh);
  if(($input['action']??'')==='status'){
   $revision=(int)($p['data']['policy_revision']??0);$current=(int)($c['policy_revision']??0);
   if($revision<1||$revision<$current)throw new RemoteError('stale_policy','An older site action was rejected.',409);
   $fresh=$this->config();$fresh['policy_revision']=$revision;$fresh['policy_envelope']=$result;$fresh['registration_verified_at']=time();$this->save($fresh);try{\FavoriteCMS\Models\Setting::set('central','registered_'.hash('sha256',$this->canonicalBinding($fresh)),'1');}catch(\Throwable){}
  }
  if(($input['action']??'')==='status'&&isset($input['user_directory'])){
   $fresh=$this->config();$ack=(array)($p['data']['directory_ack']??[]);
   if(!empty($ack['reset']))unset($fresh['user_directory_cursor']);
   elseif(($cursor=AccountDirectory::acknowledge($input['user_directory'],$ack))!==null)$fresh['user_directory_cursor']=$cursor;
   $this->save($fresh);
  }
  return (array)$p['data'];
 }
 /** Explicit authenticated admin recovery; publisher delegation and exact-site proof remain mandatory. */
 public function recoverOfficialConnection():void{
  if(\FavoriteCMS\Security\CoreIntegrity::report($this->root)['status']!=='verified')throw new \RuntimeException('Restore the official signed files before repairing connection credentials.');
  $original=$this->config();$working=$original;$working['server_url']=Authority::ENDPOINT;
  unset($working['public_key'],$working['key_id'],$working['public_keys'],$working['registry_check_after'],$working['next_attempt'],$working['policy_retry_after']);
  try{
   $this->save($working);
   if(LocalAddress::canonical((string)config('app.url'))!==null){$working['local_rebind_pending']=true;$this->save($working);(new LocalEnrollment($this,$this->root))->sync(true);$fresh=$this->config();if(!empty($fresh['local_rebind_pending'])||empty($fresh['secret']))throw new \RuntimeException('The local installation key could not be reverified. Restore its original connection backup.');}
   elseif(!empty($working['public_unverified'])){$working['local_rebind_pending']=true;$this->save($working);(new LocalEnrollment($this,$this->root))->syncPublic(true);$fresh=$this->config();if(!empty($fresh['local_rebind_pending'])||empty($fresh['secret']))throw new \RuntimeException('The installation key could not be reverified. Restore its original connection backup.');}
   else $this->connect(Authority::ORIGIN,!empty($working['secret']));
   $this->policy(true);
  }catch(\Throwable $e){$this->save($original);throw $e;}
 }
 public function registrationRequest(array $input):array{return $this->http(Authority::ENDPOINT,Crypto::canonical($input),[]);}
 private function canonicalBinding(array $c):string{
  if(($c['installation_kind']??'')==='local'){
   $url=!empty($c['public_unverified'])?Transport::site((string)config('app.url')):LocalAddress::canonical((string)config('app.url'));
   if($url===null||($c['local_url']??'')!==$url||($c['local_root']??'')!==hash('sha256',(string)realpath($this->root)."\0".php_uname('n')))throw new RemoteError('domain_mismatch','Local installation identity belongs to another site root.',409);
   return 'local://'.(string)($c['installation_id']??'');
  }
  return Transport::site((string)config('app.url'));
 }
 private function http(string $url,string $body,array $auth):array{
  $headers=['Content-Type: application/json','Accept: application/json'];
  foreach($auth as$k=>$v)$headers[]='X-FCP-'.($k==='installation_id'?'Installation':str_replace('_','-',ucwords($k,'_'))).': '.$v;
  $response=Transport::request($url,$body,$headers);
  if(($response['status']??0)!==200){
   $status=(int)($response['status']??0);
   $decoded=json_decode((string)($response['body']??''),true);
   $reason=is_array($decoded)&&is_string($decoded['error']??null)?$decoded['error']:'';
   $error=is_array($decoded)?(string)($decoded['error_code']??''):'';
   if($error==='')$error=match(true){$status===403&&$reason==='Unknown installation.'=>'unknown_installation',$status===403&&$reason==='Request authentication failed.'=>'authentication_failure',$status===429=>'rate_limited',$status===408||$status>=500=>'temporary_network_failure',$status===401=>'authentication_failure',default=>'verification_failure'};
   $message=$error==='hosting_browser_challenge'?'Hosting requires a browser JavaScript/cookie challenge. Allow the public verification endpoints for server-to-server HTTPS requests.':($reason!==''?substr(strip_tags($reason),0,220):'Central request temporarily unavailable.');
   throw new RemoteError($error,$message,$status);

  }
  $data=json_decode($response['body'],true,32,JSON_THROW_ON_ERROR);if(!is_array($data))throw new \RuntimeException('Invalid central response.');
  if(isset($data['error']))throw new \RuntimeException((string)$data['error']);return $data;
 }
 public function policy(bool $force=false):array{
  $c=$this->config();
  if($this->isControlSite($c)){
   $managed=[];foreach($this->localServer('SELECT DISTINCT product_id,kind FROM fcp_catalog WHERE product_id IS NOT NULL')as$r)$managed[(string)$r->product_id]=(string)$r->kind;
   return ['cms_license'=>'free','license_type'=>'owner','license_status'=>'active','policy_status'=>'active','policy_reason'=>null,'owner_policy_status'=>'active','managed_products'=>$managed];
  }
  if(empty($c['secret'])||empty($c['installation_id'])){
   return $this->registrationPending($c);
  }
  if(!Authority::endpoint((string)($c['server_url']??''))){return empty($c['policy_envelope'])?$this->registrationPending($c,'The official service connection is being restored automatically.'):$this->connectionDenied('The official service connection must be restored.');}
  $cached=$this->verifiedPolicy($c);$now=time();
  if(!$force&&$cached!==null&&(int)($c['policy_checked_at']??0)>$now-30)return $cached;
  if(!$force&&(int)($c['policy_retry_after']??0)>$now)return $cached??(empty($c['policy_envelope'])?$this->registrationPending($c):$this->connectionDenied('The central service could not verify this site. Retrying shortly.'));
  try{
   $this->request(['action'=>'status']);$fresh=$this->config();$fresh['policy_checked_at']=$now;unset($fresh['policy_retry_after'],$fresh['policy_last_error'],$fresh['policy_error_code']);$this->save($fresh);
   $verified=$this->verifiedPolicy($fresh);
   if($verified===null)throw new RemoteError('invalid_lease','The server must provide a valid signed site permission.',403);
   return $verified;
  }catch(\Throwable $e){
   $fresh=$this->config();$fresh['policy_retry_after']=$now+5;$fresh['policy_last_error']=mb_substr(strip_tags($e->getMessage()),0,300);$fresh['policy_error_code']=$e instanceof RemoteError?$e->errorCode:'verification_failure';$this->save($fresh);
   // Once registered, preserve the last verified action during a service outage.
   if($cached!==null)return $cached;
   // A site that never received a publisher-signed decision keeps running; only a signed Owner action restricts it.
   if(empty($fresh['policy_envelope']))return $this->registrationPending($fresh);
   return $this->connectionDenied('The central service could not verify this site. The administrator can sign in to restore the connection.');
  }
 }
 /** An unverified site may run for a bounded grace period (hosting/network trouble); afterwards it is restricted until it connects. */
 private const PENDING_GRACE=259200;
 /** Earliest moment this installation was seen without a verified connection. Kept in the connection file AND the database, so deleting one does not restart the clock. */
 private function pendingSince(array $c):int{
  $file=(int)($c['pending_since']??0);$db=0;
  try{$db=(int)\FavoriteCMS\Models\Setting::get('central','pending_since','0');}catch(\Throwable){}
  $known=array_values(array_filter([$file,$db],static fn(int $t):bool=>$t>0));
  $since=$known?min($known):time();
  if($file!==$since){try{$c['pending_since']=$since;$this->save($c);}catch(\Throwable){}}
  if($db!==$since){try{\FavoriteCMS\Models\Setting::set('central','pending_since',(string)$since);}catch(\Throwable){}}
  return $since;
 }
 private function registrationPending(array $c,string $fallback=''):array{
  $reason=trim((string)($c['registration_last_error']??$c['policy_last_error']??''));
  $detail=$reason!==''?$reason:($fallback!==''?$fallback:'Registration is pending. Your website keeps running while the connection is retried automatically.');
  $left=is_file($this->root.'/favorite-server-license/bootstrap.php')?PHP_INT_MAX:$this->pendingSince($c)+self::PENDING_GRACE-time();
  $code=(string)($c['registration_error_code']??$c['policy_error_code']??'');
  if($left<=0)return ['cms_license'=>'free','policy_status'=>'connection_required','policy_reason'=>'Verified registration is required before this site can operate.','policy_detail'=>$detail,'policy_error_code'=>$code];
  return ['cms_license'=>'free','policy_status'=>'registration_pending','policy_reason'=>$detail,'policy_error_code'=>$code,'grace_seconds_left'=>$left];
 }
 /** Local, network-free status for the administrator interface. Never throws. */
 public function adminNotice():array{
  try{
   $c=$this->config();
   if(is_file($this->root.'/favorite-server-license/bootstrap.php'))return ['pending'=>false,'welcome'=>false];
   $registered=!empty($c['secret'])&&!empty($c['installation_id'])&&(!empty($c['registration_verified_at'])||!empty($c['connected_at'])||!empty($c['registry_verified_at'])||!empty($c['policy_envelope']));
   $since=max((int)($c['registration_verified_at']??0),(int)($c['registry_verified_at']??0),(int)(strtotime((string)($c['connected_at']??''))?:0));
   $reason=trim((string)($c['registration_last_error']??$c['registry_last_error']??$c['policy_last_error']??''));
   $left=$registered?0:$this->pendingSince($c)+self::PENDING_GRACE-time();$vp=$registered&&!empty($c['public_unverified'])?$this->verifiedPolicy($c):null;$vd=(int)($vp['verification_deadline']??0);
   return ['pending'=>!$registered,'reason'=>substr(strip_tags($reason),0,300),'code'=>(string)($c['registration_error_code']??$c['registry_error_code']??$c['policy_error_code']??''),'grace_hours_left'=>$registered?0:max(0,(int)ceil($left/3600)),'restricted'=>!$registered&&$left<=0,'unverified'=>$registered&&!empty($c['public_unverified'])&&!($vp!==null&&($vp['verification']??'')==='verified'),'verified_notice'=>$registered&&!empty($c['public_unverified'])&&$vp!==null&&($vp['verification']??'')==='verified'&&empty($c['verified_notice_shown_at']),'license_alerts'=>$this->licenseAlerts(),'verify_hours_left'=>($vp&&($vp['verification']??'')==='unverified'&&$vd>0)?max(0,(int)ceil(($vd-time())/3600)):null,'welcome'=>$registered&&empty($c['welcome_shown_at'])&&$since>time()-604800];
  }catch(\Throwable){return ['pending'=>false,'welcome'=>false];}
 }
 /** Turns an entitlement answer into something an administrator can read. managed=false: nothing to show (a free, unmanaged extension). */
 public static function describeLicense(array $e,string $kind='plugin'):array{
  $mode=(string)($e['mode']??'');$reason=(string)($e['reason']??'');$until=(string)($e['valid_until']??'');
  if($mode==='legacy_free')return ['managed'=>false];
  $ts=$until!==''?strtotime($until.' UTC'):false;$days=$ts!==false?max(0,(int)ceil(($ts-time())/86400)):null;
  $end=$ts!==false?(function_exists('format_date')?format_date($until,'M j, Y g:i a'):$until.' UTC'):'';
  $r=['managed'=>true,'blocked'=>empty($e['allowed']),'mode'=>$mode!==''?$mode:$reason,'days_left'=>$days,'ends'=>$end,'buy'=>false,'manage'=>false,'tone'=>'info','label'=>'','short'=>'','detail'=>''];
  if($mode==='owner'){$r['tone']='ok';$r['label']='Owner access';$r['detail']='The control site never needs a license.';}
  elseif($mode==='free'){$r['tone']='ok';$r['label']='Free';$r['detail']='No license needed.';}
  elseif($mode==='trial'){$r['tone']=($days!==null&&$days<=2)?'warn':'info';$r['buy']=true;$r['label']='Free trial';$r['short']='Free trial · '.($days!==null?$days.' day'.($days===1?'':'s').' left':'running');$r['detail']=($end!==''?'Ends '.$end.'. ':'').'Buy a license before it ends to keep using it.';}
  elseif($mode==='paid'){$r['tone']='ok';$r['manage']=true;$r['label']='Licensed';$r['detail']=$end!==''?'Valid until '.$end.'.':'Lifetime license.';}
  elseif($reason==='trial_not_started'){$r['buy']=true;$r['label']='Free trial available';$r['detail']='A free trial starts when you activate it. After the trial a license is required.';}
  elseif($reason==='purchase_required'){$r['tone']='bad';$r['buy']=true;$r['label']='License needed';$r['detail']='The free trial has ended. Buy a license to use this '.$kind.'.';}
  elseif($reason==='license_expired'){$r['tone']='bad';$r['buy']=true;$r['label']='License expired';$r['detail']='Renew the license to keep using this '.$kind.'.';}
  elseif($reason==='central_connection_required'){$r['tone']='warn';$r['label']='Connect to manage license';$r['detail']='This site is not connected to Favorite CMS Central yet.';}
  elseif($reason==='central_temporarily_unavailable'){$r['tone']='warn';$r['label']='License check unavailable';$r['detail']='The license server could not be reached; it will be checked again shortly.';}
  elseif($reason==='policy_suspension'){$r['tone']='bad';$r['label']='Restricted';$r['detail']='This site is restricted by the platform owner.';}
  else{$r['tone']='warn';$r['label']=ucfirst(str_replace('_',' ',$reason!==''?$reason:'License check'));$r['detail']='';}
  if($r['short']==='')$r['short']=$r['label'];
  return $r;
 }
 /** License state for one extension (starts the trial only when $active). */
 public function licenseState(array $manifest,bool $active,string $kind='plugin'):array{
  return self::describeLicense($kind==='theme'?$this->theme($manifest,false,$active):$this->plugin($manifest,false,$active),$kind);
 }
 /** Cached license states needing attention (no network): trials ending within 2 days and blocked extensions. */
 public function licenseAlerts():array{
  try{
   if($this->isControlSite())return [];
   $out=[];
   foreach((array)($this->config()['entitlements']??[])as$id=>$row){
    $id=(string)$id;$theme=str_starts_with($id,'theme--');
    if(!is_dir($this->root.($theme?'/themes/'.substr($id,7):'/plugins/'.$id)))continue;
    $s=self::describeLicense((array)($row['data']??[]),$theme?'theme':'plugin');if(empty($s['managed']))continue;
    if(($s['mode']==='trial'&&$s['days_left']!==null&&$s['days_left']<=2)||in_array($s['mode'],['purchase_required','license_expired'],true))$out[]=['id'=>$id,'theme'=>$theme,'label'=>$s['label'],'detail'=>$s['detail']];
   }
   return array_slice($out,0,4);
  }catch(\Throwable){return [];}
 }
 public function markVerifiedNoticeShown():void{
  try{$c=$this->config();if(empty($c['secret'])||!empty($c['verified_notice_shown_at']))return;$c['verified_notice_shown_at']=time();$this->save($c);}catch(\Throwable){}
 }
 public function markWelcomeShown():void{
  try{$c=$this->config();if(empty($c['secret'])||!empty($c['welcome_shown_at']))return;$c['welcome_shown_at']=time();$this->save($c);}catch(\Throwable){}
 }
 private function connectionDenied(string $reason):array{return ['cms_license'=>'free','policy_status'=>'connection_required','policy_reason'=>$reason];}
 private function verifiedPolicy(array $c):?array{
  try{
   $envelope=$c['policy_envelope']??null;if(!is_array($envelope))return null;$key=Authority::delegatedKey((string)($envelope['key_id']??''),(array)($envelope['authority_certificate']??[]),(array)($envelope['key_transitions']??[]),(int)($envelope['payload']['issued_at']??0));if(!Crypto::verify($envelope,$key))return null;
   $p=$envelope['payload'];$data=(array)($p['data']??[]);$issued=(int)($p['issued_at']??0);$until=strtotime((string)($p['valid_until']??'1970-01-01').' UTC');
   $site=$this->canonicalBinding($c);
   if(($p['installation_id']??'')!==($c['installation_id']??'')||($data['canonical_site_url']??'')!==$site||($data['policy_protocol']??'')!=='signed-state-1'||$issued>time()+30||$issued<=0||$until===false||$until<$issued||$until>$issued+120)return null;
   if((int)($data['policy_revision']??0)<max(1,(int)($c['policy_revision']??0)))return null;
   if(($data['policy_status']??'')==='integrity_failed'&&in_array($data['owner_policy_status']??'',['active','warning'],true)&&\FavoriteCMS\Security\CoreIntegrity::report($this->root)['status']==='verified'){$data['policy_status']=$data['owner_policy_status'];$data['policy_reason']=$data['owner_policy_reason']??null;$data['core_repaired_offline']=true;}
   // A domain-unverified site is limited to the signed verification window; this also holds while the control server is unreachable.
   if(($data['verification']??'')==='unverified'&&(int)($data['verification_deadline']??0)>0&&time()>(int)$data['verification_deadline']&&in_array($data['policy_status']??'',['active','warning'],true)){$data['policy_status']='verification_required';$data['policy_reason']='This website was not verified within 3 days. Install the latest official Favorite CMS update and contact the Favorite CMS owner to verify it.';}
   return $data;
  }catch(\Throwable){return null;}
 }
 /**
  * True only on the CONTROL site itself: the Server package is installed here, its private vault is bound to THIS root and
  * this connection is the Server's own owner installation. A copied folder or another installation never qualifies.
  */
 private function isControlSite(?array $c=null):bool{
  $c??=$this->config();$id=(string)($c['installation_id']??'');$key=$this->root.'|'.$id;
  if(isset(self::$controlSite[$key]))return self::$controlSite[$key];
  $ok=false;
  try{
   if($id!==''&&is_file($this->root.'/favorite-server-license/bootstrap.php')){
    require_once $this->root.'/favorite-server-license/bootstrap.php';
    $cfg=(new \FavoriteCentral\Vault($this->root))->read();
    $ok=is_array($cfg)&&!empty($cfg['installed'])&&hash_equals((string)$cfg['owner_installation_id'],$id)&&hash_equals((string)$cfg['cms_root_hash'],hash('sha256',(string)realpath($this->root)));
   }
  }catch(\Throwable){$ok=false;}
  return self::$controlSite[$key]=$ok;
 }
 private static array $controlSite=[];
 /** Read straight from the local Server database (no network); empty when it is not reachable. */
 private function localServer(string $sql,array $args=[]):array{
  try{return \FavoriteCentral\Runtime::store()->db->select($sql,$args);}catch(\Throwable){return [];}
 }
 public function plugin(array $manifest,bool $force=false,bool $initialize=true):array{
  if($this->isControlSite()){
   $row=$this->localServer('SELECT features FROM fcp_products WHERE id=?',[(string)($manifest['id']??'')]);
   return ['allowed'=>true,'mode'=>'owner','updates'=>true,'valid_until'=>null,'features'=>$row?(json_decode((string)$row[0]->features,true)?:[]):[]];
  }
  $supported=($manifest['licensing']['supported']??false)===true;$managed=isset($this->policy()['managed_products'][(string)($manifest['id']??'')]);
  if(!$supported&&!$managed)return ['allowed'=>true,'mode'=>'legacy_free'];
  if($supported&&(int)($manifest['licensing']['api_version']??0)!==1)return ['allowed'=>false,'reason'=>'unsupported_licensing_api'];
  $id=(string)($manifest['id']??'');if(!preg_match('/^[a-z0-9][a-z0-9_-]{1,99}$/',$id))return ['allowed'=>false,'reason'=>'invalid_product'];
  $c=$this->config();$cached=$c['entitlements'][$id]??[];$mayUseCache=!$initialize||!empty($cached['initialized']);
  if(!$force&&$mayUseCache&&(int)($cached['checked_at']??0)>time()-300)return $this->bounded((array)$cached['data']);
  if(empty($c['secret']))return ['allowed'=>false,'reason'=>'central_connection_required'];
  if(!$force&&$mayUseCache&&(int)($c['plugin_next_attempt'][$id]??0)>time())return $this->offline($cached);
  try{$c['plugin_next_attempt'][$id]=time()+300;$this->save($c);}catch(\Throwable){}
  try{
   if($supported)$this->request(['action'=>'register_plugin','manifest'=>['id'=>$id,'name'=>(string)($manifest['name']??$id),'licensing'=>$manifest['licensing']]]);
   $data=$this->request(['action'=>'entitlement','product_id'=>$id,'initialize'=>$initialize]);
   $c=$this->config();$c['entitlements'][$id]=['checked_at'=>time(),'data'=>$data,'initialized'=>$initialize];$this->save($c);return $this->bounded($data);
  }catch(\Throwable){
   return $this->offline($cached);
  }
 }
 public function theme(array $manifest,bool $force=false,bool $initialize=true):array{
  if(($manifest['id']??'default')==='default')return ['allowed'=>true,'mode'=>'free'];
  $manifest['id']='theme--'.($manifest['id']??'');return $this->plugin($manifest,$force,$initialize);
 }
 private function offline(array $cached):array{
  if((int)($cached['checked_at']??0)>time()-604800&&!empty($cached['data']['allowed']))return $this->bounded($cached['data']+['offline_grace'=>true]);
  return ['allowed'=>false,'reason'=>'central_temporarily_unavailable'];
 }
 private function bounded(array $data):array{
  if(!empty($data['allowed'])&&!empty($data['valid_until'])&&strtotime($data['valid_until'].' UTC')<=time())return ['allowed'=>false,'reason'=>'license_expired'];
  return $data;
 }
}
