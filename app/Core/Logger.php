<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

class Logger
{
    protected static ?string $logFile = null;

    public static function getLogFile(): string
    {
        if (static::$logFile === null) {
            $dir = APP_ROOT . '/storage/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            static::$logFile = $dir . '/favorite_cms.log';
        }
        return static::$logFile;
    }

    public static function setLogFile(string $path): void
    {
        static::$logFile = $path;
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        $file = static::getLogFile();
        $date = date('Y-m-d H:i:s');
        $level = strtoupper($level);
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        $line = sprintf("[%s] [%s] %s%s\n", $date, $level, $message, $contextStr);

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = []): void
    {
        static::log('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        static::log('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        static::log('ERROR', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        static::log('DEBUG', $message, $context);
    }
}

