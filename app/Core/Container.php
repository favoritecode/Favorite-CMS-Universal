<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

use Closure;
use Exception;
use ReflectionClass;

class Container
{
    protected static ?Container $instance = null;
    protected array $bindings = [];
    protected array $instances = [];

    public static function getInstance(): static
    {
        if (is_null(static::$instance)) {
            static::$instance = new static;
        }
        return static::$instance;
    }

    public static function setInstance(?Container $container = null): ?Container
    {
        return static::$instance = $container;
    }

    public function bind(string $abstract, Closure|string $concrete = null, bool $shared = false): void
    {
        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        $this->bindings[$abstract] = compact('concrete', 'shared');
    }

    public function singleton(string $abstract, Closure|string $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    public function instance(string $abstract, mixed $instance): mixed
    {
        $this->instances[$abstract] = $instance;
        return $instance;
    }

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    public function get(string $abstract): mixed
    {
        return $this->make($abstract);
    }

    public function make(string $abstract, array $parameters = []): mixed
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $concrete = $this->bindings[$abstract]['concrete'] ?? $abstract;

        if ($concrete instanceof Closure) {
            $object = $concrete($this, $parameters);
        } else {
            $object = $this->build($concrete, $parameters);
        }

        if (isset($this->bindings[$abstract]['shared']) && $this->bindings[$abstract]['shared'] === true) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    protected function build(string $concrete, array $parameters = []): mixed
    {
        try {
            $reflector = new ReflectionClass($concrete);
        } catch (\ReflectionException $e) {
            throw new Exception("Target class [$concrete] does not exist.", 0, $e);
        }

        if (!$reflector->isInstantiable()) {
            throw new Exception("Target class [$concrete] is not instantiable.");
        }

        $constructor = $reflector->getConstructor();

        if (is_null($constructor)) {
            return new $concrete;
        }

        $dependencies = $constructor->getParameters();
        $instances = $this->resolveDependencies($dependencies, $parameters);

        return $reflector->newInstanceArgs($instances);
    }

    protected function resolveDependencies(array $dependencies, array $parameters = []): array
    {
        $results = [];

        foreach ($dependencies as $dependency) {
            $name = $dependency->getName();

            if (array_key_exists($name, $parameters)) {
                $results[] = $parameters[$name];
                continue;
            }

            $type = $dependency->getType();

            if ($type && !$type->isBuiltin()) {
                $results[] = $this->make($type->getName());
            } elseif ($dependency->isDefaultValueAvailable()) {
                $results[] = $dependency->getDefaultValue();
            } else {
                throw new Exception("Unresolvable dependency resolving [$name].");
            }
        }

        return $results;
    }
}

