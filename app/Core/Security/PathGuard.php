<?php
declare(strict_types=1);

namespace Nyx\Core\Security;

final class PathGuard
{
    public static function safeJoin(string $root, string $relative): ?string
    {
        $root = realpath($root) ?: $root;
        $relative = str_replace(['\\', "\0"], ['/', ''], $relative);
        $relative = ltrim($relative, '/');
        if (str_contains($relative, '..')) {
            return null;
        }
        $full = $root . '/' . $relative;
        $realRoot = realpath($root);
        if ($realRoot === false) {
            return $full;
        }
        // for existing paths, ensure under root
        $parent = realpath(dirname($full));
        if ($parent !== false && !str_starts_with($parent, $realRoot)) {
            return null;
        }
        return $full;
    }
}
