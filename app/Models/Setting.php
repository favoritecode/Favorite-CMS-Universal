<?php

declare(strict_types=1);

namespace FavoriteCMS\Models;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;

class Setting extends BaseModel
{
    protected static string $table = 'settings';
    protected static array $cache = [];

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        $cacheKey = "{$group}.{$key}";
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $db = Container::getInstance()->get(Database::class);
        $setting = $db->selectOne(
            "SELECT * FROM `settings` WHERE `group_name` = ? AND `setting_key` = ? LIMIT 1",
            [$group, $key]
        );
        
        if ($setting) {
            self::$cache[$cacheKey] = self::castValue($setting->value, $setting->type ?? 'string');
            return self::$cache[$cacheKey];
        }

        return $default;
    }

    public static function set(string $group, string $key, mixed $value, string $type = 'string'): void
    {
        $db = Container::getInstance()->get(Database::class);
        $setting = $db->selectOne(
            "SELECT id FROM `settings` WHERE `group_name` = ? AND `setting_key` = ? LIMIT 1",
            [$group, $key]
        );
        
        $data = [
            'group_name'  => $group,
            'setting_key' => $key,
            'value'       => self::uncastValue($value, $type),
            'type'        => $type,
            'updated_at'  => date('Y-m-d H:i:s'),
        ];

        if ($setting) {
            $db->update('settings', $data, ['id' => $setting->id]);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['is_public']  = 0;
            $db->insert('settings', $data);
        }

        self::$cache["{$group}.{$key}"] = $value;
    }

    public static function getGroup(string $group): array
    {
        $db = Container::getInstance()->get(Database::class);
        $settings = $db->select("SELECT * FROM `settings` WHERE `group_name` = ?", [$group]);
        $result = [];
        foreach ($settings as $setting) {
            $val = self::castValue($setting->value, $setting->type ?? 'string');
            $result[$setting->setting_key] = $val;
            self::$cache["{$group}.{$setting->setting_key}"] = $val;
        }
        return $result;
    }

    public static function getPublic(): array
    {
        $db = Container::getInstance()->get(Database::class);
        $settings = $db->select("SELECT * FROM `settings` WHERE `is_public` = 1");
        $result = [];
        foreach ($settings as $setting) {
            $result[$setting->group_name][$setting->setting_key] = self::castValue($setting->value, $setting->type ?? 'string');
        }
        return $result;
    }

    protected static function castValue(?string $value, string $type): mixed
    {
        if ($value === null) return null;
        
        return match($type) {
            'int'   => (int)$value,
            'bool'  => (bool)$value,
            'json'  => json_decode($value, true),
            default => $value,
        };
    }

    protected static function uncastValue(mixed $value, string $type): ?string
    {
        if ($value === null) return null;
        
        return match($type) {
            'bool'  => $value ? '1' : '0',
            'json'  => json_encode($value),
            default => (string)$value,
        };
    }
}
