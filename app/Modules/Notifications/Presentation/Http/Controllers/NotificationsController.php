<?php
declare(strict_types=1);
namespace Nyx\Modules\Notifications\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Storage\FileStore;

final class NotificationsController {
    private function path(string $uid): string {
        return 'notifications/' . preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $uid) . '.json';
    }
    public function list(Request $r): JsonResponse {
        $uid = (string)($r->query('user_id') ?? '');
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $rec = (new FileStore(defined('DATA_DIR') ? DATA_DIR : ''))->get($this->path($uid));
        $items = is_array($rec) ? ($rec['items'] ?? []) : [];
        return ApiEnvelope::ok(['user_id' => $uid, 'items' => $items, 'notifications' => $items, 'count' => count($items)]);
    }
    public function readAll(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $uid = (string)($b['user_id'] ?? '');
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $s = new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
        $rec = $s->get($this->path($uid));
        if (!is_array($rec)) $rec = ['items' => []];
        foreach ($rec['items'] as &$it) { if (is_array($it)) $it['read'] = true; }
        unset($it);
        $rec['updated_ms'] = ApiEnvelope::nowMs();
        $s->put($this->path($uid), $rec);
        return ApiEnvelope::ok(['user_id' => $uid, 'ok' => true]);
    }
}