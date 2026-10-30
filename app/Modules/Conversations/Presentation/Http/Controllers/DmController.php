<?php
declare(strict_types=1);
namespace Nyx\Modules\Conversations\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Http\AuthContext;

final class DmController {
    public function open(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $me = AuthContext::userId($r, $b);
        if ($me === '') {
            $me = strtolower(trim((string)($b['user_id'] ?? $b['sender_id'] ?? '')));
        }
        $peer = strtolower(trim((string)($b['peer_id'] ?? $b['peer'] ?? $b['to'] ?? '')));
        if ($me === '' || $peer === '') return ApiEnvelope::error('user_id and peer_id required', 422);
        if ($me === $peer) return ApiEnvelope::error('cannot DM self', 422);
        $pair = [$me, $peer];
        sort($pair);
        $sid = 'dm_' . substr(hash('sha256', $pair[0] . '|' . $pair[1]), 0, 24);
        $s = new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
        $now = ApiEnvelope::nowMs();
        $rec = $s->get("spaces/{$sid}.json");
        if (!is_array($rec)) {
            $rec = [
                'space_id' => $sid, 'id' => $sid, 'type' => 'dm', 'name' => $peer,
                'members' => [$me, $peer], 'owner_id' => $me, 'created_ms' => $now, 'is_public' => false,
            ];
            $s->put("spaces/{$sid}.json", $rec);
        }
        foreach ([$me, $peer] as $u) {
            $s->put("memberships/{$sid}/{$u}.json", [
                'space_id' => $sid, 'user_id' => $u, 'role' => 'member', 'joined_ms' => $now,
            ]);
        }
        return ApiEnvelope::ok(['conversation_id' => $sid, 'conv_id' => $sid, 'space_id' => $sid, 'type' => 'dm', 'members' => [$me, $peer]]);
    }
}
