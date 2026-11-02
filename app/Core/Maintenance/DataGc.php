<?php
declare(strict_types=1);

namespace Nyx\Core\Maintenance;

use Nyx\Shared\Infrastructure\Storage\FileStore;

/**
 * Opportunistic disk GC for shared-hosting safe cleanup.
 * Caps work per run so requests stay fast.
 */
final class DataGc
{
    private const MINING_NONCE_TTL_MS = 86_400_000;      // 24h
    private const MINING_REF_TTL_MS = 86_400_000;        // 24h
    private const FED_NONCE_TTL_MS = 600_000;            // 10m

    public function __construct(private FileStore $store) {}

    public static function make(): self
    {
        $root = defined('DATA_DIR') ? DATA_DIR : (defined('NYX_DATA_DIR') ? NYX_DATA_DIR : '');
        return new self(new FileStore($root));
    }

    /**
     * @return array<string,int>
     */
    public function run(int $maxFiles = 80): array
    {
        return [
            'rate_removed' => $this->gcRate($maxFiles),
            'sessions_removed' => $this->gcSessions($maxFiles),
            'mining_nonces_removed' => $this->gcAgedDir('mining/nonces', self::MINING_NONCE_TTL_MS, 'used_ms', $maxFiles),
            'mining_refs_removed' => $this->gcAgedDir('mining/refs', self::MINING_REF_TTL_MS, 'claimed_ms', $maxFiles),
            'federation_nonces_removed' => $this->gcAgedDir('federation/nonces', self::FED_NONCE_TTL_MS, 'used_ms', $maxFiles),
        ];
    }

    private function gcRate(int $maxFiles): int
    {
        $now = time();
        $removed = 0;
        $n = 0;
        $dirs = [
            $this->store->root() . '/rate',
            $this->store->root() . '/rate/auth',
            $this->store->root() . '/rate/auth/lock',
        ];
        foreach ($dirs as $root) {
            if (!is_dir($root)) {
                continue;
            }
            foreach (glob($root . '/*.json') ?: [] as $f) {
                if (++$n > $maxFiles * 2) {
                    return $removed;
                }
                $raw = @file_get_contents($f);
                $data = $raw !== false ? json_decode($raw, true) : null;
                if (!is_array($data)) {
                    @unlink($f);
                    $removed++;
                    continue;
                }
                if (isset($data['hits']) && is_array($data['hits'])) {
                    $window = (int)($data['window'] ?? 60);
                    $hits = array_values(array_filter(
                        $data['hits'],
                        static fn ($t) => is_int($t) && $t > $now - max(60, $window)
                    ));
                    if ($hits === []) {
                        @unlink($f);
                        $removed++;
                    }
                    continue;
                }
                $until = (int)($data['until'] ?? 0);
                $reset = (int)($data['reset'] ?? 0);
                $window = (int)($data['window'] ?? 0);
                if (($reset > 0 && $reset < $now) || ($window > 0 && $window < $now && $until <= $now)) {
                    @unlink($f);
                    $removed++;
                }
            }
        }
        return $removed;
    }

    private function gcSessions(int $maxFiles): int
    {
        $root = $this->store->root() . '/sessions';
        if (!is_dir($root)) {
            return 0;
        }
        $nowMs = (int) floor(microtime(true) * 1000);
        $removed = 0;
        $n = 0;
        foreach (glob($root . '/*.json') ?: [] as $f) {
            if (++$n > $maxFiles) {
                break;
            }
            $raw = @file_get_contents($f);
            $data = $raw !== false ? json_decode($raw, true) : null;
            if (!is_array($data)) {
                @unlink($f);
                $removed++;
                continue;
            }
            $exp = (int)($data['expires_ms'] ?? 0);
            if ($exp > 0 && $exp < $nowMs) {
                @unlink($f);
                $removed++;
            }
        }
        return $removed;
    }

    private function gcAgedDir(string $rel, int $ttlMs, string $tsKey, int $maxFiles): int
    {
        $root = $this->store->root() . '/' . trim($rel, '/');
        if (!is_dir($root)) {
            return 0;
        }
        $nowMs = (int) floor(microtime(true) * 1000);
        $removed = 0;
        $n = 0;
        foreach (glob($root . '/*.json') ?: [] as $f) {
            if (++$n > $maxFiles) {
                break;
            }
            $raw = @file_get_contents($f);
            $data = $raw !== false ? json_decode($raw, true) : null;
            if (!is_array($data)) {
                @unlink($f);
                $removed++;
                continue;
            }
            $ts = (int)($data[$tsKey] ?? $data['expires_ms'] ?? $data['created_ms'] ?? 0);
            if ($ts <= 0 || ($nowMs - $ts) > $ttlMs) {
                @unlink($f);
                $removed++;
            }
        }
        return $removed;
    }
}
