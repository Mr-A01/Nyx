<?php
declare(strict_types=1);
namespace Nyx\Shared\Infrastructure\Events;
use Nyx\Shared\Infrastructure\Storage\FileStore;
final class EventBus {
    public function __construct(private FileStore $store) {}
    public function publish(string $channel, array $event): string {
        $id = bin2hex(random_bytes(8));
        $event['id'] = $id;
        $event['ts_ms'] = (int) floor(microtime(true) * 1000);
        $this->store->put("events/{$channel}/{$id}.json", $event);
        // keep channel index (last 100)
        $idx = $this->store->get("events/{$channel}/_index.json") ?? ['ids' => []];
        $ids = is_array($idx['ids'] ?? null) ? $idx['ids'] : [];
        $ids[] = $id;
        if (count($ids) > 100) $ids = array_slice($ids, -100);
        $this->store->put("events/{$channel}/_index.json", ['ids' => $ids, 'updated_ms' => $event['ts_ms']]);
        return $id;
    }
    public function since(string $channel, int $sinceMs, int $limit = 50): array {
        $idx = $this->store->get("events/{$channel}/_index.json") ?? ['ids' => []];
        $out = [];
        foreach (($idx['ids'] ?? []) as $id) {
            $e = $this->store->get("events/{$channel}/{$id}.json");
            if (!is_array($e)) continue;
            if ((int)($e['ts_ms'] ?? 0) <= $sinceMs) continue;
            $out[] = $e;
            if (count($out) >= $limit) break;
        }
        return $out;
    }
}
