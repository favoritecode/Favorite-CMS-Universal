<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

use FavoriteCMS\Models\User;

/**
 * AvatarService — Server-side profile picture management and security enforcement.
 *
 * Handles avatar image uploads, format and MIME signature validation, safe storage,
 * external URL sanitization, and secure file removal.
 */
class AvatarService
{
    /**
     * Maximum allowed avatar file size in bytes (2 MB).
     */
    public const MAX_SIZE = 2 * 1024 * 1024;

    /**
     * Allowed MIME types and their canonical extensions.
     */
    public const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Allowed file extensions.
     */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Relative public upload directory for avatars.
     */
    public const UPLOAD_DIR = '/uploads/avatars/';

    /**
     * Validate an uploaded avatar image file.
     *
     * @param array $file The $_FILES entry (e.g. $_FILES['avatar'])
     * @return array{valid: bool, error: ?string, ext: ?string}
     */
    public function validateUploadedFile(array $file): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['valid' => false, 'error' => 'Invalid upload parameters.', 'ext' => null];
        }

        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['valid' => false, 'error' => 'No file was selected for upload.', 'ext' => null];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => 'Upload error occurred (code: ' . $file['error'] . ').', 'ext' => null];
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !file_exists($tmpPath)) {
            return ['valid' => false, 'error' => 'Uploaded temporary file not found.', 'ext' => null];
        }

        // 1. File size check
        $size = (int)($file['size'] ?? filesize($tmpPath));
        if ($size <= 0) {
            return ['valid' => false, 'error' => 'Uploaded file is empty.', 'ext' => null];
        }
        if ($size > self::MAX_SIZE) {
            return ['valid' => false, 'error' => 'File exceeds the maximum allowed size of 2 MB.', 'ext' => null];
        }

        // 2. Extension validation
        $origName = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return ['valid' => false, 'error' => 'Invalid file extension. Only JPG, PNG, and WebP are supported.', 'ext' => null];
        }

        // 3. MIME type inspection via fileinfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo ? finfo_file($finfo, $tmpPath) : mime_content_type($tmpPath);
        if ($finfo) {
            finfo_close($finfo);
        }

        $detectedMime = strtolower((string)$detectedMime);
        if (!array_key_exists($detectedMime, self::ALLOWED_MIMES)) {
            return ['valid' => false, 'error' => 'Invalid image type detected (' . $detectedMime . '). Only JPG, PNG, and WebP images are permitted.', 'ext' => null];
        }

        // 4. Binary signature and dimension validation via getimagesize
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false || empty($imageInfo[0]) || empty($imageInfo[1])) {
            return ['valid' => false, 'error' => 'File is not a valid or readable image.', 'ext' => null];
        }

        $imageType = $imageInfo[2] ?? 0;
        $validTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if (!in_array($imageType, $validTypes, true)) {
            return ['valid' => false, 'error' => 'Corrupt or unsupported image binary format.', 'ext' => null];
        }

        // Canonical extension
        $canonicalExt = self::ALLOWED_MIMES[$detectedMime];

        return ['valid' => true, 'error' => null, 'ext' => $canonicalExt];
    }

    /**
     * Store an uploaded avatar file safely in public/uploads/avatars/.
     *
     * @param array $file The $_FILES entry
     * @param int $userId The owning user ID
     * @param string|null $currentAvatar Optional current avatar path to remove
     * @return string The stored relative avatar URL (e.g. '/uploads/avatars/avatar_1_abc.png')
     * @throws \RuntimeException If validation fails or storage directory is not writable
     */
    public function storeUploadedAvatar(array $file, int $userId, ?string $currentAvatar = null): string
    {
        $validation = $this->validateUploadedFile($file);
        if (!$validation['valid']) {
            throw new \RuntimeException($validation['error'] ?? 'Avatar validation failed.');
        }

        $baseDir = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        $storageDir = $baseDir . '/public/uploads/avatars';

        if (!is_dir($storageDir)) {
            if (!mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
                throw new \RuntimeException('Failed to create avatar storage directory.');
            }
        }

        // Generate safe, unguessable filename
        $ext = $validation['ext'];
        $randomToken = bin2hex(random_bytes(8));
        $fileName = 'avatar_' . $userId . '_' . $randomToken . '.' . $ext;
        $targetPath = $storageDir . '/' . $fileName;

        $tmpPath = (string)$file['tmp_name'];

        // Support both real HTTP uploads and CLI/PHPUnit test file copies
        $saved = false;
        if (is_uploaded_file($tmpPath)) {
            $saved = move_uploaded_file($tmpPath, $targetPath);
        } else {
            $saved = copy($tmpPath, $targetPath);
        }

        if (!$saved || !file_exists($targetPath)) {
            throw new \RuntimeException('Failed to save avatar image to disk.');
        }

        @chmod($targetPath, 0644);

        // Delete old local avatar if one existed
        if ($currentAvatar !== null && $currentAvatar !== '') {
            $this->deleteLocalAvatarFile($currentAvatar);
        }

        return self::UPLOAD_DIR . $fileName;
    }

    /**
     * Validate an external image URL.
     *
     * @param string $url
     * @return array{valid: bool, error: ?string, url: ?string}
     */
    public function validateExternalUrl(string $url): array
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return ['valid' => false, 'error' => 'URL cannot be empty.', 'url' => null];
        }

        if (strlen($trimmed) > 500) {
            return ['valid' => false, 'error' => 'URL cannot exceed 500 characters.', 'url' => null];
        }

        // Reject control characters or newlines
        if (preg_match('/[\x00-\x1F\x7F]/', $trimmed)) {
            return ['valid' => false, 'error' => 'URL contains invalid control characters.', 'url' => null];
        }

        // Reject protocol-relative URLs
        if (str_starts_with($trimmed, '//')) {
            return ['valid' => false, 'error' => 'Protocol-relative URLs are not permitted.', 'url' => null];
        }

        // Validate basic URL syntax
        if (!filter_var($trimmed, FILTER_VALIDATE_URL)) {
            return ['valid' => false, 'error' => 'Please enter a valid, well-formed URL.', 'url' => null];
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);
        if ($scheme === false || $scheme === null) {
            return ['valid' => false, 'error' => 'URL must specify a scheme.', 'url' => null];
        }

        $scheme = strtolower($scheme);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['valid' => false, 'error' => 'Only HTTP and HTTPS URLs are allowed. JavaScript, data, or file schemes are blocked.', 'url' => null];
        }

        return ['valid' => true, 'error' => null, 'url' => $trimmed];
    }

    /**
     * Safely delete a local avatar file, strictly preventing path traversal.
     *
     * @param string|null $avatarPath Relative path e.g. '/uploads/avatars/avatar_1_abc.png'
     * @return bool True if file existed and was deleted, false otherwise
     */
    public function deleteLocalAvatarFile(?string $avatarPath): bool
    {
        if ($avatarPath === null || $avatarPath === '') {
            return false;
        }

        $trimmed = trim($avatarPath);
        if (!str_starts_with($trimmed, self::UPLOAD_DIR) && !str_starts_with($trimmed, 'uploads/avatars/')) {
            return false;
        }

        $baseDir = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        $storageDir = realpath($baseDir . '/public/uploads/avatars');
        if (!$storageDir) {
            return false;
        }

        $fileName = basename($trimmed);
        $fullPath = $storageDir . DIRECTORY_SEPARATOR . $fileName;

        if (file_exists($fullPath) && is_file($fullPath)) {
            $realTarget = realpath($fullPath);
            if ($realTarget && str_starts_with($realTarget, $storageDir)) {
                return @unlink($realTarget);
            }
        }

        return false;
    }

    /**
     * Remove the avatar for a given user.
     *
     * @param User $user
     * @return bool
     */
    public function removeAvatar(User $user): bool
    {
        $current = $user->avatar ?? null;
        if (!empty($current)) {
            $this->deleteLocalAvatarFile((string)$current);
            $user->update(['avatar' => null, 'updated_at' => date('Y-m-d H:i:s')]);
            return true;
        }
        return false;
    }
}
