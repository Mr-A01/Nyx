<?php
declare(strict_types=1);
namespace Nyx\Shared\Infrastructure\Storage;
final class FileStore {
    public function __construct(private string $root) {
        if (!is_dir($this->root)) @mkdir($this->root, 0750, true);
    }
    public function get(string $path): ?array {
        $full = $this->resolve($path);
        if (!is_file($full)) return null;
        $raw = @file_get_contents($full);
        if ($raw === false || $raw === '') return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
    public function put(string $path, array $data): bool {
        $full = $this->resolve($path);
        $dir = dirname($full);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;
        $tmp = $full . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
        return @rename($tmp, $full);
    }
    public function delete(string $path): bool {
        $full = $this->resolve($path);
        return is_file($full) ? @unlink($full) : true;
    }
    public function listJson(string $dirPath): array {
        $full = $this->resolve($dirPath);
        if (!is_dir($full)) return [];
        $out = [];
        foreach (glob($full . '/*.json') ?: [] as $f) {
            if (str_contains(basename($f), '_index')) continue;
            $raw = @file_get_contents($f);
            $d = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($d)) $out[] = $d;
        }
        return $out;
    }
    public function root(): string { return $this->root; }
    private function resolve(string $path): string {
        $path = ltrim(str_replace(['..', '\\'], ['', '/'], $path), '/');
        return $this->root . '/' . $path;
    }
}
