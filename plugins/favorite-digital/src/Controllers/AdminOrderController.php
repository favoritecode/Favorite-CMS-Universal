<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Services\OrderService;
use FavoriteCMS\Digital\Support\OrderLifecycleState;
use FavoriteCMS\Models\User;
use Throwable;

class AdminOrderController
{
    protected Application $app;
    protected OrderService $orderService;
    protected ?\FavoriteCMS\Digital\Services\FulfillmentService $fulfillmentService;
    protected ?\FavoriteCMS\Digital\Repositories\EntitlementRepository $entitlementRepo;
    protected ?\FavoriteCMS\Digital\Services\RefundService $refundService;

    public function __construct(
        Application $app,
        OrderService $orderService,
        ?\FavoriteCMS\Digital\Services\FulfillmentService $fulfillmentService = null,
        ?\FavoriteCMS\Digital\Repositories\EntitlementRepository $entitlementRepo = null,
        ?\FavoriteCMS\Digital\Services\RefundService $refundService = null
    ) {
        $this->app = $app;
        $this->orderService = $orderService;
        $this->fulfillmentService = $fulfillmentService;
        $this->entitlementRepo = $entitlementRepo;
        $this->refundService = $refundService;
    }

    public function getOrderService(): OrderService
    {
        return $this->orderService;
    }

    public function getRefundService(): ?\FavoriteCMS\Digital\Services\RefundService
    {
        return $this->refundService;
    }

    public function handle(Request $request): Response|string
    {
        // 1. Authenticate user
        $userId = (int)($_SESSION['auth_user_id'] ?? 0);
        if ($userId <= 0 && !isset($GLOBALS['_test_current_user'])) {
            return Response::redirect('/admin/login');
        }

        $currentUser = $this->resolveCurrentUser($userId);
        if ($currentUser && method_exists($currentUser, 'isActive') && !$currentUser->isActive()) {
            return Response::make('<h1>403 Access Denied</h1><p>Your account is inactive or banned.</p>', 403);
        }

        // 2. Authorize capability
        if ($currentUser && method_exists($currentUser, 'can') && !$currentUser->can('manage_options')) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage orders.</p>', 403);
        } elseif (!$currentUser && function_exists('current_user_can')) {
            try {
                if (!current_user_can('manage_options')) {
                    return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage orders.</p>', 403);
                }
            } catch (Throwable) {
            }
        }

        // 3. Dispatch based on method & action
        if ($request->method() === 'POST') {
            return $this->handlePost($request);
        }

