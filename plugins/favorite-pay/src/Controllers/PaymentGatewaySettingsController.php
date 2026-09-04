<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Gateways\Binance\BinancePayGateway;
use FavoriteCMS\Pay\Permissions\PaymentPermission;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use InvalidArgumentException;
use Throwable;

class PaymentGatewaySettingsController
{
    private Application $app;
    private GatewayRegistry $registry;

    public function __construct(Application $app, GatewayRegistry $registry)
    {
        $this->app = $app;
        $this->registry = $registry;
    }

    /**
     * Dispatcher for gateway settings admin pages.
     */
    public function handle(Request $request): Response|string
    {
        // 1. Authenticate user
        $userId = (int)($_SESSION['auth_user_id'] ?? 0);
        if ($userId <= 0) {
            return Response::redirect('/admin/login');
        }

        $currentUser = $this->resolveCurrentUser($userId);
        if (!$currentUser || !$currentUser->isActive()) {
            return Response::make('<h1>403 Access Denied</h1><p>Your account is inactive or banned.</p>', 403);
        }

        // 2. Authorize MANAGE_SETTINGS permission
        if (!PaymentPermission::canManageSettings($currentUser)) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage payment gateway configuration.</p>', 403);
        }

        // 3. Dispatch based on HTTP method
        if ($request->method() === 'POST') {
            return $this->update($request);
        }

        return $this->index($request);
    }

    /**
     * Render the gateway settings & diagnostics view.
     */
    public function index(Request $request): string
    {
        $binance = $this->getBinanceGateway();
        $status = $binance ? $binance->getConfigurationStatus() : null;
        $publicConfig = $binance ? $binance->getPublicConfig() : [];

        $csrfToken = (string)($_SESSION['_token'] ?? '');
        if ($csrfToken === '' && function_exists('csrf_token')) {
            $csrfToken = csrf_token();
        }

        return $this->renderView('gateways/binance', [
            'gateway'      => $binance,
            'status'       => $status,
            'config'       => $publicConfig,
            'csrfToken'    => $csrfToken,
            'flashSuccess' => $_SESSION['flash_success'] ?? null,
            'flashError'   => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Update gateway configuration.
     */
    public function update(Request $request): Response
    {
        // A. Validate CSRF
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash_error'] = 'Security token expired or invalid (CSRF failure). Please try again.';
            return Response::redirect('/admin/page/favorite-pay-gateways');
        }

        $binance = $this->getBinanceGateway();
        if (!$binance) {
            $_SESSION['flash_error'] = 'Binance Pay gateway driver is not registered.';
            return Response::redirect('/admin/page/favorite-pay-gateways');
        }

        $enabled = (bool)$request->post('enabled', false);
        $certSn = trim((string)$request->post('certificate_sn', ''));
        $submittedSecret = trim((string)$request->post('api_secret', ''));
        $sandbox = (bool)$request->post('sandbox', false);

        // Prepare config payload
        $configPayload = [
            'enabled'        => $enabled,
            'certificate_sn' => $certSn,
            'sandbox'        => $sandbox,
        ];

        // Secret handling:
        // If submittedSecret is non-empty: replace existing secret.
        // If submittedSecret is empty: preserve existing secret in $binance.
        if ($submittedSecret !== '') {
            $configPayload['api_secret'] = $submittedSecret;
        } else {
            $currentConfig = $binance->getConfig();
            $configPayload['api_secret'] = (string)($currentConfig['api_secret'] ?? '');
        }

        try {
            // Validate through driver
            $validated = $binance->validateConfig($configPayload);

            // Persist to Core settings storage
            if (class_exists(Setting::class)) {
                Setting::set('favorite_pay_binance', 'enabled', $validated['enabled'] ? 1 : 0, 'bool');
                Setting::set('favorite_pay_binance', 'certificate_sn', $validated['certificate_sn'], 'string');
                if (!empty($validated['api_secret'])) {
                    Setting::set('favorite_pay_binance', 'api_secret', $validated['api_secret'], 'string');
                }
                Setting::set('favorite_pay_binance', 'sandbox', $validated['sandbox'] ? 1 : 0, 'bool');
            }

            // Update in-memory driver instance
            $binance->setConfig($validated);

            $_SESSION['flash_success'] = 'Binance Pay settings have been updated successfully.';
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash_error'] = 'Configuration error: ' . $e->getMessage();
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to save settings: ' . $e->getMessage();
        }

        return Response::redirect('/admin/page/favorite-pay-gateways');
    }

    private function getBinanceGateway(): ?BinancePayGateway
    {
        if ($this->registry->has('binance_pay')) {
            $gw = $this->registry->get('binance_pay');
            if ($gw instanceof BinancePayGateway) {
                return $gw;
            }
        }
        return null;
    }

    private function validateCsrf(Request $request): bool
    {
        $submittedToken = (string)($request->post('_token', $request->header('X-CSRF-TOKEN', '')));
        $sessionToken = (string)($_SESSION['_token'] ?? '');

        if ($submittedToken === '' || $sessionToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $submittedToken);
    }

    private function renderView(string $viewName, array $data = []): string
    {
        $viewPath = __DIR__ . '/../../views/admin/' . $viewName . '.php';
        if (!file_exists($viewPath)) {
            return "<div class='notice notice-error'>View not found: " . htmlspecialchars($viewName, ENT_QUOTES, 'UTF-8') . "</div>";
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $viewPath;
        return (string)ob_get_clean();
    }

    private function resolveCurrentUser(int $userId): ?User
    {
        if (isset($GLOBALS['_test_current_user']) && $GLOBALS['_test_current_user'] instanceof User) {
            return $GLOBALS['_test_current_user'];
        }

        if (class_exists(User::class)) {
            try {
                return User::find($userId);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
