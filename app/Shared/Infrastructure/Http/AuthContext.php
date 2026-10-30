<?php
declare(strict_types=1);

namespace Nyx\Shared\Infrastructure\Http;

use Nyx\Core\Http\Request;
use Nyx\Shared\Infrastructure\Storage\FileStore;

/** Resolve authenticated user id from middleware session, Bearer token, or request fields. */
final class AuthContext
{
    public static function userId(?Request $r = null, array $body = []): string
    {
        $fromSession = (string)(($GLOBALS['nyx_auth']['user_id'] ?? '') ?: '');
        if ($fromSession !== '') {
            return $fromSession;
        }

        // Bearer token → sessions/{token}.json
        $token = self::bearerToken($r);
        if ($token !== '') {
            try {
                $store = new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
                $sess = $store->get('sessions/' . $token . '.json');
                if (is_array($sess)) {
                    $uid = (string)($sess['user_id'] ?? $sess['uid'] ?? '');
                    if ($uid !== '') {
                        $GLOBALS['nyx_auth'] = array_merge(
                            is_array($GLOBALS['nyx_auth'] ?? null) ? $GLOBALS['nyx_auth'] : [],
                            [
                                'user_id' => $uid,
                                'username' => (string)($sess['username'] ?? ''),
                                'token' => $token,
                            ]
                        );
                        return $uid;
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if ($r !== null) {
            $q = (string)($r->query('user_id') ?? '');
            if ($q !== '') {
                return $q;
            }
            $json = $r->json() ?? [];
            foreach (['user_id', 'sender_id', 'from', 'me'] as $k) {
                if (!empty($json[$k]) && is_string($json[$k])) {
                    return (string)$json[$k];
                }
            }
        }
        foreach (['user_id', 'sender_id', 'from', 'me'] as $k) {
            if (!empty($body[$k]) && is_string($body[$k])) {
                return (string)$body[$k];
            }
        }
        return '';
    }

    public static function username(): string
    {
        $u = (string)(($GLOBALS['nyx_auth']['username'] ?? '') ?: '');
        if ($u !== '') return $u;
        return '';
    }

    public static function bearerToken(?Request $r = null): string
    {
        $hdr = '';
        if ($r !== null && method_exists($r, 'header')) {
            $hdr = (string)($r->header('Authorization') ?? $r->header('authorization') ?? '');
        }
        if ($hdr === '') {
            $hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }
        if (stripos($hdr, 'Bearer ') === 0) {
            return trim(substr($hdr, 7));
        }
        // also accept X-Session / token query
        if ($r !== null) {
            $t = (string)($r->query('token') ?? $r->query('session') ?? '');
            if ($t !== '') return $t;
            $json = $r->json() ?? [];
            if (!empty($json['token']) && is_string($json['token'])) return $json['token'];
        }
        return '';
    }
}
