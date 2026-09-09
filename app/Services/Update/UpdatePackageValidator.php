<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Update;

use FavoriteCMS\Core\Exceptions\SecurityException;
use RuntimeException;
use ZipArchive;

class UpdatePackageValidator
{
    /**
     * Forbidden files or paths that must NEVER be present inside a Core update package.
     */
    protected const FORBIDDEN_PATTERNS = [
        '#(^|/)\.env($|/)#i',
        '#(^|/)installed\.lock$#i',
        '#(^|/)plugins/favorite-pay\b#i',
        '#(^|/)public/plugins/favorite-pay\b#i',
        '#(^|/)Favorite-CMS-Assets\b#i',
        '#(^|/)\.git\b#i',
        '#(^|/)tests\b#i',
        '#(^|/)phpunit\.xml$#i',
    ];

    /**
     * Mandatory Core files that MUST be present for a package to be recognized as Favorite CMS Universal.
     */
    protected const REQUIRED_CORE_FILES = [
        'bootstrap.php',
        'index.php',
        'migrate.php',
        'app/Core/Application.php',
        'public/index.php',
    ];

    /**
     * Validate an update package ZIP archive.
     *
     * @param string $zipPath Full path to the ZIP package.
     * @param string|null $expectedChecksum Optional expected SHA-256 hash.
     * @return array Validation result details.
     */
    public function validate(string $zipPath, ?string $expectedChecksum = null): array
    {
        $result = [
            'valid'         => true,
            'zip_path'      => $zipPath,
            'sha256'        => '',
            'size'          => 0,
            'root_prefix'   => '',
            'product'       => 'Favorite CMS Universal',
            'product_id'    => 'favorite-cms-universal',
            'version'       => '',
            'min_core'      => '',
            'min_php'       => '8.1.0',
            'file_count'    => 0,
            'manifest'      => null,
            'errors'        => [],
            'warnings'      => [],
        ];

        if (!is_file($zipPath) || !is_readable($zipPath)) {
            $result['valid'] = false;
            $result['errors'][] = "Update package not found or unreadable: {$zipPath}";
            return $result;
        }

        $result['size'] = filesize($zipPath);
        $result['sha256'] = hash_file('sha256', $zipPath);

        // Checksum verification if expected checksum was provided
        if ($expectedChecksum !== null && $expectedChecksum !== '') {
            $normalizedExpected = strtolower(trim($expectedChecksum));
            $actualChecksum = strtolower($result['sha256']);
            if (!hash_equals($normalizedExpected, $actualChecksum)) {
                $result['valid'] = false;
                $result['errors'][] = "Package checksum mismatch! Expected SHA-256 '{$normalizedExpected}', calculated '{$actualChecksum}'. Package may be corrupted or tampered with.";
                return $result;
            }
        }

        if (!class_exists(ZipArchive::class)) {
            $result['valid'] = false;
            $result['errors'][] = "The PHP ZipArchive extension is required to validate update packages.";
            return $result;
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($zipPath, ZipArchive::RDONLY);
        if ($openResult !== true) {
            $result['valid'] = false;
            $result['errors'][] = "Failed to open update archive (ZipArchive error code: {$openResult}). Package may be corrupted.";
            return $result;
        }

        try {
            $numFiles = $zip->numFiles;
            $result['file_count'] = $numFiles;

            if ($numFiles === 0) {
                $result['valid'] = false;
                $result['errors'][] = "Update package archive is empty.";
                return $result;
            }

            // 1. Path traversal (Zip-Slip) & security verification
            $entryNames = [];
            for ($i = 0; $i < $numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'] ?? '';
                $name = str_replace('\\', '/', $name);

                // ZipSlip traversal checks
                if (
                    str_contains($name, '..') ||
                    str_starts_with($name, '/') ||
                    preg_match('/^[a-zA-Z]:/', $name)
                ) {
                    throw new SecurityException("Malicious path traversal entry (Zip-Slip) detected in update package: {$name}");
                }

                $entryNames[] = $name;

                // Forbidden files check
                foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                    if (preg_match($pattern, $name)) {
                        $result['valid'] = false;
                        $result['errors'][] = "Package contains forbidden or dangerous entry: {$name}";
                        break;
                    }
                }
            }

            // 2. Detect root directory prefix (e.g. Favorite-CMS-Universal/)
            $rootPrefix = '';
            $firstSlash = strpos($entryNames[0], '/');
            if ($firstSlash !== false) {
                $possiblePrefix = substr($entryNames[0], 0, $firstSlash);
                // Check if all non-empty entries share this prefix
                $allShare = true;
                foreach ($entryNames as $name) {
                    if ($name !== '' && !str_starts_with($name, $possiblePrefix . '/')) {
                        $allShare = false;
                        break;
                    }
                }
                if ($allShare) {
                    $rootPrefix = $possiblePrefix;
                }
            }
            $result['root_prefix'] = $rootPrefix;

            // Helper to get archive file contents respecting rootPrefix
            $getArchiveFile = function (string $relPath) use ($zip, $rootPrefix): ?string {
                $target = ($rootPrefix !== '') ? $rootPrefix . '/' . $relPath : $relPath;
                $contents = $zip->getFromName($target);
                return ($contents !== false) ? $contents : null;
            };

            // 3. Inspect release.json or manifest.json if present
            $manifestJson = $getArchiveFile('release.json') ?? $getArchiveFile('manifest.json');
            if ($manifestJson !== null) {
                $manifest = json_decode($manifestJson, true);
                if (is_array($manifest)) {
                    $result['manifest'] = $manifest;
                    $result['product'] = $manifest['product'] ?? $manifest['cms_name'] ?? 'Favorite CMS Universal';
                    $result['product_id'] = $manifest['product_id'] ?? 'favorite-cms-universal';
                    $result['version'] = $manifest['version'] ?? $manifest['cms_version'] ?? '';
                    $result['min_core'] = $manifest['min_core_version'] ?? $manifest['min_core'] ?? '';
                    $result['min_php'] = $manifest['min_php'] ?? $manifest['requires_php'] ?? '8.1.0';

                    // Verify product identity matches Favorite CMS Universal
                    if (!preg_match('/favorite[\s_-]?cms/i', (string)$result['product'])) {
                        $result['valid'] = false;
                        $result['errors'][] = "Package product '{$result['product']}' does not match Favorite CMS Universal.";
                    }
                }
            }

            // 4. If version not discovered via manifest, extract from bootstrap.php in package
            if ($result['version'] === '') {
                $bootstrapContent = $getArchiveFile('bootstrap.php');
                if ($bootstrapContent !== null) {
                    if (preg_match("/define\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $bootstrapContent, $m)) {
                        $result['version'] = $m[1];
                    }
                }
            }

            // 5. If version still not found, check composer.json
            if ($result['version'] === '') {
                $composerJson = $getArchiveFile('composer.json');
                if ($composerJson !== null) {
                    $compData = json_decode($composerJson, true);
                    if (is_array($compData) && !empty($compData['version'])) {
                        $result['version'] = (string)$compData['version'];
                    }
                }
            }

            // 6. Verify required Core files exist
            foreach (self::REQUIRED_CORE_FILES as $required) {
                $target = ($rootPrefix !== '') ? $rootPrefix . '/' . $required : $required;
                if ($zip->locateName($target) === false) {
                    $result['valid'] = false;
                    $result['errors'][] = "Package is missing required Core file: {$required}. It may not be a valid Favorite CMS Universal release.";
                }
            }

            // 7. Version validation & compatibility
            if ($result['version'] === '') {
                $result['valid'] = false;
                $result['errors'][] = "Could not determine Core version from package release metadata or bootstrap.php.";
            } else {
                // Check minimum PHP requirement
                if (!empty($result['min_php'])) {
                    $minPhp = ltrim((string)$result['min_php'], '^>=~ ');
                    if (version_compare(PHP_VERSION, $minPhp, '<')) {
                        $result['valid'] = false;
                        $result['errors'][] = "Package requires PHP {$minPhp}, but current server is running PHP " . PHP_VERSION;
                    }
                }

                // Check minimum Core version requirement
                if (!empty($result['min_core']) && defined('APP_VERSION')) {
                    $minCore = ltrim((string)$result['min_core'], '^>=~ ');
                    if ($this->compareVersions(APP_VERSION, $minCore) < 0) {
                        $result['valid'] = false;
                        $result['errors'][] = "Package requires minimum Core version {$minCore}, but installed version is " . APP_VERSION;
                    }
                }
            }

        } catch (SecurityException $se) {
            $result['valid'] = false;
            $result['errors'][] = $se->getMessage();
        } finally {
            $zip->close();
        }

        return $result;
    }

    /**
     * Robust semver-aware version comparison.
     * Returns:
     *  -1 if $v1 < $v2
     *   0 if $v1 == $v2
     *   1 if $v1 > $v2
     */
    public function compareVersions(string $v1, string $v2): int
    {
        $v1Clean = ltrim(trim($v1), 'vV');
        $v2Clean = ltrim(trim($v2), 'vV');

        // Normalize beta/alpha/rc formats for PHP's version_compare
        return version_compare($v1Clean, $v2Clean);
    }

    /**
     * Check whether candidate version is strictly newer than current version.
     */
    public function isNewer(string $candidateVersion, ?string $currentVersion = null): bool
    {
        $current = $currentVersion ?? (defined('APP_VERSION') ? APP_VERSION : '1.0.0');
        return $this->compareVersions($candidateVersion, $current) > 0;
    }
}

