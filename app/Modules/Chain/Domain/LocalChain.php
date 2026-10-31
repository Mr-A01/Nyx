<?php
declare(strict_types=1);

namespace Nyx\Modules\Chain\Domain;

use Nyx\Shared\Infrastructure\Storage\FileStore;

/**
 * Append-only local chain of work/reward batches.
 * Foundation for later settlement bridges — not a consensus network by itself.
 */
final class LocalChain
{
    public function __construct(private FileStore $store) {}

    private function metaKey(): string
    {
        return 'chain/meta.json';
    }

    public function tip(): array
    {
        $meta = $this->store->get($this->metaKey());
        if (!is_array($meta)) {
            $meta = ['height' => 0, 'tip_hash' => str_repeat('0', 64), 'updated_ms' => 0];
        }
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
     */
    public function append(string $type, array $entries, array $extra = []): array
    {
        $tip = $this->tip();
        $height = (int)$tip['height'] + 1;
        $prev = (string)$tip['tip_hash'];
        $now = (int)(microtime(true) * 1000);
        $body = [
            'height' => $height,
            'type' => $type,
            'prev_hash' => $prev,
            'created_ms' => $now,
            'entries' => $entries,
            'extra' => $extra,
        ];
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $prev . '|' . $payload);
        $body['hash'] = $hash;
        $this->store->put("chain/blocks/{$height}.json", $body);
        $this->store->put($this->metaKey(), [
            'height' => $height,
            'tip_hash' => $hash,
            'updated_ms' => $now,
        ]);
        return $body;
    }

    public function verifyTip(int $maxDepth = 32): array
    {
        $tip = $this->tip();
        $h = (int)$tip['height'];
        if ($h < 1) {
            return ['ok' => true, 'height' => 0, 'checked' => 0];
        }
        $checked = 0;
        $expectedPrev = null;
        for ($i = $h; $i >= max(1, $h - $maxDepth + 1); $i--) {
            $b = $this->getBlock($i);
            if (!is_array($b)) {
                return ['ok' => false, 'error' => "missing block {$i}", 'height' => $h];
            }
            $copy = $b;
            $hash = (string)($copy['hash'] ?? '');
            unset($copy['hash']);
            $payload = json_encode($copy, JSON_UNESCAPED_SLASHES);
            $prev = (string)($b['prev_hash'] ?? '');
            $re = hash('sha256', $prev . '|' . $payload);
            if (!hash_equals($re, $hash)) {
                return ['ok' => false, 'error' => "hash mismatch at {$i}", 'height' => $h];
            }
            if ($expectedPrev !== null && !hash_equals($hash, $expectedPrev)) {
                return ['ok' => false, 'error' => "link break at {$i}", 'height' => $h];
            }
            $expectedPrev = $prev;
            $checked++;
        }
        return ['ok' => true, 'height' => $h, 'checked' => $checked, 'tip_hash' => $tip['tip_hash']];
    }
}
