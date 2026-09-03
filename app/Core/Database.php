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

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connect();
    }

    protected function connect(): void
    {
        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=%s',
            $this->config['driver'] ?? 'mysql',
            $this->config['host'] ?? '127.0.0.1',
            $this->config['port'] ?? '3306',
            $this->config['database'] ?? '',
            $this->config['charset'] ?? 'utf8mb4'
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $this->config['username'] ?? '', $this->config['password'] ?? '', $options);
            // Setup timezone
            $this->pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }

    public function query(string $sql, array $bindings = []): PDOStatement
    {
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
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->quoteIdentifier($table), $columns, $placeholders);
        
        $this->query($sql, array_values($data));
        
        return (int)$this->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(fn($key) => "$key = ?", array_keys($data)));
        $conditions = implode(' AND ', array_map(fn($key) => "$key = ?", array_keys($where)));

        $sql = sprintf('UPDATE %s SET %s WHERE %s', $this->quoteIdentifier($table), $set, $conditions);
        
        $bindings = array_merge(array_values($data), array_values($where));
        
        return $this->query($sql, $bindings)->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        $conditions = implode(' AND ', array_map(fn($key) => "$key = ?", array_keys($where)));

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

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

