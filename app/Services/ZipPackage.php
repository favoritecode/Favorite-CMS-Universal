<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
use FavoriteCMS\Core\Exceptions\SecurityException;
use ZipArchive;

/** One canonical path map for validation, lookup and extraction on Windows/Linux. */
final class ZipPackage
{
    public static function entries(ZipArchive $zip): array {
        $entries=[]; $seen=[];
        for($i=0;$i<$zip->numFiles;$i++) {
            $stat=$zip->statIndex($i); $raw=(string)($stat['name'] ?? ''); $name=str_replace('\\','/',$raw);
            if($name==='' || str_contains($name,"\0") || str_starts_with($name,'/') || preg_match('/^[A-Za-z]:/',$name) || preg_match('#(^|/)\.\.?(/|$)#',$name))throw new SecurityException('Unsafe archive path: '.$name);
            if(preg_match('/[\x00-\x1F]/',$name) || str_contains($name,':'))throw new SecurityException('Unsupported archive path: '.$name);
            $key=strtolower(rtrim($name,'/')); if(isset($seen[$key]))throw new SecurityException('Duplicate archive path: '.$name); $seen[$key]=true;
            $opsys=0;$attributes=0;
            if($zip->getExternalAttributesIndex($i,$opsys,$attributes) && (($attributes>>16)&0170000)===0120000)throw new SecurityException('Archive symlinks are not supported: '.$name);
            $entries[$name]=['index'=>$i,'raw'=>$raw,'size'=>(int)($stat['size'] ?? 0),'directory'=>str_ends_with($name,'/')];
        }
        return $entries;
    }
    public static function extract(ZipArchive $zip,string $destination,array $entries, string $prefix=''): void {
        if(!is_dir($destination) && !mkdir($destination,0755,true))throw new \RuntimeException('Could not create package staging directory.');
        $root=realpath($destination); if(!$root)throw new \RuntimeException('Invalid staging directory.');
        foreach($entries as$name=>$entry) {
            if($prefix!=='' && !str_starts_with($name,$prefix.'/'))continue;
            $relative=$prefix!==''?substr($name,strlen($prefix)+1):$name;
            if($relative==='')continue;
            $target=$root.'/'.rtrim($relative,'/');$parent=$entry['directory']?$target:dirname($target);
            if(!is_dir($parent) && !mkdir($parent,0755,true) && !is_dir($parent))throw new \RuntimeException('Could not create archive directory: '.$relative);
            $realParent=realpath($parent);
            if(!$realParent || !str_starts_with(strtolower(str_replace('\\','/',$realParent)).'/',strtolower(str_replace('\\','/',$root)).'/'))throw new SecurityException('Archive destination escapes staging directory.');
            if($entry['directory'])continue;
            if(is_link($target))throw new SecurityException('Archive destination is a symlink.');
            $input=$zip->getStream($entry['raw']);$output=fopen($target,'wb');
            if(!$input || !$output) { if(is_resource($input))fclose($input);if(is_resource($output))fclose($output);throw new \RuntimeException('Could not extract archive file: '.$relative); }
            try {$copied=stream_copy_to_stream($input,$output);if($copied!==$entry['size'])throw new \RuntimeException('Incomplete archive file: '.$relative);}finally{fclose($input);fclose($output);}
        }
    }
}