<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Exceptions\SecurityException;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Media;
use FavoriteCMS\Models\User;
use FavoriteCMS\Services\MediaService;
use FavoriteCMS\Services\UploadCapabilityService;

class MediaController
{
    protected Application $app;
    protected UploadCapabilityService $capabilityService;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->capabilityService = new UploadCapabilityService($app);
    }

    /** Media cards shown per Media Library page. */
    protected const PER_PAGE = 12;

    /** Media returned per batch to editor media pickers. */
    public const PICKER_BATCH = 24;

    public function index(Request $request): Response
    {
        $category = trim((string)$request->get('category', 'all'));
        $search   = trim((string)$request->get('s', ''));

        // Filter and paginate in SQL instead of loading the whole media table
        $totalItems  = Media::countFiltered($category, $search);
        $totalPages  = max(1, (int)ceil($totalItems / self::PER_PAGE));
        $currentPage = min(max(1, (int)$request->get('p', 1)), $totalPages);
        $mediaItems  = Media::filtered($category, $search, self::PER_PAGE, ($currentPage - 1) * self::PER_PAGE);

        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;
        if ($currentUser && !$currentUser->canManageMedia()) {
            return Response::make('<h1>403 Access Denied</h1><p>You do not have permission to manage media.</p>', 403);
        }
        $capabilities = $this->capabilityService->getUserCapabilities($currentUser);

        $viewData = [
            'pageTitle'    => 'Media Library',
            'activeMenu'   => 'media',
            'mediaItems'   => $mediaItems,
            'capabilities' => $capabilities,
            'currentCat'   => $category,
            'searchQuery'  => $search,
            'currentPage'  => $currentPage,
            'totalPages'   => $totalPages,
            'totalItems'   => $totalItems,
            'contentView'  => APP_ROOT . '/resources/views/admin/media/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
    }

    /**
     * Normalize $_FILES into a flat array of standard file structures.
     *
     * @return array<int, array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    protected function normalizeFiles(?array $filesSource = null): array
    {
        $source = $filesSource ?? $_FILES;
        $normalized = [];

        // Check $source['files'] (e.g. from multiple file input name="files[]")
        if (!empty($source['files']) && is_array($source['files']['name'])) {
            $count = count($source['files']['name']);
            for ($i = 0; $i < $count; $i++) {
                if (!empty($source['files']['name'][$i])) {
                    $normalized[] = [
                        'name'     => (string)$source['files']['name'][$i],
                        'type'     => (string)($source['files']['type'][$i] ?? ''),
                        'tmp_name' => (string)($source['files']['tmp_name'][$i] ?? ''),
                        'error'    => (int)($source['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                        'size'     => (int)($source['files']['size'][$i] ?? 0),
                    ];
                }
            }
        }

        // Check $source['file']
        if (!empty($source['file'])) {
            if (is_array($source['file']['name'])) {
                $count = count($source['file']['name']);
                for ($i = 0; $i < $count; $i++) {
                    if (!empty($source['file']['name'][$i])) {
                        $normalized[] = [
                            'name'     => (string)$source['file']['name'][$i],
                            'type'     => (string)($source['file']['type'][$i] ?? ''),
                            'tmp_name' => (string)($source['file']['tmp_name'][$i] ?? ''),
                            'error'    => (int)($source['file']['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                            'size'     => (int)($source['file']['size'][$i] ?? 0),
                        ];
                    }
                }
            } elseif (!empty($source['file']['name'])) {
                $normalized[] = $source['file'];
            }
        }

        return $normalized;
    }

    public function upload(Request $request): Response
    {
        $service = new MediaService($this->app);
        $userId = (int)($_SESSION['auth_user_id'] ?? 1);
        $user = User::find($userId);

        if (!$user || !$user->canUploadMedia()) {
            $_SESSION['flash_error'] = 'Your account is suspended and cannot upload media files.';
            return Response::redirect('/admin/media');
        }

        $files = $this->normalizeFiles();
        if (empty($files)) {
            $_SESSION['flash_error'] = 'No file was selected for upload.';
            return Response::redirect('/admin/media');
        }

        $successCount = 0;
        $errors = [];

        foreach ($files as $file) {
            try {
                $service->upload($file, $userId, $user);
                $successCount++;
            } catch (\Throwable $e) {
                $errors[] = htmlspecialchars($file['name'] ?? 'file', ENT_QUOTES, 'UTF-8') . ': ' . $e->getMessage();
            }
        }

        if ($successCount > 0 && empty($errors)) {
            $_SESSION['flash_success'] = ($successCount === 1)
                ? 'File uploaded successfully to media library.'
                : "{$successCount} files uploaded successfully to media library.";
        } elseif ($successCount > 0 && !empty($errors)) {
            $_SESSION['flash_warning'] = "{$successCount} file(s) uploaded successfully, but some failed: " . implode('; ', $errors);
        } else {
            $_SESSION['flash_error'] = 'Upload failed: ' . implode('; ', $errors);
        }

        return Response::redirect('/admin/media');
    }

    /**
     * AJAX endpoint for async uploads with progress reporting and batch support.
     */
    public function uploadAjax(Request $request): Response
    {
        $service = new MediaService($this->app);
        $userId = (int)($_SESSION['auth_user_id'] ?? 1);
        $user = User::find($userId);

        if (!$user || !$user->canUploadMedia()) {
            return Response::json([
                'success' => false,
                'message' => 'Your account is suspended and cannot upload media files.',
            ], 403);
        }

        $files = $this->normalizeFiles();
        if (empty($files)) {
            return Response::json([
                'success' => false,
                'message' => 'No file was received in the request.',
            ], 400);
        }

        // Single file upload backward compatibility
        $isSingleUpload = (count($files) === 1 && !empty($_FILES['file']) && !is_array($_FILES['file']['name']));

        if ($isSingleUpload) {
            $file = $files[0];
            try {
                $media = $service->upload($file, $userId, $user);

                return Response::json([
                    'success' => true,
                    'media'   => [
                        'id'             => (int)$media->id,
                        'filename'       => $media->filename,
                        'url'            => $media->url,
                        'mime_type'      => $media->mime_type,
                        'size'           => (int)$media->size,
                        'formatted_size' => $media->getFormattedSize(),
                        'is_image'       => $media->isImage(),
                        'is_video'       => $media->isVideo(),
                        'is_audio'       => $media->isAudio(),
                        'is_document'    => $media->isDocument(),
                        'category'       => $media->getTypeCategory(),
                        'width'          => $media->width,
                        'height'         => $media->height,
                        'alt_text'       => $media->alt_text ?: $media->filename,
                        'title'          => $media->title ?: $media->filename,
                    ],
                ], 200);
            } catch (\InvalidArgumentException $e) {
                return Response::json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 413);
            } catch (SecurityException $e) {
                return Response::json([
                    'success' => false,
                    'message' => 'Security Error: ' . $e->getMessage(),
                ], 403);
            } catch (\Throwable $e) {
                return Response::json([
                    'success' => false,
                    'message' => 'Upload failed: ' . $e->getMessage(),
                ], 500);
            }
        }

        // Batch upload processing
        $results = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($files as $file) {
            $filename = (string)($file['name'] ?? 'unnamed_file');
            try {
                $media = $service->upload($file, $userId, $user);
                $successCount++;
                $results[] = [
                    'filename' => $filename,
                    'success'  => true,
                    'media'    => [
                        'id'             => (int)$media->id,
                        'filename'       => $media->filename,
                        'url'            => $media->url,
                        'mime_type'      => $media->mime_type,
                        'size'           => (int)$media->size,
                        'formatted_size' => $media->getFormattedSize(),
                        'is_image'       => $media->isImage(),
                        'category'       => $media->getTypeCategory(),
                    ],
                ];
            } catch (\Throwable $e) {
                $failCount++;
                $results[] = [
                    'filename' => $filename,
                    'success'  => false,
                    'message'  => $e->getMessage(),
                ];
            }
        }

        return Response::json([
            'success'        => $successCount > 0,
            'total_files'    => count($files),
            'total_uploaded' => $successCount,
            'total_failed'   => $failCount,
            'results'        => $results,
        ], 200);
    }

    /**
     * JSON endpoint to import an image from external URL with SSRF protection.
     */
    public function importUrl(Request $request): Response
    {
        $userId = (int)($_SESSION['auth_user_id'] ?? 1);
        $user = User::find($userId);

        if (!$user || !$user->canUploadMedia()) {
            return Response::json([
                'success' => false,
                'message' => 'Your account is suspended and cannot import media files.',
            ], 403);
        }

        // CSRF verification
        $token = (string)$request->post('_token', '');
        if (empty($_SESSION['_token']) || !hash_equals($_SESSION['_token'], $token)) {
            return Response::json([
                'success' => false,
                'message' => 'Security check failed: invalid CSRF token.',
            ], 403);
        }

        $url = trim((string)$request->post('url', ''));
        if ($url === '') {
            return Response::json([
                'success' => false,
                'message' => 'No image URL provided.',
            ], 400);
        }

        try {
            $service = new MediaService($this->app);
            $media = $service->importFromUrl($url, $userId, $user);

            return Response::json([
                'success' => true,
                'media'   => [
                    'id'             => (int)$media->id,
                    'filename'       => $media->filename,
                    'url'            => $media->url,
                    'mime_type'      => $media->mime_type,
                    'size'           => (int)$media->size,
                    'formatted_size' => $media->getFormattedSize(),
                    'is_image'       => $media->isImage(),
                    'is_video'       => $media->isVideo(),
                    'is_audio'       => $media->isAudio(),
                    'is_document'    => $media->isDocument(),
                    'category'       => $media->getTypeCategory(),
                    'width'          => $media->width,
                    'height'         => $media->height,
                    'alt_text'       => $media->alt_text ?: $media->filename,
                    'title'          => $media->title ?: $media->filename,
                ],
            ], 200);
        } catch (SecurityException $e) {
            return Response::json([
                'success' => false,
                'message' => 'Security Error: ' . $e->getMessage(),
            ], 403);
        } catch (\InvalidArgumentException $e) {
            return Response::json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            return Response::json([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * JSON endpoint returning a bounded batch of media for editor pickers ("Load more", filters, search).
     */
    public function library(Request $request): Response
    {
        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;
        if ($currentUser && !$currentUser->canUploadMedia()) {
            return Response::json(['success' => false, 'message' => 'Access denied.'], 403);
        }

        $category = trim((string)$request->get('category', 'all'));
        $search   = trim((string)$request->get('s', ''));
        $page     = max(1, (int)$request->get('page', 1));
        $perPage  = max(1, min(60, (int)$request->get('per_page', self::PICKER_BATCH)));

        $total = Media::countFiltered($category, $search);
        $items = Media::filtered($category, $search, $perPage, ($page - 1) * $perPage);

        return Response::json([
            'success'  => true,
            'items'    => array_map(static fn(Media $media): array => $media->toPickerArray(), $items),
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
            'has_more' => ($page * $perPage) < $total,
        ], 200);
    }

    /**
     * JSON endpoint to query upload capabilities and effective limits.
     */
    public function capabilities(Request $request): Response
    {
        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;
        $capabilities = $this->capabilityService->getUserCapabilities($currentUser);

        return Response::json([
            'success'      => true,
            'capabilities' => $capabilities,
        ], 200);
    }

    public function update(Request $request): Response
    {
        $userId = (int)($_SESSION['auth_user_id'] ?? 1);
        $user = User::find($userId);
        if (!$user || !$user->canManageMedia()) {
            $_SESSION['flash_error'] = 'You do not have permission to modify media.';
            return Response::redirect('/admin/media');
        }

        $id = (int)$request->post('id', 0);
        $media = Media::find($id);
        if ($media) {
            $media->update([
                'title'       => trim((string)$request->post('title', '')),
                'alt_text'    => trim((string)$request->post('alt_text', '')),
                'description' => trim((string)$request->post('description', '')),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
            $_SESSION['flash_success'] = 'Media metadata updated.';
        }

        return Response::redirect('/admin/media');
    }

    public function delete(Request $request): Response
    {
        $userId = (int)($_SESSION['auth_user_id'] ?? 1);
        $user = User::find($userId);
        if (!$user || !$user->canManageMedia()) {
            $_SESSION['flash_error'] = 'You do not have permission to delete media.';
            return Response::redirect('/admin/media');
        }

        $id = (int)$request->get('id', $request->post('id', 0));
        $media = Media::find($id);
        if ($media) {
            $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
            // Safely clear references before deletion
            $db->execute("UPDATE `posts` SET `featured_image_id` = NULL WHERE `featured_image_id` = ?", [$id]);
            $db->execute("UPDATE `pages` SET `featured_image_id` = NULL WHERE `featured_image_id` = ?", [$id]);

            $media->delete();
            $_SESSION['flash_success'] = 'Media file deleted.';
        }

        return Response::redirect('/admin/media');
    }

    /**
     * Bulk deletion endpoint for multiple media items with transaction and reference protection.
     */
    public function bulkDelete(Request $request): Response
    {
        $userId = (int)($_SESSION['auth_user_id'] ?? 1);
        $user = User::find($userId);
        $isJson = $request->isAjax() || str_contains((string)$request->header('Accept', ''), 'application/json') || str_contains((string)$request->header('Content-Type', ''), 'application/json');

        if (!$user || !$user->canManageMedia()) {
            if ($isJson) {
                return Response::json(['success' => false, 'message' => 'You do not have permission to delete media.'], 403);
            }
            $_SESSION['flash_error'] = 'You do not have permission to delete media.';
            return Response::redirect('/admin/media');
        }

        // CSRF verification
        $token = (string)$request->post('_token', '');
        if (empty($_SESSION['_token']) || !hash_equals($_SESSION['_token'], $token)) {
            if ($isJson) {
                return Response::json(['success' => false, 'message' => 'Security check failed: invalid CSRF token.'], 403);
            }
            $_SESSION['flash_error'] = 'Security check failed: invalid CSRF token.';
            return Response::redirect('/admin/media');
        }

        $rawIds = $request->post('ids', []);
        if (is_string($rawIds)) {
            $decoded = json_decode($rawIds, true);
            $rawIds = is_array($decoded) ? $decoded : explode(',', $rawIds);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$rawIds), static fn(int $id): bool => $id > 0)));

        if (empty($ids)) {
            if ($isJson) {
                return Response::json(['success' => false, 'message' => 'No media items selected for deletion.'], 400);
            }
            $_SESSION['flash_error'] = 'No media items selected for deletion.';
            return Response::redirect('/admin/media');
        }

        $db = \FavoriteCMS\Core\Container::getInstance()->get(\FavoriteCMS\Core\Database::class);
        $db->beginTransaction();

        $deletedCount = 0;
        try {
            foreach ($ids as $id) {
                $media = Media::find($id);
                if (!$media) {
                    continue;
                }

                // Safely clear references in posts and pages
                $db->execute("UPDATE `posts` SET `featured_image_id` = NULL WHERE `featured_image_id` = ?", [$id]);
                $db->execute("UPDATE `pages` SET `featured_image_id` = NULL WHERE `featured_image_id` = ?", [$id]);

                if ($media->delete()) {
                    $deletedCount++;
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            if ($isJson) {
                return Response::json(['success' => false, 'message' => 'Bulk deletion failed: ' . $e->getMessage()], 500);
            }
            $_SESSION['flash_error'] = 'Bulk deletion failed: ' . $e->getMessage();
            return Response::redirect('/admin/media');
        }

        if ($isJson) {
            return Response::json([
                'success' => true,
                'deleted' => $deletedCount,
                'message' => "Successfully deleted {$deletedCount} media file(s).",
            ], 200);
        }

        $_SESSION['flash_success'] = "Successfully deleted {$deletedCount} media file(s).";
        return Response::redirect('/admin/media');
    }
}
