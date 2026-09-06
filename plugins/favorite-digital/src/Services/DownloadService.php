<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Services;

use DateTimeImmutable;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Digital\Domain\ProductType;
use FavoriteCMS\Digital\Exceptions\DownloadException;
use FavoriteCMS\Digital\Repositories\DownloadRepository;
use FavoriteCMS\Digital\Repositories\EntitlementRepository;
use FavoriteCMS\Digital\Repositories\ProductRepository;
use Throwable;

/**
 * DownloadService
 *
 * Enforces authoritative download access control, token management,
 * atomic download limits, and secure file streaming for Favorite Digital.
 */
class DownloadService
{
    public const MAX_PURCHASE_DOWNLOADS = 3;

    protected DownloadRepository $downloadRepo;
    protected EntitlementRepository $entitlementRepo;
    protected ProductRepository $productRepo;
    protected MembershipLifecycleService $membershipService;
    protected DefaultEntitlementChecker $checker;
    protected DigitalFileStorageService $storageService;
    protected ?Database $db;

    public function __construct(
        DownloadRepository $downloadRepo,
        EntitlementRepository $entitlementRepo,
        ProductRepository $productRepo,
        MembershipLifecycleService $membershipService,
        DefaultEntitlementChecker $checker,
        DigitalFileStorageService $storageService,
        ?Database $db = null
    ) {
        $this->downloadRepo = $downloadRepo;
        $this->entitlementRepo = $entitlementRepo;
        $this->productRepo = $productRepo;
        $this->membershipService = $membershipService;
        $this->checker = $checker;
        $this->storageService = $storageService;
        $this->db = $db ?? $downloadRepo->getDatabase();
    }

    public function getDownloadRepository(): DownloadRepository
    {
        return $this->downloadRepo;
    }

    public function getEntitlementRepository(): EntitlementRepository
    {
        return $this->entitlementRepo;
    }

    public function getProductRepository(): ProductRepository
    {
        return $this->productRepo;
    }

    public function getMembershipService(): MembershipLifecycleService
    {
        return $this->membershipService;
    }

    public function getStorageService(): DigitalFileStorageService
    {
        return $this->storageService;
    }

    /**
     * Generate or retrieve a secure, unguessable 64-character download token for an authorized customer.
     */
    public function getOrCreateDownloadToken(int $userId, int $productId, ?int $entitlementId = null): object
    {
        if ($entitlementId !== null && $entitlementId > 0) {
            $ent = $this->entitlementRepo->findEntitlement($entitlementId);
            if (!$ent || (int)$ent->user_id !== $userId || (int)$ent->product_id !== $productId) {
                throw DownloadException::entitlementNotFound();
            }
        }

        $existing = $this->downloadRepo->findDownloadByUserAndProduct($userId, $productId, $entitlementId);
        if ($existing) {
            return $existing;
        }

        $token = bin2hex(random_bytes(32)); // 64 hex chars, 256 bits of entropy

        $downloadId = $this->downloadRepo->createDownloadRecord([
            'entitlement_id' => $entitlementId ?? 0,
            'product_id'     => $productId,
            'user_id'        => $userId,
            'download_token' => $token,
            'download_count' => 0,
        ]);

        return (object)$this->downloadRepo->findDownloadById($downloadId);
    }

