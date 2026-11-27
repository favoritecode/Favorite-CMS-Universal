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
file_put_contents($dir . '/client.json', json_encode([
    'installation_id' => str_repeat('a', 32),
    'registration_error_code' => getenv('CMS_TEST_ERROR_CODE') ?: 'authority_certificate_required',
    'registration_last_attempt' => time(),
    'registration_last_error' => 'simulated registration failure',
]));
$integrity = FavoriteCMS\Security\CoreIntegrity::report(APP_ROOT);
$response = Bridge::policyResponse(Request::create('GET', '/'));
$status = null;
if ($response !== null) { $p = new ReflectionProperty($response, 'status'); $status = $p->getValue($response); }
echo json_encode(['version' => APP_VERSION, 'integrity' => $integrity['status'], 'public_site_blocked' => $response !== null, 'http_status' => $status]), PHP_EOL;
