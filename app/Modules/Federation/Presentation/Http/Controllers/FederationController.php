<?php
declare(strict_types=1);
namespace Nyx\Modules\Federation\Presentation\Http\Controllers;
use Nyx\Core\Http\Request;
use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;
use Nyx\Shared\Infrastructure\Storage\FileStore;

final class FederationController
{
    private function store(): FileStore
    {
        return new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
    }

    public function peers(Request $r): JsonResponse
    {
        $topo = $this->store()->get('topology/nodes.json') ?? ['nodes' => []];
        $nodes = is_array($topo['nodes'] ?? null) ? $topo['nodes'] : [];
        return ApiEnvelope::ok(['peers' => $nodes, 'count' => count($nodes)]);
    }

    public function topology(Request $r): JsonResponse
    {
        $topo = $this->store()->get('topology/nodes.json') ?? ['nodes' => []];
        return ApiEnvelope::ok($topo);
    }

    public function registerPeer(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $url = rtrim((string)($b['url'] ?? $b['base'] ?? ''), '/');
        if ($url === '') {
            return ApiEnvelope::error('url required', 422);
        }
        $host = (string)($b['host'] ?? parse_url($url, PHP_URL_HOST) ?: '');
        $s = $this->store();
        $topo = $s->get('topology/nodes.json') ?? ['nodes' => []];
        $nodes = is_array($topo['nodes'] ?? null) ? $topo['nodes'] : [];
        $found = false;
        foreach ($nodes as &$n) {
            if (!is_array($n)) {
                continue;
            }
            if (($n['url'] ?? '') === $url || ($n['host'] ?? '') === $host) {
                $n['url'] = $url;
                $n['host'] = $host;
                $n['added_ms'] = ApiEnvelope::nowMs();
                $found = true;
                break;
            }
        }
        unset($n);
        if (!$found) {
            $nodes[] = ['url' => $url, 'host' => $host, 'added_ms' => ApiEnvelope::nowMs()];
        }
        $topo['nodes'] = $nodes;
        $topo['updated_ms'] = ApiEnvelope::nowMs();
        $s->put('topology/nodes.json', $topo);
        return ApiEnvelope::ok(['registered' => true, 'host' => $host, 'count' => count($nodes)]);
    }

    /** Ingest gossiped messages into local message store */
    public function gossip(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $messages = $b['messages'] ?? [];
        if (!is_array($messages)) {
            $messages = [];
        }
        // single message form
        if ($messages === [] && !empty($b['id']) && !empty($b['conv_id'])) {
            $messages = [$b];
        }
        $s = $this->store();
        $stored = 0;
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $convId = (string)($msg['conv_id'] ?? '');
            $id = (string)($msg['id'] ?? $msg['message_id'] ?? '');
            if ($convId === '' || $id === '') {
                continue;
            }
            $path = "messages/{$convId}/{$id}.json";
            if ($s->get($path)) {
                continue; // idempotent
            }
            $msg['stored'] = true;
            $msg['via'] = 'gossip';
            if (empty($msg['created_ms'])) {
                $msg['created_ms'] = ApiEnvelope::nowMs();
            }
            $s->put($path, $msg);
            // index
            $key = "messages/{$convId}/_index.json";
            $idx = $s->get($key);
            if (!is_array($idx)) {
                $idx = ['ids' => []];
            }
            $ids = is_array($idx['ids'] ?? null) ? $idx['ids'] : [];
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
                $idx['ids'] = $ids;
                $idx['updated_ms'] = ApiEnvelope::nowMs();
                $s->put($key, $idx);
            }
            $stored++;
        }
        $qid = UlidLikeId::generate();
        return ApiEnvelope::ok(['queued' => true, 'id' => $qid, 'stored' => $stored, 'received' => count($messages)]);
    }

    public function heal(Request $r): JsonResponse
    {
        return ApiEnvelope::ok(['healed' => true, 'time_ms' => ApiEnvelope::nowMs()]);
    }
}
