<?php
declare(strict_types=1);
namespace Nyx\Modules\Social\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Http\AuthContext;

final class PrefsController {
    private function path(string $uid): string {
        return 'prefs/' . preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $uid) . '.json';
    }
    public function get(Request $r): JsonResponse {
        $uid = AuthContext::userId($r);
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $rec = (new FileStore(defined('DATA_DIR') ? DATA_DIR : ''))->get($this->path($uid));
        if (!is_array($rec)) $rec = ['user_id' => $uid, 'prefs' => []];
        return ApiEnvelope::ok($rec);
    }
    public function set(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $uid = AuthContext::userId($r, $b);
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $prefs = $b['prefs'] ?? $b;
        if (!is_array($prefs)) $prefs = [];
        unset($prefs['user_id'], $prefs['session'], $prefs['token']);
        $rec = ['user_id' => $uid, 'prefs' => $prefs, 'updated_ms' => ApiEnvelope::nowMs()];
        (new FileStore(defined('DATA_DIR') ? DATA_DIR : ''))->put($this->path($uid), $rec);
        return ApiEnvelope::ok($rec);
    }
}
