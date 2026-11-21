<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
use FavoriteCMS\Core\{Container,Database,Hook};
use FavoriteCMS\Models\{User,Setting};

/** Durable, opt-in publication fan-out and transactional account alerts. */
final class NotificationService
{
 /** The same lifecycle gate controls settings, enqueue, scans and delivery. */
 public static function categories():array {
  $labels=[
   'security'=>['Security alerts','Successful sign-ins, failed login attempts and account security changes.'],
   'posts'=>['Posts & pages','Newly published articles and pages.'],
   'updates'=>['Site updates','Site announcements published through the notification integration.'],
  ];
  $installed=(new \FavoriteCMS\Plugins\PluginManager(app()))->getInstalledPlugins();
  $available=static fn($id)=>isset($installed[$id])&&!empty($installed[$id]['valid'])&&!empty($installed[$id]['active'])&&!empty($installed[$id]['compatible']);
  if($available('favorite-digital')||$available('favorite-shop')){
   $labels['purchases']=['Purchase confirmations','Digital purchase confirmations and Shop order receipts.'];
   $labels['products']=['Products','New products from your active Digital and Shop plugins.'];
  }
  if($available('favorite-multimedia'))$labels['movies']=['Movies & series','New published Multimedia releases.'];
  if($available('favorite-app-store'))$labels['apps']=['Apps & software','New App Store entries.'];
  $order=['security','purchases','posts','products','movies','apps','updates'];
  return array_replace(array_intersect_key(array_fill_keys($order,null),$labels),$labels);
 }

