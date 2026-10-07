<?php
/** Local presentation smoke tests. All database data lives in memory. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('APP_ROOT', getenv('CMS_QA_ROOT') ?: realpath(dirname(__DIR__)));
define('APP_VERSION', '1.1.0');
define('CMS_NAME', 'Favorite CMS');
require APP_ROOT . '/vendor/autoload.php';
use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;

final class PreviewDatabase extends Database {
    public function query(string $sql, array $bindings = []): PDOStatement {
        if (str_contains($sql,'CREATE TABLE')) {
            $sql = preg_replace('/\b(?:BIGINT|INT)\s+AUTO_INCREMENT PRIMARY KEY/i','INTEGER PRIMARY KEY AUTOINCREMENT',$sql);
            $sql = preg_replace('/ENUM\([^)]*\)/i','TEXT',$sql);
            $sql = preg_replace('/ ON UPDATE CURRENT_TIMESTAMP/i','',$sql);
            $sql = preg_replace('/\) ENGINE=InnoDB[^;]*/i',')',$sql);
            $sql = preg_replace('/^\s*INDEX[^\n]*\n/m','',$sql);
            $sql = preg_replace('/UNIQUE KEY `[^`]+`/i','UNIQUE',$sql);
            $sql = preg_replace('/,\s*\)/',')',$sql);
        }
        if (preg_match("/SHOW COLUMNS FROM `([^`]+)` LIKE '([^']+)'/", $sql, $show)) {
            $sql = "SELECT name FROM pragma_table_info('".$show[1]."') WHERE name='".$show[2]."'";
        }
        $sql = str_replace(' UNSIGNED','',$sql);
        $sql = str_replace('INSERT IGNORE INTO','INSERT OR IGNORE INTO',$sql);
        $sql = str_replace('NOW()','CURRENT_TIMESTAMP',$sql);
        $sql = str_replace('VERSION()', "'SQLite presentation fixture'", $sql);
        return parent::query($sql,$bindings);
    }
}
$app = new Application();
$db = new PreviewDatabase(['driver'=>'sqlite','database'=>':memory:']);
$app->instance(Database::class, $db);
$app->singleton(FavoriteCMS\Core\Config::class, fn()=>new FavoriteCMS\Core\Config());
foreach (glob(APP_ROOT.'/database/migrations/*.php') as $file) {
    if ((int)basename($file)>15 && !in_array((int)basename($file), [18,19], true)) continue;
    $before=get_declared_classes(); require $file;
    $classes=array_values(array_diff(get_declared_classes(),$before));
    if ($classes) (new $classes[0]($db))->up();
}
$uid=$db->insert('users',['username'=>'preview-admin','email'=>'preview@example.test','name'=>'Preview Administrator','password'=>'test-only','status'=>'active','email_verified_at'=>'2026-10-07 00:00:00']);
$role=$db->selectOne("SELECT id FROM roles WHERE slug='super-admin'");
$db->insert('user_roles',['user_id'=>$uid,'role_id'=>$role->id]);
$db->insert('posts',['author_id'=>$uid,'title'=>'A sample article with a long title for layout checks','slug'=>'sample','content'=>'<h2>Sample heading</h2><p>Article text and <a href="#">a link</a>.</p><blockquote>A quotation</blockquote><pre>code example</pre><p style="color:#c026d3">Author-selected color</p>','excerpt'=>'Sample summary.','status'=>'published','type'=>'post','published_at'=>'2026-10-07 00:00:00']);
$db->insert('pages',['author_id'=>$uid,'title'=>'Sample Page','slug'=>'sample-page','content'=>'<h2>Page heading</h2><p>Page content.</p>','status'=>'published']);
$cat=$db->insert('taxonomies',['name'=>'Sample category','slug'=>'sample-category','taxonomy'=>'category','description'=>'Category description']);
$db->insert('post_taxonomies',['post_id'=>1,'taxonomy_id'=>$cat]);
$db->insert('taxonomies',['name'=>'Sample tag','slug'=>'sample-tag','taxonomy'=>'tag']);
$db->insert('comments',['post_id'=>1,'user_id'=>$uid,'author_name'=>'Preview Author','author_email'=>'preview@example.test','content'=>'A sample approved comment.','status'=>'approved']);
$db->insert('media',['filename'=>'sample.png','stored_filename'=>'sample.png','mime_type'=>'image/png','url'=>'/assets/images/Favorite_Web_Icon.png','path'=>'public/assets/images/Favorite_Web_Icon.png','size'=>1234,'uploader_id'=>$uid]);
$db->insert('menus',['name'=>'Preview menu','slug'=>'preview-menu','location'=>'primary']);
$db->insert('menu_items',['menu_id'=>1,'title'=>'Sample page','url'=>'/page/sample-page','type'=>'custom','sort_order'=>0]);
Setting::set('general','site_url','http://localhost');
$_SESSION=['auth_user_id'=>$uid,'auth_user_role'=>'super-admin','_token'=>'presentation-test-token'];
$_SERVER['HTTP_HOST']='localhost'; $_SERVER['REQUEST_URI']='/admin';
$GLOBALS['favorite_cms_base_path']='';
if (defined('CMS_RENDER_FIXTURE_ONLY') && CMS_RENDER_FIXTURE_ONLY) return;
$results=[];
function verify(string $name, callable $render): void {
    global $results;
    $level=ob_get_level();
    try {
        set_error_handler(static function(int $severity,string $message,string $file,int $line): bool {
            if (in_array($severity,[E_DEPRECATED,E_USER_DEPRECATED],true)) return true;
            if (!(error_reporting() & $severity)) return false;
            throw new ErrorException($message,0,$severity,$file,$line);
        });
        $html=$render();
        if (!is_string($html) || !str_contains($html,'</html>')) throw new RuntimeException('Missing full document');
        if (!getenv('CMS_QA_ROOT') && !str_contains($html,'window.favoriteAppearanceConfig')) throw new RuntimeException('Missing shared theme bootstrap');
        if (!getenv('CMS_QA_ROOT') && substr_count($html,'/* Shared appearance controller;')!==1) throw new RuntimeException('Appearance controller duplicated');
        preg_match_all('#<script(?:\s[^>]*)?>(.*?)</script>#s',$html,$scripts);
        $out=__DIR__.(getenv('CMS_QA_ROOT')?'/baseline-renders':'/renders'); if(!is_dir($out))mkdir($out,0777,true);
        $safe=preg_replace('/[^a-z0-9-]/i','-',$name);
        file_put_contents($out.'/'.$safe.'.html',$html);
        foreach($scripts[1] as $i=>$script)if(trim($script)!==''&&!str_starts_with(ltrim($script),'{'))file_put_contents($out.'/'.$safe.'-'.$i.'.js',$script);
        $results[]=['screen'=>$name,'status'=>'PASS','bytes'=>strlen($html)];
    } catch(Throwable $e) {
        while(ob_get_level()>$level)ob_end_clean();
        $results[]=['screen'=>$name,'status'=>'FAIL','error'=>$e->getMessage(),'location'=>basename($e->getFile()).':'.$e->getLine()];
    } finally { restore_error_handler(); }
}
foreach(['light','dark'] as $theme){
 $_COOKIE['favorite_admin_theme']=$theme;
 foreach(['Dashboard','Post','Page','Media','Comment','Menu','User','Theme','Widget','Plugin','Setting','Seo','Customize'] as $name){
  $class='FavoriteCMS\\Http\\Controllers\\Admin\\'.$name.'Controller';
  verify($theme.'-admin-'.$name,fn()=>(new $class($app))->index(Request::create('GET','/admin/'.strtolower($name).'s'))->getContent());
 }
 foreach(['Post','Page','User'] as $name){
  $class='FavoriteCMS\\Http\\Controllers\\Admin\\'.$name.'Controller';
  verify($theme.'-edit-'.$name,fn()=>(new $class($app))->edit(Request::create('GET','/admin/edit?id=1'))->getContent());
 }
 verify($theme.'-profile',fn()=>(new FavoriteCMS\Http\Controllers\Admin\UserController($app))->profile(Request::create())->getContent());
 $frontend=new FavoriteCMS\Http\Controllers\FrontendController($app);
 verify($theme.'-home',fn()=>$frontend->home(Request::create())->getContent());
 verify($theme.'-post',fn()=>$frontend->post(Request::create(),'sample')->getContent());
 verify($theme.'-page',fn()=>$frontend->page(Request::create(),'sample-page')->getContent());
 verify($theme.'-search',fn()=>$frontend->search(Request::create('GET','/search?q=sample'))->getContent());
 foreach(['categories','tags'] as $method) verify($theme.'-admin-'.$method,fn()=>(new FavoriteCMS\Http\Controllers\Admin\TaxonomyController($app))->$method(Request::create())->getContent());
 verify($theme.'-admin-tools',fn()=>(new FavoriteCMS\Http\Controllers\Admin\ToolController($app))->index(Request::create())->getContent());
 verify($theme.'-admin-import',fn()=>(new FavoriteCMS\Http\Controllers\Admin\ToolController($app))->importIndex(Request::create())->getContent());
 verify($theme.'-admin-updates',static function(){
  $currentVersion=APP_VERSION;$discovery=['update_available'=>false,'latest_version'=>APP_VERSION,'release_url'=>'https://example.test/releases'];$extensionUpdates=[];$health=['checks'=>[],'can_update'=>true,'passed'=>true];$state=[];$logs=[];$pendingPackage=null;$inProgress=false;$notice=null;$error=null;$csrfToken=$_SESSION['_token'];$contentView=APP_ROOT.'/resources/views/admin/updates/index.php';
  ob_start();include APP_ROOT.'/resources/views/admin/layout.php';return ob_get_clean();
 });
 verify($theme.'-category',fn()=>$frontend->category(Request::create(),'sample-category')->getContent());
 verify($theme.'-tag',fn()=>$frontend->tag(Request::create(),'sample-tag')->getContent());
 verify($theme.'-not-found',fn()=>$frontend->post(Request::create(),'does-not-exist')->getContent());
 verify($theme.'-all-widgets',static function(){
  ob_start(); include APP_ROOT.'/themes/default/header.php';
  $registry=FavoriteCMS\Widgets\WidgetRegistry::getInstance();$registry->ensureBooted();
  echo '<main class="container"><div class="sidebar">';
  foreach($registry->all() as $widget){
   $settings=['title'=>$widget->getName(),'post_id'=>1,'menu_id'=>1,'image_url'=>'/assets/images/Favorite_Web_Icon.png','content'=>'<p>Custom HTML content</p>','show_thumb'=>true];
   $output=$widget->render($settings);
   if(trim($output)==='')throw new RuntimeException('Empty widget: '.$widget->getId());
   echo $output;
  }
  echo '</div></main>';include APP_ROOT.'/themes/default/footer.php';return ob_get_clean();
 });
 foreach(['login','register','resend-verification','verification-result','logout','password-recovery','password-reset'] as $auth){
  verify($theme.'-auth-'.$auth,static function()use($auth){
   ['e'=>$e,'field'=>$field]=require APP_ROOT.'/resources/views/partials/standalone/view-helpers.php';
   $url=static fn(string $p):string=>site_path($p);$siteName='Preview CMS';$token=$_SESSION['_token'];$redirect=null;$error='';$flash='';$oldLogin='';$registrationEnabled=true;$old=[];$success=true;$message='Sample account status';$notice='';$reset=$auth==='password-reset';$logoutAction='/logout';$email='preview@example.test';
   $view=$reset?'password-recovery':$auth;
   ob_start();include APP_ROOT.'/resources/views/auth/'.$view.'.php';$content=ob_get_clean();
   ob_start();include APP_ROOT.'/resources/views/auth/layout.php';return ob_get_clean();
  });
 }
 foreach(['environment','advanced','restore'] as $mode){
  verify($theme.'-installer-'.$mode,static function()use($mode){
   $checks=[['name'=>'PHP','label'=>'PHP','status'=>'pass','message'=>'Available']];$dbDefaults=['setup_mode'=>$mode==='restore'?'advanced':$mode];$old=[];$errors=[];$notices=[];$errorGroups=[];$token=$_SESSION['_token'];$installAction='/install';$detectedUrl='http://localhost';$hasRequirementFailures=false;$dbStatus=[];$formMode=$mode==='restore'?'restore':'install';
   ob_start();include APP_ROOT.'/resources/views/installer/install.php';return ob_get_clean();
  });
 }
 verify($theme.'-installer-success',static function(){
  $siteName='Preview CMS';$siteUrl='http://localhost';$adminUsername='preview-admin';$adminEmail='preview@example.test';$migrations=[];$loginUrl='/admin/login';$homeUrl='/';
  ob_start();include APP_ROOT.'/resources/views/installer/success.php';return ob_get_clean();
 });
 foreach(['index','archive','search','single','page','404'] as $screen){
  verify($theme.'-fallback-'.$screen,static function()use($screen){
   $posts=FavoriteCMS\Models\Post::published(); $post=$posts[0]; $page=FavoriteCMS\Models\Page::find(1); $currentPage=1;$totalPages=1;$totalPosts=1;$isHome=true;$searchQuery='sample';
   ob_start();include APP_ROOT.'/resources/views/'.$screen.'.php';return ob_get_clean();
  });
 }
}
file_put_contents(__DIR__.(getenv('CMS_QA_ROOT')?'/baseline-results.json':'/render-results.json'),json_encode($results,JSON_PRETTY_PRINT));
foreach($results as $result)echo json_encode($result,JSON_UNESCAPED_SLASHES).PHP_EOL;
$failures=count(array_filter($results,fn($r)=>$r['status']==='FAIL'));
echo count($results).' screens checked; '.$failures.' failures.'.PHP_EOL;
exit($failures?1:0);