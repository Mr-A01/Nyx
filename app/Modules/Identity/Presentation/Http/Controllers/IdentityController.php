<?php
declare(strict_types=1);
namespace Nyx\Modules\Identity\Presentation\Http\Controllers;

use Nyx\Core\Http\Request;
use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Core\Security\Authentication\SessionAuthenticator;

final class IdentityController
{
    private function store(): FileStore
    {
        return new FileStore(defined('DATA_DIR') ? DATA_DIR : (defined('NYX_DATA_DIR') ? NYX_DATA_DIR : ''));
    }

    private function rateKey(string $action, string $ip): string
    {
        return 'rate/auth/' . $action . '/' . hash('sha256', $ip) . '.json';
    }

    private function checkRate(string $action, int $max = 20, int $windowSec = 300): bool
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0');
        $key = $this->rateKey($action, $ip);
        $s = $this->store();
        $row = $s->get($key);
        $now = time();
        if (!is_array($row) || (int)($row['reset'] ?? 0) < $now) {
            $s->put($key, ['count' => 1, 'reset' => $now + $windowSec]);
            return true;
        }
        $count = (int)($row['count'] ?? 0) + 1;
        if ($count > $max) {
            return false;
        }
        $s->put($key, ['count' => $count, 'reset' => (int)$row['reset']]);
        return true;
    }

    public function challenge(Request $r): JsonResponse
    {
        $n = bin2hex(random_bytes(16));
        $e = ApiEnvelope::nowMs() + 300000;
        $this->store()->put("identity/challenges/{$n}.json", ['nonce' => $n, 'expires_ms' => $e]);
        return ApiEnvelope::ok(['nonce' => $n, 'expires_ms' => $e, 'alg' => 'ed25519']);
    }

    public function register(Request $r): JsonResponse
    {
        if (!$this->checkRate('register', 10, 600)) {
            return ApiEnvelope::error('rate limit exceeded', 429);
        }
        $b = $r->json() ?? [];
        $u = strtolower(trim((string)($b['username'] ?? '')));
        $pw = (string)($b['password'] ?? '');
        $pk = (string)($b['public_key'] ?? $b['publicKey'] ?? '');

        if ($u === '' || !preg_match('/^[a-z0-9_]{3,32}$/', $u)) {
            return ApiEnvelope::error('invalid username (3-32 chars, a-z 0-9 _)', 422);
        }
        if (strlen($pw) < 8) {
            return ApiEnvelope::error('password must be at least 8 characters', 422);
        }
        if ($this->store()->get("identity/usernames/{$u}.json")) {
            return ApiEnvelope::error('username taken', 409);
        }

        $uid = (string)($b['user_id'] ?? UlidLikeId::generate());
        $ph = password_hash($pw, PASSWORD_DEFAULT);
        if ($ph === false) {
            return ApiEnvelope::error('password hashing failed', 500);
        }

        $rec = [
            'user_id' => $uid,
            'username' => $u,
            'public_key' => $pk,
            'display_name' => (string)($b['display_name'] ?? $b['displayName'] ?? $u),
            'password_hash' => $ph,
            'created_ms' => ApiEnvelope::nowMs(),
        ];
        $this->store()->put("identity/usernames/{$u}.json", $rec);
        $this->store()->put("users/{$uid}.json", $rec);

        $token = bin2hex(random_bytes(24));
        $this->store()->put("sessions/{$token}.json", [
            'token' => $token,
            'user_id' => $uid,
            'username' => $u,
            'created_ms' => ApiEnvelope::nowMs(),
            'expires_ms' => ApiEnvelope::nowMs() + 86400000 * 30,
        ]);

        return ApiEnvelope::ok([
            'user_id' => $uid,
            'username' => $u,
            'public_key' => $pk,
            'token' => $token,
            'session' => $token,
            'displayName' => $rec['display_name'],
        ]);
    }

    public function usernameLookup(Request $r): JsonResponse
    {
        $u = strtolower(trim((string)($r->query('username') ?? (($r->json() ?? [])['username'] ?? ''))));
        if ($u === '') {
            return ApiEnvelope::error('username required', 422);
        }
        $row = $this->store()->get("identity/usernames/{$u}.json");
        if (!$row) {
            return ApiEnvelope::error('not found', 404);
        }
        return ApiEnvelope::ok([
            'username' => $row['username'] ?? $u,
            'user_id' => $row['user_id'] ?? null,
            'public_key' => $row['public_key'] ?? null,
            'display_name' => $row['display_name'] ?? $u,
            'exists' => true,
        ]);
    }

    public function session(Request $r): JsonResponse
    {
        return $this->login($r);
    }

    public function login(Request $r): JsonResponse
    {
        if (!$this->checkRate('login', 30, 300)) {
            return ApiEnvelope::error('rate limit exceeded', 429);
        }

        $b = $r->json() ?? [];
        $u = strtolower(trim((string)($b['username'] ?? '')));
        $pw = (string)($b['password'] ?? '');

        if ($u === '') {
            return ApiEnvelope::error('username required', 422);
        }
        if ($pw === '') {
            return ApiEnvelope::error('password required', 401);
        }

        $row = $this->store()->get("identity/usernames/{$u}.json");
        // Constant-time style: always hash something if missing to reduce timing signal
        if (!$row || empty($row['password_hash'])) {
            password_verify($pw, '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUV'); // dummy
            return ApiEnvelope::error('invalid credentials', 401);
        }

        if (!password_verify($pw, (string)$row['password_hash'])) {
            return ApiEnvelope::error('invalid credentials', 401);
        }

        $token = bin2hex(random_bytes(24));
        $this->store()->put("sessions/{$token}.json", [
            'token' => $token,
            'user_id' => $row['user_id'] ?? null,
            'username' => $u,
            'created_ms' => ApiEnvelope::nowMs(),
            'expires_ms' => ApiEnvelope::nowMs() + 86400000 * 30,
        ]);

        return ApiEnvelope::ok([
            'token' => $token,
            'session' => $token,
            'username' => $u,
            'user_id' => $row['user_id'] ?? null,
            'displayName' => $row['display_name'] ?? $u,
            'display_name' => $row['display_name'] ?? $u,
        ]);
    }

    public function me(Request $r): JsonResponse
    {
        $auth = (new SessionAuthenticator($this->store()))->requireUser($r);
        if ($auth === false) {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $uid = (string)$auth['user_id'];
        $row = $this->store()->get("users/{$uid}.json");
        if (!$row) {
            $u = (string)($auth['username'] ?? '');
            $row = $u !== '' ? $this->store()->get("identity/usernames/{$u}.json") : null;
        }
        if (!$row) {
            return ApiEnvelope::error('user not found', 404);
        }
        return ApiEnvelope::ok([
            'user_id' => $row['user_id'] ?? $uid,
            'username' => $row['username'] ?? $auth['username'],
            'display_name' => $row['display_name'] ?? $row['username'] ?? '',
            'public_key' => $row['public_key'] ?? '',
        ]);
    }

    public function logout(Request $r): JsonResponse
    {
        $auth = new SessionAuthenticator($this->store());
        $token = $auth->extractToken($r);
        if ($token) {
            $this->store()->delete("sessions/{$token}.json");
        }
        return ApiEnvelope::ok(['logged_out' => true]);
    }
}
