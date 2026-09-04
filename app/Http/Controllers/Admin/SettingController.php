<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Currency;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\Page;
use FavoriteCMS\Models\Taxonomy;
use FavoriteCMS\Models\User;
use FavoriteCMS\Services\MediaService;
use FavoriteCMS\Services\UploadCapabilityService;

class SettingController
{
    protected Application $app;
    protected UploadCapabilityService $capabilityService;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->capabilityService = new UploadCapabilityService($app);
    }

    public function index(Request $request): Response
    {
        $serverLimits = $this->capabilityService->getServerLimits();

        $settings = [
            'site_name'                 => Setting::get('general', 'site_name', 'Favorite CMS'),
            'site_description'          => Setting::get('general', 'site_description', 'Fast, secure, modular CMS'),
            'site_url'                  => Setting::get('general', 'site_url', config('app.url', 'http://favorite-cms.local')),
            'site_logo_source'          => (string)Setting::get('general', 'site_logo_source', 'url'),
            'site_logo_url'             => (string)Setting::get('general', 'site_logo_url', ''),
            'site_logo_upload_path'     => (string)Setting::get('general', 'site_logo_upload_path', ''),
            'site_favicon_source'       => (string)Setting::get('general', 'site_favicon_source', 'url'),
            'site_favicon_url'          => (string)Setting::get('general', 'site_favicon_url', ''),
            'site_favicon_upload_path'  => (string)Setting::get('general', 'site_favicon_upload_path', ''),
            'admin_email'               => Setting::get('general', 'admin_email', 'admin@example.com'),
            'timezone'                  => Setting::get('general', 'timezone', 'UTC'),
            'primary_currency'          => Currency::getPrimaryCurrency(),
            'allow_registration'        => (int)Setting::get('general', 'allow_registration', 1),
            'posts_per_page'            => Setting::get('reading', 'posts_per_page', 10),
            'front_page_type'           => Setting::get('reading', 'front_page_type', 'posts'), // 'posts' or 'page'
            'front_page_id'             => Setting::get('reading', 'front_page_id', 0),
            'default_category'          => Setting::get('writing', 'default_category', 1),
            'max_upload_size_admin'     => Setting::get('media', 'max_upload_size_admin', UploadCapabilityService::DEFAULT_ADMIN_LIMIT_BYTES),
            'max_upload_size_moderator' => Setting::get('media', 'max_upload_size_moderator', UploadCapabilityService::DEFAULT_MODERATOR_LIMIT_BYTES),
            'max_upload_size_user'      => Setting::get('media', 'max_upload_size_user', UploadCapabilityService::DEFAULT_USER_LIMIT_BYTES),
        ];

        $pages = Page::published();
        $categories = Taxonomy::getByTaxonomy('category');

        $lockReason = null;
        $primaryCurrencyLocked = Currency::isPrimaryCurrencyLocked($lockReason);

        $viewData = [
            'pageTitle'                 => 'Settings',
            'activeMenu'                => 'settings',
            'settings'                  => $settings,
            'supportedCurrencies'       => Currency::getSupportedCurrencies(),
            'primaryCurrencyLocked'     => $primaryCurrencyLocked,
            'primaryCurrencyLockReason' => $lockReason,
            'serverLimits'              => $serverLimits,
            'pages'                     => $pages,
            'categories'                => $categories,
            'contentView'               => APP_ROOT . '/resources/views/admin/settings/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    public function update(Request $request): Response
    {
        $token = (string)$request->post('_token', '');
        if (!empty($_SESSION['_token']) && !hash_equals($_SESSION['_token'], $token)) {
            $_SESSION['flash_error'] = 'Security verification failed (invalid CSRF token).';
            return Response::redirect('/admin/settings');
        }

        Setting::set('general', 'site_name', trim((string)$request->post('site_name', 'Favorite CMS')));
        Setting::set('general', 'site_description', trim((string)$request->post('site_description', '')));
        Setting::set('general', 'site_url', trim((string)$request->post('site_url', 'http://favorite-cms.local')));

        $userId = isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : null;
        $user = null;
        if ($userId !== null) {
            try {
                $user = User::find($userId);
            } catch (\Throwable) {
                $user = null;
            }
        }

        // Logo handling
        if ($request->post('remove_uploaded_logo') === '1') {
            Setting::set('general', 'site_logo_upload_path', '');
            if (Setting::get('general', 'site_logo_source', '') === 'upload') {
                Setting::set('general', 'site_logo_source', 'url');
            }
        }

        if (!empty($_FILES['site_logo_file']['tmp_name']) && (is_uploaded_file($_FILES['site_logo_file']['tmp_name']) || defined('PHPUNIT_RUNNING'))) {
            try {
                $mediaService = new MediaService($this->app);
                $media = $mediaService->upload($_FILES['site_logo_file'], $userId, $user);
                Setting::set('general', 'site_logo_upload_path', $media->url);
                Setting::set('general', 'site_logo_source', 'upload');
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = 'Logo upload failed: ' . $e->getMessage();
                return Response::redirect('/admin/settings');
            }
        } else {
            $logoSource = (string)$request->post('site_logo_source', 'url');
            if (in_array($logoSource, ['upload', 'url'], true)) {
                Setting::set('general', 'site_logo_source', $logoSource);
            }
        }

        $rawLogoUrl = trim((string)$request->post('site_logo_url', ''));
        if ($rawLogoUrl !== '') {
            $sanitizedLogoUrl = sanitize_branding_url($rawLogoUrl);
            if ($sanitizedLogoUrl === '') {
                $_SESSION['flash_error'] = 'Invalid Logo URL. Only valid http://, https://, or local paths (e.g. /uploads/...) are allowed.';
                return Response::redirect('/admin/settings');
            }
            Setting::set('general', 'site_logo_url', $sanitizedLogoUrl);
        } else {
            Setting::set('general', 'site_logo_url', '');
        }

        // Favicon handling
        if ($request->post('remove_uploaded_favicon') === '1') {
            Setting::set('general', 'site_favicon_upload_path', '');
            if (Setting::get('general', 'site_favicon_source', '') === 'upload') {
                Setting::set('general', 'site_favicon_source', 'url');
            }
        }

        if (!empty($_FILES['site_favicon_file']['tmp_name']) && (is_uploaded_file($_FILES['site_favicon_file']['tmp_name']) || defined('PHPUNIT_RUNNING'))) {
            try {
                $mediaService = new MediaService($this->app);
                $media = $mediaService->upload($_FILES['site_favicon_file'], $userId, $user);
                Setting::set('general', 'site_favicon_upload_path', $media->url);
                Setting::set('general', 'site_favicon_source', 'upload');
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = 'Favicon upload failed: ' . $e->getMessage();
                return Response::redirect('/admin/settings');
            }
        } else {
            $faviconSource = (string)$request->post('site_favicon_source', 'url');
            if (in_array($faviconSource, ['upload', 'url'], true)) {
                Setting::set('general', 'site_favicon_source', $faviconSource);
            }
        }

        $rawFaviconUrl = trim((string)$request->post('site_favicon_url', ''));
        if ($rawFaviconUrl !== '') {
            $sanitizedFaviconUrl = sanitize_branding_url($rawFaviconUrl);
            if ($sanitizedFaviconUrl === '') {
                $_SESSION['flash_error'] = 'Invalid Favicon URL. Only valid http://, https://, or local paths (e.g. /uploads/...) are allowed.';
                return Response::redirect('/admin/settings');
            }
            Setting::set('general', 'site_favicon_url', $sanitizedFaviconUrl);
        } else {
            Setting::set('general', 'site_favicon_url', '');
        }
        Setting::set('general', 'admin_email', trim((string)$request->post('admin_email', 'admin@example.com')));
        Setting::set('general', 'timezone', trim((string)$request->post('timezone', 'UTC')));
        Setting::set('general', 'allow_registration', $request->post('allow_registration') ? 1 : 0, 'bool');

        // Primary Accounting Currency
        $rawCurrency = (string)$request->post('primary_currency', Currency::DEFAULT_CURRENCY);
        $normalizedCurrency = Currency::normalize($rawCurrency);
        if (!Currency::isSupported($normalizedCurrency)) {
            $_SESSION['flash_error'] = "Invalid Primary Currency '{$rawCurrency}'. Please select a supported currency.";
            return Response::redirect('/admin/settings');
        }

        $currentCurrency = Currency::getPrimaryCurrency();
        if ($normalizedCurrency !== $currentCurrency) {
            $reason = null;
            if (!Currency::canChangePrimaryCurrency($normalizedCurrency, $reason)) {
                $_SESSION['flash_error'] = $reason ?? "Primary Currency cannot be changed after financial activity has started.";
                return Response::redirect('/admin/settings');
            }

            try {
                Currency::setPrimaryCurrency($normalizedCurrency);
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = $e->getMessage();
                return Response::redirect('/admin/settings');
            }
        }

        Setting::set('reading', 'posts_per_page', (int)$request->post('posts_per_page', 10), 'int');
        Setting::set('reading', 'front_page_type', (string)$request->post('front_page_type', 'posts'));
        Setting::set('reading', 'front_page_id', (int)$request->post('front_page_id', 0), 'int');

        Setting::set('writing', 'default_category', (int)$request->post('default_category', 1), 'int');

        // Media & Role Upload limits
        $adminLimitMb     = (float)$request->post('max_upload_size_admin_mb', 7168);
        $moderatorLimitMb = (float)$request->post('max_upload_size_moderator_mb', 500);
        $userLimitMb      = (float)$request->post('max_upload_size_user_mb', 200);

        $adminBytes     = (int)round(max(1, $adminLimitMb) * 1024 * 1024);
        $moderatorBytes = (int)round(max(1, $moderatorLimitMb) * 1024 * 1024);
        $userBytes      = (int)round(max(1, $userLimitMb) * 1024 * 1024);

        Setting::set('media', 'max_upload_size_admin', $adminBytes, 'int');
        Setting::set('media', 'max_upload_size_moderator', $moderatorBytes, 'int');
        Setting::set('media', 'max_upload_size_user', $userBytes, 'int');

        $_SESSION['flash_success'] = 'Settings saved successfully.';
        return Response::redirect('/admin/settings');
    }
}
