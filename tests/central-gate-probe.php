<?php
/**
 * Disposable-sandbox probe: records an unregistered site whose first registration failed with a
 * NON-temporary error, then asks the real public gate whether visitors may see the site.
 * Prints one JSON line. Never contacts the network.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('CMS_TEST_ROOT');
if (!$root || !is_file($root . '/.cms-test-sandbox')) throw new RuntimeException('CMS_TEST_ROOT must be a marked disposable sandbox.');
define('APP_ROOT', realpath($root));
require APP_ROOT . '/bootstrap.php';

use FavoriteCMS\Central\Bridge;
use FavoriteCMS\Core\Request;

$dir = APP_ROOT . '/storage/favorite-central-client';
if (!is_dir($dir)) mkdir($dir, 0700, true);
// CMS_TEST_AGE_HOURS: pretend the site has been unregistered for N hours (file + database clock).
// CMS_TEST_DELETE_CLIENT=1: a customer deletes the connection file to restart the clock.
$age = getenv('CMS_TEST_AGE_HOURS');
$since = $age !== false && $age !== '' ? time() - (int)((float)$age * 3600) : null;
if ($since !== null) FavoriteCMS\Models\Setting::set('central', 'pending_since', (string)$since);
$record = [
    'installation_id' => str_repeat('a', 32),
    'registration_error_code' => getenv('CMS_TEST_ERROR_CODE') ?: 'authority_certificate_required',
    'registration_last_attempt' => time(),
    'registration_last_error' => 'simulated registration failure',
];
if ($since !== null) $record['pending_since'] = $since;
if (getenv('CMS_TEST_DELETE_CLIENT') === '1') { @unlink($dir . '/client.json'); }
else file_put_contents($dir . '/client.json', json_encode($record));
$integrity = FavoriteCMS\Security\CoreIntegrity::report(APP_ROOT);
$response = Bridge::policyResponse(Request::create('GET', '/'));
$status = null;
if ($response !== null) { $p = new ReflectionProperty($response, 'status'); $status = $p->getValue($response); }
echo json_encode(['version' => APP_VERSION, 'integrity' => $integrity['status'], 'public_site_blocked' => $response !== null, 'http_status' => $status]), PHP_EOL;
