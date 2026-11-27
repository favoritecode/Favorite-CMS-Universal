<?php
declare(strict_types=1);
/**
 * Regression tests for the 2.0.0 review fixes. No database and no network.
 * Usage: php -d extension=sodium -d extension=mbstring tests/review-fixes.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$source = dirname(__DIR__);
require $source . '/vendor/autoload.php';

use FavoriteCMS\Central\Client;

$results = []; $failed = 0;
function check(string $name, callable $run): void { global $results, $failed; try { $run(); $results[] = "PASS  $name"; } catch (Throwable $e) { $failed++; $results[] = "FAIL  $name :: " . $e->getMessage(); } }
function ok(bool $c, string $m = 'assertion failed'): void { if (!$c) throw new RuntimeException($m); }
function root(?array $config = null): string {
    $r = sys_get_temp_dir() . '/fc-review-' . bin2hex(random_bytes(6));
    mkdir($r . '/storage/favorite-central-client', 0700, true);
    if ($config !== null) file_put_contents($r . '/storage/favorite-central-client/client.json', json_encode($config));
    return $r;
}
function read(string $r): array { return json_decode((string)file_get_contents($r . '/storage/favorite-central-client/client.json'), true) ?: []; }

check('a signed decision ends the pending clock; without one the clock keeps counting (the 3-day grace still expires)', function () {
    $r = root(['pending_since' => time() - 3600, 'secret' => 's', 'installation_id' => 'i', 'connected_at' => gmdate('c')]);
    $c = new Client($r); $cfg = $c->config(); $cfg['touched'] = 1; $c->save($cfg);
    ok(isset(read($r)['pending_since']), 'no signed decision yet: the clock must stay');
    $cfg = $c->config(); $cfg['policy_envelope'] = ['x' => 1]; $c->save($cfg);
    ok(!isset(read($r)['pending_since']), 'signed decision: the clock is cleared');
});

check('the congratulations dialog is for a new registration, not for a site that was merely re-verified this week', function () {
    $old = root(['secret' => 's', 'installation_id' => 'i', 'connected_at' => gmdate('c', time() - 90 * 86400), 'registry_verified_at' => time() - 600, 'policy_envelope' => ['x' => 1]]);
    ok((new Client($old))->adminNotice()['welcome'] === false, 'old site re-verified a minute ago must not get the welcome dialog');
    $new = root(['secret' => 's', 'installation_id' => 'i', 'connected_at' => gmdate('c', time() - 60), 'registration_verified_at' => time() - 60, 'policy_envelope' => ['x' => 1]]);
    ok((new Client($new))->adminNotice()['welcome'] === true, 'a site registered a minute ago gets it');
});

check('recovery stays reachable: every backup button on the Tools page works while the site is paused', function () {
    foreach (['/admin/tools/backup/create', '/admin/tools/backup/download', '/admin/tools/backup/delete', '/admin/tools/backup/delete-selected', '/admin/tools/backup/cleanup', '/admin/tools/restore'] as $p) ok(\FavoriteCMS\Central\Bridge::recoveryAllowed($p), $p);
    ok(!\FavoriteCMS\Central\Bridge::recoveryAllowed('/admin/posts') && !\FavoriteCMS\Central\Bridge::recoveryAllowed('/admin/tools/backup/delete-selected/../../posts'), 'nothing else opens up');
});

check('browser time zone aliases are accepted instead of silently becoming UTC', function () {
    foreach (['Asia/Dhaka', 'Asia/Calcutta', 'Asia/Katmandu', 'Asia/Saigon', 'Europe/Kiev', 'UTC'] as $z) ok(\FavoriteCMS\Core\DateTime::isValidTimezone($z), $z);
    foreach (['', 'Mars/Olympus', '../etc', 'Asia/Dhaka; DROP'] as $z) ok(!\FavoriteCMS\Core\DateTime::isValidTimezone($z), "must refuse '$z'");
});

check('templates: no stray backslash in the backup table class, no hard-coded UTC header, notice is admin-only', function () use ($source) {
    $tools = (string)file_get_contents($source . '/resources/views/admin/tools/index.php');
    ok(!str_contains($tools, 'wp-table\\"'), 'stray backslash');
    ok(!str_contains((string)file_get_contents($source . '/resources/views/admin/users/security.php'), 'Time (UTC)'), 'UTC header');
    ok(str_contains((string)file_get_contents($source . '/resources/views/admin/partials/central-notice.php'), "current_user_can('manage_options')"), 'admin-only notice');
    $plugins = (string)file_get_contents($source . '/resources/views/admin/plugins/index.php');
    ok(str_contains($plugins, "\$licenses[\$id]['label']"), 'badge shows the real state, not always "License needed"');
});

check('backup retention floor cannot drop below the newest 3', function () use ($source) {
    ok(str_contains((string)file_get_contents($source . '/app/Services/BackupService.php'), 'max(self::KEEP_LATEST, (int)Setting::get('), 'floor');
});

echo implode(PHP_EOL, $results), PHP_EOL, $failed ? "$failed FAILED" : 'ALL PASSED', PHP_EOL; exit($failed ? 1 : 0);
