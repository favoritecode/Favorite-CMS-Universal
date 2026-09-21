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
            'email_transport'           => (string)Setting::get('email', 'transport', 'auto'),
            'sender_name'               => Setting::get('email', 'sender_name', ''),
            'sender_email'              => Setting::get('email', 'sender_email', ''),
            'smtp_host'                 => (string)Setting::get('email', 'smtp_host', ''),
            'smtp_port'                 => (int)Setting::get('email', 'smtp_port', 587),
            'smtp_encryption'           => (string)Setting::get('email', 'smtp_encryption', 'tls'),
            'smtp_username'             => (string)Setting::get('email', 'smtp_username', ''),
            'smtp_password_set'         => !empty(Setting::get('email', 'smtp_password', '')),
            'smtp_timeout'              => (int)Setting::get('email', 'smtp_timeout', 15),
            'mail_diagnostics'          => \FavoriteCMS\Services\MailService::getTransportDiagnostics(),
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

        $pages = Page::summaries('published');
        $categories = Taxonomy::getByTaxonomy('category');

        $lockReason = null;
        $primaryCurrencyLocked = Currency::isPrimaryCurrencyLocked($lockReason);

        $viewData = [
            'pageTitle'                 => 'Settings',
            'activeMenu'                => 'settings',
            'settings'                  => $settings,
            'supportedCurrencies'       => Currency::getSupportedCurrencies(),
            'timezones'                 => \FavoriteCMS\Core\DateTime::listTimezones(),
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
        if (empty($_SESSION['_token']) || !hash_equals($_SESSION['_token'], $token)) {
            $_SESSION['flash_error'] = 'Security verification failed (invalid CSRF token).';
            return Response::redirect('/admin/settings');
        }

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

        // Email & Notification Settings
        $transport = strtolower(trim((string)$request->post('email_transport', 'auto')));
        if (!in_array($transport, ['auto', 'mail', 'smtp'], true)) {
            $transport = 'auto';
        }
        Setting::set('email', 'transport', $transport);

        $senderName = trim((string)$request->post('sender_name', ''));
        $senderName = str_replace(["\r", "\n"], '', $senderName);
        Setting::set('email', 'sender_name', $senderName);

        $senderEmail = trim((string)$request->post('sender_email', ''));
        if ($senderEmail !== '') {
            if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL) || strpbrk($senderEmail, "\r\n\t") !== false) {
                $_SESSION['flash_error'] = 'Invalid Sender Email address. Please enter a valid email address or leave blank to use the administration email.';
                return Response::redirect('/admin/settings');
            }
            Setting::set('email', 'sender_email', $senderEmail);
        } else {
            Setting::set('email', 'sender_email', '');
        }

        // SMTP Settings
        $smtpHost = trim((string)$request->post('smtp_host', ''));
        if (strpbrk($smtpHost, "\r\n\t") !== false) {
            $_SESSION['flash_error'] = 'Invalid SMTP host (CRLF characters not allowed).';
            return Response::redirect('/admin/settings');
        }
        Setting::set('email', 'smtp_host', $smtpHost);

        $smtpPort = (int)$request->post('smtp_port', 587);
        if ($smtpPort < 1 || $smtpPort > 65535) {
            $_SESSION['flash_error'] = 'Invalid SMTP port. Must be between 1 and 65535.';
            return Response::redirect('/admin/settings');
        }
        Setting::set('email', 'smtp_port', $smtpPort, 'int');

        $smtpEncryption = strtolower(trim((string)$request->post('smtp_encryption', 'tls')));
        if ($smtpEncryption === 'starttls') {
            $smtpEncryption = 'tls';
        }
        if (!in_array($smtpEncryption, ['none', 'ssl', 'tls'], true)) {
            $_SESSION['flash_error'] = 'Invalid SMTP encryption. Allowed: none, ssl, tls.';
            return Response::redirect('/admin/settings');
        }
        Setting::set('email', 'smtp_encryption', $smtpEncryption);

        $smtpUsername = trim((string)$request->post('smtp_username', ''));
        if (strpbrk($smtpUsername, "\r\n\t") !== false) {
            $_SESSION['flash_error'] = 'Invalid SMTP username (CRLF characters not allowed).';
            return Response::redirect('/admin/settings');
        }
        Setting::set('email', 'smtp_username', $smtpUsername);

        if ($request->post('clear_smtp_password') || $request->post('smtp_password_clear')) {
            Setting::set('email', 'smtp_password', '');
        } else {
            $submittedPass = (string)$request->post('smtp_password', '');
            if ($submittedPass !== '' && $submittedPass !== '********') {
                if (strpbrk($submittedPass, "\r\n") !== false) {
                    $_SESSION['flash_error'] = 'Invalid SMTP password (CRLF characters not allowed).';
                    return Response::redirect('/admin/settings');
                }
                Setting::set('email', 'smtp_password', $submittedPass);
            }
        }

        $smtpTimeout = (int)$request->post('smtp_timeout', 15);
        if ($smtpTimeout < 1 || $smtpTimeout > 120) {
            $smtpTimeout = 15;
        }
        Setting::set('email', 'smtp_timeout', $smtpTimeout, 'int');

        // Validate and save site timezone
        $submittedTimezone = trim((string)$request->post('timezone', 'UTC'));
        if (!\FavoriteCMS\Core\DateTime::isValidTimezone($submittedTimezone)) {
            $_SESSION['flash_error'] = "Invalid Timezone '{$submittedTimezone}'. Please select a valid IANA timezone identifier.";
            return Response::redirect('/admin/settings');
        }
        Setting::set('general', 'timezone', $submittedTimezone);

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
                $_SESSION['flash_error'] = $reason ?? "Primary Currency cannot be changed.";
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

    public function sendTestEmail(Request $request): Response
    {
        $token = (string)$request->post('_token', '');
        if (empty($_SESSION['_token']) || !hash_equals($_SESSION['_token'], $token)) {
            $_SESSION['flash_error'] = 'Security verification failed (invalid CSRF token).';
            return Response::redirect('/admin/settings');
        }

        $userId = isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : null;
        $currentUser = $userId ? User::find($userId) : null;
        if (!$currentUser || !$currentUser->canManageSettings()) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to test email settings.</p>', 403);
        }

        $recipient = trim((string)$request->post('test_email', ''));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || strpbrk($recipient, "\r\n\t") !== false) {
            $_SESSION['flash_error'] = 'Please enter a valid recipient email address for the test email.';
            return Response::redirect('/admin/settings');
        }

        $siteName = (string)Setting::get('general', 'site_name', 'Favorite CMS');
        $senderName = \FavoriteCMS\Services\MailService::resolveFromName();
        $senderEmail = \FavoriteCMS\Services\MailService::resolveFromEmail();

        $subject = "Test Email from {$senderName}";
        $message = "Hello,\n\n"
            . "This is a diagnostic test email sent from {$siteName}.\n\n"
            . "Timestamp: " . date('Y-m-d H:i:s T') . "\n"
            . "Resolved Sender Name: {$senderName}\n"
            . "Resolved Sender Email: {$senderEmail}\n"
            . "Recipient: {$recipient}\n\n"
            . "If you are reading this email in your inbox, your server's outgoing mail transport is operating correctly.\n\n"
            . "Regards,\nThe {$siteName} Team";

        $accepted = \FavoriteCMS\Services\MailService::send($recipient, $subject, $message);

        if ($accepted) {
            $_SESSION['flash_success'] = "Mail transport accepted the test message. Check the recipient inbox/spam folder.";
        } else {
            $diagError = \FavoriteCMS\Services\MailService::getLastTransportError();
            $serverDetail = $diagError ? " (Server diagnostic: {$diagError})" : "";
            $_SESSION['flash_error'] = "Mail transport failed to send the test message. Please verify the configured mail settings.{$serverDetail}";
        }

        return Response::redirect('/admin/settings');
    }

    public function testSmtpConnection(Request $request): Response
    {
        $token = (string)$request->post('_token', '');
        if (empty($_SESSION['_token']) || !hash_equals($_SESSION['_token'], $token)) {
            $_SESSION['flash_error'] = 'Security verification failed (invalid CSRF token).';
            return Response::redirect('/admin/settings');
        }

        $userId = isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : null;
        $currentUser = $userId ? User::find($userId) : null;
        if (!$currentUser || !$currentUser->canManageSettings()) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to test email settings.</p>', 403);
        }

        $host = trim((string)$request->post('smtp_host', Setting::get('email', 'smtp_host', '')));
        $port = (int)$request->post('smtp_port', Setting::get('email', 'smtp_port', 587));
        $encryption = (string)$request->post('smtp_encryption', Setting::get('email', 'smtp_encryption', 'tls'));
        $username = trim((string)$request->post('smtp_username', Setting::get('email', 'smtp_username', '')));
        $timeout = (int)$request->post('smtp_timeout', Setting::get('email', 'smtp_timeout', 15));

        $submittedPass = (string)$request->post('smtp_password', '');
        $password = ($submittedPass !== '' && $submittedPass !== '********')
            ? $submittedPass
            : (string)Setting::get('email', 'smtp_password', '');

        if ($host === '') {
            $_SESSION['flash_error'] = 'Please specify an SMTP Host before testing the connection.';
            return Response::redirect('/admin/settings');
        }

        try {
            $smtp = new \FavoriteCMS\Services\Mail\SmtpTransport(
                host: $host,
                port: $port > 0 ? $port : 587,
                encryption: $encryption,
                username: $username,
                password: $password,
                timeout: $timeout > 0 ? $timeout : 15
            );

            $result = $smtp->testConnection();
            if ($result['success']) {
                $authMsg = ($username !== '' && $password !== '') ? 'Authentication confirmed.' : 'Connected (unauthenticated).';
                $_SESSION['flash_success'] = "✓ SMTP connection successful to {$host}:{$port} ({$encryption}). Handshake verified. {$authMsg}";
            } else {
                $_SESSION['flash_error'] = "✗ SMTP connection failed: " . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8');
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = "✗ SMTP configuration error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }

        return Response::redirect('/admin/settings');
    }
}

