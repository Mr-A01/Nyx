<?php
declare(strict_types=1);

namespace Nyx\Shared\Infrastructure\Storage;

/**
 * Signed upload tokens for CDN/direct-to-disk uploads.
 * Client requests a ticket, uploads with ticket, server verifies HMAC.
 */
final class SignedUpload
{
    public static function secret(): string
    {
        $s = getenv('UPLOAD_SIGNING_SECRET') ?: '';
        if ($s === '' && function_exists('env')) {
            $s = (string)env('UPLOAD_SIGNING_SECRET', '');
        }
        if ($s === '') {
            $s = getenv('APP_KEY') ?: 'nyx-dev-upload-secret-change-me';
        }
        return $s;
    }

    public static function issue(string $userId, int $ttlSec = 300): array
    {
        $exp = time() + max(60, $ttlSec);
        $id = bin2hex(random_bytes(12));
        $payload = $id . '|' . $userId . '|' . $exp;
        $sig = hash_hmac('sha256', $payload, self::secret());
        return [
            'upload_id' => $id,
            'expires_at' => $exp,
            'token' => base64_encode($payload . '|' . $sig),
            'put_path' => '/api/v3/uploads/signed',
        ];
    }

    public static function verify(string $token, string $userId): bool
    {
        $raw = base64_decode($token, true);
        if ($raw === false) return false;
        $parts = explode('|', $raw);
        if (count($parts) !== 4) return false;
        [$id, $uid, $exp, $sig] = $parts;
        if (!hash_equals($uid, $userId)) return false;
        if ((int)$exp < time()) return false;
        $payload = $id . '|' . $uid . '|' . $exp;
        $expect = hash_hmac('sha256', $payload, self::secret());
        return hash_equals($expect, $sig);
    }
}
