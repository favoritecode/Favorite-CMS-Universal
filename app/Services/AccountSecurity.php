<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
use FavoriteCMS\Core\{Container,Database};
use FavoriteCMS\Models\User;

/** Optional two-step verification. No authenticated identity is issued before verification. */
final class AccountSecurity
{
 public static function db():Database {return Container::getInstance()->get(Database::class);}
 public static function state(int $id):object {
  return self::db()->selectOne('SELECT * FROM `account_security` WHERE user_id=?',[$id])??(object)['user_id'=>$id,'method'=>'off','revision'=>0,'preferences'=>null];
 }
 public static function ensure(int $id):void {
  if(self::db()->selectOne('SELECT user_id FROM `account_security` WHERE user_id=?',[$id]))return;
  try{self::db()->insert('account_security',['user_id'=>$id]);}catch(\Throwable $e){if(!self::db()->selectOne('SELECT user_id FROM `account_security` WHERE user_id=?',[$id]))throw $e;}
 }
 private static function key():string {
  $key=(string)env('APP_KEY','');
  if(str_starts_with($key,'base64:'))$key=base64_decode(substr($key,7),true)?:'';
  if(strlen($key)<32)throw new \RuntimeException('A valid APP_KEY (32 bytes or longer) is required for authenticator protection.');
  return hash('sha256',$key,true);
 }
 public static function encrypt(string $secret):string {
  $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($secret,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,'favorite-cms-2fa');
  if($cipher===false)throw new \RuntimeException('Authenticator encryption is unavailable.');
  return base64_encode($iv.$tag.$cipher);
 }
 public static function decrypt(string $value):string {
  $raw=base64_decode($value,true);if($raw===false||strlen($raw)<29)throw new \RuntimeException('Invalid authenticator secret.');
  $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'favorite-cms-2fa');
  if($plain===false)throw new \RuntimeException('Authenticator key could not be opened. Restore the original APP_KEY or use a recovery code.');return $plain;
 }
 public static function secret():string {
  $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$out='';foreach(str_split(random_bytes(20)) as $byte){$bits=ord($byte);for($i=7;$i>=0;$i--)$out.=($bits>>$i)&1;}
  return implode('',array_map(static fn($chunk)=>$alphabet[bindec($chunk)],str_split($out,5)));
 }
 public static function totp(string $secret,int $step,int $digits=6):string {
  $bits='';$alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';foreach(str_split(strtoupper($secret)) as $c){$n=strpos($alphabet,$c);if($n===false)throw new \InvalidArgumentException('Invalid base32 secret.');$bits.=str_pad(decbin($n),5,'0',STR_PAD_LEFT);}
  $key='';foreach(str_split($bits,8) as $b)if(strlen($b)===8)$key.=chr(bindec($b));
  $hash=hash_hmac('sha1',pack('N2',intdiv($step,4294967296),$step%4294967296),$key,true);$offset=ord($hash[19])&15;
  $n=unpack('N',substr($hash,$offset,4))[1]&0x7fffffff;return str_pad((string)($n%(10**$digits)),$digits,'0',STR_PAD_LEFT);
 }
 public static function matchingStep(string $secret,string $code,int $last=-1,?int $now=null):?int {
  if(!preg_match('/^\d{6}$/D',$code))return null;$step=intdiv($now??time(),30);
  foreach([$step,$step-1,$step+1] as $candidate)if($candidate>$last&&hash_equals(self::totp($secret,$candidate),$code))return $candidate;return null;
 }
 public static function begin(User $user,string $purpose='login',string $method=''):void {
  $state=self::state((int)$user->id);$method=$method?:$state->method;
  if(!in_array($method,['email','app'],true))throw new \RuntimeException('Choose a verification method.');
  $challenge=['user_id'=>(int)$user->id,'purpose'=>$purpose,'method'=>$method,'expires'=>time()+600,'tries'=>0,'version'=>(int)$user->auth_version,'revision'=>(int)$state->revision,'email'=>(string)$user->email,'sent_at'=>0];
  if($method==='app'&&$purpose==='setup')$challenge['secret']=self::encrypt(self::secret());
  $_SESSION['two_step']=$challenge;
  if($method==='email')self::sendCode($user);
 }
 public static function sendCode(User $user):void {
  $c=$_SESSION['two_step']??[];
  if(($c['user_id']??0)!==(int)$user->id||($c['method']??'')!=='email'||($c['expires']??0)<time())throw new \RuntimeException('Verification expired. Start again.');
  if(($c['sent_at']??0)>time()-60)throw new \RuntimeException('Please wait 60 seconds before requesting another code.');
  if(!(new AuthRateLimiter())->allow('2fa-email:'.$user->id,5,900))throw new \RuntimeException('Email code limit reached. Try again in 15 minutes.');
  $code=(string)random_int(100000,999999);$salt=bin2hex(random_bytes(16));
  // Invalidate the previous code even when delivery fails; never accept an unsent code.
  unset($_SESSION['two_step']['code_hash']);$_SESSION['two_step']['sent_at']=time();
  $minutes=max(1,(int)ceil(($c['expires']-time())/60));
  if(!MailService::send((string)$user->email,'Your sign-in verification code',"Your Favorite CMS verification code is: {$code}\n\nThis verification request expires in {$minutes} minutes. Do not share this code. If you did not request it, ignore this email."))throw new \RuntimeException('The verification email could not be sent. Retry later or use a recovery code.');
  $_SESSION['two_step']['salt']=$salt;$_SESSION['two_step']['code_hash']=hash('sha256',$salt.$code);
 }
 /** Returns a verified user; consumes recovery codes and TOTP counters atomically. */
 public static function verify(string $code,string $purpose='login'):?User {
  $c=$_SESSION['two_step']??[];$id=(int)($c['user_id']??0);
  if(!$id||($c['purpose']??'')!==$purpose||($c['expires']??0)<time()||($c['tries']??0)>=6){unset($_SESSION['two_step']);return null;}
  $_SESSION['two_step']['tries']++;
  if(!(new AuthRateLimiter())->allow('2fa-verify:'.$id,15,900))return null;
  $user=User::find($id);$state=self::state($id);
  if(!$user||!in_array($user->status,['active','suspended'],true)||(int)$user->auth_version!==$c['version']||(int)$state->revision!==$c['revision']||(string)$user->email!==$c['email']){unset($_SESSION['two_step']);return null;}
  $code=trim($code);$valid=false;
  if($purpose!=='setup'&&preg_match('/^[a-f0-9]{16}$/Di',str_replace('-','',$code))){
   $hash=hash('sha256',strtolower(str_replace('-','',$code)));$old=(string)($state->recovery??'');$hashes=json_decode($old,true)?:[];
   if(in_array($hash,$hashes,true)){$hashes=array_values(array_diff($hashes,[$hash]));$valid=self::db()->query('UPDATE `account_security` SET recovery=? WHERE user_id=? AND recovery=?',[json_encode($hashes),$id,$old])->rowCount()===1;}
  }elseif($c['method']==='email'){
   $valid=isset($c['code_hash'],$c['salt'])&&hash_equals($c['code_hash'],hash('sha256',$c['salt'].$code));
  }else{
   $secret=self::decrypt((string)($purpose==='setup'?$c['secret']:$state->secret));
   $step=self::matchingStep($secret,$code,$purpose==='setup'?-1:(int)$state->last_step);
   if($step!==null){$valid=$purpose==='setup'||self::db()->query('UPDATE `account_security` SET last_step=? WHERE user_id=? AND last_step=? AND revision=?',[$step,$id,(int)$state->last_step,(int)$state->revision])->rowCount()===1;$_SESSION['two_step']['verified_step']=$step;}
  }
  if($valid)$_SESSION['two_step']['verified']=true;
  return $valid?$user:null;
 }
 public static function enable(User $user):array {
  $c=$_SESSION['two_step']??[];if(($c['expires']??0)<time()||empty($c['verified'])||($c['purpose']??'')!=='setup'||($c['user_id']??0)!==(int)$user->id)throw new \RuntimeException('Start verification first.');
  self::ensure((int)$user->id);$codes=self::codes();
  self::db()->update('account_security',['method'=>$c['method'],'secret'=>$c['secret']??null,'last_step'=>$c['verified_step']??-1,'recovery'=>json_encode(array_map(static fn($s)=>hash('sha256',str_replace('-','',$s)),$codes)),'revision'=>(int)$c['revision']+1,'enabled_at'=>gmdate('Y-m-d H:i:s')],['user_id'=>(int)$user->id]);
  self::invalidateOtherSessions($user);unset($_SESSION['two_step']);return $codes;
 }
 public static function disable(User $user):void {
  $s=self::state((int)$user->id);self::db()->update('account_security',['method'=>'off','secret'=>null,'recovery'=>null,'last_step'=>-1,'revision'=>(int)$s->revision+1],['user_id'=>(int)$user->id]);self::invalidateOtherSessions($user);unset($_SESSION['two_step']);
 }
 public static function invalidateOtherSessions(User $user):void {
  self::db()->query('UPDATE `users` SET auth_version=auth_version+1 WHERE id=?',[(int)$user->id]);$fresh=User::find((int)$user->id);$_SESSION['auth_version']=(int)$fresh->auth_version;
  $factor=self::state((int)$user->id);if($factor->method!=='off')$_SESSION['auth_two_step']=$user->id.':'.$factor->revision;else unset($_SESSION['auth_two_step']);
  (new \FavoriteCMS\Installer\InstallerSession(new \FavoriteCMS\Installer\UrlResolver()))->regenerate();
 }
 public static function codes():array {return array_map(static fn()=>implode('-',str_split(bin2hex(random_bytes(8)),4)),range(1,8));}
 public static function preferences(int $id):array {
  return array_replace(['security'=>true,'purchases'=>true,'posts'=>false,'products'=>false,'movies'=>false,'apps'=>false,'updates'=>false],json_decode((string)(self::state($id)->preferences??''),true)?:[]);
 }
}
