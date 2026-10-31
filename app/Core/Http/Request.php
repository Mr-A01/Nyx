<?php
declare(strict_types=1);
namespace Nyx\Core\Http;
final class Request {
    public function __construct(private string $method, private string $path, private array $query, private array $body, private array $headers, private array $server) {}
    public static function capture(): self {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if (str_contains($path, '/nyx')) $path = substr($path, strpos($path, '/nyx') + 4) ?: '/';
        if (str_starts_with($path, '/public')) $path = substr($path, 7) ?: '/';
        $path = '/' . trim($path, '/');
        if ($path !== '/') $path = rtrim($path, '/') ?: '/';
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))))] = $v;
            }
        }
        $raw = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        return new self($method, $path === '' ? '/' : $path, $_GET, is_array($json) ? $json : $_POST, $headers, $_SERVER);
    }
    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    public function json(?string $key = null, mixed $default = null): mixed {
        return $key === null ? $this->body : ($this->body[$key] ?? $default);
    }
    public function input(?string $key = null, mixed $default = null): mixed {
        return $this->json($key, $default);
    }
    public function query(?string $key = null, mixed $default = null): mixed {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }
    public function header(string $name, mixed $default = null): mixed {
        return $this->headers[$name] ?? $this->headers[str_replace('-', ' ', ucwords(strtolower(str_replace('-', ' ', $name))))] ?? $default;
    }
    public function bearerToken(): ?string {
        $auth = $this->header('Authorization') ?? ($this->server['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)$/i', (string)$auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
