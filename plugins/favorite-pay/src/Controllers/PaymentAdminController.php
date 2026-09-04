<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Permissions\PaymentPermission;
use FavoriteCMS\Pay\Repositories\PaymentAttemptRepository;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class PaymentAdminController
{
    private Application $app;
    private PaymentServiceInterface $paymentService;
    private PaymentAttemptRepository $repository;

    public function __construct(
        Application $app,
        PaymentServiceInterface $paymentService,
        PaymentAttemptRepository $repository
    ) {
        $this->app = $app;
        $this->paymentService = $paymentService;
        $this->repository = $repository;
    }

    /**
     * Main dispatcher invoked by Kernel::dispatchPluginAdminPage.
     */
    public function handle(Request $request): Response|string
    {
        // 1. Authenticate user
        $userId = (int)($_SESSION['auth_user_id'] ?? 0);
        if ($userId <= 0) {
            return Response::redirect('/admin/login');
        }

        $currentUser = $this->resolveCurrentUser($userId);

        if (!$currentUser || $currentUser->isBanned()) {
            return Response::make('<h1>403 Access Denied</h1><p>Your account is banned or inactive.</p>', 403);
        }

        // 2. Authorize VIEW permission
        if (!PaymentPermission::canView($currentUser)) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to view Favorite Pay transactions.</p>', 403);
        }

        // 3. Dispatch action
        $action = (string)($request->post('action', $request->get('action', 'index')));

        if ($request->method() === 'POST') {
            return match ($action) {
                'approve' => $this->approve($request, $currentUser),
                'reject'  => $this->reject($request, $currentUser),
                default   => Response::redirect('/admin/page/favorite-pay'),
            };
        }

        return match ($action) {
            'view', 'review' => $this->review($request, $currentUser),
            default          => $this->index($request),
        };
    }

    /**
     * List payment verification queue.
     */
    public function index(Request $request): string
    {
        $currentStatus = (string)$request->get('status', 'awaiting_verification');
        $currentGateway = (string)$request->get('gateway_id', 'all');
        $currentSearch = trim((string)$request->get('search', ''));
        $page = max(1, (int)$request->get('p', 1));

        $filters = [
            'status'     => $currentStatus,
            'gateway_id' => $currentGateway,
            'search'     => $currentSearch,
        ];

        $data = $this->repository->listAttempts($filters, $page, 25);

        return $this->renderView('queue', [
            'items'          => $data['items'],
            'total'          => $data['total'],
            'page'           => $data['page'],
            'totalPages'     => $data['totalPages'],
            'counts'         => $data['counts'],
            'currentStatus'  => $currentStatus,
            'currentGateway' => $currentGateway,
            'currentSearch'  => $currentSearch,
        ]);
    }

    /**
     * Review detail screen for one attempt.
     */
    public function review(Request $request, User $currentUser): Response|string
    {
        $attemptId = trim((string)$request->get('id', ''));
        if ($attemptId === '') {
            $_SESSION['flash_error'] = 'No payment attempt specified.';
            return Response::redirect('/admin/page/favorite-pay');
        }

        $attempt = $this->repository->getAttemptDetail($attemptId);
        if (!$attempt) {
            $_SESSION['flash_error'] = "Payment attempt not found: {$attemptId}";
            return Response::redirect('/admin/page/favorite-pay');
        }

        $canVerify = PaymentPermission::canVerify($currentUser);
        $csrfToken = (string)($_SESSION['_token'] ?? '');

        return $this->renderView('review', [
            'attempt'   => $attempt,
            'canVerify' => $canVerify,
            'csrfToken' => $csrfToken,
        ]);
    }

    /**
     * Approve manual payment attempt.
     */
    public function approve(Request $request, User $currentUser): Response
    {
        // A. Verify CSRF
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash_error'] = 'Security token expired or invalid (CSRF failure). Please try again.';
            return Response::redirect('/admin/page/favorite-pay');
        }

        // B. Verify permission
        if (!PaymentPermission::canVerify($currentUser)) {
            $_SESSION['flash_error'] = 'Access denied: you do not have permission to verify payments.';
            return Response::redirect('/admin/page/favorite-pay');
        }

        $attemptId = trim((string)$request->post('attempt_id', ''));
        if ($attemptId === '') {
            $_SESSION['flash_error'] = 'Payment attempt ID is required.';
            return Response::redirect('/admin/page/favorite-pay');
        }

        $notes = trim((string)$request->post('operator_notes', ''));
        $notes = $notes !== '' ? $notes : null;

        try {
            // Authoritative service call
            $this->paymentService->approveManualPayment($attemptId, (int)$currentUser->id, $notes);
            $_SESSION['flash_success'] = "Payment attempt '{$attemptId}' has been successfully approved.";
        } catch (RuntimeException $e) {
            // Double-action / state conflict
            $_SESSION['flash_error'] = "Cannot approve payment: " . $e->getMessage();
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash_error'] = "Invalid payment attempt: " . $e->getMessage();
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Failed to approve payment: " . $e->getMessage();
        }

        return Response::redirect("/admin/page/favorite-pay?action=view&id=" . urlencode($attemptId));
    }

    /**
     * Reject manual payment attempt.
     */
    public function reject(Request $request, User $currentUser): Response
    {
        // A. Verify CSRF
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash_error'] = 'Security token expired or invalid (CSRF failure). Please try again.';
            return Response::redirect('/admin/page/favorite-pay');
        }

        // B. Verify permission
        if (!PaymentPermission::canVerify($currentUser)) {
            $_SESSION['flash_error'] = 'Access denied: you do not have permission to reject payments.';
            return Response::redirect('/admin/page/favorite-pay');
        }

        $attemptId = trim((string)$request->post('attempt_id', ''));
        if ($attemptId === '') {
            $_SESSION['flash_error'] = 'Payment attempt ID is required.';
            return Response::redirect('/admin/page/favorite-pay');
        }

        $reason = trim((string)$request->post('reason', ''));
        if ($reason === '') {
            $_SESSION['flash_error'] = 'Rejection reason is required and cannot be empty.';
            return Response::redirect("/admin/page/favorite-pay?action=view&id=" . urlencode($attemptId));
        }

        try {
            // Authoritative service call
            $this->paymentService->rejectManualPayment($attemptId, (int)$currentUser->id, $reason);
            $_SESSION['flash_success'] = "Payment attempt '{$attemptId}' has been rejected.";
        } catch (RuntimeException $e) {
            // Double-action / state conflict
            $_SESSION['flash_error'] = "Cannot reject payment: " . $e->getMessage();
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash_error'] = "Invalid payment attempt: " . $e->getMessage();
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Failed to reject payment: " . $e->getMessage();
        }

        return Response::redirect("/admin/page/favorite-pay?action=view&id=" . urlencode($attemptId));
    }

    private function validateCsrf(Request $request): bool
    {
        $submittedToken = (string)$request->post('_token', '');
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
