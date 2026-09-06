<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Services\CustomerAccountService;
use FavoriteCMS\Models\User;
use Throwable;

/**
 * CustomerAccountController
 *
 * Handles customer-facing account areas:
 * - Digital Library (/account/digital, /account/library)
 * - Membership Dashboard (/account/membership, /account/memberships)
 * - Refund History (/account/refunds, /account/digital/refunds)
 */
class CustomerAccountController
{
    protected Application $app;
    protected CustomerAccountService $accountService;

    public function __construct(Application $app, CustomerAccountService $accountService)
    {
        $this->app = $app;
        $this->accountService = $accountService;
    }

    public function getAccountService(): CustomerAccountService
    {
        return $this->accountService;
    }

    /**
     * Customer Digital Library Hub.
     */
    public function library(Request $request): Response|string
    {
        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            return Response::redirect('/login?redirect=' . urlencode('/account/digital'));
        }

        $page = max(1, (int)$request->get('page', 1));
        $productType = trim((string)$request->get('product_type', ''));
        $status = trim((string)$request->get('status', ''));
        $search = trim((string)($request->get('search') ?? $request->get('q') ?? ''));

        $filters = [
            'product_type' => $productType,
            'status'       => $status,
            'search'       => $search,
        ];

        $library = $this->accountService->getDigitalLibrary($userId, $filters, $page, 12);
        $wallet = $this->accountService->getWalletSummary($userId);

        return $this->renderView('account/library', [
            'items'        => $library['items'],
            'total'        => $library['total'],
            'page'         => $library['page'],
            'perPage'      => $library['per_page'],
            'totalPages'   => $library['total_pages'],
            'typeCounts'   => $library['type_counts'],
            'activeType'   => $productType,
            'activeStatus' => $status,
            'searchTerm'   => $search,
            'wallet'       => $wallet,
            'userId'       => $userId,
            'activeTab'    => 'library',
        ]);
    }

    /**
     * Customer Membership Dashboard.
     */
    public function membership(Request $request): Response|string
    {
        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            return Response::redirect('/login?redirect=' . urlencode('/account/membership'));
        }

        $dashboard = $this->accountService->getMembershipDashboard($userId);

        return $this->renderView('account/membership', [
            'activeMembership' => $dashboard['active_membership'],
            'hasActive'        => $dashboard['has_active'],
            'allMemberships'   => $dashboard['all_memberships'],
            'coveredPerks'     => $dashboard['covered_perks'],
            'wallet'           => $dashboard['wallet'],
            'siteCurrency'     => $dashboard['site_currency'],
            'userId'           => $userId,
            'activeTab'        => 'membership',
        ]);
    }

    /**
     * Customer Refund History.
     */
    public function refunds(Request $request): Response|string
    {
        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            return Response::redirect('/login?redirect=' . urlencode('/account/refunds'));
        }

        $page = max(1, (int)$request->get('page', 1));
        $refundData = $this->accountService->getRefundHistory($userId, $page, 15);

        return $this->renderView('account/refunds', [
            'refunds'       => $refundData['refunds'],
            'total'         => $refundData['total'],
            'page'          => $refundData['page'],
            'perPage'       => $refundData['per_page'],
            'totalPages'    => $refundData['total_pages'],
            'totalRefunded' => $refundData['total_refunded'],
            'wallet'        => $refundData['wallet'],
            'userId'        => $userId,
            'activeTab'     => 'refunds',
        ]);
    }

    /**
     * Authorize customer access and redirect to an external online resource.
     */
    public function accessResource(Request $request, int $productId): Response
    {
        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            return Response::redirect('/login?redirect=' . urlencode('/account/resource/' . $productId));
        }

        try {
            $url = $this->accountService->authorizeExternalResource($userId, $productId);
            return Response::redirect($url);
        } catch (\Throwable $e) {
            $status = (int)$e->getCode();
            if ($status < 400 || $status > 599) {
                $status = 403;
            }
            return Response::make(
                '<h1>Access Denied</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p><p><a href="/account/digital">Return to Digital Library</a></p>',
                $status
            );
        }
    }

    protected function resolveCurrentUserId(): int
    {
        if (isset($GLOBALS['_test_current_user_id']) && (int)$GLOBALS['_test_current_user_id'] > 0) {
            return (int)$GLOBALS['_test_current_user_id'];
        }
        if (isset($GLOBALS['_test_current_user']) && isset($GLOBALS['_test_current_user']->id)) {
            return (int)$GLOBALS['_test_current_user']->id;
        }
        if (function_exists('current_user_id')) {
            $id = current_user_id();
            if ($id !== null && (int)$id > 0) {
                return (int)$id;
            }
        }
        if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
            return (int)$_SESSION['user_id'];
        }
        return (int)($_SESSION['auth_user_id'] ?? 0);
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
