<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;
use FavoriteCMS\Core\{Request,Response};
use FavoriteCMS\Models\{Setting,Page};

final class NotFoundRedirect
{
    public static function resolve(Request $request): ?Response
    {
        if (!in_array($request->method(),['GET','HEAD'],true) || $request->isAjax()
            || str_contains(strtolower($request->header('ACCEPT')),'application/json')
            || preg_match('#^/(admin|api|media-download|uploads|assets|themes|plugins)(/|$)#i',$request->path())) return null;
        $id=(int)Setting::get('reading','not_found_page_id',0);
        if (!$id) return null;
        $page=Page::find($id);
        if (!$page || $page->status !== 'published') return null;
        $target=UrlRedirects::target($page->url());
        if ($target === null) return null;
        if (!str_starts_with($target,'/') || str_starts_with($target,'//') || preg_match('/[\r\n]/',$target)) return null;
        if (rtrim(rawurldecode($request->path()),'/')===rtrim(rawurldecode((string)(parse_url($target,PHP_URL_PATH)?:'/')),'/')) return null;
        $status=(int)Setting::get('reading','not_found_redirect_status',302);
        return Response::redirect($target,$status===301?301:302)->header('Cache-Control','no-store');
    }
}
