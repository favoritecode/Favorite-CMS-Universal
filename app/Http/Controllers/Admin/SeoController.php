<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;

class SeoController
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function index(Request $request): Response
    {
        $defaultRobots = "User-agent: *\nAllow: /\nDisallow: /admin/\nSitemap: " . config('app.url', 'http://favorite-cms.local') . "/sitemap.xml\n";

        $seo = [
            'separator'                  => Setting::get('seo', 'title_separator', '—'),
            'meta_description'           => Setting::get('seo', 'meta_description', 'Welcome to our website powered by Favorite CMS.'),
            'og_image'                   => Setting::get('seo', 'og_image', ''),
            'robots_txt'                 => Setting::get('seo', 'robots_txt', $defaultRobots),
            'google_site_verification'   => Setting::get('seo', 'google_site_verification', ''),
            'bing_site_verification'     => Setting::get('seo', 'bing_site_verification', ''),
            'ga4_enabled'                => (int)Setting::get('seo', 'ga4_enabled', 0),
            'ga4_measurement_id'         => Setting::get('seo', 'ga4_measurement_id', ''),
            'gtm_enabled'                => (int)Setting::get('seo', 'gtm_enabled', 0),
            'gtm_container_id'           => Setting::get('seo', 'gtm_container_id', ''),
        ];

        $viewData = [
            'pageTitle'   => 'Search Engine Optimization (SEO)',
            'activeMenu'  => 'seo',
            'seo'         => $seo,
            'seoDashboard' => $this->dashboardItems($request),
            'contentView' => APP_ROOT . '/resources/views/admin/seo/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function update(Request $request): Response
    {
        Setting::set('seo', 'title_separator', trim((string)$request->post('separator', '—')));
        Setting::set('seo', 'meta_description', trim((string)$request->post('meta_description', '')));
        Setting::set('seo', 'og_image', trim((string)$request->post('og_image', '')));
        Setting::set('seo', 'robots_txt', (string)$request->post('robots_txt', ''));

        // Search engine site verification codes
        $gsc = trim(strip_tags((string)$request->post('google_site_verification', '')));
        $bing = trim(strip_tags((string)$request->post('bing_site_verification', '')));
        Setting::set('seo', 'google_site_verification', $gsc);
        Setting::set('seo', 'bing_site_verification', $bing);

        // Google Analytics 4 (GA4)
        $ga4Enabled = $request->post('ga4_enabled') ? 1 : 0;
        $rawGa4Id = trim((string)$request->post('ga4_measurement_id', ''));
        $ga4Id = preg_match('/^G-[A-Z0-9]+$/i', $rawGa4Id) ? strtoupper($rawGa4Id) : '';
        Setting::set('seo', 'ga4_enabled', $ga4Enabled, 'integer');
        Setting::set('seo', 'ga4_measurement_id', $ga4Id);

        // Google Tag Manager (GTM)
        $gtmEnabled = $request->post('gtm_enabled') ? 1 : 0;
        $rawGtmId = trim((string)$request->post('gtm_container_id', ''));
        $gtmId = preg_match('/^GTM-[A-Z0-9]+$/i', $rawGtmId) ? strtoupper($rawGtmId) : '';
        Setting::set('seo', 'gtm_enabled', $gtmEnabled, 'integer');
        Setting::set('seo', 'gtm_container_id', $gtmId);

        $_SESSION['flash_success'] = 'SEO settings updated.';
        return Response::redirect('/admin/seo');
    }
    private function dashboardItems(Request $request): array
    {
        $kind=$request->get('kind')==='page'?'page':'post';$class=$kind==='post'?\FavoriteCMS\Models\Post::class:\FavoriteCMS\Models\Page::class;
        $page=max(1,(int)$request->get('seo_page',1));$page=min($page,1000000);
        $db=$this->app->get(\FavoriteCMS\Core\Database::class);$table=$class::getTable();
        $total=(int)$db->selectOne("SELECT COUNT(*) AS cnt FROM `{$table}` WHERE `status` != 'trash'")->cnt;
        $page=min($page,max(1,(int)ceil($total/50)));
        $rows=$db->select("SELECT `id`,`title`,`slug`,`status`,SUBSTR(`content`,1,20000) AS content,SUBSTR(`excerpt`,1,4000) AS excerpt,`updated_at` FROM `{$table}` WHERE `status` != 'trash' ORDER BY `id` DESC LIMIT ? OFFSET ?",[50,($page-1)*50]);
        return ['kind'=>$kind,'page'=>$page,'pages'=>max(1,(int)ceil($total/50)),'total'=>$total,'items'=>array_map(static fn($row)=>new $class((array)$row),$rows)];
    }
    public function export(Request $request): Response
    {
        if($request->method()!=='GET')return Response::make('Method not allowed.',405)->header('Allow','GET');
        $data=$this->dashboardItems($request);
        return Response::make(\FavoriteCMS\Services\SeoTransfer::csv($data['items']))->header('Content-Type','text/csv; charset=utf-8')->header('Content-Disposition','attachment; filename="seo-'.$data['kind'].'-page-'.$data['page'].'.csv"');
    }
    private function validMutation(Request $request): bool
    {
        $stored=$_SESSION['_token']??'';$submitted=$request->post('_token','');
        return $request->method()==='POST' && is_string($stored) && $stored!=='' && is_string($submitted) && hash_equals($stored,$submitted);
    }
    public function preview(Request $request): Response
    {
        if(!$this->validMutation($request))return Response::make('Invalid request.', $request->method()==='POST'?403:405);
        unset($_SESSION['seo_import_preview']);
        try {
            $upload=$request->file('seo_file');
            if(!$upload || ($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name']))throw new \RuntimeException('Choose a valid CSV or JSON file.');
            if((int)($upload['size']??0)>5*1024*1024)throw new \RuntimeException('SEO import limit is 5 MB.');
            $contents=file_get_contents($upload['tmp_name']);if($contents===false)throw new \RuntimeException('Could not read the uploaded file.');
            $rows=\FavoriteCMS\Services\SeoTransfer::parse($contents,strtolower(pathinfo($upload['name'],PATHINFO_EXTENSION)));
            $preview=\FavoriteCMS\Services\SeoTransfer::preview($rows,(bool)$request->post('overwrite'));
            $preview['nonce']=bin2hex(random_bytes(16));$preview['expires']=time()+1800;
            $_SESSION['seo_import_preview']=$preview;
        }catch(\Throwable $e){$_SESSION['flash_error']=$e->getMessage();}
        return Response::redirect('/admin/seo');
    }
    public function apply(Request $request): Response
    {
        if(!$this->validMutation($request))return Response::make('Invalid request.', $request->method()==='POST'?403:405);
        try {
            $preview=$_SESSION['seo_import_preview']??null;
            if(!$preview || (int)$preview['expires']<time() || !hash_equals($preview['nonce'],(string)$request->post('preview_nonce','')))throw new \RuntimeException('Import preview expired. Upload the file again.');
            $count=\FavoriteCMS\Services\SeoTransfer::apply($preview);
            unset($_SESSION['seo_import_preview']);$_SESSION['flash_success']='SEO metadata updated for '.$count.' items. Content and publication status were retained.';
        }catch(\Throwable $e){$_SESSION['flash_error']=$e->getMessage();}
        return Response::redirect('/admin/seo');
    }
}
