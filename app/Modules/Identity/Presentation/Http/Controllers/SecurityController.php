<?php
declare(strict_types=1);
namespace Nyx\Modules\Identity\Presentation\Http\Controllers;

use Nyx\Core\Http\Request;
use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Http\AuthContext;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;

/**
 * Security center: sessions, recovery codes, freeze, login alerts, step-up.
 */
final class SecurityController
{
    private function store(): FileStore
    {
        return new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
    }

    private function uid(Request $r, array $b = []): string
    {
        $u = AuthContext::userId($r, $b);
        return $u !== '' ? $u : (string)($b['user_id'] ?? '');
    }

    /** List active sessions for the authenticated user. */
    public function sessions(Request $r): JsonResponse
    {
        $uid = $this->uid($r);
        if ($uid === '') return ApiEnvelope::error('unauthorized', 401);
        $items = [];
        $root = $this->store()->root() . '/sessions';
        $current = AuthContext::bearerToken($r);
        if (is_dir($root)) {
            foreach (glob($root . '/*.json') ?: [] as $f) {
                $rec = json_decode((string)@file_get_contents($f), true);
                if (!is_array($rec)) continue;
                if (strtolower((string)($rec['user_id'] ?? $rec['uid'] ?? '')) !== strtolower($uid)) continue;
                $token = (string)($rec['session'] ?? $rec['token'] ?? basename($f, '.json'));
                $items[] = [
                    'session_id' => substr(hash('sha256', $token), 0, 16),
                    'token_hint' => substr($token, 0, 6) . '…',
                    'created_ms' => (int)($rec['created_ms'] ?? 0),
                    'last_seen_ms' => (int)($rec['last_seen_ms'] ?? $rec['touched_ms'] ?? $rec['created_ms'] ?? 0),
                    'ip' => (string)($rec['ip'] ?? ''),
                    'user_agent' => substr((string)($rec['user_agent'] ?? $rec['ua'] ?? ''), 0, 120),
                    'device_id' => (string)($rec['device_id'] ?? ''),
                    'current' => hash_equals($current, $token),
                ];
            }
        }
        usort($items, static fn($a, $b) => ($b['last_seen_ms'] <=> $a['last_seen_ms']));
        return ApiEnvelope::ok(['user_id' => $uid, 'sessions' => $items, 'count' => count($items)]);
    }

    /** Revoke one session by session_id hash prefix or all except current. */
    public function revokeSession(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $uid = $this->uid($r, $b);
        if ($uid === '') return ApiEnvelope::error('unauthorized', 401);
        $target = (string)($b['session_id'] ?? '');
        $all = !empty($b['all']);
        $current = AuthContext::bearerToken($r);
        $revoked = 0;
        $root = $this->store()->root() . '/sessions';
        if (is_dir($root)) {
            foreach (glob($root . '/*.json') ?: [] as $f) {
                $rec = json_decode((string)@file_get_contents($f), true);
                if (!is_array($rec)) continue;
                if (strtolower((string)($rec['user_id'] ?? $rec['uid'] ?? '')) !== strtolower($uid)) continue;
                $token = (string)($rec['session'] ?? $rec['token'] ?? basename($f, '.json'));
                $sid = substr(hash('sha256', $token), 0, 16);
                if (hash_equals($current, $token) && !$all) continue; // keep current unless forced
                if ($all || ($target !== '' && hash_equals($target, $sid))) {
                    @unlink($f);
                    $revoked++;
                }
            }
        }
        $this->audit($uid, 'session_revoke', ['revoked' => $revoked, 'all' => $all]);
        return ApiEnvelope::ok(['revoked' => $revoked]);
    }

    /** Generate one-time recovery codes (replaces previous set). */
    public function recoveryCodes(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $uid = $this->uid($r, $b);
        if ($uid === '') return ApiEnvelope::error('unauthorized', 401);
        if (!in_array($r->method(), ['POST', 'PUT'], true)) {
            $rec = $this->store()->get("security/recovery/{$uid}.json");
            $count = is_array($rec) ? count($rec['codes'] ?? []) : 0;
            $used = is_array($rec) ? count($rec['used'] ?? []) : 0;
            return ApiEnvelope::ok(['user_id' => $uid, 'remaining' => max(0, $count - $used), 'generated' => is_array($rec)]);
        }
        $codes = [];
        $hashes = [];
        for ($i = 0; $i < 10; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
            $codes[] = $raw;
            $hashes[] = hash('sha256', strtolower($raw));
        }
        $this->store()->put("security/recovery/{$uid}.json", [
            'user_id' => $uid,
            'codes' => $hashes,
            'used' => [],
            'created_ms' => ApiEnvelope::nowMs(),
        ]);
        $this->audit($uid, 'recovery_codes_generated', ['count' => 10]);
        return ApiEnvelope::ok(['user_id' => $uid, 'codes' => $codes, 'note' => 'Store offline. Shown once.']);
    }

