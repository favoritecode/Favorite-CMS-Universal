<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Services;

use InvalidArgumentException;
use RuntimeException;

class DigitalFileStorageService
{
    protected string $storageDir;
    protected string $imagesDir;
    protected string $proofsDir;
    protected int $maxUploadBytes;

    protected array $allowedExtensions = [
        // Archives
        'zip', 'rar', '7z', 'tar', 'gz',
        // Documents & Books
        'pdf', 'epub', 'mobi', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'txt', 'csv', 'json',
        // Audio
        'mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac',
        // Video
        'mp4', 'webm', 'mov', 'mkv', 'avi',
        // Graphics & Design
        'png', 'jpg', 'jpeg', 'webp', 'svg', 'bmp', 'psd', 'ai', 'gif',
    ];

    protected array $allowedImageExtensions = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg',
    ];

    protected array $blacklistedExtensions = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phar',
        'pl', 'py', 'cgi', 'asp', 'aspx', 'jsp', 'sh', 'bash',
        'exe', 'bat', 'cmd', 'com', 'vbs', 'vbe', 'wsf', 'wsh', 'scr', 'cpl', 'dll', 'so', 'dylib', 'bin',
        'html', 'htm', 'xhtml', 'shtml', 'js', 'jar', 'app', 'msi',
    ];

    public function __construct(
        ?string $storageDir = null,
        int $maxUploadBytes = 104857600, // Default 100MB
        ?string $imagesDir = null,
        ?string $proofsDir = null
    ) {
        $baseStorage = defined('APP_ROOT') ? APP_ROOT . '/storage/plugins/favorite-digital' : sys_get_temp_dir() . '/favorite-digital';
        $this->storageDir = $storageDir ?? ($baseStorage . '/files');
        $this->imagesDir = $imagesDir ?? ($baseStorage . '/images');
        $this->proofsDir = $proofsDir ?? ($baseStorage . '/proofs');
        $this->maxUploadBytes = $maxUploadBytes;
        $this->ensureStorageDirectories();
    }

    public function getStorageDir(): string
    {
        return $this->storageDir;
    }

    public function getImagesDir(): string
    {
        return $this->imagesDir;
    }

    public function getProofsDir(): string
    {
        return $this->proofsDir;
    }

    /**
     * Store an uploaded digital file after strict security validation.
     *
     * @param array $file $_FILES['resource_file'] item
     * @return array [file_path, file_name, file_hash, file_size, mime_type]
     */
    public function storeUpload(array $file): array
    {
        if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->getUploadErrorMessage((int)$file['error']));
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            // For testing environments where is_uploaded_file() returns false for mock files:
            if (!defined('PHPUNIT_RUNNING') || !file_exists($tmpPath)) {
                throw new InvalidArgumentException('Invalid uploaded file or temporary file missing.');
            }
        }

        $rawName = (string)($file['name'] ?? '');
        $originalName = $this->sanitizeFileName($rawName);
        $size = (int)($file['size'] ?? 0);

        // 1. File size check
        if ($size <= 0) {
            $size = (int)@filesize($tmpPath);
        }
        if ($size <= 0) {
            throw new InvalidArgumentException('Uploaded file is empty (0 bytes).');
        }
        if ($size > $this->maxUploadBytes) {
            $maxMb = round($this->maxUploadBytes / 1048576, 1);
            throw new InvalidArgumentException("File size exceeds maximum allowed limit of {$maxMb}MB.");
        }

        // 2. Validate extensions & security
        $this->validateExtension($originalName);

        // 3. Binary MIME detection via finfo
        $mimeType = $this->detectMimeType($tmpPath);

        // 4. SHA-256 Hash
        $hash = hash_file('sha256', $tmpPath);
        if ($hash === false) {
            throw new RuntimeException('Failed to generate SHA-256 hash for digital file.');
        }

        // 5. Store file safely with hash-prefixed name
        $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        $targetFileName = $hash . ($ext !== '' ? '.' . $ext : '');
        $destination = $this->storageDir . '/' . $targetFileName;

        if (defined('PHPUNIT_RUNNING') && !is_uploaded_file($tmpPath)) {
            if (!copy($tmpPath, $destination)) {
                throw new RuntimeException('Failed to copy file into secure storage.');
            }
        } else {
            if (!move_uploaded_file($tmpPath, $destination)) {
                throw new RuntimeException('Failed to move uploaded file into secure storage.');
            }
        }

        $relativePath = 'storage/plugins/favorite-digital/files/' . $targetFileName;

        return [
            'file_path' => $relativePath,
            'file_name' => $originalName,
            'file_hash' => $hash,
            'file_size' => $size,
            'mime_type' => $mimeType,
        ];
    }

    /**
     * Strictly validate file extension, multi-extension bypass attempts, and blacklists.
     */
    public function validateExtension(string $filename): void
    {
        $clean = strtolower(trim($filename));
        $ext = (string)pathinfo($clean, PATHINFO_EXTENSION);

        if ($ext === '') {
            throw new InvalidArgumentException('File must have a valid file extension.');
        }

        // Strict blacklist check
        if (in_array($ext, $this->blacklistedExtensions, true)) {
            throw new InvalidArgumentException("File extension '{$ext}' is strictly forbidden for security.");
        }

        // Whitelist check
        if (!in_array($ext, $this->allowedExtensions, true)) {
            throw new InvalidArgumentException("File extension '{$ext}' is not an allowed digital product format.");
        }

        // Multi-extension inspection (e.g. exploit.php.zip)
        $parts = explode('.', $clean);
        if (count($parts) > 2) {
            foreach (array_slice($parts, 1, -1) as $intermediate) {
                if (in_array($intermediate, $this->blacklistedExtensions, true)) {
                    throw new InvalidArgumentException("Malicious multi-extension pattern detected (contained forbidden '{$intermediate}').");
                }
            }
        }
    }

    /**
     * Path traversal protection and filename sanitization.
     */
    public function sanitizeFileName(string $filename): string
    {
        $name = basename(str_replace(['\\', '/'], '/', trim($filename)));
        $name = preg_replace('/[^\w\.\-\s\(\)]+/u', '_', $name) ?? 'file';
        $name = trim($name, '. ');
        if ($name === '' || $name === '..') {
            throw new InvalidArgumentException('Invalid or dangerous filename provided.');
        }
        return $name;
    }

    /**
     * Store an uploaded cover/card image after strict image security validation.
     *
     * @param array $file $_FILES item
     * @return array [file_path, file_name, file_hash, file_size, mime_type]
     */
    public function storeImageUpload(array $file): array
    {
        if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->getUploadErrorMessage((int)$file['error']));
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            if (!defined('PHPUNIT_RUNNING') || !file_exists($tmpPath)) {
                throw new InvalidArgumentException('Invalid uploaded image or temporary file missing.');
            }
        }

        $rawName = (string)($file['name'] ?? '');
        $originalName = $this->sanitizeFileName($rawName);
        $size = (int)($file['size'] ?? 0);

        if ($size <= 0) {
            $size = (int)@filesize($tmpPath);
        }
        if ($size <= 0) {
            throw new InvalidArgumentException('Uploaded image is empty (0 bytes).');
        }
        $maxImageBytes = 10485760; // 10MB
        if ($size > $maxImageBytes) {
            throw new InvalidArgumentException('Image size exceeds maximum allowed limit of 10MB.');
        }

        // Validate image extension
        $this->validateImageExtension($originalName);

        // Binary MIME detection
        $mimeType = $this->detectMimeType($tmpPath);
        $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));

        if (!str_starts_with($mimeType, 'image/') && $ext !== 'svg') {
            throw new InvalidArgumentException("Invalid image MIME type '{$mimeType}'.");
        }

        // SVG security: reject embedded scripts
        if ($ext === 'svg') {
            $svgContent = (string)@file_get_contents($tmpPath);
            if (preg_match('/<script|javascript:|onload|onerror|data:/i', $svgContent)) {
                throw new InvalidArgumentException('SVG image contains dangerous script or event handlers.');
            }
        }

        $hash = hash_file('sha256', $tmpPath);
        if ($hash === false) {
            throw new RuntimeException('Failed to generate SHA-256 hash for image.');
        }

        $targetFileName = $hash . ($ext !== '' ? '.' . $ext : '');
        $destination = $this->imagesDir . '/' . $targetFileName;

        if (defined('PHPUNIT_RUNNING') && !is_uploaded_file($tmpPath)) {
            if (!copy($tmpPath, $destination)) {
                throw new RuntimeException('Failed to copy image into secure storage.');
            }
        } else {
            if (!move_uploaded_file($tmpPath, $destination)) {
                throw new RuntimeException('Failed to move uploaded image into secure storage.');
            }
        }

        return [
            'file_path' => 'storage/plugins/favorite-digital/images/' . $targetFileName,
            'file_name' => $originalName,
            'file_hash' => $hash,
            'file_size' => $size,
            'mime_type' => $mimeType,
        ];
    }

    /**
     * Store an uploaded payment proof screenshot.
     */
    public function storeProofUpload(array $file): array
    {
        if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->getUploadErrorMessage((int)$file['error']));
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            if (!defined('PHPUNIT_RUNNING') || !file_exists($tmpPath)) {
                throw new InvalidArgumentException('Invalid uploaded proof file or temporary file missing.');
            }
        }

        $rawName = (string)($file['name'] ?? '');
        $originalName = $this->sanitizeFileName($rawName);
        $size = (int)($file['size'] ?? 0);

        if ($size <= 0) {
            $size = (int)@filesize($tmpPath);
        }
        if ($size <= 0) {
            throw new InvalidArgumentException('Uploaded proof file is empty (0 bytes).');
        }
        $maxProofBytes = 10485760; // 10MB
        if ($size > $maxProofBytes) {
            throw new InvalidArgumentException('Proof file exceeds maximum limit of 10MB.');
        }

        $clean = strtolower(trim($originalName));
        $ext = (string)pathinfo($clean, PATHINFO_EXTENSION);
        $allowedProofExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        if (!in_array($ext, $allowedProofExts, true)) {
            throw new InvalidArgumentException("Proof file extension '{$ext}' is not allowed. Accepted: JPG, PNG, WEBP, PDF.");
        }

        // Multi-extension check
        $parts = explode('.', $clean);
        if (count($parts) > 2) {
            foreach (array_slice($parts, 1, -1) as $intermediate) {
                if (in_array($intermediate, $this->blacklistedExtensions, true)) {
                    throw new InvalidArgumentException("Forbidden intermediate extension '{$intermediate}' in proof filename.");
                }
            }
        }

        $mimeType = $this->detectMimeType($tmpPath);
        if (!str_starts_with($mimeType, 'image/') && $mimeType !== 'application/pdf') {
            throw new InvalidArgumentException("Invalid proof file MIME type '{$mimeType}'.");
        }

        $hash = hash_file('sha256', $tmpPath);
        if ($hash === false) {
            throw new RuntimeException('Failed to hash proof file.');
        }

        $targetFileName = $hash . ($ext !== '' ? '.' . $ext : '');
        $destination = $this->proofsDir . '/' . $targetFileName;

        if (defined('PHPUNIT_RUNNING') && !is_uploaded_file($tmpPath)) {
            if (!copy($tmpPath, $destination)) {
                throw new RuntimeException('Failed to copy proof file into storage.');
            }
        } else {
            if (!move_uploaded_file($tmpPath, $destination)) {
                throw new RuntimeException('Failed to move proof file into storage.');
            }
        }

        return [
            'file_path' => 'storage/plugins/favorite-digital/proofs/' . $targetFileName,
            'file_name' => $originalName,
            'file_hash' => $hash,
            'file_size' => $size,
            'mime_type' => $mimeType,
        ];
    }

    /**
     * Validate image extension against whitelist and blacklist.
     */
    public function validateImageExtension(string $filename): void
    {
        $clean = strtolower(trim($filename));
        $ext = (string)pathinfo($clean, PATHINFO_EXTENSION);

        if ($ext === '') {
            throw new InvalidArgumentException('Image file must have a valid extension.');
        }

        if (in_array($ext, $this->blacklistedExtensions, true)) {
            throw new InvalidArgumentException("File extension '{$ext}' is strictly forbidden.");
        }

        if (!in_array($ext, $this->allowedImageExtensions, true)) {
            throw new InvalidArgumentException("Extension '{$ext}' is not an allowed image format.");
        }

        $parts = explode('.', $clean);
        if (count($parts) > 2) {
            foreach (array_slice($parts, 1, -1) as $intermediate) {
                if (in_array($intermediate, $this->blacklistedExtensions, true)) {
                    throw new InvalidArgumentException("Dangerous multi-extension detected: '{$intermediate}'.");
                }
            }
        }
    }

    /**
     * Validate external URL strictly. Only http and https allowed.
     */
    public function validateSafeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException('URL cannot be empty.');
        }

        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            throw new InvalidArgumentException('Invalid URL structure provided.');
        }

        $scheme = strtolower((string)$parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException("Insecure or dangerous URL scheme '{$scheme}' is not allowed.");
        }

        $lower = strtolower($url);
        $forbiddenSchemes = ['javascript:', 'data:', 'vbscript:', 'file:', 'about:', 'blob:'];
        foreach ($forbiddenSchemes as $f) {
            if (str_contains($lower, $f)) {
                throw new InvalidArgumentException("URL contains forbidden protocol prefix '{$f}'.");
            }
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Malformed URL format.');
        }

        return $url;
    }

    protected function detectMimeType(string $path): string
    {
        try {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($path);
            if ($mime && is_string($mime)) {
                return $mime;
            }
        } catch (\Throwable) {
        }

        return 'application/octet-stream';
    }

    protected function ensureStorageDirectories(): void
    {
        foreach ([$this->storageDir, $this->imagesDir, $this->proofsDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $htaccess = $dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                $rules = "Deny from all\n<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n";
                @file_put_contents($htaccess, $rules);
            }
        }
    }

    protected function getUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE   => 'Uploaded file exceeds server upload_max_filesize limit.',
            UPLOAD_ERR_FORM_SIZE  => 'Uploaded file exceeds HTML form MAX_FILE_SIZE limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was selected for upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload directory on server.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
            default               => "Unknown file upload error (Code {$errorCode}).",
        };
    }
}