        return $this->handleGet($request);
    }

    protected function handleGet(Request $request): Response|string
    {
        $action = (string)$request->get('action', 'index');
        $id = (int)$request->get('id', 0);

        return match ($action) {
            'view'  => $this->viewOrder($request, $id),
            default => $this->index($request),
        };
    }

    protected function handlePost(Request $request): Response
    {
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash_error'] = 'Security token expired or invalid (CSRF failure). Please try again.';
            $orderId = (int)$request->post('id', 0);
            $redirect = $orderId > 0
                ? '/admin/page/favorite-digital-orders?action=view&id=' . $orderId
                : '/admin/page/favorite-digital-orders';
            return Response::redirect($redirect);
        }

        $action = (string)$request->post('action', '');
        $id = (int)$request->post('id', 0);

        return match ($action) {
            'update_status' => $this->updateStatus($request, $id),
            'fulfill'       => $this->fulfillOrder($request, $id),
            'refund'        => $this->processRefund($request, $id),
            default         => Response::redirect('/admin/page/favorite-digital-orders'),
        };
    }

    public function index(Request $request): string
    {
        $statusFilter      = (string)$request->get('status', 'all');
        $paymentFilter     = (string)$request->get('payment_status', 'all');
        $fulfillmentFilter = (string)$request->get('fulfillment_status', 'all');
        $search            = trim((string)$request->get('search', ''));
        $page              = max(1, (int)$request->get('page', 1));

        $filters = [];
        if ($statusFilter !== 'all' && OrderLifecycleState::isValidStatus($statusFilter)) {
            $filters['status'] = $statusFilter;
        }
        if ($paymentFilter !== 'all' && OrderLifecycleState::isValidPaymentStatus($paymentFilter)) {
            $filters['payment_status'] = $paymentFilter;
        }
        if ($fulfillmentFilter !== 'all' && OrderLifecycleState::isValidFulfillmentStatus($fulfillmentFilter)) {
            $filters['fulfillment_status'] = $fulfillmentFilter;
        }
        if ($search !== '') {
            $filters['search'] = $search;
        }

        $result = $this->orderService->listOrders($filters, $page, 20);

        return $this->renderView('orders/index', [
            'orders'            => $result['data'],
            'total'             => $result['total'],
            'page'              => $result['page'],
            'totalPages'        => $result['total_pages'],
            'statusFilter'      => $statusFilter,
            'paymentFilter'     => $paymentFilter,
            'fulfillmentFilter' => $fulfillmentFilter,
            'search'            => $search,
            'csrfToken'         => $this->getCsrfToken(),
            'flashSuccess'      => $_SESSION['flash_success'] ?? null,
            'flashError'        => $_SESSION['flash_error'] ?? null,
        ]);
    }

    public function viewOrder(Request $request, int $id): Response|string
    {
        $order = null;
        if ($id > 0) {
            $order = $this->orderService->getOrder($id);
        } else {
            $orderNumber = trim((string)$request->get('order_number', ''));
            if ($orderNumber !== '') {
                $order = $this->orderService->getOrderByNumber($orderNumber);
            }
        }

        if (!$order) {
            $_SESSION['flash_error'] = 'Order not found.';
            return Response::redirect('/admin/page/favorite-digital-orders');
        }

        $entitlements = [];
        if ($this->entitlementRepo !== null) {
            $entitlements = $this->entitlementRepo->getEntitlementsByOrder((int)$order->id);
        }

        $refunds = [];
        if ($this->refundService !== null) {
            $refunds = $this->refundService->getRefundRepository()->findRefundsByOrderId((int)$order->id);
        }

        return $this->renderView('orders/view', [
            'order'        => $order,
            'entitlements' => $entitlements,
            'refunds'      => $refunds,
            'csrfToken'    => $this->getCsrfToken(),
            'flashSuccess' => $_SESSION['flash_success'] ?? null,
            'flashError'   => $_SESSION['flash_error'] ?? null,
        ]);
    }

    public function updateStatus(Request $request, int $id): Response
    {
        $order = $this->orderService->getOrder($id);
        if (!$order) {
            $_SESSION['flash_error'] = 'Order not found.';
            return Response::redirect('/admin/page/favorite-digital-orders');
        }

        $status            = trim((string)$request->post('status', ''));
        $paymentStatus     = trim((string)$request->post('payment_status', ''));
        $fulfillmentStatus = trim((string)$request->post('fulfillment_status', ''));

        try {
            if ($status !== '' && OrderLifecycleState::isValidStatus($status)) {
                $this->orderService->updateStatus($id, $status);
            }
            if ($paymentStatus !== '' && OrderLifecycleState::isValidPaymentStatus($paymentStatus)) {
                $this->orderService->updatePaymentStatus($id, $paymentStatus);
            }
            if ($fulfillmentStatus !== '' && OrderLifecycleState::isValidFulfillmentStatus($fulfillmentStatus)) {
                $this->orderService->updateFulfillmentStatus($id, $fulfillmentStatus);
            }
            $_SESSION['flash_success'] = 'Order status updated successfully.';
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to update order status: ' . $e->getMessage();
        }

        return Response::redirect('/admin/page/favorite-digital-orders?action=view&id=' . $id);
    }

    protected function validateCsrf(Request $request): bool
    {
        $submittedToken = (string)$request->post('_token', '');
        $sessionToken   = (string)($_SESSION['_token'] ?? '');

        if ($submittedToken === '' || $sessionToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $submittedToken);
    }

    protected function getCsrfToken(): string
    {
        $token = (string)($_SESSION['_token'] ?? '');
        if ($token === '' && function_exists('csrf_token')) {
            $token = csrf_token();
        }
        return $token;
    }

    protected function renderView(string $viewName, array $data = []): string
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

    protected function resolveCurrentUser(int $userId): ?object
    {
        if (isset($GLOBALS['_test_current_user'])) {
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

    public function fulfillOrder(Request $request, int $id): Response
    {
        $order = $this->orderService->getOrder($id);
        if (!$order) {
            $_SESSION['flash_error'] = 'Order not found.';
            return Response::redirect('/admin/page/favorite-digital-orders');
        }

        if ($this->fulfillmentService === null) {
            $_SESSION['flash_error'] = 'Fulfillment service is unavailable.';
            return Response::redirect('/admin/page/favorite-digital-orders?action=view&id=' . $id);
        }

        try {
            $this->fulfillmentService->fulfillOrder($id);
            $_SESSION['flash_success'] = "Order #{$order->order_number} fulfilled successfully.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Fulfillment failed: " . $e->getMessage();
        }

        return Response::redirect('/admin/page/favorite-digital-orders?action=view&id=' . $id);
    }

    public function processRefund(Request $request, int $id): Response
    {
        $order = $this->orderService->getOrder($id);
        if (!$order) {
            $_SESSION['flash_error'] = 'Order not found.';
            return Response::redirect('/admin/page/favorite-digital-orders');
        }

        if ($this->refundService === null) {
            $_SESSION['flash_error'] = 'Refund service is unavailable.';
            return Response::redirect('/admin/page/favorite-digital-orders?action=view&id=' . $id);
        }

        $reason = trim((string)$request->post('reason', ''));
        if ($reason === '') {
            $_SESSION['flash_error'] = 'A refund reason must be provided.';
            return Response::redirect('/admin/page/favorite-digital-orders?action=view&id=' . $id);
        }

        $actorUserId = (int)($_SESSION['auth_user_id'] ?? 0);
        if (isset($GLOBALS['_test_current_user']->id)) {
            $actorUserId = (int)$GLOBALS['_test_current_user']->id;
        }

        try {
            $refund = $this->refundService->processRefund($id, $reason, $actorUserId, true);
            $_SESSION['flash_success'] = "Refund of ৳{$refund->refund_amount} processed successfully to customer wallet.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = "Refund failed: " . $e->getMessage();
        }

        return Response::redirect('/admin/page/favorite-digital-orders?action=view&id=' . $id);
    }

}
