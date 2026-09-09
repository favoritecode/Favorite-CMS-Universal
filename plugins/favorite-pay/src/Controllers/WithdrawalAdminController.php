<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
use FavoriteCMS\Pay\Contracts\WithdrawalServiceInterface;
use FavoriteCMS\Pay\Contracts\AuditLogServiceInterface;
use FavoriteCMS\Pay\Domain\WithdrawalStatus;
use FavoriteCMS\Pay\Permissions\PaymentPermission;
use Throwable;

class WithdrawalAdminController
{
    private Application $app;
    private WithdrawalServiceInterface $withdrawalService;
    private ?WalletServiceInterface $walletService;

    private ?AuditLogServiceInterface $auditService = null;

    public function __construct(
        Application $app,
        WithdrawalServiceInterface $withdrawalService,
        ?WalletServiceInterface $walletService = null,
        ?AuditLogServiceInterface $auditService = null
    ) {
        $this->app = $app;
        $this->withdrawalService = $withdrawalService;
        $this->walletService = $walletService;
        $this->auditService = $auditService;

        if ($this->walletService === null && method_exists($this->app, 'has') && $this->app->has(WalletServiceInterface::class)) {
            $this->walletService = $this->app->make(WalletServiceInterface::class);
        }
        if ($this->auditService === null && method_exists($this->app, 'has') && $this->app->has(AuditLogServiceInterface::class)) {
            $this->auditService = $this->app->make(AuditLogServiceInterface::class);
        }
    }

    public function getAuditService(): ?AuditLogServiceInterface
    {
        return $this->auditService;
    }

