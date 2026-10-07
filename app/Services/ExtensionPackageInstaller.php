<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
use ZipArchive;
use RuntimeException;

/** Validate type and structure before writing; commit a staged install with rollback. */
final class ExtensionPackageInstaller
{
    public static function install(array $upload,string $type,string $baseDirectory): array {
        $file=(string)($upload['tmp_name'] ?? '');
        if(($upload['error'] ?? UPLOAD_ERR_OK)!==UPLOAD_ERR_OK || $file==='' || (!is_uploaded_file($file) && (PHP_SAPI!=='cli' || !is_file($file))))throw new RuntimeException('No valid ZIP file uploaded.');
        $zip=new ZipArchive();if($zip->open($file,ZipArchive::RDONLY)!==true)throw new RuntimeException('Could not open ZIP archive.');
        $stage=null;$backup=null;$committed=false;$lock=null;
        try {
            $entries=ZipPackage::entries($zip);
            $files=array_filter(array_keys($entries),static fn($name)=>!str_ends_with($name,'/') && !str_starts_with($name,'__MACOSX/') && basename($name)!=='.DS_Store');
            if(!$files)throw new RuntimeException('The ZIP archive is empty.');
            $manifests=[];
            foreach($files as$name)if(in_array(basename($name),['theme.json','plugin.json'],true))$manifests[]=$name;
            usort($manifests,static fn($a,$b)=>substr_count($a,'/')<=>substr_count($b,'/'));
            $manifest=[];$prefix='';
            if($manifests) {
                $candidate=$manifests[0];$depth=substr_count($candidate,'/');
                $primary=array_values(array_filter($manifests,static fn($name)=>substr_count($name,'/')===$depth));
                if(count($primary)!==1)throw new RuntimeException('This archive contains multiple or mixed extensions. Upload one theme or plugin at a time.');
                $found=basename($candidate)==='theme.json'?'theme':'plugin';
                if($found!==$type)throw new RuntimeException('This is a '.$found.' ZIP. Upload it under '.($found==='theme'?'Themes':'Plugins').', not '.($type==='theme'?'Themes':'Plugins').'.');
                $raw=$zip->getFromIndex($entries[$candidate]['index']);if(strlen((string)$raw)>1024*1024)throw new RuntimeException('Extension manifest is too large.');
                $manifest=json_decode((string)$raw,true);
                if(!is_array($manifest) || !$manifest)throw new RuntimeException('Invalid '.$type.'.json manifest.');
                if(isset($manifest['type']) && in_array($manifest['type'], ['theme','plugin','core'], true) && $manifest['type']!==$type)throw new RuntimeException('Extension manifest type does not match this upload screen.');
                $prefix=dirname($candidate)==='.'?'':dirname($candidate);
                if(isset($entries[($prefix!==''?$prefix.'/':'').($type==='theme'?'plugin.json':'theme.json')]))throw new RuntimeException('Ambiguous theme/plugin package.');
            } elseif($type==='theme') {
                // Legacy themes without metadata remain installable if they contain an index template.
                foreach($files as$name)if(basename($name)==='plugin.php')throw new RuntimeException('This looks like a plugin ZIP. Upload it under Plugins with its plugin.json manifest.');
                $indexes=array_values(array_filter($files,static fn($name)=>basename($name)==='index.php'));
                usort($indexes,static fn($a,$b)=>substr_count($a,'/')<=>substr_count($b,'/'));
                if(!$indexes)throw new RuntimeException('Invalid theme ZIP: missing index.php template.');
                $candidate=$indexes[0];$prefix=dirname($candidate)==='.'?'':dirname($candidate);
            } else throw new RuntimeException('Invalid plugin ZIP: missing plugin.json manifest.');
            $root=$prefix!==''?$prefix.'/':'';
            foreach($files as$name) {
                if($prefix!=='' && !str_starts_with($name,$root) && !preg_match('#^(?:README[^/]*|LICENSE[^/]*|CHANGELOG[^/]*|\.gitignore)$#i',$name))throw new RuntimeException('Archive has files outside the extension folder. Upload a single extension package.');
            }
            $entry=$type==='theme'?'index.php':(string)($manifest['entry_point'] ?? 'plugin.php');
            if($entry==='' || str_contains($entry,'\\') || preg_match('#(^|/)\.\.?(/|$)#',$entry) || str_starts_with($entry,'/') || str_contains($entry,':') || !str_ends_with(strtolower($entry),'.php'))throw new RuntimeException('Invalid extension entry point.');
            if(!isset($entries[$root.$entry]) || $entries[$root.$entry]['directory'])throw new RuntimeException('Invalid '.$type.' ZIP: missing required '.$entry.'.');
            if(isset($entries[$root.'bootstrap.php'],$entries[$root.'app/Core/Application.php']))throw new RuntimeException('This is a CMS Core package. Use Core Updates instead.');
            $fallback=$prefix!==''?basename($prefix):pathinfo((string)($upload['name'] ?? $type),PATHINFO_FILENAME);
            $fallback=preg_replace('/[^A-Za-z0-9_-]/','',(string)$fallback);
            $id=(string)($manifest['id'] ?? ($fallback!==''?$fallback:$type.'_'.substr(hash_file('sha256',$file),0,12)));
            if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/D',$id))throw new RuntimeException('Extension ID must contain only letters, numbers, underscores and hyphens.');
            if(!is_dir($baseDirectory) && !mkdir($baseDirectory,0755,true))throw new RuntimeException('Could not create extension directory.');
            $base=realpath($baseDirectory);$target=$base.'/'.$id;
            $lock=fopen($base.'/.install.lock','c');if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another extension installation is in progress. Retry shortly.');
            $stage=$base.'/.install-'.bin2hex(random_bytes(8));if(!mkdir($stage,0755))throw new RuntimeException('Could not create extension staging directory.');
            if(file_exists($target)) {
                if(!is_dir($target) || is_link($target) || strtolower(str_replace('\\','/',(string)realpath($target)))!==strtolower(str_replace('\\','/',$target)))throw new RuntimeException('Existing extension directory is not safe to replace.');
                self::copy($target,$stage); // Preserve existing extra files/data while replacing package files.
            }
            ZipPackage::extract($zip,$stage,$entries,$prefix);
            if(file_exists($target)) {
                $backup=$base.'/.previous-'.bin2hex(random_bytes(8));
                if(!rename($target,$backup))throw new RuntimeException('Could not preserve the existing extension before replacement.');
            }
            if(!rename($stage,$target)) { if($backup && !rename($backup,$target))throw new RuntimeException('Installation failed; previous extension is preserved at '.$backup);$backup=null;throw new RuntimeException('Could not commit extension installation. Previous version was restored.'); }
            $stage=null;$committed=true;
            if(function_exists('opcache_invalidate'))foreach($entries as$name=>$entryInfo)if(str_ends_with($name,'.php') && ($prefix==='' || str_starts_with($name,$root)))@opcache_invalidate($target.'/'.($prefix!==''?substr($name,strlen($root)):$name),true);
            return ['id'=>$id,'manifest'=>$manifest];
        } finally {
            $zip->close();
            if($stage && is_dir($stage))self::remove($stage);
            if($committed && $backup && is_dir($backup))self::remove($backup);
            if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
        }
    }
    private static function copy(string $source,string $destination): void {
        foreach(new \DirectoryIterator($source)as$item) {
            if($item->isDot())continue;
            if($item->isLink())throw new RuntimeException('Existing extension contains a symlink; installation was not changed.');
            $next=$destination.'/'.$item->getFilename();
            if($item->isDir()){if(!mkdir($next,0755))throw new RuntimeException('Could not stage existing extension.');self::copy($item->getPathname(),$next);}elseif(!copy($item->getPathname(),$next))throw new RuntimeException('Could not preserve existing extension files.');
        }
    }
    private static function remove(string $directory): void {
        foreach(new \DirectoryIterator($directory)as$item){if($item->isDot())continue;$path=$item->getPathname();if($item->isDir() && !$item->isLink())self::remove($path);else @unlink($path);}@rmdir($directory);
    }
}