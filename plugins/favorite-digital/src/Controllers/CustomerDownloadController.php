<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Controllers;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Exceptions\DownloadException;
use FavoriteCMS\Digital\Repositories\EntitlementRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use FavoriteCMS\Digital\Services\DownloadService;
use FavoriteCMS\Digital\Services\MembershipLifecycleService;
use FavoriteCMS\Models\User;
use Throwable;

class CustomerDownloadController
{
    protected Application $app;
    protected DownloadService $downloadService;
    protected EntitlementRepository $entitlementRepo;
    protected ProductRepository $productRepo;
    protected MembershipLifecycleService $membershipService;

    public function __construct(
        Application $app,
        DownloadService $downloadService,
        EntitlementRepository $entitlementRepo,
        ProductRepository $productRepo,
        MembershipLifecycleService $membershipService
    ) {
        $this->app = $app;
        $this->downloadService = $downloadService;
        $this->entitlementRepo = $entitlementRepo;
        $this->productRepo = $productRepo;
        $this->membershipService = $membershipService;
    }

    public function getDownloadService(): DownloadService
    {
        return $this->downloadService;
    }

    /**
     * Handle file download request.
     */
    public function download(Request $request, string $token): Response
    {
        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            return Response::redirect('/login');
        }

        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

        try {
            $this->downloadService->serveDownload($token, $userId, $ip, $userAgent);
            return Response::make('File streamed successfully', 200);
        } catch (DownloadException $e) {
            $statusCode = str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'unavailable') ? 404 : 403;
            return Response::make(
                '<h1>Access Denied</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>',
                $statusCode
            );
        } catch (Throwable $e) {
            return Response::make(
                '<h1>Download Error</h1><p>An unexpected error occurred while preparing your download.</p>',
                500
            );
        }
    }

    /**
     * Render the customer's digital downloads dashboard.
     */
    public function index(Request $request): Response|string
    {
        $userId = $this->resolveCurrentUserId();
        if ($userId <= 0) {
            return Response::redirect('/login');
        }

        $entitlements = $this->entitlementRepo->getEntitlementsByUser($userId);
        $hasActiveMembership = $this->membershipService->hasActiveMembership($userId);

        $downloadItems = [];

        foreach ($entitlements as $ent) {
            if ($ent->status !== 'active') {
                continue;
            }

            $product = $this->productRepo->findProduct((int)$ent->product_id);
            if (!$product || $product->product_type !== ProductType::DIGITAL) {
                continue;
            }

            $details = $this->productRepo->findProductDetails((int)$ent->product_id);
            if (!$details || empty($details->file_path)) {
                continue;
            }

            $tokenRecord = $this->downloadService->getOrCreateDownloadToken($userId, (int)$ent->product_id, (int)$ent->id);

            $isMembershipAccess = $hasActiveMembership && !empty($details->is_membership_eligible);
            $downloadCount = (int)$tokenRecord->download_count;
            $maxLimit = DownloadService::MAX_PURCHASE_DOWNLOADS;
            $remaining = max(0, $maxLimit - $downloadCount);

            $downloadItems[] = [
                'entitlement_id'  => (int)$ent->id,
                'product_id'      => (int)$ent->product_id,
                'product_title'   => (string)$product->title,
                'source_type'     => (string)$ent->source_type,
                'token'           => (string)$tokenRecord->download_token,
                'download_count'  => $downloadCount,
                'max_limit'       => $maxLimit,
                'remaining'       => $remaining,
                'is_membership'   => $isMembershipAccess,
                'is_exhausted'    => !$isMembershipAccess && $downloadCount >= $maxLimit,
                'expires_at'      => $ent->expires_at,
            ];
        }

        return $this->renderView('downloads/index', [
            'items'               => $downloadItems,
            'hasActiveMembership' => $hasActiveMembership,
            'userId'              => $userId,
        ]);
    }

    protected function resolveCurrentUserId(): int
    {
        if (isset($GLOBALS['_test_current_user']) && isset($GLOBALS['_test_current_user']->id)) {
            return (int)$GLOBALS['_test_current_user']->id;
        }
        return (int)($_SESSION['auth_user_id'] ?? 0);
    }

    protected function renderView(string $viewName, array $data = []): string
    {
        extract($data);
        $viewFile = dirname(__DIR__, 2) . '/views/customer/' . $viewName . '.php';
        if (!file_exists($viewFile)) {
            return 'View not found: ' . htmlspecialchars($viewName, ENT_QUOTES, 'UTF-8');
        }

        ob_start();
        include $viewFile;
        return (string)ob_get_clean();
    }
}
