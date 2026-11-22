<?php
declare(strict_types=1);
namespace FavoriteCMS\Central;
final class Client {
 private string $dir;
 public function __construct(?string $root=null){$this->dir=($root??APP_ROOT).'/storage/favorite-central-client';}
 public function config():array{
  $path=$this->dir.'/client.json';if(!is_file($path))return [];
  try{$v=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);return is_array($v)?$v:[];}catch(\Throwable){return [];}
 }
 public function save(array $config):void{
  if(!is_dir($this->dir)&&!mkdir($this->dir,0700,true))throw new \RuntimeException('Central connection storage is not writable.');
  $raw=json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$temp=$this->dir.'/'.bin2hex(random_bytes(12)).'.tmp';
  if(file_put_contents($temp,$raw,LOCK_EX)!==strlen($raw))throw new \RuntimeException('Central connection could not be saved.');
  @chmod($temp,0600);if(!rename($temp,$this->dir.'/client.json')){unlink($temp);throw new \RuntimeException('Central connection could not be activated.');}
 }
 public function autoConnect():void{
  $c=$this->config();if(!empty($c['secret'])||(int)($c['next_attempt']??0)>time())return;
  $url=trim((string)config('central.url',''));if($url==='')return;
  $user=current_user();if(!$user||!$user->isSuperAdmin())return;
  $c['next_attempt']=time()+3600;$this->save($c);
  try{$this->connect($url);}catch(\Throwable){/* Free CMS remains available while enrollment is retried. */}
 }
 public function transfer():void{
  $c=$this->config();if(empty($c['secret']))throw new \RuntimeException('Connect this installation first.');
  $site=Transport::site((string)config('app.url'));$id=$c['installation_id'];$proof=bin2hex(random_bytes(32));
  $path=APP_ROOT.'/public/.well-known/favorite-central.json';if(is_file($path))throw new \RuntimeException('A site verification file already exists.');
  if(!is_dir(dirname($path))&&!mkdir(dirname($path),0755,true))throw new \RuntimeException('Verification directory is not writable.');
  $raw=Crypto::canonical(['installation_id'=>$id,'site_url'=>$site,'proof'=>$proof,'timestamp'=>time()]);
  $h=fopen($path,'x');if(!$h)throw new \RuntimeException('Ownership proof cannot be created.');fwrite($h,$raw);fclose($h);
  try{$this->request(['action'=>'transfer','site_url'=>$site,'proof'=>$proof]);$fresh=$this->config();$fresh['site_url']=$site;unset($fresh['policy']);$this->save($fresh);}
  finally{if(is_file($path)&&hash_equals($raw,(string)file_get_contents($path)))unlink($path);}
 }
 public function connect(string $server):void{
  if(!is_dir($this->dir)&&!mkdir($this->dir,0700,true))throw new \RuntimeException('Central connection storage is not writable.');
  $lock=fopen($this->dir.'/connect.lock','c');if(!$lock)throw new \RuntimeException('Connection lock is unavailable.');
  if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new \RuntimeException('Another connection attempt is already running. Please wait.');}
  try{$this->enroll($server);}finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 private function enroll(string $server):void{
  $base=Transport::site($server);$old=$this->config();
  if(!empty($old['secret']))throw new \RuntimeException('This installation is already connected. Use a verified transfer or recover its existing credentials.');
  $site=Transport::site((string)config('app.url'));
  if(!extension_loaded('sodium')||!extension_loaded('curl'))throw new \RuntimeException('Central connection requires PHP sodium and curl.');
  $key=sodium_crypto_sign_keypair();$id=$old['installation_id']??bin2hex(random_bytes(16));$proof=bin2hex(random_bytes(32));
  $public=base64_encode(sodium_crypto_sign_publickey($key));$timestamp=time();$nonce=bin2hex(random_bytes(16));
  $data=['installation_id'=>$id,'site_url'=>$site,'proof'=>$proof,'public_key'=>$public,'timestamp'=>$timestamp,'nonce'=>$nonce];
  $this->save($old+['installation_id'=>$id,'site_url'=>$site,'server_url'=>$base.'/favorite-central-api/']);
  $challenge=APP_ROOT.'/public/.well-known/favorite-central.json';$dir=dirname($challenge);
  if(is_file($challenge))throw new \RuntimeException('A site verification file already exists. Finish or remove that pending verification first.');
  if(!is_dir($dir)&&!mkdir($dir,0755,true))throw new \RuntimeException('Site verification directory is not writable.');
  $body=Crypto::canonical(['installation_id'=>$id,'site_url'=>$site,'proof'=>$proof,'public_key'=>$public,'timestamp'=>$timestamp]);
  $handle=fopen($challenge,'x');if(!$handle)throw new \RuntimeException('Cannot create site verification file.');
  fwrite($handle,$body);fclose($handle);
  $input=['action'=>'register']+$data+['registration_signature'=>base64_encode(sodium_crypto_sign_detached(Crypto::canonical($data),sodium_crypto_sign_secretkey($key)))];
  try{
   $result=$this->http($base.'/favorite-central-api/',Crypto::canonical($input),[]);
   if(($result['installation_id']??'')!==$id||!preg_match('/^[a-f0-9]{64}$/',(string)($result['installation_secret']??'')))throw new \RuntimeException('Central registration response is invalid.');
   $pub=base64_decode((string)($result['server_public_key']??''),true);
   if(!is_string($pub)||strlen($pub)!==32)throw new \RuntimeException('Central signing key is invalid.');
   $this->save(['installation_id'=>$id,'site_url'=>$site,'server_url'=>$base.'/favorite-central-api/','secret'=>$result['installation_secret'],'key_id'=>$result['key_id'],'public_key'=>$result['server_public_key'],'connected_at'=>gmdate('c')]);
   $this->request(['action'=>'status']);
  }finally{if(is_file($challenge)&&hash_equals($body,(string)file_get_contents($challenge)))unlink($challenge);}
 }
 public function request(array $input):array{
  $c=$this->config();if(empty($c['secret']))throw new \RuntimeException('Central server is not connected.');
  if(!extension_loaded('sodium'))throw new \RuntimeException('PHP sodium is required to verify central responses.');
  $nonce=bin2hex(random_bytes(16));$timestamp=time();$raw=Crypto::canonical($input);
  $auth=['installation_id'=>$c['installation_id'],'timestamp'=>$timestamp,'nonce'=>$nonce,'signature'=>hash_hmac('sha256',Crypto::message($timestamp,$nonce,$raw),$c['secret'])];
  // The private owner instance can use the same authenticated protocol locally.
  $local=APP_ROOT.'/favorite-server-license/bootstrap.php';$serverBase=rtrim(preg_replace('~/favorite-central-api/?$~','',(string)$c['server_url']),'/');
  if(is_file($local)&&rtrim((string)config('app.url'),'/')===$serverBase){
   require_once $local;$vault=new \FavoriteCentral\Vault(APP_ROOT);$private=$vault->read();
   if(!$private||empty($private['installed']))throw new \RuntimeException('Private central server is not installed.');
   $result=(new \FavoriteCentral\Api(\FavoriteCentral\Runtime::store(),\FavoriteCentral\Runtime::pay(),$vault->directory()))->execute($input,$raw,$auth,'local-owner');
  }else $result=$this->http($c['server_url'],$raw,$auth);
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
  $fresh=$this->config();$fresh['public_keys']=$c['public_keys']??[];$fresh['public_key']=$c['public_key'];$fresh['key_id']=$c['key_id'];$this->save($fresh);
  return (array)$p['data'];
 }
 private function http(string $url,string $body,array $auth):array{
  $headers=['Content-Type: application/json','Accept: application/json'];
  foreach($auth as$k=>$v)$headers[]='X-FCP-'.($k==='installation_id'?'Installation':str_replace('_','-',ucwords($k,'_'))).': '.$v;
  $response=Transport::request($url,$body,$headers);
  if(($response['status']??0)!==200)throw new \RuntimeException('Central request could not complete. Check connection and server status.');
  $data=json_decode($response['body'],true,32,JSON_THROW_ON_ERROR);if(!is_array($data))throw new \RuntimeException('Invalid central response.');
  if(isset($data['error']))throw new \RuntimeException((string)$data['error']);return $data;
 }
 public function policy(bool $force=false):array{
  $c=$this->config();if(empty($c['secret']))return ['cms_license'=>'free','policy_status'=>'unconnected'];
  $cached=$c['policy']??[];if(!$force&&((int)($cached['checked_at']??0)>time()-300||(int)($c['policy_next_attempt']??0)>time()))return $cached['data']??['cms_license'=>'free','policy_status'=>'temporarily_unavailable'];
  try{$c['policy_next_attempt']=time()+300;$this->save($c);}catch(\Throwable){}
  try{$data=$this->request(['action'=>'status']);$c=$this->config();$c['policy']=['checked_at'=>time(),'data'=>$data];$this->save($c);return $data;}
  catch(\Throwable){return $cached['data']??['cms_license'=>'free','policy_status'=>'temporarily_unavailable'];}
 }
 public function plugin(array $manifest,bool $force=false,bool $initialize=true):array{
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
