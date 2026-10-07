<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Update;

use RuntimeException;
use Throwable;

class ReleaseDiscovery
{
    protected const GITHUB_REPO = 'favoritecode/Favorite-CMS-Universal';
    protected const CACHE_TTL_SECONDS = 3600; // 1 hour

    protected string $appRoot;
    protected string $cacheFile;
    protected UpdatePackageValidator $validator;

    public function __construct(?string $appRoot = null, ?UpdatePackageValidator $validator = null)
    {
        $this->appRoot = $appRoot ?: (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3));
        $this->cacheFile = $this->appRoot . '/storage/cache/update_release.json';
        $this->validator = $validator ?? new UpdatePackageValidator();
    }

    /**
     * Check for available Core updates.
     *
     * @param bool $forceRefresh Bypass cache if true.
     * @return array Discovery result with release details.
     */
    public function check(bool $forceRefresh = false): array
    {
        $currentVersion = defined('APP_VERSION') ? APP_VERSION : '1.0.0-beta';

        $result = [
            'checked_at'        => date('c'),
            'current_version'   => $currentVersion,
            'update_available'  => false,
            'repair_available'  => false,
            'latest_version'    => $currentVersion,
            'tag_name'          => 'v' . $currentVersion,
            'release_name'      => '',
            'release_notes'     => '',
            'published_at'      => '',
            'release_url'       => 'https://github.com/' . self::GITHUB_REPO . '/releases',
            'download_url'      => '',
            'package_name'      => '',
            'package_size'      => 0,
            'sha256'            => '',
            'checksum_url'      => '',
            'core_inventory'    => [],
            'missing_core_files'=> [],
            'cached'            => false,
            'error'             => null,
        ];

        // 1. Check local cache if not forcing refresh
        if (!$forceRefresh && is_file($this->cacheFile)) {
            $cacheAge = time() - filemtime($this->cacheFile);
            if ($cacheAge < self::CACHE_TTL_SECONDS) {
                $cachedData = json_decode((string)file_get_contents($this->cacheFile), true);
                if (is_array($cachedData)) {
                    $cachedVersion = (string)($cachedData['latest_version'] ?? '');
                    $cacheHasRepairInventory = array_key_exists('core_inventory', $cachedData);
                    $cacheNeedsFreshRepairInventory = (
                        $this->validator->compareVersions($cachedVersion, $currentVersion) === 0
                        && !$cacheHasRepairInventory
                    );
                    if ($cacheNeedsFreshRepairInventory) {
                        @unlink($this->cacheFile);
                    } else {
                        $cachedData['cached'] = true;
                    $cachedData['current_version'] = $currentVersion;
                    $cachedData['update_available'] = $this->validator->isNewer(
                        $cachedData['latest_version'] ?? '',
                        $currentVersion
                    );
                    $sameVersion = $this->validator->compareVersions(
                        (string)($cachedData['latest_version'] ?? ''),
                        $currentVersion
                    ) === 0;
                    $cachedData['missing_core_files'] = $sameVersion
                        ? $this->detectMissingCoreFiles((array)($cachedData['core_inventory'] ?? []))
                        : [];
                    $cachedData['repair_available'] = (
                        $sameVersion
                        && !empty($cachedData['download_url'])
                        && !empty($cachedData['core_inventory'])
                        && !empty($cachedData['missing_core_files'])
                    );
                        return $cachedData;
                    }
                }
            }
        }

        // 2. Query GitHub Releases API
        $apiUrl = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
        $responseJson = $this->fetchUrl($apiUrl);

        if ($responseJson === null) {
            $result['error'] = 'Could not retrieve latest release from GitHub API. You can still install updates using manual ZIP upload.';
            return $result;
        }

        $release = json_decode($responseJson, true);
        if (!is_array($release) || empty($release['tag_name'])) {
            $result['error'] = 'Invalid response received from GitHub Releases API.';
            return $result;
        }

        $tagName = (string)$release['tag_name'];
        $cleanVersion = ltrim($tagName, 'vV');

        $result['latest_version'] = $cleanVersion;
        $result['tag_name'] = $tagName;
        $result['release_name'] = (string)($release['name'] ?? $tagName);
        $result['release_notes'] = (string)($release['body'] ?? '');
        $result['published_at'] = (string)($release['published_at'] ?? '');
        $result['release_url'] = (string)($release['html_url'] ?? $result['release_url']);
        $result['update_available'] = $this->validator->isNewer($cleanVersion, $currentVersion);
        $result['repair_available'] = (
            !$result['update_available']
            && $this->validator->compareVersions($cleanVersion, $currentVersion) === 0
        );

        // Find the Core ZIP and its official checksum sidecar.
        $zipAssetName = '';
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                $assetName = (string)($asset['name'] ?? '');
                if (preg_match('/^Favorite-CMS-Universal.*\.zip$/i', $assetName)
                    && !preg_match('/[-_](?:tests|plugin|theme).*\.zip$/i', $assetName)) {
                    $result['download_url'] = (string)($asset['browser_download_url'] ?? '');
                    $result['package_name'] = $assetName;
                    $result['package_size'] = (int)($asset['size'] ?? 0);
                    $zipAssetName = $assetName;
                    break;
                }
            }

            if ($zipAssetName !== '') {
                $exactChecksumName = $zipAssetName . '.sha256';
                foreach ($release['assets'] as $asset) {
                    $assetName = (string)($asset['name'] ?? '');
                    if ($assetName === $exactChecksumName) {
                        $result['checksum_url'] = (string)($asset['browser_download_url'] ?? '');
                        break;
                    }
                }
                if ($result['checksum_url'] === '') {
                    foreach ($release['assets'] as $asset) {
                        $assetName = (string)($asset['name'] ?? '');
                        if (preg_match('/\.zip\.sha256$/i', $assetName) && !preg_match('/[-_]tests/i', $assetName)) {
                            $result['checksum_url'] = (string)($asset['browser_download_url'] ?? '');
                            break;
                        }
                    }
                }
            }
        }

        // Prefer the published .sha256 sidecar; keep release-note parsing as a legacy fallback.
        if (!empty($result['checksum_url'])) {
            $checksumText = $this->fetchUrl($result['checksum_url']);
            if ($checksumText !== null && preg_match('/(?<![a-fA-F0-9])([a-fA-F0-9]{64})(?![a-fA-F0-9])/', $checksumText, $m)) {
                $result['sha256'] = strtolower($m[1]);
            }
        }
        if ($result['sha256'] === '' && preg_match('/(?<![a-zA-Z0-9])SHA-?256(?:\*\*|__)?\s*:?\s*(?:\*\*|__)?\s*`?([a-fA-F0-9]{64})(?![a-fA-F0-9])`?/i', $result['release_notes'], $m)) {
            $result['sha256'] = strtolower($m[1]);
        }

        $sameVersion = $this->validator->compareVersions($cleanVersion, $currentVersion) === 0;
        if ($sameVersion && !empty($result['download_url'])) {
            // Build a package-backed Core inventory only for same-version repair detection.
            $inventoryZip = $this->cacheFile . '.inventory-' . md5($tagName . '|' . $result['download_url']) . '.zip';
            try {
                if (!is_file($inventoryZip)) {
                    $this->downloadPackage($result['download_url'], $inventoryZip, 104857600);
                }
                $packageValidation = $this->validator->validate($inventoryZip, $result['sha256'] !== '' ? $result['sha256'] : null);
                if (!$packageValidation['valid']) {
                    throw new RuntimeException('Repair package validation failed: ' . implode('; ', $packageValidation['errors']));
                }
                $result['core_inventory'] = $this->buildCoreInventory($inventoryZip, $packageValidation['root_prefix']);
                $result['missing_core_files'] = $this->detectMissingCoreFiles($result['core_inventory']);
            } catch (Throwable $e) {
                $result['core_inventory'] = [];
                $result['missing_core_files'] = [];
                // Do not manufacture a repair state when the package cannot be safely inspected.
                $result['error'] = 'The current Core release could not be safely inspected for repair status.';
            } finally {
                if (is_file($inventoryZip)) {
                    @unlink($inventoryZip);
                }
            }
        }

        $result['repair_available'] = (
            $sameVersion
            && !empty($result['download_url'])
            && !empty($result['core_inventory'])
            && !empty($result['missing_core_files'])
        );

        // 3. Cache response locally
        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        @file_put_contents($this->cacheFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $result;
    }

    /**
     * Build the canonical list of Core files that this updater is allowed to
     * restore. User uploads, installed plugins, custom themes and runtime data
     * are intentionally excluded.
     */
    protected function buildCoreInventory(string $zipPath, string $rootPrefix = ''): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Could not open Core package for inventory inspection.');
        }

        $allowedPrefixes = [
            'app/',
            'resources/',
            'vendor/',
            'config/',
            'database/migrations/',
            'themes/default/',
            'public/themes/default/',
            'public/assets/',
        ];
        $allowedFiles = [
            '.htaccess',
            'bootstrap.php',
            'index.php',
            'migrate.php',
            'public/index.php',
            'public/.htaccess',
            'README.txt',
            'README.md',
            'LICENSE',
            'CHANGELOG.md',
            'release.json',
        ];

        $inventory = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
                if ($rootPrefix !== '') {
                    $prefix = rtrim($rootPrefix, '/') . '/';
                    if (!str_starts_with($name, $prefix)) {
                        continue;
                    }
                    $name = substr($name, strlen($prefix));
                }
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                $allowed = in_array($name, $allowedFiles, true);
                if (!$allowed) {
                    foreach ($allowedPrefixes as $prefix) {
                        if (str_starts_with($name, $prefix)) {
                            $allowed = true;
                            break;
                        }
                    }
                }
                if ($allowed) {
                    $inventory[] = $name;
                }
            }
        } finally {
            $zip->close();
        }

        sort($inventory, SORT_STRING);
        return array_values(array_unique($inventory));
    }

    /**
     * Return only canonical Core files currently missing from the installation.
     */
    protected function detectMissingCoreFiles(array $inventory): array
    {
        $missing = [];
        foreach ($inventory as $relativePath) {
            $relativePath = ltrim(str_replace('\\', '/', (string)$relativePath), '/');
            if ($relativePath === '') {
                continue;
            }
            $absolute = $this->appRoot . '/' . $relativePath;
            if (!is_file($absolute)) {
                $missing[] = $relativePath;
            }
        }
        return $missing;
    }

    /**
     * Download a release package to a target local path with strict timeouts and size limits.
     *
     * @param string $downloadUrl
     * @param string $targetPath
     * @param int $maxSizeBytes (default 100MB)
     * @return bool
     */
    public function downloadPackage(string $downloadUrl, string $targetPath, int $maxSizeBytes = 104857600): bool
    {
        if (empty($downloadUrl) || !filter_var($downloadUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException("Invalid download URL provided: {$downloadUrl}");
        }

        // Target directory preparation
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }

        $fp = fopen($targetPath, 'wb');
        if (!$fp) {
            throw new RuntimeException("Could not open destination file for writing: {$targetPath}");
        }

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($downloadUrl);
                curl_setopt_array($ch, [
                    CURLOPT_FILE           => $fp,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 5,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_TIMEOUT        => 120,
                    CURLOPT_USERAGENT      => 'Favorite-CMS-Universal/' . (defined('APP_VERSION') ? APP_VERSION : '1.0.0'),
                    CURLOPT_FAILONERROR    => true,
                ]);

                $success = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                fclose($fp);

                if (!$success || $httpCode >= 400) {
                    @unlink($targetPath);
                    throw new RuntimeException("Download failed (HTTP {$httpCode}): {$curlError}");
                }
            } else {
                // Stream context fallback
                $context = stream_context_create([
                    'http' => [
                        'method'        => 'GET',
                        'timeout'       => 120,
                        'follow_location' => 1,
                        'user_agent'    => 'Favorite-CMS-Universal/' . (defined('APP_VERSION') ? APP_VERSION : '1.0.0'),
                    ],
                ]);

                $srcStream = @fopen($downloadUrl, 'rb', false, $context);
                if (!$srcStream) {
                    fclose($fp);
                    @unlink($targetPath);
                    throw new RuntimeException("Could not connect to download URL: {$downloadUrl}");
                }

                $written = stream_copy_to_stream($srcStream, $fp);
                fclose($srcStream);
                fclose($fp);

                if ($written === false || $written === 0) {
                    @unlink($targetPath);
                    throw new RuntimeException("Failed to stream download content from: {$downloadUrl}");
                }
            }

            // Verify file size limit
            if (filesize($targetPath) > $maxSizeBytes) {
                @unlink($targetPath);
                throw new RuntimeException("Downloaded package exceeded maximum permitted size ({$maxSizeBytes} bytes).");
            }

            return true;
        } catch (\Throwable $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
            throw $e;
        }
    }

    /**
     * HTTP GET request with strict timeouts and proper user-agent.
     */
    protected function fetchUrl(string $url): ?string
    {
        $version = defined('APP_VERSION') ? APP_VERSION : '1.0.0';
        $userAgent = 'Favorite-CMS-Universal/' . $version . ' (+https://github.com/' . self::GITHUB_REPO . ')';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_USERAGENT      => $userAgent,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/vnd.github.v3+json',
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && is_string($content)) {
                return $content;
            }

            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "User-Agent: {$userAgent}\r\nAccept: application/vnd.github.v3+json\r\n",
                'timeout'       => 12,
                'follow_location' => 1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        return ($content !== false) ? $content : null;
    }
}

