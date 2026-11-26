<?php
declare(strict_types=1);
namespace FavoriteCMS\Security;
/** Signed release manifest. Checks official CMS source only, not site content/config/customization. */
final class CoreIntegrity {
 private const PUBLIC_KEY_B64 = 'vLAT9ZGSIoqUt0XRndW87gY2J0vP9ew/piPKBWywo64=';
 public static function check(string $root):void {
  $path=self::requestPath($_SERVER);
  // Keep the owner, recovery, verification and installer available when a protected file changes.
  if(PHP_SAPI==='cli'||preg_match('~^/(?:admin|install)(?:/|$)~',$path)||in_array($path,['/forgot-password','/reset-password','/.well-known/favorite-central-live','/favorite-central-live','/favorite-central-verify'],true))return;
  if(self::report($root)['status']==='modified')self::notice();
 }
 /** Match the front controller's subfolder/public-directory routing before autoload is available. */
 public static function requestPath(array $server):string{
  $path='/'.ltrim(str_replace('\\','/',(string)(parse_url((string)($server['REQUEST_URI']??'/'),PHP_URL_PATH)??'/')),'/');$path=preg_replace('~/+~','/',$path)?:'/';
  $script=str_replace('\\','/',(string)($server['SCRIPT_NAME']??'/index.php'));$dir=rtrim(str_replace('\\','/',dirname($script)),'/.');$publicless=str_ends_with($dir,'/public')?substr($dir,0,-7):$dir;$candidates=array_unique([$dir,$publicless]);usort($candidates,static fn($a,$b)=>strlen($b)<=>strlen($a));
  foreach($candidates as$base)if($base!==''&&$base!=='/'&&($path===$base||str_starts_with($path,$base.'/')))return substr($path,strlen($base))?:'/';
  return $path;
 }
 /** Local self-attestation; hosting owners can alter PHP, so the server labels this as reported, not independently proven. */
 public static function report(string $root):array {
  if(!function_exists('sodium_crypto_sign_verify_detached'))return ['status'=>'unavailable','file_count'=>0];
  $manifestFile=$root.'/app/Security/core-integrity.json';
  $hash=is_file($manifestFile)?hash_file('sha256',$manifestFile):'';
  try {
   $raw=@file_get_contents($manifestFile);
   if(!is_string($raw)||$raw==='')throw new \RuntimeException('manifest_missing');
   $manifest=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
   if(!is_array($manifest)||!isset($manifest['files'],$manifest['signature'])||!is_array($manifest['files']))throw new \RuntimeException('manifest_invalid');
   $files=$manifest['files'];$sign=base64_decode((string)$manifest['signature'],true);
   $public=base64_decode(self::PUBLIC_KEY_B64,true);
   $message=json_encode($files,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
   if(!is_string($sign)||strlen($sign)!==SODIUM_CRYPTO_SIGN_BYTES||!is_string($public)||strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||!sodium_crypto_sign_verify_detached($sign,$message,$public))throw new \RuntimeException('manifest_signature');
   foreach($files as $name=>$digest){
    if(!is_string($name)||!preg_match('~^(?:app|resources|public|vendor|config)/[A-Za-z0-9_./-]+\.php$|^(?:bootstrap|index|migrate)\.php$~D',$name)||str_contains($name,'..')||!is_string($digest)||!preg_match('/^[a-f0-9]{64}$/D',$digest))throw new \RuntimeException('manifest_path');
    $actual=@hash_file('sha256',$root.'/'.$name);
    if(!is_string($actual)||!hash_equals($digest,$actual))return ['status'=>'modified','reason'=>is_string($actual)?'file_integrity':'file_missing','failed_file'=>$name,'manifest_sha256'=>$hash,'file_count'=>count($files)];
   }
   return ['status'=>'verified','manifest_sha256'=>$hash,'file_count'=>count($files)];
  }catch(\Throwable $e){return ['status'=>'modified','reason'=>in_array($e->getMessage(),['manifest_missing','manifest_invalid','manifest_signature','manifest_path'],true)?$e->getMessage():'verification_error','manifest_sha256'=>$hash,'file_count'=>0];}
 }
 private static function notice():never {
  $raw='/'.ltrim((string)(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)??'/'),'/');$raw=preg_replace('~/+~','/',$raw)?:'/';$route=self::requestPath($_SERVER);$base=$route==='/'?rtrim($raw,'/'):(str_ends_with($raw,$route)?substr($raw,0,strlen($raw)-strlen($route)):'');$login=htmlspecialchars($base.'/admin/login?safe_repair=1',ENT_QUOTES,'UTF-8');

  if(!headers_sent()){
   http_response_code(503);header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store, private');header('X-Robots-Tag: noindex, nofollow');
  }
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Official CMS Verification | Favorite CMS</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;font:16px/1.6 system-ui,-apple-system,Segoe UI,sans-serif;color:#192b48;background:radial-gradient(circle at 20% 15%,#dbeafe,transparent 42%),linear-gradient(135deg,#fafcff,#edf4ff)}main{width:min(100%,610px);padding:clamp(26px,6vw,55px);border:1px solid #dce7f7;border-radius:24px;background:white;box-shadow:0 26px 85px #123f771b}.brand{font-weight:800;letter-spacing:.01em;color:#185ac3}.icon{display:grid;place-items:center;margin:28px 0 20px;width:60px;height:60px;border-radius:17px;background:#fff3e5;color:#a76219;font-size:29px}h1{font-size:clamp(25px,5vw,37px);line-height:1.18;margin:0 0 17px}p{color:#51647e;margin:0 0 16px}.note{margin-top:24px;padding:19px 20px;border-radius:14px;background:#f5f8fe;border:1px solid #e5eefa}.note strong{display:block;color:#1c3556;margin-bottom:6px}a{color:#145bc8;font-weight:700;text-decoration:none}a:focus-visible{outline:3px solid #3b82f6;outline-offset:3px}footer{margin-top:26px;font-size:12px;color:#8a9ab0}</style></head><body><main><div class="brand">◆ Favorite CMS <span style="font-weight:500;color:#75869e">Official Edition</span></div><div class="icon" aria-hidden="true">!</div><h1>Official CMS verification required</h1><p>The protected Favorite CMS source files do not match the verified official release.</p><div class="note"><strong>For the website administrator</strong><p>Please restore the unmodified official Favorite CMS package using your admin update or recovery tools. Your pages, themes, plugins, settings and uploads are not removed by this check.</p><a href="'.$login.'">Administrator sign in</a> &nbsp;·&nbsp; <a href="https://favoriteweb.net" rel="noopener noreferrer">Official Favorite CMS</a></div><footer>Favorite CMS · Core integrity protection</footer></main></body></html>';
  exit;
 }
}
