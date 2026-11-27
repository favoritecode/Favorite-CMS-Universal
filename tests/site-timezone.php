<?php
/**
 * Site time zone: stored times are UTC, every displayed time follows Settings -> Site Time Zone.
 * Part 1 is static (no database). Part 2 needs a marked, installed, disposable sandbox (CMS_TEST_ROOT + local DB).
 * Run with a hostile host setting to prove independence:  php -d date.timezone=Asia/Tokyo tests/site-timezone.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$results = []; $failed = 0;
function t(string $n, callable $f): void { global $results, $failed; try { $f(); $results[] = "PASS  $n"; } catch (Throwable $e) { $failed++; $results[] = "FAIL  $n :: " . $e->getMessage(); } }
function ok(bool $c, string $m = 'assertion failed'): void { if (!$c) throw new RuntimeException($m); }
$source = dirname(__DIR__);

t('no view or theme prints server-time date()/strtotime() output (all must use format_date)', function () use ($source) {
    $bad = [];
    foreach (['resources', 'themes'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            foreach (file($f->getPathname()) as $n => $line) if (preg_match('/(?<![\w>:$])date\s*\(|(?<![\w>:$])strtotime\s*\(|gmdate\s*\(/', $line)) $bad[] = substr($f->getPathname(), strlen($source) + 1) . ':' . ($n + 1);
        }
    }
    ok(!$bad, 'raw date functions in views: ' . implode(', ', $bad));
});

$root = getenv('CMS_TEST_ROOT');
if ($root && is_file($root . '/.cms-test-sandbox') && realpath($root) !== realpath($source)) {
    define('APP_ROOT', realpath($root)); require APP_ROOT . '/bootstrap.php';
    $set = fn(string $tz) => FavoriteCMS\Models\Setting::set('general', 'timezone', $tz);
    t('PHP default time zone is forced to UTC (ignores hosting php.ini)', fn() => ok(date_default_timezone_get() === 'UTC', date_default_timezone_get()));
    t('Asia/Dhaka: stored UTC 14:41:57 is shown as 20:41:57', function () use ($set) { $set('Asia/Dhaka'); ok(format_date('2026-11-27 14:41:57', 'Y-m-d H:i:s') === '2026-11-27 20:41:57', format_date('2026-11-27 14:41:57', 'Y-m-d H:i:s')); });
    t('epoch and ISO-8601 inputs are converted too', function () use ($set) { $set('Asia/Dhaka'); ok(format_date(1795876917, 'H:i') === gmdate('H:i', 1795876917 + 6 * 3600)); ok(format_date('2026-11-27T14:41:57Z', 'H:i') === '20:41'); ok(format_date('2026-11-27T14:41:57+00:00', 'H:i') === '20:41'); });
    t('America/New_York (UTC-5 in November)', function () use ($set) { $set('America/New_York'); ok(format_date('2026-11-27 14:41:57', 'H:i') === '09:41'); });
    t('changing the setting changes every display immediately; invalid value falls back to UTC', function () use ($set) { $set('Asia/Tokyo'); ok(format_date('2026-11-27 14:41:57', 'H:i') === '23:41'); $set('Not/AZone'); ok(format_date('2026-11-27 14:41:57', 'H:i') === '14:41'); });
    t('new backup name and listing use the site time zone', function () use ($set) {
        $set('Asia/Dhaka'); $svc = new FavoriteCMS\Services\BackupService(); $r = $svc->createBackup(['include_media' => false]);
        ok(preg_match('/favorite_cms_backup_(\d{4}-\d{2}-\d{2})_(\d{6})_/', $r['filename'], $m) === 1);
        $named = DateTimeImmutable::createFromFormat('Y-m-d His', $m[1] . ' ' . $m[2], new DateTimeZone('Asia/Dhaka'))->getTimestamp();
        ok(abs($named - time()) < 180, 'backup file name must carry Dhaka time, off by ' . ($named - time()) . 's');
        $found = array_values(array_filter($svc->getBackups(), fn($b) => $b['filename'] === $r['filename']));
        ok($found && abs($found[0]['date'] - time()) < 180);
        @unlink($r['path']);
    });
    t('scheduled publication input is read in site time and stored as UTC', function () use ($set) {
        $set('Asia/Dhaka'); $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', '2030-01-01T09:00', FavoriteCMS\Core\DateTime::getTimezoneObject());
        ok($d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') === '2030-01-01 03:00:00');
        ok(format_date('2030-01-01 03:00:00', 'Y-m-d\TH:i') === '2030-01-01T09:00', 'round trip into the datetime-local field');
    });
    $set('Asia/Dhaka');
} else $results[] = 'SKIP  runtime checks (set CMS_TEST_ROOT to a marked installed sandbox)';
echo implode(PHP_EOL, $results), PHP_EOL, $failed ? "$failed FAILED" : 'ALL PASSED', PHP_EOL; exit($failed ? 1 : 0);
