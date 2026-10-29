<?php
declare(strict_types=1);

namespace Nyx\Core\Security\Crypto;

final class SodiumBox
{
    public static function available(): bool
    {
        return extension_loaded('sodium');
    }

    public static function random(int $bytes = 32): string
    {
        return random_bytes($bytes);
    }

    public static function hash(string $data): string
    {
        return hash('sha256', $data);
    }

    public static function secretKey(): string
    {
        return sodium_crypto_secretbox_keygen();
    }

    public static function encrypt(string $plain, string $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
        return base64_encode($nonce . $cipher);
    }

    public static function decrypt(string $payloadB64, string $key): ?string
    {
        $raw = base64_decode($payloadB64, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        return $plain === false ? null : $plain;
    }
}
