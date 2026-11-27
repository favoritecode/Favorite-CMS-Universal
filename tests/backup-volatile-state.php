<?php
/**
 * Backups must survive normal requests rewriting the Central connection state while the archive is being built.
 * Disposable installed sandbox only (CMS_TEST_ROOT with .cms-test-sandbox and a local database).
 * Env: CMS_TEST_ROOT, CMS_TEST_BACKUPS (default 6), CMS_TEST_BIG_MB (default 40)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('CMS_TEST_ROOT');
if (!$root || !is_file($root . '/.cms-test-sandbox') || realpath($root) === realpath(dirname(__DIR__))) throw new RuntimeException('Set CMS_TEST_ROOT to a marked disposable installed sandbox.');
define('APP_ROOT', realpath($root)); require APP_ROOT . '/bootstrap.php';
$runs = (int)(getenv('CMS_TEST_BACKUPS') ?: 6); $bigMb = (int)(getenv('CMS_TEST_BIG_MB') ?: 40);
$dir = APP_ROOT . '/storage/favorite-central-client'; if (!is_dir($dir)) mkdir($dir, 0700, true);
$state = $dir . '/client.json'; file_put_contents($state, json_encode(['installation_id' => str_repeat('a', 32), 'n' => 0]));
// A large upload makes archive finalisation slow enough for concurrent requests to hit it (like a real site).
$big = APP_ROOT . '/public/uploads/backup-test-big.bin'; $h = fopen($big, 'wb'); for ($i = 0; $i < $bigMb; $i++) fwrite($h, random_bytes(1048576)); fclose($h);
// Writer process: what policy()/autoConnect() do on every request (atomic rename of a fresh temp file).
$writer = sys_get_temp_dir() . '/fc-state-writer-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents($writer, '<?php $d=$argv[1];$end=microtime(true)+(float)$argv[2];$n=0;while(microtime(true)<$end){$t=$d."/".bin2hex(random_bytes(12)).".tmp";file_put_contents($t,json_encode(["installation_id"=>str_repeat("a",32),"n"=>++$n,"pad"=>str_repeat("x",$n%900)]));@rename($t,$d."/client.json");usleep(300);}');
$proc = proc_open([PHP_BINARY, $writer, $dir, '600'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
usleep(300000);
$ok = 0; $failed = []; $service = new FavoriteCMS\Services\BackupService();
for ($i = 0; $i < $runs; $i++) {
    try { $r = $service->createBackup(['include_media' => true]); $ok++; @unlink($r['path']); }
    catch (Throwable $e) { $failed[] = $e->getMessage(); }
}
proc_terminate($proc); @unlink($writer); @unlink($big);
foreach (glob($dir . '/tmp*') ?: [] as $f) @unlink($f);
echo "backups with concurrent connection-state writes: $ok/$runs succeeded\n";
foreach (array_unique($failed) as $m) echo "  failure: $m\n";
exit($ok === $runs ? 0 : 1);
