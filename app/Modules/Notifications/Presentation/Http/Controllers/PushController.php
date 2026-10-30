<?php
declare(strict_types=1);
namespace Nyx\Modules\Notifications\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Http\AuthContext;

final class PushController {
    private function store(): FileStore {
        return new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
    }
    public function subscribe(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        if (($b['endpoint'] ?? '') === '') return ApiEnvelope::error('endpoint required', 422);
        $uid = AuthContext::userId($r, $b);
        if ($uid === '') $uid = (string)($b['user_id'] ?? '');
        $id = UlidLikeId::generate('push');
        $rec = ['id' => $id, 'endpoint' => $b['endpoint'], 'keys' => $b['keys'] ?? [], 'user_id' => $uid, 'created_ms' => ApiEnvelope::nowMs()];
        $this->store()->put("push/{$id}.json", $rec);
        return ApiEnvelope::ok(['subscribed' => true, 'id' => $id, 'user_id' => $uid]);
    }
    public function unsubscribe(Request $r): JsonResponse {
        $id = (string)(($r->json() ?? [])['id'] ?? '');
        if ($id === '') return ApiEnvelope::error('id required', 422);
        $this->store()->delete("push/{$id}.json");
        return ApiEnvelope::ok(['unsubscribed' => true, 'id' => $id]);
    }
    public function prefs(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $uid = AuthContext::userId($r, $b);
        if ($uid === '') $uid = (string)($b['user_id'] ?? $r->query('user_id') ?? '');
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $s = $this->store();
        if (in_array($r->method(), ['POST', 'PUT'], true)) {
            $prefs = ['user_id' => $uid, 'prefs' => $b['prefs'] ?? $b, 'updated_ms' => ApiEnvelope::nowMs()];
            $s->put("notif_prefs/{$uid}.json", $prefs);
            return ApiEnvelope::ok($prefs);
        }
        return ApiEnvelope::ok($s->get("notif_prefs/{$uid}.json") ?? ['user_id' => $uid, 'prefs' => ['messages' => true, 'mentions' => true]]);
    }
    /** In-app notification feed (membership, mentions, system). */
    public function list(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $uid = AuthContext::userId($r, $b);
        if ($uid === '') $uid = (string)($b['user_id'] ?? $r->query('user_id') ?? '');
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $s = $this->store();
        $items = [];
        $rec = $s->get("notifications/{$uid}.json");
        if (is_array($rec) && is_array($rec['items'] ?? null)) {
            $items = $rec['items'];
        }
        return ApiEnvelope::ok([
            'user_id' => $uid,
            'items' => $items,
            'notifications' => $items,
            'count' => count($items),
            'unread' => count(array_filter($items, static fn($x) => empty($x['read']))),
        ]);
    }
    public function markRead(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $uid = AuthContext::userId($r, $b);
        if ($uid === '') $uid = (string)($b['user_id'] ?? '');
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $s = $this->store();
        $rec = $s->get("notifications/{$uid}.json");
        if (!is_array($rec)) $rec = ['user_id' => $uid, 'items' => []];
        $id = (string)($b['id'] ?? '');
        $items = is_array($rec['items'] ?? null) ? $rec['items'] : [];
        foreach ($items as &$it) {
            if ($id === '' || (string)($it['id'] ?? '') === $id) $it['read'] = true;
        }
        unset($it);
        $rec['items'] = $items;
        $rec['updated_ms'] = ApiEnvelope::nowMs();
        $s->put("notifications/{$uid}.json", $rec);
        return ApiEnvelope::ok(['user_id' => $uid, 'count' => count($items)]);
    }
}
