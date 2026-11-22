<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/bootstrap.php';
$count=\FavoriteCMS\Services\ScheduledPublication::runDue(app(\FavoriteCMS\Core\Database::class));
echo 'Published '.$count." scheduled items.\n";
