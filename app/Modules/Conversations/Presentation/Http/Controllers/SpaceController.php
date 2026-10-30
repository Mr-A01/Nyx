<?php
declare(strict_types=1);
namespace Nyx\Modules\Conversations\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Http\AuthContext;

final class SpaceController {
    private function store(): FileStore {
        return new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
    }
    private function host(): string {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    private function membersOf(string $sid): array {
        $store = $this->store();
        $dir = $store->root() . '/memberships/' . $sid;
        $members = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                $rec = json_decode((string)file_get_contents($f), true);
                if (!is_array($rec)) continue;
                $uid = strtolower((string)($rec['user_id'] ?? basename($f, '.json')));
                $row = $store->get("users/{$uid}.json");
                if (!is_array($row)) $row = $store->get("identity/usernames/{$uid}.json");
                if (is_array($row)) {
                    $rec['user_id'] = strtolower((string)($row['user_id'] ?? $uid));
                    $rec['username'] = (string)($row['username'] ?? $rec['user_id']);
                    $rec['display_name'] = (string)($row['display_name'] ?? $row['displayName'] ?? $rec['username']);
                    $rec['displayName'] = $rec['display_name'];
                } else {
                    $rec['user_id'] = $uid;
                    $rec['username'] = $uid;
                    $rec['display_name'] = $uid;
                    $rec['displayName'] = $uid;
                }
                $members[] = $rec;
            }
        }
        // Dedupe by user_id, keep highest role
        $rank = ['owner' => 100, 'admin' => 80, 'mod' => 60, 'member' => 20, 'readonly' => 10];
        $by = [];
        foreach ($members as $m) {
            $id = strtolower((string)($m['user_id'] ?? ''));
            if ($id === '') continue;
            $r = strtolower((string)($m['role'] ?? 'member'));
            if (!isset($by[$id]) || ($rank[$r] ?? 0) > ($rank[strtolower((string)($by[$id]['role'] ?? 'member'))] ?? 0)) {
                $by[$id] = $m;
            }
        }
        return array_values($by);
    }

    private const ROLE_RANK = ['owner' => 100, 'admin' => 80, 'mod' => 60, 'member' => 20, 'readonly' => 10];

    private function roleOf(string $sid, string $uid): string {
        $uid = strtolower(trim($uid));
        if ($uid === '') return '';
        $store = $this->store();
        $sp = $store->get("spaces/{$sid}.json");
        if (is_array($sp)) {
            $owner = strtolower((string)($sp['owner_id'] ?? ''));
            if ($owner === $uid) return 'owner';
            // owner_id may be username — resolve both ways
            if ($owner !== '') {
                $orow = $store->get("users/{$owner}.json");
                if (!is_array($orow)) $orow = $store->get("identity/usernames/{$owner}.json");
                if (is_array($orow) && strtolower((string)($orow['user_id'] ?? '')) === $uid) return 'owner';
                $urow = $store->get("users/{$uid}.json");
                if (!is_array($urow)) $urow = $store->get("identity/usernames/{$uid}.json");
                if (is_array($urow) && strtolower((string)($urow['username'] ?? '')) === $owner) return 'owner';
            }
        }
        $mem = $store->get("memberships/{$sid}/{$uid}.json");
        if (!is_array($mem)) return '';
        $role = strtolower((string)($mem['role'] ?? 'member'));
        return $role !== '' ? $role : 'member';
    }

    private function rank(string $role): int {
        return self::ROLE_RANK[strtolower($role)] ?? 0;
    }

    /** @return true|JsonResponse */
    private function assertActor(string $sid, string $actor, string $minRole = 'admin') {
        $actor = strtolower(trim($actor));
        if ($actor === '') return ApiEnvelope::error('actor_id required', 401);
        $role = $this->roleOf($sid, $actor);
        if ($this->rank($role) < $this->rank($minRole)) {
            return ApiEnvelope::error('FORBIDDEN', 403);
        }
        return true;
    }

    private function audit(string $sid, string $actor, string $action, string $detail = '', array $extra = []): void {
        try {
            $key = "audit/{$sid}/" . ApiEnvelope::nowMs() . '_' . substr(hash('sha256', $action.$actor.$detail.microtime(true)), 0, 8) . '.json';
            $this->store()->put($key, array_merge([
                'space_id' => $sid, 'actor_id' => $actor, 'action' => $action,
                'detail' => $detail, 'ts' => ApiEnvelope::nowMs(),
            ], $extra));
        } catch (\Throwable $e) {}
    }

    private function listAudit(string $sid, int $limit = 40): array {
        $dir = $this->store()->root() . '/audit/' . $sid;
        $items = [];
        if (is_dir($dir)) {
            $files = glob($dir . '/*.json') ?: [];
            rsort($files);
            foreach (array_slice($files, 0, $limit) as $f) {
                $rec = json_decode((string)file_get_contents($f), true);
                if (is_array($rec)) $items[] = $rec;
            }
        }
        return $items;
    }

    public function create(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $name = trim((string)($b['name'] ?? ''));
        $authUser = (string)(($GLOBALS['nyx_auth']['user_id'] ?? '') ?: '');
        if ($authUser === '') {
            try {
                $authUser = \Nyx\Shared\Infrastructure\Http\AuthContext::userId($r, $b);
            } catch (\Throwable $e) {}
        }
        $owner = $authUser !== '' ? $authUser : (string)($b['owner_id'] ?? $b['user_id'] ?? '');
        if ($authUser === '' && $owner === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        // Never create owner-less spaces
        if ($owner === '' && $authUser !== '') $owner = $authUser;
        if ($name === '') return ApiEnvelope::error('name required', 422);
        $type = strtolower(trim((string)($b['type'] ?? 'group')));
        if (!in_array($type, ['group', 'channel'], true)) $type = 'group';
        $sid = (string)($b['space_id'] ?? UlidLikeId::generate());
        $now = ApiEnvelope::nowMs();
        $public = array_key_exists('is_public', $b) || array_key_exists('public', $b)
            ? (!empty($b['is_public']) || !empty($b['public']))
            : ($type === 'channel');
        $rec = [
            'space_id' => $sid, 'id' => $sid, 'name' => $name, 'type' => $type,
            'owner_id' => $owner, 'description' => (string)($b['description'] ?? ''),
            'is_public' => $public, 'public' => $public,
            'posting' => (string)($b['posting'] ?? ($type === 'channel' ? 'admins' : 'members')),
            'history_visible' => array_key_exists('history_visible', $b) ? (bool)$b['history_visible'] : true,
            'slowmode_sec' => (int)($b['slowmode_sec'] ?? 0),
            'created_ms' => $now, 'updated_ms' => $now,
            'link' => $this->host() . '/nyx/client.html#/g/' . $sid,
        ];
        $this->store()->put("spaces/{$sid}.json", $rec);
        if ($owner !== '') {
            $this->store()->put("memberships/{$sid}/{$owner}.json", [
                'space_id' => $sid, 'user_id' => $owner, 'role' => 'owner', 'joined_ms' => $now,
            ]);
        }
        $invited = 0;
        if (is_array($b['members'] ?? null)) {
            $invited = count(array_filter($b['members'], static fn($m) => is_string($m) ? $m !== '' : !empty($m)));
        } elseif (is_array($b['member_ids'] ?? null)) {
            $invited = count($b['member_ids']);
        }
        $rec['member_count'] = 1 + max(0, $invited);
        $rec['solo_owner'] = $invited === 0;
        $rec['invite_hint'] = $invited === 0
            ? 'You are the only member. Invite others from group settings when ready.'
            : null;
        return ApiEnvelope::ok($rec);
    }
    public function resolveSpaceId(string $raw): string {
        $sid = strtolower(trim($raw));
        if ($sid === '') return '';
        $sid = preg_replace('/^nyx:space:/', '', $sid);
        if (preg_match('/^(grp_|chn_)[a-f0-9]+$/', $sid)) return $sid;
        // vanity handle
        $handle = preg_replace('/[^a-z0-9_]/', '', $sid);
        if ($handle !== '' && !preg_match('/^[a-f0-9]{16,}$/', $handle)) {
            $idx = $this->store()->get("spaces/by_handle/{$handle}.json");
            if (is_array($idx) && !empty($idx['space_id'])) return (string)$idx['space_id'];
        }
        // bare hex → try prefixes
        if (preg_match('/^[a-f0-9]{16,64}$/', $sid)) {
            foreach (['grp_', 'chn_', ''] as $p) {
                $try = $p === '' ? $sid : ($p . $sid);
                if ($this->store()->get("spaces/{$try}.json")) return $try;
            }
        }
        return $sid;
    }
    public function get(Request $r): JsonResponse {
        $sid = (string)($r->query('id') ?? $r->query('space_id') ?? ($r->json()['id'] ?? $r->json()['space_id'] ?? ''));
        if ($sid === '') return ApiEnvelope::error('id required', 422);
        $sid = $this->resolveSpaceId($sid);
        $rec = $this->store()->get("spaces/{$sid}.json");
        if (!$rec) return ApiEnvelope::error('space not found', 404);
        $members = $this->membersOf($sid);
        $rec['members'] = array_values(array_map(static fn($m) => (string)($m['user_id'] ?? ''), $members));
        $rec['member_count'] = count($members);
        $rec['roles'] = [];
        foreach ($members as $m) {
            if (!empty($m['user_id'])) $rec['roles'][(string)$m['user_id']] = (string)($m['role'] ?? 'member');
        }
        $rec['link'] = $this->host() . '/nyx/client.html#/g/' . $sid;
        return ApiEnvelope::ok($rec);
    }
    public function update(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $sid = (string)($b['space_id'] ?? $b['id'] ?? '');
        $actor = AuthContext::userId($r, $b);
        if ($actor === '') $actor = (string)($b['actor_id'] ?? $b['user_id'] ?? '');
        if ($sid === '') return ApiEnvelope::error('space_id required', 422);
        $rec = $this->store()->get("spaces/{$sid}.json");
        if (!$rec) return ApiEnvelope::error('space not found', 404);
        $auth = $this->assertActor($sid, $actor, 'admin');
        if ($auth !== true) return $auth;
        foreach (['name', 'description', 'posting', 'join_mode'] as $k) {
            if (array_key_exists($k, $b)) $rec[$k] = is_string($b[$k]) ? trim((string)$b[$k]) : $b[$k];
        }
        // Public handle / vanity id (admin/owner)
        if (array_key_exists('public_id', $b) || array_key_exists('handle', $b) || array_key_exists('username', $b)) {
            $raw = (string)($b['public_id'] ?? $b['handle'] ?? $b['username'] ?? '');
            $handle = strtolower(preg_replace('/[^a-z0-9_]/', '', $raw));
            if (strlen($handle) > 32) $handle = substr($handle, 0, 32);
            if ($handle !== '' && strlen($handle) < 3) {
                return ApiEnvelope::error('public_id must be 3–32 chars (a-z, 0-9, _)', 422);
            }
            $oldHandle = strtolower((string)($rec['public_id'] ?? $rec['handle'] ?? ''));
            if ($handle !== '' && $handle !== $oldHandle) {
                $existing = $this->store()->get("spaces/by_handle/{$handle}.json");
                if (is_array($existing) && (string)($existing['space_id'] ?? '') !== $sid) {
                    return ApiEnvelope::error('public_id already taken', 409);
                }
                if ($oldHandle !== '') {
                    try { $this->store()->put("spaces/by_handle/{$oldHandle}.json", ['space_id' => null, 'freed_ms' => ApiEnvelope::nowMs()]); } catch (\Throwable $e) {}
                }
                $this->store()->put("spaces/by_handle/{$handle}.json", ['space_id' => $sid, 'handle' => $handle, 'updated_ms' => ApiEnvelope::nowMs()]);
            }
            if ($handle === '') {
                if ($oldHandle !== '') {
                    try { $this->store()->put("spaces/by_handle/{$oldHandle}.json", ['space_id' => null, 'freed_ms' => ApiEnvelope::nowMs()]); } catch (\Throwable $e) {}
                }
                unset($rec['public_id'], $rec['handle']);
            } else {
                $rec['public_id'] = $handle;
                $rec['handle'] = $handle;
            }
        }
        if (array_key_exists('slowmode_sec', $b)) $rec['slowmode_sec'] = max(0, min(86400, (int)$b['slowmode_sec']));
        if (array_key_exists('history_visible', $b)) $rec['history_visible'] = !empty($b['history_visible']);
        if (array_key_exists('is_public', $b) || array_key_exists('public', $b)) {
            $pub = !empty($b['is_public']) || !empty($b['public']);
            $rec['is_public'] = $pub; $rec['public'] = $pub;
        }
        if (array_key_exists('history_visible', $b)) $rec['history_visible'] = (bool)$b['history_visible'];
        if (array_key_exists('slowmode_sec', $b)) $rec['slowmode_sec'] = max(0, (int)$b['slowmode_sec']);
        $rec['updated_ms'] = ApiEnvelope::nowMs();
        $rec['link'] = $this->host() . '/nyx/client.html#/g/' . $sid;
        $this->store()->put("spaces/{$sid}.json", $rec);
        return ApiEnvelope::ok($rec);
    }
    public function join(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $sid = (string)($b['space_id'] ?? $b['id'] ?? $b['handle'] ?? $b['public_id'] ?? '');
        $uid = AuthContext::userId($r, $b);
        if ($sid === '' || $uid === '') return ApiEnvelope::error('space_id and user_id required', 422);
        $sid = $this->resolveSpaceId($sid);
        $space = $this->store()->get("spaces/{$sid}.json");
        if (!$space) return ApiEnvelope::error('space not found', 404);
        $rec = ['space_id' => $sid, 'user_id' => $uid, 'role' => $b['role'] ?? 'member', 'joined_ms' => ApiEnvelope::nowMs()];
        $this->store()->put("memberships/{$sid}/{$uid}.json", $rec);
        return ApiEnvelope::ok(array_merge($rec, ['space' => $space]));
    }
    public function leave(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $sid = (string)($b['space_id'] ?? '');
        $uid = AuthContext::userId($r, $b);
        if ($sid === '' || $uid === '') return ApiEnvelope::error('space_id and user_id required', 422);
        $path = $this->store()->root() . "/memberships/{$sid}/{$uid}.json";
        if (is_file($path)) @unlink($path);
        return ApiEnvelope::ok(['left' => true, 'space_id' => $sid, 'user_id' => $uid]);
    }
    public function kick(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $sid = (string)($b['space_id'] ?? '');
        $uid = strtolower(trim((string)($b['user_id'] ?? $b['target_id'] ?? $b['username'] ?? '')));
        $actor = AuthContext::userId($r, $b);
        if ($actor === '') $actor = strtolower(trim((string)($b['actor_id'] ?? '')));
        $row = $this->store()->get("users/{$uid}.json");
        if (!is_array($row)) $row = $this->store()->get("identity/usernames/{$uid}.json");
        if (is_array($row) && !empty($row['user_id'])) $uid = strtolower((string)$row['user_id']);
        if ($sid === '' || $uid === '') return ApiEnvelope::error('space_id and user_id required', 422);
        $auth = $this->assertActor($sid, $actor, 'mod');
        if ($auth !== true) return $auth;
        if ($uid === $actor) return ApiEnvelope::error('cannot kick yourself', 422);
        $tr = $this->roleOf($sid, $uid);
        if ($tr === 'owner') return ApiEnvelope::error('cannot kick owner', 403);
        if ($this->rank($tr) >= $this->rank($this->roleOf($sid, $actor))) {
            return ApiEnvelope::error('FORBIDDEN: target rank too high', 403);
        }
        $path = $this->store()->root() . "/memberships/{$sid}/{$uid}.json";
        if (is_file($path)) @unlink($path);
        $this->audit($sid, $actor, 'kick', $uid, ['target_id'=>$uid]);
        return ApiEnvelope::ok(['kicked' => true, 'space_id' => $sid, 'user_id' => $uid, 'actor_id' => $actor]);
    }
    public function setRole(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $sid = (string)($b['space_id'] ?? '');
        $uid = strtolower(trim((string)($b['user_id'] ?? $b['username'] ?? '')));
        $actor = AuthContext::userId($r, $b);
        if ($actor === '') $actor = strtolower(trim((string)($b['actor_id'] ?? $b['session_user'] ?? '')));
        $role = strtolower((string)($b['role'] ?? 'member'));
        // resolve username to user_id
        $row = $this->store()->get("users/{$uid}.json");
        if (!is_array($row)) $row = $this->store()->get("identity/usernames/{$uid}.json");
        if (is_array($row) && !empty($row['user_id'])) $uid = strtolower((string)$row['user_id']);
        if ($sid === '' || $uid === '') return ApiEnvelope::error('space_id and user_id required', 422);
        if (!in_array($role, ['owner', 'admin', 'mod', 'member', 'readonly'], true)) $role = 'member';
        $auth = $this->assertActor($sid, $actor, $role === 'owner' ? 'owner' : 'admin');
        if ($auth !== true) return $auth;
        $actorRole = $this->roleOf($sid, $actor);
        $targetRole = $this->roleOf($sid, $uid);
        // cannot modify equal/higher rank unless owner transferring
        if ($role !== 'owner' && $this->rank($targetRole) >= $this->rank($actorRole) && $actor !== $uid) {
            return ApiEnvelope::error('FORBIDDEN: cannot modify equal or higher role', 403);
        }
        if ($this->rank($role) >= $this->rank($actorRole) && $role !== 'owner' && $actorRole !== 'owner') {
            return ApiEnvelope::error('FORBIDDEN: cannot assign equal or higher role', 403);
        }
        if ($role === 'owner') {
            return $this->transferOwnershipInternal($sid, $actor, $uid);
        }
        $rec = $this->store()->get("memberships/{$sid}/{$uid}.json") ?? [
            'space_id' => $sid, 'user_id' => $uid, 'joined_ms' => ApiEnvelope::nowMs(),
        ];
        $rec['role'] = $role;
        $rec['user_id'] = $uid;
        $rec['space_id'] = $sid;
        $this->store()->put("memberships/{$sid}/{$uid}.json", $rec);
        $this->audit($sid, $actor, 'set_role', "{$uid} → {$role}", ['target_id' => $uid, 'role' => $role]);
        return ApiEnvelope::ok($rec);
    }

    private function transferOwnershipInternal(string $sid, string $actor, string $toUser): JsonResponse {
        if ($this->roleOf($sid, $actor) !== 'owner') return ApiEnvelope::error('FORBIDDEN', 403);
        $sp = $this->store()->get("spaces/{$sid}.json");
        if (!is_array($sp)) return ApiEnvelope::error('space not found', 404);
        $prev = strtolower((string)($sp['owner_id'] ?? $actor));
        // ensure canonical user_id for owner
        $row = $this->store()->get("users/{$toUser}.json");
        if (!is_array($row)) $row = $this->store()->get("identity/usernames/{$toUser}.json");
        if (is_array($row) && !empty($row['user_id'])) $toUser = strtolower((string)$row['user_id']);
        $sp['owner_id'] = $toUser;
        $sp['updated_ms'] = ApiEnvelope::nowMs();
        $this->store()->put("spaces/{$sid}.json", $sp);
        $to = $this->store()->get("memberships/{$sid}/{$toUser}.json") ?? ['space_id'=>$sid,'user_id'=>$toUser,'joined_ms'=>ApiEnvelope::nowMs()];
        $to['role'] = 'owner'; $to['user_id'] = $toUser;
        $this->store()->put("memberships/{$sid}/{$toUser}.json", $to);
        if ($prev !== '' && $prev !== $toUser) {
            $pm = $this->store()->get("memberships/{$sid}/{$prev}.json") ?? ['space_id'=>$sid,'user_id'=>$prev,'joined_ms'=>ApiEnvelope::nowMs()];
            $pm['role'] = 'admin'; $pm['user_id'] = $prev;
            $this->store()->put("memberships/{$sid}/{$prev}.json", $pm);
        }
        $this->audit($sid, $actor, 'transfer_ownership', "{$prev} → {$toUser}", ['to_user_id'=>$toUser]);
        return ApiEnvelope::ok(['ok'=>true,'space_id'=>$sid,'owner_id'=>$toUser,'previous_owner'=>$prev]);
    }

    public function transfer(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $sid = (string)($b['space_id'] ?? '');
        $actor = AuthContext::userId($r, $b); if ($actor === '') $actor = strtolower(trim((string)($b['actor_id'] ?? '')));
        $to = strtolower(trim((string)($b['to_user_id'] ?? $b['user_id'] ?? $b['username'] ?? '')));
        $row = $this->store()->get("users/{$to}.json");
        if (!is_array($row)) $row = $this->store()->get("identity/usernames/{$to}.json");
        if (is_array($row) && !empty($row['user_id'])) $to = strtolower((string)$row['user_id']);
        if ($sid === '' || $to === '') return ApiEnvelope::error('space_id and to_user_id required', 422);
        return $this->transferOwnershipInternal($sid, $actor, $to);
    }
    public function invite(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $sid = (string)($b['space_id'] ?? $r->query('space_id') ?? '');
        if ($sid === '') return ApiEnvelope::error('space_id required', 422);
        $actor = strtolower(trim((string)($b['actor_id'] ?? '')));
        if ($actor !== '') {
            $auth = $this->assertActor($sid, $actor, 'admin');
            if ($auth !== true) return $auth;
        }
        $ttl = max(0, (int)($b['ttl_ms'] ?? 0));
        $maxUses = max(0, (int)($b['max_uses'] ?? 0));
        $rotate = !empty($b['rotate']);
        $code = substr(hash('sha256', $sid . '|' . ($b['user_id'] ?? '') . '|' . microtime(true) . '|' . random_int(1, 1000000000)), 0, 12);
        $url = $this->host() . '/nyx/client.html#invite=' . $code;
        $now = ApiEnvelope::nowMs();
        $rec = [
            'space_id' => $sid, 'code' => $code, 'token' => $code, 'invite_token' => $code,
            'invite_url' => $url, 'link' => $url,
            'created_ms' => $now, 'created_by' => $actor,
            'ttl_ms' => $ttl, 'max_uses' => $maxUses, 'uses' => 0,
            'expires_at_ms' => $ttl > 0 ? ($now + $ttl) : 0,
            'share_id' => 'nyx:space:' . $sid,
        ];
        $this->store()->put("invites/{$code}.json", $rec);
        // also store as active invite for space if rotate
        if ($rotate || $ttl || $maxUses) {
            $this->store()->put("invites/active_" . preg_replace("/[^a-zA-Z0-9_\-.]/", "_", $sid) . ".json", $rec);
        }
        $this->audit($sid, $actor ?: 'system', 'invite_create', $code, ['ttl_ms'=>$ttl,'max_uses'=>$maxUses]);
        return ApiEnvelope::ok($rec);
    }
    public function mine(Request $r): JsonResponse {
        $uid = strtolower(trim(AuthContext::userId($r)));
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $store = $this->store();
        // Resolve username -> canonical user_id when session carries username
        $urow = $store->get("users/{$uid}.json");
        if (!is_array($urow)) $urow = $store->get("identity/usernames/{$uid}.json");
        if (is_array($urow) && !empty($urow['user_id'])) {
            $uid = strtolower((string)$urow['user_id']);
        }
        $spaces = [];
        $root = $store->root() . '/memberships';
        if (is_dir($root)) foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $sid = basename($dir);
            if (!is_file($dir . '/' . $uid . '.json')) continue;
            $sp = $store->get("spaces/{$sid}.json") ?? ['space_id' => $sid, 'id' => $sid];
            $sp['id'] = $sp['id'] ?? $sid;
            $sp['space_id'] = $sp['space_id'] ?? $sid;
            $mcount = 0;
            $mdir = $store->root() . '/memberships/' . $sid;
            $ids = [];
            if (is_dir($mdir)) {
                foreach (glob($mdir . '/*.json') ?: [] as $mf) $ids[] = basename($mf, '.json');
                $mcount = count($ids);
            }
            $sp['member_count'] = $mcount;
            $sp['memberCount'] = $mcount;
            $sp['member_ids'] = $ids ?: ($sp['member_ids'] ?? $sp['memberIds'] ?? $sp['members'] ?? []);
            $sp['memberIds'] = $sp['member_ids'];
            $sp['members'] = $sp['member_ids'];
            // Per-viewer DM title: never show self as conversation name
            $type = strtolower((string)($sp['type'] ?? ''));
            if ($type === 'dm' || str_starts_with($sid, 'dm_')) {
                $sp['type'] = 'dm';
                $peer = '';
                foreach ($sp['member_ids'] as $mid) {
                    if (strtolower((string)$mid) !== $uid) { $peer = strtolower((string)$mid); break; }
                }
                if ($peer !== '') {
                    $prow = $store->get("users/{$peer}.json");
                    $pname = is_array($prow)
                        ? (string)($prow['display_name'] ?? $prow['username'] ?? $peer)
                        : $peer;
                    $sp['peer'] = $peer;
                    $sp['peer_id'] = $peer;
                    $sp['peer_display'] = $pname;
                    $sp['name'] = $pname;
                }
            }
            $spaces[] = $sp;
        }
        return ApiEnvelope::ok(['spaces' => $spaces, 'count' => count($spaces)]);
    }
    public function members(Request $r): JsonResponse {
        $sid = (string)($r->query('space_id') ?? ($r->json()['space_id'] ?? ''));
        if ($sid === '') return ApiEnvelope::error('space_id required', 422);
        $members = $this->membersOf($sid);
        return ApiEnvelope::ok(['space_id' => $sid, 'members' => $members, 'items' => $members, 'count' => count($members)]);
    }
    public function joinInvite(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $code = (string)($b['code'] ?? $r->query('code') ?? '');
        $uid = AuthContext::userId($r, $b);
        if ($code === '' || $uid === '') return ApiEnvelope::error('code and user_id required', 422);
        $inv = $this->store()->get("invites/{$code}.json");
        if (!$inv) return ApiEnvelope::error('invite not found', 404);
        $now = ApiEnvelope::nowMs();
        $exp = (int)($inv['expires_at_ms'] ?? 0);
        if ($exp > 0 && $now > $exp) return ApiEnvelope::error('invite expired', 410);
        $maxUses = (int)($inv['max_uses'] ?? 0);
        $uses = (int)($inv['uses'] ?? 0);
        if ($maxUses > 0 && $uses >= $maxUses) return ApiEnvelope::error('invite max uses reached', 410);
        $sid = (string)($inv['space_id'] ?? '');
        if ($sid === '') return ApiEnvelope::error('invalid invite', 422);
        $rec = ['space_id' => $sid, 'user_id' => $uid, 'role' => 'member', 'joined_ms' => ApiEnvelope::nowMs()];
        $this->store()->put("memberships/{$sid}/{$uid}.json", $rec);
        $space = $this->store()->get("spaces/{$sid}.json");
        return ApiEnvelope::ok(['joined' => true, 'space_id' => $sid, 'invite' => $code, 'space' => $space]);
    }

    public function requestJoin(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $sid = (string)($b['space_id'] ?? $b['id'] ?? '');
        $uid = AuthContext::userId($r, $b);
        if ($sid === '' || $uid === '') return ApiEnvelope::error('space_id and user_id required', 422);
        $space = $this->store()->get("spaces/{$sid}.json");
        if (!$space) return ApiEnvelope::error('space not found', 404);
        $public = !empty($space['is_public']) || !empty($space['public']);
        if ($public) {
            $rec = ['space_id' => $sid, 'user_id' => $uid, 'role' => 'member', 'joined_ms' => ApiEnvelope::nowMs()];
            $this->store()->put("memberships/{$sid}/{$uid}.json", $rec);
            return ApiEnvelope::ok(['joined' => true, 'status' => 'joined', 'space_id' => $sid, 'space' => $space]);
        }
        $req = [
            'space_id' => $sid, 'user_id' => $uid, 'status' => 'pending',
            'requested_ms' => ApiEnvelope::nowMs(),
        ];
        $this->store()->put("join_requests/{$sid}/{$uid}.json", $req);
        return ApiEnvelope::ok(['status' => 'pending', 'space_id' => $sid, 'request' => $req]);
    }

    public function joinRequests(Request $r): JsonResponse {
        $sid = (string)($r->query('space_id') ?? ($r->json()['space_id'] ?? ''));
        if ($sid === '') return ApiEnvelope::error('space_id required', 422);
        $dir = $this->store()->root() . '/join_requests/' . $sid;
        $items = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                $rec = json_decode((string)file_get_contents($f), true);
                if (is_array($rec) && (($rec['status'] ?? 'pending') === 'pending')) $items[] = $rec;
            }
        }
        return ApiEnvelope::ok(['space_id' => $sid, 'items' => $items, 'count' => count($items)]);
    }

    public function decideJoinRequest(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $sid = (string)($b['space_id'] ?? '');
        $uid = AuthContext::userId($r, $b);
        $decision = strtolower((string)($b['decision'] ?? 'approve'));
        if ($sid === '' || $uid === '') return ApiEnvelope::error('space_id and user_id required', 422);
        $path = $this->store()->root() . "/join_requests/{$sid}/{$uid}.json";
        $req = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        if ($decision === 'approve' || $decision === 'approved') {
            $rec = ['space_id' => $sid, 'user_id' => $uid, 'role' => 'member', 'joined_ms' => ApiEnvelope::nowMs()];
            $this->store()->put("memberships/{$sid}/{$uid}.json", $rec);
            if (is_file($path)) @unlink($path);
            return ApiEnvelope::ok(['approved' => true, 'space_id' => $sid, 'user_id' => $uid]);
        }
        if (is_array($req)) {
            $req['status'] = 'rejected';
            $req['decided_ms'] = ApiEnvelope::nowMs();
            $this->store()->put("join_requests/{$sid}/{$uid}.json", $req);
        } elseif (is_file($path)) {
            @unlink($path);
        }
        return ApiEnvelope::ok(['rejected' => true, 'space_id' => $sid, 'user_id' => $uid]);
    }

    public function ban(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $sid = (string)($b['space_id'] ?? '');
        $uid = strtolower(trim((string)($b['user_id'] ?? $b['target_id'] ?? $b['username'] ?? '')));
        $actor = AuthContext::userId($r, $b); if ($actor === '') $actor = strtolower(trim((string)($b['actor_id'] ?? '')));
        $row = $this->store()->get("users/{$uid}.json");
        if (!is_array($row)) $row = $this->store()->get("identity/usernames/{$uid}.json");
        if (is_array($row) && !empty($row['user_id'])) $uid = strtolower((string)$row['user_id']);
        $reason = substr(trim((string)($b['reason'] ?? '')), 0, 200);
        if ($sid === '' || $uid === '') return ApiEnvelope::error('space_id and user_id required', 422);
        $auth = $this->assertActor($sid, $actor, 'admin');
        if ($auth !== true) return $auth;
        if ($uid === $actor) return ApiEnvelope::error('cannot ban yourself', 422);
        $tr = $this->roleOf($sid, $uid);
        if ($tr === 'owner') return ApiEnvelope::error('cannot ban owner', 403);
        if ($this->rank($tr) >= $this->rank($this->roleOf($sid, $actor))) {
            return ApiEnvelope::error('FORBIDDEN: target rank too high', 403);
        }
        $path = $this->store()->root() . "/memberships/{$sid}/{$uid}.json";
        if (is_file($path)) @unlink($path);
        $ban = [
            'space_id' => $sid, 'user_id' => $uid, 'banned_by' => $actor,
            'banned_ms' => ApiEnvelope::nowMs(), 'reason' => $reason,
        ];
        $this->store()->put("bans/{$sid}/{$uid}.json", $ban);
        $this->audit($sid, $actor, 'ban', $uid . ($reason ? " ({$reason})" : ''), ['target_id'=>$uid,'reason'=>$reason]);
        return ApiEnvelope::ok(['banned' => true, 'space_id' => $sid, 'user_id' => $uid]);
    }

    public function unban(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $sid = (string)($b['space_id'] ?? '');
        $uid = strtolower(trim((string)($b['user_id'] ?? $b['target_id'] ?? '')));
        $actor = AuthContext::userId($r, $b); if ($actor === '') $actor = strtolower(trim((string)($b['actor_id'] ?? '')));
        if ($sid === '' || $uid === '') return ApiEnvelope::error('space_id and user_id required', 422);
        $auth = $this->assertActor($sid, $actor, 'admin');
        if ($auth !== true) return $auth;
        $path = $this->store()->root() . "/bans/{$sid}/{$uid}.json";
        if (is_file($path)) @unlink($path);
        $this->audit($sid, $actor, 'unban', $uid, ['target_id'=>$uid]);
        return ApiEnvelope::ok(['unbanned' => true, 'space_id' => $sid, 'user_id' => $uid]);
    }

    public function bans(Request $r): JsonResponse {
        $sid = (string)($r->query('space_id') ?? ($r->json()['space_id'] ?? ''));
        if ($sid === '') return ApiEnvelope::error('space_id required', 422);
        $dir = $this->store()->root() . '/bans/' . $sid;
        $items = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                $rec = json_decode((string)file_get_contents($f), true);
                if (is_array($rec)) $items[] = $rec;
            }
        }
        return ApiEnvelope::ok(['space_id' => $sid, 'items' => $items, 'count' => count($items)]);
    }


    public function conversations(Request $r): JsonResponse {
        $uid = AuthContext::userId($r);
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $store = $this->store();
        $items = [];
        // memberships
        $root = $store->root() . '/memberships';
        if (is_dir($root)) foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $sid = basename($dir);
            if (!is_file($dir . '/' . $uid . '.json')) continue;
            $sp = $store->get("spaces/{$sid}.json") ?? ['space_id' => $sid, 'id' => $sid, 'name' => $sid];
            $sp['id'] = $sp['id'] ?? $sid;
            $sp['space_id'] = $sp['space_id'] ?? $sid;
            $lastAt = 0; $preview = ''; $unread = 0;
            $idx = $store->get("messages/{$sid}/_index.json");
            if (is_array($idx) && !empty($idx['ids'])) {
                $lastId = end($idx['ids']);
                $last = $store->get("messages/{$sid}/{$lastId}.json");
                if (is_array($last)) {
                    $lastAt = (int)($last['created_ms'] ?? 0);
                    $preview = (string)($last['plaintext'] ?? $last['body'] ?? $last['text'] ?? '');
                    if ($preview === '' && !empty($last['ciphertext'])) $preview = '[encrypted]';
                }
            }
            $items[] = [
                'id' => $sid,
                'space' => $sp,
                'conversation_id' => $sid,
                'conv_id' => $sid,
                'type' => $sp['type'] ?? (str_starts_with($sid, 'dm_') ? 'dm' : 'group'),
                'last_at_ms' => $lastAt,
                'last_preview' => mb_substr($preview, 0, 120),
                'unread' => $unread,
            ];
        }
        // also scan spaces owned
        usort($items, static fn($a,$b) => ((int)$b['last_at_ms']) <=> ((int)$a['last_at_ms']));
        return ApiEnvelope::ok(['conversations' => $items, 'items' => $items, 'count' => count($items)]);
    }

