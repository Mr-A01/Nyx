<?php
declare(strict_types=1);

namespace Nyx\Core\Bootstrap;

use Nyx\Core\Container\Container;
use Nyx\Core\Http\Request;
use Nyx\Core\Http\Response;
use Nyx\Core\Http\JsonResponse;
use Nyx\Core\Routing\Router;
use Nyx\Core\Security\Authentication\SessionAuthenticator;

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

    /**
     * Paths that never require a session (public surface).
     */
    private function isPublicRoute(string $method, string $path): bool
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return true;
        }

        $publicExact = [
            '/api/v3/ops/info',
            '/api/v3/ops/health',
            '/api/v3/ops/network',
            '/api/v3/ops/metrics',
            '/api/v3/ops/selftest',
            '/api/v3/health',
            '/api/v1/auth/register',
            '/api/v1/auth/login',
            '/api/v1/auth/challenge',
            '/api/v1/auth/session',
            '/api/v3/auth/register',
            '/api/v3/auth/login',
            '/api/v3/auth/challenge',
            '/api/v3/auth/session',
            '/api/v3/auth/continue/redeem',
            '/api/v3/security/continue/redeem',
            '/api/v1/identity/challenge',
            '/api/v3/identity/challenge',
        ];
        if (in_array($path, $publicExact, true)) {
            return true;
        }

        // Federation gossip is peer-to-peer (separate secret checks inside controller)
        if (str_starts_with($path, '/api/v3/federation/')) {
            return true;
        }

        // Username public lookup (GET only)
        if ($method === 'GET' && (
            $path === '/api/v1/users/lookup' ||
            $path === '/api/v3/users/lookup' ||
            $path === '/api/v1/identity/username' ||
            $path === '/api/v3/identity/username'
        )) {
            return true;
        }

        // Static HTML already served before Application; keep GET root-like safe
        if ($method === 'GET' && preg_match('#^/(client|status|identity|home)\.html$#', $path)) {
            return true;
        }

        return false;
    }

    /**
     * Mutations that MUST have a valid session.
     */
    private function requiresAuth(string $method, string $path): bool
    {
        if ($this->isPublicRoute($method, $path)) {
            return false;
        }

        // All POST/PUT/DELETE under api require auth by default
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && str_starts_with($path, '/api/')) {
            // already excluded public auth/federation above
            return true;
        }

        // Sensitive GET endpoints
        $authGets = [
            '/api/v3/profile',
            '/api/v3/account/profile',
            '/api/v3/prefs',
            '/api/v3/account/preferences',
            '/api/v3/contacts',
            '/api/v3/conversations',
            '/api/v3/messages/saved',
            '/api/v3/messages/sync',
            '/api/v3/messages/wait',
            '/api/v3/messages/list',
            '/api/v3/sync',
            '/api/v1/auth/me',
            '/api/v3/auth/me',
            '/api/v3/spaces/mine',
            '/api/v3/groups/mine',
            '/api/v3/moderation/blocks',
            '/api/v3/push/prefs',
            '/api/v3/spaces/bans',
            '/api/v3/spaces/members',
            '/api/v3/spaces/get',
            '/api/v3/spaces/info',
            '/api/v3/spaces/audit',
            '/api/v3/spaces/join-requests',
            '/api/v3/crypto/status',
            '/api/v3/crypto/prekeys',
            '/api/v3/crypto/keys',
            '/api/v3/search/users',
            '/api/v3/users/search',
            '/api/v3/search/messages',
            '/api/v3/messages/search',
            '/api/v3/search',
            '/api/v3/search/global',
            '/api/v3/ledger/me',
            '/api/v3/ledger/history',
        ];
        if ($method === 'GET' && in_array($path, $authGets, true)) {
            return true;
        }

        return false;
    }

    public function handle(Request $request): Response
    {
        try {
            $method = $request->method();
            $path = $request->path();

            if ($this->requiresAuth($method, $path)) {
                $auth = (new SessionAuthenticator())->requireUser($request);
                if ($auth === false) {
                    return JsonResponse::error('unauthorized', 401);
                }
                // Attach session to request server bag for controllers that read it
                $GLOBALS['nyx_auth'] = $auth;
            }

            $route = $this->router->match($method, $path);
            if ($route === null) {
                if ($path === '/' || $path === '') {
                    return JsonResponse::ok([
                        'service' => 'nyx-messenger-pro',
                        'version' => defined('NYX_VERSION') ? NYX_VERSION : ($GLOBALS['nyx_config']['app']['version'] ?? '1.44.1'),
                        'protocol' => 3,
                        'status' => 'ok',
                        'architecture' => 'modular',
                    ]);
                }
                return JsonResponse::error('Not Found', 404);
            }

            $handler = $route['handler'] ?? $route['hand'] ?? null;
            if (is_callable($handler) && !is_array($handler)) {
                $result = $handler($request, $route['params'] ?? []);
            } elseif (is_array($handler) && count($handler) === 2) {
                [$class, $methodName] = $handler;
                if (is_object($class)) {
                    $controller = $class;
                } elseif (is_string($class)) {
                    $controller = $this->container->make($class);
                } else {
                    return JsonResponse::error('Invalid route handler', 500);
                }
                $result = $controller->{$methodName}($request, $route['params'] ?? []);
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
            define('NYX_VERSION', $config['app']['version'] ?? '1.44.1-secure');
        }
        if (!defined('NYX_PROTOCOL')) {
            define('NYX_PROTOCOL', (int)($config['app']['protocol'] ?? 3));
        }
    }

    private function loadRoutes(): void
    {
        $router = $this->router;
        $api = $this->routePaths['api'] ?? null;
        if ($api && is_file($api)) {
            require $api;
        } elseif ($api && is_dir($api)) {
            foreach (['v3', 'v1'] as $ver) {
                $dir = $api . '/' . $ver;
                if (!is_dir($dir)) {
                    continue;
                }
                foreach (glob($dir . '/*.php') ?: [] as $file) {
                    require $file;
                }
            }
        }
        $modular = $this->basePath('routes/api/v3/modular.php');
        if (is_file($modular) && ($this->routePaths['api'] ?? '') !== $modular) {
            require $modular;
        }
        $web = $this->routePaths['web'] ?? null;
        if ($web && is_file($web)) {
            require $web;
        }
    }
}
