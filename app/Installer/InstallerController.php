<?php

declare(strict_types=1);

namespace FavoriteCMS\Installer;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Services\RestoreService;
use Throwable;

class InstallerController
{
    protected UrlResolver $urls;
    protected CsrfService $csrf;
    protected EnvironmentChecker $environment;
    protected DatabaseProvisioner $databases;
    protected InstallationStateManager $state;
    protected InstallationService $installer;

    public function __construct(protected Application $app)
    {
        $this->urls = new UrlResolver();
        $this->csrf = new CsrfService();
        $this->environment = new EnvironmentChecker($this->urls);
        $this->databases = new DatabaseProvisioner();
        $this->state = new InstallationStateManager();
        $this->installer = new InstallationService($app, $this->databases, $this->state);
    }

    public function handle(Request $request): Response
    {
        if ($this->app->isInstalled()) {
            return Response::redirect('/');
        }

        if ($request->method() === 'POST') {
            return $this->process($request);
        }

        return $this->show($request);
    }

    protected function show(Request $request, array $errors = [], array $notices = [], array $old = []): Response
    {
        $checks = $this->environment->check($request);
        $dbStatus = $this->detectDatabaseStatus();
        $detectedUrl = $this->urls->currentBaseUrl($request);

        $defaultConfig = $this->databases->defaultConfig();
        $dbDefaults = array_merge([
            'db_host' => $defaultConfig['host'] !== '' ? $defaultConfig['host'] : 'localhost',
            'db_port' => $defaultConfig['port'] !== '' ? $defaultConfig['port'] : '3306',
            'db_name' => $defaultConfig['database'],
            'db_username' => $defaultConfig['username'],
            'db_prefix' => $defaultConfig['prefix'] !== '' ? $defaultConfig['prefix'] : $this->databases->generateTablePrefix(),
            'setup_mode' => 'recommended',
        ], $old);

        $content = $this->renderView('installer/install', [
            'checks' => $checks,
            'hasRequirementFailures' => $this->environment->hasFailures($checks),
            'dbStatus' => $dbStatus,
            'errors' => $errors,
            'notices' => $notices,
            'old' => $old,
            'dbDefaults' => $dbDefaults,
            'token' => $this->csrf->token(),
            'detectedUrl' => $detectedUrl,
            'installAction' => $this->urls->route($request, '/install'),
            'basePath' => $request->basePath(),
        ]);

        return $this->noCache(Response::make($content, 200));
    }

    protected function process(Request $request): Response
    {
        if (!$this->csrf->validate((string)$request->post('_token', ''))) {
            return $this->show($request, ['Invalid or expired security token. A fresh token has been created; please review the form and try again.'], [], $this->safeOld($request));
        }

        $action = (string)$request->post('db_action', 'install');
        $old = $this->safeOld($request);

        // Handle Site Restoration from Backup Archive if submitted
        if ($action === 'restore') {
            return $this->processRestore($request, $old);
        }

        $dbConfig = $this->databases->normalize($request->all());
        $mode = (string)$request->post('setup_mode', 'recommended');

        if ($mode === 'automatic') {
            $adminConfig = $dbConfig;
            $adminConfig['username'] = trim((string)$request->post('db_admin_username', $dbConfig['username']));
            $adminConfig['password'] = (string)$request->post('db_admin_password', $dbConfig['password']);

            $auto = $this->databases->createAutomatically(
                $adminConfig,
                trim((string)$request->post('db_username', '')),
                (string)$request->post('db_password', '')
            );

            if (!$auto['ok']) {
                $old['setup_mode'] = 'advanced';
                return $this->show($request, [(string)$auto['message']], ['Advanced Database Setup is available below to verify manual credentials.'], $old);
            }

            $dbConfig = $auto['config'];
        }

        if ($action === 'test_database') {
            try {
                $this->databases->testConnection($dbConfig);
                return $this->show($request, [], ['Database connection verified successfully! Everything is ready for installation.'], $old);
            } catch (Throwable $e) {
                return $this->show($request, [$this->databasePublicMessage($e, $dbConfig)], [], $old);
            }
        }

        $site = $this->validateSite($request);
        $admin = $this->validateAdmin($request);
        $errors = array_merge($this->databases->validate($dbConfig), $site['errors'], $admin['errors']);

        if ($errors !== []) {
            return $this->show($request, $errors, [], $old);
        }

        try {
            $result = $this->installer->install($dbConfig, $site['data'], $admin['data']);
            (new InstallerSession($this->urls))->regenerate();
        } catch (Throwable $e) {
            return $this->show($request, [$this->installer->publicMessage($e)], [], $old);
        }

        $content = $this->renderView('installer/success', [
            'siteName' => $site['data']['name'],
            'siteUrl' => $site['data']['url'],
            'adminUsername' => $admin['data']['username'],
            'adminEmail' => $admin['data']['email'],
            'loginUrl' => $this->urls->route($request, '/admin/login'),
            'homeUrl' => $this->urls->route($request, '/'),
            'migrations' => $result['applied_migrations'],
        ]);

        return $this->noCache(Response::make($content, 200));
    }

