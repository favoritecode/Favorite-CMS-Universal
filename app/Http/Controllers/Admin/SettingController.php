<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\Page;
use FavoriteCMS\Models\Taxonomy;

class SettingController
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function index(Request $request): Response
    {
        $settings = [
            'site_name'        => Setting::get('general', 'site_name', 'Favorite CMS'),
            'site_description' => Setting::get('general', 'site_description', 'Fast, secure, modular CMS'),
            'site_url'         => Setting::get('general', 'site_url', config('app.url', 'http://favorite-cms.local')),
            'admin_email'      => Setting::get('general', 'admin_email', 'admin@example.com'),
            'timezone'         => Setting::get('general', 'timezone', 'UTC'),
            'posts_per_page'   => Setting::get('reading', 'posts_per_page', 10),
            'front_page_type'  => Setting::get('reading', 'front_page_type', 'posts'), // 'posts' or 'page'
            'front_page_id'    => Setting::get('reading', 'front_page_id', 0),
            'default_category' => Setting::get('writing', 'default_category', 1),
        ];

        $pages = Page::published();
        $categories = Taxonomy::getByTaxonomy('category');

        $viewData = [
            'pageTitle'   => 'Settings',
            'activeMenu'  => 'settings',
            'settings'    => $settings,
            'pages'       => $pages,
            'categories'  => $categories,
            'contentView' => APP_ROOT . '/resources/views/admin/settings/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function update(Request $request): Response
    {
        Setting::set('general', 'site_name', trim((string)$request->post('site_name', 'Favorite CMS')));
        Setting::set('general', 'site_description', trim((string)$request->post('site_description', '')));
        Setting::set('general', 'site_url', trim((string)$request->post('site_url', 'http://favorite-cms.local')));
        Setting::set('general', 'admin_email', trim((string)$request->post('admin_email', 'admin@example.com')));
        Setting::set('general', 'timezone', trim((string)$request->post('timezone', 'UTC')));

        Setting::set('reading', 'posts_per_page', (int)$request->post('posts_per_page', 10), 'int');
        Setting::set('reading', 'front_page_type', (string)$request->post('front_page_type', 'posts'));
        Setting::set('reading', 'front_page_id', (int)$request->post('front_page_id', 0), 'int');

        Setting::set('writing', 'default_category', (int)$request->post('default_category', 1), 'int');

        $_SESSION['flash_success'] = 'Settings saved successfully.';
        return Response::redirect('/admin/settings');
    }
}

