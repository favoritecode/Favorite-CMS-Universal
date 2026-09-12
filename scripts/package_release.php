<?php

declare(strict_types=1);

/**
 * Favorite CMS Universal — Production Release Packager
 *
 * Generates a clean, portable production ZIP archive with:
 * - Strictly normalized forward slashes ('/')
 * - Single root directory: Favorite-CMS-Universal/
 * - POSIX/Unix external file attributes (0755 for directories, 0644 for files)
 *   to ensure 100% compatibility with Linux, cPanel, Hostinger File Manager, macOS, and Windows.
 * - Complete exclusion of dev tooling, tests, cache, sessions, and environment secrets.
 */

$sourceDir = dirname(__DIR__);
$outputDir = $sourceDir . '/release';
$zipName = 'Favorite-CMS-Universal.zip';
$finalZipPath = $outputDir . '/' . $zipName;
$rootPrefix = 'Favorite-CMS-Universal';
$previousArchives = array_map(static fn (string $path): string => 'release/' . basename($path), glob($outputDir . '/*.zip') ?: []);

// Both runtime entry points must ship the same built-in theme.
$themeFiles = [];
foreach (['themes/default', 'public/themes/default'] as $themeDirectory) {
    $themeFiles[$themeDirectory] = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir . '/' . $themeDirectory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($sourceDir . '/' . $themeDirectory) + 1));
            $themeFiles[$themeDirectory][$relative] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($themeFiles[$themeDirectory]);
}
if (!$themeFiles['themes/default'] || $themeFiles['themes/default'] !== $themeFiles['public/themes/default']) {
    throw new RuntimeException('Default theme mirrors are not synchronized.');
}

echo "==================================================\n";
echo "Favorite CMS Universal — Packaging Release Archive\n";
echo "==================================================\n";

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

// 1. Define allowed directories and root files
$dirsToCopy = [
    'app',
    'config',
    'database',
    'public',
    'resources',
    'themes/default',
];

$filesToCopy = [
    '.htaccess',
    'index.php',
    'bootstrap.php',
    'migrate.php',
    'README.txt',
    'README.md',
    'LICENSE',
];

// 2. Patterns to strictly exclude from release package
$excludePatterns = [
    '/\.git\b/',
    '/\.github\b/',
    '/\.idea\b/',
    '/\.vscode\b/',
    '/\.env\b/',
    '/tests\b/',
    '/phpunit\.xml/',
    '/installed\.lock/',
    '/\.log$/',
    '/cache\//',
    '/sessions\//',
    '/release\//',
    '#/public/(plugins|uploads)(/|$)#',
    '#/public/themes/(?!default(?:/|$))[^/]+#',
    '#\.(zip|sql|bak|backup|tmp|temp|log|map)$#i',
    '/node_modules\b/',
    '/dfre\b/',
];

// 3. Staging directory setup
$stageDir = $outputDir . '/stage';
if (is_dir($stageDir)) {
    removeDir($stageDir);
}
mkdir($stageDir, 0775, true);

// 4. Copy runtime directories
foreach ($dirsToCopy as $dir) {
    $src = $sourceDir . '/' . $dir;
    $dst = $stageDir . '/' . $dir;
    if (is_dir($src)) {
        echo "Staging directory: {$dir}...\n";
        copyDir($src, $dst, $excludePatterns);
    }
}

// Build the runtime autoloader from the lock file, without development packages.
copy($sourceDir . '/composer.json', $stageDir . '/composer.json');
copy($sourceDir . '/composer.lock', $stageDir . '/composer.lock');
$composer = getenv('COMPOSER_BINARY') ?: dirname(PHP_BINARY) . '/composer.phar';
if (!is_file($composer)) {
    throw new RuntimeException('Set COMPOSER_BINARY to the Composer PHAR used for production packaging.');
}
$process = proc_open([PHP_BINARY, $composer, 'install', '--no-dev', '--no-scripts', '--no-plugins', '--no-interaction', '--prefer-dist', '--optimize-autoloader'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $stageDir);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start Composer.');
}
fclose($pipes[0]);
if (proc_close($process) !== 0) {
    throw new RuntimeException('Production dependency installation failed.');
}
unlink($stageDir . '/composer.json');
unlink($stageDir . '/composer.lock');
@mkdir($stageDir . '/plugins', 0755, true);
@mkdir($stageDir . '/public/plugins', 0755, true);

