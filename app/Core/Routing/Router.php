<?php
declare(strict_types=1);

namespace Nyx\Core\Routing;

final class Router
{
    /** @var list<array{method:string,pattern:string,handler:mixed,regex:string,params:list<string>}> */
    private array $routes = [];

    public function get(string $path, mixed $handler): void { $this->add('GET', $path, $handler); }
    public function post(string $path, mixed $handler): void { $this->add('POST', $path, $handler); }
    public function put(string $path, mixed $handler): void { $this->add('PUT', $path, $handler); }
    public function delete(string $path, mixed $handler): void { $this->add('DELETE', $path, $handler); }
    public function any(string $path, mixed $handler): void
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'] as $m) {
            $this->add($m, $path, $handler);
        }
    }

    public function add(string $method, string $path, mixed $handler): void
    {
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        $params = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $path);
        $regex = '#^' . $regex . '$#';
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $path,
            'handler' => $handler,
            'regex' => $regex,
            'params' => $params,
        ];
    }

    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            array_shift($m);
            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = $m[$i] ?? null;
            }
            return ['handler' => $route['handler'], 'params' => $params, 'pattern' => $route['pattern']];
        }
        return null;
    }
}
