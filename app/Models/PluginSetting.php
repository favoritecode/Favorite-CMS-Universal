<?php

declare(strict_types=1);

namespace FavoriteCMS\Models;

use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;

class PluginSetting extends BaseModel
{
    protected static string $table = 'plugin_settings';

    /**
     * @var array<string, array<string, mixed>>
     */
    protected static array $cache = [];

    public static function get(string $pluginId, string $key, mixed $default = null): mixed
    {
        if (isset(static::$cache[$pluginId][$key])) {
            return static::$cache[$pluginId][$key];
        }

        $db = Container::getInstance()->get(Database::class);
        $row = $db->selectOne(
            "SELECT `value` FROM `plugin_settings` WHERE `plugin_id` = ? AND `setting_key` = ? LIMIT 1",
            [$pluginId, $key]
        );

        if (!$row || $row->value === null) {
            return $default;
        }

        $val = $row->value;
        $decoded = json_decode($val, true);
        $final = (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded)))
            ? $decoded
            : $val;

        static::$cache[$pluginId][$key] = $final;
        return $final;
    }

    public static function set(string $pluginId, string $key, mixed $value): void
    {
        $db = Container::getInstance()->get(Database::class);

        $storedVal = (is_array($value) || is_object($value))
            ? json_encode($value)
            : (string)$value;

        $existing = $db->selectOne(
            "SELECT `id` FROM `plugin_settings` WHERE `plugin_id` = ? AND `setting_key` = ? LIMIT 1",
            [$pluginId, $key]
        );

        if ($existing) {
            $db->execute(
                "UPDATE `plugin_settings` SET `value` = ? WHERE `id` = ?",
                [$storedVal, $existing->id]
            );
        } else {
            $db->insert('plugin_settings', [
                'plugin_id'   => $pluginId,
                'setting_key' => $key,
                'value'       => $storedVal,
            ]);
        }

        static::$cache[$pluginId][$key] = $value;
    }

    public static function forPlugin(string $pluginId): array
    {
        $db = Container::getInstance()->get(Database::class);
        $rows = $db->select(
            "SELECT `setting_key`, `value` FROM `plugin_settings` WHERE `plugin_id` = ?",
            [$pluginId]
        );

        $results = [];
        foreach ($rows as $row) {
            $val = $row->value;
            $decoded = json_decode($val, true);
            $results[$row->setting_key] = (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded)))
                ? $decoded
                : $val;
        }

        static::$cache[$pluginId] = $results;
        return $results;
    }

    public static function deleteSetting(string $pluginId, ?string $key = null): void
    {
        $db = Container::getInstance()->get(Database::class);

        if ($key !== null) {
            $db->execute(
                "DELETE FROM `plugin_settings` WHERE `plugin_id` = ? AND `setting_key` = ?",
                [$pluginId, $key]
            );
            unset(static::$cache[$pluginId][$key]);
        } else {
            $db->execute(
                "DELETE FROM `plugin_settings` WHERE `plugin_id` = ?",
                [$pluginId]
            );
            unset(static::$cache[$pluginId]);
        }
    }

    public static function clearCache(): void
    {
        static::$cache = [];
    }
}