// 5. Setup clean runtime storage structure
echo "Setting up clean storage directory structure...\n";
$storageDir = $stageDir . '/storage';
@mkdir($storageDir . '/cache', 0775, true);
@mkdir($storageDir . '/logs', 0775, true);
@mkdir($storageDir . '/sessions', 0775, true);
@file_put_contents($storageDir . '/cache/.gitkeep', '');
@file_put_contents($storageDir . '/logs/.gitkeep', '');
@file_put_contents($storageDir . '/sessions/.gitkeep', '');

// 6. Clean public/uploads to include only .gitkeep
$uploadsDir = $stageDir . '/public/uploads';
if (is_dir($uploadsDir)) {
    $existing = glob($uploadsDir . '/*');
    foreach ($existing as $item) {
        if (basename($item) !== '.gitkeep') {
            if (is_dir($item)) {
                removeDir($item);
            } else {
                @unlink($item);
            }
        }
    }
} else {
    @mkdir($uploadsDir, 0775, true);
}
@file_put_contents($uploadsDir . '/.gitkeep', '');
@mkdir($uploadsDir . '/avatars', 0775, true);
@file_put_contents($uploadsDir . '/avatars/.gitkeep', '');

// 7. Verify critical public entrypoints
if (!file_exists($stageDir . '/public/.htaccess')) {
    throw new RuntimeException("CRITICAL: public/.htaccess is missing from stage!");
}
if (!file_exists($stageDir . '/public/index.php')) {
    throw new RuntimeException("CRITICAL: public/index.php is missing from stage!");
}

// 8. Copy root runtime files
foreach ($filesToCopy as $file) {
    $src = $sourceDir . '/' . $file;
    $dst = $stageDir . '/' . $file;
    if (file_exists($src)) {
        echo "Staging file: {$file}...\n";
        copy($src, $dst);
    }
}

// 8b. Add authoritative release metadata manifest
$appVersion = '1.0.10';
$bootstrapPath = $sourceDir . '/bootstrap.php';
if (file_exists($bootstrapPath) && preg_match("/define\(\s*['\"]APP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", (string)file_get_contents($bootstrapPath), $m)) {
    $appVersion = $m[1];
}

$releaseMetadata = [
    'product'          => 'Favorite CMS Universal',
    'product_id'       => 'favorite-cms-universal',
    'version'          => $appVersion,
    'min_core_version' => '1.0.0',
    'min_php'          => '8.1.0',
    'created_at'       => date('c'),
];
file_put_contents($stageDir . '/release.json', json_encode($releaseMetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Staged release metadata: release.json (v{$appVersion})...\n";

// 9. Build ZIP archive using PHP native ZipArchive with Unix attributes
echo "Generating ZIP archive with POSIX / Unix permissions...\n";
$zip = new ZipArchive();
if ($zip->open($finalZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException("Could not open {$finalZipPath} for writing.");
}

// Unix permission bitmasks
$dirAttr = (0040755 << 16) | 0x10; // drwxr-xr-x + directory flag
$fileAttr = (0100644 << 16);       // -rw-r--r--

// First add root directory
$zip->addEmptyDir($rootPrefix);
$zip->setExternalAttributesName($rootPrefix, ZipArchive::OPSYS_UNIX, $dirAttr);
$zip->setExternalAttributesIndex($zip->numFiles - 1, ZipArchive::OPSYS_UNIX, $dirAttr);

// Add directories and files recursively
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stageDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    $realPath = $item->getRealPath();
    $relPath = substr($realPath, strlen($stageDir) + 1);
    $normalizedRel = str_replace('\\', '/', $relPath);
    $entryName = $rootPrefix . '/' . $normalizedRel;

    if ($item->isDir()) {
        $zip->addEmptyDir($entryName);
        $zip->setExternalAttributesName($entryName, ZipArchive::OPSYS_UNIX, $dirAttr);
        $zip->setExternalAttributesIndex($zip->numFiles - 1, ZipArchive::OPSYS_UNIX, $dirAttr);
    } else {
        $zip->addFile($realPath, $entryName);
        $zip->setExternalAttributesName($entryName, ZipArchive::OPSYS_UNIX, $fileAttr);
        $zip->setExternalAttributesIndex($zip->numFiles - 1, ZipArchive::OPSYS_UNIX, $fileAttr);
    }
}

$zip->close();

// Exact source-file exclusions, stored beside (never inside) the release ZIP.
$excluded = $previousArchives;
$sourceIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS));
foreach ($sourceIterator as $item) {
    if (!$item->isFile()) continue;
    $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($sourceDir) + 1));
    if (str_starts_with($relative, 'release/')) continue; // generated build output is not an input
    $staged = $stageDir . '/' . $relative;
    if (!is_file($staged) || hash_file('sha256', $item->getPathname()) !== hash_file('sha256', $staged)) {
        $excluded[] = $relative;
    }
}
sort($excluded, SORT_STRING);
file_put_contents($outputDir . '/production-exclusions.txt', implode("\n", $excluded) . "\n");
echo 'Excluded source files/build inputs: ' . count($excluded) . " (production-exclusions.txt; includes previous release ZIPs)\n";