 public static function event(string $key,string $category,string $subject,string $body,?int $userId=null):bool {
  try{$db=AccountSecurity::db();if(!in_array($category,['security','purchases','posts','products','movies','apps','updates'],true))return false;
   if(!isset(self::categories()[$category]))return true;
   if($userId!==null&&!AccountSecurity::preferences($userId)[$category])return true;
   $key=hash('sha256',$key);if($db->selectOne('SELECT id FROM `notification_events` WHERE event_key=?',[$key]))return true;
   $db->insert('notification_events',['event_key'=>$key,'category'=>$category,'subject'=>mb_substr(strip_tags($subject),0,180),'body'=>mb_substr(strip_tags($body),0,12000),'user_id'=>$userId,'created_at'=>gmdate('Y-m-d H:i:s')]);return true;
  }catch(\Throwable $e){error_log('Favorite CMS notification enqueue failed: '.$e->getMessage());return false;}
 }
 private static function requireEvent(string $key,string $category,string $subject,string $body,?int $userId=null):void {
  if(!self::event($key,$category,$subject,$body,$userId))throw new \RuntimeException('Publication notification could not be recorded; source cursor retained for retry.');
 }
 public static function security(int $id,string $action,string $detail='',bool $throttle=false):void {
  if($throttle&&!(new AuthRateLimiter())->allow('security-alert:'.$id.':'.$action,1,3600))return;
  self::event('security:'.$id.':'.$action.':'.bin2hex(random_bytes(12)),'security',$action,$action."\n\n".$detail."\nTime (UTC): ".gmdate('Y-m-d H:i:s')."\n\nIf this was not you, change your password and review Account security.",$id);
 }
 public static function boot():void {
  Hook::addAction('cms_notification',static function($event){if(is_array($event))self::event((string)($event['key']??''),(string)($event['category']??''),(string)($event['subject']??''),(string)($event['body']??''),isset($event['user_id'])?(int)$event['user_id']:null);});
  // No SMTP round-trips on normal page requests. Cron also works without visitors.
  if(PHP_SAPI!=='cli'&&function_exists('fastcgi_finish_request'))register_shutdown_function(static function(){if(session_status()===PHP_SESSION_ACTIVE)session_write_close();fastcgi_finish_request();try{self::work(3,false);}catch(\Throwable $e){error_log('Notification worker: '.$e->getMessage());}});
 }
 public static function sources():array {
  return Hook::applyFilters('cms_notification_sources',[
   ['table'=>'posts','category'=>'posts','path'=>'/','plugin'=>null],
   ['table'=>'pages','category'=>'posts','path'=>'/','plugin'=>null],
   ['table'=>'favorite_digital_products','category'=>'products','path'=>'/store','plugin'=>'favorite-digital'],
   ['table'=>'multimedia_movies','category'=>'movies','path'=>'/movie','plugin'=>'favorite-multimedia'],
   ['table'=>'multimedia_series','category'=>'movies','path'=>'/series','plugin'=>'favorite-multimedia'],
   ['table'=>'favorite_app_store_apps','category'=>'apps','path'=>'/apps','plugin'=>'favorite-app-store'],
   ['table'=>'favorite_digital_orders','category'=>'purchases','path'=>'/admin/users/profile','plugin'=>'favorite-digital'],
  ]);
 }
 public static function scan():void {
  $db=AccountSecurity::db();$db->registerPrefixableTables(array_column(self::sources(),'table'));
  $installed=(string)Setting::get('notifications','installed_at',gmdate('Y-m-d H:i:s'));
  foreach(self::sources() as $source){
   $table=$source['table']??'';if(!preg_match('/^[a-z_]+$/D',$table))continue;
   // Do not notify for a deactivated plugin whose tables remain installed.
   if(!empty($source['plugin'])){
    $plugins=(new \FavoriteCMS\Plugins\PluginManager(app()))->getInstalledPlugins();$plugin=$plugins[(string)$source['plugin']]??[];
    if(empty($plugin['valid'])||empty($plugin['active'])||empty($plugin['compatible']))continue;
   }
   if(!isset(self::categories()[$source['category']]))continue;
   if(!$db->tableExists($table))continue;
   try{
    $columns=$db->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?$db->select('PRAGMA table_info('.$db->quoteIdentifier($table).')'):$db->select('SHOW COLUMNS FROM `'.$table.'`');
    $names=array_map(static fn($c)=>$c->name??$c->Field,$columns);if(!in_array('updated_at',$names,true))continue;
    $cursor=$db->selectOne('SELECT * FROM `notification_sources` WHERE source_key=?',[$table]);
    if(!$cursor){try{$db->insert('notification_sources',['source_key'=>$table,'cursor_time'=>$installed,'cursor_id'=>0]);}catch(\Throwable){}$cursor=(object)['cursor_time'=>$installed,'cursor_id'=>0];}
    $rows=$db->select('SELECT * FROM `'.$table.'` WHERE (updated_at>? OR (updated_at=? AND id>?)) AND updated_at<=? ORDER BY updated_at,id LIMIT 100',[$cursor->cursor_time,$cursor->cursor_time,(int)$cursor->cursor_id,gmdate('Y-m-d H:i:s',time()-2)]);
    foreach($rows as $row){
     if($source['category']==='purchases'){
      if(($row->payment_status??'')==='paid'&&!empty($row->user_id))self::requireEvent($table.':paid:'.$row->id,'purchases','Purchase confirmed — '.($row->order_number??$row->id),"Your purchase has been confirmed.\nOrder: ".($row->order_number??$row->id)."\nTotal: ".($row->total_amount??'').' '.($row->currency??'')."\nView order: ".app_url('/account/orders/'.rawurlencode((string)($row->order_number??$row->id)))."",(int)$row->user_id);
     }elseif(($row->status??'')==='published'&&($table!=='posts'||($row->type??'post')==='post')){
      $url=app_url($source['path']);if($table==='posts')$url=app_url((new \FavoriteCMS\Models\Post((array)$row))->url());elseif($table==='pages')$url=app_url((new \FavoriteCMS\Models\Page((array)$row))->url());elseif(!empty($row->slug))$url=app_url(rtrim($source['path'],'/').'/'.rawurlencode((string)$row->slug));
      self::requireEvent($table.':published:'.$row->id,$source['category'],'New '.rtrim($source['category'],'s').': '.($row->title??'New content'),($row->title??'New content')."\n\n".$url);
     }
     $db->update('notification_sources',['cursor_time'=>$row->updated_at,'cursor_id'=>(int)$row->id],['source_key'=>$table]);
    }
   }catch(\Throwable $e){error_log('Notification source '.$table.': '.$e->getMessage());}
  }
  self::scanShop($installed);
 }
 /** Shop 1.x owns raw PDO tables; never assume the CMS table prefix. */
 private static function scanShop(string $installed):void {
  $plugins=(new \FavoriteCMS\Plugins\PluginManager(app()))->getInstalledPlugins();$plugin=$plugins['favorite-shop']??[];
  if(empty($plugin['valid'])||empty($plugin['active'])||empty($plugin['compatible']))return;
  $db=AccountSecurity::db();$pdo=$db->getPdo();
  foreach(['favorite_shop_products','favorite_shop_orders'] as $table){
   $physical=null;
   foreach(array_unique([$table,$db->prefix().$table]) as $candidate){
    try{$pdo->query('SELECT 1 FROM '.$db->quoteIdentifier($candidate,false).' LIMIT 1');$physical=$candidate;break;}catch(\PDOException){}
   }
   if($physical===null)continue;
   try{
    $cursor=$db->selectOne('SELECT * FROM notification_sources WHERE source_key=?',[$table]);
    if(!$cursor){$db->insert('notification_sources',['source_key'=>$table,'cursor_time'=>$installed,'cursor_id'=>0]);$cursor=(object)['cursor_time'=>$installed,'cursor_id'=>0];}
    $query=$pdo->prepare('SELECT * FROM '.$db->quoteIdentifier($physical,false).' WHERE (updated_at>? OR (updated_at=? AND id>?)) AND updated_at<=? ORDER BY updated_at,id LIMIT 100');
    $query->execute([$cursor->cursor_time,$cursor->cursor_time,(int)$cursor->cursor_id,gmdate('Y-m-d H:i:s',time()-2)]);
    foreach($query->fetchAll(\PDO::FETCH_OBJ) as $row){
     if($table==='favorite_shop_products'){
      if(($row->status??'')==='published')self::requireEvent($table.':published:'.$row->id,'products','New product: '.$row->name,$row->name."\n\n".app_url('/shop/product/'.rawurlencode((string)$row->slug)));
     }elseif(!empty($row->user_id)&&!in_array($row->status??'',['cancelled','returned','failed'],true)){
      // A registered customer's COD receipt must not wait for payment collection.
      $number=(string)($row->order_number??$row->id);$total=number_format((int)($row->total_cents??0)/100,2,'.','').' '.($row->currency??'');
      self::requireEvent($table.':placed:'.$row->id,'purchases','Order received — '.$number,"Your Shop order has been received.\nOrder: ".$number."\nTotal: ".$total."\nPayment: ".($row->payment_status??'unpaid')."\nView order: ".app_url('/shop/order/'.rawurlencode($number)),(int)$row->user_id);
     }
     $db->update('notification_sources',['cursor_time'=>$row->updated_at,'cursor_id'=>(int)$row->id],['source_key'=>$table]);
    }
   }catch(\Throwable $e){error_log('Notification source '.$table.': '.$e->getMessage());}
  }
 }
 public static function work(int $limit=20,bool $force=true):array {
  $db=AccountSecurity::db();$dir=APP_ROOT.'/storage/cache/notifications';if(!is_dir($dir)&&!@mkdir($dir,0700,true))throw new \RuntimeException('Notification worker storage is not writable.');
  $lock=fopen($dir.'/worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return ['busy'=>true];}
  try{
   $stamp=$dir.'/last-run';if(!$force&&is_file($stamp)&&(int)file_get_contents($stamp)>time()-60)return ['throttled'=>true];file_put_contents($stamp,(string)time());
   if(!$db->tableExists('notification_events'))return ['migration_required'=>true];
   self::scan();$sent=0;$failed=0;$now=gmdate('Y-m-d H:i:s');
   // Targeted security/receipt events take precedence over subscriber broadcasts.
   foreach($db->select("SELECT * FROM `notification_events` WHERE status='pending' ORDER BY CASE WHEN user_id IS NULL THEN 1 ELSE 0 END,id LIMIT 10") as $event){
    if(!isset(self::categories()[$event->category])){$db->update('notification_events',['status'=>'expanded'],['id'=>(int)$event->id]);continue;}
    $users=$event->user_id?$db->select('SELECT id,email,status FROM `users` WHERE id=?',[(int)$event->user_id]):$db->select("SELECT u.id,u.email,u.status FROM `users` u JOIN `account_security` a ON a.user_id=u.id WHERE u.id>? AND u.status='active' ORDER BY u.id LIMIT 100",[(int)$event->cursor_id]);
    foreach($users as $user){
     $prefs=AccountSecurity::preferences((int)$user->id);
     if($user->status==='active'&&!empty($prefs[$event->category])&&($event->user_id||($prefs['_since_'.$event->category]??$prefs['_since']??'9999')<=$event->created_at)){
      try{$db->insert('notification_outbox',['event_id'=>(int)$event->id,'user_id'=>(int)$user->id,'email'=>$user->email,'available_at'=>$now]);}catch(\Throwable $e){if(!$db->selectOne('SELECT id FROM `notification_outbox` WHERE event_id=? AND user_id=?',[$event->id,$user->id]))throw $e;}
     }
     $db->update('notification_events',['cursor_id'=>(int)$user->id],['id'=>(int)$event->id]);
    }
    if($event->user_id||count($users)<100)$db->update('notification_events',['status'=>'expanded'],['id'=>(int)$event->id]);
   }
   // Interrupted attempts are reclaimed after a ten-minute lease.
   $db->query("UPDATE `notification_outbox` SET status='pending' WHERE status='sending' AND available_at<=?",[$now]);
   $rows=$db->select("SELECT o.*,e.subject,e.body,e.category FROM `notification_outbox` o JOIN `notification_events` e ON e.id=o.event_id WHERE o.status='pending' AND o.available_at<=? ORDER BY CASE WHEN e.user_id IS NULL THEN 1 ELSE 0 END,o.id LIMIT ".max(1,min(100,$limit)),[$now]);
   foreach($rows as $row){
    $user=User::find((int)$row->user_id);$prefs=AccountSecurity::preferences((int)$row->user_id);
    if(!isset(self::categories()[$row->category])||!$user||$user->status!=='active'||empty($prefs[$row->category])||(string)$user->email!==$row->email){$db->update('notification_outbox',['status'=>'cancelled'],['id'=>$row->id]);continue;}
    if(!$db->query("UPDATE `notification_outbox` SET status='sending',available_at=?,attempts=attempts+1 WHERE id=? AND status='pending'",[gmdate('Y-m-d H:i:s',time()+600),$row->id])->rowCount())continue;
    $ok=false;try{$ok=MailService::send($row->email,$row->subject,$row->body."\n\nManage email preferences: ".app_url('/admin/account/security'));}catch(\Throwable $e){error_log('Notification transport: '.$e->getMessage());}
    $attempt=(int)$row->attempts+1;
    $db->update('notification_outbox',['status'=>$ok?'sent':($attempt>=5?'failed':'pending'),'sent_at'=>$ok?$now:null,'last_error'=>$ok?null:'Mail transport failed; check Settings > Email.','available_at'=>gmdate('Y-m-d H:i:s',time()+min(21600,60*(2**$attempt)))],['id'=>$row->id]);
    if($ok)$sent++;else $failed++;
   }
   $db->query("DELETE FROM `notification_outbox` WHERE status IN ('sent','cancelled') AND available_at<?",[gmdate('Y-m-d H:i:s',time()-30*86400)]);
   $db->query("DELETE FROM `notification_events` WHERE status='expanded' AND created_at<? AND id NOT IN (SELECT event_id FROM `notification_outbox`)",[gmdate('Y-m-d H:i:s',time()-30*86400)]);
   return ['sent'=>$sent,'failed'=>$failed];
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
}
