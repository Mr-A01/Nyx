<?php
declare(strict_types=1);
namespace Nyx\Modules\Social\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Http\AuthContext;

final class ContactsController {
    private function store(): FileStore {
        return new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
    }
    private function path(string $uid): string {
        return 'contacts/' . preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $uid) . '.json';
    }
    private function load(string $uid): array {
        $rec = $this->store()->get($this->path($uid));
        return is_array($rec) ? $rec : ['user_id' => $uid, 'items' => []];
    }
    private function save(string $uid, array $rec): void {
        $rec['user_id'] = $uid;
        $rec['updated_ms'] = ApiEnvelope::nowMs();
        $this->store()->put($this->path($uid), $rec);
    }
    public function list(Request $r): JsonResponse {
        $uid = AuthContext::userId($r);
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $rec = $this->load($uid);
        return ApiEnvelope::ok(['user_id' => $uid, 'items' => $rec['items'] ?? [], 'contacts' => $rec['items'] ?? [], 'count' => count($rec['items'] ?? [])]);
    }
    public function mutate(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $uid = AuthContext::userId($r, $b);
        $peer = strtolower(trim((string)($b['peer'] ?? $b['peer_id'] ?? $b['user_id'] ?? $b['username'] ?? $b['contact'] ?? $b['target'] ?? '')));
        // resolve username -> canonical user_id when possible
        $row = $this->store()->get('users/' . $peer . '.json');
        if (!is_array($row)) $row = $this->store()->get('identity/usernames/' . $peer . '.json');
        $peerUid = is_array($row) && !empty($row['user_id']) ? strtolower((string)$row['user_id']) : $peer;
        $peerName = is_array($row) ? (string)($row['display_name'] ?? $row['username'] ?? $peer) : $peer;
        $peerUser = is_array($row) ? (string)($row['username'] ?? $peer) : $peer;
        $action = strtolower((string)($b['action'] ?? 'add'));
        if ($uid === '' || $peer === '') return ApiEnvelope::error('user_id and peer required', 422);
        if (strtolower($uid) === $peer) return ApiEnvelope::error('cannot add self', 422);
        $rec = $this->load($uid);
        $items = array_values(array_filter($rec['items'] ?? [], static fn($x) => is_array($x)));
        $items = array_values(array_filter($items, static function($x) use ($peer, $peerUid) {
            $xu = strtolower((string)($x['username'] ?? ''));
            $xid = strtolower((string)($x['user_id'] ?? ''));
            return $xu !== $peer && $xid !== $peer && $xid !== $peerUid && $xu !== $peerUid;
        }));
        if ($action !== 'remove' && $action !== 'delete') {
            $items[] = [
                'username' => $peerUser,
                'user_id' => $peerUid,
                'displayName' => (string)($b['displayName'] ?? $b['display_name'] ?? $peerName),
                'display_name' => (string)($b['displayName'] ?? $b['display_name'] ?? $peerName),
                'added_ms' => ApiEnvelope::nowMs(),
            ];
        }
        $rec['items'] = $items;
        $this->save($uid, $rec);
        return ApiEnvelope::ok(['user_id' => $uid, 'items' => $items, 'count' => count($items), 'action' => $action]);
    }
}
