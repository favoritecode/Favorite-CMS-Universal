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
  $c=$client->config();$policy=$client->policy();$all=$this->manifests();
  $groups=['plugins'=>[],'themes'=>[]];foreach($all as$m)$groups[str_starts_with($m['id'],'theme--')?'themes':'plugins'][]=$m;
  $tab=(string)$r->get('tab','plugins');if(!in_array($tab,['plugins','themes','cms'],true))$tab='plugins';
  $connected=!empty($c['secret']);$h=Ui::styles().'<section class="fcp license-workspace"><header><div><span class="eyebrow">YOUR CMS WORKSPACE</span><h1>Licenses & connection</h1><p>Manage your extensions and CMS connection in one place.</p></div><a class="connection-pill" href="'.Ui::e($this->pageUrl('cms')).'"><span class="status-dot '.($connected?'is-connected':'').'"></span>'.($connected?'Connected':'Setup connection').' ↗</a></header>';
  if($message)$h.='<p class="notice" role="status">'.Ui::e($message).'</p>';if($error)$h.='<p role="alert" class="notice error">'.Ui::e($error).'</p>';
  if($checkout)$h.='<section class="card"><h2>Continue on the central site</h2><p>Your installation and chosen package are attached to this short-lived checkout.</p><a class="button primary" target="_blank" rel="noopener noreferrer" href="'.Ui::e($checkout['checkout_url']).'">Open Favorite Pay checkout ↗</a></section>';
  $h.='<div class="license-overview"><div><small>Installed plugins</small><strong>'.count($groups['plugins']).'</strong></div><div><small>Installed themes</small><strong>'.(count($groups['themes'])+1).'</strong></div><div><small>CMS license</small><strong class="free-label">Free <span class="badge">'.Ui::e(defined('APP_VERSION')?APP_VERSION:'').'</span></strong></div></div>';
  $h.='<nav class="license-tabs" aria-label="License categories">';foreach(['plugins'=>'Plugins','themes'=>'Themes','cms'=>'CMS & connection']as$key=>$title)$h.='<a '.($tab===$key?'class="active" aria-current="page" ':'').'href="'.Ui::e($this->pageUrl($key)).'">'.Ui::e($title).($key!=='cms'?'<span>'.count($groups[$key]).'</span>':'').'</a>';$h.='</nav>';
  if($tab==='cms')$h.=$this->connection($c,$policy);
  else{
   $query=trim((string)$r->get('q',''));$items=$groups[$tab];if($query!=='')$items=array_values(array_filter($items,fn($m)=>mb_stripos((string)($m['name']??$m['id']),$query)!==false));
   $h.='<section class="extension-panel"><div class="extension-toolbar"><div><h2>'.($tab==='themes'?'Theme licenses':'Plugin licenses').'</h2><p>'.($tab==='themes'?'Keep theme access and updates organized.':'Check access, purchases and updates for each plugin.').'</p></div><form method="get" class="license-search"><input type="hidden" name="tab" value="'.Ui::e($tab).'"><label class="sr-only" for="license-search">Search '.Ui::e($tab).'</label><input id="license-search" name="q" type="search" placeholder="Search '.Ui::e($tab).'…" value="'.Ui::e($query).'"><button>Search</button></form></div>';
   if($tab==='themes')$h.='<div class="default-theme"><span class="extension-symbol">T</span><div><strong>Default theme</strong><p>Included with your CMS · Always Free</p></div><span class="badge">Free</span></div>';
   if(!$items)$h.='<div class="license-empty"><h3>'.($query!==''?'No matching extensions':'No additional '.Ui::e($tab).' installed').'</h3><p>'.($query!==''?'Try a different name or clear your search.':'Installed extensions will appear here.').'</p></div>';
   foreach($items as$m)$h.=$this->extension($client,$m,$connected);
   $h.='<p class="license-footnote">Free extensions need no license key. Open an item to manage available actions.</p></section>';
  }
  return $h.'</section>';
 }
 private function pageUrl(string $tab):string{return site_path('/admin/page/central-connection').'?tab='.$tab;}
 private function connection(array $c,array $policy):string{
  $connected=!empty($c['secret']);$h='<div class="connection-grid"><section class="card"><span class="eyebrow">CMS CONNECTION</span><h2>'.($connected?'Your site is connected':'Connect your CMS').'</h2><p>'.($connected?'Review your installation and refresh its license status.':'Connect once to manage paid extensions and trusted updates. Your CMS remains Free.').'</p>';
  if(!$connected)$h.=$this->form('connect',Ui::field('server_url','Central site URL',(string)config('central.url','https://favoriteweb.net'),'url'),'Connect securely');
  else{$h.='<dl class="connection-facts"><div><dt>Central server</dt><dd>'.Ui::e(preg_replace('~/favorite-central-api/?$~','',(string)$c['server_url'])).'</dd></div><div><dt>Installation ID</dt><dd><code>'.Ui::e($c['installation_id']).'</code></dd></div><div><dt>Policy status</dt><dd>'.Ui::e(ucfirst((string)($policy['policy_status']??'unknown'))).'</dd></div></dl>';if(!empty($policy['policy_reason']))$h.='<p class="notice">'.Ui::e($policy['policy_reason']).'</p>';$h.='<details><summary>Refresh license status</summary>'.$this->form('refresh','','Refresh status').'</details>';if(rtrim((string)($c['site_url']??''),'/')!==rtrim((string)config('app.url'),'/'))$h.='<details><summary>Verify domain transfer</summary><p>Retain this installation identity when moving to a new canonical HTTPS address.</p>'.$this->form('transfer','','Verify transfer').'</details>';}
  $h.='</section><aside class="card"><span class="eyebrow">CMS MANAGEMENT</span><h2>Site & user controls</h2><p>Manage this site’s users separately from extension licenses.</p><div class="management-links"><a class="button" href="'.Ui::e(site_path('/admin/users')).'">Manage site users ↗</a><a class="button" href="'.Ui::e(site_path('/admin/updates')).'">CMS Update Manager ↗</a></div><p>CMS '.Ui::e(defined('APP_VERSION')?APP_VERSION:'').' · Free license</p>';
  if(class_exists(\FavoriteCentral\Runtime::class))try{$s=\FavoriteCentral\Runtime::store();if((new \FavoriteCentral\Owner($s->config))->candidate(current_user()))$h.='<div class="owner-control-link"><h3>Central Owner controls</h3><p>Manage connected CMS installations and policy actions in your private Owner panel.</p><a class="button primary" href="'.Ui::e(site_path('/admin/page/central-control?section=installations')).'">Manage CMS installations ↗</a></div>';}catch(\Throwable){}
  $h.='</aside></div>';if($connected)$h.='<details class="card"><summary>Trusted CMS release</summary><p>The existing CMS Update Manager creates a backup before installing a signed release.</p>'.$this->form('core_update','','Install latest signed CMS').'</details>';return $h;
 }
 private function extension(Client $client,array $m,bool $connected):string{
  $id=$m['id'];$theme=str_starts_with($id,'theme--');$state=$client->plugin($m,false,$this->active($id));$mode=(string)($state['mode']??$state['reason']??'unknown');
  $labels=['legacy_free'=>'Free','free'=>'Free','owner'=>'Owner access','paid'=>'Licensed','trial'=>'Trial','trial_not_started'=>'Trial available','purchase_required'=>'License needed','central_connection_required'=>'Connect to manage','central_temporarily_unavailable'=>'Check connection','license_expired'=>'Expired','policy_suspension'=>'Restricted'];$label=$labels[$mode]??ucfirst(str_replace('_',' ',$mode));
  $h='<details class="license-item"><summary><span class="extension-symbol">'.($theme?'T':'P').'</span><span class="extension-title"><strong>'.Ui::e($m['name']??$id).'</strong><small>'.($theme?'Theme':'Plugin').' · v'.Ui::e($m['version']??'—').' · '.($this->active($id)?'Active':'Inactive').'</small></span><span class="badge '.(!empty($state['allowed'])?'access-ok':'').'">'.Ui::e($label).'</span><span class="manage-label">Details <span aria-hidden="true">⌄</span></span></summary><div class="license-details">';
  if($mode==='legacy_free')$h.='<p>This extension currently has Free access. A license key is not required.</p>';
  if($mode==='trial_not_started')$h.='<p>Your trial has not started. Activate this '.($theme?'theme':'plugin').' when you are ready.</p>';
  if(!empty($state['valid_until']))$h.='<p>Access ends: '.Ui::e($state['valid_until']).' UTC</p>';
  if($mode!=='legacy_free'&&$connected){
   $h.='<div class="license-action-grid"><details><summary>Activate purchased license</summary>'.$this->form('activate','<input type="hidden" name="product_id" value="'.Ui::e($id).'">'.Ui::field('license_key','License key'),'Activate license').'</details>';
   if(!empty($state['purchase_id']))$h.='<details><summary>Deactivate this installation</summary>'.$this->form('deactivate','<input type="hidden" name="product_id" value="'.Ui::e($id).'"><input type="hidden" name="purchase_id" value="'.Ui::e($state['purchase_id']).'">','Deactivate installation').'</details>';
   $h.='</div>';try{$packages=$client->request(['action'=>'packages','product_id'=>$id]);foreach($packages['packages']??[]as$p){$p=(array)$p;$h.='<details><summary>'.Ui::e($p['name'].' · '.number_format($p['amount_minor']/100,2).' '.$p['currency']).'</summary>'.$this->form('checkout','<input type="hidden" name="package_id" value="'.Ui::e($p['id']).'"><p>'.((int)$p['days']===0?'Lifetime':(int)$p['days'].' days').'</p>','Continue to checkout').'</details>';}}catch(\Throwable){$h.='<p>Packages are temporarily unavailable.</p>';}
  }elseif($mode!=='legacy_free')$h.='<p><a href="'.Ui::e($this->pageUrl('cms')).'">Connect your CMS</a> to manage this license.</p>';
  if($connected)$h.='<details><summary>Trusted '.($theme?'theme':'plugin').' update</summary>'.$this->form($theme?'theme_update':'plugin_update','<input type="hidden" name="product_id" value="'.Ui::e($id).'">','Install latest signed '.($theme?'theme':'plugin')).'</details>';
  return $h.'</div></details>';
 }
 private function form(string $action,string $body,string $button='Continue'):string{return '<form method="post">'.Ui::token($action).$body.Ui::field('password','Confirm current CMS password','','password').'<div class="actions"><button class="primary">'.Ui::e($button).'</button></div></form>';}
 private function active(string $id):bool{
  if(str_starts_with($id,'theme--'))return (string)\FavoriteCMS\Models\Setting::get('theme','active_theme','default')===substr($id,7);
  $raw=\FavoriteCMS\Models\Setting::get('plugins','active','[]');$list=is_array($raw)?$raw:json_decode((string)$raw,true);return in_array($id,(array)$list,true);
 }
 private function manifests():array{$list=[];foreach(glob(APP_ROOT.'/plugins/*/plugin.json')?:[]as$f){$m=json_decode((string)file_get_contents($f),true);if(!is_array($m))continue;$m['id']=basename(dirname($f));$list[]=$m;}foreach(glob(APP_ROOT.'/themes/*/theme.json')?:[]as$f){$native=basename(dirname($f));if($native==='default')continue;$m=json_decode((string)file_get_contents($f),true);if(!is_array($m))continue;$m['id']='theme--'.$native;$list[]=$m;}return $list;}
 private function invalidate(string $id):void{$client=new Client();$c=$client->config();unset($c['entitlements'][$id]);$client->save($c);}
}
