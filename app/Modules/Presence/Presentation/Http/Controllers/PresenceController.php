<?php
declare(strict_types=1);
namespace Nyx\Modules\Presence\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Http\AuthContext;
final class PresenceController {
    private function store(): FileStore { return new FileStore(defined('DATA_DIR') ? DATA_DIR : ''); }
    private function safe(string $s): string { return preg_replace('/[^a-zA-Z0-9._-]+/', '_', $s) ?: 'x'; }
    private function dataRoot(): string {
        $d = defined('DATA_DIR') ? (string)DATA_DIR : '';
        if ($d === '') $d = sys_get_temp_dir() . '/nyx-data';
        if (!is_dir($d)) @mkdir($d, 0750, true);
        return $d;
    }
    public function heartbeat(Request $request): JsonResponse {
        $body = $request->json() ?? [];
        $userId = AuthContext::userId($request, $body);
        if ($userId === '') $userId = (string)($body['user_id'] ?? '');
        if ($userId === '') return ApiEnvelope::error('user_id required', 422);
        $rec = ['user_id' => $userId, 'status' => (string)($body['status'] ?? 'online'), 'last_seen_ms' => ApiEnvelope::nowMs()];
        $this->store()->put('presence/' . strtolower($userId) . '.json', $rec);
        return ApiEnvelope::ok($rec);
    }
    public function batch(Request $request): JsonResponse {
        $body = $request->json() ?? [];
        $ids = $body['user_ids'] ?? $body['ids'] ?? null;
        if (!is_array($ids) || count($ids) === 0) {
            $q = (string)($request->query('user_ids') ?? $request->query('ids') ?? '');
            $ids = $q !== '' ? array_filter(array_map('trim', explode(',', $q))) : [];
        }
        $out = [];
        foreach ($ids as $id) {
            $id = strtolower(trim((string)$id));
            if ($id === '') continue;
            $rec = $this->store()->get('presence/' . $id . '.json');
            $out[$id] = $rec ?: ['user_id' => $id, 'status' => 'offline', 'last_seen_ms' => 0];
        }
        return ApiEnvelope::ok(['presence' => $out]);
    }
    public function typing(Request $request): JsonResponse {
        $body = $request->json() ?? [];
        $userId = AuthContext::userId($request, $body);
        if ($userId === '') $userId = strtolower(trim((string)($body['user_id'] ?? '')));
        $convId = (string)($body['conv_id'] ?? $body['conversation_id'] ?? $body['space_id'] ?? '');
        if ($userId === '' || $convId === '') return ApiEnvelope::error('user_id and conv_id required', 422);
        $now = ApiEnvelope::nowMs();
        $ttl = (int)($body['ttl_sec'] ?? 8);
        if ($ttl < 1) $ttl = 8;
        if ($ttl > 60) $ttl = 60;
        $rec = ['user_id' => $userId, 'conv_id' => $convId, 'typing' => true, 'ms' => $now, 'expires_ms' => $now + ($ttl * 1000)];
        $dir = $this->dataRoot() . '/typing/' . $this->safe($convId);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        file_put_contents($dir . '/' . $this->safe($userId) . '.json', json_encode($rec));
        return ApiEnvelope::ok($rec);
    }
    public function typingGet(Request $request): JsonResponse {
        $convId = (string)($request->query('conv_id') ?? $request->query('conversation_id') ?? '');
        if ($convId === '') return ApiEnvelope::error('conv_id required', 422);
        $dir = $this->dataRoot() . '/typing/' . $this->safe($convId);
        $items = []; $now = ApiEnvelope::nowMs();
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                $rec = json_decode((string)file_get_contents($f), true);
                if (!is_array($rec)) continue;
                $exp = (int)($rec['expires_ms'] ?? 0);
                if ($exp > 0 && $exp < $now) { @unlink($f); continue; }
                $items[] = $rec;
            }
        }
        return ApiEnvelope::ok(['conversation_id' => $convId, 'conv_id' => $convId, 'items' => $items, 'typing' => $items, 'count' => count($items)]);
    }
    public function listTyping(Request $request): JsonResponse {
        return $this->typingGet($request);
    }
}
