<?php
declare(strict_types=1);

namespace Nyx\Shared\Infrastructure\Storage;

/**
 * JSON file store rooted at data/. Path traversal is rejected.
 */
final class FileStore
{
    public function __construct(private string $root)
    {
        if (!is_dir($this->root)) {
            @mkdir($this->root, 0750, true);
        }
        $resolved = realpath($this->root);
        if ($resolved !== false) {
            $this->root = $resolved;
        }
    }

    public function get(string $path): ?array
    {
        $full = $this->resolve($path);
        if ($full === null || !is_file($full)) {
            return null;
        }
        $raw = @file_get_contents($full);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function put(string $path, array $data): bool
    {
        $full = $this->resolve($path);
        if ($full === null) {
            return false;
        }
        $dir = dirname($full);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $tmp = $full . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        return @rename($tmp, $full);
    }

    public function delete(string $path): bool
    {
        $full = $this->resolve($path);
        if ($full === null) {
            return false;
        }
        return is_file($full) ? @unlink($full) : true;
    }

    public function listJson(string $dirPath): array
    {
        $full = $this->resolve($dirPath);
        if ($full === null || !is_dir($full)) {
            return [];
        }
        $out = [];
        foreach (glob($full . '/*.json') ?: [] as $f) {
            if (str_contains(basename($f), '_index')) {
                continue;
            }
            $raw = @file_get_contents($f);
            $d = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($d)) {
                $out[] = $d;
            }
        }
        return $out;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Resolve a relative store path under $root. Returns null if unsafe.
     */
    private function resolve(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        // Normalize separators; strip traversal sequences aggressively
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return null; // hard reject traversal
            }
            // only allow safe path segments
            if (!preg_match('/^[A-Za-z0-9._@+=\-]+$/', $seg)) {
                return null;
            }
            $parts[] = $seg;
        }
        if ($parts === []) {
            return null;
        }
        $full = $this->root . '/' . implode('/', $parts);
        // If parent exists, ensure realpath stays under root
        $parent = dirname($full);
        if (is_dir($parent)) {
            $realParent = realpath($parent);
            if ($realParent === false || !str_starts_with($realParent, $this->root)) {
                return null;
            }
        }
        return $full;
    }
}
