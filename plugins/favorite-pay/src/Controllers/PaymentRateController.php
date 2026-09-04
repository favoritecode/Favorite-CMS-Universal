<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Domain\ConversionSnapshot;
use FavoriteCMS\Pay\Permissions\PaymentPermission;
use FavoriteCMS\Pay\Providers\DatabaseRateProvider;
use FavoriteCMS\Pay\Support\SafeLogger;
use InvalidArgumentException;
use Throwable;

class PaymentRateController
{
    private Application $app;
    private CurrencyServiceInterface $currencyService;
    private DatabaseRateProvider $rateProvider;

    public function __construct(
        Application $app,
        CurrencyServiceInterface $currencyService,
        ?DatabaseRateProvider $rateProvider = null
    ) {
        $this->app = $app;
        $this->currencyService = $currencyService;

        if ($rateProvider !== null) {
            $this->rateProvider = $rateProvider;
        } elseif ($app->has(Database::class)) {
            $this->rateProvider = new DatabaseRateProvider($app->make(Database::class));
        } else {
            throw new InvalidArgumentException("Database or DatabaseRateProvider is required for PaymentRateController.");
        }
    }

    /**
     * Dispatcher for rate management admin pages.
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

        // 2. Authorize MANAGE_RATES permission
        if (!PaymentPermission::canManageRates($currentUser)) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage exchange rates.</p>', 403);
        }

        // 3. Dispatch based on HTTP method & action
        if ($request->method() === 'POST') {
            $action = (string)$request->post('action', 'save');
            if ($action === 'refresh_live') {
                return $this->refreshLiveRates($request);
            }
            if ($action === 'deactivate') {
                $operatorId = (int)($currentUser->id ?? $userId);
                return $this->deactivate($request, $currentUser, $operatorId);
            }
            $operatorId = (int)($currentUser->id ?? $userId);
            return $this->store($request, $currentUser, $operatorId);
        }

        return $this->index($request);
    }

    /**
     * Render the exchange rates management view.
     */
    public function index(Request $request): string
    {
        $rates = $this->rateProvider->getAllRates(100);
        $supportedCurrencies = $this->currencyService->getSupportedCurrencies();

        $csrfToken = (string)($_SESSION['_token'] ?? '');
        if ($csrfToken === '' && function_exists('csrf_token')) {
            $csrfToken = csrf_token();
        }

        $provider = $this->currencyService->getProvider();
        $liveFxStatus = null;
        if ($provider instanceof \FavoriteCMS\Pay\Providers\LiveExchangeRateProvider) {
            $liveFxStatus = $provider->getDiagnosticStatus();
        }

        return $this->renderView('rates', [
            'rates'               => $rates,
            'supportedCurrencies' => $supportedCurrencies,
            'baseCurrency'        => $this->currencyService->getBaseCurrency(),
            'csrfToken'           => $csrfToken,
            'liveFxStatus'        => $liveFxStatus,
            'flashSuccess'        => $_SESSION['flash_success'] ?? null,
            'flashError'          => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Trigger on-demand sync with Live FX provider.
     */
    public function refreshLiveRates(Request $request): Response
    {
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash_error'] = 'Security token expired or invalid (CSRF failure). Please try again.';
            return Response::redirect('/admin/page/favorite-pay-rates');
        }

        $provider = $this->currencyService->getProvider();
        if ($provider instanceof \FavoriteCMS\Pay\Providers\LiveExchangeRateProvider) {
            $success = $provider->refreshRates();
            if ($success) {
                $_SESSION['flash_success'] = 'Live FX market rates synchronized successfully.';
            } else {
                $status = $provider->getDiagnosticStatus();
                $_SESSION['flash_error'] = 'Failed to refresh live FX rates: ' . ($status['last_error'] ?? 'API endpoint unreachable');
            }
        } else {
            $_SESSION['flash_error'] = 'Live FX provider is not active.';
        }

        return Response::redirect('/admin/page/favorite-pay-rates');
    }

    /**
     * Store a newly configured operator authoritative exchange rate.
     */
    public function store(Request $request, User $currentUser, int $operatorId = 0): Response
    {
        // A. Validate CSRF
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash_error'] = 'Security token expired or invalid (CSRF failure). Please try again.';
            return Response::redirect('/admin/page/favorite-pay-rates');
        }

        $base = strtoupper(trim((string)$request->post('base_currency', '')));
        $quote = strtoupper(trim((string)$request->post('quote_currency', '')));
        $rateStr = trim((string)$request->post('rate', ''));
        $effectiveAtInput = trim((string)$request->post('effective_at', ''));
        $expiresAtInput = trim((string)$request->post('expires_at', ''));
        $notes = trim((string)$request->post('notes', ''));

        // B. Validate Currencies
        if ($base === '' || $quote === '') {
            $_SESSION['flash_error'] = 'Base currency and Quote currency are required.';
            return Response::redirect('/admin/page/favorite-pay-rates');
        }

        if ($base === $quote) {
            $_SESSION['flash_error'] = 'Base currency and Quote currency cannot be the same.';
            return Response::redirect('/admin/page/favorite-pay-rates');
        }

        // C. Validate Rate (strictly positive exact decimal with reasonable domain bounds)
        if ($rateStr === '' || !preg_match('/^\d+(\.\d{1,6})?$/', $rateStr)) {
            $_SESSION['flash_error'] = 'Exchange rate must be a valid positive decimal number with up to 6 decimal places (e.g. 122.50).';
            return Response::redirect('/admin/page/favorite-pay-rates');
        }

        $rateFloat = (float)$rateStr;
        if ($rateFloat <= 0.000000 || $rateFloat > 100000000.0) {
            $_SESSION['flash_error'] = 'Exchange rate must be between 0.000001 and 100,000,000.00.';
            return Response::redirect('/admin/page/favorite-pay-rates');
        }

        // D. Timestamps parsing & validation
        $now = date('Y-m-d H:i:s');
        $effectiveAt = $effectiveAtInput !== '' ? date('Y-m-d H:i:s', strtotime($effectiveAtInput)) : $now;
        if (!$effectiveAt || strtotime($effectiveAt) === false) {
            $effectiveAt = $now;
        }

        $expiresAt = null;
        if ($expiresAtInput !== '') {
            $parsedExpires = date('Y-m-d H:i:s', strtotime($expiresAtInput));
            if ($parsedExpires && strtotime($parsedExpires) !== false) {
                if (strtotime($parsedExpires) <= strtotime($effectiveAt)) {
                    $_SESSION['flash_error'] = 'Expiration time must be strictly after effective time.';
                    return Response::redirect('/admin/page/favorite-pay-rates');
                }
                $expiresAt = $parsedExpires;
            }
        }

        try {
            // Check for existing active rate to prevent conflicting backdated overlaps
            $existingActive = $this->rateProvider->getRate($base, $quote);
            $oldRateStr = $existingActive ? $existingActive->getRateDecimalString() : 'None';
            if ($existingActive !== null) {
                $existingEffective = $existingActive->getLockedAt();
                if ($existingEffective && strtotime($effectiveAt) < strtotime($existingEffective)) {
                    $_SESSION['flash_error'] = "Cannot backdate rate: effective time ({$effectiveAt}) is earlier than currently active rate effective time ({$existingEffective}).";
                    return Response::redirect('/admin/page/favorite-pay-rates');
                }
            }

            // Build conversion snapshot to compute exact rate_factor and rate_scale
            $snapshot = ConversionSnapshot::create(
                $base,
                $quote,
                $rateStr,
                true,
                ConversionSnapshot::DEFAULT_SCALE,
                $expiresAt,
                'operator'
            );

            // Overlap Prevention: Retire previous active rates for this currency pair
            $retiredCount = $this->rateProvider->retireActiveRates($base, $quote, $effectiveAt);

            // Insert new rate record
            $finalOpId = $operatorId > 0 ? $operatorId : (int)($currentUser->id ?? 1);
            $rateData = [
                'base_currency'    => $base,
                'quote_currency'   => $quote,
                'rate'             => (float)$rateStr,
                'rate_factor'      => $snapshot->getRateFactor(),
                'rate_scale'       => $snapshot->getRateScale(),
                'is_authoritative' => 1,
                'status'           => 'active',
                'source'           => 'operator',
                'operator_id'      => $finalOpId,
                'effective_at'     => $effectiveAt,
                'expires_at'       => $expiresAt,
                'created_at'       => $now,
            ];
            if ($notes !== '') {
                $rateData['notes'] = $notes;
            }

            $rateId = $this->rateProvider->insertRate($rateData);

            // Comprehensive Audit Log (Answers WHO, WHAT, OLD, NEW, WHEN, EFFECTIVE, EXPIRY, WHY, SOURCE, ACTION)
            SafeLogger::info("Operator exchange rate configured", [
                'action'         => 'rate_created',
                'rate_id'        => $rateId,
                'base_currency'  => $base,
                'quote_currency' => $quote,
                'pair'           => "{$base}/{$quote}",
                'old_rate'       => $oldRateStr,
                'new_rate'       => $rateStr,
                'operator_id'    => $finalOpId,
                'created_at'     => $now,
                'effective_at'   => $effectiveAt,
                'expires_at'     => $expiresAt ?? 'indefinite',
                'notes'          => $notes !== '' ? $notes : 'None',
                'source'         => 'operator',
                'retired_prior'  => $retiredCount,
            ]);

            // Hook for external auditing/listeners
            if (function_exists('do_action')) {
                do_action('favorite.pay.rate.operator_locked', [
                    'rate_id'       => $rateId,
                    'from'          => $base,
                    'to'            => $quote,
                    'rate'          => $rateStr,
                    'operator_id'   => $operatorId > 0 ? $operatorId : (int)($currentUser->id ?? 1),
                    'effective_at'  => $effectiveAt,
                    'expires_at'    => $expiresAt,
                    'notes'         => $notes,
                ]);
            }

            $_SESSION['flash_success'] = "Authoritative rate for 1 {$base} = {$rateStr} {$quote} has been configured successfully.";
        } catch (Throwable $e) {
            SafeLogger::error("Failed to save operator rate: " . $e->getMessage(), [
                'base' => $base,
                'quote' => $quote,
                'rate' => $rateStr,
            ]);
            $_SESSION['flash_error'] = 'Failed to save exchange rate: ' . $e->getMessage();
        }

        return Response::redirect('/admin/page/favorite-pay-rates');
    }

    /**
     * Deactivate an existing rate.
     */
    public function deactivate(Request $request, User $currentUser, int $operatorId = 0): Response
    {
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash_error'] = 'Security token expired or invalid (CSRF failure). Please try again.';
            return Response::redirect('/admin/page/favorite-pay-rates');
        }

        $rateId = (int)$request->post('rate_id', 0);
        if ($rateId <= 0) {
            $_SESSION['flash_error'] = 'Invalid rate ID provided.';
            return Response::redirect('/admin/page/favorite-pay-rates');
        }

        $rate = $this->rateProvider->getRateById($rateId);
        if (!$rate) {
            $_SESSION['flash_error'] = 'Exchange rate record not found.';
            return Response::redirect('/admin/page/favorite-pay-rates');
        }

        $success = $this->rateProvider->deactivateRate($rateId, $operatorId > 0 ? $operatorId : (int)($currentUser->id ?? 1));
        if ($success) {
            $finalOpId = $operatorId > 0 ? $operatorId : (int)($currentUser->id ?? 1);
            $now = date('Y-m-d H:i:s');
            SafeLogger::info("Operator exchange rate deactivated", [
                'action'       => 'rate_deactivated',
                'rate_id'      => $rateId,
                'pair'         => ($rate['base_currency'] ?? '') . '/' . ($rate['quote_currency'] ?? ''),
                'rate'         => (string)($rate['rate'] ?? ''),
                'operator_id'  => $finalOpId,
                'deactivated_at' => $now,
                'source'       => 'operator',
            ]);

            if (function_exists('do_action')) {
                do_action('favorite.pay.rate.deactivated', [
                    'rate_id'     => $rateId,
                    'operator_id' => $operatorId > 0 ? $operatorId : (int)($currentUser->id ?? 1),
                ]);
            }

            $_SESSION['flash_success'] = "Exchange rate #{$rateId} has been deactivated.";
        } else {
            $_SESSION['flash_error'] = 'Failed to deactivate exchange rate.';
        }

        return Response::redirect('/admin/page/favorite-pay-rates');
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
