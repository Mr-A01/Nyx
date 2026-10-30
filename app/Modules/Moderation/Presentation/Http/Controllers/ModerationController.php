<?php
declare(strict_types=1);
namespace Nyx\Modules\Moderation\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;
use Nyx\Shared\Infrastructure\Storage\FileStore;

final class ModerationController {
    private function store(): FileStore { return new FileStore(defined('DATA_DIR') ? DATA_DIR : ''); }

    public function report(Request $r): JsonResponse {
        $b = $r->json() ?? []; $id = UlidLikeId::generate('rep');
        $rec = [
            'id' => $id,
            'reporter' => (string)($b['user_id'] ?? $b['reporter'] ?? ''),
            'target' => (string)($b['target_id'] ?? $b['message_id'] ?? ''),
            'reason' => (string)($b['reason'] ?? ''),
            'kind' => (string)($b['kind'] ?? 'user'),
            'created_ms' => ApiEnvelope::nowMs(),
        ];
        $this->store()->put("reports/{$id}.json", $rec);
        return ApiEnvelope::ok($rec);
    }

    public function block(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $uid = strtolower(trim((string)($b['user_id'] ?? '')));
        $tid = strtolower(trim((string)($b['target_id'] ?? $b['blocked_id'] ?? '')));
        if ($uid === '' || $tid === '') return ApiEnvelope::error('user_id and target_id required', 422);
        if ($uid === $tid) return ApiEnvelope::error('cannot block yourself', 422);
        $rec = ['user_id' => $uid, 'target_id' => $tid, 'blocked' => true, 'created_ms' => ApiEnvelope::nowMs()];
        $this->store()->put("blocks/{$uid}/{$tid}.json", $rec);
        return ApiEnvelope::ok($rec);
    }

    public function unblock(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $uid = strtolower(trim((string)($b['user_id'] ?? '')));
        $tid = strtolower(trim((string)($b['target_id'] ?? $b['blocked_id'] ?? '')));
        if ($uid === '' || $tid === '') return ApiEnvelope::error('user_id and target_id required', 422);
        $path = $this->store()->root() . "/blocks/{$uid}/{$tid}.json";
        if (is_file($path)) @unlink($path);
        return ApiEnvelope::ok(['unblocked' => true, 'user_id' => $uid, 'target_id' => $tid]);
    }

    public function listBlocks(Request $r): JsonResponse {
        $uid = strtolower(trim((string)($r->query('user_id') ?? (($r->json() ?? [])['user_id'] ?? ''))));
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $dir = $this->store()->root() . "/blocks/{$uid}";
        $items = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                $rec = json_decode((string)file_get_contents($f), true);
                if (is_array($rec)) $items[] = $rec;
            }
        }
        return ApiEnvelope::ok(['user_id' => $uid, 'blocked' => $items, 'count' => count($items)]);
    }
}
