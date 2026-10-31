<?php
declare(strict_types=1);
namespace Nyx\Modules\Messaging\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Storage\FileStore;
final class PinsController {
    private function store(): FileStore { return new FileStore(defined('DATA_DIR') ? DATA_DIR : ''); }
    private function path(string $cid): string { return 'pins/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $cid) . '.json'; }
    private function load(string $cid): array {
        $rec = $this->store()->get($this->path($cid));
        return is_array($rec) ? $rec : ['conversation_id' => $cid, 'items' => []];
    }
    public function pin(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $cid = (string)($b['conversation_id'] ?? $b['conv_id'] ?? '');
        $mid = (string)($b['message_id'] ?? $b['id'] ?? '');
        $actor = (string)($b['user_id'] ?? $b['actor_id'] ?? '');
        if ($cid === '' || $mid === '') return ApiEnvelope::error('conversation_id and message_id required', 422);
        $rec = $this->load($cid);
        $items = is_array($rec['items'] ?? null) ? $rec['items'] : [];
        $exists = false;
        foreach ($items as $it) { if (is_array($it) && ($it['message_id'] ?? '') === $mid) { $exists = true; break; } }
        if (!$exists) $items[] = ['message_id' => $mid, 'pinned_by' => $actor, 'pinned_at_ms' => ApiEnvelope::nowMs()];
        $rec['items'] = array_slice($items, -50);
        $rec['updated_ms'] = ApiEnvelope::nowMs();
        $this->store()->put($this->path($cid), $rec);
        return ApiEnvelope::ok(['pinned' => true, 'already' => $exists, 'message_id' => $mid, 'conversation_id' => $cid, 'count' => count($rec['items']), 'pins' => array_column($rec['items'], 'message_id')]);
    }
    public function unpin(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $cid = (string)($b['conversation_id'] ?? $b['conv_id'] ?? '');
        $mid = (string)($b['message_id'] ?? $b['id'] ?? '');
        if ($cid === '' || $mid === '') return ApiEnvelope::error('conversation_id and message_id required', 422);
        $rec = $this->load($cid);
        $items = is_array($rec['items'] ?? null) ? $rec['items'] : [];
        $rec['items'] = array_values(array_filter($items, static fn($x) => !is_array($x) || (($x['message_id'] ?? '') !== $mid)));
        $rec['updated_ms'] = ApiEnvelope::nowMs();
        $this->store()->put($this->path($cid), $rec);
        return ApiEnvelope::ok(['unpinned' => true, 'message_id' => $mid, 'conversation_id' => $cid, 'count' => count($rec['items']), 'pins' => array_column($rec['items'], 'message_id')]);
    }
    public function list(Request $r): JsonResponse {
        $cid = (string)($r->query('conversation_id') ?? $r->query('conv_id') ?? '');
        if ($cid === '') return ApiEnvelope::error('conversation_id required', 422);
        $rec = $this->load($cid);
        return ApiEnvelope::ok(['conversation_id' => $cid, 'items' => $rec['items'] ?? [], 'pins' => array_values(array_map(static fn($x) => is_array($x) ? ($x['message_id'] ?? '') : '', $rec['items'] ?? [])), 'count' => count($rec['items'] ?? [])]);
    }
}