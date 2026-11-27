<?php
/**
 * Administrators can SEE the license state of plugins and themes (free trial days left, license needed, licensed until)
 * and are told how to buy. No database needed.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('APP_ROOT', dirname(__DIR__)); require APP_ROOT . '/vendor/autoload.php';
use FavoriteCMS\Central\Client;

$results = []; $failed = 0;
function t(string $n, callable $f): void { global $results, $failed; try { $f(); $results[] = "PASS  $n"; } catch (Throwable $e) { $failed++; $results[] = "FAIL  $n :: " . $e->getMessage(); } }
function ok(bool $c, string $m = 'assertion failed'): void { if (!$c) throw new RuntimeException($m); }
$in = fn(int $days) => gmdate('Y-m-d H:i:s', time() + $days * 86400 - 600);

t('free trial: days left, end date, Buy link; turns amber in the last 2 days', function () use ($in) {
    $s = Client::describeLicense(['allowed' => true, 'mode' => 'trial', 'valid_until' => $in(5)]);
    ok($s['managed'] && !$s['blocked'] && $s['label'] === 'Free trial' && $s['short'] === 'Free trial · 5 days left' && $s['days_left'] === 5 && $s['buy'] === true && $s['tone'] === 'info' && str_starts_with($s['detail'], 'Ends ') && !str_contains($s['detail'], 'days left'), json_encode($s));
    $w = Client::describeLicense(['allowed' => true, 'mode' => 'trial', 'valid_until' => $in(1)]); ok($w['tone'] === 'warn' && $w['short'] === 'Free trial · 1 day left', json_encode($w));
});
t('inactive managed extension: "free trial available" (trial not started yet)', function () { $s = Client::describeLicense(['allowed' => false, 'reason' => 'trial_not_started']); ok($s['managed'] && $s['blocked'] && $s['label'] === 'Free trial available' && $s['buy'] === true && str_contains($s['detail'], 'starts when you activate'), json_encode($s)); });
t('trial ended: "License needed", blocked, Buy link, red', function () { $s = Client::describeLicense(['allowed' => false, 'reason' => 'purchase_required']); ok($s['blocked'] && $s['label'] === 'License needed' && $s['buy'] && $s['tone'] === 'bad' && str_contains($s['detail'], 'free trial has ended'), json_encode($s)); });
t('licensed: until date (or lifetime), Manage link, green', function () use ($in) {
    $s = Client::describeLicense(['allowed' => true, 'mode' => 'paid', 'valid_until' => $in(300)]); ok($s['label'] === 'Licensed' && $s['manage'] && !$s['buy'] && $s['tone'] === 'ok' && str_contains($s['detail'], 'Valid until'), json_encode($s));
    $l = Client::describeLicense(['allowed' => true, 'mode' => 'paid', 'valid_until' => null]); ok($l['detail'] === 'Lifetime license.', json_encode($l));
});
t('expired license, owner access, free, connection problems, restriction', function () {
    ok(Client::describeLicense(['allowed' => false, 'reason' => 'license_expired'])['label'] === 'License expired');
    ok(Client::describeLicense(['allowed' => true, 'mode' => 'owner', 'valid_until' => null])['label'] === 'Owner access');
    ok(Client::describeLicense(['allowed' => true, 'mode' => 'free'])['label'] === 'Free');
    ok(Client::describeLicense(['allowed' => false, 'reason' => 'central_connection_required'])['label'] === 'Connect to manage license');
    ok(Client::describeLicense(['allowed' => false, 'reason' => 'central_temporarily_unavailable'])['label'] === 'License check unavailable');
    ok(Client::describeLicense(['allowed' => false, 'reason' => 'policy_suspension'])['label'] === 'Restricted');
});
t('a free, unmanaged extension shows nothing', function () { ok(Client::describeLicense(['allowed' => true, 'mode' => 'legacy_free']) === ['managed' => false]); });
t('badge partial: label, detail and a Buy link to Site & add-ons focused on this extension', function () use ($in) {
    $lic = Client::describeLicense(['allowed' => true, 'mode' => 'trial', 'valid_until' => $in(4)]); $buyUrl = '/admin/page/central-connection?tab=plugins&focus=favorite-pay';
    ob_start(); include APP_ROOT . '/resources/views/admin/partials/license-badge.php'; $h = ob_get_clean();
    ok(str_contains($h, 'Free trial') && str_contains($h, '4 days left') && str_contains($h, 'Buy a license') && str_contains($h, 'focus=favorite-pay') && str_contains($h, 'var(--admin-info-bg)'), $h);
    $lic = ['managed' => false]; ob_start(); include APP_ROOT . '/resources/views/admin/partials/license-badge.php'; ok(trim(ob_get_clean()) === '', 'unmanaged must render nothing');
});
t('badge partial escapes everything', function () { $lic = ['managed' => true, 'tone' => 'bad', 'label' => '<script>x</script>', 'detail' => '"><img src=x>', 'buy' => true]; $buyUrl = '"><b>'; ob_start(); include APP_ROOT . '/resources/views/admin/partials/license-badge.php'; $h = ob_get_clean(); ok(!str_contains($h, '<script>') && !str_contains($h, '<img') && !str_contains($h, '"><b>')); });
t('Plugins page: blocked-but-active plugin is flagged "License needed" with a Buy link, trial shows days left', function () use ($in) {
    $plugins = ['paid-one' => ['name' => 'Paid One', 'version' => '1.0.0', 'author' => 'A', 'description' => 'd', 'active' => true, 'errors' => [], 'dependencies' => []], 'trial-one' => ['name' => 'Trial One', 'version' => '1.0.0', 'author' => 'A', 'description' => 'd', 'active' => true, 'errors' => [], 'dependencies' => []], 'free-one' => ['name' => 'Free One', 'version' => '1.0.0', 'author' => 'A', 'description' => 'd', 'active' => false, 'errors' => [], 'dependencies' => []]];
    $licenses = ['paid-one' => Client::describeLicense(['allowed' => false, 'reason' => 'purchase_required']), 'trial-one' => Client::describeLicense(['allowed' => true, 'mode' => 'trial', 'valid_until' => $in(6)])];
    $bootErrors = []; $csrfToken = 't'; $uploadError = null;
    if (!function_exists('site_base_path')) { function site_base_path(): string { return ''; } }
    ob_start(); include APP_ROOT . '/resources/views/admin/plugins/index.php'; $h = ob_get_clean();
    ok(substr_count($h, 'License needed') >= 2, 'status badge + license block'); ok(str_contains($h, '6 days left') && substr_count($h, 'Buy a license') >= 2 && str_contains($h, 'focus=paid-one') && str_contains($h, 'focus=trial-one'), 'trial/buy missing');
    ok(!str_contains($h, 'focus=free-one'), 'free plugin must show no license block');
});
echo implode(PHP_EOL, $results), PHP_EOL, $failed ? "$failed FAILED" : 'ALL PASSED', PHP_EOL; exit($failed ? 1 : 0);
