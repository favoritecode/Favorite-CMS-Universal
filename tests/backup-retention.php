<?php
/**
 * Backup retention + manual multi-delete. Disposable installed sandbox only (CMS_TEST_ROOT + local DB).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('CMS_TEST_ROOT');
if (!$root || !is_file($root . '/.cms-test-sandbox') || realpath($root) === realpath(dirname(__DIR__))) throw new RuntimeException('Set CMS_TEST_ROOT to a marked disposable installed sandbox.');
define('APP_ROOT', realpath($root)); require APP_ROOT . '/bootstrap.php';
use FavoriteCMS\Services\BackupService;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Models\Setting;

$results = []; $failed = 0;
function t(string $n, callable $f): void { global $results, $failed; try { $f(); $results[] = "PASS  $n"; } catch (Throwable $e) { $failed++; $results[] = "FAIL  $n :: " . $e->getMessage(); } }
function ok(bool $c, string $m = 'assertion failed'): void { if (!$c) throw new RuntimeException($m); }
$dir = APP_ROOT . '/storage/backups'; if (!is_dir($dir)) mkdir($dir, 0700, true);
$clear = function () use ($dir) { foreach (glob($dir . '/*') ?: [] as $f) if (is_file($f)) @unlink($f); };
$make = function (string $name, int $ageDays, string $ext = '') use ($dir): string { $p = $dir . '/' . $name . $ext; file_put_contents($p, 'x'); touch($p, time() - $ageDays * 86400 - 60); return $name . $ext; };
$names = fn() => array_map('basename', glob($dir . '/favorite_cms_backup_*.zip') ?: []);
$svc = new BackupService();
foreach (['auto_cleanup' => '1', 'keep_latest' => '3', 'max_age_days' => '30'] as $k => $v) Setting::set('backup', $k, $v);

t('keeps the newest 3 ALWAYS and anything younger than 30 days; deletes older extras', function () use ($clear, $make, $names, $svc) {
    $clear(); foreach ([1, 2, 3, 10, 31, 40, 60, 90] as $i => $age) $make("favorite_cms_backup_t{$i}_d{$age}", $age, '.zip');
    $r = $svc->applyRetention(); $left = $names();
    ok(count($r['deleted']) === 4 && count($left) === 4, 'deleted ' . count($r['deleted']) . ', left ' . implode(',', $left));
    foreach ([1, 2, 3, 10] as $age) ok(in_array("favorite_cms_backup_t" . array_search($age, [1, 2, 3, 10, 31, 40, 60, 90]) . "_d{$age}.zip", $left, true), "d{$age} must be kept");
});
t('even when EVERY backup is old, the newest 3 survive (never leaves the site without a backup)', function () use ($clear, $make, $names, $svc) {
    $clear(); foreach ([100, 120, 140, 160, 180] as $i => $age) $make("favorite_cms_backup_o{$i}", $age, '.zip');
    $svc->applyRetention(); $left = $names(); sort($left);
    ok($left === ['favorite_cms_backup_o0.zip', 'favorite_cms_backup_o1.zip', 'favorite_cms_backup_o2.zip'], implode(',', $left));
});
t('fewer than 3 backups: nothing is deleted, however old', function () use ($clear, $make, $names, $svc) {
    $clear(); $make('favorite_cms_backup_a', 400, '.zip'); $make('favorite_cms_backup_b', 500, '.zip');
    $r = $svc->applyRetention(); ok($r['deleted'] === [] && count($names()) === 2);
});
t('abandoned temp files are removed after a day; a fresh partial (running backup) is untouched', function () use ($clear, $make, $dir, $svc) {
    $clear(); $make('favorite_cms_backup_x', 0, '.zip');
    $make('favorite_cms_backup_old.zip', 3, '.partial'); $make('dump_old', 3, '.sql'); $make('database-export-old', 3, '.sql'); $make('favorite_cms_backup_live.zip', 0, '.partial');
    $svc->applyRetention();
    ok(!is_file($dir . '/favorite_cms_backup_old.zip.partial') && !is_file($dir . '/dump_old.sql') && !is_file($dir . '/database-export-old.sql'), 'stale temp files must go');
    ok(is_file($dir . '/favorite_cms_backup_live.zip.partial'), 'fresh partial must stay'); ok(is_file($dir . '/favorite_cms_backup_x.zip'));
});
t('unrelated files in the backup directory are never touched', function () use ($clear, $make, $dir, $svc) {
    $clear(); foreach ([1, 2, 3, 90, 91] as $i => $a) $make("favorite_cms_backup_u$i", $a, '.zip'); $make('notes', 400, '.txt'); $make('keepme', 400, '.zip');
    $svc->applyRetention(); ok(is_file($dir . '/notes.txt') && is_file($dir . '/keepme.zip'));
});
t('a new backup triggers the cleanup automatically (newest kept, old extras gone)', function () use ($clear, $make, $names, $svc) {
    $clear(); foreach ([1, 2, 3, 45, 50] as $i => $a) $make("favorite_cms_backup_n$i", $a, '.zip');
    $r = $svc->createBackup(['include_media' => false]); $left = $names();
    ok(in_array($r['filename'], $left, true), 'new backup must exist'); ok(!in_array('favorite_cms_backup_n3.zip', $left, true) && !in_array('favorite_cms_backup_n4.zip', $left, true), 'old extras must be gone: ' . implode(',', $left));
});
t('policy can be switched off and tuned in Settings', function () use ($clear, $make, $names, $svc) {
    $clear(); foreach ([1, 2, 3, 45, 50] as $i => $a) $make("favorite_cms_backup_p$i", $a, '.zip');
    Setting::set('backup', 'auto_cleanup', '0'); ok($svc->maybeApplyRetention(true) === null && count($names()) === 5, 'disabled policy must not delete');
    Setting::set('backup', 'auto_cleanup', '1'); Setting::set('backup', 'keep_latest', '5'); $svc->applyRetention(); ok(count($names()) === 5, 'keep_latest=5 keeps all five');
    Setting::set('backup', 'keep_latest', '3');
});
t('manual multi-select delete: valid names deleted, unsafe names rejected', function () use ($clear, $make, $names, $svc, $dir) {
    $clear(); foreach (['a', 'b', 'c', 'd'] as $i => $n) $make("favorite_cms_backup_m$n", 1, '.zip'); file_put_contents($dir . '/../outside.txt', 'x');
    $r = $svc->deleteBackups(['favorite_cms_backup_ma.zip', 'favorite_cms_backup_mb.zip', '../outside.txt', 'favorite_cms_backup_../../x.zip', 'nope.zip', 'favorite_cms_backup_missing.zip']);
    ok(count($r['deleted']) === 2 && count($names()) === 2, json_encode($r)); ok(is_file($dir . '/../outside.txt'), 'files outside the backup dir must be untouched'); @unlink($dir . '/../outside.txt');
});
t('controller: bulk-delete requires the CSRF token and works with files[]', function () use ($clear, $make, $names, $app) {
    $clear(); foreach (['a', 'b', 'c'] as $n) $make("favorite_cms_backup_c$n", 1, '.zip');
    $_SESSION = ['_token' => 'tok', 'auth_user_id' => 1]; $ctl = new FavoriteCMS\Http\Controllers\Admin\ToolController($app);
    try { $ctl->deleteSelectedBackups(Request::create('POST', '/admin/tools/backup/delete-selected', ['files' => ['favorite_cms_backup_ca.zip']])); $bad = false; } catch (Throwable) { $bad = true; }
    ok($bad || count($names()) === 3, 'must not delete without a valid CSRF token');
    $ctl->deleteSelectedBackups(Request::create('POST', '/admin/tools/backup/delete-selected', ['_token' => 'tok', 'files' => ['favorite_cms_backup_ca.zip', 'favorite_cms_backup_cb.zip']]));
    ok($names() === ['favorite_cms_backup_cc.zip'], implode(',', $names()));
});
t('Tools page renders checkboxes, bulk button and the policy text', function () use ($clear, $make, $svc) {
    $clear(); foreach (['a', 'b'] as $n) $make("favorite_cms_backup_v$n", 1, '.zip');
    $backups = $svc->getBackups(); $csrfToken = 't'; $diagnostics = []; $notice = $error = null; $bloggerPreview = null; $bloggerToken = ''; $backupPolicy = $svc->retentionPolicy();
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); $pageTitle = 'x';
    ob_start(); include APP_ROOT . '/resources/views/admin/tools/index.php'; $html = ob_get_clean();
    ok(substr_count($html, 'class="bk-pick"') === 2 && str_contains($html, 'id="bk-delete-selected"') && str_contains($html, 'delete-selected') && str_contains($html, 'newest <strong>3</strong>') && str_contains($html, 'backup/cleanup'), 'view incomplete');
});
$clear();
echo implode(PHP_EOL, $results), PHP_EOL, $failed ? "$failed FAILED" : 'ALL PASSED', PHP_EOL; exit($failed ? 1 : 0);
