<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
final class BuilderContent {
    public static function run(\Closure $save):Response {
        return class_exists('FavoritePageBuilderCmsBridge',false)?\FavoritePageBuilderStorage::run($save):$save();
    }
    public static function state(string $type,int $id):array {
        if (!class_exists('FavoritePageBuilderCmsBridge',false)) return [];
        $doc=\FavoritePageBuilderCmsBridge::find($type,$id);
        // An empty, unpublished connection is not a saved Builder design.
        if (!$doc || (empty($doc['nodes']) && !is_array($doc['publishedVersion']??null))) return [];
        return \FavoritePageBuilderCmsBridge::state($type,$id);
    }
    public static function backup(string $type,int $id):array {
        return class_exists('FavoritePageBuilderCmsBridge',false)&&method_exists('FavoritePageBuilderCmsBridge','backupState')?\FavoritePageBuilderCmsBridge::backupState($type,$id):[];
    }
    public static function content(Request $request,string $type,object $record,string $content):string {
        $document='';
        if(class_exists('FavoritePageBuilderCmsBridge',false)){
            $doc=\FavoritePageBuilderCmsBridge::find($type,(int)$record->id);$document=(string)($doc['id']??'');
            if((string)$request->post('_builder_baseline','')!==''||self::state($type,(int)$record->id))$content=\FavoritePageBuilderCmsBridge::simpleContent($request,$type,$record,$content);
        }
        // Metadata-only saves do not silently change content ownership.
        $normalize=static fn(string $value)=>trim(str_replace(["\r\n","\r"],"\n",$value));
        if((string)$request->post('_builder_disconnect','0')==='1'||$normalize($content)!==$normalize((string)$record->content))ContentEditorOwner::simple($type,(int)$record->id,$document);
        return $content;
    }
}
