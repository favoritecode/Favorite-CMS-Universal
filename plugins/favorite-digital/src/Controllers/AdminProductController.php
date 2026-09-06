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

class AdminProductController
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
     * Dispatcher for admin digital product requests.
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
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage digital products.</p>', 403);
        } elseif (!$currentUser && function_exists('current_user_can')) {
            try {
                if (!current_user_can('manage_options')) {
                    return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage digital products.</p>', 403);
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
            return Response::redirect('/admin/page/favorite-digital');
        }

        $action = (string)$request->post('action', '');
        $id = (int)$request->post('id', 0);

        return match ($action) {
            'store'   => $this->store($request),
            'update'  => $this->update($request, $id),
            'publish' => $this->publish($request, $id),
            'draft'   => $this->draft($request, $id),
            'archive' => $this->archive($request, $id),
            default   => Response::redirect('/admin/page/favorite-digital'),
        };
    }

    /**
     * List digital products.
     */
    public function index(Request $request): string
    {
        $repo = $this->service->getRepository();
        $status = $request->get('status');
        $statusFilter = ($status !== null && $status !== '' && $status !== 'all') ? (string)$status : null;
        $search = $request->get('q') ? (string)$request->get('q') : null;

        $filters = [
            'product_type' => ProductType::DIGITAL,
            'status'       => $statusFilter,
            'search'       => $search,
        ];
        $result = $repo->listProducts($filters, 1, 100);
        $products = $result['items'];
        $counts = $result['counts'];

        $csrfToken = $this->getCsrfToken();

        return $this->renderView('products/index', [
            'products'      => $products,
            'statusFilter'  => $statusFilter,
            'search'        => $search,
            'counts'        => $counts,
            'csrfToken'     => $csrfToken,
            'flashSuccess'  => $_SESSION['flash_success'] ?? null,
            'flashError'    => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Show create form.
     */
    public function create(Request $request): string
    {
        $old = $_SESSION['old_input'] ?? [];
        unset($_SESSION['old_input']);

        return $this->renderView('products/create', [
            'old'          => $old,
            'csrfToken'    => $this->getCsrfToken(),
            'flashError'   => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Store newly created digital product.
     */
    public function store(Request $request): Response
    {
        $productInput = [
            'title'                 => (string)$request->post('title', ''),
            'slug'                  => (string)$request->post('slug', ''),
            'description'          => (string)$request->post('description', ''),
            'short_description'    => (string)$request->post('short_description', ''),
            'original_price'        => (string)$request->post('original_price', '0.00'),
            'discount_percent'     => (string)$request->post('discount_percent', '0.00'),
            'is_free'               => (int)($request->post('is_free') ? 1 : 0),
            'status'                => (string)$request->post('status', ProductStatus::DRAFT),
            'is_membership_eligible'=> (int)($request->post('is_membership_eligible') ? 1 : 0),
            'cover_image_url'       => (string)$request->post('cover_image_url', ''),
        ];

        $maxDownloads = $request->post('max_downloads', $request->post('max_downloads_per_user'));
        $detailsInput = [
            'version'                => (string)$request->post('version', '1.0.0'),
            'max_downloads'          => ($maxDownloads !== '' && $maxDownloads !== null) ? (int)$maxDownloads : 0,
            'download_expiry_days'   => $request->post('download_expiry_days') !== '' && $request->post('download_expiry_days') !== null
                                        ? (int)$request->post('download_expiry_days')
                                        : 0,
            'is_membership_eligible' => (int)($request->post('is_membership_eligible') ? 1 : 0),
            'resource_type'          => (string)$request->post('resource_type', 'file'),
            'resource_url'           => (string)$request->post('resource_url', ''),
        ];

        $uploadedFile = $request->file('digital_file');
        $uploadedImage = $request->file('cover_image');

        try {
            $productId = $this->service->createDigitalProduct($productInput, $detailsInput, $uploadedFile, $uploadedImage);
            $_SESSION['flash_success'] = "Digital product #{$productId} created successfully.";
            return Response::redirect('/admin/page/favorite-digital?action=view&id=' . $productId);
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to create product: ' . $e->getMessage();
            $_SESSION['old_input'] = array_merge($productInput, $detailsInput);
            return Response::redirect('/admin/page/favorite-digital?action=create');
        }
    }

    /**
     * Show edit form.
     */
    public function edit(Request $request, int $id): Response|string
    {
        $product = $this->service->getRepository()->findProduct($id);
        if (!$product || $product->product_type !== ProductType::DIGITAL) {
            $_SESSION['flash_error'] = 'Digital product not found.';
            return Response::redirect('/admin/page/favorite-digital');
        }

        $details = $this->service->getRepository()->findProductDetails($id);
        $old = $_SESSION['old_input'] ?? null;
        unset($_SESSION['old_input']);

        return $this->renderView('products/edit', [
            'product'      => $product,
            'details'      => $details,
            'old'          => $old,
            'csrfToken'    => $this->getCsrfToken(),
            'flashError'   => $_SESSION['flash_error'] ?? null,
            'flashSuccess' => $_SESSION['flash_success'] ?? null,
        ]);
    }

    /**
     * Update digital product.
     */
    public function update(Request $request, int $id): Response
    {
        if ($id <= 0) {
            $_SESSION['flash_error'] = 'Invalid product ID.';
            return Response::redirect('/admin/page/favorite-digital');
        }

        $productInput = [
            'title'                 => (string)$request->post('title', ''),
            'slug'                  => (string)$request->post('slug', ''),
            'description'          => (string)$request->post('description', ''),
            'short_description'    => (string)$request->post('short_description', ''),
            'original_price'        => (string)$request->post('original_price', '0.00'),
            'discount_percent'     => (string)$request->post('discount_percent', '0.00'),
            'is_free'               => (int)($request->post('is_free') ? 1 : 0),
            'status'                => (string)$request->post('status', ProductStatus::DRAFT),
            'is_membership_eligible'=> (int)($request->post('is_membership_eligible') ? 1 : 0),
            'cover_image_url'       => (string)$request->post('cover_image_url', ''),
        ];

        $maxDownloads = $request->post('max_downloads', $request->post('max_downloads_per_user'));
        $detailsInput = [
            'version'                => (string)$request->post('version', '1.0.0'),
            'max_downloads'          => ($maxDownloads !== '' && $maxDownloads !== null) ? (int)$maxDownloads : 0,
            'download_expiry_days'   => $request->post('download_expiry_days') !== '' && $request->post('download_expiry_days') !== null
                                        ? (int)$request->post('download_expiry_days')
                                        : 0,
            'is_membership_eligible' => (int)($request->post('is_membership_eligible') ? 1 : 0),
            'resource_type'          => (string)$request->post('resource_type', 'file'),
            'resource_url'           => (string)$request->post('resource_url', ''),
        ];

        $uploadedFile = $request->file('digital_file');
        $uploadedImage = $request->file('cover_image');

        try {
            $this->service->updateDigitalProduct($id, $productInput, $detailsInput, $uploadedFile, $uploadedImage);
            $_SESSION['flash_success'] = 'Digital product updated successfully.';
            return Response::redirect('/admin/page/favorite-digital?action=view&id=' . $id);
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to update product: ' . $e->getMessage();
            $_SESSION['old_input'] = array_merge($productInput, $detailsInput);
            return Response::redirect('/admin/page/favorite-digital?action=edit&id=' . $id);
        }
    }

    /**
     * View digital product details.
     */
    public function view(Request $request, int $id): Response|string
    {
        $product = $this->service->getRepository()->findProduct($id);
        if (!$product || $product->product_type !== ProductType::DIGITAL) {
            $_SESSION['flash_error'] = 'Digital product not found.';
            return Response::redirect('/admin/page/favorite-digital');
        }

        $details = $this->service->getRepository()->findProductDetails($id);

        return $this->renderView('products/view', [
            'product'      => $product,
            'details'      => $details,
            'csrfToken'    => $this->getCsrfToken(),
            'flashSuccess' => $_SESSION['flash_success'] ?? null,
            'flashError'   => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Publish digital product.
     */
    public function publish(Request $request, int $id): Response
    {
        try {
            $this->service->publishProduct($id);
            $_SESSION['flash_success'] = "Product #{$id} published successfully.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Publish failed: ' . $e->getMessage();
        }

        $redirect = $request->post('redirect_to', '/admin/page/favorite-digital?action=view&id=' . $id);
        return Response::redirect((string)$redirect);
    }

    /**
     * Move digital product to draft.
     */
    public function draft(Request $request, int $id): Response
    {
        try {
            $this->service->draftProduct($id);
            $_SESSION['flash_success'] = "Product #{$id} switched to draft.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Draft update failed: ' . $e->getMessage();
        }

        $redirect = $request->post('redirect_to', '/admin/page/favorite-digital?action=view&id=' . $id);
        return Response::redirect((string)$redirect);
    }

    /**
     * Archive digital product.
     */
    public function archive(Request $request, int $id): Response
    {
        try {
            $this->service->archiveProduct($id);
            $_SESSION['flash_success'] = "Product #{$id} archived successfully.";
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Archive failed: ' . $e->getMessage();
        }

        $redirect = $request->post('redirect_to', '/admin/page/favorite-digital?action=view&id=' . $id);
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
