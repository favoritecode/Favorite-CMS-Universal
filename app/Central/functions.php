<?php
declare(strict_types=1);
if(!function_exists('favorite_license_entitlement')){
 function favorite_license_entitlement(string $pluginId,bool $refresh=false):array{
  if(!preg_match('/^[a-z0-9][a-z0-9_-]{1,99}$/',$pluginId))return ['allowed'=>false,'reason'=>'invalid_product'];
  $path=APP_ROOT.'/plugins/'.$pluginId.'/plugin.json';if(!is_file($path))return ['allowed'=>false,'reason'=>'plugin_not_installed'];
  $manifest=json_decode((string)file_get_contents($path),true);if(!is_array($manifest))return ['allowed'=>false,'reason'=>'invalid_manifest'];
  $manifest['id']=$pluginId;return (new \FavoriteCMS\Central\Client())->plugin($manifest,$refresh);
 }
}
if(!function_exists('favorite_license_has_feature')){
 function favorite_license_has_feature(string $pluginId,string $feature):bool{
  $state=favorite_license_entitlement($pluginId);
  return !empty($state['allowed'])&&(($state['mode']??'')==='legacy_free'||in_array($feature,(array)($state['features']??[]),true));
 }
}

if(!function_exists('favorite_theme_entitlement')){
 function favorite_theme_entitlement(string $themeId,bool $refresh=false):array{
  if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/',$themeId))return ['allowed'=>false,'reason'=>'invalid_theme'];
  $path=APP_ROOT.'/themes/'.$themeId.'/theme.json';if(!is_file($path))return ['allowed'=>false,'reason'=>'theme_not_installed'];
  $manifest=json_decode((string)file_get_contents($path),true);if(!is_array($manifest))return ['allowed'=>false,'reason'=>'invalid_manifest'];$manifest['id']=$themeId;return (new \FavoriteCMS\Central\Client())->theme($manifest,$refresh);
 }
}
if(!function_exists('favorite_theme_has_feature')){
 function favorite_theme_has_feature(string $themeId,string $feature):bool{$state=favorite_theme_entitlement($themeId);return !empty($state['allowed'])&&($themeId==='default'||($state['mode']??'')==='legacy_free'||in_array($feature,(array)($state['features']??[]),true));}
}
