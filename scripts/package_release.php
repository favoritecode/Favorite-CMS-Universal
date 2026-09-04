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
    'plugins',
    'public',
    'resources',
    'themes',
    'vendor',
];

$filesToCopy = [
    '.htaccess',
    'index.php',
    'bootstrap.php',
    'README.txt',
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
    } else {
        $zip->addFile($realPath, $entryName);
        $zip->setExternalAttributesName($entryName, ZipArchive::OPSYS_UNIX, $fileAttr);
    }
}

$zip->close();

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

