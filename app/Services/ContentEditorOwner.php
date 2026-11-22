<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
use FavoriteCMS\Models\Setting;
/** Durable per-record editor choice; absent values retain legacy Builder behaviour. */
final class ContentEditorOwner {
    public static function get(string $type,int $id):array {
        $value=Setting::get('content_editor_owners',$type.'_'.$id,[]);
        if(is_string($value))$value=json_decode($value,true);
        return is_array($value)?$value:[];
    }
    public static function allows(string $type,int $id,string $document):bool {
        $owner=self::get($type,$id);
        if(($owner['mode']??'')==='simple')return false;
        return ($owner['mode']??'')!=='builder'||($owner['document']??'')===$document;
    }
    public static function token(string $type,int $id):string {return hash('sha256',json_encode(self::get($type,$id),JSON_THROW_ON_ERROR));}
    public static function simple(string $type,int $id,string $document=''):void {
        $old=self::get($type,$id);
        self::write($type,$id,'simple',$document!==''?$document:(string)($old['document']??''),$old);
    }
    public static function builder(string $type,int $id,string $document):void {
        $old=self::get($type,$id);
        if(($old['mode']??'')==='builder'&&($old['document']??'')===$document)return;
        self::write($type,$id,'builder',$document,$old);
    }
    private static function write(string $type,int $id,string $mode,string $document,array $old):void {
        if(!in_array($type,['post','page'],true)||$id<1)throw new \RuntimeException('Invalid content editor target.');
        Setting::set('content_editor_owners',$type.'_'.$id,['mode'=>$mode,'document'=>$document,'revision'=>(int)($old['revision']??0)+1],'json');
    }
}
