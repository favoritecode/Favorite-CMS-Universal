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
            'separator'        => Setting::get('seo', 'title_separator', '—'),
            'meta_description' => Setting::get('seo', 'meta_description', 'Welcome to our website powered by Favorite CMS.'),
            'og_image'         => Setting::get('seo', 'og_image', ''),
            'robots_txt'       => Setting::get('seo', 'robots_txt', $defaultRobots),
        ];

        $viewData = [
            'pageTitle'   => 'Search Engine Optimization (SEO)',
            'activeMenu'  => 'seo',
            'seo'         => $seo,
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

        $_SESSION['flash_success'] = 'SEO settings updated.';
        return Response::redirect('/admin/seo');
    }
}

