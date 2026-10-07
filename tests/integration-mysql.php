<?php
/** CLI-only integration checks. Creates uniquely named databases on a local test server. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=getenv('CMS_TEST_ROOT');
if (!$root || !is_file($root.'/.cms-test-sandbox')) throw new RuntimeException('CMS_TEST_ROOT must point to an isolated copied CMS with a .cms-test-sandbox marker.');
if (realpath($root) === realpath(dirname(__DIR__))) throw new RuntimeException('Never run installation tests against the source CMS.');
define('APP_ROOT',realpath($root)); define('PHPUNIT_RUNNING',true);
$app=require APP_ROOT.'/bootstrap.php';
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Kernel;
use FavoriteCMS\Models\Post;
use FavoriteCMS\Models\Page;
use FavoriteCMS\Models\Media;
use FavoriteCMS\Models\User;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Services\ContentWorkspace;
use FavoriteCMS\Services\ContentRevision;
use FavoriteCMS\Http\Controllers\Admin\ContentWorkspaceController;
$host=getenv('CMS_TEST_DB_HOST') ?: '127.0.0.1';
if(!in_array($host,['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Use a local disposable test database server.');
$config=['driver'=>'mysql','host'=>$host,'port'=>getenv('CMS_TEST_DB_PORT') ?: '3306','username'=>getenv('CMS_TEST_DB_USER') ?: 'root','password'=>getenv('CMS_TEST_DB_PASSWORD') ?: '','charset'=>'utf8mb4','prefix'=>'qa_'];
$server=new PDO('mysql:host='.$host.';port='.$config['port'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='favorite_qa_'.bin2hex(random_bytes(6)); $restored=$name.'_restore';
$server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');
$server->exec('CREATE DATABASE `'.$restored.'` CHARACTER SET utf8mb4');
$config['database']=$name;
$results=[];
function check(string $name,callable $run): void { global $results; try {$run();$results[]=['test'=>$name,'status'=>'PASS'];}catch(Throwable $e){$results[]=['test'=>$name,'status'=>'FAIL','error'=>$e->getMessage()];} }
function assertThat(bool $condition,string $message='Assertion failed'): void { if(!$condition)throw new RuntimeException($message); }
function body($response):array {return json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);}
function status($response):int {return (new ReflectionProperty($response,'status'))->getValue($response);}
check('actual installer and all 19 MySQL migrations',function() use($app,$config){
 $service=new FavoriteCMS\Installer\InstallationService($app,new FavoriteCMS\Installer\DatabaseProvisioner(),new FavoriteCMS\Installer\InstallationStateManager());
 $result=$service->install($config,['name'=>'CMS integration fixture','url'=>'http://127.0.0.1:8766'],['username'=>'qa_admin','name'=>'QA Admin','email'=>'qa@example.test','password'=>'Local-Testing-Only-2026!']);
 assertThat(count($result['applied_migrations'])===19); assertThat($result['admin_user_id']>0); assertThat(is_file(APP_ROOT.'/.env')); assertThat(is_file(APP_ROOT.'/storage/installed.lock'));
});
$db=$app->make(Database::class);
$_SESSION=['auth_user_id'=>1,'auth_user_role'=>'super-admin','_token'=>'integration-token'];
$_SERVER['HTTP_HOST']='127.0.0.1:8766'; $_SERVER['REQUEST_URI']='/admin';
$kernel=new Kernel($app);$dispatch=new ReflectionMethod($kernel,'dispatchAdmin');
$send=function(string $path,array $data=[],string $method='POST')use($dispatch,$kernel){return $dispatch->invoke($kernel,Request::create($method,$path,$data),$path,$method);};
$postId=0;$pageId=0;
check('normal post store preserves custom code, SEO, categories and tags',function()use($send,$db,&$postId){
 $response=$send('/admin/posts/store',['_token'=>'integration-token','title'=>'First version বাংলা','content'=>'<p style="color:#c026d3">Original text</p><iframe src="https://example.test/embed"></iframe>','status'=>'draft','excerpt'=>'Original excerpt','meta_title'=>'SEO title','tags'=>'one,two']);
 assertThat(status($response)===302);$postId=(int)$db->selectOne('SELECT id FROM `posts` ORDER BY id DESC LIMIT 1')->id;
 $post=Post::find($postId);assertThat(str_contains($post->content,'iframe'));assertThat($post->getSeoMeta()->meta_title==='SEO title');assertThat(count($post->getTaxonomies('tag'))===2);
 assertThat(count(body($send('/admin/posts/workspace',['id'=>$postId],'GET'))['history'])===1);
});
check('normal page store and history',function()use($send,$db,&$pageId){
 $response=$send('/admin/pages/store',['_token'=>'integration-token','title'=>'First page','content'=>'<p>First page content</p>','status'=>'draft']);assertThat(status($response)===302);
 $pageId=(int)$db->selectOne('SELECT id FROM `pages` ORDER BY id DESC LIMIT 1')->id;
 assertThat(count(body($send('/admin/pages/workspace',['id'=>$pageId],'GET'))['history'])===1);
});
check('autosave is private, durable, tab-separated and does not publish',function()use($send,$db,$postId){
 $post=Post::find($postId);$baseline=ContentWorkspace::fingerprint($post);
 foreach(['tab_alpha1','tab_bravo2']as$client){$r=$send('/admin/posts/autosave',['_token'=>'integration-token','id'=>$postId,'client_id'=>$client,'baseline'=>$baseline,'title'=>'Unsaved '.$client,'content'=>'<p>Draft</p><iframe src="https://example.test/embed"></iframe>']);assertThat(status($r)===200);}
 assertThat(Post::find($postId)->title===$post->title);assertThat(Post::find($postId)->status==='draft');
 $workspace=body($send('/admin/posts/workspace',['id'=>$postId],'GET'));assertThat(count($workspace['drafts'])===2);
 $recovery=body($send('/admin/posts/snapshot',['id'=>$postId,'kind'=>'draft','snapshot_id'=>$workspace['drafts'][0]->id ?? $workspace['drafts'][0]['id']],'GET'));
 assertThat(str_contains($recovery['fields']['content'],'iframe'));
});
check('CSRF and method checks protect draft writes',function()use($send){ assertThat(status($send('/admin/posts/autosave',[], 'GET'))===405);assertThat(status($send('/admin/posts/autosave',['_token'=>'wrong']))===403); });
check('stale autosaves and normal saves cannot overwrite another edit',function()use($send,$db,$postId){
 $post=Post::find($postId);$old=ContentWorkspace::fingerprint($post);$db->update('posts',['title'=>'Edited in another tab'],['id'=>$postId]);
 assertThat(status($send('/admin/posts/autosave',['_token'=>'integration-token','id'=>$postId,'baseline'=>$old,'client_id'=>'tab_alpha1','title'=>'Stale draft','content'=>'<p>Stale</p>']))===409);
 $send('/admin/posts/update',['_token'=>'integration-token','id'=>$postId,'title'=>'Stale write','content'=>$post->content,'_workspace_baseline'=>$old]);assertThat(Post::find($postId)->title==='Edited in another tab');
});
check('updates keep pre-change versions and restore only stages content',function()use($send,$postId){
 $post=Post::find($postId);$send('/admin/posts/update',['_token'=>'integration-token','id'=>$postId,'title'=>'Second version','content'=>'<p>Second content</p><iframe src="https://example.test/embed"></iframe>','status'=>'draft','_workspace_baseline'=>ContentWorkspace::fingerprint($post)]);
 $workspace=body($send('/admin/posts/workspace',['id'=>$postId],'GET'));assertThat(count($workspace['history'])>=2);
 $snapshot=body($send('/admin/posts/snapshot',['id'=>$postId,'kind'=>'history','snapshot_id'=>end($workspace['history'])['id']],'GET'));assertThat($snapshot['fields']['title']==='First version বাংলা');assertThat(Post::find($postId)->title==='Second version');
});
check('page update keeps old and new title/content versions',function()use($send,$pageId){$page=Page::find($pageId);$send('/admin/pages/update',['_token'=>'integration-token','id'=>$pageId,'title'=>'Second page','content'=>'<p>Second page</p>','status'=>'draft','_workspace_baseline'=>ContentWorkspace::fingerprint($page)]);assertThat(count(body($send('/admin/pages/workspace',['id'=>$pageId],'GET'))['history'])===2);});
check('history retains at most 30 snapshots',function()use($postId){for($i=0;$i<40;$i++){$p=Post::find($postId);$p->title='History sample '.$i;ContentWorkspace::capture($p,'post');}assertThat(count(ContentWorkspace::db()->select('SELECT id FROM `content_history` WHERE content_type=? AND content_id=?',['post',$postId]))===30);});
check('server drafts retain at most 10 per account/type',function()use($send,$postId){for($i=0;$i<15;$i++)$send('/admin/posts/autosave',['_token'=>'integration-token','id'=>$postId,'baseline'=>ContentWorkspace::fingerprint(Post::find($postId)),'client_id'=>'tab_limit_'.$i,'title'=>'Draft '.$i,'content'=>Post::find($postId)->content]);assertThat(count(body($send('/admin/posts/workspace',['id'=>$postId],'GET'))['drafts'])===10);});
check('exact draft acknowledgement preserves newer/other-tab drafts',function()use($send,$db,$postId){$row=$db->selectOne('SELECT * FROM `content_drafts` WHERE content_type=? AND content_id=? ORDER BY id DESC LIMIT 1',['post',$postId]);$r=Request::create('POST','/', ['id'=>$postId,'_workspace_client'=>$row->client_id,'_workspace_hash'=>'older-hash']);ContentWorkspace::acknowledge($r,'post');assertThat($db->selectOne('SELECT id FROM `content_drafts` WHERE id=?',[$row->id])!==null);$r=Request::create('POST','/', ['id'=>$postId,'_workspace_client'=>$row->client_id,'_workspace_hash'=>hash('sha256',$row->payload)]);ContentWorkspace::acknowledge($r,'post');assertThat($db->selectOne('SELECT id FROM `content_drafts` WHERE id=?',[$row->id])===null);});
check('lower-trust editors recover drafts without exposed active code',function()use($db,$send,$postId){
 $uid=$db->insert('users',['username'=>'qa_editor','name'=>'Editor','email'=>'editor@example.test','password'=>'test','status'=>'active']);$role=$db->selectOne("SELECT id FROM `roles` WHERE slug='editor'");$db->insert('user_roles',['user_id'=>$uid,'role_id'=>$role->id]);
 $_SESSION['auth_user_id']=$uid; $actor=User::find($uid);$post=Post::find($postId);assertThat($actor->canEditPost($post));
 $prepared=ContentRevision::prepare($post,'post',$actor);assertThat($prepared['token']!=='');
 $draft=$send('/admin/posts/autosave',['_token'=>'integration-token','id'=>$postId,'baseline'=>ContentWorkspace::fingerprint($post),'client_id'=>'tab_editor1','title'=>'Editor draft','content'=>str_replace('Second content','Editor content',$prepared['content']),'_content_revision'=>$prepared['token']]);assertThat(status($draft)===200);
 $workspace=body($send('/admin/posts/workspace',['id'=>$postId],'GET'));assertThat(count($workspace['drafts'])===1,'another account drafts leaked');
 $loaded=body($send('/admin/posts/snapshot',['id'=>$postId,'kind'=>'draft','snapshot_id'=>$workspace['drafts'][0]['id']],'GET'));assertThat(!str_contains($loaded['fields']['content'],'<iframe'));assertThat(str_contains($loaded['fields']['content'],'data-favorite-protected'));assertThat(str_contains(ContentRevision::clean($loaded['fields']['content'],$post,'post',$actor,$loaded['fields']['token']),'<iframe'));
 $_SESSION['auth_user_id']=1;
});
check('outsider/suspended accounts cannot read or autosave protected posts',function()use($db,$send,$postId){
 $uid=$db->insert('users',['username'=>'qa_outsider','name'=>'Outsider','email'=>'outsider@example.test','password'=>'test','status'=>'active']);$_SESSION['auth_user_id']=$uid;
 assertThat(status($send('/admin/posts/workspace',['id'=>$postId],'GET'))===403);
 $db->update('users',['status'=>'suspended'],['id'=>$uid]);assertThat(status($send('/admin/posts/autosave',['_token'=>'integration-token','id'=>$postId]))===302);$_SESSION['auth_user_id']=1;
});
check('customizer preview changes layout/sections without persistent writes',function()use($send,$db){
 $before=$db->select('SELECT * FROM `settings` ORDER BY id');
 $response=$send('/admin/customize/preview',['_token'=>'integration-token','mods'=>['accent_color'=>'#ec4899','site_layout'=>'left','footer_copyright'=>'Unsaved preview footer'],'sections'=>['hero'=>['enabled'=>1],'latest-posts'=>['enabled'=>1]],'section_order'=>['latest-posts','hero']]);
 assertThat(status($response)===200);assertThat(str_contains($response->getContent(),'#ec4899'));assertThat(str_contains($response->getContent(),'layout-left'));assertThat(str_contains($response->getContent(),'Unsaved preview footer'));
 assertThat(json_encode($before)===json_encode($db->select('SELECT * FROM `settings` ORDER BY id')),'preview wrote settings');
 assertThat(status($send('/admin/customize/preview',[],'GET'))===405);
});
check('customizer normal AJAX save remains compatible',function()use($app){$ctrl=new FavoriteCMS\Http\Controllers\Admin\CustomizeController($app);$response=$ctrl->save(Request::create('POST','/',['_ajax'=>1,'mods'=>['accent_color'=>'#2563eb','site_layout'=>'right'],'sections'=>['hero'=>['enabled'=>1],'latest-posts'=>['enabled'=>1]]]));assertThat(body($response)['success']===true);assertThat(Setting::get('theme_mods_default','accent_color')==='#2563eb');});
check('request cache reduces repeated count queries and invalidates after writes/rollback',function()use($db){
 $start=$db->queryCount();Post::countByStatus();Post::countByStatus();Post::countByStatus();assertThat($db->queryCount()-$start===1);
 $before=Post::countByStatus()['all'];$db->beginTransaction();$db->insert('posts',['author_id'=>1,'title'=>'Count fixture','slug'=>'count-fixture','content'=>'','status'=>'draft']);assertThat(Post::countByStatus()['all']===$before+1);$db->rollback();assertThat(Post::countByStatus()['all']===$before);
 Setting::clearCache();$start=$db->queryCount();Setting::get('general','site_name');Setting::get('general','site_url');Setting::get('general','nonexistent','fallback');assertThat($db->queryCount()-$start===1);Setting::set('general','site_name','Updated site name');assertThat(Setting::get('general','site_name')==='Updated site name');
});
$media=null;$originalHash='';
check('grouped settings cache respects nulls, direct updates/deletes and rollback',function()use($db){
 Setting::set('general','nullable',null);assertThat(Setting::get('general','nullable','fallback')===null);
 Setting::get('general','site_name');$db->update('settings',['value'=>'Direct update'],['group_name'=>'general','setting_key'=>'site_name']);assertThat(Setting::get('general','site_name')==='Direct update');
 $db->beginTransaction();$db->update('settings',['value'=>'Transaction'],['group_name'=>'general','setting_key'=>'site_name']);assertThat(Setting::get('general','site_name')==='Transaction');$db->rollback();assertThat(Setting::get('general','site_name')==='Direct update');
 $db->delete('settings',['group_name'=>'general','setting_key'=>'nullable']);assertThat(Setting::get('general','nullable','fallback')==='fallback');
});
check('real raster upload creates smaller variants without altering original',function()use($app,&$media,&$originalHash){
 assertThat(extension_loaded('gd'),'Enable GD for the raster tests');$file=APP_ROOT.'/storage/qa-image.png';$image=imagecreatetruecolor(1600,1000);imagefill($image,0,0,imagecolorallocate($image,210,40,110));imagepng($image,$file);imagedestroy($image);$originalHash=hash_file('sha256',$file);
 $media=(new FavoriteCMS\Services\MediaService($app))->upload(['tmp_name'=>$file,'name'=>'qa-image.png','error'=>UPLOAD_ERR_OK,'size'=>filesize($file)],1,User::find(1));
 assertThat(hash_file('sha256',$media->path)===$originalHash);assertThat($media->getThumbnailUrl(320,240)!==$media->url);assertThat(count(FavoriteCMS\Services\ImageVariants::all($media))===4);
 assertThat(str_contains($media->getResponsiveSrcset(),'320w'));assertThat($media->toPickerArray()['url']===$media->url);foreach(FavoriteCMS\Services\ImageVariants::all($media)as$v){$s=getimagesize($v['path']);assertThat($s[0]===$v['width']);assertThat($s[1]===$v['height']);}
});
check('GIF/SVG/small files and out-of-root paths retain originals',function()use($db){foreach(['image/gif','image/svg+xml']as$mime){$name=$mime==='image/gif'?'qa-original.gif':'qa-original.svg';$file=APP_ROOT.'/public/uploads/'.$name;if($mime==='image/gif'){$image=imagecreatetruecolor(640,480);imagegif($image,$file);imagedestroy($image);}else file_put_contents($file,'<svg xmlns="http://www.w3.org/2000/svg" width="640" height="480"></svg>');$hash=hash_file('sha256',$file);$m=new Media(['url'=>'/uploads/'.$name,'path'=>$file,'mime_type'=>$mime]);assertThat($m->getThumbnailUrl(320,200)===$m->url);assertThat(hash_file('sha256',$file)===$hash);} $small=APP_ROOT.'/public/uploads/qa-small.png';$image=imagecreatetruecolor(200,100);imagepng($image,$small);imagedestroy($image);$m=new Media(['url'=>'/uploads/qa-small.png','path'=>$small,'mime_type'=>'image/png']);assertThat($m->getThumbnailUrl(320,200)===$m->url);$m=new Media(['url'=>'/uploads/outside.png','path'=>APP_ROOT.'/storage/qa-image.png','mime_type'=>'image/png']);assertThat($m->getThumbnailUrl(320,200)===$m->url); });
check('subdirectory responsive URLs remain correct',function()use($media){$GLOBALS['favorite_cms_base_path']='/blog';$set=$media->getResponsiveSrcset();assertThat(str_contains($set,'/blog/uploads/'));$GLOBALS['favorite_cms_base_path']='';});
check('full backup/restore keeps content, private drafts, history and media',function()use($app,$db,$config,$restored,$media,$originalHash){
 $backup=(new FavoriteCMS\Services\BackupService(APP_ROOT.'/storage/qa-backups',APP_ROOT))->createBackup();assertThat(is_file($backup['path']));$service=new FavoriteCMS\Services\RestoreService(APP_ROOT.'/storage/restored-root');$inspection=$service->inspectBackup($backup['path']);assertThat(isset($inspection['manifest']['tables']['qa_content_history']));
 $target=$config;$target['database']=$restored;$target['prefix']='restored_';$result=$service->restoreBackup($backup['path'],$target,null,false);assertThat($result['success']);$copy=new Database($target);assertThat(count($copy->select('SELECT * FROM `content_history`'))===count($db->select('SELECT * FROM `content_history`')));assertThat(count($copy->select('SELECT * FROM `content_drafts`'))===count($db->select('SELECT * FROM `content_drafts`')));
 $row=$copy->selectOne('SELECT * FROM `media` WHERE id=?',[(int)$media->id]);assertThat($row!==null);assertThat(hash_file('sha256',APP_ROOT.'/storage/restored-root/public'.$media->url)===$originalHash);
});
check('deleting media removes derived copies and leaves neighboring files',function()use($media){$variants=FavoriteCMS\Services\ImageVariants::all($media);$neighbor=dirname($media->path).'/qa-neighbor.txt';file_put_contents($neighbor,'keep');$media->delete();assertThat(!is_file($media->path));foreach($variants as$v)assertThat(!is_file($v['path']));assertThat(file_get_contents($neighbor)==='keep');});
file_put_contents(APP_ROOT.'/storage/integration-results.json',json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
foreach($results as$result)echo json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL;
file_put_contents(APP_ROOT.'/storage/qa-databases.json',json_encode(['source'=>$name,'restore'=>$restored]));
$fail=count(array_filter($results,static fn($r)=>$r['status']==='FAIL'));
echo count($results).' integration checks; '.$fail.' failures.'.PHP_EOL;
exit($fail?1:0);