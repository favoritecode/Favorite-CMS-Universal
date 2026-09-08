<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
use FavoriteCMS\Pay\Contracts\WithdrawalServiceInterface;
use FavoriteCMS\Pay\Domain\WithdrawalStatus;
use FavoriteCMS\Pay\Permissions\PaymentPermission;
use Throwable;

class WithdrawalAdminController
{
    private Application $app;
    private WithdrawalServiceInterface $withdrawalService;
    private ?WalletServiceInterface $walletService;

    public function __construct(
        Application $app,
        WithdrawalServiceInterface $withdrawalService,
        ?WalletServiceInterface $walletService = null
    ) {
        $this->app = $app;
        $this->withdrawalService = $withdrawalService;
        $this->walletService = $walletService;

        if ($this->walletService === null && method_exists($this->app, 'has') && $this->app->has(WalletServiceInterface::class)) {
            $this->walletService = $this->app->make(WalletServiceInterface::class);
        }
    }

    public function handle(Request $request): Response|string
    {
        $userId = (int)($_SESSION['auth_user_id'] ?? $_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return Response::redirect('/admin/login');
        }

        $currentUser = $this->resolveCurrentUser($userId);
        if (!$currentUser || (method_exists($currentUser, 'isBanned') && $currentUser->isBanned())) {
            return Response::make('<h1>403 Access Denied</h1><p>Your account is banned or inactive.</p>', 403);
        }

        if (!PaymentPermission::canViewWithdrawals($currentUser)) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to view withdrawals.</p>', 403);
        }

        $action = (string)($request->post('action', $request->get('action', 'index')));

        if ($request->method() === 'POST') {
            if (!$this->validateCsrf($request)) {
                $_SESSION['flash_error'] = 'Invalid security token. Please try again.';
                return Response::redirect('/admin/page/favorite-pay-withdrawals');
            }

            if (!PaymentPermission::canManageWithdrawals($currentUser)) {
                return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to perform this action.</p>', 403);
            }

            return match ($action) {
                'update_settings'                 => $this->handleUpdateSettings($request),
                'approve'                         => $this->handleApprove($request, $userId),
                'process', 'start_processing'     => $this->handleStartProcessing($request, $userId),
                'paid', 'mark_paid'               => $this->handleMarkPaid($request, $userId),
                'reject'                          => $this->handleReject($request, $userId),
                'failed', 'mark_failed'           => $this->handleMarkFailed($request, $userId),
                default                           => Response::redirect('/admin/page/favorite-pay-withdrawals'),
            };
        }

