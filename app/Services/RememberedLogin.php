<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
use FavoriteCMS\Models\User;

/** Optional password-step credential. Never grants or stores a two-factor proof. */
final class RememberedLogin
{
 public const COOKIE='fc_remember';
 public static function options(int $expires):array {
  $base=parse_url(app_url('/'));$path=rtrim((string)($base['path']??''),'/').'/';
  return ['expires'=>$expires,'path'=>$path,'secure'=>true,'httponly'=>true,'samesite'=>'Lax'];
 }
 public static function enabled():bool {return strtolower((string)parse_url(app_url('/'),PHP_URL_SCHEME))==='https';}
 private static function db():\FavoriteCMS\Core\Database {$db=AccountSecurity::db();$db->registerPrefixableTables(['remembered_logins']);return $db;}
 private static function fingerprint(User $user):string {
  $state=AccountSecurity::state((int)$user->id);
  return hash('sha256',json_encode([(string)$user->password,(int)$user->auth_version,(string)$user->email,(int)$state->revision,(string)$state->method]));
 }
 private static function cookie(string $value,int $expires):void {if(!headers_sent())setcookie(self::COOKIE,$value,self::options($expires));}
 public static function forget():void {
  try{$db=self::db();$selector=(string)($_SESSION['remember_selector']??'');$cookie=$_COOKIE[self::COOKIE]??'';if(!is_string($cookie))$cookie='';
   if(preg_match('/^([a-f0-9]{32}):[a-f0-9]{64}$/D',$cookie,$m))$db->delete('remembered_logins',['selector'=>$m[1]]);
   if(preg_match('/^[a-f0-9]{32}$/D',$selector))$db->delete('remembered_logins',['selector'=>$selector]);
  }catch(\Throwable $e){error_log('Remembered login cleanup: '.$e->getMessage());}
  unset($_SESSION['remember_selector'],$_SESSION['remember_choice'],$_SESSION['remember_expires']);unset($_COOKIE[self::COOKIE]);self::cookie('',time()-3600);
 }
 public static function candidate(bool $consume=false):?User {
  if(!self::enabled())return null;
  $cookie=$_COOKIE[self::COOKIE]??'';if(!is_string($cookie)||!preg_match('/^([a-f0-9]{32}):([a-f0-9]{64})$/D',$cookie,$m))return null;
  try{$db=self::db();$row=$db->selectOne('SELECT * FROM remembered_logins WHERE selector=?',[$m[1]]);
   if(!$row||!hash_equals($row->validator_hash,hash('sha256',$m[2]))||(int)$row->expires_at<=time())return null;
   $user=User::find((int)$row->user_id);
   if(!$user||!in_array($user->status,['active','suspended'],true)||!hash_equals($row->fingerprint,self::fingerprint($user))||(EmailVerificationService::isRequired()&&empty($user->email_verified_at)))return null;
   if($consume){
    // Atomic one-use credential: concurrent or replayed POST cannot reuse it.
    if(!$db->query('DELETE FROM remembered_logins WHERE selector=? AND validator_hash=?',[$m[1],$row->validator_hash])->rowCount())return null;
    $_SESSION['remember_choice']=true;$_SESSION['remember_expires']=(int)$row->expires_at;self::cookie('',time()-3600);unset($_COOKIE[self::COOKIE]);
   }
   return $user;
  }catch(\Throwable $e){error_log('Remembered login lookup: '.$e->getMessage());return null;}
 }
 public static function issue(User $user):void {
  if(!self::enabled())return;
  try{$db=self::db();$expires=min(time()+30*86400,(int)($_SESSION['remember_expires']??time()+30*86400));if($expires<=time())return;
   $selector=bin2hex(random_bytes(16));$validator=bin2hex(random_bytes(32));
   $db->query('DELETE FROM remembered_logins WHERE expires_at<=? OR (user_id=? AND fingerprint<>?)',[time(),(int)$user->id,self::fingerprint($user)]);
   // Bound retained device credentials per account.
   $rows=$db->select('SELECT selector FROM remembered_logins WHERE user_id=? ORDER BY expires_at DESC',[(int)$user->id]);
   foreach(array_slice($rows,9) as $old)$db->delete('remembered_logins',['selector'=>$old->selector]);
   $db->insert('remembered_logins',['selector'=>$selector,'validator_hash'=>hash('sha256',$validator),'user_id'=>(int)$user->id,'fingerprint'=>self::fingerprint($user),'expires_at'=>$expires]);
   $_SESSION['remember_selector']=$selector;self::cookie($selector.':'.$validator,$expires);
  }catch(\Throwable $e){error_log('Remembered login issue: '.$e->getMessage());}
 }
}
