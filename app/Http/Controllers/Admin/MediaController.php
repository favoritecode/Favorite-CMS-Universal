<?php

declare(strict_types=1);

namespace FavoriteCMS\Http\Controllers\Admin;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Core\Response;
use FavoriteCMS\Models\Media;
use FavoriteCMS\Services\MediaService;

class MediaController
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function index(Request $request): Response
    {
        $mediaItems = Media::all();

        $viewData = [
            'pageTitle'   => 'Media Library',
            'activeMenu'  => 'media',
            'mediaItems'  => $mediaItems,
            'contentView' => APP_ROOT . '/resources/views/admin/media/index.php',
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

        if (empty($_FILES['file'])) {
            $_SESSION['flash_error'] = 'No file was selected for upload.';
            return Response::redirect('/admin/media');
        }

        try {
            $service->upload($_FILES['file'], $userId);
            $_SESSION['flash_success'] = 'File uploaded successfully.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Upload failed: ' . $e->getMessage();
        }

        return Response::redirect('/admin/media');
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

