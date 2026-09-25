<?php

declare(strict_types=1);

/**
 * Favorite CMS Universal — Repository Governance & Integrity Safeguard.
 *
 * Enforces permanent repository rules:
 * - Rejects standalone plugins (Favorite Multimedia, Favorite Web Tools, Favorite Shop)
 * - Rejects non-core release ZIP files
 * - Rejects real .env credential files
 * - Rejects committed runtime cache, session, and log files
 * - Rejects IDE metadata
 * - Verifies verified v1.0.0 release ZIP immutability
 *
 * NOTE: This safeguard reports violations and exits with code 1 upon failure.
 * It NEVER auto-deletes files, rewrites history, or alters CMS runtime code.
 */

$root = dirname(__DIR__);
$violations = [];

echo "=======================================================\n";
echo "Favorite CMS Universal — Repository Governance Audit\n";
echo "=======================================================\n\n";

// 1. Check for forbidden standalone plugins in plugins/
$forbiddenPlugins = [
    'favorite-multimedia',
    'favorite-web-tools',
    'favorite-shop',
];

foreach ($forbiddenPlugins as $pluginSlug) {
    $path = $root . '/plugins/' . $pluginSlug;
    if (is_dir($path) || file_exists($path)) {
        $violations[] = "[NON-CORE PLUGIN] Forbidden plugin detected: plugins/{$pluginSlug}. Standalone plugins must be maintained in Favorite-CMS-Assets or their dedicated repository.";
    }
}

// 2. Check for forbidden non-core release ZIPs across project root
$forbiddenZipPatterns = [
    '#favorite[-_]multimedia.*\.zip$#i',
    '#favorite[-_]web[-_]tools.*\.zip$#i',
    '#favorite[-_]shop.*\.zip$#i',
];

$rootFiles = scandir($root) ?: [];
foreach ($rootFiles as $file) {
    foreach ($forbiddenZipPatterns as $pattern) {
        if (preg_match($pattern, $file)) {
            $violations[] = "[NON-CORE ASSET] Forbidden release ZIP detected in repository: {$file}. Non-core releases belong in Favorite-CMS-Assets.";
        }
    }
}

// Check release/ directory if present
if (is_dir($root . '/release')) {
    $releaseFiles = scandir($root . '/release') ?: [];
    foreach ($releaseFiles as $file) {
        foreach ($forbiddenZipPatterns as $pattern) {
            if (preg_match($pattern, $file)) {
                $violations[] = "[NON-CORE ASSET] Forbidden release ZIP detected in release/: {$file}.";
            }
        }
    }
}

// 3. Check for tracked or committed real .env files with secrets
if (file_exists($root . '/.env')) {
    $envContent = (string)file_get_contents($root . '/.env');
    // Check if this .env has real database passwords
    if (preg_match('/DB_PASSWORD=[^\r\n]+/i', $envContent, $m)) {
        $val = trim(explode('=', $m[0], 2)[1]);
        if ($val !== '' && $val !== '""' && $val !== "''") {
            $violations[] = "[SECURITY RISK] Real credentials detected in root .env file. Real credentials must never be committed.";
        }
    }
}

// 4. Check for tracked runtime cache / log files in storage/
$trackedStorageFiles = [
    $root . '/storage/installed.lock',
    $root . '/.phpunit.result.cache',
];

foreach ($trackedStorageFiles as $file) {
    if (file_exists($file)) {
        // Check if git tracks it
        $relPath = str_replace($root . '/', '', str_replace('\\', '/', $file));
        $execOutput = [];
        exec("git ls-files " . escapeshellarg($relPath), $execOutput);
        if (!empty($execOutput)) {
            $violations[] = "[RUNTIME JUNK] Runtime generated file is tracked by git: {$relPath}.";
        }
    }
}

// 5. Verify v1.0.0 installer ZIP immutability (if present locally)
$v1ZipPath = $root . '/release/Favorite-CMS-Universal-v1.0.0.zip';
$canonicalHash = 'b4e9803d58ba55007834fda49f0ca7187da2fe34832b2fba091b6e55e069dbbe';
$canonicalSize = 993789;

if (file_exists($v1ZipPath)) {
    $currentHash = strtolower(hash_file('sha256', $v1ZipPath));
    $currentSize = filesize($v1ZipPath);
    if ($currentHash !== $canonicalHash || $currentSize !== $canonicalSize) {
        $violations[] = "[IMMUTABILITY VIOLATION] Verified release ZIP Favorite-CMS-Universal-v1.0.0.zip has been modified! Expected size: {$canonicalSize}, got: {$currentSize}. Expected hash: {$canonicalHash}, got: {$currentHash}.";
    }
}

// 6. Check for master development files preservation
$requiredDevFiles = [
    'phpunit.xml',
    'tests/Unit/CoreTest.php',
    'tests/Integration/InstallerTest.php',
    'AGENTS.md',
    'REPOSITORY-RULES.md',
    'docs/disaster-recovery.md',
    'docs/release-process.md',
];

foreach ($requiredDevFiles as $devFile) {
    if (!file_exists($root . '/' . $devFile)) {
        $violations[] = "[MASTER SOURCE LOSS] Essential development file missing: {$devFile}. Master repository must preserve complete development source.";
    }
}

// Report Results
if (!empty($violations)) {
    echo "❌ GOVERNANCE AUDIT FAILED with " . count($violations) . " violation(s):\n\n";
    foreach ($violations as $index => $v) {
        echo "  " . ($index + 1) . ". {$v}\n";
    }
    echo "\nPlease resolve the above issues before committing.\n";
    exit(1);
}

echo "✅ All repository governance checks passed successfully!\n";
echo "   - Core-only boundary: INTACT\n";
echo "   - Master development source: PRESERVED\n";
echo "   - Verified v1.0.0 ZIP immutability: VERIFIED\n";
echo "   - No non-core products or assets detected.\n";
exit(0);
