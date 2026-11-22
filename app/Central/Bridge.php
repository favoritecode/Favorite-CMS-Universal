<?php
declare(strict_types=1);
namespace FavoriteCMS\Central;
use FavoriteCMS\Core\{Request,Response};
final class Bridge {
 public static function boot():void{
  require_once __DIR__.'/functions.php';
  $private=APP_ROOT.'/favorite-server-license/bootstrap.php';if(is_file($private)){require_once $private;\FavoriteCentral\Runtime::boot(app());}
  try{(new Client())->autoConnect();}catch(\Throwable){}
  add_admin_menu('central-connection','Licenses & connection','🔑',static fn($r)=>(new self())->page($r),'manage_options',57);
 }
 public static function policyResponse(Request $r):?Response{
  $path=$r->path();
  // Keep the administration and payment/webhook endpoints recoverable.
  if(preg_match('~^/admin(?:/|$)~',$path)||str_starts_with($path,'/api/favorite-pay/webhook/'))return null;
  $policy=(new Client())->policy();
  if(($policy['policy_status']??'')!=='suspended')return null;
  $reason=(string)($policy['policy_reason']??'Contact the site administrator for assistance.');
  return Response::make(Ui::document('Site temporarily restricted','<section class="card"><h1>Site temporarily restricted</h1><p>'.Ui::e($reason).'</p><p>The site data is preserved. The administrator can contact Favorite Central to resolve this restriction.</p></section>'),503,['Cache-Control'=>'no-store','Retry-After'=>'3600']);
 }
 private function page(Request $r):string{
  if(!current_user_can('manage_options'))return '<h1>403 — Administrator access required</h1>';
  $client=new Client();$message='';$error='';$checkout=null;
  if($r->isPost())try{
   if(!hash_equals(csrf_token(),(string)$r->post('_token','')))throw new \RuntimeException('Session expired. Refresh this page.');
   $user=current_user();if(!$user||!$user->verifyPassword((string)$r->post('password','')))throw new \RuntimeException('Confirm your current CMS password.');
   switch((string)$r->post('action','')){
    case 'connect':$client->connect((string)$r->post('server_url',''));$message='Central connection verified. CMS remains Free.';break;
    case 'transfer':$client->transfer();$message='Domain ownership verified. Installation and trial identity preserved.';break;
    case 'refresh':$client->policy(true);foreach($this->manifests()as$m)$client->plugin($m,true,$this->active((string)$m['id']));$message='Connection and license status refreshed.';break;
    case 'activate':$client->request(['action'=>'activate','product_id'=>(string)$r->post('product_id'),'license_key'=>trim((string)$r->post('license_key'))]);$this->invalidate((string)$r->post('product_id'));$message='License activated.';break;
    case 'deactivate':$client->request(['action'=>'deactivate','purchase_id'=>(string)$r->post('purchase_id')]);$this->invalidate((string)$r->post('product_id'));$message='This installation was deactivated. Other installations are unchanged.';break;
    case 'checkout':$checkout=$client->request(['action'=>'checkout_ticket','package_id'=>(string)$r->post('package_id')]);break;
    case 'plugin_update':
    case 'theme_update':
     $product=(string)$r->post('product_id');$theme=str_starts_with($product,'theme--');$native=$theme?substr($product,7):$product;$type=$theme?'theme':'plugin';$base=APP_ROOT.($theme?'/themes':'/plugins');
     if(!preg_match('/^[a-z0-9][a-z0-9_-]{1,79}$/',$native)||!is_file($base.'/'.$native.'/'.$type.'.json')||$theme&&$native==='default')throw new \RuntimeException('Choose an installed extension.');
     $download=(new Updates($client))->download($product);
     try{if(($download['manifest']['payload']['kind']??'plugin')!==$type)throw new \RuntimeException('Signed extension kind differs from the selected installation.');\FavoriteCMS\Services\ExtensionPackageInstaller::installVerified($download['path'],$download['manifest'],$base);$this->invalidate($product);$message='Signed extension release installed. Existing activation choice is preserved.';}finally{if(is_file($download['path']))unlink($download['path']);}break;
    case 'core_update':
     $download=(new Updates($client))->download('favorite-cms-universal');
     try{$result=(new \FavoriteCMS\Services\Update\UpdateManager())->runUpdate($download['path'],['expected_sha256'=>$download['sha256'],'expected_version'=>$download['version']]);if(($result['state']??'COMPLETED')!=='COMPLETED')throw new \RuntimeException('Update did not complete. Open CMS Updates to review recovery details.');$message='CMS updated. Open the dashboard in a fresh request.';}finally{if(is_file($download['path']))unlink($download['path']);}break;
    default:throw new \RuntimeException('Unknown operation.');
   }
  }catch(\Throwable $e){$error=$e instanceof \RuntimeException||$e instanceof \InvalidArgumentException?$e->getMessage():'Central operation could not complete. Existing site data is unchanged.';}
  $c=$client->config();$policy=$client->policy();$h=Ui::styles().'<section class="fcp"><header><div><span class="eyebrow">FAVORITE CENTRAL</span><h1>Licenses & connection</h1><p>CMS is Free. Existing plugins keep their Free access.</p></div><span class="badge">CMS '.Ui::e(defined('APP_VERSION')?APP_VERSION:'').'</span></header>';
  if($message)$h.='<p class="notice">'.Ui::e($message).'</p>';if($error)$h.='<p role="alert" class="notice error">'.Ui::e($error).'</p>';
  if($checkout)$h.='<section class="card"><h2>Continue on the central site</h2><p>Your installation and chosen package are attached to this short-lived checkout.</p><a class="button primary" target="_blank" rel="noopener noreferrer" href="'.Ui::e($checkout['checkout_url']).'">Open Favorite Pay checkout ↗</a></section>';
  $h.='<section class="card"><h2>Central connection</h2>';
  if(empty($c['secret']))$h.='<p>Connect to your trusted Favorite Central server. HTTPS ownership verification pins its signing key.</p>'.$this->form('connect',Ui::field('server_url','Central site URL','https://','url'));
  else{$h.='<p>Server: '.Ui::e(preg_replace('~/favorite-central-api/?$~','',(string)$c['server_url'])).'</p><p>Installation: <code>'.Ui::e($c['installation_id']).'</code></p><p>Status: '.Ui::e($policy['policy_status']??'unknown').'</p>';if(!empty($policy['policy_reason']))$h.='<p class="notice">'.Ui::e($policy['policy_reason']).'</p>';$h.=$this->form('refresh','');if(rtrim((string)($c['site_url']??''),'/')!==rtrim((string)config('app.url'),'/'))$h.='<details><summary>Verify domain transfer</summary><p>Verify this site at its new canonical HTTPS address while retaining its existing installation identity.</p>'.$this->form('transfer','').'</details>';}
  $h.='</section><section class="card"><h2>Plugin & theme licenses</h2><p>Existing extensions remain Free unless a repository policy or licensing-supported release manages their access.</p></section>';
  foreach($this->manifests()as$m){
   $state=$client->plugin($m,false,$this->active((string)$m['id']));$id=$m['id'];$h.='<section class="card"><div class="inline"><h2>'.Ui::e($m['name']??$id).'</h2><span class="badge">'.Ui::e($state['mode']??$state['reason']??'unknown').'</span></div>';
   if(($state['reason']??'')==='trial_not_started')$h.='<p>Your trial has not started. Activate this plugin from the Plugins page when you are ready.</p>';
   if(!empty($state['valid_until']))$h.='<p>Access ends: '.Ui::e($state['valid_until']).' UTC</p>';
   if(($state['mode']??'')!=='legacy_free'){
    $h.='<details><summary>Activate a purchased license</summary>'.$this->form('activate','<input type="hidden" name="product_id" value="'.Ui::e($id).'">'.Ui::field('license_key','License key')).'</details>';
    if(!empty($state['purchase_id']))$h.='<details><summary>Deactivate this installation</summary>'.$this->form('deactivate','<input type="hidden" name="product_id" value="'.Ui::e($id).'"><input type="hidden" name="purchase_id" value="'.Ui::e($state['purchase_id']).'">').'</details>';
    try{$packages=$client->request(['action'=>'packages','product_id'=>$id]);foreach($packages['packages']??[]as$p){$p=(array)$p;$h.='<details><summary>'.Ui::e($p['name'].' · '.number_format($p['amount_minor']/100,2).' '.$p['currency']).'</summary>'.$this->form('checkout','<input type="hidden" name="package_id" value="'.Ui::e($p['id']).'"><p>'.((int)$p['days']===0?'Lifetime':(int)$p['days'].' days').'</p>').'</details>';}}catch(\Throwable){$h.='<p>Packages are temporarily unavailable.</p>';}
   }
   if(!empty($c['secret']))$h.='<details><summary>Trusted extension update</summary>'.$this->form('plugin_update','<input type="hidden" name="product_id" value="'.Ui::e($id).'">','Install latest signed plugin').'</details>';
   $h.='</section>';
  }
  if(!empty($c['secret']))$h.='<section class="card"><h2>Trusted CMS updates</h2><p>Download a signed release from your connected server and install it with the existing CMS backup, migration and recovery system.</p>'.$this->form('core_update','<p>A complete backup is created by the existing Update Manager.</p>','Check & install latest signed CMS').'</section>';
  return $h.'</section>';
 }
 private function form(string $action,string $body,string $button='Continue'):string{return '<form method="post">'.Ui::token($action).$body.Ui::field('password','Confirm current CMS password','','password').'<div class="actions"><button class="primary">'.Ui::e($button).'</button></div></form>';}
 private function active(string $id):bool{
  if(str_starts_with($id,'theme--'))return (string)\FavoriteCMS\Models\Setting::get('theme','active_theme','default')===substr($id,7);
  $raw=\FavoriteCMS\Models\Setting::get('plugins','active','[]');$list=is_array($raw)?$raw:json_decode((string)$raw,true);return in_array($id,(array)$list,true);
 }
 private function manifests():array{$list=[];foreach(glob(APP_ROOT.'/plugins/*/plugin.json')?:[]as$f){$m=json_decode((string)file_get_contents($f),true);if(!is_array($m))continue;$m['id']=basename(dirname($f));$list[]=$m;}foreach(glob(APP_ROOT.'/themes/*/theme.json')?:[]as$f){$native=basename(dirname($f));if($native==='default')continue;$m=json_decode((string)file_get_contents($f),true);if(!is_array($m))continue;$m['id']='theme--'.$native;$list[]=$m;}return $list;}
 private function invalidate(string $id):void{$client=new Client();$c=$client->config();unset($c['entitlements'][$id]);$client->save($c);}
}
