<?php
declare(strict_types=1);
namespace FavoriteCMS\Http\Controllers\Admin;
use FavoriteCMS\Core\{Application,Request,Response};
use FavoriteCMS\Models\{User,Page};
use FavoriteCMS\Services\UrlRedirects;

final class RedirectController
{
    public function __construct(private Application $app) {}
    public function handle(Request $request,string $action='index'): Response
    {
        $user=User::find((int)($_SESSION['auth_user_id']??0));
        if(!$user||!$user->canManageSettings())return Response::make('Access denied.',403);
        $mutation=in_array($action,['store','update','toggle','delete'],true);
        if($mutation){
            if($request->method()!=='POST')return Response::make('Method not allowed.',405)->header('Allow','POST');
            $token=$request->post('_token','');$stored=$_SESSION['_token']??'';
            if(!is_string($token)||!is_string($stored)||$stored===''||!hash_equals($stored,$token))return Response::make('Invalid security token.',403);
            foreach($request->all()as$value)if(!is_scalar($value)&&$value!==null)return Response::make('Invalid form data.',400);
        }elseif(!in_array($request->method(),['GET','HEAD'],true))return Response::make('Method not allowed.',405)->header('Allow','GET, HEAD');
        if($action==='new')return $this->form();
        if($action==='edit'){
            $id=$request->get('id','');if(!is_string($id))return Response::make('Redirect not found.',404);
            foreach(UrlRedirects::rules()as$r)if($r['id']===$id)return $this->form($r);
            return Response::make('Redirect not found.',404);
        }
        if($action==='store'||$action==='update'){
            $values=['id'=>$action==='update'?(string)$request->post('id',''):'','source'=>(string)$request->post('source',''),'destination'=>(string)$request->post('destination',''),'status'=>(string)$request->post('status','301'),'enabled'=>(bool)$request->post('enabled',false),'preserve_query'=>(bool)$request->post('preserve_query',false),'revision'=>(string)$request->post('revision','')];
            try{
                UrlRedirects::save($values,$action==='update'?$values['id']:null);
                $_SESSION['redirect_notice']=$action==='update'?'Redirect updated.':'Redirect added.';
                return Response::redirect('/admin/settings/redirects');
            }catch(\InvalidArgumentException $e){return $this->form($values,$e->getMessage(),422);}
            catch(\Throwable $e){error_log('Redirect save failed: '.$e->getMessage());return $this->form($values,'Could not save. Existing rules have been preserved. Please reload and try again.',500);}
        }
        if($action==='toggle'||$action==='delete'){
            try{UrlRedirects::change((string)$request->post('id',''),(string)$request->post('revision',''),$action==='delete');$_SESSION['redirect_notice']=$action==='delete'?'Redirect deleted.':'Redirect status updated.';}
            catch(\InvalidArgumentException $e){$_SESSION['redirect_error']=$e->getMessage();}
            catch(\Throwable $e){error_log('Redirect change failed: '.$e->getMessage());$_SESSION['redirect_error']='Could not change this rule. Existing rules have been preserved.';}
            return Response::redirect('/admin/settings/redirects');
        }
        if($action!=='index')return Response::make('Not found.',404);
        $raw=$request->get('s','');$search=is_string($raw)?mb_substr(trim($raw),0,200):'';
        $rules=array_reverse(UrlRedirects::rules());
        if($search!==''){$needle=mb_strtolower($search);$rules=array_values(array_filter($rules,static fn($r)=>str_contains(mb_strtolower($r['source'].' '.$r['destination']),$needle)));}
        $total=count($rules);$totalPages=max(1,(int)ceil($total/20));$rawPage=$request->get('p',1);$page=min($totalPages,max(1,is_scalar($rawPage)?(int)$rawPage:1));
        $rows=array_slice($rules,($page-1)*20,20);
        $notice=$_SESSION['redirect_notice']??'';$error=$_SESSION['redirect_error']??'';unset($_SESSION['redirect_notice'],$_SESSION['redirect_error']);
        return $this->render('index',compact('rows','total','totalPages','page','search','notice','error'));
    }
    private function form(array $entry=[],string $error='',int $status=200): Response
    {
        $entry=array_replace(['id'=>'','source'=>'','destination'=>'','status'=>301,'enabled'=>true,'preserve_query'=>false,'revision'=>''],$entry);
        $pages=Page::summaries('published');
        return $this->render('form',compact('entry','pages','error'),$status);
    }
    private function render(string $view,array $data,int $status=200): Response
    {
        $pageTitle='URL Redirects';$activeMenu='settings';$contentView=APP_ROOT.'/resources/views/admin/redirects/'.$view.'.php';
        extract($data,EXTR_SKIP);ob_start();include APP_ROOT.'/resources/views/admin/layout.php';return Response::make((string)ob_get_clean(),$status);
    }
}
