<?php
declare(strict_types=1);

namespace Nyx\Shared\Infrastructure\Fs;

/**
 * Filesystem helpers that only mutate paths proven to sit under a trusted root.
 *
 * This is the single deletion choke-point for the application: callers must not
 * invoke unlink() directly on request-derived paths.
 */
final class SafeFs
{
    /**
     * Delete a file only when its real path is inside $root and every path
     * segment is a strict allow-list (no traversal, no odd characters).
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

        if (!is_file($absolutePath) && !is_link($absolutePath)) {
            return false;
        }

        $fileReal = realpath($absolutePath);
        if ($fileReal === false) {
            // dangling symlink or race — refuse
            return false;
        }

        $rootPrefix = rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($fileReal === $rootReal || !str_starts_with($fileReal, $rootPrefix)) {
            return false;
        }

        $relative = substr($fileReal, strlen($rootPrefix));
        if ($relative === false || $relative === '') {
            return false;
        }

        $segments = explode(DIRECTORY_SEPARATOR, str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative));
        $safeSegments = [];
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                return false;
            }
            // Allow typical store keys: hex ids, json names, etc.
            if (preg_match('/^[A-Za-z0-9._-]+$/', $seg) !== 1) {
                return false;
            }
            $safeSegments[] = $seg;
        }

        // Rebuild path solely from trusted root + allow-listed segments (no caller string reuse).
        $safePath = $rootPrefix . implode(DIRECTORY_SEPARATOR, $safeSegments);
        if (!is_file($safePath)) {
            return false;
        }

        // Final equality check against realpath of rebuilt path
        $safeReal = realpath($safePath);
        if ($safeReal === false || $safeReal !== $fileReal) {
            return false;
        }

        // Security boundary: path is root-jailed + segment-validated. Do not call unlink elsewhere in app/.
        return self::deleteFile($safePath);
    }

    /**
     * Isolated delete of an already-validated absolute path under a data root.
     */
    private static function deleteFile(string $validatedAbsoluteFile): bool
    {
        return unlink($validatedAbsoluteFile); // nosemgrep: php.lang.security.unlink-use.unlink-use -- root-jailed + segment allow-list only
    }
}
