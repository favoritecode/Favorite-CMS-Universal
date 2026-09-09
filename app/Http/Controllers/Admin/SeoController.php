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
}

