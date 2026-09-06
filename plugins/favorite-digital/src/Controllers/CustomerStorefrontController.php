<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Exceptions\OrderValidationException;
use FavoriteCMS\Digital\Services\StorefrontService;
use FavoriteCMS\Models\User;
use Throwable;

/**
 * CustomerStorefrontController
 *
 * Public catalog storefront, product discovery, search, filtering, sorting,
 * detailed product presentation, and checkout flow initiation.
 */
class CustomerStorefrontController
{
    protected Application $app;
    protected StorefrontService $storefrontService;

    public function __construct(Application $app, StorefrontService $storefrontService)
    {
        $this->app = $app;
        $this->storefrontService = $storefrontService;
    }

    public function getStorefrontService(): StorefrontService
    {
        return $this->storefrontService;
    }

    /**
     * Catalog Storefront Listing.
     */
    public function index(Request $request): Response|string
    {
        $userId = $this->resolveCurrentUserId();

        $page = max(1, (int)$request->get('page', 1));
        $search = trim((string)($request->get('search') ?? $request->get('q') ?? ''));
        $productType = trim((string)$request->get('product_type', ''));
        $price = trim((string)$request->get('price', ''));
        $membership = trim((string)$request->get('membership', ''));
        $sort = trim((string)$request->get('sort', 'newest'));

        $filters = [
            'search'       => $search,
            'product_type' => $productType,
            'price'        => $price,
            'membership'   => $membership,
            'sort'         => $sort,
        ];

        $catalog = $this->storefrontService->browseProducts($filters, $page, 12, $userId > 0 ? $userId : null);

        return $this->renderView('store/index', [
            'catalog'          => $catalog,
            'products'         => $catalog['items'],
            'total'            => $catalog['total'],
            'page'             => $catalog['page'],
            'perPage'          => $catalog['perPage'],
            'totalPages'       => $catalog['totalPages'],
            'typeCounts'       => $catalog['typeCounts'],
            'activeSort'       => $catalog['activeSort'],
            'searchTerm'       => $search,
            'activeType'       => $productType,
            'activePrice'      => $price,
            'activeMembership' => $membership,
            'siteCurrency'     => $catalog['site_currency'],
            'userId'           => $userId,
            'flashSuccess'     => $_SESSION['flash_success'] ?? null,
            'flashError'       => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Product Detail Page.
     * Enforces public visibility; unpublished products return HTTP 404.
     */
    public function show(Request $request, string $slug): Response|string
    {
        $userId = $this->resolveCurrentUserId();

        $data = $this->storefrontService->getProductDetail($slug, $userId > 0 ? $userId : null);
        if ($data === null) {
            return Response::make("<h1>404 Not Found</h1><p>The requested product is unavailable or does not exist.</p>", 404);
        }

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        return $this->renderView('store/show', array_merge($data, [
            'slug'         => $slug,
            'userId'       => $userId,
            'csrfToken'    => $this->getCsrfToken(),
            'flashSuccess' => $flashSuccess,
            'flashError'   => $flashError,
        ]));
    }

    /**
     * Handle Customer "Buy / Purchase" POST Action.
     * Validates CSRF, verifies authentication, creates order via OrderService,
     * and redirects to either free fulfillment or Phase 5B checkout.
     */
    public function buy(Request $request, string $slug): Response
    {
        $cleanSlug = trim($slug);

        // 1. Validate CSRF Token
        $submittedToken = (string)$request->post('_token', '');
        $sessionToken   = (string)($_SESSION['_token'] ?? '');

        if ($submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
            $_SESSION['flash_error'] = 'Security token expired or invalid (CSRF failure). Please try again.';
            return Response::redirect('/store/' . urlencode($cleanSlug));
        }

        // 2. Authenticate Customer
        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            $_SESSION['flash_error'] = 'Please sign in to complete your purchase.';
            return Response::redirect('/login?redirect=' . urlencode('/store/' . $cleanSlug));
        }

        // 3. Initiate Purchase via StorefrontService
        try {
            $result = $this->storefrontService->initiatePurchase($cleanSlug, $userId);

            if (!empty($result['message'])) {
                $_SESSION['flash_success'] = $result['message'];
            }

            return Response::redirect($result['redirect_url']);
        } catch (OrderValidationException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return Response::redirect('/store/' . urlencode($cleanSlug));
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Could not process purchase request: ' . $e->getMessage();
            return Response::redirect('/store/' . urlencode($cleanSlug));
        }
    }

    protected function resolveCurrentUserId(): int
    {
        if (isset($GLOBALS['_test_current_user']) && isset($GLOBALS['_test_current_user']->id)) {
            return (int)$GLOBALS['_test_current_user']->id;
        }
        return (int)($_SESSION['auth_user_id'] ?? 0);
    }

    protected function getCsrfToken(): string
    {
        if (empty($_SESSION['_token'])) {
            $_SESSION['_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['_token'];
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
