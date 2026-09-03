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
use FavoriteCMS\Rendering\Engine;

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
                '/admin/posts/trash'       => $ctrl->trash($request),
                '/admin/posts/restore'     => $ctrl->restore($request),
                '/admin/posts/delete'      => $ctrl->delete($request),
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
            $ctrl = new CommentController($this->app);
            return match ($path) {
                '/admin/comments'           => $ctrl->index($request),
                '/admin/comments/approve'   => $ctrl->approve($request),
                '/admin/comments/unapprove' => $ctrl->unapprove($request),
                '/admin/comments/spam'      => $ctrl->spam($request),
                '/admin/comments/trash'     => $ctrl->trash($request),
                '/admin/comments/delete'    => $ctrl->delete($request),
                default                     => Response::redirect('/admin/comments'),
            };
        }

        // Module 7: Users & Profile
        if (str_starts_with($path, '/admin/users')) {
            $ctrl = new UserController($this->app);
            return match ($path) {
                '/admin/users'                => $ctrl->index($request),
                '/admin/users/new'            => $ctrl->create($request),
                '/admin/users/store'          => $ctrl->store($request),
                '/admin/users/edit'           => $ctrl->edit($request),
                '/admin/users/update'         => $ctrl->update($request),
                '/admin/users/profile'        => $ctrl->profile($request),
                '/admin/users/profile/update' => $ctrl->updateProfile($request),
                '/admin/users/delete'         => $ctrl->delete($request),
                default                       => Response::redirect('/admin/users'),
            };
        }

        // Module 8: Menus
        if (str_starts_with($path, '/admin/menus')) {
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
            $ctrl = new PluginController($this->app);
            return match ($path) {
                '/admin/plugins'            => $ctrl->index($request),
                '/admin/plugins/activate'   => $ctrl->activate($request),
                '/admin/plugins/deactivate' => $ctrl->deactivate($request),
                '/admin/plugins/upload'     => $ctrl->upload($request),
                '/admin/plugins/delete'     => $ctrl->delete($request),
                default                     => Response::redirect('/admin/plugins'),
            };
        }

        // Module 11: Settings
        if (str_starts_with($path, '/admin/settings')) {
            $ctrl = new SettingController($this->app);
            return match ($path) {
                '/admin/settings'        => $ctrl->index($request),
                '/admin/settings/update' => $ctrl->update($request),
                default                  => Response::redirect('/admin/settings'),
            };
        }

        // Module 12: SEO
        if (str_starts_with($path, '/admin/seo')) {
            $ctrl = new SeoController($this->app);
            return match ($path) {
                '/admin/seo'        => $ctrl->index($request),
                '/admin/seo/update' => $ctrl->update($request),
                default             => Response::redirect('/admin/seo'),
            };
        }

        // Module 13: Tools & Backup
        if (str_starts_with($path, '/admin/tools')) {
            $ctrl = new ToolController($this->app);
            return match ($path) {
                '/admin/tools'        => $ctrl->index($request),
                '/admin/tools/export' => $ctrl->export($request),
                default               => Response::redirect('/admin/tools'),
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

            if (($user->status ?? 'active') !== 'active') {
                return $this->showLogin($request, 'Your account is currently inactive or suspended.');
            }

            $_SESSION['auth_user_id']    = $user->id;
            $_SESSION['auth_user_name']  = $user->name ?? $user->username ?? 'Admin';
            $_SESSION['auth_user_email'] = $user->email;

            $db->execute("UPDATE `users` SET `last_login_at` = ? WHERE `id` = ?", [date('Y-m-d H:i:s'), $user->id]);

            return Response::redirect('/admin');

        } catch (\Throwable $e) {
            return $this->showLogin($request, 'Authentication error: ' . $e->getMessage());
        }
    }

    protected function processLogout(Request $request): Response
    {
        unset($_SESSION['auth_user_id'], $_SESSION['auth_user_name'], $_SESSION['auth_user_email']);
        $_SESSION['login_flash'] = 'You have been successfully logged out.';
        return Response::redirect('/admin/login');
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