    /**
     * Authoritatively validate download request:
     * 1. Authenticated user check
     * 2. Token lookup and ownership check
     * 3. Entitlement status (active, not revoked, not expired)
     * 4. Membership access check
     * 5. Download limit check (3 max for purchase vs unlimited for active membership)
     * 6. Path traversal and filesystem security check
     *
     * @return array Authorization payload
     * @throws DownloadException
     */
    public function authorizeDownload(string $token, int $authenticatedUserId): array
    {
        if ($authenticatedUserId <= 0) {
            throw DownloadException::unauthenticated();
        }

        $token = trim($token);
        if ($token === '') {
            throw DownloadException::invalidToken();
        }

        $download = $this->downloadRepo->findDownloadByToken($token);
        if (!$download) {
            throw DownloadException::invalidToken();
        }

        // Ownership enforcement: Token belongs to authenticated customer
        if ((int)$download->user_id !== $authenticatedUserId) {
            throw DownloadException::accessDenied("You are not authorized to access this download.");
        }

        $productId = (int)$download->product_id;
        $product = $this->productRepo->findProduct($productId);
        if (!$product) {
            throw DownloadException::fileNotFound("Product unavailable.");
        }

        if ($product->product_type !== ProductType::DIGITAL) {
            throw DownloadException::accessDenied("Only digital products can be downloaded.");
        }

        $details = $this->productRepo->findProductDetails($productId);
        if (!$details || empty($details->file_path)) {
            throw DownloadException::fileNotFound("No downloadable file attached to this product.");
        }

        // Determine access mode (Direct/Package purchase vs Active Membership)
        $isMembershipAccess = false;
        $entitlement = null;

        if (!empty($download->entitlement_id)) {
            $entitlement = $this->entitlementRepo->findEntitlement((int)$download->entitlement_id);
        }
        if (!$entitlement) {
            $entitlement = $this->entitlementRepo->findActiveEntitlement($authenticatedUserId, $productId);
        }

        // Check if user has an active membership
        $hasActiveMembership = $this->membershipService->hasActiveMembership($authenticatedUserId);
        $isMembershipEligible = !empty($details->is_membership_eligible);

        if ($entitlement) {
            // Validate entitlement ownership
            if ((int)$entitlement->user_id !== $authenticatedUserId) {
                throw DownloadException::accessDenied("Entitlement does not belong to the current user.");
            }

            // Validate entitlement product match
            if ((int)$entitlement->product_id !== $productId) {
                throw DownloadException::entitlementNotFound();
            }

            // Revocation check
            if ($entitlement->status === 'revoked') {
                throw DownloadException::entitlementRevoked();
            }

            // Expiration check
            if ($entitlement->status === 'expired') {
                throw DownloadException::entitlementExpired();
            }

            $nowStr = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            if (!empty($entitlement->expires_at) && $entitlement->expires_at <= $nowStr) {
                throw DownloadException::entitlementExpired();
            }

            // Check if user is downloading via active membership (Scenario R: does not consume 3-download quota)
            if ($hasActiveMembership && $isMembershipEligible) {
                $isMembershipAccess = true;
            } else {
                // Direct purchase / package access: enforce maximum 3 downloads
                $maxDownloads = self::MAX_PURCHASE_DOWNLOADS;
                if ((int)$download->download_count >= $maxDownloads) {
                    throw DownloadException::downloadLimitReached($maxDownloads);
                }
            }
        } else {
            // No direct purchase entitlement. Check dynamic membership access
            if (!$isMembershipEligible) {
                throw DownloadException::entitlementNotFound();
            }

            if (!$hasActiveMembership) {
                throw DownloadException::membershipExpired();
            }

            $isMembershipAccess = true;
        }

        // Resolve and harden file path
        $absolutePath = $this->resolveAndValidateFilePath((string)$details->file_path);

        $downloadFileName = !empty($details->file_name)
            ? $this->storageService->sanitizeFileName((string)$details->file_name)
            : basename($absolutePath);

        $mimeType = !empty($details->mime_type) ? (string)$details->mime_type : 'application/octet-stream';
        $fileSize = !empty($details->file_size) ? (int)$details->file_size : (int)@filesize($absolutePath);

        return [
            'download'           => $download,
            'product'            => $product,
            'details'            => $details,
            'entitlement'        => $entitlement,
            'file_path'          => $absolutePath,
            'file_name'          => $downloadFileName,
            'mime_type'          => $mimeType,
            'file_size'          => $fileSize,
            'is_membership'      => $isMembershipAccess,
        ];
    }

