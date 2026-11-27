<?php
declare(strict_types=1);
namespace FavoriteCMS\Central;
use FavoriteCMS\Core\{Request,Response};
final class Bridge {
 public static function boot(bool $connect=true):void{
  require_once __DIR__.'/functions.php';
  $private=APP_ROOT.'/favorite-server-license/bootstrap.php';if(is_file($private)){require_once $private;\FavoriteCentral\Runtime::boot(app());}
  try{if($connect)(new Client())->autoConnect();}catch(\Throwable){}
  // Site & add-ons (license state, Buy, activation, connection, updates). Reachable by URL from the Plugins/Themes pages and
  // notices, deliberately not listed in the sidebar. Owner administration lives in CMS Central Control (Server package).
  add_admin_menu('central-connection','Site & add-ons','🔑',static fn($r)=>(new self())->page($r),'manage_options',57,true);
 }
 /** Verified decisions govern registered sites; first-use service outages may defer enrollment. */
 public static function recoveryAllowed(string $path):bool{
  if(in_array($path,['/admin','/admin/','/admin/tools','/admin/login','/admin/logout','/admin/two-step','/admin/connection-status','/admin/updates','/admin/tools/backup/create','/admin/tools/backup/download','/admin/tools/restore'],true)||str_starts_with($path,'/admin/updates/'))return true;
  if(in_array($path,['/forgot-password','/reset-password','/admin/forgot-password','/admin/reset-password'],true))return true;
  if(str_starts_with($path,'/api/favorite-pay/webhook/')||in_array($path,['/favorite-central-verify','/favorite-central-live','/.well-known/favorite-central-live'],true))return true;
  if($path==='/admin/page/central-control'&&is_file(APP_ROOT.'/favorite-server-license/bootstrap.php'))try{return (new \FavoriteCentral\Owner(\FavoriteCentral\Runtime::store()->config))->candidate(current_user());}catch(\Throwable){}
  return false;
 }
 public static function repairMode():bool{
  try{if(\FavoriteCMS\Security\CoreIntegrity::report(APP_ROOT)['status']!=='verified')return true;return !in_array((new Client())->policy()['policy_status']??'connection_required',['active','warning','registration_pending'],true);}catch(\Throwable){return true;}
 }
 public static function policyResponse(Request $r):?Response{
  $path=$r->path();
  if(self::recoveryAllowed($path))return null;
  $integrity=\FavoriteCMS\Security\CoreIntegrity::report(APP_ROOT);
  $policy=$integrity['status']==='verified'?(new Client())->policy():['policy_status'=>'integrity_failed','policy_reason'=>'Protected software verification failed. Restore the complete official release.'];
  $state=(string)($policy['policy_status']??'connection_required');
  if(in_array($state,['active','warning','registration_pending'],true))return null;
  $title=match($state){'banned'=>'License access disabled','suspended'=>'License suspended','integrity_failed'=>'Official software verification required','verification_required'=>'Site verification required',default=>'Verified connection required'};
  $heading='Favorite CMS — '.$title;
  $reason=(string)($policy['policy_reason']??'This website is currently unavailable because its Favorite CMS license status requires administrator attention.');
  $loginUrl=function_exists('site_path')?site_path('/admin/login'):'/admin/login';
  $html='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.Ui::e($title).' | Favorite CMS</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;font:16px/1.6 system-ui,-apple-system,Segoe UI,sans-serif;color:#192b48;background:radial-gradient(circle at 20% 15%,#dbeafe,transparent 42%),linear-gradient(135deg,#fafcff,#edf4ff)}main{width:min(100%,610px);padding:clamp(26px,6vw,55px);border:1px solid #dce7f7;border-radius:24px;background:#fff;box-shadow:0 26px 85px #123f771b}.brand{font-weight:800;letter-spacing:.01em;color:#185ac3}.icon{display:grid;place-items:center;margin:28px 0 20px;width:60px;height:60px;border-radius:17px;background:#fff3e5;color:#a76219;font-size:29px}h1{font-size:clamp(24px,5vw,34px);line-height:1.18;margin:0 0 16px}p{color:#51647e;margin:0 0 16px}.note{margin-top:24px;padding:19px 20px;border-radius:14px;background:#f5f8fe;border:1px solid #e5eefa}.note strong{display:block;color:#1c3556;margin-bottom:6px}a{color:#145bc8;font-weight:700;text-decoration:none}a:focus-visible{outline:3px solid #3b82f6;outline-offset:3px}footer{margin-top:26px;font-size:12px;color:#8a9ab0}</style></head><body><main><div class="brand">◆ Favorite CMS <span style="font-weight:500;color:#75869e">Official Edition</span></div><div class="icon" aria-hidden="true">!</div><h1>'.Ui::e($heading).'</h1><p>'.Ui::e($reason).'</p>'.(in_array($state,['connection_required','verification_required'],true)?'<p>If this site was installed from an official Favorite CMS package, the administrator should open <strong>Software updates</strong> to install the latest official version, and then contact the Favorite CMS owner at <a href="https://favoriteweb.net" rel="noopener noreferrer">favoriteweb.net</a> to verify the site.</p>':'').'<div class="note"><strong>For the website administrator</strong><p>Your website content, database, users, themes, and plugins remain preserved. Sign in to the administrator panel to review license status, appeal, or restore service.</p><a href="'.Ui::e($loginUrl).'">Administrator sign in</a> &nbsp;·&nbsp; <a href="'.Ui::e(site_path('/admin/connection-status')).'">Connection recovery</a> &nbsp;·&nbsp; <a href="'.Ui::e(site_path('/admin/updates')).'">Software updates</a> &nbsp;·&nbsp; <a href="https://favoriteweb.net" rel="noopener noreferrer">Official Favorite CMS</a></div><footer>Favorite CMS · License &amp; policy notice</footer></main></body></html>';
  return Response::make($html,503,['Content-Type'=>'text/html; charset=utf-8','Cache-Control'=>'no-store, private','Retry-After'=>'300','X-Robots-Tag'=>'noindex, nofollow']);
 }
 private function page(Request $r):string|Response{
  if(!current_user_can('manage_options'))return '<h1>403 — Administrator access required</h1>';
  $client=new Client();$message='';$error='';$checkout=null;
  if($r->isPost())try{
   if(!hash_equals(csrf_token(),(string)$r->post('_token','')))throw new \RuntimeException('Session expired. Refresh this page.');
   $user=current_user();if((string)$r->post('action','')!=='checkout'&&(!$user||!$user->verifyPassword((string)$r->post('password',''))))throw new \RuntimeException('Confirm your current password.');
   switch((string)$r->post('action','')){
    case 'connect':
     if(!is_file(APP_ROOT.'/favorite-server-license/bootstrap.php'))throw new \RuntimeException('Site registration is automatic. No server selection is needed.');
     require_once APP_ROOT.'/favorite-server-license/bootstrap.php';
     (new \FavoriteCentral\Setup(app(),APP_ROOT))->install($user,(string)$r->post('password',''));
     $message='Local site services confirmed and connected.';break;
    case 'transfer':$client->transfer();$message='Domain ownership verified. Installation and trial identity preserved.';break;
    case 'refresh':$client->policy(true);foreach($this->manifests()as$m)$client->plugin($m,true,$this->active((string)$m['id']));$message='Connection and license status refreshed.';break;
    case 'activate':$client->request(['action'=>'activate','product_id'=>(string)$r->post('product_id'),'license_key'=>trim((string)$r->post('license_key'))]);$this->invalidate((string)$r->post('product_id'));$message='License activated.';break;
    case 'deactivate':$client->request(['action'=>'deactivate','purchase_id'=>(string)$r->post('purchase_id')]);$this->invalidate((string)$r->post('product_id'));$message='This installation was deactivated. Other installations are unchanged.';break;
    case 'checkout':
     $checkout=$client->request(['action'=>'checkout_ticket','package_id'=>(string)$r->post('package_id')]);$this->invalidate((string)$r->post('product_id'));
     $go=(string)($checkout['checkout_url']??'');if(!str_starts_with($go,Authority::ORIGIN.'/')&&!(is_file(APP_ROOT.'/favorite-server-license/bootstrap.php')&&str_starts_with($go,rtrim((string)config('app.url'),'/').'/')))throw new \RuntimeException('The checkout address is not trusted.');
     return Response::redirect($go);
    case 'plugin_update':
    case 'theme_update':
     $product=(string)$r->post('product_id');$theme=str_starts_with($product,'theme--');$native=$theme?substr($product,7):$product;$type=$theme?'theme':'plugin';$base=APP_ROOT.($theme?'/themes':'/plugins');
     if(!preg_match('/^[a-z0-9][a-z0-9_-]{1,79}$/',$native)||!is_file($base.'/'.$native.'/'.$type.'.json')||$theme&&$native==='default')throw new \RuntimeException('Choose an installed extension.');
     $download=(new Updates($client))->download($product);
     try{if(($download['manifest']['payload']['kind']??'plugin')!==$type)throw new \RuntimeException('Signed extension kind differs from the selected installation.');\FavoriteCMS\Services\ExtensionPackageInstaller::installVerified($download['path'],$download['manifest'],$base);$this->invalidate($product);$message='Signed extension release installed. Existing activation choice is preserved.';}finally{if(is_file($download['path']))unlink($download['path']);}break;
    case 'core_update':
     $download=(new Updates($client))->download('favorite-cms-universal');
     try{$result=(new \FavoriteCMS\Services\Update\UpdateManager())->runUpdate($download['path'],['expected_sha256'=>$download['sha256'],'expected_version'=>$download['version']]);if(($result['state']??'COMPLETED')!=='COMPLETED')throw new \RuntimeException('Update did not complete. Open Updates to review recovery details.');$message='Site software updated. Open the dashboard in a fresh request.';}finally{if(is_file($download['path']))unlink($download['path']);}break;
    default:throw new \RuntimeException('Unknown operation.');
   }
  }catch(\Throwable $e){$error=$e instanceof \RuntimeException||$e instanceof \InvalidArgumentException?$e->getMessage():'Central operation could not complete. Existing site data is unchanged.';}
  $c=$client->config();$policy=$client->policy();$all=$this->manifests();
  $groups=['plugins'=>[],'themes'=>[]];foreach($all as$m)$groups[str_starts_with($m['id'],'theme--')?'themes':'plugins'][]=$m;
  $tab=(string)$r->get('tab','cms');if(!in_array($tab,['plugins','themes','cms'],true))$tab='cms';
  $connected=!empty($c['secret'])&&!Client::localServerPending();$h=Ui::styles().'<section class="fcp license-workspace"><header class="ws-head"><div><span class="eyebrow">YOUR WORKSPACE</span><h1>Site & add-ons</h1><p>Connection, licenses and updates for this website.</p></div>'.($tab==='cms'?'':'<a class="connection-pill" href="'.Ui::e($this->pageUrl('cms')).'"><span class="status-dot '.($connected?'is-connected':'').'"></span>'.($connected?'Connected':'Setup connection').' ↗</a>').'</header>';
  if($message)$h.='<p class="notice" role="status">'.Ui::e($message).'</p>';if($error)$h.='<p role="alert" class="notice error">'.Ui::e($error).'</p>';
  $h.='<nav class="license-tabs" aria-label="License categories">';foreach(['cms'=>'Site & connection','plugins'=>'Plugins','themes'=>'Themes']as$key=>$title)$h.='<a '.($tab===$key?'class="active" aria-current="page" ':'').'href="'.Ui::e($this->pageUrl($key)).'">'.Ui::e($title).($key!=='cms'?'<span>'.count($groups[$key]).'</span>':'').'</a>';$h.='</nav>';
  if($tab==='cms')$h.=$this->connection($c,$policy);
  else{
   $query=trim((string)$r->get('q',''));$items=$groups[$tab];if($query!=='')$items=array_values(array_filter($items,fn($m)=>mb_stripos((string)($m['name']??$m['id']),$query)!==false));
   $h.='<section class="extension-panel"><div class="extension-toolbar"><div><h2>'.($tab==='themes'?'Theme licenses':'Plugin licenses').'</h2><p>'.($tab==='themes'?'Each theme with its license, price and Buy button.':'Each plugin with its license, price and Buy button.').'</p></div><form method="get" class="license-search"><input type="hidden" name="tab" value="'.Ui::e($tab).'"><label class="sr-only" for="license-search">Search '.Ui::e($tab).'</label><input id="license-search" name="q" type="search" placeholder="Search '.Ui::e($tab).'…" value="'.Ui::e($query).'"><button>Search</button></form></div>';
   if($tab==='themes')$h.='<div class="default-theme"><span class="extension-symbol">T</span><div><strong>Default theme</strong><p>Included with your site · Always Free</p></div><span class="badge">Free</span></div>';
   if(!$items)$h.='<div class="license-empty"><h3>'.($query!==''?'No matching extensions':'No additional '.Ui::e($tab).' installed').'</h3><p>'.($query!==''?'Try a different name or clear your search.':'Installed extensions will appear here.').'</p></div>';
   foreach($items as$m)$h.=$this->extension($client,$m,$connected,(string)$r->get('focus','')===(string)$m['id']);
   $h.='<p class="license-footnote">Extensions marked Free need no license. Press Buy to pay with Favorite Pay; the license activates by itself once the payment is approved.</p></section>';
  }
  return $h.'</section>';
 }
 private function pageUrl(string $tab):string{return site_path('/admin/page/central-connection').'?tab='.$tab;}
 private function connection(array $c,array $policy):string{
  $connected=!empty($c['secret'])&&!Client::localServerPending();$ver=defined('APP_VERSION')?APP_VERSION:'';
  $h='<div class="connection-grid"><section class="card connection-main"><span class="eyebrow">CONNECTION</span>';
  if(!$connected){
   $h.='<h2>Connect your site</h2><p>Activate site services for add-on access and trusted updates. Your content stays available.</p>';
   $missing=Client::missingRequirements();
   if($missing)$h.='<p class="notice error" role="alert">Connection needs a hosting change. Enable PHP <strong>'.Ui::e(implode(' and ',$missing)).'</strong> in your hosting PHP extensions, then reload this page. Your password is not the cause.</p>';
   elseif(is_file(APP_ROOT.'/favorite-server-license/bootstrap.php'))$h.='<p>Server is installed on this hosting. Confirm once to initialize local services with this administrator account.</p>'.$this->form('connect','','Confirm local connection');
   else{$h.='<p class="notice">Site registration runs automatically. If services are temporarily unavailable, it will retry; you can continue managing content.</p>';if(!empty($c['registration_last_error']))$h.='<p class="notice error" role="alert">Last diagnostic: '.Ui::e($c['registration_last_error']).'</p>';}
  }else{
   $h.='<h2 class="conn-status"><span class="status-dot is-connected"></span>Connected<small>'.Ui::e(' · '.ucfirst((string)($policy['policy_status']??'active'))).'</small></h2>';
   $h.='<dl class="connection-facts"><div><dt>Website</dt><dd>'.Ui::e($c['site_url']??config('app.url','')).'</dd></div><div><dt>Access</dt><dd>Free site access</dd></div><div><dt>CMS version</dt><dd>'.Ui::e($ver).'</dd></div><div><dt>Installation ID</dt><dd><code>'.Ui::e($c['installation_id']??'—').'</code></dd></div><div><dt>Last verified</dt><dd>'.Ui::e(!empty($c['registry_verified_at'])?(function_exists('format_date')?format_date((int)$c['registry_verified_at'],'M j, Y g:i a'):gmdate('Y-m-d H:i:s',(int)$c['registry_verified_at']).' UTC'):(($t=strtotime((string)($c['connected_at']??'')))?(function_exists('format_date')?format_date($t,'M j, Y g:i a'):gmdate('Y-m-d H:i:s',$t).' UTC'):'—')).'</dd></div></dl>';
   if(!empty($policy['policy_reason']))$h.='<p class="notice">'.Ui::e($policy['policy_reason']).'</p>';
   if(!empty($c['registry_last_error']))$h.='<p class="notice error" role="alert">Diagnostic: '.Ui::e($c['registry_last_error']).'</p>';
   $h.='<details><summary>Refresh license status</summary>'.$this->form('refresh','','Refresh status').'</details>';
   if(rtrim((string)($c['site_url']??''),'/')!==rtrim((string)config('app.url'),'/'))$h.='<details><summary>Verify domain transfer</summary><p>Retain this installation identity when moving to a new canonical HTTPS address.</p>'.$this->form('transfer','','Verify transfer').'</details>';
  }
  $h.='</section><aside class="card management-card"><span class="eyebrow">MANAGE</span><div class="management-links"><a class="button" href="'.Ui::e(site_path('/admin/users')).'"><span>Site users<small>Accounts, roles and permissions</small></span><span aria-hidden="true">↗</span></a><a class="button" href="'.Ui::e(site_path('/admin/updates')).'"><span>Software updates<small>Review, back up and install updates</small></span><span aria-hidden="true">↗</span></a></div>';
  if($connected)$h.='<details><summary>Quick install of the latest signed update</summary><p>A backup is created automatically before installing.</p>'.$this->form('core_update','','Install latest signed update').'</details>';
  if(class_exists(\FavoriteCentral\Runtime::class))try{$s=\FavoriteCentral\Runtime::store();if((new \FavoriteCentral\Owner($s->config))->candidate(current_user()))$h.='<div class="owner-control-link"><h3>Central Owner controls</h3><p>Manage connected sites and policy actions in your private Owner panel.</p><a class="button primary" href="'.Ui::e(site_path('/admin/page/central-control?section=installations')).'">Manage sites ↗</a></div>';}catch(\Throwable){}
  return $h.'</aside></div>';
 }
 private function packages(Client $client,string $id):array{
  try{
   $c=$client->config();$hit=$c['packages_cache'][$id]??null;if(is_array($hit)&&(int)($hit['at']??0)>time()-600)return (array)$hit['list'];
   $res=$client->request(['action'=>'packages','product_id'=>$id]);$list=array_values(array_filter((array)($res['packages']??[]),'is_array'));
   $c=$client->config();$c['packages_cache'][$id]=['at'=>time(),'list'=>$list];$client->save($c);return $list;
  }catch(\Throwable){return [];}
 }
 private function term(int $days):string{return $days===0?'lifetime':($days%365===0?($days/365===1?'1 year':($days/365).' years'):($days===30?'1 month':$days.' days'));}
 /** One extension as a card: identity + license badge, then the note and the price with a single Buy button. */
 private function extension(Client $client,array $m,bool $connected,bool $open=false):string{
  $id=(string)$m['id'];$theme=str_starts_with($id,'theme--');$active=$this->active($id);$state=$client->plugin($m,false,$active);$s=Client::describeLicense($state,$theme?'theme':'plugin');
  $managed=!empty($s['managed']);$tone=$managed?(string)$s['tone']:'ok';
  $h='<article class="ext'.($open?' is-focus':'').'" id="ext-'.Ui::e($id).'"><div class="ext-main"><span class="extension-symbol">'.($theme?'T':'P').'</span><div class="ext-title"><strong>'.Ui::e($m['name']??$id).'</strong><small>'.($theme?'Theme':'Plugin').' · v'.Ui::e($m['version']??'—').' · '.($active?'Active':'Inactive').'</small></div><span class="ext-badge tone-'.Ui::e($tone).'">'.Ui::e($managed?(string)$s['short']:'Free').'</span></div>';
  if(!$managed)return $h.'</article>';
  $note=(string)$s['detail'];$buy='';
  if(!$connected)$note='Connect this website to buy a license. '.$note;
  elseif(!empty($s['buy'])||!empty($s['manage'])){
   $pkgs=$this->packages($client,$id);
   if(!$pkgs)$note.=' The price could not be loaded right now; please try again in a moment.';
   foreach($pkgs as$p){
    $p=(array)$p;$price=number_format(((int)($p['amount_minor']??0))/100,2).' '.Ui::e((string)($p['currency']??''));$term=$this->term((int)($p['days']??0));
    $buy.='<form method="post" class="ext-buy">'.Ui::token('checkout').'<input type="hidden" name="package_id" value="'.Ui::e((string)$p['id']).'"><input type="hidden" name="product_id" value="'.Ui::e($id).'"><div class="ext-price"><strong>'.$price.'</strong><span>/ '.Ui::e($term).(count($pkgs)>1?' · '.Ui::e((string)($p['name']??'')):'').'</span></div><button class="primary">'.(!empty($s['manage'])?'Renew':'Buy').'</button></form>';
   }
  }
  return $h.'<div class="ext-body"><p class="ext-note">'.Ui::e(trim($note)).'</p>'.$buy.'</div></article>';
 }
 private function form(string $action,string $body,string $button='Continue'):string{return '<form method="post">'.Ui::token($action).$body.Ui::field('password','Confirm current password','','password').'<div class="actions"><button class="primary">'.Ui::e($button).'</button></div></form>';}
 private function active(string $id):bool{
  if(str_starts_with($id,'theme--'))return (string)\FavoriteCMS\Models\Setting::get('theme','active_theme','default')===substr($id,7);
  $raw=\FavoriteCMS\Models\Setting::get('plugins','active','[]');$list=is_array($raw)?$raw:json_decode((string)$raw,true);return in_array($id,(array)$list,true);
 }
 private function manifests():array{$list=[];foreach(glob(APP_ROOT.'/plugins/*/plugin.json')?:[]as$f){$m=json_decode((string)file_get_contents($f),true);if(!is_array($m))continue;$m['id']=basename(dirname($f));$list[]=$m;}foreach(glob(APP_ROOT.'/themes/*/theme.json')?:[]as$f){$native=basename(dirname($f));if($native==='default')continue;$m=json_decode((string)file_get_contents($f),true);if(!is_array($m))continue;$m['id']='theme--'.$native;$list[]=$m;}return $list;}
 private function invalidate(string $id):void{$client=new Client();$c=$client->config();unset($c['entitlements'][$id],$c['plugin_next_attempt'][$id]);$client->save($c);}
}
