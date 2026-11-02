<?php
declare(strict_types=1);

namespace Nyx\Shared\Infrastructure\Fs;

/**
 * Filesystem helpers that only mutate paths proven to sit under a trusted root.
 */
final class SafeFs
{
    /**
     * Unlink a file only if its realpath is inside $root (realpath).
     */
    public static function unlinkUnder(string $root, string $absolutePath): bool
    {
        if ($root === '' || $absolutePath === '' || str_contains($absolutePath, "\0")) {
            return false;
        }
        $rootReal = realpath($root);
        if ($rootReal === false || !is_dir($rootReal)) {
            return false;
        }
        if (!is_file($absolutePath)) {
            return false;
        }
        $fileReal = realpath($absolutePath);
        if ($fileReal === false) {
            return false;
        }
        $rootPrefix = rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($fileReal, $rootPrefix) && $fileReal !== $rootReal) {
            return false;
        }
        return unlink($fileReal);
    }
}