    /**
     * Atomically increment the download count and update audit fields.
     */
    public function recordDownload(int $downloadId, string $ip, string $userAgent, bool $isMembership = false): bool
    {
        if ($isMembership) {
            return $this->downloadRepo->incrementUnlimitedDownloadCount($downloadId, $ip, $userAgent);
        }

        return $this->downloadRepo->incrementDownloadCount(
            $downloadId,
            $ip,
            $userAgent,
            self::MAX_PURCHASE_DOWNLOADS
        );
    }

    /**
     * Strictly validate and resolve stored file path, rejecting path traversal,
     * absolute paths, PHP wrappers, remote URLs, and non-existent files.
     */
    public function resolveAndValidateFilePath(string $storedPath): string
    {
        $clean = trim($storedPath);

        // Reject null bytes, path traversal sequences, stream wrappers, and protocols
        if (str_contains($clean, "\0")) {
            throw DownloadException::pathTraversalDetected();
        }
        if (str_contains($clean, '..') || str_contains($clean, '://')) {
            throw DownloadException::pathTraversalDetected();
        }

        // Reject absolute paths
        if (str_starts_with($clean, '/') || str_starts_with($clean, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $clean)) {
            throw DownloadException::pathTraversalDetected();
        }

        $storageDir = rtrim(str_replace('\\', '/', $this->storageService->getStorageDir()), '/');

        // Normalize relative path
        if (str_starts_with($clean, 'storage/plugins/favorite-digital/files/')) {
            $subPath = substr($clean, strlen('storage/plugins/favorite-digital/files/'));
            $absolutePath = $storageDir . '/' . ltrim($subPath, '/\\');
        } else {
            $absolutePath = $storageDir . '/' . ltrim($clean, '/\\');
        }

        // Normalize path
        $normalized = str_replace('\\', '/', $absolutePath);

        // Security check: Must reside within storage directory
        if (!str_starts_with($normalized, $storageDir)) {
            throw DownloadException::pathTraversalDetected();
        }

        if (!file_exists($normalized) || !is_readable($normalized) || is_dir($normalized)) {
            throw DownloadException::fileNotFound("File unavailable.");
        }

        return $normalized;
    }

    /**
     * Serve the file securely with defensive HTTP headers and chunked streaming.
     */
    public function streamFile(string $absolutePath, string $fileName, string $mimeType, int $fileSize): void
    {
        if (headers_sent()) {
            return;
        }

        // In test runner, do not perform buffer clearing or chunked exit
        if (defined('PHPUNIT_RUNNING')) {
            return;
        }

        // Clean any output buffers to prevent corruption
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Defensive headers
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fileName) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: private, no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        if ($fileSize > 0) {
            header('Content-Length: ' . $fileSize);
        }

        // Chunked streaming for memory efficiency
        $chunkSize = 1024 * 1024; // 1MB chunks
        $handle = fopen($absolutePath, 'rb');
        if ($handle === false) {
            return;
        }

        while (!feof($handle)) {
            echo fread($handle, $chunkSize);
            flush();
        }
        fclose($handle);
        exit;
    }

    /**
     * Complete download flow: Authorize -> Atomically record -> Stream file.
     */
    public function serveDownload(string $token, int $authenticatedUserId, string $ip, string $userAgent): void
    {
        $auth = $this->authorizeDownload($token, $authenticatedUserId);

        $downloadId = (int)$auth['download']->id;
        $isMembership = (bool)$auth['is_membership'];

        $recorded = $this->recordDownload($downloadId, $ip, $userAgent, $isMembership);
        if (!$recorded) {
            // Race condition triggered limit reached
            throw DownloadException::downloadLimitReached(self::MAX_PURCHASE_DOWNLOADS);
        }

        $this->streamFile(
            $auth['file_path'],
            $auth['file_name'],
            $auth['mime_type'],
            $auth['file_size']
        );
    }
}
