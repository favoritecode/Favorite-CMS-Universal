<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Domain\MembershipStatus;
use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Services\MembershipLifecycleService;
use FavoriteCMS\Digital\Services\ProductManagementService;
use FavoriteCMS\Models\User;
use Throwable;

class AdminMembershipController
{
    protected Application $app;
    protected MembershipLifecycleService $membershipService;
    protected ProductManagementService $productService;

    public function __construct(
        Application $app,
        MembershipLifecycleService $membershipService,
        ProductManagementService $productService
    ) {
        $this->app = $app;
        $this->membershipService = $membershipService;
        $this->productService = $productService;
    }

    public function getMembershipService(): MembershipLifecycleService
    {
        return $this->membershipService;
    }

    public function getProductService(): ProductManagementService
    {
        return $this->productService;
    }

    /**
     * Dispatcher for admin membership requests.
     */
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
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage memberships.</p>', 403);
        } elseif (!$currentUser && function_exists('current_user_can')) {
            try {
                if (!current_user_can('manage_options')) {
                    return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage memberships.</p>', 403);
                }
            } catch (Throwable) {
            }
        }

        // 3. Dispatch based on method & action
        $method = $request->method();
        if ($method === 'POST') {
            return $this->handlePost($request);
        }

        return $this->handleGet($request);
    }

    protected function handleGet(Request $request): Response|string
    {
        $action = (string)$request->get('action', 'index');
        $id = (int)$request->get('id', 0);

        return match ($action) {
            'create_plan'     => $this->createPlanForm($request),
            'edit_plan'       => $this->editPlanForm($request, $id),
            'view_membership' => $this->viewMembership($request, $id),
            default           => $this->index($request),
        };
    }

    protected function handlePost(Request $request): Response
    {
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash_error'] = 'Security token expired or invalid (CSRF failure). Please try again.';
            return Response::redirect('/admin/page/favorite-digital-memberships');
        }

        $action = (string)$request->post('action', '');
        $id = (int)$request->post('id', 0);

        return match ($action) {
            'store_plan'        => $this->storePlan($request),
            'update_plan'       => $this->updatePlan($request, $id),
            'extend'            => $this->extendMembership($request, $id),
            'toggle_auto_renew' => $this->toggleAutoRenew($request, $id),
            'expire'            => $this->expireMembership($request, $id),
            'recover_grace'     => $this->recoverGrace($request, $id),
            default             => Response::redirect('/admin/page/favorite-digital-memberships'),
        };
    }

    /**
     * Overview list of plans and customer subscriptions.
     */
    public function index(Request $request): string
    {
        $plans = $this->membershipService->listPlans();

        $statusFilter = (string)$request->get('status', 'all');
        $page = max(1, (int)$request->get('page', 1));

        $filters = [];
        if ($statusFilter !== 'all' && MembershipStatus::isValid($statusFilter)) {
            $filters['status'] = $statusFilter;
        }

        $membershipList = $this->membershipService->getRepo()->listMemberships($filters, $page, 25);

        return $this->renderView('memberships/index', [
            'plans'          => $plans,
            'memberships'    => $membershipList['items'],
            'total'          => $membershipList['total'],
            'page'           => $membershipList['page'],
            'totalPages'     => $membershipList['total_pages'],
            'statusFilter'   => $statusFilter,
            'csrfToken'      => $this->getCsrfToken(),
            'flashSuccess'   => $_SESSION['flash_success'] ?? null,
            'flashError'     => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Show form to create a new membership tier.
     */
    public function createPlanForm(Request $request): string
    {
        $old = $_SESSION['old_input'] ?? [];
        unset($_SESSION['old_input']);

        return $this->renderView('memberships/create_plan', [
            'old'        => $old,
            'csrfToken'  => $this->getCsrfToken(),
            'flashError' => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Process creation of a new membership plan.
     */
    public function storePlan(Request $request): Response
    {
        $productInput = [
            'title'            => (string)$request->post('title', ''),
            'slug'             => (string)$request->post('slug', ''),
            'description'      => (string)$request->post('description', ''),
            'original_price'   => (string)$request->post('original_price', '0.00'),
            'discount_percent' => (string)$request->post('discount_percent', '0.00'),
            'is_free'          => (int)($request->post('is_free') ? 1 : 0),
            'status'           => (string)$request->post('status', ProductStatus::PUBLISHED),
        ];

        $planInput = [
            'plan_type'           => (string)$request->post('plan_type', 'monthly'),
            'grace_period_days'   => $request->post('grace_period_days'),
            'allows_auto_renewal' => (int)($request->post('allows_auto_renewal') ? 1 : 0),
        ];

        try {
            $productId = $this->membershipService->createPlan($productInput, $planInput);
            $_SESSION['flash_success'] = "Membership Plan #{$productId} created successfully.";
            return Response::redirect('/admin/page/favorite-digital-memberships');
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to create membership plan: ' . $e->getMessage();
            $_SESSION['old_input']   = array_merge($productInput, $planInput);
            return Response::redirect('/admin/page/favorite-digital-memberships?action=create_plan');
        }
    }

    /**
     * Show form to edit an existing membership plan.
     */
    public function editPlanForm(Request $request, int $productId): Response|string
    {
        $product = $this->membershipService->getRepo()->findProduct($productId);
        if (!$product || $product->product_type !== ProductType::MEMBERSHIP) {
            $_SESSION['flash_error'] = 'Membership plan product not found.';
            return Response::redirect('/admin/page/favorite-digital-memberships');
        }

        $plan = $this->membershipService->getPlanByProductId($productId);
        $old = $_SESSION['old_input'] ?? [
            'title'               => $product->title,
            'slug'                => $product->slug,
            'description'         => $product->description,
            'original_price'      => $product->original_price,
            'discount_percent'    => $product->discount_percent,
            'final_price'         => $product->final_price,
            'is_free'             => $product->is_free,
            'status'              => $product->status,
            'plan_type'           => $plan ? $plan->plan_type : 'monthly',
            'grace_period_days'   => $plan ? $plan->grace_period_days : 3,
            'allows_auto_renewal' => $plan ? $plan->allows_auto_renewal : 0,
        ];
        unset($_SESSION['old_input']);

        return $this->renderView('memberships/edit_plan', [
            'product'    => $product,
            'plan'       => $plan,
            'old'        => $old,
            'csrfToken'  => $this->getCsrfToken(),
            'flashError' => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Process update of an existing membership plan.
     */
    public function updatePlan(Request $request, int $productId): Response
    {
        $productInput = [
            'title'            => (string)$request->post('title', ''),
            'slug'             => (string)$request->post('slug', ''),
            'description'      => (string)$request->post('description', ''),
            'original_price'   => (string)$request->post('original_price', '0.00'),
            'discount_percent' => (string)$request->post('discount_percent', '0.00'),
            'is_free'          => (int)($request->post('is_free') ? 1 : 0),
            'status'           => (string)$request->post('status', ProductStatus::PUBLISHED),
        ];

        $planInput = [
            'plan_type'           => (string)$request->post('plan_type', 'monthly'),
            'grace_period_days'   => $request->post('grace_period_days'),
            'allows_auto_renewal' => (int)($request->post('allows_auto_renewal') ? 1 : 0),
        ];

        try {
            $this->membershipService->updatePlan($productId, $productInput, $planInput);
            $_SESSION['flash_success'] = "Membership Plan #{$productId} updated successfully.";
            return Response::redirect('/admin/page/favorite-digital-memberships');
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to update membership plan: ' . $e->getMessage();
            $_SESSION['old_input']   = array_merge($productInput, $planInput);
            return Response::redirect('/admin/page/favorite-digital-memberships?action=edit_plan&id=' . $productId);
        }
    }

    /**
     * Detailed view of a customer membership record.
     */
    public function viewMembership(Request $request, int $membershipId): Response|string
    {
        $membership = $this->membershipService->getRepo()->getMembershipWithPlanAndUser($membershipId);
        if (!$membership) {
            $_SESSION['flash_error'] = 'Customer membership record not found.';
            return Response::redirect('/admin/page/favorite-digital-memberships');
        }

        $availablePlans = $this->membershipService->listPlans();

        return $this->renderView('memberships/view', [
            'membership'     => $membership,
            'availablePlans' => $availablePlans,
            'csrfToken'      => $this->getCsrfToken(),
            'flashSuccess'   => $_SESSION['flash_success'] ?? null,
            'flashError'     => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Admin manual extension of customer membership.
     */
    public function extendMembership(Request $request, int $membershipId): Response
    {
        $newPlanId = $request->post('new_plan_id') ? (int)$request->post('new_plan_id') : null;

        try {
            $updated = $this->membershipService->extendMembership($membershipId, $newPlanId);
            $_SESSION['flash_success'] = "Membership #{$membershipId} extended successfully until {$updated->expires_at}.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Extension failed: ' . $e->getMessage();
        }

        $redirect = $request->post('redirect_to', '/admin/page/favorite-digital-memberships?action=view_membership&id=' . $membershipId);
        return Response::redirect((string)$redirect);
    }

    /**
     * Admin toggle auto-renewal for customer membership.
     */
    public function toggleAutoRenew(Request $request, int $membershipId): Response
    {
        $enable = !empty($request->post('enable'));

        try {
            if ($enable) {
                $this->membershipService->enableAutoRenewal($membershipId);
                $_SESSION['flash_success'] = "Auto-renewal enabled for Membership #{$membershipId}.";
            } else {
                $this->membershipService->disableAutoRenewal($membershipId);
                $_SESSION['flash_success'] = "Auto-renewal disabled for Membership #{$membershipId}. Active paid time is preserved.";
            }
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Toggle auto-renewal failed: ' . $e->getMessage();
        }

        $redirect = $request->post('redirect_to', '/admin/page/favorite-digital-memberships?action=view_membership&id=' . $membershipId);
        return Response::redirect((string)$redirect);
    }

    /**
     * Admin manual expiration of customer membership.
     */
    public function expireMembership(Request $request, int $membershipId): Response
    {
        try {
            $this->membershipService->expireMembership($membershipId);
            $_SESSION['flash_success'] = "Membership #{$membershipId} has been manually expired.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Expiration failed: ' . $e->getMessage();
        }

        $redirect = $request->post('redirect_to', '/admin/page/favorite-digital-memberships?action=view_membership&id=' . $membershipId);
        return Response::redirect((string)$redirect);
    }

    /**
     * Admin manual recovery of membership from grace period.
     */
    public function recoverGrace(Request $request, int $membershipId): Response
    {
        try {
            $this->membershipService->recoverFromGrace($membershipId);
            $_SESSION['flash_success'] = "Membership #{$membershipId} has been recovered from grace period.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Grace recovery failed: ' . $e->getMessage();
        }

        $redirect = $request->post('redirect_to', '/admin/page/favorite-digital-memberships?action=view_membership&id=' . $membershipId);
        return Response::redirect((string)$redirect);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
}
