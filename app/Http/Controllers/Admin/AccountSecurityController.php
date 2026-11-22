<?php
declare(strict_types=1);
namespace FavoriteCMS\Http\Controllers\Admin;
use FavoriteCMS\Core\{Request,Response};
use FavoriteCMS\Models\User;
use FavoriteCMS\Services\{AccountSecurity,AuthRateLimiter,NotificationService};
final class AccountSecurityController {
 public function handle(Request $request):Response {
  $user=User::find((int)($_SESSION['auth_user_id']??0));if(!$user)return Response::redirect('/admin/login');
  $error='';$message='';$codes=[];$notificationCategories=NotificationService::categories();
  if($request->method()==='POST')try{
   $token=(string)$request->post('_token','');if(empty($_SESSION['_token'])||!hash_equals($_SESSION['_token'],$token))return Response::make('Invalid security token.',403);
   $action=(string)$request->post('action','');
   if($action==='announcement'){
    if(!$user->canManageSettings())return Response::make('Forbidden',403);
    $subject=trim((string)$request->post('subject',''));$body=trim((string)$request->post('body',''));
    if($subject===''||$body===''||mb_strlen($subject)>150||mb_strlen($body)>5000)throw new \RuntimeException('Enter a title (up to 150 characters) and a message (up to 5000 characters).');
    NotificationService::event('announcement:'.bin2hex(random_bytes(16)),'updates',$subject,$body);$message='Site update queued for subscribers.';
   }elseif($action==='retry_failed'){
    if(!$user->canManageSettings())return Response::make('Forbidden',403);
    AccountSecurity::db()->query("UPDATE `notification_outbox` SET status='pending',attempts=0,available_at=? WHERE status='failed'",[gmdate('Y-m-d H:i:s')]);$message='Failed emails returned to the queue. Check email settings before retrying.';
   }elseif($action==='run_queue'){
    if(!$user->canManageSettings())return Response::make('Forbidden',403);
    $result=NotificationService::work(10);$message='Email queue checked: '.json_encode($result);
   }elseif($action==='preferences'){
    AccountSecurity::ensure((int)$user->id);$prefs=AccountSecurity::preferences((int)$user->id);foreach(array_keys($notificationCategories) as $key){$value=$request->post('notice_'.$key)==='1';if($value&&empty($prefs[$key]))$prefs['_since_'.$key]=gmdate('Y-m-d H:i:s');$prefs[$key]=$value;}if(empty($prefs['_since']))$prefs['_since']=gmdate('Y-m-d H:i:s');
    AccountSecurity::db()->update('account_security',['preferences'=>json_encode($prefs)],['user_id'=>(int)$user->id]);$message='Email preferences saved.';
   }elseif($action==='cancel') {unset($_SESSION['two_step']);$message='Setup cancelled.';}
   elseif($action==='resend') {AccountSecurity::sendCode($user);$message='A new verification code has been sent.';}
   elseif($action==='confirm') {
    $purpose=(string)($_SESSION['two_step']['purpose']??'');if(!in_array($purpose,['setup','disable','recovery'],true)||!AccountSecurity::verify((string)$request->post('code',''),$purpose))throw new \RuntimeException('Code is invalid, already used, or expired.');
    if($purpose==='setup'){$codes=AccountSecurity::enable($user);$message='Two-step verification is now enabled.';}
    elseif($purpose==='disable'){AccountSecurity::disable($user);$message='Two-step verification disabled.';}
    else {AccountSecurity::ensure((int)$user->id);$codes=AccountSecurity::codes();AccountSecurity::db()->update('account_security',['recovery'=>json_encode(array_map(static fn($s)=>hash('sha256',str_replace('-','',$s)),$codes))],['user_id'=>(int)$user->id]);unset($_SESSION['two_step']);$message='New recovery codes generated. Previous codes no longer work.';}
    NotificationService::security((int)$user->id,$message);
   }else {
    if(!(new AuthRateLimiter())->allow('security-manage:'.$user->id,10,900)||!$user->verifyPassword((string)$request->post('password','')))throw new \RuntimeException('Confirm your current password, or wait before trying again.');
    $state=AccountSecurity::state((int)$user->id);
    if($action==='setup'){
     if($state->method!=='off')throw new \RuntimeException('Disable the current method before switching.');
     AccountSecurity::begin($user,'setup',(string)$request->post('method',''));
     $message='Verify a code below to finish setup.';
    }elseif(in_array($action,['disable','recovery'],true)&&$state->method!=='off'){AccountSecurity::begin($user,$action);$message='Verify your current method or a recovery code to continue.';}
    else throw new \RuntimeException('Choose a valid security action.');
   }
  }catch(\Throwable $e){$error=$e->getMessage();}
  $state=AccountSecurity::state((int)$user->id);$prefs=AccountSecurity::preferences((int)$user->id);$pending=$_SESSION['two_step']??null;
  if($pending&&(($pending['user_id']??0)!==(int)$user->id||($pending['expires']??0)<time()||($pending['purpose']??'')==='login')){$pending=null;unset($_SESSION['two_step']);}
  $setupSecret='';if($pending&&$pending['purpose']==='setup'&&$pending['method']==='app')try{$setupSecret=AccountSecurity::decrypt($pending['secret']);}catch(\Throwable $e){$error=$e->getMessage();}
  $history=AccountSecurity::db()->select('SELECT o.status,o.sent_at,o.last_error,e.subject,e.created_at FROM `notification_outbox` o JOIN `notification_events` e ON e.id=o.event_id WHERE o.user_id=? ORDER BY o.id DESC LIMIT 10',[(int)$user->id]);
  $queueStats=$user->canManageSettings()?AccountSecurity::db()->select('SELECT status,COUNT(*) AS total FROM `notification_outbox` GROUP BY status'):[];
  $pageTitle='Security & email preferences';$activeMenu='security';$contentView=APP_ROOT.'/resources/views/admin/users/security.php';
  ob_start();include APP_ROOT.'/resources/views/admin/layout.php';return Response::make((string)ob_get_clean())->header('Cache-Control','no-store')->header('Referrer-Policy','no-referrer');
 }
}
