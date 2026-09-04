<?php

declare(strict_types=1);

namespace FavoriteCMS\Installer;

use FavoriteCMS\Core\Database;
use PDO;
use Throwable;

class DatabaseProvisioner
{
    /**
     * Generate a unique, safe table prefix for new installations.
     */
    public function generateTablePrefix(): string
    {
        return 'fvcms_' . bin2hex(random_bytes(2)) . '_';
    }

    /**
     * Detect database credentials if provided by the hosting environment.
     */
    public function detectEnvironmentCredentials(): array
    {
        $detected = [];

        // Check common hosting environment variables
        $host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? ($_SERVER['DB_HOST'] ?? ''));
        if ($host !== '') {
            $detected['host'] = (string)$host;
        }

        $port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? ($_SERVER['DB_PORT'] ?? ''));
        if ($port !== '') {
            $detected['port'] = (string)$port;
        }

        $database = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? ($_SERVER['DB_DATABASE'] ?? (getenv('MYSQL_DATABASE') ?: '')));
        if ($database !== '') {
            $detected['database'] = (string)$database;
        }

        $username = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? ($_SERVER['DB_USERNAME'] ?? (getenv('MYSQL_USER') ?: '')));
        if ($username !== '') {
            $detected['username'] = (string)$username;
        }

        $password = getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? ($_SERVER['DB_PASSWORD'] ?? ''));
        if ($password !== '') {
            $detected['password'] = (string)$password;
        }

        // Check standard 12-factor DATABASE_URL if present (overrides individual components)
        $dbUrl = getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? ($_SERVER['DATABASE_URL'] ?? ''));
        if (is_string($dbUrl) && $dbUrl !== '' && str_starts_with($dbUrl, 'mysql://')) {
            $parts = parse_url($dbUrl);
            if ($parts !== false) {
                if (!empty($parts['host'])) {
                    $detected['host'] = $parts['host'];
                }
                if (!empty($parts['port'])) {
                    $detected['port'] = (string)$parts['port'];
                }
                if (!empty($parts['user'])) {
                    $detected['username'] = urldecode($parts['user']);
                }
                if (isset($parts['pass'])) {
                    $detected['password'] = urldecode($parts['pass']);
                }
                if (!empty($parts['path'])) {
                    $detected['database'] = ltrim($parts['path'], '/');
                }
            }
        }

        return $detected;
    }

    public function defaultConfig(): array
    {
        $envDetected = $this->detectEnvironmentCredentials();

        return [
            'driver' => 'mysql',
            'host' => (string)($envDetected['host'] ?? env('DB_HOST', 'localhost')),
            'port' => (string)($envDetected['port'] ?? env('DB_PORT', '3306')),
            'database' => (string)($envDetected['database'] ?? env('DB_DATABASE', '')),
            'username' => (string)($envDetected['username'] ?? env('DB_USERNAME', '')),
            'password' => (string)($envDetected['password'] ?? env('DB_PASSWORD', '')),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => (string)env('DB_PREFIX', $this->generateTablePrefix()),
        ];
    }

    public function normalize(array $input): array
    {
        $defaults = $this->defaultConfig();
        $host = trim((string)($input['db_host'] ?? ''));
        $port = trim((string)($input['db_port'] ?? ''));
        $database = trim((string)($input['db_name'] ?? $defaults['database']));
        $username = trim((string)($input['db_username'] ?? $defaults['username']));
        $prefix = trim((string)($input['db_prefix'] ?? ''));

        // Smart defaults for shared hosting
        if ($host === '') {
            $host = $defaults['host'] !== '' ? $defaults['host'] : 'localhost';
        }
        if ($port === '') {
            $port = $defaults['port'] !== '' ? $defaults['port'] : '3306';
        }
        if ($prefix === '') {
            $prefix = $defaults['prefix'] !== '' ? $defaults['prefix'] : $this->generateTablePrefix();
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
            $errors[] = 'Database host contains invalid characters. Default is "localhost".';
        }
        if (!ctype_digit((string)($config['port'] ?? '')) || (int)$config['port'] < 1 || (int)$config['port'] > 65535) {
            $errors[] = 'Database port must be a valid port number (typically 3306).';
        }
        if (!$this->validIdentifier((string)($config['database'] ?? ''))) {
            $errors[] = 'Database name is required and may contain only letters, numbers, underscores, and dollar signs.';
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

        try {
            return new Database($config);
        } catch (\Throwable $e) {
            throw new \RuntimeException($this->formatDatabaseError($e, $config), (int)$e->getCode(), $e);
        }
    }

    /**
     * Transform low-level MySQL PDO errors into friendly, understandable instructions.
     */
    public function formatDatabaseError(\Throwable $e, array $config = []): string
    {
        $message = $e->getMessage();
        $code = (int)$e->getCode();
        $dbName = htmlspecialchars((string)($config['database'] ?? 'the database'), ENT_QUOTES, 'UTF-8');
        $host = htmlspecialchars((string)($config['host'] ?? 'localhost'), ENT_QUOTES, 'UTF-8');

        // Access denied / Wrong credentials (error 1045 or SQLSTATE 28000)
        if (str_contains($message, '1045') || str_contains($message, '28000') || str_contains($message, 'Access denied for user')) {
            return 'Incorrect database username or password. Please verify the credentials provided by your hosting control panel.';
        }

        // Unknown database / Database does not exist (error 1049 or SQLSTATE 42000)
        if (str_contains($message, '1049') || (str_contains($message, '42000') && str_contains($message, 'Unknown database'))) {
            return "The database \"{$dbName}\" does not exist on host \"{$host}\". Please create the database first in your hosting control panel (e.g. cPanel MySQL Databases), or verify the spelling.";
        }

        // Host unreachable / Network refused / Name resolution failure (error 2002 or HY000)
        if (str_contains($message, '2002') || str_contains($message, 'Connection refused') || str_contains($message, 'No such host') || str_contains($message, 'getaddrinfo')) {
            return "Could not reach database host \"{$host}\". On shared hosting (such as Hostinger or cPanel), the host should almost always be \"localhost\". Ensure the database service is active.";
        }

        // Connection timed out (error 2006)
        if (str_contains($message, '2006') || str_contains($message, 'MySQL server has gone away') || str_contains($message, 'timed out')) {
            return "The connection to database host \"{$host}\" timed out. Please verify that your hosting server allows local MySQL connections.";
        }

        // Insufficient privileges (error 1142)
        if (str_contains($message, '1142') || str_contains($message, 'command denied')) {
            return "The database user has insufficient privileges. Please ensure your database user has been granted ALL PRIVILEGES on \"{$dbName}\" in your hosting control panel.";
        }

        // General sanitized fallback (never expose password in message)
        return 'Database connection failed. Please verify your database name, username, and password from your hosting control panel.';
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
                'message' => $this->formatDatabaseError($e, $serverConfig),
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
