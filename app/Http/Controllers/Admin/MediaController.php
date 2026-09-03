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

    public function index(Request $request): Response
    {
        $category = trim((string)$request->get('category', 'all'));
        $search   = trim((string)$request->get('s', ''));

        $allItems = Media::all();
        $mediaItems = [];

        foreach ($allItems as $item) {
            if ($category !== 'all' && $item->getTypeCategory() !== $category) {
                continue;
            }
            if ($search !== '' && stripos($item->filename ?? '', $search) === false && stripos($item->title ?? '', $search) === false) {
                continue;
            }
            $mediaItems[] = $item;
        }

        $currentUser = isset($_SESSION['auth_user_id']) ? User::find((int)$_SESSION['auth_user_id']) : null;
        $capabilities = $this->capabilityService->getUserCapabilities($currentUser);

        $viewData = [
            'pageTitle'    => 'Media Library',
            'activeMenu'   => 'media',
            'mediaItems'   => $mediaItems,
            'capabilities' => $capabilities,
            'currentCat'   => $category,
            'searchQuery'  => $search,
            'contentView'  => APP_ROOT . '/resources/views/admin/media/index.php',
        ];

        extract($viewData, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/resources/views/admin/layout.php';
        return Response::make((string)ob_get_clean(), 200);
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

        if (empty($_FILES['file'])) {
            $_SESSION['flash_error'] = 'No file was selected for upload.';
            return Response::redirect('/admin/media');
        }

        try {
            $service->upload($_FILES['file'], $userId, $user);
            $_SESSION['flash_success'] = 'File uploaded successfully to media library.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Upload failed: ' . $e->getMessage();
        }

        return Response::redirect('/admin/media');
    }

    /**
     * AJAX endpoint for async modal uploads with progress reporting.
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

        if (empty($_FILES['file'])) {
            return Response::json([
                'success' => false,
                'message' => 'No file was received in the request.',
            ], 400);
        }

        try {
            $media = $service->upload($_FILES['file'], $userId, $user);

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
            ], 413); // Payload Too Large / Invalid Argument
        } catch (\SecurityException $e) {
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
        $id = (int)$request->get('id', 0);
        $media = Media::find($id);
        if ($media) {
            $media->delete();
            $_SESSION['flash_success'] = 'Media file deleted.';
        }

        return Response::redirect('/admin/media');
    }
}
