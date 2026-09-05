<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Import;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Services\Import\Models\NormalizedMedia;
use FavoriteCMS\Services\Import\Security\SsrfGuard;
use Throwable;

class MediaMigrator
{
    protected Application $app;
    protected ?Database $db;
    protected string $uploadsBaseDir;
    protected string $uploadsBaseUrl;

    /**
     * Maximum allowed size for a single downloaded media file (10 MB).
     */
    protected int $maxFileSizeBytes = 10485760;

    /**
     * Allowed image MIME types and target file extensions.
     */
    protected array $allowedMimeTypes = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'image/svg+xml' => 'svg',
    ];

    public function __construct(?Application $app = null)
    {
        $this->app = $app ?? Application::getInstance();
        try {
            $this->db = $this->app->make(Database::class);
        } catch (Throwable) {
            $this->db = null;
        }
        $this->uploadsBaseDir = defined('APP_ROOT') ? APP_ROOT . '/public/uploads' : dirname(__DIR__, 4) . '/public/uploads';
        $this->uploadsBaseUrl = '/uploads';
    }

    /**
     * Extract all image URLs from an HTML content string.
     *
     * @return string[]
     */
    public function extractImageUrls(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $urls = [];
        if (preg_match_all('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                $trimmed = trim($url);
                if (filter_var($trimmed, FILTER_VALIDATE_URL) && str_starts_with(strtolower($trimmed), 'http')) {
                    $urls[] = $trimmed;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Safely download a remote image and return a NormalizedMedia object.
     * If download fails or URL is blocked by SSRF, the original URL is preserved and status is marked 'failed'.
     */
    public function downloadMedia(string $sourceUrl, ?int $uploaderId = null): NormalizedMedia
    {
        $media = new NormalizedMedia([
            'sourceUrl' => $sourceUrl,
            'status'    => 'pending',
        ]);

        // 1. SSRF Safety Check
        try {
            SsrfGuard::assertUrlSafe($sourceUrl);
        } catch (Throwable $e) {
            $media->status = 'failed';
            $media->failureReason = 'Security restriction: ' . $e->getMessage();
            return $media;
        }

        // 2. Stream download with size limit and timeout
        $tempFile = @tempnam(sys_get_temp_dir(), 'fcms_import_');
        if (!$tempFile) {
            $media->status = 'failed';
            $media->failureReason = 'Unable to create local temporary file.';
            return $media;
        }

        try {
            $context = stream_context_create([
                'http' => [
                    'method'          => 'GET',
                    'follow_location' => 1,
                    'max_redirects'   => 3,
                    'timeout'         => 8.0,
                    'user_agent'      => 'FavoriteCMS-Importer/1.0',
                    'header'          => "Accept: image/*\r\n",
                ],
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $srcHandle = @fopen($sourceUrl, 'rb', false, $context);
            if (!$srcHandle) {
                $media->status = 'failed';
                $media->failureReason = 'Failed to connect to remote media host or resource returned HTTP error.';
                @unlink($tempFile);
                return $media;
            }

            $destHandle = fopen($tempFile, 'wb');
            $bytesWritten = 0;

            while (!feof($srcHandle)) {
                $chunk = fread($srcHandle, 32768); // 32 KB chunk
                if ($chunk === false) {
                    break;
                }
                $bytesWritten += strlen($chunk);
                if ($bytesWritten > $this->maxFileSizeBytes) {
                    fclose($srcHandle);
                    fclose($destHandle);
                    @unlink($tempFile);
                    $media->status = 'failed';
                    $media->failureReason = "Remote file exceeds maximum allowed import size ({$this->maxFileSizeBytes} bytes).";
                    return $media;
                }
                fwrite($destHandle, $chunk);
            }

            fclose($srcHandle);
            fclose($destHandle);

            if ($bytesWritten === 0) {
                @unlink($tempFile);
                $media->status = 'failed';
                $media->failureReason = 'Remote media file was empty.';
                return $media;
            }

            // 3. MIME Verification
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tempFile);
            finfo_close($finfo);

            if (!is_string($mime) || !array_key_exists($mime, $this->allowedMimeTypes)) {
                @unlink($tempFile);
                $media->status = 'failed';
                $media->failureReason = "Disallowed or invalid image MIME type: '{$mime}'.";
                return $media;
            }

            $ext = $this->allowedMimeTypes[$mime];

            // 4. Determine storage path
            $subDir = 'imports/' . date('Y/m');
            $targetDir = $this->uploadsBaseDir . '/' . $subDir;
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }

            $originalFilename = basename((string)parse_url($sourceUrl, PHP_URL_PATH));
            $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalFilename, PATHINFO_FILENAME));
            if ($safeBase === '') {
                $safeBase = 'imported_image';
            }

            $storedFilename = sprintf('%s_%s.%s', substr($safeBase, 0, 40), bin2hex(random_bytes(6)), $ext);
            $destinationPath = $targetDir . '/' . $storedFilename;
            $destinationUrl = $this->uploadsBaseUrl . '/' . $subDir . '/' . $storedFilename;

            // Move temp file to destination
            if (!@rename($tempFile, $destinationPath)) {
                if (!@copy($tempFile, $destinationPath)) {
                    @unlink($tempFile);
                    $media->status = 'failed';
                    $media->failureReason = 'Could not write downloaded media to destination storage directory.';
                    return $media;
                }
                @unlink($tempFile);
            }

            // Image dimensions
            $width = null;
            $height = null;
            if ($mime !== 'image/svg+xml') {
                $dims = @getimagesize($destinationPath);
                if ($dims) {
                    $width = (int)$dims[0];
                    $height = (int)$dims[1];
                }
            }

            $mediaId = null;
            if ($this->db) {
                try {
                    $now = date('Y-m-d H:i:s');
                    $mediaId = $this->db->insert('media', [
                        'filename'        => $originalFilename ?: $storedFilename,
                        'stored_filename' => $storedFilename,
                        'path'            => $destinationPath,
                        'url'             => $destinationUrl,
                        'mime_type'       => $mime,
                        'size'            => $bytesWritten,
                        'width'           => $width,
                        'height'          => $height,
                        'alt_text'        => $safeBase,
                        'title'           => $safeBase,
                        'description'     => 'Imported from ' . $sourceUrl,
                        'uploader_id'     => $uploaderId,
                        'disk'            => 'local',
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]);
                } catch (Throwable) {
                    // Ignore DB insert failure if schema differs or test mock
                }
            }

            $media->filename = $storedFilename;
            $media->mimeType = $mime;
            $media->localPath = $destinationPath;
            $media->localUrl = $destinationUrl;
            $media->mediaId = $mediaId > 0 ? $mediaId : null;
            $media->status = 'downloaded';

            return $media;

        } catch (Throwable $e) {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            $media->status = 'failed';
            $media->failureReason = 'Exception during media download: ' . $e->getMessage();
            return $media;
        }
    }

    /**
     * Rewrite content replacing downloaded media URLs with local media URLs.
     *
     * @param string $content
     * @param array<string, string> $urlMap [ 'https://old-url.com/img.jpg' => '/uploads/imports/2026/09/img_123.jpg' ]
     * @return string
     */
    public function rewriteContentUrls(string $content, array $urlMap): string
    {
        if (empty($urlMap) || trim($content) === '') {
            return $content;
        }

        foreach ($urlMap as $remoteUrl => $localUrl) {
            if ($remoteUrl !== '' && $localUrl !== '') {
                $content = str_replace($remoteUrl, $localUrl, $content);
            }
        }

        return $content;
    }
}
