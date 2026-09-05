<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

use PDO;
use PDOException;
use PDOStatement;

class Database
{
    protected PDO $pdo;
    protected array $config;
    protected string $prefix = '';
    protected array $prefixableTables = [
        'cms_migrations',
        'users',
        'roles',
        'permissions',
        'role_permissions',
        'user_roles',
        'posts',
        'pages',
        'taxonomies',
        'post_taxonomies',
        'media',
        'menus',
        'menu_items',
        'settings',
        'seo_meta',
        'sessions',
        'plugin_settings',
        'comments',
    ];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->prefix = $this->normalizePrefix((string)($config['prefix'] ?? ''));
        $this->connect();
    }

    protected function connect(): void
    {
        $driver = $this->config['driver'] ?? 'mysql';
        if ($driver === 'sqlite') {
            $database = (string)($this->config['database'] ?? ':memory:');
            $dsn = 'sqlite:' . $database;
        } else {
            $dsn = sprintf(
                '%s:host=%s;port=%s;dbname=%s;charset=%s',
                $driver,
                $this->config['host'] ?? '127.0.0.1',
                $this->config['port'] ?? '3306',
                $this->config['database'] ?? '',
                $this->config['charset'] ?? 'utf8mb4'
            );
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $this->config['username'] ?? '', $this->config['password'] ?? '', $options);
            if ($driver !== 'sqlite') {
                // Setup timezone
                $this->pdo->exec("SET time_zone = '+00:00'");
            }
        } catch (PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }

    public function query(string $sql, array $bindings = []): PDOStatement
    {
        $sql = $this->prefixSql($sql);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return $stmt;
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->query($sql, $bindings)->fetchAll();
    }

    public function selectOne(string $sql, array $bindings = []): ?object
    {
        $result = $this->query($sql, $bindings)->fetch();
        return $result ?: null;
    }

    public function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_map(fn ($key) => $this->quoteIdentifier((string)$key, false), array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->quoteIdentifier($table), $columns, $placeholders);
        
        $this->query($sql, array_values($data));
        
        return (int)$this->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(fn($key) => $this->quoteIdentifier((string)$key, false) . " = ?", array_keys($data)));
        $conditions = implode(' AND ', array_map(fn($key) => $this->quoteIdentifier((string)$key, false) . " = ?", array_keys($where)));

        $sql = sprintf('UPDATE %s SET %s WHERE %s', $this->quoteIdentifier($table), $set, $conditions);
        
        $bindings = array_merge(array_values($data), array_values($where));
        
        return $this->query($sql, $bindings)->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        $conditions = implode(' AND ', array_map(fn($key) => $this->quoteIdentifier((string)$key, false) . " = ?", array_keys($where)));

        $sql = sprintf('DELETE FROM %s WHERE %s', $this->quoteIdentifier($table), $conditions);
        
        return $this->query($sql, array_values($where))->rowCount();
    }

    public function execute(string $sql, array $bindings = []): bool
    {
        return $this->query($sql, $bindings)->rowCount() > 0 || true;
    }

    public function transaction(\Closure $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    public function tableExists(string $table): bool
    {
        try {
            $this->query("SELECT 1 FROM {$this->quoteIdentifier($table)} LIMIT 1");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    public function table(string $table): string
    {
        return $this->prefixTable($table);
    }

    /**
     * Register additional table names that should be prefixed by Database.
     *
     * @param string|array<string> ...$tables
     */
    public function registerPrefixableTables(string|array ...$tables): void
    {
        $flattened = [];
        foreach ($tables as $entry) {
            if (is_array($entry)) {
                foreach ($entry as $t) {
                    $flattened[] = (string)$t;
                }
            } else {
                $flattened[] = (string)$entry;
            }
        }

        foreach ($flattened as $table) {
            $table = trim($table);
            if ($table !== '' && !in_array($table, $this->prefixableTables, true)) {
                $this->prefixableTables[] = $table;
            }
        }
    }

    /**
     * Get all currently registered prefixable table names.
     *
     * @return array<string>
     */
    public function getPrefixableTables(): array
    {
        return $this->prefixableTables;
    }

    public function quoteIdentifier(string $identifier, bool $applyPrefix = true): string
    {
        if ($applyPrefix) {
            $identifier = $this->prefixTable($identifier);
        }
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    protected function prefixTable(string $table): string
    {
        if ($this->prefix === '' || !in_array($table, $this->prefixableTables, true) || str_starts_with($table, $this->prefix)) {
            return $table;
        }

        return $this->prefix . $table;
    }

    protected function prefixSql(string $sql): string
    {
        if ($this->prefix === '') {
            return $sql;
        }

        foreach ($this->prefixableTables as $table) {
            $prefixed = $this->prefix . $table;
            $sql = preg_replace('/`' . preg_quote($table, '/') . '`/', '`' . str_replace('`', '``', $prefixed) . '`', $sql) ?? $sql;
            $sql = preg_replace(
                '/\b(FROM|JOIN|INTO|UPDATE|TABLE|TABLES|DESCRIBE|DESC)\s+' . preg_quote($table, '/') . '\b/i',
                '$1 `' . str_replace('`', '``', $prefixed) . '`',
                $sql
            ) ?? $sql;
            $sql = str_replace("LIKE '" . $table . "'", "LIKE '" . $prefixed . "'", $sql);
        }

        return $sql;
    }

    protected function normalizePrefix(string $prefix): string
    {
        if ($prefix === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/', $prefix) !== 1) {
            throw new \InvalidArgumentException('Invalid database table prefix.');
        }

        return $prefix;
    }
}