    /**
     * Process restoring a backup file directly from the installer wizard.
     */
    protected function processRestore(Request $request, array $old): Response
    {
        $uploaded = $_FILES['backup_file'] ?? null;
        if (!$uploaded || empty($uploaded['tmp_name']) || $uploaded['error'] !== UPLOAD_ERR_OK) {
            return $this->show($request, ['Please select a valid Favorite CMS backup (.zip) file to restore.'], [], $old);
        }

        $dbConfig = $this->databases->normalize($request->all());
        $validationErrors = $this->databases->validate($dbConfig);
        if (!empty($validationErrors)) {
            return $this->show($request, $validationErrors, [], $old);
        }

        try {
            $restoreService = new RestoreService();
            $inspection = $restoreService->inspectBackup($uploaded['tmp_name']);

            $detectedUrl = $this->urls->currentBaseUrl($request);
            $siteUrl = $this->urls->normalizeSiteUrl((string)$request->post('site_url', '')) ?: $detectedUrl;

            $result = $restoreService->restoreBackup(
                $uploaded['tmp_name'],
                $dbConfig,
                $siteUrl,
                true // Explicit consent during installer restore
            );

            // Write .env configuration
            $this->databases->writeEnv($dbConfig, $siteUrl);

            // Write installation lockfile
            $lockPath = APP_ROOT . '/storage/installed.lock';
            @file_put_contents($lockPath, date('c') . " - Restored from backup\n");

            (new InstallerSession($this->urls))->regenerate();

            $content = $this->renderView('installer/success', [
                'siteName' => $inspection['site_name'] . ' (Restored)',
                'siteUrl' => $siteUrl,
                'adminUsername' => 'Original Administrator',
                'adminEmail' => 'As configured in backup',
                'loginUrl' => $this->urls->route($request, '/admin/login'),
                'homeUrl' => $this->urls->route($request, '/'),
                'migrations' => $inspection['tables'],
            ]);

            return $this->noCache(Response::make($content, 200));
        } catch (Throwable $e) {
            return $this->show($request, ['Restore failed: ' . $e->getMessage()], [], $old);
        }
    }

    protected function detectDatabaseStatus(): array
    {
        $defaults = $this->databases->defaultConfig();
        if (($defaults['database'] ?? '') === '' || ($defaults['username'] ?? '') === '') {
            return [
                'connected' => false,
                'message' => 'Recommended setup automatically configures your database host and prefix. Enter your database credentials below to begin.',
                'state' => 'missing',
            ];
        }

        try {
            $db = new Database($defaults);
            if ($this->state->databaseLooksInstalled($db)) {
                return ['connected' => true, 'message' => 'Existing Favorite CMS installation detected in this database.', 'state' => 'installed'];
            }
            if ($this->state->databaseLooksPartial($db)) {
                return ['connected' => true, 'message' => 'Partial Favorite CMS tables detected. The installer can safely resume.', 'state' => 'partial'];
            }

            return ['connected' => true, 'message' => 'Pre-configured database connection is available.', 'state' => 'ready'];
        } catch (Throwable $e) {
            return [
                'connected' => false,
                'message' => 'Recommended setup automatically defaults to localhost (3306). Please enter your database name, username, and password.',
                'state' => 'ready',
            ];
        }
    }

    protected function validateSite(Request $request): array
    {
        $name = trim((string)$request->post('site_name', ''));
        $url = $this->urls->normalizeSiteUrl((string)$request->post('site_url', '')) ?: $this->urls->currentBaseUrl($request);
        $errors = [];

        if ($name === '') {
            $errors[] = 'Please provide a site name.';
        }
        if (!$this->urls->normalizeSiteUrl($url)) {
            $errors[] = 'Please provide a valid site URL.';
        }

        return ['errors' => $errors, 'data' => ['name' => $name, 'url' => $url]];
    }

    protected function validateAdmin(Request $request): array
    {
        $username = trim((string)$request->post('admin_username', ''));
        $email = trim((string)$request->post('admin_email', ''));
        $password = (string)$request->post('admin_password', '');
        $confirm = (string)$request->post('admin_password_confirm', '');
        $errors = [];

        if ($username === '') {
            $errors[] = 'Please choose an admin username.';
        } elseif (strlen($username) < 3 || strlen($username) > 60 || !preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
            $errors[] = 'Admin username must be 3-60 characters and may contain letters, numbers, underscores, hyphens, and periods.';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid admin email address.';
        }

        if (strlen($password) < 10) {
            $errors[] = 'Admin password must be at least 10 characters long.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'Admin password must include at least one letter and one number.';
        }
        if ($password !== $confirm) {
            $errors[] = 'The admin password and confirmation do not match.';
        }

        return [
            'errors' => $errors,
            'data' => [
                'username' => $username,
                'email' => $email,
                'password' => $password,
            ],
        ];
    }

    protected function safeOld(Request $request): array
    {
        $keys = [
            'setup_mode',
            'db_host',
            'db_port',
            'db_name',
            'db_username',
            'db_prefix',
            'site_name',
            'site_url',
            'admin_username',
            'admin_email',
        ];

        $old = [];
        foreach ($keys as $key) {
            $old[$key] = trim((string)$request->post($key, ''));
        }

        return $old;
    }

    protected function databasePublicMessage(Throwable $e, array $config = []): string
    {
        return $this->databases->formatDatabaseError($e, $config);
    }

    protected function noCache(Response $response): Response
    {
        return $response
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    protected function renderView(string $template, array $data = []): string
    {
        $path = APP_ROOT . '/resources/views/' . $template . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException("Installer view not found: {$template}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $path;
        return (string)ob_get_clean();
    }
}
