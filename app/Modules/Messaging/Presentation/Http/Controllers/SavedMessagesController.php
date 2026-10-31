<?php
declare(strict_types=1);
namespace Nyx\Modules\Messaging\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Storage\FileStore;

final class SavedMessagesController {
    private function path(string $uid): string {
        return 'saved/' . preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $uid) . '.json';
    }
    private function load(string $uid): array {
        $rec = (new FileStore(defined('DATA_DIR') ? DATA_DIR : ''))->get($this->path($uid));
        return is_array($rec) ? $rec : ['user_id' => $uid, 'items' => []];
    }
    public function list(Request $r): JsonResponse {
        $uid = (string)($r->query('user_id') ?? ($r->json()['user_id'] ?? ''));
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $rec = $this->load($uid);
        return ApiEnvelope::ok(['user_id' => $uid, 'items' => $rec['items'] ?? [], 'count' => count($rec['items'] ?? [])]);
    }
    public function save(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $uid = (string)($b['user_id'] ?? '');
        $msgId = (string)($b['message_id'] ?? $b['id'] ?? '');
        $convId = (string)($b['conv_id'] ?? '');
        if ($uid === '' || $msgId === '') return ApiEnvelope::error('user_id and message_id required', 422);
        $rec = $this->load($uid);
        $items = is_array($rec['items'] ?? null) ? $rec['items'] : [];
        $items = array_values(array_filter($items, static fn($x) => !is_array($x) || (($x['message_id'] ?? '') !== $msgId)));
        $items[] = [
            'message_id' => $msgId,
            'conv_id' => $convId,
            'preview' => substr((string)($b['preview'] ?? $b['text'] ?? ''), 0, 200),
            'saved_ms' => ApiEnvelope::nowMs(),
        ];
        $rec['items'] = array_slice($items, -200);
        $rec['updated_ms'] = ApiEnvelope::nowMs();
        (new FileStore(defined('DATA_DIR') ? DATA_DIR : ''))->put($this->path($uid), $rec);
        return ApiEnvelope::ok(['saved' => true, 'message_id' => $msgId, 'count' => count($rec['items'])]);
    }
    public function unsave(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $uid = (string)($b['user_id'] ?? '');
        $msgId = (string)($b['message_id'] ?? $b['id'] ?? '');
        if ($uid === '' || $msgId === '') return ApiEnvelope::error('user_id and message_id required', 422);
        $rec = $this->load($uid);
        $items = is_array($rec['items'] ?? null) ? $rec['items'] : [];
        $rec['items'] = array_values(array_filter($items, static fn($x) => !is_array($x) || (($x['message_id'] ?? '') !== $msgId)));
        $rec['updated_ms'] = ApiEnvelope::nowMs();
        (new FileStore(defined('DATA_DIR') ? DATA_DIR : ''))->put($this->path($uid), $rec);
        return ApiEnvelope::ok(['unsaved' => true, 'message_id' => $msgId, 'count' => count($rec['items'])]);
    }
}