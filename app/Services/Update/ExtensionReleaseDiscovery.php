<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Update;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Plugins\PluginManager;
use FavoriteCMS\Themes\ThemeManager;
use RuntimeException;

class ExtensionReleaseDiscovery
{
    protected const DEFAULT_ASSETS_REPO = 'favoritecode/Favorite-CMS-Assets';
    protected const CACHE_TTL_SECONDS = 3600; // 1 hour

    protected Application $app;
    protected string $appRoot;
    protected string $cacheFile;

    public function __construct(Application $app, ?string $appRoot = null)
    {
        $this->app = $app;
        $this->appRoot = $appRoot ?: (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3));
        $this->cacheFile = $this->appRoot . '/storage/cache/extension_updates.json';
    }

    /**
     * Check for available updates strictly for currently INSTALLED extensions (plugins and themes).
     * Uninstalled extensions are never queried, returned, or displayed.
     *
     * @param bool $forceRefresh
     * @return array List of available extension updates.
     */
    public function checkUpdates(bool $forceRefresh = false): array
    {
        $installedExtensions = $this->getInstalledExtensions();
        if (empty($installedExtensions)) {
            return [];
        }

        // Check local cache
        if (!$forceRefresh && is_file($this->cacheFile)) {
            $cacheAge = time() - filemtime($this->cacheFile);
            if ($cacheAge < self::CACHE_TTL_SECONDS) {
                $cached = json_decode((string)file_get_contents($this->cacheFile), true);
                if (is_array($cached)) {
                    // Filter cached updates strictly against currently installed extensions
                    return $this->filterAvailableUpdates($cached, $installedExtensions);
                }
            }
        }

        // Group installed extensions by their declared update source repository
        $sources = [];
        foreach ($installedExtensions as $ext) {
            $sourceRepo = $ext['update_source'] ?? self::DEFAULT_ASSETS_REPO;
            $sources[$sourceRepo][] = $ext;
        }

        $allDiscoveredUpdates = [];

        foreach ($sources as $sourceRepo => $extensions) {
            $releases = $this->fetchReleasesForRepo($sourceRepo);
            if (empty($releases) || !is_array($releases)) {
                continue;
            }

            foreach ($extensions as $ext) {
                $extId = strtolower(trim((string)$ext['id']));
                $currentVersion = (string)($ext['version'] ?? '1.0.0');

                // Find matching release for this specific extension ID
                $matchedRelease = $this->findLatestReleaseForExtension($extId, $releases);
                if ($matchedRelease !== null) {
                    $availableVersion = $matchedRelease['version'];
                    if (version_compare($availableVersion, $currentVersion, '>')) {
                        $allDiscoveredUpdates[$ext['type'] . ':' . $extId] = [
                            'id'                => $extId,
                            'type'              => $ext['type'], // 'plugin' or 'theme'
                            'name'              => $ext['name'] ?? ucfirst($extId),
                            'installed_version' => $currentVersion,
                            'available_version' => $availableVersion,
                            'tag_name'          => $matchedRelease['tag_name'],
                            'release_name'      => $matchedRelease['release_name'],
                            'release_notes'     => $matchedRelease['release_notes'],
                            'published_at'      => $matchedRelease['published_at'],
                            'download_url'      => $matchedRelease['download_url'],
                            'package_name'      => $matchedRelease['package_name'],
                            'package_size'      => $matchedRelease['package_size'],
                            'sha256'            => $matchedRelease['sha256'],
                            'update_source'     => $sourceRepo,
                        ];
                    }
                }
            }
        }

        // Cache discovery result
        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        @file_put_contents($this->cacheFile, json_encode(array_values($allDiscoveredUpdates), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return array_values($allDiscoveredUpdates);
    }

    /**
     * Gather list of currently installed plugins and themes from their authoritative managers.
     */
    public function getInstalledExtensions(): array
    {
        $installed = [];

        // 1. Installed Plugins
        try {
            $pluginMgr = new PluginManager($this->app);
            $plugins = $pluginMgr->scan();
            foreach ($plugins as $id => $meta) {
                $extId = (string)($meta['id'] ?? $id);
                if ($extId === '') {
                    continue;
                }
                $installed['plugin:' . $extId] = [
                    'id'            => $extId,
                    'type'          => 'plugin',
                    'name'          => $meta['name'] ?? ucfirst($extId),
                    'version'       => $meta['version'] ?? '1.0.0',
                    'update_source' => $meta['update_source'] ?? self::DEFAULT_ASSETS_REPO,
                ];
            }
        } catch (\Throwable) {
            // Gracefully ignore plugin scan issues during updates
        }

        // 2. Installed Themes
        try {
            $themeMgr = new ThemeManager($this->app);
            $themes = $themeMgr->all();
            foreach ($themes as $id => $meta) {
                $extId = (string)($meta['id'] ?? $id);
                if ($extId === '') {
                    continue;
                }
                $installed['theme:' . $extId] = [
                    'id'            => $extId,
                    'type'          => 'theme',
                    'name'          => $meta['name'] ?? ucfirst($extId),
                    'version'       => $meta['version'] ?? '1.0.0',
                    'update_source' => $meta['update_source'] ?? self::DEFAULT_ASSETS_REPO,
                ];
            }
        } catch (\Throwable) {
            // Gracefully ignore theme scan issues during updates
        }

        return $installed;
    }

    /**
     * Find the latest valid release matching an extension ID.
     * Supports standard tag conventions:
     * - v{version}-{extension_id} (e.g. v1.2.0-favorite-web, v1.0.12-favorite-pay)
     * - {extension_id}-v{version} or {extension_id}-{version}
     * - {extension_id} matching tag or asset name
     */
    public function findLatestReleaseForExtension(string $extensionId, array $releases): ?array
    {
        $matched = null;
        $highestVersion = '0.0.0';

        foreach ($releases as $rel) {
            $tag = (string)($rel['tag_name'] ?? '');
            $parsedVersion = null;

            // Pattern 1: v{version}-{extensionId} (e.g. v1.2.0-favorite-web)
            if (preg_match('/^v?([0-9\.]+)-' . preg_quote($extensionId, '/') . '$/i', $tag, $m)) {
                $parsedVersion = $m[1];
            }
            // Pattern 2: {extensionId}-v?{version} (e.g. favorite-web-v1.2.0)
            elseif (preg_match('/^' . preg_quote($extensionId, '/') . '-v?([0-9\.]+)$/i', $tag, $m)) {
                $parsedVersion = $m[1];
            }

            if ($parsedVersion === null) {
                // Check if any asset strictly matches {extensionId}*.zip
                $assetMatch = false;
                if (!empty($rel['assets']) && is_array($rel['assets'])) {
                    foreach ($rel['assets'] as $asset) {
                        $assetName = (string)($asset['name'] ?? '');
                        if (preg_match('/^' . preg_quote($extensionId, '/') . '[-_v]?([0-9\.]*)?\.zip$/i', $assetName, $am)) {
                            $assetMatch = true;
                            if (!empty($am[1])) {
                                $parsedVersion = $am[1];
                            }
                            break;
                        }
                    }
                }

                if (!$assetMatch) {
                    continue;
                }
            }

            $version = $parsedVersion ?: ltrim($tag, 'vV');
            if (version_compare($version, $highestVersion, '>')) {
                // Find matching zip asset
                $downloadUrl = '';
                $packageName = '';
                $packageSize = 0;
                $sha256 = '';

                if (!empty($rel['assets']) && is_array($rel['assets'])) {
                    // Look for extension zip asset first
                    foreach ($rel['assets'] as $asset) {
                        $aName = (string)($asset['name'] ?? '');
                        if (str_ends_with(strtolower($aName), '.zip') && (stripos($aName, $extensionId) !== false || stripos($aName, str_replace('-', '', $extensionId)) !== false)) {
                            $downloadUrl = (string)($asset['browser_download_url'] ?? '');
                            $packageName = $aName;
                            $packageSize = (int)($asset['size'] ?? 0);
                            break;
                        }
                    }

                    // Fallback to any zip asset in the release if only one exists
                    if ($downloadUrl === '') {
                        foreach ($rel['assets'] as $asset) {
                            $aName = (string)($asset['name'] ?? '');
                            if (str_ends_with(strtolower($aName), '.zip')) {
                                $downloadUrl = (string)($asset['browser_download_url'] ?? '');
                                $packageName = $aName;
                                $packageSize = (int)($asset['size'] ?? 0);
                                break;
                            }
                        }
                    }
                }

                // If no zip asset, fallback to zipball_url
                if ($downloadUrl === '' && !empty($rel['zipball_url'])) {
                    $downloadUrl = (string)$rel['zipball_url'];
                    $packageName = $extensionId . '-' . $version . '.zip';
                }

                // Extract SHA-256 checksum from release notes if present
                $releaseNotes = (string)($rel['body'] ?? '');
                if (preg_match('/SHA-?256:?\s*`?([a-fA-F0-9]{64})`?/i', $releaseNotes, $sm)) {
                    $sha256 = strtolower($sm[1]);
                }

                $highestVersion = $version;
                $matched = [
                    'version'       => $version,
                    'tag_name'      => $tag,
                    'release_name'  => (string)($rel['name'] ?? $tag),
                    'release_notes' => $releaseNotes,
                    'published_at'  => (string)($rel['published_at'] ?? ''),
                    'download_url'  => $downloadUrl,
                    'package_name'  => $packageName,
                    'package_size'  => $packageSize,
                    'sha256'        => $sha256,
                ];
            }
        }

        return $matched;
    }

    /**
     * Filter cached update records against currently installed extensions.
     */
    protected function filterAvailableUpdates(array $cachedUpdates, array $installedExtensions): array
    {
        $filtered = [];
        foreach ($cachedUpdates as $upd) {
            $key = ($upd['type'] ?? '') . ':' . ($upd['id'] ?? '');
            if (isset($installedExtensions[$key])) {
                $currentVer = (string)($installedExtensions[$key]['version'] ?? '1.0.0');
                if (version_compare((string)($upd['available_version'] ?? ''), $currentVer, '>')) {
                    $upd['installed_version'] = $currentVer;
                    $filtered[] = $upd;
                }
            }
        }
        return $filtered;
    }

    /**
     * Fetch releases list for a GitHub repository.
     */
    protected function fetchReleasesForRepo(string $repo): ?array
    {
        $apiUrl = 'https://api.github.com/repos/' . trim($repo, '/') . '/releases?per_page=30';
        $json = $this->fetchUrl($apiUrl);
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * HTTP GET request with timeouts and proper user agent.
     */
    protected function fetchUrl(string $url): ?string
    {
        $version = defined('APP_VERSION') ? APP_VERSION : '1.0.0';
        $userAgent = 'Favorite-CMS-Universal/' . $version . ' (+https://github.com/favoritecode/Favorite-CMS-Universal)';

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

