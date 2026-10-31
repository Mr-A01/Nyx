<?php
declare(strict_types=1);

namespace Nyx\Modules\Chain\Domain;

use Nyx\Shared\Infrastructure\Storage\FileStore;

/**
 * NYX Work-Chain — append-only, hash-linked, Merkle-committed ledger of network work.
 *
 * Not global consensus: each relay keeps a verifiable local chain for rewards + settlement export.
 */
final class LocalChain
{
    public const NETWORK = 'nyx-work-chain';
    public const VERSION = 2;
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private FileStore $store) {}

    private function metaKey(): string
    {
        return 'chain/meta.json';
    }

    public function tip(): array
    {
        $meta = $this->store->get($this->metaKey());
        if (!is_array($meta)) {
            return [
                'height' => 0,
                'tip_hash' => self::GENESIS_HASH,
                'updated_ms' => 0,
                'network' => self::NETWORK,
                'version' => self::VERSION,
                'total_work' => 0,
            ];
        }
        if (empty($meta['tip_hash'])) {
            $meta['tip_hash'] = self::GENESIS_HASH;
        }
        $meta['network'] = self::NETWORK;
        return $meta;
    }

    public function getBlock(int $height): ?array
    {
        if ($height < 1) {
            return null;
        }
        $b = $this->store->get("chain/blocks/{$height}.json");
        return is_array($b) ? $b : null;
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function append(string $type, array $entries, array $extra = []): array
    {
        $tip = $this->tip();
        $height = (int)$tip['height'] + 1;
        $prev = (string)($tip['tip_hash'] ?: self::GENESIS_HASH);
        $now = (int)(microtime(true) * 1000);
        $node = strtolower((string)($_SERVER['HTTP_HOST'] ?? ($extra['node'] ?? 'node')));

        $normEntries = [];
        foreach ($entries as $e) {
            if (is_array($e)) {
                $normEntries[] = $e;
            }
        }
        $merkle = $this->merkleRoot($normEntries);
        $workScore = $this->scoreEntries($normEntries);

        $body = [
            'version' => self::VERSION,
            'network' => self::NETWORK,
            'height' => $height,
            'type' => $type,
            'prev_hash' => $prev,
            'created_ms' => $now,
            'node' => $node,
            'entry_count' => count($normEntries),
            'work_score' => $workScore,
            'merkle_root' => $merkle,
            'entries' => $normEntries,
            'extra' => $extra,
        ];
        $body['hash'] = $this->hashBlock($body);

        $this->store->put("chain/blocks/{$height}.json", $body);
        $totalWork = (int)($tip['total_work'] ?? 0) + $workScore;
        $this->store->put($this->metaKey(), [
            'height' => $height,
            'tip_hash' => $body['hash'],
            'updated_ms' => $now,
            'network' => self::NETWORK,
            'version' => self::VERSION,
            'total_work' => $totalWork,
            'last_type' => $type,
            'last_node' => $node,
        ]);
        return $body;
    }

    /**
     * Verify chain linkage + hashes. $maxDepth 0 = full chain.
     *
     * @return array<string,mixed>
     */
    public function verifyTip(int $maxDepth = 64): array
    {
        $tip = $this->tip();
        $h = (int)$tip['height'];
        if ($h < 1) {
            return [
                'ok' => true,
                'height' => 0,
                'checked' => 0,
                'tip_hash' => self::GENESIS_HASH,
                'network' => self::NETWORK,
            ];
        }
        $from = $maxDepth <= 0 ? 1 : max(1, $h - $maxDepth + 1);
        $checked = 0;
        $work = 0;
        $expectedNextPrev = null; // when walking high→low, block hash must equal next block's prev_hash

        for ($i = $h; $i >= $from; $i--) {
            $b = $this->getBlock($i);
            if (!is_array($b)) {
                return ['ok' => false, 'error' => "missing block {$i}", 'height' => $h, 'checked' => $checked];
            }
            $hash = (string)($b['hash'] ?? '');
            $prev = (string)($b['prev_hash'] ?? '');
            $re = $this->hashBlock($b);
            if (!hash_equals($re, $hash)) {
                return ['ok' => false, 'error' => "hash mismatch at {$i}", 'height' => $h, 'checked' => $checked];
            }
            // Merkle re-check for v2
            if ((int)($b['version'] ?? 1) >= 2) {
                $entries = is_array($b['entries'] ?? null) ? $b['entries'] : [];
                $mr = $this->merkleRoot($entries);
                if (!hash_equals($mr, (string)($b['merkle_root'] ?? ''))) {
                    return ['ok' => false, 'error' => "merkle mismatch at {$i}", 'height' => $h, 'checked' => $checked];
                }
            }
            if ($expectedNextPrev !== null && !hash_equals($hash, $expectedNextPrev)) {
                return ['ok' => false, 'error' => "link break at {$i}", 'height' => $h, 'checked' => $checked];
            }
            if ($i === 1 && $prev !== self::GENESIS_HASH && $prev !== str_repeat('0', 64)) {
                // allow legacy genesis zeros
                if (!preg_match('/^0+$/', $prev)) {
                    return ['ok' => false, 'error' => 'invalid genesis prev_hash', 'height' => $h, 'checked' => $checked];
                }
            }
            $expectedNextPrev = $prev;
            $work += (int)($b['work_score'] ?? $this->scoreEntries(is_array($b['entries'] ?? null) ? $b['entries'] : []));
            $checked++;
        }

        // Tip meta must match tip block
        $tipBlock = $this->getBlock($h);
        if (is_array($tipBlock) && !hash_equals((string)$tipBlock['hash'], (string)$tip['tip_hash'])) {
            return ['ok' => false, 'error' => 'tip meta desync', 'height' => $h, 'checked' => $checked];
        }

        return [
            'ok' => true,
            'height' => $h,
            'checked' => $checked,
            'from' => $from,
            'tip_hash' => $tip['tip_hash'],
            'work_score_range' => $work,
            'network' => self::NETWORK,
        ];
    }

    /** Rebuild meta.json by scanning blocks on disk. */
    public function reindex(): array
    {
        $dir = $this->store->root() . '/chain/blocks';
        $max = 0;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                $n = (int)basename($f, '.json');
                if ($n > $max) {
                    $max = $n;
                }
            }
        }
        if ($max < 1) {
            $meta = [
                'height' => 0,
                'tip_hash' => self::GENESIS_HASH,
                'updated_ms' => (int)(microtime(true) * 1000),
                'network' => self::NETWORK,
                'version' => self::VERSION,
                'total_work' => 0,
            ];
            $this->store->put($this->metaKey(), $meta);
            return ['height' => 0, 'tip_hash' => self::GENESIS_HASH, 'reindexed' => true];
        }
        $verify = $this->verifyTip(0);
        if (empty($verify['ok'])) {
            return ['reindexed' => false, 'error' => $verify['error'] ?? 'verify failed', 'verify' => $verify];
        }
        $tipBlock = $this->getBlock($max);
        $totalWork = 0;
        for ($i = 1; $i <= $max; $i++) {
            $b = $this->getBlock($i);
            if (is_array($b)) {
                $entries = is_array($b['entries'] ?? null) ? $b['entries'] : [];
                $totalWork += (int)($b['work_score'] ?? 0);
                if ((int)($b['work_score'] ?? 0) === 0 && $entries !== []) {
                    $totalWork += $this->scoreEntries($entries);
                }
            }
        }
        $meta = [
            'height' => $max,
            'tip_hash' => (string)($tipBlock['hash'] ?? self::GENESIS_HASH),
            'updated_ms' => (int)(microtime(true) * 1000),
            'network' => self::NETWORK,
            'version' => self::VERSION,
            'total_work' => $totalWork,
            'last_type' => $tipBlock['type'] ?? null,
            'last_node' => $tipBlock['node'] ?? null,
        ];
        $this->store->put($this->metaKey(), $meta);
        return ['reindexed' => true, 'height' => $max, 'tip_hash' => $meta['tip_hash'], 'total_work' => $totalWork, 'verify' => $verify];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function range(int $from, int $to, int $max = 100): array
    {
        $tipH = (int)$this->tip()['height'];
        if ($to < 1 || $from < 1) {
            return [];
        }
        $to = min($to, $tipH);
        $from = max(1, $from);
        if ($from > $to) {
            return [];
        }
        if (($to - $from + 1) > $max) {
            $from = $to - $max + 1;
        }
        $out = [];
        for ($i = $from; $i <= $to; $i++) {
            $b = $this->getBlock($i);
            if (is_array($b)) {
                $out[] = $b;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function stats(): array
    {
        $tip = $this->tip();
        $h = (int)$tip['height'];
        $types = [];
        $sample = min($h, 50);
        for ($i = $h; $i >= max(1, $h - $sample + 1); $i--) {
            $b = $this->getBlock($i);
            if (!is_array($b)) {
                continue;
            }
            $t = (string)($b['type'] ?? 'unknown');
            $types[$t] = ($types[$t] ?? 0) + 1;
        }
        return [
            'network' => self::NETWORK,
            'version' => self::VERSION,
            'height' => $h,
            'tip_hash' => $tip['tip_hash'] ?? self::GENESIS_HASH,
            'total_work' => (int)($tip['total_work'] ?? 0),
            'updated_ms' => (int)($tip['updated_ms'] ?? 0),
            'recent_types' => $types,
            'node' => strtolower((string)($_SERVER['HTTP_HOST'] ?? '')),
        ];
    }

    /**
     * Canonical block hash. Supports v1 legacy and v2.
     * @param array<string,mixed> $block
     */
    public function hashBlock(array $block): string
    {
        $version = (int)($block['version'] ?? 1);
        $copy = $block;
        unset($copy['hash']);

        if ($version >= 2) {
            $canonical = [
                'version' => (int)($copy['version'] ?? self::VERSION),
                'network' => (string)($copy['network'] ?? self::NETWORK),
                'height' => (int)($copy['height'] ?? 0),
                'type' => (string)($copy['type'] ?? ''),
                'prev_hash' => (string)($copy['prev_hash'] ?? ''),
                'created_ms' => (int)($copy['created_ms'] ?? 0),
                'node' => (string)($copy['node'] ?? ''),
                'entry_count' => (int)($copy['entry_count'] ?? 0),
                'work_score' => (int)($copy['work_score'] ?? 0),
                'merkle_root' => (string)($copy['merkle_root'] ?? ''),
                'entries' => $copy['entries'] ?? [],
                'extra' => $copy['extra'] ?? [],
            ];
            return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        // v1 legacy: prev|json(body without hash)
        $prev = (string)($copy['prev_hash'] ?? '');
        $payload = json_encode($copy, JSON_UNESCAPED_SLASHES);
        return hash('sha256', $prev . '|' . $payload);
    }

    /** @param list<array<string,mixed>> $entries */
    public function merkleRoot(array $entries): string
    {
        if ($entries === []) {
            return str_repeat('0', 64);
        }
        $layer = [];
        foreach ($entries as $e) {
            $layer[] = hash('sha256', json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        while (count($layer) > 1) {
            $next = [];
            $n = count($layer);
            for ($i = 0; $i < $n; $i += 2) {
                if ($i + 1 < $n) {
                    $next[] = hash('sha256', $layer[$i] . $layer[$i + 1]);
                } else {
                    $next[] = hash('sha256', $layer[$i] . $layer[$i]);
                }
            }
            $layer = $next;
        }
        return $layer[0];
    }

    /** @param list<array<string,mixed>> $entries */
    private function scoreEntries(array $entries): int
    {
        $score = 0;
        foreach ($entries as $e) {
            $score += (int)($e['reward'] ?? 0);
            if (isset($e['works']) && is_array($e['works'])) {
                foreach ($e['works'] as $w) {
                    if (is_array($w)) {
                        $score += (int)($w['reward'] ?? 0);
                    }
                }
            }
        }
        return max(0, $score);
    }
}
