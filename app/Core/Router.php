<?php
declare(strict_types=1);

namespace App\Core;

class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $middlewares = []): void
    {
        $regex = preg_replace('#\{([a-zA-Z0-9_]+)\}#', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function get(string $pattern, callable $handler, array $mw = []): void { $this->add('GET', $pattern, $handler, $mw); }
    public function post(string $pattern, callable $handler, array $mw = []): void { $this->add('POST', $pattern, $handler, $mw); }
    public function put(string $pattern, callable $handler, array $mw = []): void { $this->add('PUT', $pattern, $handler, $mw); }
    public function delete(string $pattern, callable $handler, array $mw = []): void { $this->add('DELETE', $pattern, $handler, $mw); }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path = $request->path();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                foreach ($route['middlewares'] as $mw) {
                    $mw($request, $params);
                }
                ($route['handler'])($request, $params);
                return;
            }
        }

        Response::error('API endpoint not found.', 404);
    }
}
