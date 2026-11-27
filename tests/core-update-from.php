<?php
/**
 * Run the INSTALLED (old) updater against a release ZIP on a disposable sandbox + local MySQL/MariaDB.
 * Env: CMS_TEST_ROOT (marked sandbox of the OLD release), CMS_TEST_UPDATE_ZIP, CMS_TEST_FROM_VERSION,
 *      CMS_TEST_TO_VERSION, CMS_TEST_DB_PORT/USER/PASSWORD.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('CMS_TEST_ROOT'); $zip = getenv('CMS_TEST_UPDATE_ZIP');
$from = getenv('CMS_TEST_FROM_VERSION') ?: ''; $to = getenv('CMS_TEST_TO_VERSION') ?: '';
if (!$root || !is_file($root . '/.cms-test-sandbox') || !$zip || !is_file($zip) || realpath($root) === realpath(dirname(__DIR__)))
    throw new RuntimeException('Set a marked disposable old-CMS copy and update ZIP.');
define('APP_ROOT', realpath($root)); $app = require APP_ROOT . '/bootstrap.php';
if ($from === '') $from = APP_VERSION;
if ($to === '' || APP_VERSION !== $from) throw new RuntimeException('Sandbox must be an untouched ' . $from . ' copy (found ' . APP_VERSION . ').');
use FavoriteCMS\Core\Database; use FavoriteCMS\Models\Setting;
$config = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => getenv('CMS_TEST_DB_PORT') ?: '3306', 'username' => getenv('CMS_TEST_DB_USER') ?: 'root', 'password' => getenv('CMS_TEST_DB_PASSWORD') ?: '', 'charset' => 'utf8mb4', 'prefix' => 'upgrade_'];
$server = new PDO('mysql:host=' . $config['host'] . ';port=' . $config['port'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'favorite_upgrade_' . bin2hex(random_bytes(6)); $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4');
$config['database'] = $name;
(new FavoriteCMS\Installer\InstallationService($app, new FavoriteCMS\Installer\DatabaseProvisioner(), new FavoriteCMS\Installer\InstallationStateManager()))
    ->install($config, ['name' => 'Existing website', 'url' => 'http://127.0.0.1:8767'], ['username' => 'old_admin', 'name' => 'Existing Admin', 'email' => 'old@example.test', 'password' => 'Local-Upgrade-Only-2026!']);
$db = $app->make(Database::class); $_SESSION = ['auth_user_id' => 1, 'auth_user_name' => 'Existing Admin'];
$db->insert('posts', ['title' => 'Existing article', 'slug' => 'existing-article', 'content' => '<p style="color:#c026d3">Keep this exact content</p>', 'author_id' => 1, 'status' => 'published', 'type' => 'post']);
$db->insert('pages', ['title' => 'Existing page', 'slug' => 'existing-page', 'content' => '<p>Existing page</p>', 'author_id' => 1, 'status' => 'published']);
Setting::set('theme_mods_default', 'accent_color', '#dc2626');
mkdir(APP_ROOT . '/themes/custom-keep'); file_put_contents(APP_ROOT . '/themes/custom-keep/index.php', '<?php // preserve custom theme');
mkdir(APP_ROOT . '/plugins/custom-keep'); file_put_contents(APP_ROOT . '/plugins/custom-keep/plugin.php', '<?php // preserve custom plugin');
file_put_contents(APP_ROOT . '/public/uploads/keep-original.txt', 'original-upload');
$envHash = hash_file('sha256', APP_ROOT . '/.env');
$posts = $db->select('SELECT * FROM `posts` ORDER BY id'); $users = $db->select('SELECT * FROM `users` ORDER BY id'); $pages = $db->select('SELECT * FROM `pages` ORDER BY id');
// A newer release may ADD columns; every value that existed before the update must be unchanged.
$same = static function (array $before, array $after): bool {
    if (count($before) !== count($after)) return false;
    foreach ($before as $i => $row) { $now = (array)$after[$i]; foreach ((array)$row as $k => $v) if (!array_key_exists($k, $now) || (string)$now[$k] !== (string)$v) return false; }
    return true;
};
$manager = new FavoriteCMS\Services\Update\UpdateManager($app);
$validation = $manager->getValidator()->validate($zip);
if (!$validation['valid']) throw new RuntimeException('Installed validator rejected release: ' . implode('; ', $validation['errors']));
$result = $manager->runUpdate($zip, ['expected_sha256' => hash_file('sha256', $zip)]);
$checks = [
    "installed $from validator accepts release" => $validation['valid'],
    "installed $from updater completes to $to" => (!empty($result['success']) && $result['previous_version'] === $from && $result['updated_version'] === $to),
    'existing posts remain exact' => $same($posts, $db->select('SELECT * FROM `posts` ORDER BY id')),
    'existing accounts and pages remain exact' => $same($users, $db->select('SELECT * FROM `users` ORDER BY id')) && $same($pages, $db->select('SELECT * FROM `pages` ORDER BY id')),
    'config (.env) and media remain exact' => hash_file('sha256', APP_ROOT . '/.env') === $envHash && file_get_contents(APP_ROOT . '/public/uploads/keep-original.txt') === 'original-upload',
    'custom themes/plugins remain exact' => file_get_contents(APP_ROOT . '/themes/custom-keep/index.php') === '<?php // preserve custom theme' && file_get_contents(APP_ROOT . '/plugins/custom-keep/plugin.php') === '<?php // preserve custom plugin',
    'automatic backup exists' => is_file(APP_ROOT . '/storage/backups/' . ($result['backup_file'] ?? 'missing')),
    'maintenance and update locks are released' => !$manager->getMaintenance()->isActive() && !$manager->isUpdateInProgress(),
    // Very old updaters (1.0.x) do not write release.json; the new bootstrap version is authoritative.
    'new release version installed' => str_contains((string)file_get_contents(APP_ROOT . '/bootstrap.php'), "'" . $to . "'"),
];
if (empty($result['success'])) echo 'UPDATE RESULT: ' . json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
foreach ($checks as $test => $passed) echo json_encode(['test' => $test, 'status' => $passed ? 'PASS' : 'FAIL']) . PHP_EOL;
echo count($checks) . ' update checks; ' . count(array_filter($checks, static fn($v) => !$v)) . ' failures. Database: ' . $name . PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
