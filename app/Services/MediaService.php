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
    /** Accept only passive SVG graphics; reject active content instead of attempting to repair it. */
    public function validateSvg(string $path): void
    {
        $unsafe = 'SVG contains unsupported or active content. Please upload a plain SVG or a raster image.';
        if (!class_exists(\DOMDocument::class) || filesize($path) > 2 * 1024 * 1024) {
            throw new SecurityException($unsafe);
        }
        $xml = (string)file_get_contents($path);
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new SecurityException($unsafe);
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            if (!$doc->loadXML($xml, LIBXML_NONET) || $doc->documentElement?->localName !== 'svg') {
                throw new SecurityException($unsafe);
            }
            $elements = ['svg', 'g', 'defs', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'title', 'desc', 'text', 'tspan', 'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask'];
            $attributes = ['id', 'version', 'viewBox', 'width', 'height', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'transform', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity', 'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform', 'spreadMethod', 'fx', 'fy', 'clip-path', 'clip-rule', 'mask', 'preserveAspectRatio', 'font-size', 'font-family', 'font-weight', 'text-anchor', 'dx', 'dy'];
            $xpath = new \DOMXPath($doc);
            if ($xpath->query('//processing-instruction()')->length > 0) {
                throw new SecurityException($unsafe);
            }
            foreach ($doc->getElementsByTagName('*') as $element) {
                if ($element->namespaceURI !== 'http://www.w3.org/2000/svg' || !in_array($element->localName, $elements, true)) {
                    throw new SecurityException($unsafe);
                }
                foreach ($element->attributes as $attribute) {
                    if (!in_array($attribute->name, $attributes, true) || $attribute->namespaceURI
                        || preg_match('/(?:javascript|data|https?):|[<>]/i', $attribute->value)
                        || (stripos($attribute->value, 'url') !== false && !preg_match('/^url\(#[A-Za-z][A-Za-z0-9_-]*\)$/D', $attribute->value))) {
                        throw new SecurityException($unsafe);
                    }
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

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

        if ($user && !$user->canUploadMedia()) {
            throw new \SecurityException("Your account is suspended and cannot upload media files.");
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
        if ($detectedMime === 'image/svg+xml') {
            $this->validateSvg($file['tmp_name']);
        }

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

    /**
     * Allowed raster image MIME types for URL imports.
     */
    protected array $allowedUrlImportMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * Validate whether an IP address is a public, non-reserved, non-loopback, non-metadata IP.
     */
    public static function isPublicIp(string $ip): bool
    {
        // 1. Basic IP validation
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // 2. Standard PHP filter flag check (rejects standard private and reserved ranges)
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // 3. Handle IPv4-mapped IPv6 (e.g. ::ffff:192.168.1.1)
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $extracted = substr($ip, 7);
            if (filter_var($extracted, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return self::isPublicIp($extracted);
            }
        }

        // 4. Comprehensive IPv4 explicit CIDR range checks
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            if ($ipLong === false) {
                return false;
            }

            $forbiddenCidrs = [
                '0.0.0.0/8',          // Current network (RFC 1122)
                '10.0.0.0/8',         // Private RFC 1918
                '100.64.0.0/10',      // Carrier-grade NAT (RFC 6598)
                '127.0.0.0/8',        // Loopback (RFC 1122)
                '169.254.0.0/16',     // Link-local / Cloud metadata (RFC 3927)
                '172.16.0.0/12',      // Private RFC 1918
                '192.0.0.0/24',       // IETF Protocol Assignments (RFC 6890)
                '192.0.2.0/24',       // TEST-NET-1 (RFC 5737)
                '192.88.99.0/24',     // 6to4 Relay Anycast (RFC 7526)
                '192.168.0.0/16',     // Private RFC 1918
                '198.18.0.0/15',      // Benchmarking (RFC 2544)
                '198.51.100.0/24',    // TEST-NET-2 (RFC 5737)
                '203.0.113.0/24',     // TEST-NET-3 (RFC 5737)
                '224.0.0.0/4',        // Multicast (RFC 5771)
                '240.0.0.0/4',        // Reserved for future use (RFC 1112)
                '255.255.255.255/32', // Limited broadcast
            ];

            foreach ($forbiddenCidrs as $cidr) {
                [$subnet, $mask] = explode('/', $cidr);
                $subnetLong = ip2long($subnet);
                $maskInt = (int)$mask;
                $maskLong = $maskInt === 0 ? 0 : (~0 << (32 - $maskInt));
                if (($ipLong & $maskLong) === ($subnetLong & $maskLong)) {
                    return false;
                }
            }

            // Explicit check for cloud metadata endpoint
            if ($ip === '169.254.169.254') {
                return false;
            }

            return true;
        }

        // 5. Comprehensive IPv6 checks
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $hex = bin2hex((string)inet_pton($ip));
            // ::1 loopback
            if ($hex === str_repeat('0', 31) . '1') {
                return false;
            }
            // :: unspecified
            if ($hex === str_repeat('0', 32)) {
                return false;
            }
            // fc00::/7 (Unique Local Address) starts with fc or fd
            if (preg_match('/^[fF][c-dC-D]/', $ip) || str_starts_with($hex, 'fc') || str_starts_with($hex, 'fd')) {
                return false;
            }
            // fe80::/10 (Link-Local)
            if (preg_match('/^[fF][eE][8-9a-bA-B]/', $ip) || preg_match('/^fe[89ab]/i', $hex)) {
                return false;
            }
            // ff00::/8 (Multicast)
            if (str_starts_with(strtolower($ip), 'ff') || str_starts_with($hex, 'ff')) {
                return false;
            }
            // 2001:db8::/32 (Documentation)
            if (str_starts_with($hex, '20010db8')) {
                return false;
            }
            return true;
        }

        return false;
    }

    /**
     * Validate URL scheme, host, and all resolved DNS IP addresses against SSRF.
     */
    public static function validateUrlForSsrf(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new SecurityException("No URL provided for import.");
        }

        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            throw new SecurityException("Invalid URL format: scheme and host are required.");
        }

        $scheme = strtolower((string)$parsed['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new SecurityException("Invalid URL scheme: only HTTP and HTTPS are permitted.");
        }

        $host = strtolower((string)$parsed['host']);
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]' || $host === '0.0.0.0') {
            throw new SecurityException("Requests to localhost or loopback are strictly prohibited.");
        }

        // Port restrictions: permit only standard web ports
        $port = isset($parsed['port']) ? (int)$parsed['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443, 8080, 8443], true)) {
            throw new SecurityException("Access to port {$port} is prohibited for URL imports.");
        }

        // Resolve DNS and validate every IP returned
        $resolvedIps = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $resolvedIps[] = $host;
        } else {
            $dnsRecords = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (!empty($dnsRecords)) {
                foreach ($dnsRecords as $rec) {
                    if (!empty($rec['ip'])) {
                        $resolvedIps[] = $rec['ip'];
                    }
                    if (!empty($rec['ipv6'])) {
                        $resolvedIps[] = $rec['ipv6'];
                    }
                }
            }
            if (empty($resolvedIps)) {
                $hostIps = @gethostbynamel($host);
                if (!empty($hostIps)) {
                    $resolvedIps = $hostIps;
                }
            }
        }

        if (empty($resolvedIps)) {
            throw new SecurityException("Could not resolve host '{$host}'.");
        }

        foreach ($resolvedIps as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new SecurityException("URL resolves to a restricted or private IP address ({$ip}).");
            }
        }

        return $url;
    }

    /**
     * Import a remote image from URL with SSRF protection, strict timeout, size limits,
     * MIME validation, and integration into the Media Library.
     */
    public function importFromUrl(string $url, ?int $uploaderId = null, ?User $user = null): Media
    {
        if (!$user && $uploaderId) {
            $user = User::find($uploaderId);
        }

        if ($user && !$user->canUploadMedia()) {
            throw new SecurityException("Your account is suspended and cannot upload or import media files.");
        }

        // 1. Initial SSRF validation of target URL
        $currentUrl = self::validateUrlForSsrf($url);

        // 2. Determine upload size limits
        $maxAllowedBytes = min($this->capabilityService->getEffectiveUserLimit($user), 10 * 1024 * 1024); // Cap at 10MB max for remote fetches

        $tempDir = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/temp';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }
        $tempFile = tempnam($tempDir, 'fc_import_');
        if ($tempFile === false) {
            throw new \RuntimeException("Failed to create temporary file for URL import.");
        }

        try {
            $maxRedirects = 3;
            $redirectCount = 0;
            $downloadSuccess = false;

            while ($redirectCount <= $maxRedirects) {
                $fp = fopen($tempFile, 'w+b');
                if (!$fp) {
                    throw new \RuntimeException("Cannot open temporary file for streaming download.");
                }

                $downloadedBytes = 0;
                $ch = curl_init($currentUrl);
                curl_setopt_array($ch, [
                    CURLOPT_FILE            => $fp,
                    CURLOPT_HEADER          => false,
                    CURLOPT_FOLLOWLOCATION  => false, // We manually inspect each redirect to prevent SSRF bypass
                    CURLOPT_CONNECTTIMEOUT  => 5,
                    CURLOPT_TIMEOUT         => 10,
                    CURLOPT_USERAGENT       => 'FavoriteCMS-MediaImporter/1.0',
                    CURLOPT_SSL_VERIFYPEER  => true,
                    CURLOPT_SSL_VERIFYHOST  => 2,
                    CURLOPT_NOPROGRESS      => false,
                    CURLOPT_PROGRESSFUNCTION => function ($resource, $downloadSize, $downloaded) use ($maxAllowedBytes, &$downloadedBytes) {
                        $downloadedBytes = $downloaded;
                        if ($downloaded > $maxAllowedBytes) {
                            return 1; // Non-zero aborts transfer immediately
                        }
                        return 0;
                    },
                ]);

                $execResult = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $redirectUrl = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                $curlError = curl_error($ch);
                curl_close($ch);
                fclose($fp);

                if ($downloadedBytes > $maxAllowedBytes) {
                    $formattedLimit = UploadCapabilityService::formatBytes($maxAllowedBytes);
                    throw new \InvalidArgumentException("Remote image exceeds the maximum allowed file size ({$formattedLimit}).");
                }

                // Handle redirects with strict re-validation
                if (in_array($httpCode, [301, 302, 303, 307, 308], true)) {
                    if (empty($redirectUrl)) {
                        throw new SecurityException("Redirect location header missing.");
                    }
                    // Handle relative redirect URL
                    if (str_starts_with($redirectUrl, '/')) {
                        $parsedOriginal = parse_url($currentUrl);
                        $redirectUrl = ($parsedOriginal['scheme'] ?? 'https') . '://' . ($parsedOriginal['host'] ?? '') . $redirectUrl;
                    }
                    // Re-validate the redirect destination through full SSRF check
                    $currentUrl = self::validateUrlForSsrf($redirectUrl);
                    $redirectCount++;
                    continue;
                }

                if (!$execResult || $httpCode !== 200) {
                    throw new \RuntimeException("Failed to fetch image from URL (HTTP {$httpCode}): " . ($curlError ?: 'Request failed.'));
                }

                $downloadSuccess = true;
                break;
            }

            if (!$downloadSuccess) {
                throw new SecurityException("Too many redirects encountered while importing image.");
            }

            $fileSize = (int)filesize($tempFile);
            if ($fileSize <= 0) {
                throw new \InvalidArgumentException("Imported image file is empty.");
            }

            // 3. MIME validation using finfo on local disk content
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = (string)finfo_file($finfo, $tempFile);
            finfo_close($finfo);

            if (!array_key_exists($detectedMime, $this->allowedUrlImportMimeTypes)) {
                throw new \InvalidArgumentException("Disallowed file type: MIME '{$detectedMime}' is not permitted. Only JPG, PNG, GIF, and WebP images are allowed.");
            }

            // 4. Verify raster image dimensions with getimagesize
            $dims = @getimagesize($tempFile);
            if ($dims === false || empty($dims[0]) || empty($dims[1])) {
                throw new \InvalidArgumentException("The downloaded file is corrupt or is not a valid raster image.");
            }
            $width = (int)$dims[0];
            $height = (int)$dims[1];

            $targetExtension = $this->allowedUrlImportMimeTypes[$detectedMime];

            // 5. Sanitize original filename from URL
            $urlPath = (string)parse_url($currentUrl, PHP_URL_PATH);
            $baseName = pathinfo($urlPath, PATHINFO_FILENAME);
            $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', $baseName);
            if (empty($safeBase)) {
                $safeBase = 'imported_image';
            }
            $originalName = $safeBase . '.' . $targetExtension;

            // 6. Target storage directory
            $subDir = date('Y/m');
            $targetDir = $this->uploadsBaseDir . '/' . $subDir;
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }

            $storedFilename = sprintf('%s_%s.%s', $safeBase, bin2hex(random_bytes(6)), $targetExtension);
            $targetPath = $targetDir . '/' . $storedFilename;
            $targetUrl  = $this->uploadsBaseUrl . '/' . $subDir . '/' . $storedFilename;

            // Path safety check
            $realBase = realpath($this->uploadsBaseDir) ?: $this->uploadsBaseDir;
            if (str_starts_with(str_replace('\\', '/', $targetPath), str_replace('\\', '/', $realBase)) === false) {
                throw new SecurityException("Target upload path traversal attempted.");
            }

            if (!copy($tempFile, $targetPath)) {
                throw new \RuntimeException("Failed to save imported image to uploads directory.");
            }

            // 7. Persist media record in database
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
                'alt_text'        => $safeBase,
                'title'           => $safeBase,
                'description'     => 'Imported from ' . $url,
                'uploader_id'     => $uploaderId,
                'disk'            => 'local',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

            return Media::find($mediaId);
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }
}
