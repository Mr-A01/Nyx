<?php
declare(strict_types=1);
namespace Nyx\Modules\Conversations\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Storage\FileStore;

final class ConvFlagsController {
    private function path(string $uid): string {
        return 'convflags/' . preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $uid) . '.json';
    }
    private function load(string $uid): array {
        $rec = (new FileStore(defined('DATA_DIR') ? DATA_DIR : ''))->get($this->path($uid));
        return is_array($rec) ? $rec : ['user_id' => $uid, 'muted' => [], 'pinned' => [], 'archived' => []];
    }
    public function get(Request $r): JsonResponse {
        $uid = (string)($r->query('user_id') ?? '');
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        return ApiEnvelope::ok($this->load($uid));
    }
    public function set(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $uid = (string)($b['user_id'] ?? '');
        $cid = (string)($b['conv_id'] ?? $b['space_id'] ?? '');
        $flag = strtolower((string)($b['flag'] ?? 'muted')); // muted|pinned|archived
        $value = array_key_exists('value', $b) ? (bool)$b['value'] : true;
        if ($uid === '' || $cid === '') return ApiEnvelope::error('user_id and conv_id required', 422);
        if (!in_array($flag, ['muted', 'pinned', 'archived'], true)) $flag = 'muted';
        $rec = $this->load($uid);
        $list = is_array($rec[$flag] ?? null) ? $rec[$flag] : [];
        if ($value) {
            if (!in_array($cid, $list, true)) $list[] = $cid;
        } else {
            $list = array_values(array_filter($list, static fn($x) => $x !== $cid));
        }
        $rec[$flag] = $list;
        $rec['updated_ms'] = ApiEnvelope::nowMs();
        (new FileStore(defined('DATA_DIR') ? DATA_DIR : ''))->put($this->path($uid), $rec);
        return ApiEnvelope::ok($rec);
    }
}