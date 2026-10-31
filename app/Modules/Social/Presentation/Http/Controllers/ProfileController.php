<?php
declare(strict_types=1);
namespace Nyx\Modules\Social\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Http\AuthContext;

final class ProfileController {
    private function store(): FileStore {
        return new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
    }
    private function resolveUser(string $uid): ?array {
        $s = $this->store();
        $data = defined('DATA_DIR') ? rtrim((string)DATA_DIR, '/') : '';
        $candidates = [
            "users/{$uid}.json",
            "identity/usernames/" . strtolower($uid) . ".json",
            "profiles/{$uid}.json",
        ];
        foreach ($candidates as $p) {
            $rec = $s->get($p);
            if (is_array($rec)) return $rec;
        }
        // scan usernames dir for user_id match
        $dir = $data . '/identity/usernames';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                $rec = json_decode((string)file_get_contents($f), true);
                if (!is_array($rec)) continue;
                if (($rec['user_id'] ?? '') === $uid || strtolower((string)($rec['username'] ?? '')) === strtolower($uid)) {
                    return $rec;
                }
            }
        }
        return null;
    }
    public function get(Request $r): JsonResponse {
        $uid = AuthContext::userId($r);
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $base = $this->resolveUser($uid) ?? ['username' => $uid, 'user_id' => $uid];
        $prof = $this->store()->get('profiles/' . preg_replace('/[^a-zA-Z0-9_\-.]/', '_', strtolower((string)($base['username'] ?? $uid))) . '.json');
        if (!is_array($prof)) $prof = [];
        $out = [
            'user_id' => $base['user_id'] ?? $uid,
            'username' => $base['username'] ?? $uid,
            'displayName' => $prof['displayName'] ?? $prof['display_name'] ?? $base['display_name'] ?? $base['displayName'] ?? ($base['username'] ?? $uid),
            'display_name' => $prof['display_name'] ?? $prof['displayName'] ?? $base['display_name'] ?? ($base['username'] ?? $uid),
            'bio' => $prof['bio'] ?? '',
            'status' => $prof['status'] ?? ($prof['statusMessage'] ?? ''),
            'statusMessage' => $prof['statusMessage'] ?? ($prof['status'] ?? ''),
            'pronouns' => $prof['pronouns'] ?? '',
            'accent' => $prof['accent'] ?? 'blue',
            'link' => $prof['link'] ?? '',
            'links' => $prof['links'] ?? [],
            'avatar' => $prof['avatar'] ?? null,
            'public_key' => $base['public_key'] ?? '',
            'created_ms' => $base['created_ms'] ?? ($prof['created_ms'] ?? null),
            'updated_ms' => $prof['updated_ms'] ?? null,
        ];
        return ApiEnvelope::ok($out);
    }
    public function update(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $uid = AuthContext::userId($r, $b);
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $base = $this->resolveUser($uid) ?? ['username' => $uid, 'user_id' => $uid];
        $key = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', strtolower((string)($base['username'] ?? $uid)));
        $prev = $this->store()->get("profiles/{$key}.json");
        if (!is_array($prev)) $prev = [];
        $prev['user_id'] = $base['user_id'] ?? $uid;
        $prev['username'] = $base['username'] ?? $uid;
        if (isset($b['display_name']) || isset($b['displayName'])) {
            $prev['displayName'] = (string)($b['displayName'] ?? $b['display_name']);
            $prev['display_name'] = $prev['displayName'];
        }
        if (array_key_exists('bio', $b)) $prev['bio'] = substr((string)$b['bio'], 0, 500);
        if (array_key_exists('avatar', $b)) $prev['avatar'] = $b['avatar'];
        if (array_key_exists('status', $b) || array_key_exists('statusMessage', $b)) {
            $prev['status'] = substr((string)($b['status'] ?? $b['statusMessage'] ?? ''), 0, 80);
            $prev['statusMessage'] = $prev['status'];
        }
        if (array_key_exists('pronouns', $b)) $prev['pronouns'] = substr((string)$b['pronouns'], 0, 32);
        if (array_key_exists('accent', $b)) $prev['accent'] = substr(preg_replace('/[^a-z]/', '', strtolower((string)$b['accent'])), 0, 16);
        if (array_key_exists('link', $b)) $prev['link'] = substr((string)$b['link'], 0, 300);
        if (array_key_exists('links', $b) && is_array($b['links'])) $prev['links'] = $b['links'];
        $prev['updated_ms'] = ApiEnvelope::nowMs();
        $this->store()->put("profiles/{$key}.json", $prev);
        return ApiEnvelope::ok($prev);
    }
}
