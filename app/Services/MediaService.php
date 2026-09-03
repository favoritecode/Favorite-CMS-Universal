<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Exceptions\SecurityException;
use FavoriteCMS\Models\Media;
use FavoriteCMS\Models\User;

class MediaService
{
    protected Application $app;
    protected string $uploadsBaseDir;
    protected string $uploadsBaseUrl;
    protected UploadCapabilityService $capabilityService;

    /**
     * Comprehensive MIME types to extensions mapping.
     */
    protected array $allowedMimeTypes = [
        // Images
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'image/svg+xml'   => 'svg',
        'image/x-icon'    => 'ico',
        'image/bmp'       => 'bmp',

        // Videos
        'video/mp4'        => 'mp4',
        'video/webm'       => 'webm',
        'video/x-matroska' => 'mkv',
        'video/quicktime'  => 'mov',
        'video/x-msvideo'  => 'avi',
        'video/ogg'        => 'ogv',

        // Audio
        'audio/mpeg'  => 'mp3',
        'audio/mp3'   => 'mp3',
        'audio/wav'   => 'wav',
        'audio/x-wav' => 'wav',
        'audio/ogg'   => 'ogg',
        'audio/mp4'   => 'm4a',
        'audio/x-m4a' => 'm4a',

        // Documents
        'application/pdf'    => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'text/plain'         => 'txt',
        'text/csv'           => 'csv',

        // Archives
        'application/zip'              => 'zip',
        'application/x-zip-compressed' => 'zip',
        'application/x-tar'            => 'tar',
        'application/gzip'             => 'gz',
    ];