    /** Consume a recovery code (for account recovery flows). */
    public function useRecoveryCode(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $uid = strtolower(trim((string)($b['user_id'] ?? $b['username'] ?? '')));
        $code = strtolower(trim((string)($b['code'] ?? '')));
        if ($uid === '' || $code === '') return ApiEnvelope::error('user_id and code required', 422);
        $row = $this->store()->get("users/{$uid}.json");
        if (!is_array($row)) $row = $this->store()->get("identity/usernames/{$uid}.json");
        if (is_array($row) && !empty($row['user_id'])) $uid = strtolower((string)$row['user_id']);
        $rec = $this->store()->get("security/recovery/{$uid}.json");
        if (!is_array($rec)) return ApiEnvelope::error('no recovery codes', 404);
        $h = hash('sha256', $code);
        $codes = $rec['codes'] ?? [];
        $used = $rec['used'] ?? [];
        if (!in_array($h, $codes, true) || in_array($h, $used, true)) {
            return ApiEnvelope::error('invalid or used code', 403);
        }
        $used[] = $h;
        $rec['used'] = $used;
        $this->store()->put("security/recovery/{$uid}.json", $rec);
        return ApiEnvelope::ok(['ok' => true, 'user_id' => $uid, 'remaining' => count($codes) - count($used)]);
    }

    /** Freeze / unfreeze account. */
    public function freeze(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $uid = $this->uid($r, $b);
        if ($uid === '') return ApiEnvelope::error('unauthorized', 401);
        $freeze = array_key_exists('frozen', $b) ? !empty($b['frozen']) : true;
        $rec = [
            'user_id' => $uid,
            'frozen' => $freeze,
            'reason' => substr((string)($b['reason'] ?? ''), 0, 200),
            'updated_ms' => ApiEnvelope::nowMs(),
        ];
        $this->store()->put("security/freeze/{$uid}.json", $rec);
        // mirror on user record
        $user = $this->store()->get("users/{$uid}.json");
        if (is_array($user)) {
            $user['frozen'] = $freeze;
            $this->store()->put("users/{$uid}.json", $user);
        }
        $this->audit($uid, $freeze ? 'account_freeze' : 'account_unfreeze', $rec);
        return ApiEnvelope::ok($rec);
    }

    public function freezeStatus(Request $r): JsonResponse
    {
        $uid = $this->uid($r);
        if ($uid === '') return ApiEnvelope::error('unauthorized', 401);
        $rec = $this->store()->get("security/freeze/{$uid}.json");
        return ApiEnvelope::ok(is_array($rec) ? $rec : ['user_id' => $uid, 'frozen' => false]);
    }

    /** Login alerts feed. */
    public function loginAlerts(Request $r): JsonResponse
    {
        $uid = $this->uid($r);
        if ($uid === '') return ApiEnvelope::error('unauthorized', 401);
        $rec = $this->store()->get("security/login_alerts/{$uid}.json");
        $items = is_array($rec) && is_array($rec['items'] ?? null) ? $rec['items'] : [];
        return ApiEnvelope::ok(['user_id' => $uid, 'items' => array_slice($items, -50), 'count' => count($items)]);
    }

    /** Record a login alert (called from login pipeline or client). */
    public function recordLoginAlert(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $uid = $this->uid($r, $b);
        if ($uid === '') $uid = strtolower(trim((string)($b['user_id'] ?? '')));
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $rec = $this->store()->get("security/login_alerts/{$uid}.json");
        if (!is_array($rec)) $rec = ['user_id' => $uid, 'items' => []];
        $item = [
            'id' => UlidLikeId::generate('la'),
            'ms' => ApiEnvelope::nowMs(),
            'ip' => (string)($b['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string)($b['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
            'device_id' => (string)($b['device_id'] ?? ''),
            'success' => !array_key_exists('success', $b) || !empty($b['success']),
        ];
        $rec['items'][] = $item;
        if (count($rec['items']) > 100) $rec['items'] = array_slice($rec['items'], -100);
        $this->store()->put("security/login_alerts/{$uid}.json", $rec);
        return ApiEnvelope::ok(['recorded' => true, 'item' => $item]);
    }

    /** Issue short-lived step-up token for sensitive ops. */
    public function stepUp(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $uid = $this->uid($r, $b);
        if ($uid === '') return ApiEnvelope::error('unauthorized', 401);
        $password = (string)($b['password'] ?? '');
        // soft check: if password provided, verify against user record hash if present
        $user = $this->store()->get("users/{$uid}.json");
        if (is_array($user) && !empty($user['password_hash']) && $password !== '') {
            if (!password_verify($password, (string)$user['password_hash'])) {
                return ApiEnvelope::error('invalid password', 403);
            }
        }
        $token = bin2hex(random_bytes(24));
        $exp = ApiEnvelope::nowMs() + 5 * 60 * 1000;
        $this->store()->put("security/stepup/{$token}.json", [
            'user_id' => $uid,
            'token' => $token,
            'expires_ms' => $exp,
            'scope' => (string)($b['scope'] ?? 'sensitive'),
        ]);
        return ApiEnvelope::ok(['step_up_token' => $token, 'expires_ms' => $exp, 'ttl_sec' => 300]);
    }

    public static function requireStepUp(?string $token, string $uid): bool
    {
        if ($token === null || $token === '') return false;
        $store = new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
        $rec = $store->get("security/stepup/{$token}.json");
        if (!is_array($rec)) return false;
        if (strtolower((string)($rec['user_id'] ?? '')) !== strtolower($uid)) return false;
        if ((int)($rec['expires_ms'] ?? 0) < ApiEnvelope::nowMs()) return false;
        return true;
    }

    private function audit(string $uid, string $action, array $extra = []): void
    {
        $rec = $this->store()->get("security/audit/{$uid}.json");
        if (!is_array($rec)) $rec = ['user_id' => $uid, 'items' => []];
        $rec['items'][] = array_merge(['action' => $action, 'ms' => ApiEnvelope::nowMs()], $extra);
        if (count($rec['items']) > 200) $rec['items'] = array_slice($rec['items'], -200);
        $this->store()->put("security/audit/{$uid}.json", $rec);
    }
}
