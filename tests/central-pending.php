<?php
declare(strict_types=1);
/**
 * Regression test: a site must keep running while its Central registration is pending.
 * Only a publisher-signed Owner decision may restrict a site. No network access is used.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$source = dirname(__DIR__);
require $source . '/vendor/autoload.php';

use FavoriteCMS\Central\Authority;
use FavoriteCMS\Central\Client;

$results = [];
$failed = 0;
function check(string $name, callable $run): void {
    global $results, $failed;
    try { $run(); $results[] = "PASS  $name"; }
    catch (Throwable $e) { $failed++; $results[] = "FAIL  $name :: " . $e->getMessage(); }
}
function ok(bool $c, string $m = 'assertion failed'): void { if (!$c) throw new RuntimeException($m); }
function freshRoot(array $config = null): string {
    $root = sys_get_temp_dir() . '/fc-pending-' . bin2hex(random_bytes(6));
    mkdir($root . '/storage/favorite-central-client', 0700, true);
    if ($config !== null) file_put_contents($root . '/storage/favorite-central-client/client.json', json_encode($config));
    return $root;
}
const RUNNING = ['active', 'warning', 'registration_pending']; // states Bridge::policyResponse lets through

check('never-registered site keeps running (no config at all)', function () {
    $p = (new Client(freshRoot()))->policy();
    ok($p['policy_status'] === 'registration_pending', 'got ' . $p['policy_status']);
    ok(in_array($p['policy_status'], RUNNING, true));
});

check('verification/authority failure no longer blocks the public site', function () {
    foreach (['authority_certificate_required', 'hosting_browser_challenge', 'verification_failure', 'domain_mismatch', 'untrusted_authority'] as $code) {
        $root = freshRoot(['installation_id' => str_repeat('a', 32), 'registration_error_code' => $code, 'registration_last_attempt' => time(), 'registration_last_error' => 'Reason for ' . $code]);
        $p = (new Client($root))->policy();
        ok($p['policy_status'] === 'registration_pending', "$code => " . $p['policy_status']);
        ok(str_contains($p['policy_reason'], $code), 'reason must be surfaced for ' . $code);
        ok($p['policy_error_code'] === $code);
    }
});

check('registered legacy site (secret, no signed state yet) with unreachable server keeps running', function () {
    $root = freshRoot(['installation_id' => str_repeat('b', 32), 'secret' => 'x', 'server_url' => Authority::ENDPOINT, 'policy_retry_after' => time() + 600]);
    $p = (new Client($root))->policy();
    ok($p['policy_status'] === 'registration_pending', 'got ' . $p['policy_status']);
});

check('site with non-official server URL and no signed state keeps running', function () {
    $root = freshRoot(['installation_id' => str_repeat('c', 32), 'secret' => 'x', 'server_url' => 'https://example.invalid/api']);
    $p = (new Client($root))->policy();
    ok($p['policy_status'] === 'registration_pending', 'got ' . $p['policy_status']);
});

check('forged/invalid cached policy is still rejected (tamper protection kept)', function () {
    $root = freshRoot(['installation_id' => str_repeat('d', 32), 'secret' => 'x', 'server_url' => Authority::ENDPOINT, 'policy_retry_after' => time() + 600,
        'policy_envelope' => ['payload' => ['installation_id' => str_repeat('d', 32), 'data' => ['policy_status' => 'active']], 'key_id' => 'x', 'signature' => 'AAAA']]);
    $p = (new Client($root))->policy();
    ok($p['policy_status'] === 'connection_required', 'forged "active" must NOT be accepted, got ' . $p['policy_status']);
});

check('grace is bounded: still pending at 71h, restricted after 72h', function () {
    $cfg = ['installation_id' => str_repeat('9', 32), 'registration_error_code' => 'hosting_browser_challenge', 'registration_last_error' => 'Hosting challenge detail'];
    $p = (new Client(freshRoot($cfg + ['pending_since' => time() - 71 * 3600])))->policy();
    ok($p['policy_status'] === 'registration_pending' && $p['grace_seconds_left'] > 0 && $p['grace_seconds_left'] <= 3600, 'at 23h: ' . json_encode($p));
    $p = (new Client(freshRoot($cfg + ['pending_since' => time() - 72 * 3600 - 5])))->policy();
    ok($p['policy_status'] === 'connection_required', 'after 24h must be restricted, got ' . $p['policy_status']);
    ok(!str_contains($p['policy_reason'], 'Hosting challenge'), 'public reason must be generic');
    ok(str_contains($p['policy_detail'], 'Hosting challenge'), 'admin detail kept');
});

check('first observation starts the clock and persists it', function () {
    $root = freshRoot();
    $c = new Client($root);
    $c->policy();
    $stored = (int)(($c->config())['pending_since'] ?? 0);
    ok($stored > time() - 5 && $stored <= time(), 'pending_since must be stored on first observation');
    sleep(1);
    $c->policy();
    ok((int)(($c->config())['pending_since']) === $stored, 'clock must not restart');
});

check('registered site with a verified signed state is never put on the grace clock', function () {
    $n = (new Client(freshRoot(['installation_id' => str_repeat('3', 32), 'secret' => 'x', 'registration_verified_at' => time() - 90000, 'welcome_shown_at' => 1])))->adminNotice();
    ok($n['pending'] === false && $n['restricted'] === false && $n['grace_hours_left'] === 0);
});

check('admin notice: pending shows reason and remaining grace, never welcome', function () {
    $n = (new Client(freshRoot(['installation_id' => str_repeat('e', 32), 'registration_error_code' => 'temporary_network_failure', 'registration_last_error' => '<b>Network</b> down'])))->adminNotice();
    ok($n['pending'] === true && $n['welcome'] === false);
    ok($n['code'] === 'temporary_network_failure');
    ok(!str_contains($n['reason'], '<'), 'tags must be stripped');
});

check('welcome dialog appears once after a fresh registration', function () {
    $root = freshRoot(['installation_id' => str_repeat('f', 32), 'secret' => 'x', 'registration_verified_at' => time() - 60]);
    $c = new Client($root);
    ok($c->adminNotice()['welcome'] === true, 'should welcome');
    $c->markWelcomeShown();
    $n = $c->adminNotice();
    ok($n['welcome'] === false && $n['pending'] === false, 'must not repeat');
});

check('existing long-registered sites are not greeted after an update', function () {
    $root = freshRoot(['installation_id' => str_repeat('1', 32), 'secret' => 'x', 'connected_at' => gmdate('c', time() - 86400 * 30)]);
    $n = (new Client($root))->adminNotice();
    ok($n['welcome'] === false && $n['pending'] === false);
});

check('owner (server) installation never sees customer notices', function () {
    $root = freshRoot(['installation_id' => str_repeat('2', 32)]);
    mkdir($root . '/favorite-server-license', 0755, true);
    file_put_contents($root . '/favorite-server-license/bootstrap.php', '<?php');
    $n = (new Client($root))->adminNotice();
    ok($n['pending'] === false && $n['welcome'] === false);
});

echo implode(PHP_EOL, $results), PHP_EOL, ($failed ? "$failed FAILED" : 'ALL PASSED'), PHP_EOL;
exit($failed ? 1 : 0);
