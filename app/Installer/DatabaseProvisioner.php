<?php

declare(strict_types=1);

namespace FavoriteCMS\Installer;

use FavoriteCMS\Core\Database;
use PDO;
use Throwable;

class DatabaseProvisioner
{
    public function defaultConfig(): array
    {
        return [
            'driver' => 'mysql',
            'host' => (string)env('DB_HOST', 'localhost'),
            'port' => (string)env('DB_PORT', '3306'),
            'database' => (string)env('DB_DATABASE', ''),
            'username' => (string)env('DB_USERNAME', ''),
            'password' => (string)env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => (string)env('DB_PREFIX', 'fvcms_'),
        ];
    }

    public function normalize(array $input): array
    {
        $defaults = $this->defaultConfig();
        $host = trim((string)($input['db_host'] ?? $defaults['host']));
        $port = trim((string)($input['db_port'] ?? $defaults['port']));
        $database = trim((string)($input['db_name'] ?? $defaults['database']));
        $username = trim((string)($input['db_username'] ?? $defaults['username']));
        $prefix = trim((string)($input['db_prefix'] ?? $defaults['prefix']));

        if ($host === '') {
            $host = 'localhost';
        }
        if ($port === '') {
            $port = '3306';
        }

        return [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => (string)($input['db_password'] ?? $defaults['password']),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $prefix,
        ];
    }

    public function validate(array $config): array
    {
        $errors = [];

        if (!extension_loaded('pdo_mysql')) {
            $errors[] = 'The PHP pdo_mysql extension is required.';
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $config['host'] ?? '')) {
            $errors[] = 'Database host contains invalid characters.';
        }
        if (!ctype_digit((string)($config['port'] ?? '')) || (int)$config['port'] < 1 || (int)$config['port'] > 65535) {
            $errors[] = 'Database port must be a valid TCP port.';
        }
        if (!$this->validIdentifier((string)($config['database'] ?? ''))) {
            $errors[] = 'Database name may contain only letters, numbers, underscores, and dollar signs.';
        }
        if (trim((string)($config['username'] ?? '')) === '') {
            $errors[] = 'Database username is required.';
        }
        if (!$this->validPrefix((string)($config['prefix'] ?? ''))) {
            $errors[] = 'Table prefix may contain only letters, numbers, and underscores, and must start with a letter.';
        }

        return $errors;
    }

    public function testConnection(array $config): Database
    {
        $errors = $this->validate($config);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        return new Database($config);
    }

    public function createAutomatically(array $serverConfig, string $targetUsername, string $targetPassword): array
    {
        $errors = $this->validate($serverConfig);
        if ($errors !== []) {
            return ['ok' => false, 'message' => implode(' ', $errors), 'manual_required' => true];
        }

        try {
            $pdo = $this->connectServer($serverConfig);
            $database = (string)$serverConfig['database'];
            $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . $this->quoteIdentifier($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

            if ($targetUsername !== '' && $targetUsername !== (string)$serverConfig['username']) {
                $user = $pdo->quote($targetUsername);
                $host = $pdo->quote('%');
                $password = $pdo->quote($targetPassword);
                $pdo->exec("CREATE USER IF NOT EXISTS {$user}@{$host} IDENTIFIED BY {$password}");
                $pdo->exec('GRANT ALL PRIVILEGES ON ' . $this->quoteIdentifier($database) . ".* TO {$user}@{$host}");

                $serverConfig['username'] = $targetUsername;
                $serverConfig['password'] = $targetPassword;
            }

            $this->testConnection($serverConfig);

            return ['ok' => true, 'config' => $serverConfig, 'message' => 'Database created and verified.'];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Automatic database creation is not permitted by this hosting provider or account. Please enter the database credentials from your hosting control panel.',
                'manual_required' => true,
                'diagnostic' => $e->getMessage(),
            ];
        }
    }

    public function writeEnv(array $dbConfig, string $siteUrl): void
    {
        $envPath = APP_ROOT . '/.env';
        $values = [];

        if (is_file($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (trim($line) === '' || str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $values[trim($key)] = trim($value, " \t\"'");
            }
        }

        $values = array_merge($values, [
            'APP_NAME' => $values['APP_NAME'] ?? 'Favorite CMS',
            'APP_ENV' => $values['APP_ENV'] ?? 'production',
            'APP_URL' => $siteUrl,
            'APP_DEBUG' => $values['APP_DEBUG'] ?? 'false',
            'APP_KEY' => $values['APP_KEY'] ?? 'base64:' . base64_encode(random_bytes(32)),
            'DB_DRIVER' => 'mysql',
            'DB_HOST' => (string)$dbConfig['host'],
            'DB_PORT' => (string)$dbConfig['port'],
            'DB_DATABASE' => (string)$dbConfig['database'],
            'DB_USERNAME' => (string)$dbConfig['username'],
            'DB_PASSWORD' => (string)$dbConfig['password'],
            'DB_PREFIX' => (string)($dbConfig['prefix'] ?? ''),
            'SESSION_DRIVER' => $values['SESSION_DRIVER'] ?? 'file',
            'SESSION_LIFETIME' => $values['SESSION_LIFETIME'] ?? '120',
        ]);

        $lines = [];
        foreach ($values as $key => $value) {
            putenv($key . '=' . (string)$value);
            $_ENV[$key] = (string)$value;
            $_SERVER[$key] = (string)$value;
            $lines[] = $key . '=' . $this->envValue((string)$value);
        }

        $tmp = $envPath . '.tmp';
        if (file_put_contents($tmp, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false || !rename($tmp, $envPath)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write the .env configuration file. Please check file permissions.');
        }
    }

    protected function connectServer(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4',
            $config['host'],
            $config['port']
        );

        return new PDO($dsn, (string)$config['username'], (string)$config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    protected function validIdentifier(string $value): bool
    {
        return $value !== '' && strlen($value) <= 64 && preg_match('/^[A-Za-z0-9_$]+$/', $value) === 1;
    }

    protected function validPrefix(string $value): bool
    {
        return $value === '' || (strlen($value) <= 32 && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $value) === 1);
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    protected function envValue(string $value): string
    {
        if ($value === '' || preg_match('/\s|#|"|\'/', $value)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }
}
