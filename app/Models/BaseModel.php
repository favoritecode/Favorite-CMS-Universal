<?php

declare(strict_types=1);

namespace FavoriteCMS\Models;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Container;

abstract class BaseModel
{
    protected static string $table = '';
    protected array $attributes = [];
    protected Database $db;

    public function __construct(array $attributes = [])
    {
        $this->db = Container::getInstance()->get(Database::class);
        $this->fill($attributes);
    }

    public static function getTable(): string
    {
        return static::$table;
    }

    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
        return $this;
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function __get(string $name)
    {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, $value)
    {
        $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]) && $this->attributes[$name] !== null && $this->attributes[$name] !== '';
    }

    public function __unset(string $name): void
    {
        unset($this->attributes[$name]);
    }

    public static function find(int $id): ?static
    {
        $db = Container::getInstance()->get(Database::class);
        $result = $db->selectOne(sprintf("SELECT * FROM %s WHERE id = ?", $db->quoteIdentifier(static::$table)), [$id]);
        return $result ? new static((array)$result) : null;
    }

    public static function findOrFail(int $id): static
    {
        $model = static::find($id);
        if (!$model) {
            throw new \Exception("Model not found with ID: {$id}");
        }
        return $model;
    }

    public static function all(array $columns = ['*']): array
    {
        $db = Container::getInstance()->get(Database::class);
        $select = implode(', ', $columns);
        $results = $db->select(sprintf("SELECT %s FROM %s", $select, $db->quoteIdentifier(static::$table)));
        
        return array_map(fn($row) => new static((array)$row), $results);
    }

    public function save(): bool
    {
        if (isset($this->attributes['id'])) {
            return $this->update($this->attributes);
        } else {
            $id = static::create($this->attributes);
            if ($id) {
                $this->attributes['id'] = $id->id ?? $id;
                return true;
            }
            return false;
        }
    }

    public static function create(array $data): static
    {
        $db = Container::getInstance()->get(Database::class);
        if (!isset($data['created_at'])) {
            $data['created_at'] = (new \DateTime())->format('Y-m-d H:i:s');
        }
        if (!isset($data['updated_at'])) {
            $data['updated_at'] = (new \DateTime())->format('Y-m-d H:i:s');
        }
        $id = $db->insert(static::$table, $data);
        return static::find($id);
    }

    public function update(array $data): bool
    {
        if (!isset($this->attributes['id'])) {
            return false;
        }
        $data['updated_at'] = (new \DateTime())->format('Y-m-d H:i:s');
        return $this->db->update(static::$table, $data, ['id' => $this->attributes['id']]) > 0;
    }

    public function delete(): bool
    {
        if (!isset($this->attributes['id'])) {
            return false;
        }
        return $this->db->delete(static::$table, ['id' => $this->attributes['id']]) > 0;
    }
}

