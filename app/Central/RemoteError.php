<?php
declare(strict_types=1);
namespace FavoriteCMS\Central;
final class RemoteError extends \RuntimeException {
 function __construct(public readonly string $errorCode,string $message,int $http=503){parent::__construct($message,$http);}
}
