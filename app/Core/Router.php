<?php

declare(strict_types=1);

namespace FavoriteCMS\Core;

class Router
{
    /**
     * @var array<array{method: string, path: string, pattern: string, handler: callable|array}>
     */
    protected static array $routes = [];

    public static function get(string $path, callable|array $handler): void
    {
        static::match(['GET', 'HEAD'], $path, $handler);
    }

    public static function post(string $path, callable|array $handler): void
    {
        static::match(['POST'], $path, $handler);
    }

    public static function put(string $path, callable|array $handler): void
    {
        static::match(['PUT'], $path, $handler);
    }

    public static function delete(string $path, callable|array $handler): void
    {
        static::match(['DELETE'], $path, $handler);
    }

    public static function any(string $path, callable|array $handler): void
    {
        static::match(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD'], $path, $handler);
    }

    /**
     * @param array<string>|string $methods
     */
    public static function match(array|string $methods, string $path, callable|array $handler): void
    {
        $methods = (array)$methods;
        $path = '/' . trim($path, '/');
        if ($path === '//') {
            $path = '/';
        }

        // Convert {param} placeholders to named regex groups
        $pattern = preg_replace('#\{([a-zA-Z0-9_]+)\}#', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';

        foreach ($methods as $m) {
            static::$routes[] = [
                'method'  => strtoupper($m),
                'path'    => $path,
                'pattern' => $pattern,
                'handler' => $handler,
            ];
        }
    }

    /**
     * Dispatch an incoming request against registered dynamic routes.
     * Returns a Response if a route matched, or null to continue core dispatching.
     */
    public static function dispatch(Request $request): ?Response
    {
        $path   = '/' . trim($request->path(), '/');
        if ($path === '//') {
            $path = '/';
        }
        $method = strtoupper($request->method());

        foreach (static::$routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }

            if (preg_match($route['pattern'], $path, $matches)) {
                $params = array_filter($matches, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
                return static::executeHandler($route['handler'], $request, $params);
            }
        }

        return null;
    }

    protected static function executeHandler(callable|array $handler, Request $request, array $params = []): Response
    {
        $result = null;

        if (is_callable($handler)) {
            $result = call_user_func($handler, $request, ...array_values($params));
        } elseif (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $instance = is_object($class) ? $class : new $class(Container::getInstance()->get(Application::class));
            $result = call_user_func([$instance, $method], $request, ...array_values($params));
        }

        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        return Response::make((string)$result, 200);
    }

    public static function getRoutes(): array
    {
        return static::$routes;
    }

    public static function reset(): void
    {
        static::$routes = [];
    }
}

