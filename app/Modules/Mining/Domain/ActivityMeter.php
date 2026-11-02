<?php
declare(strict_types=1);

namespace Nyx\Modules\Mining\Domain;

use Nyx\Shared\Infrastructure\Storage\FileStore;

/**
 * Server-side activity accounting for useful relay work.
 * Message sends, probes, and selftests increment counters;
 * miners claim against unclaimed units (not self-reported busywork).
 */
final class ActivityMeter
{
    public function __construct(private FileStore $store) {}

    public static function make(): self
    {
        $root = defined('DATA_DIR') ? DATA_DIR : (defined('NYX_DATA_DIR') ? NYX_DATA_DIR : '');
        return new self(new FileStore($root));
    }

    private function day(): string
    {
        return gmdate('Ymd');
    }

    private function key(string $host, string $type): string
    {
        $host = preg_replace('/[^a-z0-9.\-]/', '', strtolower($host)) ?: 'local';
        $type = preg_replace('/[^a-z0-9_]/', '', strtolower($type)) ?: 'unknown';
        return "mining/activity/{$this->day()}/{$host}/{$type}.json";
    }

    public function host(): string
    {
        return strtolower((string)($_SERVER['HTTP_HOST'] ?? 'local'));
    }

    /** Record useful work performed by this node. */
    public function record(string $type, int $units = 1, ?string $host = null): void
    {
        if ($units < 1) {
            return;
        }
        $units = min(1000, $units);
        $host = $host ?? $this->host();
        $k = $this->key($host, $type);
        $rec = $this->store->get($k) ?? [
            'host' => $host,
            'type' => $type,
            'day' => $this->day(),
            'total' => 0,
            'claimed' => 0,
            'updated_ms' => 0,
        ];
        $rec['total'] = (int)($rec['total'] ?? 0) + $units;
        $rec['updated_ms'] = (int) floor(microtime(true) * 1000);
        $this->store->put($k, $rec);
    }

    /**
     * Claim unclaimed units for a work type on this host.
     * @return array{claimed:int,remaining:int,total:int}
     */
    public function claim(string $type, int $maxUnits, ?string $host = null): array
    {
        $host = $host ?? $this->host();
        $k = $this->key($host, $type);
        $rec = $this->store->get($k) ?? [
            'host' => $host,
            'type' => $type,
            'day' => $this->day(),
            'total' => 0,
            'claimed' => 0,
        ];
        $total = (int)($rec['total'] ?? 0);
        $claimed = (int)($rec['claimed'] ?? 0);
        $available = max(0, $total - $claimed);
        $take = min($maxUnits, $available);
        if ($take > 0) {
            $rec['claimed'] = $claimed + $take;
            $rec['updated_ms'] = (int) floor(microtime(true) * 1000);
            $this->store->put($k, $rec);
        }
        return [
            'claimed' => $take,
            'remaining' => max(0, $total - (int)$rec['claimed']),
            'total' => $total,
        ];
    }

    /** Snapshot of today's activity for this host. */
    public function snapshot(?string $host = null): array
    {
        $host = $host ?? $this->host();
        $hostSafe = preg_replace('/[^a-z0-9.\-]/', '', strtolower($host)) ?: 'local';
        $dir = $this->store->root() . '/mining/activity/' . $this->day() . '/' . $hostSafe;
        $out = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                $d = json_decode((string)@file_get_contents($f), true);
                if (is_array($d)) {
                    $out[] = $d;
                }
            }
        }
        return ['host' => $host, 'day' => $this->day(), 'items' => $out];
    }
}
