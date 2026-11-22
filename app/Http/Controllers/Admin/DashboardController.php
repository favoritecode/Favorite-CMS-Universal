<?php
declare(strict_types=1);
namespace FavoriteCMS\Http\Controllers\Admin;
use FavoriteCMS\Core\{Application,Request,Response};
use FavoriteCMS\Models\User;
use FavoriteCMS\Services\DashboardService;

class DashboardController
{
    public function __construct(protected Application $app) {}
    public function index(Request $request): Response
    {
        $currentUser=User::find((int)($_SESSION['auth_user_id']??0));
        if(!$currentUser||!$currentUser->isActive())return Response::redirect('/admin/login');
        if(!$currentUser->hasPermission('view_admin')&&!$currentUser->canCreatePosts()&&!$currentUser->canUpdatePosts()&&!$currentUser->hasRole('subscriber'))return Response::redirect('/admin/users/profile');
        $service=new DashboardService($this->app);$sources=$service->sources($currentUser);
        if($request->method()==='POST'){
            $token=$request->post('_token','');
            if(!is_string($token)||empty($_SESSION['_token'])||!hash_equals($_SESSION['_token'],$token))return Response::make('Invalid security token.',403);
            if($request->post('action')==='reorder'){
                try{return Response::json(['success'=>true,'order'=>$service->reorder($currentUser,$sources,$request->post('order',[]))])->header('Cache-Control','no-store');}
                catch(\InvalidArgumentException $error){return Response::json(['success'=>false,'message'=>$error->getMessage()],422);}
            }
            try{
                if($request->post('action')==='reset'){
                    $this->app->make(\FavoriteCMS\Core\Database::class)->delete('settings',['group_name'=>'dashboard','setting_key'=>'user_'.(int)$currentUser->id]);
                    \FavoriteCMS\Models\Setting::clearCache();
                }elseif($request->post('action')==='save')$service->save($currentUser,$sources,$request->post('sources',[]));
                else throw new \InvalidArgumentException('Choose a valid dashboard action.');
                $_SESSION['flash_success']='Dashboard updated. Your site content has not been changed.';
            }catch(\InvalidArgumentException $error){$_SESSION['flash_error']=$error->getMessage();}
            return Response::redirect('/admin');
        }
        if(!in_array($request->method(),['GET','HEAD'],true))return Response::make('Method not allowed.',405)->header('Allow','GET, HEAD, POST');
        $selected=$service->selected($currentUser,$sources);$panels=$service->panels($currentUser,$sources,$selected);
        $pageTitle='Dashboard';$activeMenu='dashboard';$contentView=APP_ROOT.'/resources/views/admin/dashboard.php';
        ob_start();include APP_ROOT.'/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean())->header('Cache-Control','private, no-store');
    }
}
