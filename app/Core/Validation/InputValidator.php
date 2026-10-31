<?php
declare(strict_types=1);

namespace Nyx\Core\Validation;

final class InputValidator
{
    public static function string(mixed $v, int $max = 8000, int $min = 0): ?string
    {
        if (!is_string($v) && !is_numeric($v)) {
            return null;
        }
        $s = trim((string) $v);
        $len = (function_exists('mb_strlen') ? mb_strlen($s) : strlen($s));
        if ($len < $min || $len > $max) {
            return null;
        }
        return $s;
    }

    public static function id(mixed $v, int $max = 128): ?string
    {
        $s = self::string($v, $max, 1);
        if ($s === null) {
            return null;
        }
        if (!preg_match('/^[a-zA-Z0-9._:@\\-]+$/', $s)) {
            return null;
        }
        return $s;
    }

    public static function url(mixed $v): ?string
    {
        $s = self::string($v, 2048, 8);
        if ($s === null) {
            return null;
        }
        if (!filter_var($s, FILTER_VALIDATE_URL)) {
            return null;
        }
        $p = parse_url($s);
        if (!isset($p['scheme']) || !in_array($p['scheme'], ['http', 'https'], true)) {
            return null;
        }
        return $s;
    }

    /** @param array<string,mixed> $body */
    public static function requireKeys(array $body, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $body) || $body[$k] === null || $body[$k] === '') {
                return "missing_field:{$k}";
            }
        }
        return null;
    }
}