public function openDm(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $store = $this->store();
        // Resolve any identifier (username or hash) to canonical user_id — no extra network
        $resolve = static function (string $raw) use ($store): array {
            $raw = strtolower(trim($raw));
            if ($raw === '') return ['id' => '', 'name' => ''];
            $row = $store->get("users/{$raw}.json");
            if (!is_array($row)) $row = $store->get("identity/usernames/{$raw}.json");
            if (is_array($row)) {
                $id = strtolower((string)($row['user_id'] ?? $raw));
                $name = (string)($row['display_name'] ?? $row['username'] ?? $id);
                return ['id' => $id, 'name' => $name];
            }
            return ['id' => $raw, 'name' => $raw];
        };

        $meRaw = strtolower(trim(AuthContext::userId($r, $b)));
        if ($meRaw === '') {
            $meRaw = strtolower(trim((string)($b['owner_id'] ?? $b['from'] ?? $b['me'] ?? $b['user_id'] ?? '')));
        }
        $peerRaw = strtolower(trim((string)($b['peer_id'] ?? $b['peer'] ?? $b['username'] ?? $b['user_id'] ?? $b['userId'] ?? $b['to'] ?? $b['target'] ?? '')));
        if ($peerRaw === '' && !empty($b['user_id']) && strtolower(trim((string)$b['user_id'])) !== $meRaw) {
            $peerRaw = strtolower(trim((string)$b['user_id']));
        }
        if ($peerRaw === '') return ApiEnvelope::error('peer required', 422);
        if ($meRaw === '') return ApiEnvelope::error('user_id (self) required', 422);

        $meInfo = $resolve($meRaw);
        $peerInfo = $resolve($peerRaw);
        $me = $meInfo['id'];
        $peer = $peerInfo['id'];
        if ($me === '' || $peer === '') return ApiEnvelope::error('peer required', 422);
        if ($me === $peer) return ApiEnvelope::error('cannot message yourself', 422);

        $ids = [$me, $peer];
        sort($ids);
        $sid = 'dm_' . substr(hash('sha256', $ids[0] . '|' . $ids[1]), 0, 24);

        $existing = $store->get('spaces/' . $sid . '.json');
        if (!is_array($existing)) {
            $existing = [
                'space_id' => $sid,
                'id' => $sid,
                'type' => 'dm',
                // Neutral stored name — per-viewer title is computed on read
                'name' => 'Direct message',
                'owner_id' => $me,
                'members' => [$me, $peer],
                'member_ids' => [$me, $peer],
                'is_public' => false,
                'created_ms' => (int)(microtime(true) * 1000),
            ];
            $store->put('spaces/' . $sid . '.json', $existing);
        }
        // memberships — always use canonical ids (idempotent)
        foreach ([$me, $peer] as $uid) {
            $mp = 'memberships/' . $sid . '/' . $uid . '.json';
            if (!is_array($store->get($mp))) {
                $store->put($mp, [
                    'space_id' => $sid,
                    'user_id' => $uid,
                    'role' => 'member',
                    'joined_ms' => (int)(microtime(true) * 1000),
                ]);
            }
        }
        $existing['id'] = $sid;
        $existing['conversation_id'] = $sid;
        $existing['space_id'] = $sid;
        $existing['type'] = 'dm';
        // Always return peer relative to the caller
        $existing['peer'] = $peer;
        $existing['peer_id'] = $peer;
        $existing['peer_display'] = $peerInfo['name'] !== '' ? $peerInfo['name'] : $peer;
        $existing['name'] = $existing['peer_display'];
        $existing['memberIds'] = [$me, $peer];
        $existing['member_ids'] = [$me, $peer];
        $existing['members'] = [$me, $peer];
        return ApiEnvelope::ok($existing);
    }

    
    public function addMember(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $sid = (string)($b['space_id'] ?? $b['id'] ?? $r->query('space_id') ?? '');
        $raw = strtolower(trim((string)($b['user_id'] ?? $b['username'] ?? $b['peer_id'] ?? $b['member'] ?? '')));
        if ($sid === '' || $raw === '') return ApiEnvelope::error('space_id and user_id required', 422);
        $store = $this->store();
        $sp = $store->get("spaces/{$sid}.json");
        if (!is_array($sp)) return ApiEnvelope::error('space not found', 404);
        // resolve username -> user_id
        $uid = $raw;
        $row = $store->get("users/{$raw}.json");
        if (!is_array($row)) $row = $store->get("identity/usernames/{$raw}.json");
        if (is_array($row) && !empty($row['user_id'])) $uid = strtolower((string)$row['user_id']);
        $dname = is_array($row) ? (string)($row['display_name'] ?? $row['username'] ?? $uid) : $uid;
        $store->put("memberships/{$sid}/{$uid}.json", [
            'space_id' => $sid, 'user_id' => $uid, 'role' => 'member',
            'joined_ms' => ApiEnvelope::nowMs(),
        ]);
        $mids = [];
        $mdir = $store->root() . '/memberships/' . $sid;
        if (is_dir($mdir)) {
            foreach (glob($mdir . '/*.json') ?: [] as $mf) {
                $mids[] = basename($mf, '.json');
            }
        }
        if (!in_array($uid, $mids, true)) $mids[] = $uid;
        $sp['members'] = $mids;
        $sp['member_ids'] = $mids;
        $sp['memberIds'] = $mids;
        $sp['member_count'] = count($mids);
        $sp['memberCount'] = count($mids);
        $store->put("spaces/{$sid}.json", $sp);
        // Server-side notification for the added member
        try {
            $nid = bin2hex(random_bytes(8));
            $nrec = $store->get("notifications/{$uid}.json");
            if (!is_array($nrec)) $nrec = ['user_id' => $uid, 'items' => []];
            $items = is_array($nrec['items'] ?? null) ? $nrec['items'] : [];
            array_unshift($items, [
                'id' => $nid,
                'user_id' => $uid,
                'kind' => 'system',
                'title' => 'Added to group',
                'desc' => (string)($sp['name'] ?? $sid),
                'space_id' => $sid,
                'conv_id' => $sid,
                'ts' => ApiEnvelope::nowMs(),
                'read' => false,
            ]);
            if (count($items) > 100) $items = array_slice($items, 0, 100);
            $nrec['items'] = $items;
            $nrec['updated_ms'] = ApiEnvelope::nowMs();
            $store->put("notifications/{$uid}.json", $nrec);
        } catch (\Throwable $e) {}
        return ApiEnvelope::ok([
            'space_id' => $sid, 'user_id' => $uid, 'display_name' => $dname,
            'role' => 'member', 'joined_ms' => ApiEnvelope::nowMs(),
            'member_ids' => $mids, 'count' => count($mids),
        ]);
    }

    public function mute(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $cid = (string)($b['conversation_id'] ?? $b['conv_id'] ?? $b['space_id'] ?? '');
        $uid = AuthContext::userId($r, $b);
        if ($uid === '') $uid = strtolower(trim((string)($b['user_id'] ?? '')));
        if ($cid === '' || $uid === '') return ApiEnvelope::error('conversation_id and user_id required', 422);
        $muted = !empty($b['muted']);
        $ms = (int)($b['ms'] ?? 0);
        $until = $ms > 0 ? (ApiEnvelope::nowMs() + $ms) : ($muted ? (ApiEnvelope::nowMs() + 365 * 86400 * 1000) : 0);
        $rec = [
            'conversation_id' => $cid,
            'user_id' => $uid,
            'muted' => $muted || $until > ApiEnvelope::nowMs(),
            'muted_until_ms' => $until,
            'updated_ms' => ApiEnvelope::nowMs(),
        ];
        $this->store()->put("mutes/{$uid}/{$cid}.json", $rec);
        return ApiEnvelope::ok($rec);
    }

    public function pinConv(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $cid = (string)($b['conversation_id'] ?? $b['conv_id'] ?? $b['space_id'] ?? '');
        $uid = AuthContext::userId($r, $b);
        if ($uid === '') $uid = strtolower(trim((string)($b['user_id'] ?? '')));
        if ($cid === '' || $uid === '') return ApiEnvelope::error('conversation_id and user_id required', 422);
        $pinned = array_key_exists('pinned', $b) ? !empty($b['pinned']) : true;
        $rec = ['conversation_id' => $cid, 'user_id' => $uid, 'pinned' => $pinned, 'updated_ms' => ApiEnvelope::nowMs()];
        $this->store()->put("pins_conv/{$uid}/{$cid}.json", $rec);
        return ApiEnvelope::ok($rec);
    }

    public function archive(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $cid = (string)($b['conversation_id'] ?? $b['conv_id'] ?? $b['space_id'] ?? '');
        $uid = AuthContext::userId($r, $b);
        if ($uid === '') $uid = strtolower(trim((string)($b['user_id'] ?? '')));
        if ($cid === '' || $uid === '') return ApiEnvelope::error('conversation_id and user_id required', 422);
        $archived = array_key_exists('archived', $b) ? !empty($b['archived']) : true;
        $rec = ['conversation_id' => $cid, 'user_id' => $uid, 'archived' => $archived, 'updated_ms' => ApiEnvelope::nowMs()];
        $this->store()->put("archives/{$uid}/{$cid}.json", $rec);
        return ApiEnvelope::ok($rec);
    }

    /** Clear chat history for the requesting user (or for all if for_all). */
    public function clearHistory(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $cid = (string)($b['conversation_id'] ?? $b['conv_id'] ?? $b['space_id'] ?? '');
        $uid = AuthContext::userId($r, $b);
        if ($uid === '') $uid = strtolower(trim((string)($b['user_id'] ?? '')));
        if ($cid === '' || $uid === '') return ApiEnvelope::error('conversation_id and user_id required', 422);
        $forAll = !empty($b['for_all']);
        $now = ApiEnvelope::nowMs();
        $s = $this->store();
        $s->put("clears/{$uid}/{$cid}.json", [
            'conversation_id' => $cid, 'user_id' => $uid,
            'cleared_ms' => $now, 'for_all' => $forAll,
        ]);
        if ($forAll) {
            $idx = $s->get("messages/{$cid}/_index.json");
            $ids = is_array($idx['ids'] ?? null) ? $idx['ids'] : [];
            foreach ($ids as $mid) {
                $m = $s->get("messages/{$cid}/{$mid}.json");
                if (is_array($m)) {
                    $m['deleted'] = 1; $m['deleted_ms'] = $now; $m['body'] = ''; $m['ciphertext'] = null;
                    $s->put("messages/{$cid}/{$mid}.json", $m);
                }
            }
        }
        return ApiEnvelope::ok(['conversation_id' => $cid, 'user_id' => $uid, 'cleared_ms' => $now, 'for_all' => $forAll]);
    }

    /** Hide/remove conversation from user's list (DM remove / leave equivalent). */
    public function deleteConversation(Request $r): JsonResponse {
        $b = $r->json() ?? [];
        $cid = (string)($b['conversation_id'] ?? $b['conv_id'] ?? $b['space_id'] ?? '');
        $uid = AuthContext::userId($r, $b);
        if ($uid === '') $uid = strtolower(trim((string)($b['user_id'] ?? '')));
        if ($cid === '' || $uid === '') return ApiEnvelope::error('conversation_id and user_id required', 422);
        $s = $this->store();
        $s->put("hidden/{$uid}/{$cid}.json", [
            'conversation_id' => $cid, 'user_id' => $uid, 'hidden' => true, 'updated_ms' => ApiEnvelope::nowMs(),
        ]);
        // also leave membership if group
        $path = $s->root() . "/memberships/{$cid}/{$uid}.json";
        if (is_file($path)) @unlink($path);
        return ApiEnvelope::ok(['conversation_id' => $cid, 'user_id' => $uid, 'deleted' => true]);
    }

    public function auditLog(Request $r): JsonResponse {
        $sid = (string)($r->query('space_id') ?? (($r->json() ?? [])['space_id'] ?? ''));
        $b = $r->json() ?? [];
        $actor = AuthContext::userId($r, $b);
        if ($actor === '') $actor = strtolower(trim((string)($r->query('actor_id') ?? ($b['actor_id'] ?? ''))));
        if ($sid === '') return ApiEnvelope::error('space_id required', 422);
        $auth = $this->assertActor($sid, $actor, 'mod');
        if ($auth !== true) return $auth;
        $items = $this->listAudit($sid, 50);
        return ApiEnvelope::ok(['space_id'=>$sid,'items'=>$items,'audit'=>$items,'count'=>count($items)]);
    }

    public function settings(Request $r): JsonResponse {
        return $this->update($r);
    }
}