<?php
/** Run the installed 1.0.2 updater itself against the new release. Isolated copy and local DB only. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=getenv('CMS_TEST_ROOT');$zip=getenv('CMS_TEST_UPDATE_ZIP');
if(!$root || !is_file($root.'/.cms-test-sandbox') || !$zip || !is_file($zip) || realpath($root)===realpath(dirname(__DIR__)))throw new RuntimeException('Set a marked disposable old-CMS copy and update ZIP.');
define('APP_ROOT',realpath($root));$app=require APP_ROOT.'/bootstrap.php';
if(APP_VERSION!=='1.0.2')throw new RuntimeException('Start from an untouched 1.0.2 copy.');
use FavoriteCMS\Core\Database;use FavoriteCMS\Models\Setting;
$config=['driver'=>'mysql','host'=>'127.0.0.1','port'=>getenv('CMS_TEST_DB_PORT') ?: '3306','username'=>getenv('CMS_TEST_DB_USER') ?: 'root','password'=>getenv('CMS_TEST_DB_PASSWORD') ?: '','charset'=>'utf8mb4','prefix'=>'upgrade_'];
$server=new PDO('mysql:host='.$config['host'].';port='.$config['port'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$name='favorite_upgrade_'.bin2hex(random_bytes(6));$server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$config['database']=$name;
$install=(new FavoriteCMS\Installer\InstallationService($app,new FavoriteCMS\Installer\DatabaseProvisioner(),new FavoriteCMS\Installer\InstallationStateManager()))->install($config,['name'=>'Existing website','url'=>'http://127.0.0.1:8767'],['username'=>'old_admin','name'=>'Existing Admin','email'=>'old@example.test','password'=>'Local-Upgrade-Only-2026!']);
$db=$app->make(Database::class);$_SESSION=['auth_user_id'=>1,'auth_user_name'=>'Existing Admin'];
$content='<p style="color:#c026d3">Keep this exact content</p><iframe src="https://example.test/embed"></iframe>';
$id=$db->insert('posts',['title'=>'Existing article','slug'=>'existing-article','content'=>$content,'author_id'=>1,'status'=>'published','type'=>'post']);
$db->insert('pages',['title'=>'Existing page','slug'=>'existing-page','content'=>'<p>Existing page</p>','author_id'=>1,'status'=>'published']);Setting::set('admin_appearance','user_1','light');Setting::set('theme_mods_default','accent_color','#dc2626');
mkdir(APP_ROOT.'/themes/custom-keep');file_put_contents(APP_ROOT.'/themes/custom-keep/index.php','<?php // preserve custom theme');mkdir(APP_ROOT.'/plugins/custom-keep');file_put_contents(APP_ROOT.'/plugins/custom-keep/plugin.php','<?php // preserve custom plugin');file_put_contents(APP_ROOT.'/public/uploads/keep-original.txt','original-upload');
$envHash=hash_file('sha256',APP_ROOT.'/.env');$before=$db->select('SELECT * FROM `posts` ORDER BY id');$users=$db->select('SELECT * FROM `users` ORDER BY id');$pages=$db->select('SELECT * FROM `pages` ORDER BY id');
$manager=new FavoriteCMS\Services\Update\UpdateManager($app);$validation=$manager->getValidator()->validate($zip);if(!$validation['valid'])throw new RuntimeException('Old validator rejected release: '.implode('; ',$validation['errors']));
$result=$manager->runUpdate($zip,['expected_sha256'=>hash_file('sha256',$zip)]);
$checks=[
 'old 1.0.2 validator accepts release'=>$validation['valid'],
 'actual old updater completes to 1.1.0'=>($result['success'] && $result['previous_version']==='1.0.2' && $result['updated_version']==='1.1.0'),
 'new migration runs through old loaded Database prefix handling'=>count($result['applied_migrations'])===2 && in_array('019_create_seo_automation', $result['applied_migrations'], true) && $db->tableExists('content_drafts') && $db->tableExists('content_history') && $db->tableExists('seo_redirects'),
 'existing posts and embedded content remain exact'=>json_encode($before)===json_encode($db->select('SELECT * FROM `posts` ORDER BY id')),
 'existing accounts and pages remain exact'=>json_encode($users)===json_encode($db->select('SELECT * FROM `users` ORDER BY id')) && json_encode($pages)===json_encode($db->select('SELECT * FROM `pages` ORDER BY id')),
 'existing config and media remain exact'=>hash_file('sha256',APP_ROOT.'/.env')===$envHash && file_get_contents(APP_ROOT.'/public/uploads/keep-original.txt')==='original-upload',
 'custom themes/plugins remain exact'=>file_get_contents(APP_ROOT.'/themes/custom-keep/index.php')==='<?php // preserve custom theme' && file_get_contents(APP_ROOT.'/plugins/custom-keep/plugin.php')==='<?php // preserve custom plugin',
 'automatic backup exists'=>is_file(APP_ROOT.'/storage/backups/'.$result['backup_file']),
 'maintenance and update locks are released'=>!$manager->getMaintenance()->isActive() && !$manager->isUpdateInProgress(),
 'new core metadata and feature files installed'=>json_decode(file_get_contents(APP_ROOT.'/release.json'),true)['version']==='1.1.0' && is_file(APP_ROOT.'/app/Services/ContentWorkspace.php') && is_file(APP_ROOT.'/resources/views/admin/customize/enhancements.js') && is_file(APP_ROOT.'/app/Services/ExtensionPackageInstaller.php'),
];
file_put_contents(APP_ROOT.'/storage/update-results.json',json_encode(['result'=>$result,'checks'=>$checks,'database'=>$name],JSON_PRETTY_PRINT));foreach($checks as$test=>$passed)echo json_encode(['test'=>$test,'status'=>$passed?'PASS':'FAIL']).PHP_EOL;echo count($checks).' update checks; '.count(array_filter($checks,static fn($v)=>!$v)).' failures.'.PHP_EOL;exit(in_array(false,$checks,true)?1:0);