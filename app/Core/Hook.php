<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

class Hook
{
    /**
     * @var array<string, array<int, array<callable>>>
     */
    protected static array $actions = [];

    /**
     * @var array<string, array<int, array<callable>>>
     */
    protected static array $filters = [];

    /**
     * Add an action hook.
     */
    public static function addAction(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        static::$actions[$tag][$priority][] = [
            'callback'     => $callback,
            'acceptedArgs' => $acceptedArgs,
        ];
    }

    /**
     * Execute an action hook.
     */
    public static function doAction(string $tag, mixed ...$args): void
    {
        if (empty(static::$actions[$tag])) {
            return;
        }

        ksort(static::$actions[$tag]);

        foreach (static::$actions[$tag] as $priority => $callbacks) {
            foreach ($callbacks as $item) {
                $numArgs = $item['acceptedArgs'];
                $passArgs = array_slice($args, 0, $numArgs);
                try {
                    call_user_func_array($item['callback'], $passArgs);
                } catch (\Throwable $e) {
                    error_log("Error in action hook '{$tag}': " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Check if an action hook has registered callbacks.
     */
    public static function hasAction(string $tag): bool
    {
        return !empty(static::$actions[$tag]);
    }

    /**
     * Remove all callbacks or a specific tag from actions.
     */
    public static function removeAction(string $tag): void
    {
        unset(static::$actions[$tag]);
    }

    /**
     * Add a filter hook.
     */
    public static function addFilter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        static::$filters[$tag][$priority][] = [
            'callback'     => $callback,
            'acceptedArgs' => $acceptedArgs,
        ];
    }

    /**
     * Apply all registered filters to a value.
     */
    public static function applyFilters(string $tag, mixed $value, mixed ...$args): mixed
    {
        if (empty(static::$filters[$tag])) {
            return $value;
        }

        ksort(static::$filters[$tag]);

        foreach (static::$filters[$tag] as $priority => $callbacks) {
            foreach ($callbacks as $item) {
                $numArgs = $item['acceptedArgs'];
                $passArgs = array_merge([$value], array_slice($args, 0, max(0, $numArgs - 1)));
                try {
                    $value = call_user_func_array($item['callback'], $passArgs);
                } catch (\Throwable $e) {
                    error_log("Error in filter hook '{$tag}': " . $e->getMessage());
                }
            }
        }

        return $value;
    }

    /**
     * Check if a filter hook has registered callbacks.
     */
    public static function hasFilter(string $tag): bool
    {
        return !empty(static::$filters[$tag]);
    }

    /**
     * Remove all callbacks for a filter tag.
     */
    public static function removeFilter(string $tag): void
    {
        unset(static::$filters[$tag]);
    }

    /**
     * Clear all registered hooks (for testing).
     */
    public static function reset(): void
    {
        static::$actions = [];
        static::$filters = [];
    }
}

