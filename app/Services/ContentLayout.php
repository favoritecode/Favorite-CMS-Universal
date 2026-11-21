<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
use FavoriteCMS\Models\Setting;
final class ContentLayout
{
    public static function get(string $type, int $id): string {
        $value=$id>0?Setting::get('content_layouts',$type.'_'.$id,'right'):'right';
        return in_array($value,['right','left','none'],true)?$value:'right';
    }
    public static function save(\FavoriteCMS\Core\Request $request,string $type,int $id):void {
        // Older clients that do not send this field keep the saved choice.
        $value=$request->post('sidebar_layout',null);if($value===null)return;
        if(!is_string($value)||!in_array($value,['right','left','none'],true))throw new \RuntimeException('Choose a valid sidebar layout.');
        Setting::set('content_layouts',$type.'_'.$id,$value);
    }
    public static function render(object $record,string $type,string $theme,\Closure $render):string {
        $preview=$record->_preview_sidebar_layout ?? null;
        $layout=($record->_preview_content_verified??false)===true&&in_array($preview,['right','left','none'],true)?$preview:self::get($type,(int)$record->id);
        $html=Setting::withPreview(['theme_mods_'.$theme=>['site_layout'=>$layout]],$render);
        if($layout==='none'&&stripos($html,'</head>')!==false){
            $css='<style id="cms-content-full-width">.site-content>.container.layout>.site-main--singular{width:100%;max-width:none;margin-inline:0}</style>';
            $html=(string)preg_replace('~</head>~i',$css.'</head>',$html,1);
        }
        return $html;
    }
}