    /**
     * Dangerous extensions that are strictly forbidden under all circumstances.
     */
    protected array $blockedExtensions = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'pht', 'phar',
        'pl', 'py', 'cgi', 'asp', 'aspx', 'jsp', 'sh', 'bash',
        'exe', 'bat', 'cmd', 'com', 'vbs', 'dll', 'so',
        'html', 'htm', 'xhtml', 'shtml', 'js'
    ];

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->uploadsBaseDir = defined('APP_ROOT') ? APP_ROOT . '/public/uploads' : dirname(__DIR__, 2) . '/public/uploads';
        $this->uploadsBaseUrl = '/uploads';
        $this->capabilityService = new UploadCapabilityService($app);
    }

    /**
     * Upload an incoming file with role-aware limit detection, streaming, and strict security validation.
     */
    public function upload(array $file, ?int $uploaderId = null, ?User $user = null): Media
    {
        $isUploaded = is_uploaded_file($file['tmp_name']) || (defined('PHPUNIT_RUNNING') && file_exists($file['tmp_name']));
        if (empty($file['tmp_name']) || !$isUploaded) {
            throw new \InvalidArgumentException("No valid upload file provided or file was not uploaded via HTTP POST.");
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = match ($file['error']) {
                UPLOAD_ERR_INI_SIZE   => "The uploaded file exceeds the upload_max_filesize directive in php.ini.",
                UPLOAD_ERR_FORM_SIZE  => "The uploaded file exceeds the MAX_FILE_SIZE directive specified in the HTML form.",
                UPLOAD_ERR_PARTIAL    => "The uploaded file was only partially uploaded.",
                UPLOAD_ERR_NO_FILE    => "No file was uploaded.",
                UPLOAD_ERR_NO_TMP_DIR => "Missing a temporary folder on the server.",
                UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk.",
                UPLOAD_ERR_EXTENSION  => "A PHP extension stopped the file upload.",
                default               => "Upload error code: {$file['error']}",
            };
            throw new \RuntimeException($errorMsg);
        }

        // Resolve user model if ID is passed
        if (!$user && $uploaderId) {
            $user = User::find($uploaderId);
        }

        // 1. Role-aware upload size validation
        $maxAllowedBytes = $this->capabilityService->getEffectiveUserLimit($user);
        $fileSize = (int)$file['size'];

        if ($fileSize > $maxAllowedBytes) {
            $formattedActual = UploadCapabilityService::formatBytes($fileSize);
            $formattedLimit  = UploadCapabilityService::formatBytes($maxAllowedBytes);
            throw new \InvalidArgumentException("Uploaded file ({$formattedActual}) exceeds your maximum allowed upload limit ({$formattedLimit}).");
        }

        // 2. Sanitize and validate original filename
        $originalName = basename(str_replace(chr(0), '', (string)$file['name']));
        if ($originalName === '') {
            $originalName = 'upload_file';
        }

        // 3. Prevent double-extension attacks (e.g. image.php.jpg or script.phtml.png)
        $nameParts = explode('.', strtolower($originalName));
        array_shift($nameParts); // remove base
        foreach ($nameParts as $extSegment) {
            if (in_array($extSegment, $this->blockedExtensions, true)) {
                throw new SecurityException("Dangerous file format detected: multi-extension containing '.{$extSegment}' is prohibited.");
            }
        }

        $inputExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (in_array($inputExtension, $this->blockedExtensions, true)) {
            throw new SecurityException("Executable files and scripts are strictly prohibited from upload.");
        }

        // 4. Verify MIME type using finfo on server
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!array_key_exists($detectedMime, $this->allowedMimeTypes)) {
            throw new \InvalidArgumentException("Disallowed file type: MIME '{$detectedMime}' is not permitted in the media library.");
        }

        $targetExtension = $this->allowedMimeTypes[$detectedMime];

        // 5. Sanitize base filename for disk storage
        $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        if ($safeBase === '') {
            $safeBase = 'media';
        }

        // Target subdirectory: YYYY/MM
        $subDir = date('Y/m');
        $targetDir = $this->uploadsBaseDir . '/' . $subDir;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        // 6. Generate unique, collision-resistant filename
        $storedFilename = sprintf('%s_%s.%s', $safeBase, bin2hex(random_bytes(6)), $targetExtension);
        $targetPath = $targetDir . '/' . $storedFilename;
        $targetUrl  = $this->uploadsBaseUrl . '/' . $subDir . '/' . $storedFilename;

        // Path safety check: ensure target path is within base uploads directory
        $realBase = realpath($this->uploadsBaseDir) ?: $this->uploadsBaseDir;
        if (str_starts_with(str_replace('\\', '/', $targetPath), str_replace('\\', '/', $realBase)) === false) {
            throw new \SecurityException("Target upload path traversal attempted.");
        }

        // 7. Low-memory streaming transfer to disk
        $moved = is_uploaded_file($file['tmp_name'])
            ? move_uploaded_file($file['tmp_name'], $targetPath)
            : copy($file['tmp_name'], $targetPath);
        if (!$moved) {
            throw new \RuntimeException("Failed to move uploaded file to destination path on disk.");
        }

        // 8. Determine image dimensions if applicable (without buffering large video files)
        $width = null;
        $height = null;
        if (str_starts_with($detectedMime, 'image/') && $detectedMime !== 'image/svg+xml') {
            $dims = @getimagesize($targetPath);
            if ($dims) {
                $width  = (int)$dims[0];
                $height = (int)$dims[1];
            }
        }

        // 9. Persist media record in database
        $db = Container::getInstance()->get(Database::class);
        $now = date('Y-m-d H:i:s');

        $mediaId = $db->insert('media', [
            'filename'        => $originalName,
            'stored_filename' => $storedFilename,
            'path'            => $targetPath,
            'url'             => $targetUrl,
            'mime_type'       => $detectedMime,
            'size'            => $fileSize,
            'width'           => $width,
            'height'          => $height,
            'alt_text'        => pathinfo($originalName, PATHINFO_FILENAME),
            'title'           => pathinfo($originalName, PATHINFO_FILENAME),
            'description'     => '',
            'uploader_id'     => $uploaderId,
            'disk'            => 'local',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return Media::find($mediaId);
    }
}
