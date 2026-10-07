<?php
/** Build a clean installation/Core-update ZIP with portable forward-slash paths. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=realpath(dirname(__DIR__));$release=json_decode(file_get_contents($root.'/release.json'),true,512,JSON_THROW_ON_ERROR);
$output=$argv[1] ?? dirname($root).'/Favorite-CMS-Universal-v'.$release['version'].'-update.zip';
$zip=new ZipArchive();if($zip->open($output,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Could not create release archive.');
$count=0;
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS))as$file){
 if(!$file->isFile() || $file->isLink())continue;
 $relative=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
 if(preg_match('#^(?:tests|node_modules|\.git|_qa)/#',$relative) || preg_match('#(^|/)\.env(?:$|\.)#',$relative) || preg_match('#(^|/)installed\.lock$#',$relative))continue;
 if(str_starts_with($relative,'storage/') && !in_array(basename($relative),['.gitkeep','.htaccess'],true))continue;
 if(str_starts_with($relative,'public/uploads/') && !in_array(basename($relative),['.gitkeep','.htaccess'],true))continue;
 if(preg_match('#^(?:public/)?plugins/[^/]+/#',$relative))continue;
 if(preg_match('#^(?:public/)?themes/(?!default/)[^/]+/#',$relative) || $relative==='.cms-test-sandbox')continue;
 if(!$zip->addFile($file->getPathname(),$relative))throw new RuntimeException('Could not add release file: '.$relative);$count++;
}
$zip->close();require $root.'/vendor/autoload.php';
$result=(new FavoriteCMS\Services\Update\UpdatePackageValidator())->validate($output);
if(!$result['valid'])throw new RuntimeException('Release failed validation: '.implode('; ',$result['errors']));
echo json_encode(['path'=>$output,'version'=>$result['version'],'root_prefix'=>$result['root_prefix'],'file_count'=>$count,'bytes'=>filesize($output),'sha256'=>hash_file('sha256',$output)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;