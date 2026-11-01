<?php
declare(strict_types=1);

namespace Nyx\Shared\Infrastructure\Http;

use Nyx\Core\Http\Request;
use Nyx\Shared\Infrastructure\Storage\FileStore;

/**
 * Resolves the authenticated user from a validated session only.
 * Never trusts client-supplied user_id / from / me fields for identity.
 */
final class AuthContext
{
    public static function userId(?Request $r = null, array $body = []): string
    {
        // 1) Session attached by Application after SessionAuthenticator
        $fromGlobal = (string)(($GLOBALS['nyx_auth']['user_id'] ?? '') ?: '');
        if ($fromGlobal !== '') {
            return strtolower($fromGlobal);
        }

        // 2) Validate bearer session against store
        $token = self::bearerToken($r);
        if ($token !== '' && preg_match('/^[a-f0-9]{32,128}$/i', $token)) {
            try {
                $root = defined('DATA_DIR') ? DATA_DIR : (defined('NYX_DATA_DIR') ? NYX_DATA_DIR : '');
                $store = new FileStore($root);
                $sess = $store->get('sessions/' . $token . '.json');
                if (is_array($sess)) {
                    $expires = (int)($sess['expires_ms'] ?? 0);
                    if ($expires > 0 && $expires < (int)floor(microtime(true) * 1000)) {
                        $store->delete('sessions/' . $token . '.json');
                        return '';
                    }
                    $uid = strtolower((string)($sess['user_id'] ?? $sess['uid'] ?? ''));
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
                return '';
            }
        }

        // Intentionally NO fallback to body/query user_id — that was an impersonation vector.
        return '';
    }

    public static function username(): string
    {
        return (string)(($GLOBALS['nyx_auth']['username'] ?? '') ?: '');
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
        // Optional dedicated headers only (never query/body)
        if ($r !== null && method_exists($r, 'header')) {
            $alt = (string)($r->header('X-Session-Token') ?? $r->header('X-Auth-Token') ?? '');
            if ($alt !== '' && preg_match('/^[a-f0-9]{32,128}$/i', $alt)) {
                return $alt;
            }
        }
        return '';
    }
}