        return $this->index($request, $currentUser);
    }

    public function index(Request $request, ?User $currentUser = null): string
    {
        $status = (string)$request->get('status', 'all');
        $search = trim((string)$request->get('search', ''));
        $page = max(1, (int)$request->get('p', 1));
        $limit = 25;
        $offset = ($page - 1) * $limit;

        $filters = [
            'status' => $status,
            'search' => $search,
        ];

        $data = $this->withdrawalService->listWithdrawals($filters, $limit, $offset);
        $settings = $this->withdrawalService->getSettings();
        $primaryCurrency = $this->walletService !== null ? $this->walletService->getPrimaryCurrency() : 'BDT';

        $csrfToken = $this->getCsrfToken();

        return $this->renderView('withdrawals', [
            'items'           => $data['items'],
            'total'           => $data['total'],
            'counts'          => $data['counts'],
            'currentStatus'   => $status,
            'currentSearch'   => $search,
            'page'            => $page,
            'totalPages'      => max(1, (int)ceil($data['total'] / $limit)),
            'settings'        => $settings,
            'primaryCurrency' => $primaryCurrency,
            'csrfToken'       => $csrfToken,
            'canManage'       => PaymentPermission::canManageWithdrawals($currentUser),
        ]);
    }

    private function handleUpdateSettings(Request $request): Response
    {
        $enabled = (bool)$request->post('enabled', false);
        $minAmount = max(1.0, (float)$request->post('min_amount', 100.0));
        $maxAmount = max($minAmount, (float)$request->post('max_amount', 500000.0));
        $feeFixed = max(0.0, (float)$request->post('fee_fixed', 0.0));
        $feePct = max(0.0, (float)$request->post('fee_pct', 0.0));
        $allowedMethods = $request->post('allowed_methods');

        $settings = [
            'enabled'    => $enabled,
            'min_amount' => $minAmount,
            'max_amount' => $maxAmount,
            'fee_fixed'  => $feeFixed,
            'fee_pct'    => $feePct,
        ];

        if (is_array($allowedMethods)) {
            $settings['allowed_methods'] = array_values(array_filter(array_map('strtolower', $allowedMethods)));
        }

        $this->withdrawalService->updateSettings($settings);

        $_SESSION['flash_success'] = 'Withdrawal settings updated successfully! (Withdrawals: ' . ($enabled ? 'ENABLED' : 'DISABLED') . ')';
        return Response::redirect('/admin/page/favorite-pay-withdrawals');
    }

    private function handleApprove(Request $request, int $adminUserId): Response
    {
        $id = trim((string)$request->post('withdrawal_id', ''));
        $notes = trim((string)$request->post('operator_notes', ''));

        try {
            $this->withdrawalService->approve($id, $adminUserId, $notes !== '' ? $notes : null);
            $_SESSION['flash_success'] = "Withdrawal #{$id} approved successfully.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Action failed: " . $e->getMessage();
        }

        return Response::redirect('/admin/page/favorite-pay-withdrawals');
    }

    private function handleStartProcessing(Request $request, int $adminUserId): Response
    {
        $id = trim((string)$request->post('withdrawal_id', ''));
        $notes = trim((string)$request->post('operator_notes', ''));

        try {
            $this->withdrawalService->startProcessing($id, $adminUserId, $notes !== '' ? $notes : null);
            $_SESSION['flash_success'] = "Withdrawal #{$id} moved to processing.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Action failed: " . $e->getMessage();
        }

        return Response::redirect('/admin/page/favorite-pay-withdrawals');
    }

    private function handleMarkPaid(Request $request, int $adminUserId): Response
    {
        $id = trim((string)$request->post('withdrawal_id', ''));
        $txRef = trim((string)$request->post('transaction_reference', ''));
        $notes = trim((string)$request->post('operator_notes', ''));

        try {
            $this->withdrawalService->markPaid($id, $adminUserId, $txRef !== '' ? $txRef : null, $notes !== '' ? $notes : null);
            $_SESSION['flash_success'] = "Withdrawal #{$id} marked as PAID. Wallet hold permanently finalized.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Action failed: " . $e->getMessage();
        }

        return Response::redirect('/admin/page/favorite-pay-withdrawals');
    }

    private function handleReject(Request $request, int $adminUserId): Response
    {
        $id = trim((string)$request->post('withdrawal_id', ''));
        $reason = trim((string)$request->post('reason', ''));
        if ($reason === '') {
            $reason = 'Rejected by administrator';
        }

        try {
            $this->withdrawalService->reject($id, $adminUserId, $reason);
            $_SESSION['flash_success'] = "Withdrawal #{$id} rejected. Wallet hold released back to customer balance.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Action failed: " . $e->getMessage();
        }

        return Response::redirect('/admin/page/favorite-pay-withdrawals');
    }

    private function handleMarkFailed(Request $request, int $adminUserId): Response
    {
        $id = trim((string)$request->post('withdrawal_id', ''));
        $reason = trim((string)$request->post('reason', 'Payout failure'));

        try {
            $this->withdrawalService->markFailed($id, $adminUserId, $reason);
            $_SESSION['flash_success'] = "Withdrawal #{$id} marked as FAILED. Wallet hold released back to customer balance.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Action failed: " . $e->getMessage();
        }

        return Response::redirect('/admin/page/favorite-pay-withdrawals');
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
            }
        }
        return null;
    }

    private function validateCsrf(Request $request): bool
    {
        $submittedToken = (string)($request->post('_token') ?: $request->post('_csrf_token', ''));
        $sessionToken = (string)($_SESSION['_token'] ?? $_SESSION['_csrf_token'] ?? '');
        if ($submittedToken === '' || $sessionToken === '') {
            return false;
        }
        return hash_equals($sessionToken, $submittedToken);
    }

    private function getCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        if (empty($_SESSION['_token'])) {
            $_SESSION['_token'] = bin2hex(random_bytes(16));
        }
        return (string)$_SESSION['_token'];
    }

    private function renderView(string $viewName, array $data = []): string
    {
        $viewFile = __DIR__ . '/../../views/admin/' . $viewName . '.php';
        if (!file_exists($viewFile)) {
            return "<div class='alert alert-danger'>Admin view not found: {$viewName}</div>";
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $viewFile;
        return (string)ob_get_clean();
    }
}
