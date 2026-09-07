<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

use FavoriteCMS\Installer\InstallerController;
use FavoriteCMS\Installer\InstallerSession;
use FavoriteCMS\Installer\UrlResolver;
use FavoriteCMS\Plugins\PluginManager;
use FavoriteCMS\Http\Controllers\FrontendController;
use FavoriteCMS\Http\Controllers\Admin\DashboardController;
use FavoriteCMS\Http\Controllers\Admin\PostController;
use FavoriteCMS\Http\Controllers\Admin\PageController;
use FavoriteCMS\Http\Controllers\Admin\TaxonomyController;
use FavoriteCMS\Http\Controllers\Admin\MediaController;
use FavoriteCMS\Http\Controllers\Admin\CommentController;
use FavoriteCMS\Http\Controllers\Admin\UserController;
use FavoriteCMS\Http\Controllers\Admin\MenuController;
use FavoriteCMS\Http\Controllers\Admin\ThemeController;
use FavoriteCMS\Http\Controllers\Admin\WidgetController;
use FavoriteCMS\Http\Controllers\Admin\CustomizeController;
use FavoriteCMS\Http\Controllers\Admin\PluginController;
use FavoriteCMS\Http\Controllers\Admin\SettingController;
use FavoriteCMS\Http\Controllers\Admin\SeoController;
use FavoriteCMS\Http\Controllers\Admin\ToolController;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Rendering\Engine;
use FavoriteCMS\Services\EmailVerificationService;

