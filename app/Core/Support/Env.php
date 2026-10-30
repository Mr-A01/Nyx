<?php
declare(strict_types=1);

namespace Nyx\Core\Support;

final class Env
{
    private static bool $loaded = false;

    public static function load(string $basePath): void
    {
        if (self::$loaded) {
            return;
        }
        $file = rtrim($basePath, '/') . '/.env';
        if (!is_file($file)) {
            self::$loaded = true;
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if (
                (str_starts_with($v, '"') && str_ends_with($v, '"')) ||
                (str_starts_with($v, "'") && str_ends_with($v, "'"))
            ) {
                $v = substr($v, 1, -1);
            }
            if ($k !== '' && getenv($k) === false) {
                putenv("{$k}={$v}");
                $_ENV[$k] = $v;
            }
        }
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $v = $_ENV[$key] ?? getenv($key);
        if ($v === false || $v === null || $v === '') {
            return $default;
        }
        return $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }
}
