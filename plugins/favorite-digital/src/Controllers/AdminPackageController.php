<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Domain\ProductStatus;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Services\ProductManagementService;
use FavoriteCMS\Models\User;
use Throwable;

class AdminPackageController
{
    protected Application $app;
    protected ProductManagementService $service;

    public function __construct(
        Application $app,
        ProductManagementService $service
    ) {
        $this->app = $app;
        $this->service = $service;
    }

    public function getService(): ProductManagementService
    {
        return $this->service;
    }

    /**
     * Dispatcher for admin package requests.
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
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage packages.</p>', 403);
        } elseif (!$currentUser && function_exists('current_user_can')) {
            try {
                if (!current_user_can('manage_options')) {
                    return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage packages.</p>', 403);
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
            'create' => $this->create($request),
            'edit'   => $this->edit($request, $id),
            'view'   => $this->view($request, $id),
            default  => $this->index($request),
        };
    }

    protected function handlePost(Request $request): Response
    {
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash_error'] = 'Security token expired or invalid (CSRF failure). Please try again.';
            return Response::redirect('/admin/page/favorite-digital-packages');
        }

        $action = (string)$request->post('action', '');
        $id = (int)$request->post('id', 0);

        return match ($action) {
            'store'         => $this->store($request),
            'update'        => $this->update($request, $id),
            'add_item'      => $this->addItem($request, $id),
            'remove_item'   => $this->removeItem($request, $id),
            'reorder_items' => $this->reorderItems($request, $id),
            'publish'       => $this->publish($request, $id),
            'draft'         => $this->draft($request, $id),
            'archive'       => $this->archive($request, $id),
            default         => Response::redirect('/admin/page/favorite-digital-packages'),
        };
    }

    /**
     * List packages.
     */
    public function index(Request $request): string
    {
        $repo = $this->service->getRepository();
        $status = $request->get('status');
        $statusFilter = ($status !== null && $status !== '' && $status !== 'all') ? (string)$status : null;
        $search = $request->get('q') ? (string)$request->get('q') : null;

        $filters = [
            'product_type' => ProductType::PACKAGE,
            'status'       => $statusFilter,
            'search'       => $search,
        ];
        $result = $repo->listProducts($filters, 1, 100);
        $packages = $result['items'];
        $counts = $result['counts'];

        return $this->renderView('packages/index', [
            'packages'      => $packages,
            'statusFilter'  => $statusFilter,
            'search'        => $search,
            'counts'        => $counts,
            'csrfToken'     => $this->getCsrfToken(),
            'flashSuccess'  => $_SESSION['flash_success'] ?? null,
            'flashError'    => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Show package create form.
     */
    public function create(Request $request): string
    {
        $repo = $this->service->getRepository();
        $availableProducts = $repo->getAvailableProductsForPackage();

        $old = $_SESSION['old_input'] ?? [];
        unset($_SESSION['old_input']);

        return $this->renderView('packages/create', [
            'availableProducts' => $availableProducts,
            'old'               => $old,
            'csrfToken'         => $this->getCsrfToken(),
            'flashError'        => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Store newly created package.
     */
    public function store(Request $request): Response
    {
        $productInput = [
            'title'            => (string)$request->post('title', ''),
            'slug'             => (string)$request->post('slug', ''),
            'description'      => (string)$request->post('description', ''),
            'original_price'   => (string)$request->post('original_price', '0.00'),
            'discount_percent' => (string)$request->post('discount_percent', '0.00'),
            'is_free'          => (int)($request->post('is_free') ? 1 : 0),
            'status'           => (string)$request->post('status', ProductStatus::DRAFT),
        ];

        $packageInput = [
            'package_type' => (string)$request->post('package_type', 'bundle'),
        ];

        $rawItems = $request->post('included_items', []);
        $includedItems = is_array($rawItems) ? $rawItems : [];

        try {
            $packageId = $this->service->createPackage($productInput, $packageInput, $includedItems);
            $_SESSION['flash_success'] = "Package #{$packageId} created successfully.";
            return Response::redirect('/admin/page/favorite-digital-packages?action=view&id=' . $packageId);
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to create package: ' . $e->getMessage();
            $_SESSION['old_input'] = array_merge($productInput, $packageInput, ['included_items' => $includedItems]);
            return Response::redirect('/admin/page/favorite-digital-packages?action=create');
        }
    }

    /**
     * Show edit form.
     */
    public function edit(Request $request, int $id): Response|string
    {
        $repo = $this->service->getRepository();
        $product = $repo->findProduct($id);
        if (!$product || $product->product_type !== ProductType::PACKAGE) {
            $_SESSION['flash_error'] = 'Package not found.';
            return Response::redirect('/admin/page/favorite-digital-packages');
        }

        $package = $repo->findPackageByProductId($id);
        $packageId = $package ? (int)$package->id : 0;
        $items = $packageId > 0 ? $repo->getPackageItemsWithProducts($packageId) : [];
        $availableProducts = $repo->getAvailableProductsForPackage($id);

        $old = $_SESSION['old_input'] ?? null;
        unset($_SESSION['old_input']);

        return $this->renderView('packages/edit', [
            'product'           => $product,
            'package'           => $package,
            'items'             => $items,
            'availableProducts' => $availableProducts,
            'old'               => $old,
            'csrfToken'         => $this->getCsrfToken(),
            'flashError'        => $_SESSION['flash_error'] ?? null,
            'flashSuccess'      => $_SESSION['flash_success'] ?? null,
        ]);
    }

    /**
     * Update package.
     */
    public function update(Request $request, int $id): Response
    {
        if ($id <= 0) {
            $_SESSION['flash_error'] = 'Invalid package ID.';
            return Response::redirect('/admin/page/favorite-digital-packages');
        }

        $productInput = [
            'title'            => (string)$request->post('title', ''),
            'slug'             => (string)$request->post('slug', ''),
            'description'      => (string)$request->post('description', ''),
            'original_price'   => (string)$request->post('original_price', '0.00'),
            'discount_percent' => (string)$request->post('discount_percent', '0.00'),
            'is_free'          => (int)($request->post('is_free') ? 1 : 0),
            'status'           => (string)$request->post('status', ProductStatus::DRAFT),
        ];

        $packageInput = [
            'package_type' => (string)$request->post('package_type', 'bundle'),
        ];

        try {
            $this->service->updatePackage($id, $productInput, $packageInput);
            $_SESSION['flash_success'] = 'Package updated successfully.';
            return Response::redirect('/admin/page/favorite-digital-packages?action=view&id=' . $id);
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to update package: ' . $e->getMessage();
            $_SESSION['old_input'] = array_merge($productInput, $packageInput);
            return Response::redirect('/admin/page/favorite-digital-packages?action=edit&id=' . $id);
        }
    }

    /**
     * Add single item to package.
     */
    public function addItem(Request $request, int $packageProductId): Response
    {
        $includedProductId = (int)$request->post('included_product_id', 0);
        $sortOrder = (int)$request->post('sort_order', 0);

        try {
            $this->service->addPackageItem($packageProductId, $includedProductId, $sortOrder);
            $_SESSION['flash_success'] = 'Item added to package successfully.';
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to add item: ' . $e->getMessage();
        }

        return Response::redirect('/admin/page/favorite-digital-packages?action=edit&id=' . $packageProductId);
    }

    /**
     * Remove single item from package.
     */
    public function removeItem(Request $request, int $packageProductId): Response
    {
        $includedProductId = (int)$request->post('included_product_id', 0);

        try {
            $this->service->removePackageItem($packageProductId, $includedProductId);
            $_SESSION['flash_success'] = 'Item removed from package.';
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to remove item: ' . $e->getMessage();
        }

        return Response::redirect('/admin/page/favorite-digital-packages?action=edit&id=' . $packageProductId);
    }

    /**
     * Reorder package items.
     */
    public function reorderItems(Request $request, int $packageProductId): Response
    {
        $rawOrder = $request->post('item_ids', $request->post('item_order', []));
        $orderedIds = is_array($rawOrder) ? $rawOrder : [];

        try {
            $this->service->reorderPackageItems($packageProductId, $orderedIds);
            $_SESSION['flash_success'] = 'Package items reordered successfully.';
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to reorder items: ' . $e->getMessage();
        }

        return Response::redirect('/admin/page/favorite-digital-packages?action=edit&id=' . $packageProductId);
    }

    /**
     * View package details.
     */
    public function view(Request $request, int $id): Response|string
    {
        $repo = $this->service->getRepository();
        $product = $repo->findProduct($id);
        if (!$product || $product->product_type !== ProductType::PACKAGE) {
            $_SESSION['flash_error'] = 'Package not found.';
            return Response::redirect('/admin/page/favorite-digital-packages');
        }

        $package = $repo->findPackageByProductId($id);
        $packageId = $package ? (int)$package->id : 0;
        $items = $packageId > 0 ? $repo->getPackageItemsWithProducts($packageId) : [];

        // Compute total individual catalog value
        $combinedIndividualPrice = 0.00;
        foreach ($items as $item) {
            $combinedIndividualPrice += (float)$item->final_price;
        }

        return $this->renderView('packages/view', [
            'product'                 => $product,
            'package'                 => $package,
            'items'                   => $items,
            'combinedIndividualPrice' => $combinedIndividualPrice,
            'csrfToken'               => $this->getCsrfToken(),
            'flashSuccess'            => $_SESSION['flash_success'] ?? null,
            'flashError'              => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Publish package.
     */
    public function publish(Request $request, int $id): Response
    {
        try {
            $this->service->publishProduct($id);
            $_SESSION['flash_success'] = "Package #{$id} published successfully.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Publish failed: ' . $e->getMessage();
        }

        $redirect = $request->post('redirect_to', '/admin/page/favorite-digital-packages?action=view&id=' . $id);
        return Response::redirect((string)$redirect);
    }

    /**
     * Move package to draft.
     */
    public function draft(Request $request, int $id): Response
    {
        try {
            $this->service->draftProduct($id);
            $_SESSION['flash_success'] = "Package #{$id} switched to draft.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Draft update failed: ' . $e->getMessage();
        }

        $redirect = $request->post('redirect_to', '/admin/page/favorite-digital-packages?action=view&id=' . $id);
        return Response::redirect((string)$redirect);
    }

    /**
     * Archive package.
     */
    public function archive(Request $request, int $id): Response
    {
        try {
            $this->service->archiveProduct($id);
            $_SESSION['flash_success'] = "Package #{$id} archived successfully.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Archive failed: ' . $e->getMessage();
        }

        $redirect = $request->post('redirect_to', '/admin/page/favorite-digital-packages?action=view&id=' . $id);
        return Response::redirect((string)$redirect);
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
