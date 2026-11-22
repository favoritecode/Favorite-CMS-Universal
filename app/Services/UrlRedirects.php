<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;

use FavoriteCMS\Core\{Container,Database,Request,Response};
use FavoriteCMS\Models\{Setting,Page,Post};

/** Exact, administrator-defined navigation rules. No regexes or filesystem rewrites. */
final class UrlRedirects
{
    public const MAX_RULES = 1000;
    public static function rules(): array
    {
        $records=Setting::getGroup('url_redirects');$rules=[];
        if(count($records)>self::MAX_RULES)throw new \RuntimeException('Redirect settings exceed the supported limit.');
        foreach($records as$id=>$raw){
            $r=json_decode((string)$raw,true);
            if(!is_array($r)||!isset($r['id'],$r['source'],$r['destination'],$r['revision'])||$r['id']!==$id)throw new \RuntimeException('Redirect settings are unreadable. No rules were changed.');
            $rules[]=$r;
        }
        return $rules;
    }
    public static function normalize(string $value, bool $destination=false, bool $stripBase=true): string
    {
        $value=trim($value);
        if($value===''||strlen($value)>2048||preg_match('/[\x00-\x20\x7f\\\\]/',$value)||preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i',$value)) throw new \InvalidArgumentException('Enter a valid site URL without spaces or control characters.');
        $parts=parse_url($value);
        if($parts===false||isset($parts['user'])||isset($parts['pass'])||str_starts_with($value,'//')) throw new \InvalidArgumentException('Use a site path such as /old-page, not a protocol-relative URL.');
        if(isset($parts['scheme'])||isset($parts['host'])) {
            $site=parse_url((string)Setting::get('general','site_url',''));
            if(!in_array(strtolower($parts['scheme']??''),['http','https'],true)||empty($parts['host'])||empty($site['host'])||strcasecmp($parts['host'],$site['host'])!==0
                || ($parts['port']??(strtolower($parts['scheme']??'')==='https'?443:80))!==($site['port']??(strtolower($site['scheme']??'')==='https'?443:80))) throw new \InvalidArgumentException('Source and destination must belong to this site. Use a path such as /new-page.');
        } elseif(!str_starts_with($value,'/')) throw new \InvalidArgumentException('Start the URL path with /.');
        if(!$destination&&(isset($parts['query'])||isset($parts['fragment']))) throw new \InvalidArgumentException('Source matches the path only. Leave its query string and #fragment out.');
        $path=$parts['path']??'/';
        if(preg_match('/%(?:2f|5c)/i',$path)) throw new \InvalidArgumentException('Encoded path separators are not supported.');
        $path=rawurldecode($path);
        if(!mb_check_encoding($path,'UTF-8')||preg_match('/[\x00-\x20\x7f\\\\]/',$path)||str_contains($path,'//')) throw new \InvalidArgumentException('Invalid URL path.');
        if($stripBase){$base=(string)(parse_url((string)Setting::get('general','site_url',''),PHP_URL_PATH)?:'');$base=rtrim($base,'/');if($base!==''&&($path===$base||str_starts_with($path,$base.'/')))$path=substr($path,strlen($base))?:'/';}
        if(str_contains($path,'*'))throw new \InvalidArgumentException('Use an exact path. Wildcards are not supported.');
        $segments=explode('/',trim($path,'/'));
        foreach($segments as $segment)if(in_array($segment,['.','..'],true))throw new \InvalidArgumentException('Relative dot segments are not allowed.');
        $path='/'.implode('/',array_map('rawurlencode',$segments));
        if(self::reserved($path))throw new \InvalidArgumentException('Admin, API, download and system paths cannot be used in redirect rules.');
        if($destination){if(isset($parts['query']))$path.='?'.$parts['query'];if(isset($parts['fragment']))$path.='#'.$parts['fragment'];}
        if(strlen($path)>2048)throw new \InvalidArgumentException('The encoded URL is too long. Use a shorter path.');
        return $path;
    }
    public static function reserved(string $path): bool
    {
        return (bool)preg_match('#^/(admin|api|media-download|uploads|assets|public|storage|app|config|database|vendor|resources|themes|plugins|install)(/|$)#i',$path)
            || in_array(strtolower(rtrim($path,'/')),['/login','/logout','/register','/signup','/forgot-password','/reset-password','/appearance','/robots.txt','/sitemap.xml'],true)
            || (bool)preg_match('#^/sitemap-[0-9]+\.xml$#i',$path);
    }
    public static function eligible(Request $r): bool
    {
        return in_array($r->method(),['GET','HEAD'],true)&&!$r->isAjax()&&$r->header('RANGE')===''&&!str_contains(strtolower($r->header('ACCEPT')),'application/json')&&!self::reserved($r->path());
    }
    /** Known Core aliases must participate in loop detection too. */
    private static function canonical(string $path): ?string
    {
        if(preg_match('#^/(post|blog|page)/([^/]+)$#',$path,$m)){
            $slug=rawurldecode($m[2]);$type=$m[1]==='page'?'page':'post';
            $item=$type==='page'?Page::findBySlug($slug):Post::findBySlug($slug);
            if($item&&$item->status==='published')return $item->url()!==$path?$item->url():null;
            $location=SeoRedirects::resolve($type,$slug)?->getHeader('Location');
            if($location){$base=(string)($GLOBALS['favorite_cms_base_path']??'');if($base!==''&&str_starts_with($location,$base.'/'))$location=substr($location,strlen($base));return self::normalize($location,true,false);}
            return null;
        }
        if(substr_count($path,'/')===1&&$path!=='/'){$page=Page::findBySlug(rawurldecode(substr($path,1)));if($page&&$page->status==='published'&&$page->url()!==$path)return $page->url();}
        return null;
    }
    private static function pathOnly(string $url): string { return self::normalize((string)(parse_url($url,PHP_URL_PATH)?:'/'),false,false); }
    private static function follow(string $destination, array $map, array &$canonicalCache, array $seen=[]): string
    {
        for($i=0;$i<32;$i++){
            $path=self::pathOnly($destination);
            if(isset($seen[$path]))throw new \InvalidArgumentException('These redirects would create a loop. Choose another destination.');
            $seen[$path]=true;
            if(isset($map[$path])){$destination=$map[$path]['destination'];continue;}
            if(!array_key_exists($path,$canonicalCache))$canonicalCache[$path]=self::canonical($path);
            if($canonicalCache[$path]){
                $previous=parse_url($destination);$destination=$canonicalCache[$path];
                if(isset($previous['query'])&&parse_url($destination,PHP_URL_QUERY)===null)$destination.='?'.$previous['query'];
                if(isset($previous['fragment'])&&parse_url($destination,PHP_URL_FRAGMENT)===null)$destination.='#'.$previous['fragment'];
                continue;
            }
            return $destination;
        }
        throw new \InvalidArgumentException('This redirect chain is too long. Link directly to the final page.');
    }
    public static function target(string $destination): ?string
    {
        try{$map=[];foreach(self::rules()as$r)if(!empty($r['enabled']))$map[$r['source']]=$r;$cache=[];return self::follow($destination,$map,$cache);}catch(\Throwable){return null;}
    }
    public static function save(array $input, ?string $id=null): string
    {
        $source=self::normalize((string)$input['source']);$destination=self::normalize((string)$input['destination'],true);
        if($source===self::pathOnly($destination))throw new \InvalidArgumentException('Source and destination cannot be the same page.');
        $status=(int)($input['status']??301);if(!in_array($status,[301,302],true))throw new \InvalidArgumentException('Choose 301 or 302.');
        return self::mutate(function(array $rules)use($input,$id,$source,$destination,$status):array{
            $found=null;foreach($rules as $i=>$r){if($r['id']===$id)$found=$i;if($r['source']===$source&&$r['id']!==$id)throw new \InvalidArgumentException('A rule already exists for this source. Edit that rule instead.');}
            if($id!==null&&$found===null)throw new \InvalidArgumentException('Redirect no longer exists. Refresh the list.');
            if($found!==null&&($input['revision']??'')!==$rules[$found]['revision'])throw new \InvalidArgumentException('This redirect changed in another session. Reload it before saving.');
            if($id===null&&count($rules)>=self::MAX_RULES)throw new \InvalidArgumentException('The 1,000-rule limit has been reached.');
            $entry=['id'=>$id??bin2hex(random_bytes(12)),'source'=>$source,'destination'=>$destination,'status'=>$status,'enabled'=>!empty($input['enabled']),'preserve_query'=>!empty($input['preserve_query']),'revision'=>bin2hex(random_bytes(12)),'created_at'=>$found!==null?$rules[$found]['created_at']:date('c'),'updated_at'=>date('c')];
            if($found!==null)$rules[$found]=$entry;else$rules[]=$entry;
            return [$rules,$entry['id']];
        });
    }
    public static function change(string $id,string $revision,bool $delete): void
    {
        self::mutate(function(array $rules)use($id,$revision,$delete):array{
            foreach($rules as$i=>$r)if($r['id']===$id){
                if($r['revision']!==$revision)throw new \InvalidArgumentException('This rule changed. Refresh the list and try again.');
                if($delete)array_splice($rules,$i,1);else{$rules[$i]['enabled']=empty($r['enabled']);$rules[$i]['revision']=bin2hex(random_bytes(12));$rules[$i]['updated_at']=date('c');}
                return [$rules,$id];
            }
            throw new \InvalidArgumentException('Redirect no longer exists.');
        });
    }
    private static function mutate(\Closure $edit): string
    {
        $db=Container::getInstance()->get(Database::class);
        return $db->transaction(function()use($db,$edit):string{
            $driver=$db->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $insert=$driver==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';
            $db->execute($insert." INTO `settings` (`group_name`,`setting_key`,`value`,`type`,`is_public`) VALUES (?,?,?,?,?)",['redirects','rules_lock','1','string',0]);
            if($driver==='mysql')$db->selectOne('SELECT id FROM settings WHERE group_name=? AND setting_key=? FOR UPDATE',['redirects','rules_lock']);
            Setting::clearCache();$before=self::rules();[$rules,$id]=$edit($before);$map=[];
            foreach($rules as$r)if(!empty($r['enabled']))$map[$r['source']]=$r;
            $cache=[];foreach($map as$source=>$r)self::follow($r['destination'],$map,$cache,[$source=>true]);
            $previous=[];foreach($before as$r)$previous[$r['id']]=$r;
            foreach($rules as$r){
                if(!isset($previous[$r['id']])||$previous[$r['id']]!==$r)Setting::set('url_redirects',$r['id'],json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
                unset($previous[$r['id']]);
            }
            foreach($previous as$old)$db->delete('settings',['group_name'=>'url_redirects','setting_key'=>$old['id']]);
            return $id;
        });
    }
    public static function resolve(Request $request): ?Response
    {
        if(!self::eligible($request))return null;
        try{
            $path=self::normalize($request->path(),false,false);$map=[];
            foreach(self::rules()as$r)if(!empty($r['enabled']))$map[$r['source']]=$r;
            if(!isset($map[$path]))return null;
            $r=$map[$path];$cache=[];$target=self::follow($r['destination'],$map,$cache,[$path=>true]);
            if(!empty($r['preserve_query'])&&parse_url($target,PHP_URL_QUERY)===null){
                $query=(string)(parse_url($request->uri(),PHP_URL_QUERY)??'');
                if($query!==''&&!preg_match('/[\r\n\x00]/',$query)){$parts=explode('#',$target,2);$target=$parts[0].'?'.$query.(isset($parts[1])?'#'.$parts[1]:'');}
            }
            return Response::redirect($target,(int)$r['status']===302?302:301)->header('Cache-Control','no-store');
        }catch(\Throwable){return null;}
    }
}
