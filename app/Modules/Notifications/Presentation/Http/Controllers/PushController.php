<?php
declare(strict_types=1);

namespace Nyx\Modules\Notifications\Presentation\Http\Controllers;

use Nyx\Core\Http\Request;
use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Http\AuthContext;

final class PushController
{
    private function store(): FileStore
    {
        return new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
    }

    private function requireUser(Request $r, array $b = []): string
    {
        $uid = AuthContext::userId($r, $b);
        return $uid !== '' ? strtolower($uid) : '';
    }

    public function subscribe(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $uid = $this->requireUser($r, $b);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        if (($b['endpoint'] ?? '') === '') {
            return ApiEnvelope::error('endpoint required', 422);
        }
        $id = UlidLikeId::generate('push');
        $rec = [
            'id' => $id,
            'endpoint' => substr((string)$b['endpoint'], 0, 2048),
            'keys' => is_array($b['keys'] ?? null) ? $b['keys'] : [],
            'user_id' => $uid,
            'created_ms' => ApiEnvelope::nowMs(),
        ];
        $this->store()->put("push/{$id}.json", $rec);
        // index by user
        $idx = $this->store()->get("push/by_user/{$uid}.json") ?? ['ids' => []];
        $ids = is_array($idx['ids'] ?? null) ? $idx['ids'] : [];
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }
        $this->store()->put("push/by_user/{$uid}.json", ['user_id' => $uid, 'ids' => array_slice($ids, -50)]);
        return ApiEnvelope::ok(['subscribed' => true, 'id' => $id, 'user_id' => $uid]);
    }

    public function unsubscribe(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $uid = $this->requireUser($r, $b);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $id = (string)($b['id'] ?? '');
        if ($id === '') {
            return ApiEnvelope::error('id required', 422);
        }
        $rec = $this->store()->get("push/{$id}.json");
        if (!is_array($rec) || strtolower((string)($rec['user_id'] ?? '')) !== $uid) {
            return ApiEnvelope::error('forbidden', 403);
        }
        $this->store()->delete("push/{$id}.json");
        return ApiEnvelope::ok(['unsubscribed' => true, 'id' => $id]);
    }

    public function prefs(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $uid = $this->requireUser($r, $b);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $s = $this->store();
        if (in_array($r->method(), ['POST', 'PUT'], true)) {
            $prefs = ['user_id' => $uid, 'prefs' => $b['prefs'] ?? $b, 'updated_ms' => ApiEnvelope::nowMs()];
            $s->put("notif_prefs/{$uid}.json", $prefs);
            return ApiEnvelope::ok($prefs);
        }
        return ApiEnvelope::ok($s->get("notif_prefs/{$uid}.json") ?? [
            'user_id' => $uid,
            'prefs' => ['messages' => true, 'mentions' => true],
        ]);
    }

    public function list(Request $r): JsonResponse
    {
        $uid = $this->requireUser($r);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
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

    public function markRead(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $uid = $this->requireUser($r, $b);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $s = $this->store();
        $rec = $s->get("notifications/{$uid}.json");
        if (!is_array($rec)) {
            $rec = ['user_id' => $uid, 'items' => []];
        }
        $id = (string)($b['id'] ?? '');
        $items = is_array($rec['items'] ?? null) ? $rec['items'] : [];
        foreach ($items as &$it) {
            if ($id === '' || (string)($it['id'] ?? '') === $id) {
                $it['read'] = true;
            }
        }
        unset($it);
        $rec['items'] = $items;
        $rec['updated_ms'] = ApiEnvelope::nowMs();
        $s->put("notifications/{$uid}.json", $rec);
        return ApiEnvelope::ok(['user_id' => $uid, 'count' => count($items)]);
    }
}
