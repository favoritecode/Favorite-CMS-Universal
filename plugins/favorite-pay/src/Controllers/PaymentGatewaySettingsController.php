<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Gateways\Binance\BinancePayGateway;
use FavoriteCMS\Pay\Gateways\Bkash\BkashMerchantGateway;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
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
        return $this->handleAutomatic($request);
    }

    /**
     * Handle Automatic Payment Gateways settings (Binance, bKash, City Bank).
     */
    public function handleAutomatic(Request $request): Response|string
    {
        $auth = $this->authorize();
        if ($auth !== null) {
            return $auth;
        }

        if ($request->method() === 'POST') {
            return $this->updateAutomatic($request);
        }

        return $this->indexAutomatic($request);
    }

    /**
     * Handle Manual Payment Methods settings (Mobile Banking & Bank Transfer).
     */
    public function handleManual(Request $request): Response|string
    {
        $auth = $this->authorize();
        if ($auth !== null) {
            return $auth;
        }

        if ($request->method() === 'POST') {
            return $this->updateManual($request);
        }

        return $this->indexManual($request);
    }

    private function authorize(): ?Response
    {
        $userId = (int)($_SESSION['auth_user_id'] ?? 0);
        if ($userId <= 0) {
            return Response::redirect('/admin/login');
        }

        $currentUser = $this->resolveCurrentUser($userId);
        if (!$currentUser || !$currentUser->isActive()) {
            return Response::make('<h1>403 Access Denied</h1><p>Your account is inactive or banned.</p>', 403);
        }

        if (!PaymentPermission::canManageSettings($currentUser)) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage payment gateway configuration.</p>', 403);
        }

        return null;
    }

    /**
     * Render Automatic Gateways view (Binance, bKash).
     */
    public function indexAutomatic(Request $request): string
    {
        $binance = $this->getBinanceGateway();
        $status = $binance ? $binance->getConfigurationStatus() : null;
        $publicConfig = $binance ? $binance->getPublicConfig() : [];

        $bkash = $this->getBkashGateway();
        $bkashStatus = $bkash ? $bkash->getConfigurationStatus() : null;
        $bkashConfig = $bkash ? $bkash->getPublicConfig() : [];

        $csrfToken = (string)($_SESSION['_token'] ?? '');
        if ($csrfToken === '' && function_exists('csrf_token')) {
            $csrfToken = csrf_token();
        }

        $tab = trim((string)$request->get('tab', 'binance'));

        return $this->renderView('gateways/automatic', [
            'gateway'        => $binance,
            'status'         => $status,
            'config'         => $publicConfig,
            'bkash'          => $bkash,
            'bkashStatus'    => $bkashStatus,
            'bkashConfig'    => $bkashConfig,
            'activeTab'      => $tab,
            'csrfToken'      => $csrfToken,
            'flashSuccess'   => $_SESSION['flash_success'] ?? null,
            'flashError'     => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Render Manual Payments view (Mobile Banking & Bank Transfer).
     */
    public function indexManual(Request $request): string
    {
        $methods = [
            'bkash'  => $this->getManualGateway('manual_bkash'),
            'nagad'  => $this->getManualGateway('manual_nagad'),
            'rocket' => $this->getManualGateway('manual_rocket'),
            'bank'   => $this->getManualGateway('manual_bank'),
        ];

        $csrfToken = (string)($_SESSION['_token'] ?? '');
        if ($csrfToken === '' && function_exists('csrf_token')) {
            $csrfToken = csrf_token();
        }

        $tab = trim((string)$request->get('tab', 'mobile'));

        return $this->renderView('gateways/manual', [
            'methods'      => $methods,
            'activeTab'    => $tab,
            'csrfToken'    => $csrfToken,
            'flashSuccess' => $_SESSION['flash_success'] ?? null,
            'flashError'   => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Update Automatic gateway settings.
     */
    public function updateAutomatic(Request $request): Response
    {
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash_error'] = 'Security token expired or invalid (CSRF failure). Please try again.';
            return Response::redirect('/admin/page/favorite-pay-gateways');
        }

        $target = trim((string)$request->post('gateway', 'binance'));

        if ($target === 'bkash' || $target === 'bkash_direct') {
            return $this->updateBkash($request);
        }

        return $this->updateBinance($request);
    }

    /**
     * Update Binance Pay settings.
     */
    public function updateBinance(Request $request): Response
    {
        $binance = $this->getBinanceGateway();
        if (!$binance) {
            $_SESSION['flash_error'] = 'Binance Pay gateway driver is not registered.';
            return Response::redirect('/admin/page/favorite-pay-gateways');
        }

        $enabled = (bool)$request->post('enabled', false);
        $certSn = trim((string)$request->post('certificate_sn', ''));
        $submittedSecret = trim((string)$request->post('api_secret', ''));
        $preferredCurrency = trim((string)$request->post('preferred_currency', 'USDT'));
        $sandbox = (bool)$request->post('sandbox', false);

        $configPayload = [
            'enabled'            => $enabled,
            'certificate_sn'     => $certSn,
            'preferred_currency' => $preferredCurrency,
            'sandbox'            => $sandbox,
        ];

        if ($submittedSecret !== '') {
            $configPayload['api_secret'] = $submittedSecret;
        } else {
            $currentConfig = $binance->getConfig();
            $configPayload['api_secret'] = (string)($currentConfig['api_secret'] ?? '');
        }

        try {
            $validated = $binance->validateConfig($configPayload);

            if (class_exists(Setting::class)) {
                Setting::set('favorite_pay_binance', 'enabled', $validated['enabled'] ? 1 : 0, 'bool');
                Setting::set('favorite_pay_binance', 'certificate_sn', $validated['certificate_sn'], 'string');
                if (!empty($validated['api_secret'])) {
                    Setting::set('favorite_pay_binance', 'api_secret', $validated['api_secret'], 'string');
                }
                Setting::set('favorite_pay_binance', 'preferred_currency', $validated['preferred_currency'], 'string');
                Setting::set('favorite_pay_binance', 'sandbox', $validated['sandbox'] ? 1 : 0, 'bool');
            }

            $binance->setConfig($validated);
            $_SESSION['flash_success'] = 'Binance Pay settings have been updated successfully.';
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash_error'] = 'Configuration error: ' . $e->getMessage();
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to save settings: ' . $e->getMessage();
        }

        return Response::redirect('/admin/page/favorite-pay-gateways?tab=binance');
    }

    /**
     * Update bKash Merchant settings.
     */
    public function updateBkash(Request $request): Response
    {
        $bkash = $this->getBkashGateway();
        if (!$bkash) {
            $_SESSION['flash_error'] = 'bKash Automatic gateway driver is not registered.';
            return Response::redirect('/admin/page/favorite-pay-gateways?tab=bkash');
        }

        $enabled = (bool)$request->post('enabled', false);
        $sandbox = (bool)$request->post('sandbox', false);
        $appKey = trim((string)$request->post('app_key', ''));
        $submittedAppSecret = trim((string)$request->post('app_secret', ''));
        $username = trim((string)$request->post('username', ''));
        $submittedPassword = trim((string)$request->post('password', ''));
        $baseUrl = trim((string)$request->post('base_url', ''));

        $currentConfig = $bkash->getConfig();
        $appSecret = $submittedAppSecret !== '' ? $submittedAppSecret : (string)($currentConfig['app_secret'] ?? '');
        $password = $submittedPassword !== '' ? $submittedPassword : (string)($currentConfig['password'] ?? '');

        $configPayload = [
            'enabled'    => $enabled,
            'sandbox'    => $sandbox,
            'app_key'    => $appKey,
            'app_secret' => $appSecret,
            'username'   => $username,
            'password'   => $password,
            'base_url'   => $baseUrl,
        ];

        try {
            $validated = $bkash->validateConfig($configPayload);

            if (class_exists(Setting::class)) {
                Setting::set('favorite_pay_bkash_direct', 'enabled', $validated['enabled'] ? 1 : 0, 'bool');
                Setting::set('favorite_pay_bkash_direct', 'sandbox', $validated['sandbox'] ? 1 : 0, 'bool');
                Setting::set('favorite_pay_bkash_direct', 'app_key', $validated['app_key'], 'string');
                if (!empty($validated['app_secret'])) {
                    Setting::set('favorite_pay_bkash_direct', 'app_secret', $validated['app_secret'], 'string');
                }
                Setting::set('favorite_pay_bkash_direct', 'username', $validated['username'], 'string');
                if (!empty($validated['password'])) {
                    Setting::set('favorite_pay_bkash_direct', 'password', $validated['password'], 'string');
                }
                Setting::set('favorite_pay_bkash_direct', 'base_url', $validated['base_url'], 'string');
            }

            $bkash->setConfig($validated);
            $_SESSION['flash_success'] = 'bKash Merchant settings have been updated successfully.';
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash_error'] = 'Configuration error: ' . $e->getMessage();
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to save bKash settings: ' . $e->getMessage();
        }

        return Response::redirect('/admin/page/favorite-pay-gateways?tab=bkash');
    }

    /**
     * Update Manual Payment method settings.
     */
    public function updateManual(Request $request): Response
    {
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash_error'] = 'Security token expired or invalid (CSRF failure). Please try again.';
            return Response::redirect('/admin/page/favorite-pay-manual');
        }

        $methodKey = trim((string)$request->post('method', ''));
        $allowed = ['manual_bkash', 'manual_nagad', 'manual_rocket', 'manual_bank'];
        if (!in_array($methodKey, $allowed, true)) {
            $_SESSION['flash_error'] = 'Invalid manual payment method specified.';
            return Response::redirect('/admin/page/favorite-pay-manual');
        }

        $gw = $this->getManualGateway($methodKey);
        if (!$gw) {
            $_SESSION['flash_error'] = "Gateway '{$methodKey}' is not registered.";
            return Response::redirect('/admin/page/favorite-pay-manual');
        }

        $enabled = (bool)$request->post('enabled', false);
        $accountNumber = trim((string)$request->post('account_number', ''));
        $accountName = trim((string)$request->post('account_name', ''));
        $accountType = trim((string)$request->post('account_type', 'Personal / Merchant'));
        $instructions = trim((string)$request->post('instructions', ''));
        $refInstructions = trim((string)$request->post('reference_instructions', ''));
        $proofReq = trim((string)$request->post('proof_requirements', ''));

        $configPayload = [
            'channel'                => str_replace('manual_', '', $methodKey),
            'account_number'         => $accountNumber,
            'account_name'           => $accountName,
            'account_type'           => $accountType,
            'instructions'           => $instructions,
            'reference_instructions' => $refInstructions,
            'proof_requirements'     => $proofReq,
        ];

        if ($methodKey === 'manual_bank') {
            $configPayload['bank_name'] = trim((string)$request->post('bank_name', ''));
            $configPayload['branch_name'] = trim((string)$request->post('branch_name', ''));
            $configPayload['routing_no'] = trim((string)$request->post('routing_no', ''));
            $configPayload['swift_code'] = trim((string)$request->post('swift_code', ''));
        }

        try {
            $validated = $gw->validateConfig($configPayload);

            $settingGroup = 'favorite_pay_' . $methodKey;
            if (class_exists(Setting::class)) {
                Setting::set($settingGroup, 'enabled', $enabled ? 1 : 0, 'bool');
                foreach ($validated as $k => $v) {
                    Setting::set($settingGroup, $k, (string)$v, 'string');
                }
            }

            $gw->setEnabled($enabled);
            $gw->setConfig($validated);

            $_SESSION['flash_success'] = "Settings for {$gw->getTitle()} updated successfully.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to save manual settings: ' . $e->getMessage();
        }

        $activeTab = $methodKey === 'manual_bank' ? 'bank' : 'mobile';
        return Response::redirect('/admin/page/favorite-pay-manual?tab=' . $activeTab);
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

    private function getBkashGateway(): ?BkashMerchantGateway
    {
        if ($this->registry->has('bkash_direct')) {
            $gw = $this->registry->get('bkash_direct');
            if ($gw instanceof BkashMerchantGateway) {
                return $gw;
            }
        }
        return null;
    }

    private function getManualGateway(string $id): ?ManualBangladeshGateway
    {
        if ($this->registry->has($id)) {
            $gw = $this->registry->get($id);
            if ($gw instanceof ManualBangladeshGateway) {
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
            return "<div class=\'notice notice-error\'>View not found: " . htmlspecialchars($viewName, ENT_QUOTES, 'UTF-8') . "</div>";
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
