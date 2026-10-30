<?php
declare(strict_types=1);

namespace Nyx\Core\Bootstrap;

use Nyx\Core\Container\Container;
use Nyx\Core\Http\Request;
use Nyx\Core\Http\Response;
use Nyx\Core\Http\JsonResponse;
use Nyx\Core\Routing\Router;

final class Application
{
    private Container $container;
    private Router $router;
    private string $basePath;
    private array $routePaths = [];

    private function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
        $this->container = new Container();
        $this->router = new Router();
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Router::class, $this->router);
        $this->container->instance(self::class, $this);
    }

    public static function configure(string $basePath): self
    {
        return new self($basePath);
    }

    public function withRouting(?string $api = null, ?string $web = null): self
    {
        if ($api) {
            $this->routePaths['api'] = $api;
        }
        if ($web) {
            $this->routePaths['web'] = $web;
        }
        return $this;
    }

    public function withMiddleware(): self
    {
        return $this;
    }

    public function withExceptions(): self
    {
        return $this;
    }

    public function create(): self
    {
        $this->loadConfig();
        $this->loadRoutes();
        return $this;
    }

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function handle(Request $request): Response
    {
        try {
            $route = $this->router->match($request->method(), $request->path());
            if ($route === null) {
                // Built-in health for empty installs
                if ($request->path() === '/' || $request->path() === '') {
                    return JsonResponse::ok([
                        'service' => 'nyx-messenger-pro',
                        'version' => defined('NYX_VERSION') ? NYX_VERSION : ($GLOBALS['nyx_config']['app']['version'] ?? '1.40.0'),
                        'protocol' => 3,
                        'status' => 'ok',
                        'architecture' => 'modular',
                    ]);
                }
                return JsonResponse::error('Not Found', 404);
            }
            $handler = $route['handler'];
            if (is_callable($handler)) {
                $result = $handler($request, $route['params'] ?? []);
            } elseif (is_array($handler) && count($handler) === 2) {
                [$class, $method] = $handler;
                $controller = $this->container->make($class);
                $result = $controller->{$method}($request, $route['params'] ?? []);
            } else {
                return JsonResponse::error('Invalid route handler', 500);
            }
            if ($result instanceof Response) {
                return $result;
            }
            return JsonResponse::ok($result);
        } catch (\Throwable $e) {
            $debug = (bool)($GLOBALS['nyx_config']['app']['debug'] ?? false);
            return JsonResponse::error(
                $debug ? $e->getMessage() : 'Internal Server Error',
                500,
                $debug ? ['trace' => explode("\n", $e->getTraceAsString())] : null
            );
        }
    }

    private function loadConfig(): void
    {
        $config = [];
        foreach (glob($this->basePath('config/*.php')) ?: [] as $file) {
            $key = basename($file, '.php');
            if ($key === 'environments') {
                continue;
            }
            $config[$key] = require $file;
        }
        $env = $config['app']['env'] ?? 'production';
        $envFile = $this->basePath("config/environments/{$env}.php");
        if (is_file($envFile)) {
            $config['env'] = require $envFile;
        }
        $GLOBALS['nyx_config'] = $config;
        if (!defined('NYX_VERSION')) {
            define('NYX_VERSION', $config['app']['version'] ?? '1.40.0');
        }
        if (!defined('NYX_PROTOCOL')) {
            define('NYX_PROTOCOL', (int)($config['app']['protocol'] ?? 3));
        }
    }

    private function loadRoutes(): void
    {
        $router = $this->router;
        $apiDir = $this->routePaths['api'] ?? null;
        if ($apiDir) {
            if (is_file($apiDir)) {
                require $apiDir;
            } elseif (is_dir($apiDir)) {
                foreach (['v3', 'v1'] as $ver) {
                    $dir = $apiDir . '/' . $ver;
                    if (!is_dir($dir)) {
                        continue;
                    }
                    foreach (glob($dir . '/*.php') ?: [] as $file) {
                        require $file;
                    }
                }
            }
        }
        $web = $this->routePaths['web'] ?? null;
        if ($web && is_file($web)) {
            require $web;
        }
    }
}
