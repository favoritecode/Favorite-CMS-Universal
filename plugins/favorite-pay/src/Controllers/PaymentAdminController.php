<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\User;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Contracts\AuditLogServiceInterface;
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
    private ?AuditLogServiceInterface $auditService = null;

    public function __construct(
        Application $app,
        PaymentServiceInterface $paymentService,
        PaymentAttemptRepository $repository,
        ?AuditLogServiceInterface $auditService = null
    ) {
        $this->app = $app;
        $this->paymentService = $paymentService;
        $this->repository = $repository;
        $this->auditService = $auditService;

        if ($this->auditService === null && method_exists($this->app, 'has') && $this->app->has(AuditLogServiceInterface::class)) {
            $this->auditService = $this->app->make(AuditLogServiceInterface::class);
        }
    }

    /**
     * Main dispatcher invoked by Kernel::dispatchPluginAdminPage.
     */
    public function handle(Request $request): Response|string
    {
        // 1. Authenticate user
        $userId = (int)($_SESSION['auth_user_id'] ?? $_SESSION['user_id'] ?? 0);
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
                default   => Response::redirect('/admin/page/favorite-pay-payments'),
            };
        }

        return match ($action) {
            'view', 'review' => $this->review($request, $currentUser),
            // Payment proof contains private customer information. Require the
            // same verification permission used for approve/reject operations.
            'proof'          => PaymentPermission::canVerify($currentUser)
                ? $this->proof($request)
                : Response::make('<h1>403 Access Denied</h1><p>You do not have permission to download payment proofs.</p>', 403),
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
            return Response::redirect('/admin/page/favorite-pay-payments');
        }

        $attempt = $this->repository->getAttemptDetail($attemptId);
        if (!$attempt) {
            $_SESSION['flash_error'] = "Payment attempt not found: {$attemptId}";
            return Response::redirect('/admin/page/favorite-pay-payments');
        }

        $canVerify = PaymentPermission::canVerify($currentUser);
        if (empty($_SESSION['_token'])) {
            $_SESSION['_token'] = bin2hex(random_bytes(32));
        }
        $csrfToken = (string)$_SESSION['_token'];

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
            return Response::redirect('/admin/page/favorite-pay-payments');
        }

        // B. Verify permission
        if (!PaymentPermission::canVerify($currentUser)) {
            $_SESSION['flash_error'] = 'Access denied: you do not have permission to verify payments.';
            return Response::redirect('/admin/page/favorite-pay-payments');
        }

        $attemptId = trim((string)$request->post('attempt_id', ''));
        if ($attemptId === '') {
            $_SESSION['flash_error'] = 'Payment attempt ID is required.';
            return Response::redirect('/admin/page/favorite-pay-payments');
        }

        $notes = trim((string)$request->post('operator_notes', ''));
        $notes = $notes !== '' ? $notes : null;

        try {
            // Authoritative service call
            $this->paymentService->approveManualPayment($attemptId, (int)$currentUser->id, $notes);

            if ($this->auditService !== null) {
                try {
                    $this->auditService->log(
                        action: 'payment.manual_approved',
                        subjectType: 'payment',
                        subjectId: $attemptId,
                        metadata: ['notes' => $notes],
                        description: "Manual payment attempt '{$attemptId}' approved by admin",
                        actorUserId: (int)$currentUser->id,
                        actorType: 'admin',
                        paymentId: $attemptId
                    );
                } catch (Throwable) {
                }
            }

            $_SESSION['flash_success'] = "Payment attempt '{$attemptId}' has been successfully approved.";
        } catch (RuntimeException $e) {
            // Double-action / state conflict
            $_SESSION['flash_error'] = "Cannot approve payment: " . $e->getMessage();
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash_error'] = "Invalid payment attempt: " . $e->getMessage();
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Failed to approve payment: " . $e->getMessage();
        }

        return Response::redirect("/admin/page/favorite-pay-payments?action=view&id=" . urlencode($attemptId));
    }

    /**
     * Reject manual payment attempt.
     */
    public function reject(Request $request, User $currentUser): Response
    {
        // A. Verify CSRF
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash_error'] = 'Security token expired or invalid (CSRF failure). Please try again.';
            return Response::redirect('/admin/page/favorite-pay-payments');
        }

        // B. Verify permission
        if (!PaymentPermission::canVerify($currentUser)) {
            $_SESSION['flash_error'] = 'Access denied: you do not have permission to reject payments.';
            return Response::redirect('/admin/page/favorite-pay-payments');
        }

        $attemptId = trim((string)$request->post('attempt_id', ''));
        if ($attemptId === '') {
            $_SESSION['flash_error'] = 'Payment attempt ID is required.';
            return Response::redirect('/admin/page/favorite-pay-payments');
        }

        $reason = trim((string)$request->post('reason', ''));
        if ($reason === '') {
            $_SESSION['flash_error'] = 'Rejection reason is required and cannot be empty.';
            return Response::redirect("/admin/page/favorite-pay-payments?action=view&id=" . urlencode($attemptId));
        }

        try {
            // Authoritative service call
            $this->paymentService->rejectManualPayment($attemptId, (int)$currentUser->id, $reason);

            if ($this->auditService !== null) {
                try {
                    $this->auditService->log(
                        action: 'payment.manual_rejected',
                        subjectType: 'payment',
                        subjectId: $attemptId,
                        metadata: ['reason' => $reason],
                        description: "Manual payment attempt '{$attemptId}' rejected by admin",
                        actorUserId: (int)$currentUser->id,
                        actorType: 'admin',
                        paymentId: $attemptId
                    );
                } catch (Throwable) {
                }
            }

            $_SESSION['flash_success'] = "Payment attempt '{$attemptId}' has been rejected.";
        } catch (RuntimeException $e) {
            // Double-action / state conflict
            $_SESSION['flash_error'] = "Cannot reject payment: " . $e->getMessage();
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash_error'] = "Invalid payment attempt: " . $e->getMessage();
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Failed to reject payment: " . $e->getMessage();
        }

        return Response::redirect("/admin/page/favorite-pay-payments?action=view&id=" . urlencode($attemptId));
    }

    /** Operator-only proof download. Never expose the private storage path. */
    private function proof(Request $request): Response
    {
        $attempt = $this->repository->getAttemptDetail(trim((string)$request->get('id', '')));
        $payload = $attempt['decoded_payload'] ?? [];
        $relative = (string)($payload['proof_path'] ?? '');
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 4);
        $base = null;
        $candidate = null;
        if (str_starts_with($relative, 'payments/proofs/')) {
            $base = realpath($root . '/storage/app/payments/proofs');
            $candidate = $root . '/storage/app/' . $relative;
        } elseif (str_starts_with($relative, 'storage/plugins/favorite-digital/proofs/')) {
            $base = realpath($root . '/storage/plugins/favorite-digital/proofs');
            $candidate = $root . '/' . $relative;
        }
        $path = $candidate !== null ? realpath($candidate) : false;
        $normalizedBase = $base ? str_replace(DIRECTORY_SEPARATOR, '/', $base) : '';
        $normalizedPath = $path ? str_replace(DIRECTORY_SEPARATOR, '/', $path) : '';
        if (PHP_OS_FAMILY === 'Windows') {
            $normalizedBase = strtolower($normalizedBase);
            $normalizedPath = strtolower($normalizedPath);
        }
        $inside = $base && $path && str_starts_with($normalizedPath, rtrim($normalizedBase, '/') . '/');
        if (!$inside || !is_file($path) || filesize($path) > 10485760) {
            return Response::make('Payment proof is unavailable.', 404);
        }
        $content = @file_get_contents($path, false, null, 0, 10485761);
        if ($content === false || strlen($content) > 10485760) return Response::make('Payment proof is unavailable.', 404);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)
            || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
            return Response::make('Payment proof format is not supported.', 415);
        }
        $hash = (string)($payload['proof_hash'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/i', $hash) && !hash_equals(strtolower($hash), hash('sha256', $content))) {
            return Response::make('Payment proof could not be verified.', 409);
        }
        return Response::make($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="payment-proof.' . $extension . '"',
            'Content-Length' => (string)strlen($content),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
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

        $feedback = '';
        foreach (['flash_success' => 'success', 'flash_error' => 'error'] as $key => $kind) {
            if (!empty($_SESSION[$key])) {
                $feedback .= '<div class="notice notice-' . $kind . '" role="status" style="margin-bottom:16px;padding:12px 16px;border-left:4px solid ' . ($kind === 'success' ? '#16a34a' : '#dc2626') . ';">'
                    . htmlspecialchars((string)$_SESSION[$key], ENT_QUOTES, 'UTF-8') . '</div>';
                unset($_SESSION[$key]);
            }
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $viewPath;
        return \FavoriteCMS\Pay\Support\AdminThemeBridge::render($feedback . (string)ob_get_clean());
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
