<?php
declare(strict_types=1);

namespace Nyx\Core\Security\Authentication;

use Nyx\Core\Http\Request;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Core\Maintenance\DataGc;

/**
 * Validates Bearer session tokens under data/sessions/.
 * Soft device bind via X-Device-Id; set NYX_STRICT_DEVICE_BIND=true to hard-fail mismatch.
 */
final class SessionAuthenticator
{
    public function __construct(private ?FileStore $store = null)
    {
        if ($this->store === null) {
            $root = defined('DATA_DIR') ? DATA_DIR : (defined('NYX_DATA_DIR') ? NYX_DATA_DIR : '');
            $this->store = new FileStore($root);
        }
    }

    public function extractToken(Request $request): ?string
    {
        $auth = (string)($request->header('Authorization') ?? '');
        if ($auth !== '' && preg_match('/^Bearer\s+(\S+)/i', $auth, $m)) {
            return $m[1];
        }
        $alt = (string)($request->header('X-Session-Token') ?? $request->header('X-Auth-Token') ?? '');
        if ($alt !== '' && preg_match('/^[a-f0-9]{32,128}$/i', $alt)) {
            return $alt;
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public function validate(Request $request): ?array
    {
        $token = $this->extractToken($request);
        if ($token === null || $token === '' || !preg_match('/^[a-f0-9]{32,128}$/i', $token)) {
            return null;
        }
        $session = $this->store->get("sessions/{$token}.json");
        if (!is_array($session) || empty($session['token'])) {
            return null;
        }
        $expires = (int)($session['expires_ms'] ?? 0);
        $nowMs = (int) floor(microtime(true) * 1000);
        if ($expires > 0 && $expires < $nowMs) {
            $this->store->delete("sessions/{$token}.json");
            return null;
        }

        $bound = strtolower(trim((string)($session['device_id'] ?? '')));
        $clientDevice = strtolower(trim((string)($request->header('X-Device-Id') ?? '')));
        $strict = filter_var(getenv('NYX_STRICT_DEVICE_BIND') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        if ($bound !== '') {
            if ($clientDevice === '') {
                // Omitting device header when session is bound: strict mode rejects
                if ($strict) {
                    return null;
                }
            } elseif (!hash_equals($bound, $clientDevice)) {
                if ($strict) {
                    return null;
                }
            }
        }
        if ($bound === '' && $clientDevice !== '' && preg_match('/^[a-zA-Z0-9._\\-:]{4,128}$/', $clientDevice)) {
            $session['device_id'] = $clientDevice;
            $session['device_bound_ms'] = $nowMs;
            $this->store->put("sessions/{$token}.json", $session);
        }
        if (random_int(1, 8) === 1) {
            $session['last_seen_ms'] = $nowMs;
            $this->store->put("sessions/{$token}.json", $session);
        }
        if (random_int(1, 40) === 1) {
            try {
                (new DataGc($this->store))->run(30);
            } catch (\Throwable $e) {
            }
        }

        return [
            'token' => (string)$session['token'],
            'user_id' => isset($session['user_id']) ? (string)$session['user_id'] : null,
            'username' => isset($session['username']) ? (string)$session['username'] : null,
            'expires_ms' => $expires > 0 ? $expires : null,
            'device_id' => isset($session['device_id']) ? (string)$session['device_id'] : null,
        ];
    }

    public function requireUser(Request $request): array|false
    {
        $session = $this->validate($request);
        if ($session === null || empty($session['user_id'])) {
            return false;
        }
        return $session;
    }

    public function revoke(string $token): bool
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{32,128}$/i', $token)) {
            return false;
        }
        $key = "sessions/{$token}.json";
        $exists = is_array($this->store->get($key));
        $this->store->delete($key);
        return $exists;
    }
}