    public function setAuditService(AuditLogServiceInterface $auditService): void
    {
        $this->auditService = $auditService;
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

        if ($action === 'export_csv') {
            if ($request->method() === 'POST') {
                if (!$this->validateCsrf($request)) {
                    $_SESSION['flash_error'] = 'Invalid security token. Please try again.';
                    return Response::redirect('/admin/page/favorite-pay-withdrawals');
                }
            }
            return $this->handleExportCsv($request, $currentUser);
        }

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
                'cancel'                          => $this->handleCancel($request, $userId),
                'update_notes'                    => $this->handleUpdateNotes($request, $userId),
                'update_reference'                => $this->handleUpdateReference($request, $userId),
                default                           => Response::redirect('/admin/page/favorite-pay-withdrawals'),
            };
        }

        return $this->index($request, $currentUser);
    }

    public function handleExportCsv(Request $request, ?User $currentUser = null): Response
    {
        if (!PaymentPermission::canViewWithdrawals($currentUser)) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to export withdrawals.</p>', 403);
        }

        $filters = [
            'status'    => (string)$request->get('status', $request->post('status', 'all')),
            'method'    => (string)$request->get('method', $request->post('method', 'all')),
            'search'    => trim((string)$request->get('search', $request->post('search', ''))),
            'date_from' => trim((string)$request->get('date_from', $request->post('date_from', ''))),
            'date_to'   => trim((string)$request->get('date_to', $request->post('date_to', ''))),
        ];

        $csv = $this->withdrawalService->exportWithdrawalsCsv($filters);
        $filename = 'withdrawals-' . date('Y-m-d') . '.csv';

        return Response::make($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'no-store, no-cache, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ]);
    }

    public function index(Request $request, ?User $currentUser = null): string
    {
        $id = trim((string)$request->get('id', ''));
        if ($id !== '') {
            return $this->detail($request, $id, $currentUser);
        }

        $status = (string)$request->get('status', 'all');
        $method = (string)$request->get('method', 'all');
        $search = trim((string)$request->get('search', ''));
        $dateFrom = trim((string)$request->get('date_from', ''));
        $dateTo = trim((string)$request->get('date_to', ''));
        $page = max(1, (int)$request->get('p', 1));
        $limit = 25;
        $offset = ($page - 1) * $limit;

        $filters = [
            'status'    => $status,
            'method'    => $method,
            'search'    => $search,
            'date_from' => $dateFrom !== '' ? $dateFrom : null,
            'date_to'   => $dateTo !== '' ? $dateTo : null,
        ];

        $data = $this->withdrawalService->listWithdrawals($filters, $limit, $offset);
        $summary = $this->withdrawalService->getSummary($filters);
        $settings = $this->withdrawalService->getSettings();
        $primaryCurrency = $this->walletService !== null ? $this->walletService->getPrimaryCurrency() : 'BDT';

        $csrfToken = $this->getCsrfToken();

        return $this->renderView('withdrawals', [
            'items'           => $data['items'],
            'total'           => $data['total'],
            'counts'          => $data['counts'],
            'summary'         => $summary,
            'currentStatus'   => $status,
            'currentMethod'   => $method,
            'currentSearch'   => $search,
            'currentDateFrom' => $dateFrom,
            'currentDateTo'   => $dateTo,
            'page'            => $page,
            'totalPages'      => max(1, (int)ceil($data['total'] / $limit)),
            'settings'        => $settings,
            'primaryCurrency' => $primaryCurrency,
            'csrfToken'       => $csrfToken,
            'canManage'       => PaymentPermission::canManageWithdrawals($currentUser),
        ]);
    }

    public function detail(Request $request, string $id, ?User $currentUser = null): string
    {
        $withdrawal = $this->withdrawalService->getWithdrawal($id);
        if (!$withdrawal) {
            return "<div class='fpay-settings-box'><div style='color: #b91c1c; font-weight: bold;'>Withdrawal #" . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . " not found.</div><div style='margin-top: 10px;'><a href='/admin/page/favorite-pay-withdrawals' class='button'>&larr; Back to queue</a></div></div>";
        }

        $customerUser = null;
        if (class_exists(User::class)) {
            try {
                $customerUser = User::find($withdrawal->getUserId());
            } catch (Throwable) {
            }
        }

        $auditTrail = $withdrawal->getAuditTrail();
        $settings = $this->withdrawalService->getSettings();
        $primaryCurrency = $this->walletService !== null ? $this->walletService->getPrimaryCurrency() : 'BDT';
        $csrfToken = $this->getCsrfToken();

        $currentYm = substr($withdrawal->getCreatedAt(), 0, 7);
        $monthlyPosition = $this->withdrawalService->getMonthlyWithdrawalCount($withdrawal->getUserId(), $currentYm);
        $maxMonthlyCount = (int)($settings['max_monthly_count'] ?? 5);

        // Log withdrawal viewed (non-blocking)
        if ($this->auditService !== null) {
            try {
                $adminId = $currentUser ? (int)$currentUser->id : 0;
                $this->auditService->log(
                    action: 'withdrawal.viewed',
                    subjectType: 'withdrawal',
                    subjectId: $withdrawal->getId(),
                    targetUserId: $withdrawal->getUserId(),
                    metadata: ['status' => $withdrawal->getStatus()->value],
                    description: "Withdrawal #{$withdrawal->getId()} viewed by admin",
                    actorUserId: $adminId > 0 ? $adminId : null,
                    actorType: 'admin',
                    withdrawalId: $withdrawal->getId()
                );
            } catch (Throwable) {
            }
        }

        $operationalAuditLogs = $this->auditService !== null ? $this->auditService->getWithdrawalLogs($withdrawal->getId()) : [];

        return $this->renderView('withdrawal_detail', [
            'withdrawal'           => $withdrawal,
            'auditTrail'           => $auditTrail,
            'operationalAuditLogs' => $operationalAuditLogs,
            'settings'             => $settings,
            'primaryCurrency'      => $primaryCurrency,
            'csrfToken'            => $csrfToken,
            'canManage'            => PaymentPermission::canManageWithdrawals($currentUser),
            'customerUser'         => $customerUser,
            'monthlyPosition'      => $monthlyPosition,
            'maxMonthlyCount'      => $maxMonthlyCount,
        ]);
    }

    private function handleUpdateSettings(Request $request): Response
    {
        $enabled = (bool)$request->post('enabled', false);
        $minAmount = max(0.0, (float)$request->post('min_amount', 500.0));
        $maxMonthlyCount = max(1, (int)$request->post('max_monthly_count', 5));
        $feeFixed = max(0.0, (float)$request->post('fee_fixed', 0.0));
        $feePct = max(0.0, (float)$request->post('fee_pct', 0.0));
        $allowedMethods = $request->post('allowed_methods');

        $settings = [
            'enabled'           => $enabled,
            'min_amount'        => $minAmount,
            'max_monthly_count' => $maxMonthlyCount,
            'fee_fixed'         => $feeFixed,
            'fee_pct'           => $feePct,
        ];

        if (is_array($allowedMethods)) {
            $settings['allowed_methods'] = array_values(array_filter(array_map('strtolower', $allowedMethods)));
        }

        $prevSettings = $this->withdrawalService->getSettings();
        $this->withdrawalService->updateSettings($settings);

        // Record audit log with before/after diff (non-blocking)
        if ($this->auditService !== null) {
            try {
                $changes = [];
                foreach ($settings as $k => $newVal) {
                    $oldVal = $prevSettings[$k] ?? null;
                    if ($oldVal !== $newVal) {
                        $changes[$k] = [
                            'before' => $oldVal,
                            'after'  => $newVal,
                        ];
                    }
                }
                if (!empty($changes)) {
                    $adminId = (int)($_SESSION['auth_user_id'] ?? 0);
                    $this->auditService->log(
                        action: 'settings.updated',
                        subjectType: 'settings',
                        subjectId: 'favorite_pay_withdrawals',
                        metadata: ['changes' => $changes],
                        description: "Withdrawal settings updated by admin",
                        actorUserId: $adminId > 0 ? $adminId : null,
                        actorType: 'admin'
                    );
                }
            } catch (Throwable) {
            }
        }

        $_SESSION['flash_success'] = 'Withdrawal settings updated successfully! (Withdrawals: ' . ($enabled ? 'ENABLED' : 'DISABLED') . ')';
        return Response::redirect('/admin/page/favorite-pay-withdrawals');
    }

    private function handleApprove(Request $request, int $adminUserId): Response
    {
        $id = trim((string)$request->post('withdrawal_id', ''));
        $notes = trim((string)$request->post('operator_notes', ''));
        $redirect = (string)$request->post('redirect_to', '/admin/page/favorite-pay-withdrawals');

        try {
            $this->withdrawalService->approve($id, $adminUserId, $notes !== '' ? $notes : null);
            $_SESSION['flash_success'] = "Withdrawal #{$id} approved successfully.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Action failed: " . $e->getMessage();
        }

        return Response::redirect($redirect);
    }

    private function handleStartProcessing(Request $request, int $adminUserId): Response
    {
        $id = trim((string)$request->post('withdrawal_id', ''));
        $notes = trim((string)$request->post('operator_notes', ''));
        $redirect = (string)$request->post('redirect_to', '/admin/page/favorite-pay-withdrawals');

        try {
            $this->withdrawalService->startProcessing($id, $adminUserId, $notes !== '' ? $notes : null);
            $_SESSION['flash_success'] = "Withdrawal #{$id} moved to processing.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Action failed: " . $e->getMessage();
        }

        return Response::redirect($redirect);
    }

    private function handleMarkPaid(Request $request, int $adminUserId): Response
    {
        $id = trim((string)$request->post('withdrawal_id', ''));
        $txRef = trim((string)$request->post('transaction_reference', ''));
        $notes = trim((string)$request->post('operator_notes', ''));
        $redirect = (string)$request->post('redirect_to', '/admin/page/favorite-pay-withdrawals');

        try {
            $this->withdrawalService->markPaid($id, $adminUserId, $txRef !== '' ? $txRef : null, $notes !== '' ? $notes : null);
            $_SESSION['flash_success'] = "Withdrawal #{$id} marked as PAID. Wallet hold permanently finalized.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Action failed: " . $e->getMessage();
        }

        return Response::redirect($redirect);
    }

    private function handleReject(Request $request, int $adminUserId): Response
    {
        $id = trim((string)$request->post('withdrawal_id', ''));
        $reason = trim((string)$request->post('reason', ''));
        $redirect = (string)$request->post('redirect_to', '/admin/page/favorite-pay-withdrawals');
        if ($reason === '') {
            $reason = 'Rejected by administrator';
        }

        try {
            $this->withdrawalService->reject($id, $adminUserId, $reason);
            $_SESSION['flash_success'] = "Withdrawal #{$id} rejected. Wallet hold released back to customer balance.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Action failed: " . $e->getMessage();
        }

        return Response::redirect($redirect);
    }

    private function handleMarkFailed(Request $request, int $adminUserId): Response
    {
        $id = trim((string)$request->post('withdrawal_id', ''));
        $reason = trim((string)$request->post('reason', 'Payout failure'));
        $redirect = (string)$request->post('redirect_to', '/admin/page/favorite-pay-withdrawals');

        try {
            $this->withdrawalService->markFailed($id, $adminUserId, $reason);
            $_SESSION['flash_success'] = "Withdrawal #{$id} marked as FAILED. Wallet hold released back to customer balance.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Action failed: " . $e->getMessage();
        }

        return Response::redirect($redirect);
    }

    private function handleCancel(Request $request, int $adminUserId): Response
    {
        $id = trim((string)$request->post('withdrawal_id', ''));
        $reason = trim((string)$request->post('reason', 'Cancelled by administrator'));
        $redirect = (string)$request->post('redirect_to', '/admin/page/favorite-pay-withdrawals');
        if ($reason === '') {
            $reason = 'Cancelled by administrator';
        }

        try {
            $this->withdrawalService->cancel($id, $adminUserId, $reason, true);
            $_SESSION['flash_success'] = "Withdrawal #{$id} cancelled. Wallet hold released back to customer balance.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Action failed: " . $e->getMessage();
        }

        return Response::redirect($redirect);
    }

    private function handleUpdateNotes(Request $request, int $adminUserId): Response
    {
        $id = trim((string)$request->post('withdrawal_id', ''));
        $notes = trim((string)$request->post('operator_notes', ''));
        $redirect = (string)$request->post('redirect_to', '/admin/page/favorite-pay-withdrawals?id=' . urlencode($id));

        try {
            $this->withdrawalService->updateProcessingNotes($id, $adminUserId, $notes);
            $_SESSION['flash_success'] = "Processing notes for #{$id} updated successfully.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Action failed: " . $e->getMessage();
        }

        return Response::redirect($redirect);
    }

    private function handleUpdateReference(Request $request, int $adminUserId): Response
    {
        $id = trim((string)$request->post('withdrawal_id', ''));
        $ref = trim((string)$request->post('transaction_reference', ''));
        $redirect = (string)$request->post('redirect_to', '/admin/page/favorite-pay-withdrawals?id=' . urlencode($id));

        try {
            $this->withdrawalService->updateTransactionReference($id, $adminUserId, $ref);
            $_SESSION['flash_success'] = "Transaction reference for #{$id} updated successfully.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Action failed: " . $e->getMessage();
        }

        return Response::redirect($redirect);
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
