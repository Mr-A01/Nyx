<?php
declare(strict_types=1);

namespace Nyx\Core\Security\Authentication;

use Nyx\Core\Http\Request;
use Nyx\Shared\Infrastructure\Storage\FileStore;

/**
 * Validates Bearer session tokens stored under data/sessions/.
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
        // Fallback headers / query used by some clients
        $alt = (string)($request->header('X-Session-Token') ?? $request->header('X-Auth-Token') ?? '');
        if ($alt !== '') {
            return $alt;
        }
        $q = $request->query('token');
        if (is_string($q) && $q !== '') {
            return $q;
        }
        $body = $request->json() ?? [];
        foreach (['token', 'session', 'session_token'] as $k) {
            if (!empty($body[$k]) && is_string($body[$k])) {
                return $body[$k];
            }
        }
        return null;
    }

    /**
     * @return array{token:string,user_id:?string,username:?string,expires_ms:?int}|null
     */
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
        if ($expires > 0 && $expires < (int)floor(microtime(true) * 1000)) {
            $this->store->delete("sessions/{$token}.json");
            return null;
        }
        return [
            'token' => (string)$session['token'],
            'user_id' => isset($session['user_id']) ? (string)$session['user_id'] : null,
            'username' => isset($session['username']) ? (string)$session['username'] : null,
            'expires_ms' => $expires > 0 ? $expires : null,
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
}
