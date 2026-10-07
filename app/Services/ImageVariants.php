<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
use FavoriteCMS\Models\Media;

/** Derived raster files only: never resizes, re-encodes or replaces an original. */
final class ImageVariants
{
    private static array $memo = [];
    private static function contained(string $file, string $root): bool {
        $file = str_replace('\\', '/', $file); $root = str_replace('\\', '/', $root);
        if (DIRECTORY_SEPARATOR === '\\') { $file = strtolower($file); $root = strtolower($root); }
        return str_starts_with($file, $root . '/');
    }
    private static function source(Media $media): ?string {
        if (!defined('APP_ROOT')) return null;
        $root=realpath(APP_ROOT . '/public/uploads'); $file=realpath((string)$media->path);
        if (!$root || !$file || !is_file($file) || !self::contained($file, $root)) return null;
        if (!preg_match('#^/?uploads/[^?\#]+$#D',(string)$media->url) && !preg_match('#/uploads/[^?\#]+$#D',(string)$media->url)) return null;
        return $file;
    }
    public static function all(Media $media): array {
        $file=self::source($media);
        if (!$file || !function_exists('imagecreatetruecolor') || !in_array($media->mime_type,['image/jpeg','image/png','image/webp'],true)) return [];
        $stat=@stat($file); if (!$stat) return []; $key=$file.':'.$stat['mtime'].':'.$stat['size'];
        if (isset(self::$memo[$key])) return self::$memo[$key];
        self::$memo[$key]=[];
        $info=@getimagesize($file);
        if (!$info || $info[0]<320 || $info[0]*$info[1]>12000000) return [];
        $header=(string)@file_get_contents($file,false,null,0,65536);
        if (str_contains($header,'ANIM') || ($media->mime_type==='image/png' && str_contains($header,'acTL'))) return [];
        // Preserve EXIF orientation by leaving oriented originals to the browser.
        if ($media->mime_type==='image/jpeg' && str_contains($header,"Exif\0\0")) {
            if (!function_exists('exif_read_data')) return [];
            $exif=@exif_read_data($file);
            if (($exif['Orientation'] ?? 1) != 1) return [];
        }
        $limit=ini_get('memory_limit'); $bytes=$limit==='-1'?PHP_INT_MAX:(int)$limit*match(strtolower(substr($limit,-1))){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};
        if ($info[0]*$info[1]*8+16*1024*1024 > $bytes-memory_get_usage(true)) return [];
        $extension=function_exists('imagewebp')?'webp':($media->mime_type==='image/png'?'png':'jpg');
        $directory=dirname($file).'/.fc-thumbnails/'.substr(hash('sha256',basename($file)),0,16);
        $fingerprint=substr(hash('sha256',$stat['mtime'].':'.$stat['size'].':v1'),0,12);
        $source=null; $variants=[];
        try {
            foreach([320,640,960,1280] as $width) {
                if($width >= $info[0]) continue;
                $height=max(1,(int)round($info[1]*$width/$info[0]));
                $target=$directory.'/'.$width.'-'.$fingerprint.'.'.$extension;
                if (!is_file($target)) {
                    if (!$source) {
                        $source=match($media->mime_type){'image/jpeg'=>@imagecreatefromjpeg($file),'image/png'=>@imagecreatefrompng($file),'image/webp'=>function_exists('imagecreatefromwebp')?@imagecreatefromwebp($file):false};
                        if(!$source) return [];
                    }
                    if(!is_dir($directory) && !@mkdir($directory,0755,true) && !is_dir($directory)) return [];
                    $scaled=imagecreatetruecolor($width,$height); imagealphablending($scaled,false); imagesavealpha($scaled,true);
                    imagefill($scaled,0,0,imagecolorallocatealpha($scaled,0,0,0,127));
                    imagecopyresampled($scaled,$source,0,0,0,0,$width,$height,$info[0],$info[1]);
                    $temporary=tempnam($directory,'thumb-');
                    $saved=match($extension){'webp'=>imagewebp($scaled,$temporary,82),'png'=>imagepng($scaled,$temporary,6),default=>imagejpeg($scaled,$temporary,85)};
                    imagedestroy($scaled);
                    if($saved) { if(!@rename($temporary,$target)) { if(is_file($temporary))unlink($temporary); if(!is_file($target))continue; } } else { unlink($temporary); continue; }
                }
                $url=rtrim(str_replace('\\','/',dirname((string)$media->url)),'/').'/.fc-thumbnails/'.basename($directory).'/'.basename($target);
                $variants[]=['width'=>$width,'height'=>$height,'url'=>$url,'path'=>$target];
            }
        } catch(\Throwable $error) {
            // Optional optimization must not break an upload or page render.
            error_log('Favorite CMS thumbnail skipped: '.$error->getMessage());
        } finally { if($source)imagedestroy($source); }
        return self::$memo[$key]=$variants;
    }
    public static function thumbnail(Media $media,int $width,int $height): string {
        $variants=self::all($media); $required=max(1,$width);
        foreach($variants as $variant) if($variant['width'] >= $required)return $variant['url'];
        return $variants ? end($variants)['url'] : (string)$media->url;
    }
    public static function srcset(Media $media): string {
        $variants=self::all($media); if(!$variants)return '';
        $set=array_map(static fn($v)=>(function_exists('site_path') ? site_path($v['url']) : $v['url']).' '.$v['width'].'w',$variants);
        $width=(int)$media->width; if($width>0)$set[]=(function_exists('site_path') ? site_path((string)$media->url) : $media->url).' '.$width.'w';
        return implode(', ',$set);
    }
    public static function delete(Media $media): void {
        $file=self::source($media); if(!$file)return;
        $directory=dirname($file).'/.fc-thumbnails/'.substr(hash('sha256',basename($file)),0,16);
        $resolved=realpath($directory); $parent=realpath(dirname($file));
        if(!$resolved || !self::contained($resolved, $parent.'/.fc-thumbnails'))return;
        foreach(glob($resolved.'/*') ?: [] as $derived) if(is_file($derived) && preg_match('/^(?:320|640|960|1280)-[a-f0-9]{12}\.(?:webp|png|jpg)$/D',basename($derived)))unlink($derived);
        @rmdir($resolved);
    }
}