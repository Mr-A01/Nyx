<?php
declare(strict_types=1);
namespace Nyx\Modules\Devices\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Http\AuthContext;

final class DeviceController {
    public function register(Request $r): JsonResponse {
        $b = $r->json() ?? []; $uid = AuthContext::userId($r, $b); if ($uid === '') $uid = (string)($b['user_id'] ?? '');
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $did = (string)($b['device_id'] ?? UlidLikeId::generate()); $now = ApiEnvelope::nowMs();
        $rec = ['device_id' => $did, 'user_id' => $uid, 'public_key' => $b['public_key'] ?? '', 'created_ms' => $now, 'touched_ms' => $now];
        $store = new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
        $store->put("devices/{$did}.json", $rec);
        $idx = $store->get("devices/by_user/{$uid}.json");
        $ids = is_array($idx) && is_array($idx['device_ids'] ?? null) ? $idx['device_ids'] : [];
        if (!in_array($did, $ids, true)) $ids[] = $did;
        $store->put("devices/by_user/{$uid}.json", ['user_id' => $uid, 'device_ids' => $ids, 'updated_ms' => $now]);
        return ApiEnvelope::ok($rec);
    }
    public function touch(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $uid = AuthContext::userId($r, $b);
        if ($uid === '') return ApiEnvelope::error('unauthorized', 401);
        $did = (string)($b['device_id'] ?? $r->query('device_id') ?? '');
        if ($did === '') return ApiEnvelope::error('device_id required', 422);
        $s = new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
        $rec = $s->get("devices/{$did}.json");
        if (!is_array($rec) || strtolower((string)($rec['user_id'] ?? '')) !== strtolower($uid)) {
            return ApiEnvelope::error('forbidden', 403);
        }
        $rec['touched_ms'] = ApiEnvelope::nowMs();
        $s->put("devices/{$did}.json", $rec);
        return ApiEnvelope::ok($rec);
    }
    public function revoke(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $did = (string)($b['device_id'] ?? '');
        $uid = AuthContext::userId($r, $b);
        if ($uid === '') return ApiEnvelope::error('unauthorized', 401);
        if ($did === '') return ApiEnvelope::error('device_id required', 422);
        $s = new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
        $rec = $s->get("devices/{$did}.json");
        if (!is_array($rec) || strtolower((string)($rec['user_id'] ?? '')) !== strtolower($uid)) {
            return ApiEnvelope::error('forbidden', 403);
        }
        $s->delete("devices/{$did}.json");
        return ApiEnvelope::ok(['device_id' => $did, 'revoked' => true]);
    }

    public function list(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $uid = AuthContext::userId($r, $b);
        if ($uid === '') return ApiEnvelope::error('unauthorized', 401);
        $store = new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
        $idx = $store->get("devices/by_user/{$uid}.json");
        $ids = is_array($idx) && is_array($idx['device_ids'] ?? null) ? $idx['device_ids'] : [];
        $items = [];
        foreach ($ids as $did) {
            $rec = $store->get("devices/{$did}.json");
            if (is_array($rec)) $items[] = $rec;
        }
        // fallback scan
        if (!$items) {
            $root = $store->root() . '/devices';
            if (is_dir($root)) {
                foreach (glob($root . '/*.json') ?: [] as $f) {
                    $rec = json_decode((string)@file_get_contents($f), true);
                    if (is_array($rec) && strtolower((string)($rec['user_id'] ?? '')) === strtolower($uid)) $items[] = $rec;
                }
            }
        }
        return ApiEnvelope::ok(['user_id' => $uid, 'devices' => $items, 'items' => $items, 'count' => count($items)]);
    }

}
