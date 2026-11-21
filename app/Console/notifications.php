<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/bootstrap.php';
try{echo json_encode(\FavoriteCMS\Services\NotificationService::work(30),JSON_PRETTY_PRINT).PHP_EOL;}catch(Throwable $e){fwrite(STDERR,'Notification worker failed: '.$e->getMessage().PHP_EOL);exit(1);}
