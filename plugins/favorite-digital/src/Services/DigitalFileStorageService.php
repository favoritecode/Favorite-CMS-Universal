<?php

declare(strict_types=1);

namespace FavoriteCMS\Digital\Services;

use InvalidArgumentException;
use RuntimeException;

class DigitalFileStorageService
{
    protected string $storageDir;
    protected int $maxUploadBytes;

    protected array $allowedExtensions = [
        // Archives
        'zip', 'rar', '7z', 'tar', 'gz',
        // Documents & Books
        'pdf', 'epub', 'mobi', 'docx', 'xlsx', 'pptx', 'txt', 'csv', 'json',
        // Audio
        'mp3', 'wav', 'ogg', 'm4a', 'flac',
        // Video
        'mp4', 'webm', 'mov', 'mkv',
        // Graphics & Design
        'png', 'jpg', 'jpeg', 'webp', 'svg', 'bmp', 'psd', 'ai',
    ];

    protected array $blacklistedExtensions = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'pht', 'phar',
        'pl', 'py', 'cgi', 'asp', 'aspx', 'jsp', 'sh', 'bash',
        'exe', 'bat', 'cmd', 'com', 'vbs', 'dll', 'so',
        'html', 'htm', 'xhtml', 'shtml', 'js', 'jar', 'app',
    ];

    public function __construct(?string $storageDir = null, int $maxUploadBytes = 104857600) // Default 100MB
    {
        $this->storageDir = $storageDir ?? (APP_ROOT . '/storage/plugins/favorite-digital/files');
        $this->maxUploadBytes = $maxUploadBytes;
        $this->ensureStorageDirectory();
    }

    public function getStorageDir(): string
    {
        return $this->storageDir;
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

    protected function ensureStorageDirectory(): void
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }

        // Hardening: ensure .htaccess blocks any script execution
        $htaccess = $this->storageDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            $rules = "Deny from all\n<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n";
            @file_put_contents($htaccess, $rules);
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