class Kernel
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(Request $request): Response
    {
        try {
            $urls = new UrlResolver();
            $request->setBasePath($urls->basePath($request));
            $GLOBALS['favorite_cms_base_path'] = $request->basePath();
            (new InstallerSession($urls))->start($request);

            $path = $request->path();

            // 1. Installer: If not installed, handle installation
            if (!$this->app->isInstalled()) {
                $installer = new InstallerController($this->app);
                return $installer->handle($request);
            }

            // If already installed and visiting /install, redirect to /
            if ($path === '/install') {
                return Response::redirect('/');
            }

            // Boot active plugins safely
            (new PluginManager($this->app))->bootActivePlugins();

            // Load active theme functions.php if available
            try {
                $activeTheme = \FavoriteCMS\Models\Setting::get('theme', 'active_theme', 'default');
                $themeFunctions = APP_ROOT . '/themes/' . $activeTheme . '/functions.php';
                if (file_exists($themeFunctions)) {
                    include_once $themeFunctions;
                }
            } catch (\Throwable) {}

            // Boot widget registry and allow plugins/themes to register widgets via widgets_init
            \FavoriteCMS\Widgets\WidgetRegistry::getInstance()->ensureBooted();

            // Fire core init hook
            \FavoriteCMS\Core\Hook::doAction('init', $this->app);

            // Check for PHP post_max_size overflow (empty $_POST/$_FILES despite positive Content-Length)
            if (
                isset($_SERVER['REQUEST_METHOD']) &&
                strtoupper($_SERVER['REQUEST_METHOD']) === 'POST' &&
                (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 &&
                empty($_POST) &&
                empty($_FILES)
            ) {
                $postMax = ini_get('post_max_size') ?: 'unknown';
                $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
                $formattedLen = \FavoriteCMS\Services\UploadCapabilityService::formatBytes($length);
                return Response::make(
                    "<!DOCTYPE html><html><head><title>413 Payload Too Large</title><style>body{font-family:sans-serif;padding:40px;line-height:1.6;max-width:600px;margin:auto;color:#334155;}h1{color:#e11d48;}</style></head><body><h1>413 Payload Too Large</h1><p>The submitted request payload ({$formattedLen}) exceeded the server's <code>post_max_size</code> setting ({$postMax}).</p><p>To prevent data loss, the request was rejected rather than silently truncated. Please increase <code>post_max_size</code> in PHP or submit smaller content.</p><p><a href='javascript:history.back()'>&larr; Go Back</a></p></body></html>",
                    413
                );
            }

            // 2. Dispatch request
            return $this->dispatch($request);

        } catch (\Throwable $e) {
            return $this->handleException($e);
        }
    }

    protected function dispatch(Request $request): Response
    {
        $path   = $request->path();
        $method = $request->method();

        // Immediately invalidate session if authenticated user was banned
        if (!empty($_SESSION['auth_user_id'])) {
            $checkUser = User::find((int)$_SESSION['auth_user_id']);
            if (!$checkUser || $checkUser->isBanned()) {
                unset(
                    $_SESSION['auth_user_id'],
                    $_SESSION['auth_user_name'],
                    $_SESSION['auth_user_email'],
                    $_SESSION['auth_user_role']
                );
                $_SESSION['login_flash'] = 'Your account has been permanently banned.';
                $_SESSION['flash_error'] = 'Your account has been permanently banned.';
                if (str_starts_with($path, '/admin')) {
                    return Response::redirect('/admin/login');
                }
            }
        }

        // ---------------------------------------------------------------------
        // Public Auth & Registration Routes
        // ---------------------------------------------------------------------
        if ($path === '/register' || $path === '/signup' || $path === '/admin/register') {
            return $method === 'POST' ? $this->processRegister($request) : $this->showRegister($request);
        }

        if ($path === '/login') {
            return Response::redirect('/admin/login');
        }

        if ($path === '/verify-email') {
            return $this->handleEmailVerification($request);
        }

        if ($path === '/resend-verification') {
            return $method === 'POST' ? $this->processResendVerification($request) : $this->showResendVerification($request);
        }

        // ---------------------------------------------------------------------
        // Admin Routes
        // ---------------------------------------------------------------------
        if (str_starts_with($path, '/admin')) {
            return $this->dispatchAdmin($request, $path, $method);
        }

        // ---------------------------------------------------------------------
        // Static Theme & Plugin Assets
        // ---------------------------------------------------------------------
        if (str_starts_with($path, '/themes/') || str_starts_with($path, '/plugins/')) {
            $assetResponse = $this->serveStaticAsset($path);
            if ($assetResponse !== null) {
                return $assetResponse;
            }
        }

        // ---------------------------------------------------------------------
        // Dynamic Plugin Frontend Routes
        // ---------------------------------------------------------------------
        $dynamicResp = \FavoriteCMS\Core\Router::dispatch($request);
        if ($dynamicResp !== null) {
            return $dynamicResp;
        }

        // ---------------------------------------------------------------------
        // Public Frontend Routes
        // ---------------------------------------------------------------------
        $frontend = new FrontendController($this->app);

        if ($path === '/' || $path === '') {
            return $frontend->home($request);
        }

        if ($path === '/logout') {
            return $this->processLogout($request);
        }

        if ($path === '/search') {
            return $frontend->search($request);
        }

        if ($path === '/comment/submit' && $method === 'POST') {
            return $frontend->submitComment($request);
        }

        if ($path === '/sitemap.xml') {
            return $frontend->sitemap($request);
        }

        if ($path === '/robots.txt') {
            return $frontend->robots($request);
        }

        // /post/{slug}
        if (preg_match('#^/post/([a-zA-Z0-9_\-]+)$#', $path, $m)) {
            return $frontend->post($request, $m[1]);
        }

        // /page/{slug}
        if (preg_match('#^/page/([a-zA-Z0-9_\-]+)$#', $path, $m)) {
            return $frontend->page($request, $m[1]);
        }

        // /category/{slug}
        if (preg_match('#^/category/([a-zA-Z0-9_\-]+)$#', $path, $m)) {
            return $frontend->category($request, $m[1]);
        }

        // /tag/{slug}
        if (preg_match('#^/tag/([a-zA-Z0-9_\-]+)$#', $path, $m)) {
            return $frontend->tag($request, $m[1]);
        }

        // Fallback check for single page by direct slug e.g. /about
        $pageSlug = trim($path, '/');
        if (!empty($pageSlug)) {
            $resp = $frontend->page($request, $pageSlug);
            $refStatus = new \ReflectionProperty($resp, 'status');
            $refStatus->setAccessible(true);
            if ($refStatus->getValue($resp) === 200) {
                return $resp;
            }
        }

        return $this->notFound($request);
    }

    protected function dispatchAdmin(Request $request, string $path, string $method): Response
    {
        // Auth routes
        if ($path === '/admin/login') {
            return $method === 'POST' ? $this->processLogin($request) : $this->showLogin($request);
        }

        if ($path === '/admin/logout') {
            return $this->processLogout($request);
        }

        // Require authentication for all other /admin routes
        if (empty($_SESSION['auth_user_id'])) {
            return Response::redirect('/admin/login');
        }

        $currentUser = User::find((int)$_SESSION['auth_user_id']);
        if (!$currentUser || $currentUser->isBanned()) {
            unset(
                $_SESSION['auth_user_id'],
                $_SESSION['auth_user_name'],
                $_SESSION['auth_user_email'],
                $_SESSION['auth_user_role']
            );
            $_SESSION['login_flash'] = 'Your account has been permanently banned.';
            $_SESSION['flash_error'] = 'Your account has been permanently banned.';
            return Response::redirect('/admin/login');
        }

        // Enforce suspended restrictions: suspended users can view profile/settings and logout
        if ($currentUser->isSuspended()) {
            $allowedForSuspended = [
                '/admin',
                '/admin/',
                '/admin/users/profile',
                '/admin/users/profile/update',
                '/admin/logout',
            ];
            if (!in_array($path, $allowedForSuspended, true)) {
                if ($path === '/admin/posts' && $method === 'GET') {
                    // Allowed read-only posts index
                } else {
                    $_SESSION['flash_error'] = 'Your account is currently suspended. Protected site activities, content creation, and modifications are restricted.';
                    return Response::redirect('/admin/users/profile');
                }
            }
        }

        // Module 1: Dashboard
        if ($path === '/admin' || $path === '/admin/') {
            return (new DashboardController($this->app))->index($request);
        }

        // Dynamic Plugin Admin Pages: /admin/page/{slug}
        if (preg_match('#^/admin/page/([a-zA-Z0-9_\-]+)$#', $path, $m)) {
            return $this->dispatchPluginAdminPage($request, $m[1]);
        }

        // Module 2: Posts
        if (str_starts_with($path, '/admin/posts')) {
            $ctrl = new PostController($this->app);
            return match ($path) {
                '/admin/posts'             => $ctrl->index($request),
                '/admin/posts/new'         => $ctrl->create($request),
                '/admin/posts/store'       => $ctrl->store($request),
                '/admin/posts/edit'        => $ctrl->edit($request),
                '/admin/posts/update'      => $ctrl->update($request),
                '/admin/posts/preview'     => $ctrl->preview($request),
                '/admin/posts/approve'     => $ctrl->approve($request),
                '/admin/posts/reject'      => $ctrl->reject($request),
                '/admin/posts/trash'       => $ctrl->trash($request),
                '/admin/posts/restore'     => $ctrl->restore($request),
                '/admin/posts/delete'      => $ctrl->delete($request),
                '/admin/posts/bulk'        => $ctrl->bulkAction($request),
                '/admin/posts/quick-draft' => $ctrl->quickDraft($request),
                default                    => Response::redirect('/admin/posts'),
            };
        }

        // Module 3: Pages
        if (str_starts_with($path, '/admin/pages')) {
            $ctrl = new PageController($this->app);
            return match ($path) {
                '/admin/pages'         => $ctrl->index($request),
                '/admin/pages/new'     => $ctrl->create($request),
                '/admin/pages/store'   => $ctrl->store($request),
                '/admin/pages/edit'    => $ctrl->edit($request),
                '/admin/pages/update'  => $ctrl->update($request),
                '/admin/pages/preview' => $ctrl->preview($request),
                '/admin/pages/trash'   => $ctrl->trash($request),
                '/admin/pages/restore' => $ctrl->restore($request),
                '/admin/pages/delete'  => $ctrl->delete($request),
                '/admin/pages/bulk'    => $ctrl->bulkAction($request),
                default                => Response::redirect('/admin/pages'),
            };
        }

        // Module 4: Taxonomies
        if (str_starts_with($path, '/admin/taxonomies')) {
            $ctrl = new TaxonomyController($this->app);
            return match ($path) {
                '/admin/taxonomies/categories' => $ctrl->categories($request),
                '/admin/taxonomies/tags'       => $ctrl->tags($request),
                '/admin/taxonomies/store'      => $ctrl->store($request),
                '/admin/taxonomies/delete'     => $ctrl->delete($request),
                default                        => Response::redirect('/admin/taxonomies/categories'),
            };
        }

        // Module 5: Media
        if (str_starts_with($path, '/admin/media')) {
            $ctrl = new MediaController($this->app);
            return match ($path) {
                '/admin/media'              => $ctrl->index($request),
                '/admin/media/upload'       => $ctrl->upload($request),
                '/admin/media/upload-ajax'  => $ctrl->uploadAjax($request),
                '/admin/media/capabilities' => $ctrl->capabilities($request),
                '/admin/media/update'       => $ctrl->update($request),
                '/admin/media/delete'       => $ctrl->delete($request),
                default                     => Response::redirect('/admin/media'),
            };
        }

        // Module 6: Comments
        if (str_starts_with($path, '/admin/comments')) {
            if (!$currentUser->canModerateComments()) {
                return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to moderate comments.</p>', 403);
            }

            $ctrl = new CommentController($this->app);
            return match ($path) {
                '/admin/comments'           => $ctrl->index($request),
                '/admin/comments/approve'   => $ctrl->approve($request),
                '/admin/comments/unapprove' => $ctrl->unapprove($request),
                '/admin/comments/spam'      => $ctrl->spam($request),
                '/admin/comments/trash'     => $ctrl->trash($request),
                '/admin/comments/delete'    => $ctrl->delete($request),
                '/admin/comments/bulk'      => $ctrl->bulkAction($request),
                default                     => Response::redirect('/admin/comments'),
            };
        }

        // Module 7: Users & Profile
        if (str_starts_with($path, '/admin/users')) {
            $ctrl = new UserController($this->app);
            if ($path === '/admin/users/profile' || $path === '/admin/users/profile/update') {
                return $method === 'POST' ? $ctrl->updateProfile($request) : $ctrl->profile($request);
            }
            if ($path === '/admin/users/profile/delete-account' && $method === 'POST') {
                return $ctrl->deleteOwnAccount($request);
            }

            if (!$currentUser->canManageUsers()) {
                return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage users.</p>', 403);
            }

            return match ($path) {
                '/admin/users'                => $ctrl->index($request),
                '/admin/users/new'            => $ctrl->create($request),
                '/admin/users/store'          => $ctrl->store($request),
                '/admin/users/edit'           => $ctrl->edit($request),
                '/admin/users/update'         => $ctrl->update($request),
                '/admin/users/status'         => $ctrl->changeStatus($request),
                '/admin/users/role'           => $ctrl->changeRole($request),
                '/admin/users/delete'         => $ctrl->delete($request),
                '/admin/users/bulk'           => $ctrl->bulkAction($request),
                default                       => Response::redirect('/admin/users'),
            };
        }

        // Admin-only modules protection (Themes, Plugins, Widgets, Customize, Settings, Tools)
        $isAdmin = $currentUser->hasRole('admin') || $currentUser->hasRole('super-admin');

        // Module 8: Menus
        if (str_starts_with($path, '/admin/menus')) {
            if (!$isAdmin) {
                return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage menus.</p>', 403);
            }
            $ctrl = new MenuController($this->app);
            return match ($path) {
                '/admin/menus'             => $ctrl->index($request),
                '/admin/menus/create'      => $ctrl->createMenu($request),
                '/admin/menus/item/add'    => $ctrl->addItem($request),
                '/admin/menus/item/delete' => $ctrl->deleteItem($request),
                '/admin/menus/location'    => $ctrl->saveLocation($request),
                '/admin/menus/delete'      => $ctrl->deleteMenu($request),
                default                    => Response::redirect('/admin/menus'),
            };
        }

        // Module 9: Themes
        if (str_starts_with($path, '/admin/themes')) {
            if (!$isAdmin) {
                return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage themes.</p>', 403);
            }
            $ctrl = new ThemeController($this->app);
            return match ($path) {
                '/admin/themes'          => $ctrl->index($request),
                '/admin/themes/activate' => $ctrl->activate($request),
                '/admin/themes/upload'   => $ctrl->upload($request),
                '/admin/themes/delete'   => $ctrl->delete($request),
                default                  => Response::redirect('/admin/themes'),
            };
        }

        // Module 9b: Widgets
        if (str_starts_with($path, '/admin/widgets')) {
            if (!$isAdmin) {
                return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage widgets.</p>', 403);
            }
            $ctrl = new WidgetController($this->app);
            return match ($path) {
                '/admin/widgets'           => $ctrl->index($request),
                '/admin/widgets/store'     => $ctrl->store($request),
                '/admin/widgets/update'    => $ctrl->update($request),
                '/admin/widgets/delete'    => $ctrl->delete($request),
                '/admin/widgets/reorder'   => $ctrl->reorder($request),
                '/admin/widgets/move'      => $ctrl->move($request),
                '/admin/widgets/duplicate' => $ctrl->duplicate($request),
                '/admin/widgets/reset'     => $ctrl->reset($request),
                default                    => Response::redirect('/admin/widgets'),
            };
        }

        // Module 9c: Customize Theme
        if (str_starts_with($path, '/admin/customize')) {
            if (!$isAdmin) {
                return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to customize themes.</p>', 403);
            }
            $ctrl = new CustomizeController($this->app);
            return match ($path) {
                '/admin/customize'                  => $ctrl->index($request),
                '/admin/customize/save'             => $ctrl->save($request),
                '/admin/customize/sections/reorder' => $ctrl->reorderSections($request),
                '/admin/customize/reset'            => $ctrl->reset($request),
                default                             => Response::redirect('/admin/customize'),
            };
        }

        // Module 10: Plugins
        if (str_starts_with($path, '/admin/plugins')) {
            if (!$currentUser->canManagePlugins()) {
                return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage plugins.</p>', 403);
            }
            $ctrl = new PluginController($this->app);
            return match ($path) {
                '/admin/plugins'            => $ctrl->index($request),
                '/admin/plugins/activate'   => $ctrl->activate($request),
                '/admin/plugins/deactivate' => $ctrl->deactivate($request),
                '/admin/plugins/upload'     => $ctrl->upload($request),
                '/admin/plugins/delete'     => $ctrl->delete($request),
                '/admin/plugins/bulk'       => $ctrl->bulkAction($request),
                default                     => Response::redirect('/admin/plugins'),
            };
        }

        // Module 11: Settings
        if (str_starts_with($path, '/admin/settings')) {
            if (!$isAdmin) {
                return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to access settings.</p>', 403);
            }
            $ctrl = new SettingController($this->app);
            return match ($path) {
                '/admin/settings'        => $ctrl->index($request),
                '/admin/settings/update' => $ctrl->update($request),
                default                  => Response::redirect('/admin/settings'),
            };
        }

        // Module 12: SEO
        if (str_starts_with($path, '/admin/seo')) {
            if (!$isAdmin) {
                return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to access SEO settings.</p>', 403);
            }
            $ctrl = new SeoController($this->app);
            return match ($path) {
                '/admin/seo'        => $ctrl->index($request),
                '/admin/seo/update' => $ctrl->update($request),
                default             => Response::redirect('/admin/seo'),
            };
        }

        // Module 13: Tools & Backup
        if (str_starts_with($path, '/admin/tools')) {
            if (!$isAdmin) {
                return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to access tools.</p>', 403);
            }
            $ctrl = new ToolController($this->app);
            return match ($path) {
                '/admin/tools'                 => $ctrl->index($request),
                '/admin/tools/export'          => $ctrl->export($request),
                '/admin/tools/backup/create'   => $ctrl->createBackup($request),
                '/admin/tools/backup/download'        => $ctrl->downloadBackup($request),
                '/admin/tools/backup/delete'          => $ctrl->deleteBackup($request),
                '/admin/tools/restore'                => $ctrl->restoreBackup($request),
                '/admin/tools/import'                 => $ctrl->importIndex($request),
                '/admin/tools/import/preview'         => $ctrl->importPreview($request),
                '/admin/tools/import/process'         => $ctrl->importProcess($request),
                '/admin/tools/import/blogger/preview' => $ctrl->bloggerImportPreview($request),
                '/admin/tools/import/blogger'         => $ctrl->bloggerImportProcess($request),
                default                               => Response::redirect('/admin/tools'),
            };
        }

        return Response::redirect('/admin');
    }

    // -------------------------------------------------------------------------
    // Login & Logout
    // -------------------------------------------------------------------------
    protected function showLogin(Request $request, string $error = ''): Response
    {
        if (!empty($_SESSION['auth_user_id'])) {
            return Response::redirect('/admin');
        }

        $siteName = 'Favorite CMS';
        try {
            $db = $this->app->make(Database::class);
            $setting = $db->selectOne("SELECT value FROM `settings` WHERE `group_name` = 'general' AND `setting_key` = 'site_name' LIMIT 1");
            if ($setting && $setting->value) {
                $siteName = $setting->value;
            }
        } catch (\Throwable) {
        }

        if (empty($_SESSION['_token'])) {
            $_SESSION['_token'] = bin2hex(random_bytes(32));
        }
        $token = $_SESSION['_token'];

        $flashMsg = $_SESSION['login_flash'] ?? '';
        unset($_SESSION['login_flash']);

        $errorHtml = '';
        if ($error !== '') {
            $errorHtml = '<div class="alert alert-error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
        } elseif ($flashMsg !== '') {
            $errorHtml = '<div class="alert alert-info">' . htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $sn = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log In &lsaquo; $sn &mdash; Favorite CMS</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #f0f0f1;
            color: #3c434a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            font-size: 14px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1rem;
        }
        .login-box {
            background: #fff;
            border: 1px solid #c3c4c7;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            padding: 26px 24px;
            width: 100%;
            max-width: 360px;
        }
        .header { text-align: center; margin-bottom: 24px; }
        .header h1 { font-size: 24px; font-weight: 600; color: #1d2327; }
        .header .star { color: #e5a00d; font-size: 28px; }
        .alert { padding: 12px; border-left: 4px solid; margin-bottom: 16px; font-size: 13px; }
        .alert-error { background: #fcf0f1; border-color: #d63638; color: #8a1f11; }
        .alert-info { background: #f0f6fc; border-color: #2271b1; color: #1d4ed8; }
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 5px; font-weight: 500; color: #1d2327; font-size: 13.5px; }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #8c8f94;
            border-radius: 4px;
            font-size: 14px;
            color: #2c3338;
        }
        input:focus { border-color: #2271b1; outline: 2px solid transparent; box-shadow: 0 0 0 1px #2271b1; }
        .btn-submit {
            width: 100%;
            padding: 10px;
            background: #2271b1;
            border: 1px solid #2271b1;
            border-radius: 4px;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }
        .btn-submit:hover { background: #135e96; }
        .back-link { margin-top: 18px; text-align: center; font-size: 13px; }
        .back-link a { color: #2271b1; text-decoration: none; }
        .back-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="header">
        <h1><span class="star">&#9733;</span> $sn</h1>
    </div>
    <div class="login-box">
        $errorHtml
        <form method="POST" action="/admin/login">
            <input type="hidden" name="_token" value="$token">
            <div class="form-group">
                <label for="login">Username or Email Address</label>
                <input type="text" id="login" name="login" required autofocus autocomplete="username">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn-submit">Log In</button>
        </form>
        <div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid #e2e8f0; text-align: center; font-size: 13px;">
            Don't have an account? <a href="/register" style="color: #2271b1; text-decoration: none; font-weight: 600;">Sign Up</a>
        </div>
        <div style="margin-top: 8px; text-align: center; font-size: 12.5px;">
            <a href="/resend-verification" style="color: #64748b; text-decoration: none;">Resend email verification</a>
        </div>
    </div>
    <div class="back-link">
        <a href="/">&larr; Go to $sn</a>
    </div>
</body>
</html>
HTML;

        return Response::make($html, 200);
    }

    protected function processLogin(Request $request): Response
    {
        $token  = (string)$request->post('_token', '');
        $stored = (string)($_SESSION['_token'] ?? '');
        if ($stored === '' || !hash_equals($stored, $token)) {
            return $this->showLogin($request, 'Invalid security token. Please try again.');
        }

        $login    = trim((string)$request->post('login', ''));
        $password = (string)$request->post('password', '');

        if ($login === '' || $password === '') {
            return $this->showLogin($request, 'Please enter both your username/email and password.');
        }

        try {
            $db = $this->app->make(Database::class);
            $user = $db->selectOne(
                "SELECT * FROM `users` WHERE `email` = ? OR `username` = ? LIMIT 1",
                [$login, $login]
            );

            if (!$user || !password_verify($password, $user->password)) {
                return $this->showLogin($request, 'Error: The password you entered for the username or email is incorrect.');
            }

            if ($user->status === 'banned') {
                return $this->showLogin($request, 'Your account has been permanently banned.');
            }

            if ($user->status === 'inactive') {
                return $this->showLogin($request, 'Your account is currently inactive.');
            }

            if (EmailVerificationService::isRequired() && empty($user->email_verified_at)) {
                return $this->showLogin($request, 'Your email address is not yet verified. Please check your inbox or resend verification link.');
            }

            $_SESSION['auth_user_id']    = $user->id;
            $_SESSION['auth_user_name']  = $user->name ?? $user->username ?? 'User';
            $_SESSION['auth_user_email'] = $user->email;

            $db->execute("UPDATE `users` SET `last_login_at` = ? WHERE `id` = ?", [date('Y-m-d H:i:s'), $user->id]);

            if ($user->status === 'suspended') {
                $_SESSION['flash_error'] = 'Your account is currently suspended. Site activity and content creation are restricted.';
                return Response::redirect('/admin/users/profile');
            }

            return Response::redirect('/admin');

        } catch (\Throwable $e) {
            return $this->showLogin($request, 'Authentication error: ' . $e->getMessage());
        }
    }

    protected function showRegister(Request $request, ?string $error = null, array $old = []): Response
    {
        $regEnabled = (int)Setting::get('general', 'allow_registration', 1);
        if (!$regEnabled && $error === null) {
            $error = 'Public registration is currently disabled by the site administrator.';
        }

        if (empty($_SESSION['_token'])) {
            $_SESSION['_token'] = bin2hex(random_bytes(32));
        }
        $token = htmlspecialchars($_SESSION['_token'], ENT_QUOTES, 'UTF-8');
        $siteName = Setting::get('general', 'site_name', 'Favorite CMS');
        $sn = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');

        $errorHtml = '';
        if ($error) {
            $errorHtml = '<div class="alert alert-error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $oldUsername = htmlspecialchars($old['username'] ?? '', ENT_QUOTES, 'UTF-8');
        $oldName     = htmlspecialchars($old['name'] ?? '', ENT_QUOTES, 'UTF-8');
        $oldEmail    = htmlspecialchars($old['email'] ?? '', ENT_QUOTES, 'UTF-8');

        $formDisabled = !$regEnabled ? 'disabled' : '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up &lsaquo; $sn &mdash; Favorite CMS</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background: #f0f0f1;
            color: #2c3338;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1rem;
        }
        .register-box {
            background: #fff;
            border: 1px solid #c3c4c7;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            padding: 26px 24px;
            width: 100%;
            max-width: 400px;
            border-radius: 4px;
        }
        .header { text-align: center; margin-bottom: 20px; }
        .header h1 { font-size: 22px; font-weight: 600; color: #1d2327; }
        .header .star { color: #e5a00d; font-size: 26px; }
        .alert { padding: 12px; border-left: 4px solid; margin-bottom: 16px; font-size: 13px; }
        .alert-error { background: #fcf0f1; border-color: #d63638; color: #8a1f11; }
        .form-group { margin-bottom: 14px; }
        label { display: block; margin-bottom: 5px; font-weight: 500; color: #1d2327; font-size: 13px; }
        input[type="text"], input[type="email"], input[type="password"] {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #8c8f94;
            border-radius: 4px;
            font-size: 14px;
            color: #2c3338;
        }
        input:focus { border-color: #2271b1; outline: 2px solid transparent; box-shadow: 0 0 0 1px #2271b1; }
        .btn-submit {
            width: 100%;
            padding: 10px;
            background: #2271b1;
            border: 1px solid #2271b1;
            border-radius: 4px;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
            margin-top: 6px;
        }
        .btn-submit:hover { background: #135e96; }
        .btn-submit:disabled { background: #94a3b8; border-color: #94a3b8; cursor: not-allowed; }
        .footer-links { margin-top: 18px; text-align: center; font-size: 13px; }
        .footer-links a { color: #2271b1; text-decoration: none; }
        .footer-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="header">
        <h1><span class="star">&#9733;</span> $sn</h1>
    </div>
    <div class="register-box">
        $errorHtml
        <form method="POST" action="/register">
            <input type="hidden" name="_token" value="$token">
            <div class="form-group">
                <label for="username">Username (required)</label>
                <input type="text" id="username" name="username" value="$oldUsername" required autofocus autocomplete="username" $formDisabled>
            </div>
            <div class="form-group">
                <label for="name">Display Name (optional)</label>
                <input type="text" id="name" name="name" value="$oldName" autocomplete="name" $formDisabled>
            </div>
            <div class="form-group">
                <label for="email">Email Address (required)</label>
                <input type="email" id="email" name="email" value="$oldEmail" required autocomplete="email" $formDisabled>
            </div>
            <div class="form-group">
                <label for="password">Password (min 8 characters)</label>
                <input type="password" id="password" name="password" required autocomplete="new-password" minlength="8" $formDisabled>
            </div>
            <div class="form-group">
                <label for="password_confirmation">Confirm Password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password" minlength="8" $formDisabled>
            </div>
            <button type="submit" class="btn-submit" $formDisabled>Create Account</button>
        </form>
        <div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid #e2e8f0; text-align: center; font-size: 13px;">
            Already have an account? <a href="/admin/login" style="color: #2271b1; text-decoration: none; font-weight: 600;">Log In</a>
        </div>
    </div>
    <div class="footer-links">
        <a href="/">&larr; Go to $sn</a>
    </div>
</body>
</html>
HTML;

        return Response::make($html, 200);
    }

    protected function processRegister(Request $request): Response
    {
        $token  = (string)$request->post('_token', '');
        $stored = (string)($_SESSION['_token'] ?? '');
        if ($stored === '' || !hash_equals($stored, $token)) {
            return $this->showRegister($request, 'Invalid security token. Please try again.');
        }

        $regEnabled = (int)Setting::get('general', 'allow_registration', 1);
        if (!$regEnabled) {
            return $this->showRegister($request, 'Public registration is currently disabled by the site administrator.');
        }

        $username = trim((string)$request->post('username', ''));
        $name     = trim((string)$request->post('name', ''));
        $email    = trim((string)$request->post('email', ''));
        $password = (string)$request->post('password', '');
        $passwordConfirm = (string)$request->post('password_confirmation', '');

        $old = ['username' => $username, 'name' => $name, 'email' => $email];

        if ($username === '' || $email === '' || $password === '') {
            return $this->showRegister($request, 'Please complete all required fields.', $old);
        }

        if (!preg_match('/^[a-zA-Z0-9_\-\.]{3,30}$/', $username)) {
            return $this->showRegister($request, 'Username must be 3-30 alphanumeric characters, dots, dashes, or underscores.', $old);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->showRegister($request, 'Please enter a valid email address.', $old);
        }

        if (strlen($password) < 8) {
            return $this->showRegister($request, 'Password must be at least 8 characters long.', $old);
        }

        if ($password !== $passwordConfirm) {
            return $this->showRegister($request, 'Passwords do not match.', $old);
        }

        try {
            $db = $this->app->make(Database::class);

            // Anti-ban / Anti-suspension bypass check: reject recycling suspended/banned accounts
            $statusCheck = $db->selectOne(
                "SELECT `status` FROM `users` WHERE `email` = ? OR `username` = ? LIMIT 1",
                [$email, $username]
            );
            if ($statusCheck && in_array($statusCheck->status, ['suspended', 'banned'], true)) {
                return $this->showRegister($request, 'This email address or username is unavailable for registration. Please contact site support.', $old);
            }

            if ($statusCheck) {
                return $this->showRegister($request, 'A user with this username or email already exists.', $old);
            }

            $now = date('Y-m-d H:i:s');
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $requiresVerification = EmailVerificationService::isRequired();

            $userId = $db->insert('users', [
                'username'          => $username,
                'name'              => $name !== '' ? $name : $username,
                'email'             => $email,
                'password'          => $hash,
                'status'            => 'active',
                'email_verified_at' => $requiresVerification ? null : $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

            // Assign Normal User role ('subscriber')
            $role = $db->selectOne("SELECT id FROM `roles` WHERE `slug` = 'subscriber' LIMIT 1");
            if ($role) {
                $db->execute("INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$userId, $role->id]);
            }

            if ($requiresVerification) {
                $user = User::find($userId);
                if ($user) {
                    $verifService = new EmailVerificationService($db);
                    $token = $verifService->createVerificationToken($user, $email);
                    $verifService->sendVerificationEmail($user, $email, $token, false);
                }

                $_SESSION['login_flash'] = 'Registration successful! A verification email has been sent. Please check your inbox and verify your email to log in.';
                $_SESSION['flash_info']  = 'Registration successful! A verification email has been sent. Please check your inbox and verify your email to log in.';
                return Response::redirect('/admin/login');
            }

            // Automatically authenticate user if email verification is not required
            $_SESSION['auth_user_id']    = $userId;
            $_SESSION['auth_user_name']  = $name !== '' ? $name : $username;
            $_SESSION['auth_user_email'] = $email;

            $_SESSION['flash_success'] = 'Welcome, ' . htmlspecialchars($username) . '! Your account has been registered successfully.';
            return Response::redirect('/admin');

        } catch (\Throwable $e) {
            return $this->showRegister($request, 'Registration error: ' . $e->getMessage(), $old);
        }
    }

    protected function processLogout(Request $request): Response
    {
        unset($_SESSION['auth_user_id'], $_SESSION['auth_user_name'], $_SESSION['auth_user_email']);
        $_SESSION['login_flash'] = 'You have been successfully logged out.';
        $redirect = $request->get('redirect');
        if ($redirect && \FavoriteCMS\Core\AccountMenu::isValidUrl((string)$redirect)) {
            return Response::redirect((string)$redirect);
        }
        return Response::redirect($request->path() === '/logout' ? '/' : '/admin/login');
    }

    // -------------------------------------------------------------------------
    // Email Verification Endpoints
    // -------------------------------------------------------------------------
    protected function handleEmailVerification(Request $request): Response
    {
        $token = (string)$request->get('token', '');
        $db = $this->app->make(Database::class);
        $service = new EmailVerificationService($db);
        $result = $service->verifyToken($token);

        if ($result['success']) {
            $user = $result['user'];
            if ($result['isEmailChange']) {
                if (!empty($_SESSION['auth_user_id']) && (int)$_SESSION['auth_user_id'] === (int)$user->id) {
                    $_SESSION['auth_user_email'] = $user->email;
                }
                $_SESSION['flash_success'] = 'Your email address has been successfully updated and verified!';
                return Response::redirect(!empty($_SESSION['auth_user_id']) ? '/admin/users/profile' : '/admin/login');
            }

            $_SESSION['login_flash'] = 'Your email has been successfully verified! You may now sign in.';
            $_SESSION['flash_success'] = 'Your email has been successfully verified! You may now sign in.';
            return Response::redirect('/admin/login');
        }

        return $this->showVerificationResult(false, $result['error'] ?? 'Verification link is invalid or expired.');
    }

    protected function showVerificationResult(bool $success, string $message): Response
    {
        $siteName = Setting::get('general', 'site_name', 'Favorite CMS');
        $sn = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
        $msgHtml = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $alertClass = $success ? 'alert-success' : 'alert-error';
        $title = $success ? 'Email Verified' : 'Verification Failed';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title} &lsaquo; {$sn} &mdash; Favorite CMS</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #f0f0f1;
            color: #3c434a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            font-size: 14px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1rem;
        }
        .box {
            background: #fff;
            border: 1px solid #c3c4c7;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            padding: 28px 24px;
            width: 100%;
            max-width: 420px;
            border-radius: 4px;
            text-align: center;
        }
        .alert { padding: 12px; border-left: 4px solid; margin-bottom: 20px; font-size: 13.5px; text-align: left; }
        .alert-error { background: #fcf0f1; border-color: #d63638; color: #8a1f11; }
        .alert-success { background: #f0fdf4; border-color: #16a34a; color: #166534; }
        .btn { display: inline-block; padding: 10px 18px; border-radius: 4px; font-size: 13.5px; font-weight: 600; text-decoration: none; }
        .btn-primary { background: #2271b1; color: #fff; border: 1px solid #2271b1; }
        .btn-secondary { background: #fff; color: #3c434a; border: 1px solid #c3c4c7; margin-left: 8px; }
    </style>
</head>
<body>
    <div class="box">
        <h1 style="font-size: 20px; margin-bottom: 16px; color: #1d2327;">{$title}</h1>
        <div class="alert {$alertClass}">{$msgHtml}</div>
        <div style="margin-top: 20px;">
            <a href="/admin/login" class="btn btn-primary">Sign In</a>
            <a href="/resend-verification" class="btn btn-secondary">Resend Link</a>
        </div>
    </div>
</body>
</html>
HTML;
        return Response::make($html, $success ? 200 : 400);
    }

    protected function showResendVerification(Request $request, ?string $error = null, ?string $success = null): Response
    {
        if (empty($_SESSION['_token'])) {
            $_SESSION['_token'] = bin2hex(random_bytes(32));
        }
        $token = htmlspecialchars($_SESSION['_token'], ENT_QUOTES, 'UTF-8');
        $siteName = Setting::get('general', 'site_name', 'Favorite CMS');
        $sn = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
        $emailParam = htmlspecialchars(trim((string)$request->get('email', '')), ENT_QUOTES, 'UTF-8');

        $statusHtml = '';
        if ($error) {
            $statusHtml = '<div class="alert alert-error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
        } elseif ($success) {
            $statusHtml = '<div class="alert alert-success">' . htmlspecialchars($success, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Resend Email Verification &lsaquo; {$sn} &mdash; Favorite CMS</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #f0f0f1;
            color: #3c434a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            font-size: 14px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1rem;
        }
        .box {
            background: #fff;
            border: 1px solid #c3c4c7;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            padding: 26px 24px;
            width: 100%;
            max-width: 380px;
            border-radius: 4px;
        }
        .header { text-align: center; margin-bottom: 20px; }
        .header h1 { font-size: 20px; font-weight: 600; color: #1d2327; }
        .alert { padding: 12px; border-left: 4px solid; margin-bottom: 16px; font-size: 13px; }
        .alert-error { background: #fcf0f1; border-color: #d63638; color: #8a1f11; }
        .alert-success { background: #f0fdf4; border-color: #16a34a; color: #166534; }
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; }
        input[type="email"] {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #8c8f94;
            border-radius: 4px;
            font-size: 14px;
        }
        .btn-submit {
            width: 100%;
            padding: 10px;
            background: #2271b1;
            border: 1px solid #2271b1;
            border-radius: 4px;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
        }
        .back-link { margin-top: 16px; text-align: center; font-size: 13px; }
        .back-link a { color: #2271b1; text-decoration: none; }
    </style>
</head>
<body>
    <div class="box">
        <div class="header">
            <h1>Resend Verification Email</h1>
        </div>
        {$statusHtml}
        <form method="POST" action="/resend-verification">
            <input type="hidden" name="_token" value="{$token}">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" value="{$emailParam}" required autofocus autocomplete="email">
            </div>
            <button type="submit" class="btn-submit">Send Verification Link</button>
        </form>
        <div class="back-link">
            <a href="/admin/login">&larr; Back to Log In</a>
        </div>
    </div>
</body>
</html>
HTML;
        return Response::make($html, 200);
    }

    protected function processResendVerification(Request $request): Response
    {
        $token  = (string)$request->post('_token', '');
        $stored = (string)($_SESSION['_token'] ?? '');
        if ($stored === '' || !hash_equals($stored, $token)) {
            return $this->showResendVerification($request, 'Invalid security token. Please try again.');
        }

        $email = trim((string)$request->post('email', ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->showResendVerification($request, 'Please enter a valid email address.');
        }

        $db = $this->app->make(Database::class);
        $service = new EmailVerificationService($db);

        // Enforce rate-limiting cooldown
        if (!$service->canResend($email)) {
            $wait = $service->getSecondsUntilResend($email);
            return $this->showResendVerification($request, "Please wait {$wait} seconds before requesting another verification email.");
        }

        // Generic anti-enumeration response
        $genericMsg = 'If an unverified account associated with this email exists, a new verification link has been sent. Please check your inbox.';

        $user = User::findByEmail($email);
        if ($user && !$user->isEmailVerified() && $user->status !== 'banned') {
            $newToken = $service->createVerificationToken($user, $email);
            $service->sendVerificationEmail($user, $email, $newToken, false);
        }

        return $this->showResendVerification($request, null, $genericMsg);
    }

    protected function dispatchPluginAdminPage(Request $request, string $slug): Response
    {
        $page = \FavoriteCMS\Core\AdminMenu::findPage($slug);
        if (!$page) {
            return $this->notFound($request);
        }

        $cap = $page['capability'] ?? 'manage_options';
        if (!current_user_can($cap)) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to access this page.</p>', 403);
        }

        $handler = $page['handler'] ?? null;
        if (!is_callable($handler)) {
            return Response::make('<h1>Error</h1><p>Admin page handler is not callable.</p>', 500);
        }

        $content = call_user_func($handler, $request);
        if ($content instanceof Response) {
            return $content;
        }

        $siteName = \FavoriteCMS\Models\Setting::get('general', 'site_name', 'Favorite CMS');
        $username = $_SESSION['auth_user_name'] ?? 'Admin';
        $activeMenu = $slug;
        $pageTitle = $page['title'] ?? 'Plugin Page';
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        // Wrap inside admin layout
        ob_start();
        ?>
        <div class="page-header">
            <h1 class="page-title"><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        </div>
        <div class="plugin-page-card" style="background: #fff; padding: 24px; border: 1px solid #c3c4c7; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <?php echo (string)$content; ?>
        </div>
        <?php
        $customHtml = (string)ob_get_clean();

        $viewData = [
            'siteName'     => $siteName,
            'username'     => $username,
            'activeMenu'   => $activeMenu,
            'pageTitle'    => $pageTitle,
            'flashSuccess' => $flashSuccess,
            'flashError'   => $flashError,
            'contentView'  => null,
            'customHtml'   => $customHtml,
        ];
        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    protected function serveStaticAsset(string $path): ?Response
    {
        // Prevent directory traversal
        if (str_contains($path, '..')) {
            return null;
        }

        $filePath = APP_ROOT . $path;
        if (!file_exists($filePath) || is_dir($filePath)) {
            return null;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimes = [
            'css'   => 'text/css; charset=utf-8',
            'js'    => 'application/javascript; charset=utf-8',
            'json'  => 'application/json',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'svg'   => 'image/svg+xml',
            'ico'   => 'image/x-icon',
            'webp'  => 'image/webp',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
            'eot'   => 'application/vnd.ms-fontobject',
        ];

        $mime = $mimes[$ext] ?? 'application/octet-stream';
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $res = Response::make($content, 200);
        $res->header('Content-Type', $mime);
        $res->header('Cache-Control', 'public, max-age=86400');
        return $res;
    }

    protected function notFound(Request $request): Response
    {
        try {
            $engine = new Engine($this->app);
            $html = $engine->render('404');
            return Response::make($html, 404);
        } catch (\Throwable) {
            return Response::make('<h1>404 Not Found</h1>', 404);
        }
    }

    protected function handleException(\Throwable $e): Response
    {
        if (env('APP_DEBUG', false)) {
            $msg   = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');
            $class = htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8');
            $file  = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
            $line  = $e->getLine();

            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
                  . '<title>Error — Favorite CMS</title>'
                  . '<style>body{font-family:monospace;background:#1e1e2e;color:#cdd6f4;margin:0;padding:2rem}'
                  . 'h1{color:#f38ba8;font-size:1.4rem;margin-bottom:.5rem}'
                  . '.meta{color:#a6e3a1;font-size:.85rem;margin-bottom:1.5rem}'
                  . 'pre{background:#181825;padding:1.5rem;border-radius:8px;overflow-x:auto;font-size:.82rem;line-height:1.6}'
                  . '</style></head><body>'
                  . "<h1>$class</h1>"
                  . "<div class=\"meta\">$file : line $line</div>"
                  . "<pre>$msg\n\n$trace</pre>"
                  . '</body></html>';

            return Response::make($html, 500);
        }

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>500 — Server Error</title></head>'
              . '<body style="font-family:system-ui;text-align:center;padding:4rem">'
              . '<h1>500 — Internal Server Error</h1>'
              . '<p>Something went wrong. Please try again later.</p>'
              . '<a href="/">← Go home</a></body></html>';

        return Response::make($html, 500);
    }
}
