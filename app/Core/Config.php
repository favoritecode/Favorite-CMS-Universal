<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

class Config
{
    protected array $items = [];

    public function __construct()
    {
        $this->loadConfigurationFiles();
    }

    protected function loadConfigurationFiles(): void
    {
        $configPath = APP_ROOT . '/config';
        if (!is_dir($configPath)) {
            return;
        }

        foreach (glob($configPath . '/*.php') as $file) {
            $key = basename($file, '.php');
            $this->items[$key] = require $file;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $array = $this->items;

        if (is_null($key)) {
            return $array;
        }

        if (isset($array[$key])) {
            return $array[$key];
        }

        foreach (explode('.', $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }

        return $array;
    }

    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $array = &$this->items;

        while (count($keys) > 1) {
            $key = array_shift($keys);

            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }

            $array = &$array[$key];
        }

        $array[array_shift($keys)] = $value;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    public function all(): array
    {
        return $this->items;
    }
}

