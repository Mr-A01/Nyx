<?php
declare(strict_types=1);

namespace Nyx\Shared\Infrastructure\Cache;

/**
 * Thin Redis wrapper. Enabled when ext-redis is loaded and REDIS_URL / REDIS_HOST is set.
 * Falls back silently so shared hosting keeps working without Redis.
 */
final class RedisClient
{
    private static ?\Redis $conn = null;
    private static bool $tried = false;
    private static bool $ok = false;

    public static function enabled(): bool
    {
        self::connect();
        return self::$ok;
    }

    public static function connect(): ?\Redis
    {
        if (self::$tried) {
            return self::$ok ? self::$conn : null;
        }
        self::$tried = true;
        if (!class_exists(\Redis::class)) {
            return null;
        }
        $url = getenv('REDIS_URL') ?: (function_exists('env') ? (string)env('REDIS_URL', '') : '');
        $host = getenv('REDIS_HOST') ?: (function_exists('env') ? (string)env('REDIS_HOST', '') : '');
        $port = (int)(getenv('REDIS_PORT') ?: (function_exists('env') ? env('REDIS_PORT', 6379) : 6379));
        $pass = getenv('REDIS_PASSWORD') ?: (function_exists('env') ? (string)env('REDIS_PASSWORD', '') : '');
        if ($url === '' && $host === '') {
            return null;
        }
        try {
            $r = new \Redis();
            if ($url !== '') {
                $parts = parse_url($url);
                $host = $parts['host'] ?? '127.0.0.1';
                $port = (int)($parts['port'] ?? 6379);
                $pass = $parts['pass'] ?? $pass;
            }
            if (!$r->connect($host ?: '127.0.0.1', $port ?: 6379, 1.5)) {
                return null;
            }
            if ($pass !== '') {
                $r->auth($pass);
            }
            $db = (int)(getenv('REDIS_DB') ?: 0);
            if ($db > 0) {
                $r->select($db);
            }
            self::$conn = $r;
            self::$ok = true;
            return $r;
        } catch (\Throwable $e) {
            self::$ok = false;
            return null;
        }
    }

    public static function get(string $key): ?string
    {
        $r = self::connect();
        if (!$r) return null;
        try {
            $v = $r->get($key);
            return $v === false ? null : (string)$v;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function set(string $key, string $value, int $ttl = 0): bool
    {
        $r = self::connect();
        if (!$r) return false;
        try {
            return $ttl > 0 ? (bool)$r->setex($key, $ttl, $value) : (bool)$r->set($key, $value);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function incr(string $key): int
    {
        $r = self::connect();
        if (!$r) return 0;
        try {
            return (int)$r->incr($key);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Publish JSON payload to channel (pub/sub). */
    public static function publish(string $channel, string $payload): bool
    {
        $r = self::connect();
        if (!$r) return false;
        try {
            return $r->publish($channel, $payload) >= 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function rpush(string $key, string $value, int $maxLen = 500): bool
    {
        $r = self::connect();
        if (!$r) return false;
        try {
            $r->rPush($key, $value);
            $r->lTrim($key, -$maxLen, -1);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function lrange(string $key, int $start, int $end): array
    {
        $r = self::connect();
        if (!$r) return [];
        try {
            $v = $r->lRange($key, $start, $end);
            return is_array($v) ? $v : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
