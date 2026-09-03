<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Media;

class MediaService
{
    protected Application $app;
    protected string $uploadsBaseDir;
    protected string $uploadsBaseUrl;

    protected array $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'text/plain' => 'txt',
    ];

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->uploadsBaseDir = APP_ROOT . '/public/uploads';
        $this->uploadsBaseUrl = '/uploads';
    }

    /**
     * Upload an incoming file with MIME and extension validation.
     */
    public function upload(array $file, ?int $uploaderId = null): Media
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException("No valid upload file provided.");
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException("Upload error code: {$file['error']}");
        }

        // Validate size (max 20MB by default)
        $maxBytes = 20 * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            throw new \InvalidArgumentException("Uploaded file exceeds 20MB limit.");
        }

        // Verify MIME type using finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!array_key_exists($detectedMime, $this->allowedMimeTypes)) {
            throw new \InvalidArgumentException("Disallowed file type: {$detectedMime}");
        }

        $extension = $this->allowedMimeTypes[$detectedMime];
        $originalName = basename($file['name']);
        $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        if ($safeBase === '') {
            $safeBase = 'file';
        }

        // Target subdirectory: YYYY/MM
        $subDir = date('Y/m');
        $targetDir = $this->uploadsBaseDir . '/' . $subDir;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        // Generate unique filename
        $storedFilename = sprintf('%s_%s.%s', $safeBase, bin2hex(random_bytes(4)), $extension);
        $targetPath = $targetDir . '/' . $storedFilename;
        $targetUrl  = $this->uploadsBaseUrl . '/' . $subDir . '/' . $storedFilename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new \RuntimeException("Failed to move uploaded file to destination.");
        }

        // Determine image dimensions if applicable
        $width = null;
        $height = null;
        if (str_starts_with($detectedMime, 'image/') && $detectedMime !== 'image/svg+xml') {
            $dims = @getimagesize($targetPath);
            if ($dims) {
                $width  = $dims[0];
                $height = $dims[1];
            }
        }

        $db = Container::getInstance()->get(Database::class);
        $now = date('Y-m-d H:i:s');

        $mediaId = $db->insert('media', [
            'filename'        => $originalName,
            'stored_filename' => $storedFilename,
            'path'            => $targetPath,
            'url'             => $targetUrl,
            'mime_type'       => $detectedMime,
            'size'            => $file['size'],
            'width'           => $width,
            'height'          => $height,
            'alt_text'        => $originalName,
            'title'           => $originalName,
            'description'     => '',
            'uploader_id'     => $uploaderId,
            'disk'            => 'local',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return Media::find($mediaId);
    }
}

