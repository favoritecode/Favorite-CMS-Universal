<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Services\OrderService;
use FavoriteCMS\Models\User;
use Throwable;

class CustomerOrderController
{
    protected Application $app;
    protected OrderService $orderService;

    public function __construct(Application $app, OrderService $orderService)
    {
        $this->app = $app;
        $this->orderService = $orderService;
    }

    public function getOrderService(): OrderService
    {
        return $this->orderService;
    }

    public function index(Request $request): Response|string
    {
        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            return Response::redirect('/login');
        }

        $page = max(1, (int)$request->get('page', 1));
        $result = $this->orderService->listUserOrders($userId, $page, 15);

        return $this->renderView('orders/index', [
            'orders'     => $result['data'],
            'total'      => $result['total'],
            'page'       => $result['page'],
            'totalPages' => $result['total_pages'],
            'userId'     => $userId,
        ]);
    }

    public function view(Request $request, string $orderNumber): Response|string
    {
        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            return Response::redirect('/login');
        }

        $order = $this->orderService->getOrderByNumber($orderNumber);
        if (!$order) {
            return Response::make('<h1>404 Not Found</h1><p>Order not found.</p>', 404);
        }

        // Ownership verification: only owner or admin can view
        if ((int)$order->user_id !== $userId) {
            $isAdmin = false;
            $currentUser = $this->resolveCurrentUser($userId);
            if ($currentUser && method_exists($currentUser, 'can') && $currentUser->can('manage_options')) {
                $isAdmin = true;
            } elseif (function_exists('current_user_can')) {
                try {
                    $isAdmin = current_user_can('manage_options');
                } catch (Throwable) {
                }
            }

            if (!$isAdmin) {
                return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to view this order.</p>', 403);
            }
        }

        return $this->renderView('orders/view', [
            'order'  => $order,
            'userId' => $userId,
        ]);
    }

    protected function resolveCurrentUserId(): int
    {
        if (isset($GLOBALS['_test_current_user']) && isset($GLOBALS['_test_current_user']->id)) {
            return (int)$GLOBALS['_test_current_user']->id;
        }
        return (int)($_SESSION['auth_user_id'] ?? 0);
    }

    protected function resolveCurrentUser(int $userId): ?User
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

    protected function renderView(string $viewName, array $data = []): string
    {
        $viewPath = __DIR__ . '/../../views/customer/' . $viewName . '.php';
        if (!file_exists($viewPath)) {
            return "<div class='notice notice-error'>View not found: " . htmlspecialchars($viewName, ENT_QUOTES, 'UTF-8') . "</div>";
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $viewPath;
        return (string)ob_get_clean();
    }
}