// Cleanup stage directory
removeDir($stageDir);

// 10. Verify generated ZIP
echo "Verifying archive integrity and entry formatting...\n";
$readZip = new ZipArchive();
if ($readZip->open($finalZipPath) !== true) {
    throw new RuntimeException("Generated ZIP could not be read.");
}

$totalEntries = $readZip->numFiles;
$backslashCount = 0;
$doubleNestingCount = 0;
$hasPubIndex = false;
$hasPubHtaccess = false;

for ($i = 0; $i < $totalEntries; $i++) {
    $stat = $readZip->statIndex($i);
    $name = $stat['name'];

    if (!str_starts_with($name, $rootPrefix . '/') || preg_match('#(?:^|/)(?:\.git|\.github|tests|phpunit|backups|node_modules)(?:/|$)|(?:^|/)\.env(?:\.|$)|\.(?:sql|zip|bak|log|tmp)$#i', $name)
        || preg_match('#^' . preg_quote($rootPrefix, '#') . '/(?:public/)?plugins/[^/]+#', $name)
        || preg_match('#^' . preg_quote($rootPrefix, '#') . '/(?:public/)?themes/(?!default(?:/|$))[^/]+#', $name)
        || str_contains($name, '..')) {
        throw new RuntimeException('Prohibited archive entry: ' . $name);
    }
    $readZip->getExternalAttributesIndex($i, $opsys, $attrs);
    if ($opsys !== ZipArchive::OPSYS_UNIX || (($attrs >> 16) & 0777) !== (str_ends_with($name, '/') ? 0755 : 0644)) {
        throw new RuntimeException('Invalid archive permissions: ' . $name);
    }
    if (!str_ends_with($name, '/') && $readZip->getFromIndex($i) === false) {
        throw new RuntimeException('Unreadable archive entry: ' . $name);
    }

    if (str_contains($name, '\\')) {
        $backslashCount++;
    }
    if (str_starts_with($name, "{$rootPrefix}/{$rootPrefix}/")) {
        $doubleNestingCount++;
    }
    if ($name === "{$rootPrefix}/public/index.php") {
        $hasPubIndex = true;
    }
    if ($name === "{$rootPrefix}/public/.htaccess") {
        $hasPubHtaccess = true;
    }
}

$readZip->close();

if ($backslashCount > 0) {
    throw new RuntimeException("FAILED: {$backslashCount} entries contain backslashes!");
}
if ($doubleNestingCount > 0) {
    throw new RuntimeException("FAILED: {$doubleNestingCount} entries have double nesting!");
}
if (!$hasPubIndex || !$hasPubHtaccess) {
    throw new RuntimeException("FAILED: public/index.php or public/.htaccess missing from ZIP!");
}

$zipSize = filesize($finalZipPath);
$zipHash = hash_file('sha256', $finalZipPath);
file_put_contents($finalZipPath . '.sha256', $zipHash . '  ' . $zipName . "\n");

echo "==================================================\n";
echo "RELEASE PACKAGE SUCCESSFULLY GENERATED!\n";
echo "Total Entries:    {$totalEntries}\n";
echo "Backslash Count:  0 (Normalized '/')\n";
echo "Double Nesting:   0\n";
echo "Unix Attributes:  ENABLED (0755 dirs, 0644 files)\n";
echo "Path:             {$finalZipPath}\n";
echo "Size:             " . round($zipSize / 1024 / 1024, 2) . " MB ({$zipSize} bytes)\n";
echo "SHA-256:          {$zipHash}\n";
echo "==================================================\n";

// Helper functions
function copyDir(string $src, string $dst, array $excludes): void
{
    @mkdir($dst, 0775, true);
    $dir = opendir($src);
    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $srcPath = $src . '/' . $file;
        $dstPath = $dst . '/' . $file;

        if (is_link($srcPath)) {
            throw new RuntimeException('Symlink is not allowed in a production package: ' . $srcPath);
        }

        $skip = false;
        foreach ($excludes as $pattern) {
            if (preg_match($pattern, '/' . $file) || preg_match($pattern, $srcPath)) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }

        if (is_dir($srcPath)) {
            copyDir($srcPath, $dstPath, $excludes);
        } else {
            copy($srcPath, $dstPath);
        }
    }
    closedir($dir);
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            @unlink($file->getRealPath());
        }
    }
    @rmdir($dir);
}

