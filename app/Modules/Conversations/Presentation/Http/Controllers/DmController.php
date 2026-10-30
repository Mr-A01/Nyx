<?php
declare(strict_types=1);
namespace Nyx\Modules\Conversations\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Http\AuthContext;

final class DmController {
    private function store(): FileStore {
        return new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
    }

    /** Resolve username or id to canonical user_id (hash). */
    private function resolveToUserId(string $raw): string {
        $raw = strtolower(trim($raw));
        if ($raw === '') return '';
        $s = $this->store();
        // Already a user_id?
        $u = $s->get("users/{$raw}.json");
        if (is_array($u) && !empty($u['user_id'])) {
            return strtolower((string)$u['user_id']);
        }
        // Look up by username
        $u = $s->get("identity/usernames/{$raw}.json");
        if (is_array($u) && !empty($u['user_id'])) {
            return strtolower((string)$u['user_id']);
        }
        // Fallback: treat as id
        return $raw;
    }

    public function open(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $me = AuthContext::userId($r, $b);
        if ($me === '') {
            $me = strtolower(trim((string)($b['user_id'] ?? $b['sender_id'] ?? '')));
        }
        $peerRaw = strtolower(trim((string)($b['peer_id'] ?? $b['peer'] ?? $b['to'] ?? $b['username'] ?? '')));
        if ($me === '' || $peerRaw === '') return ApiEnvelope::error('user_id and peer_id required', 422);

        $me = $this->resolveToUserId($me);
        $peer = $this->resolveToUserId($peerRaw);
        if ($me === '' || $peer === '') return ApiEnvelope::error('user_id and peer_id required', 422);
        if ($me === $peer) return ApiEnvelope::error('cannot DM self', 422);

        $pair = [$me, $peer];
        sort($pair);
        $sid = 'dm_' . substr(hash('sha256', $pair[0] . '|' . $pair[1]), 0, 24);
        $s = $this->store();
        $now = ApiEnvelope::nowMs();
        $rec = $s->get("spaces/{$sid}.json");
        if (!is_array($rec)) {
            // Prefer human-readable name if peer was a username
            $peerName = $peerRaw;
            $u = $s->get("users/{$peer}.json");
            if (is_array($u)) {
                $peerName = (string)($u['display_name'] ?? $u['username'] ?? $peer);
            }
            $rec = [
                'space_id' => $sid, 'id' => $sid, 'type' => 'dm', 'name' => $peerName,
                'members' => [$me, $peer], 'owner_id' => $me, 'created_ms' => $now, 'is_public' => false,
            ];
            $s->put("spaces/{$sid}.json", $rec);
        }
        foreach ([$me, $peer] as $u) {
            $s->put("memberships/{$sid}/{$u}.json", [
                'space_id' => $sid, 'user_id' => $u, 'role' => 'member', 'joined_ms' => $now,
            ]);
        }
        return ApiEnvelope::ok([
            'conversation_id' => $sid,
            'conv_id' => $sid,
            'space_id' => $sid,
            'type' => 'dm',
            'members' => [$me, $peer],
            'peer_id' => $peer,
            'peer_name' => $rec['name'] ?? $peer,
        ]);
    }
}
