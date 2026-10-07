<?php
/** Router for localhost screenshot fixtures; excluded from installation/update ZIPs. */
declare(strict_types=1);
if(PHP_SAPI!=='cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true)){http_response_code(404);exit;}
$root=dirname(__DIR__);$renders=getenv('CMS_TEST_RENDER_DIR') ?: __DIR__.'/renders';$uri=rawurldecode((string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
if($uri==='/')$uri='/screens/'.(($_COOKIE['favorite_admin_theme'] ?? 'dark')==='light'?'light':'dark').'-home.html';
if(preg_match('#^/screens/([a-z0-9-]+\.html)$#i',$uri,$match)){$file=$renders.'/'.$match[1];if(is_file($file)){header('Content-Type: text/html; charset=UTF-8');readfile($file);exit;}}
if(preg_match('#\.(css|js|png|jpg|jpeg|gif|webp|ico|svg|woff2?|ttf)$#i',$uri,$match)){
 foreach([$root.'/public',$root]as$base){$file=realpath($base.$uri);if(!$file || !str_starts_with(str_replace('\\','/',$file),str_replace('\\','/',realpath($base)).'/'))continue;if(is_file($file)){$types=['css'=>'text/css','js'=>'text/javascript','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp','ico'=>'image/x-icon','svg'=>'image/svg+xml','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf'];header('Content-Type: '.$types[strtolower($match[1])]);readfile($file);exit;}}
}
http_response_code(404);header('Content-Type: application/json');echo json_encode(['success'=>false,'error'=>'This server serves read-only test fixtures. Use a disposable installed CMS for save/upload tests.']);