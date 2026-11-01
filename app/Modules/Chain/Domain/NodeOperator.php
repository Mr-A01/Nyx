<?php
declare(strict_types=1);

namespace Nyx\Modules\Chain\Domain;

use Nyx\Shared\Infrastructure\Storage\FileStore;

/**
 * Per-node Ed25519 operator identity for work-chain non-repudiation.
 * Key material lives under data/chain/ (not in web root).
 */
final class NodeOperator
{
    private const KEY_FILE = 'chain/operator_ed25519.json';

    public function __construct(private FileStore $store) {}

    /** @return array{public_key:string,secret_key:string,created_ms:int} */
    public function ensureKeypair(): array
    {
        $existing = $this->store->get(self::KEY_FILE);
        if (is_array($existing)
            && !empty($existing['public_key'])
            && !empty($existing['secret_key'])
            && strlen((string)$existing['public_key']) === 64
            && strlen((string)$existing['secret_key']) === 128
        ) {
            return [
                'public_key' => (string)$existing['public_key'],
                'secret_key' => (string)$existing['secret_key'],
                'created_ms' => (int)($existing['created_ms'] ?? 0),
            ];
        }
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('sodium extension required for operator signatures');
        }
        $kp = sodium_crypto_sign_keypair();
        $sk = sodium_crypto_sign_secretkey($kp);
        $pk = sodium_crypto_sign_publickey($kp);
        $rec = [
            'alg' => 'ed25519',
            'public_key' => bin2hex($pk),
            'secret_key' => bin2hex($sk),
            'created_ms' => (int)(microtime(true) * 1000),
            'note' => 'node-local operator key for work-chain block signatures',
        ];
        $this->store->put(self::KEY_FILE, $rec);
        return [
            'public_key' => $rec['public_key'],
            'secret_key' => $rec['secret_key'],
            'created_ms' => $rec['created_ms'],
        ];
    }

    public function publicKeyHex(): string
    {
        return $this->ensureKeypair()['public_key'];
    }

    /**
     * Sign a 32-byte or 64-char hex digest. Returns hex signature (128 chars).
     */
    public function signHash(string $hashHex): string
    {
        $msg = $this->hashToMessage($hashHex);
        $kp = $this->ensureKeypair();
        $sk = hex2bin($kp['secret_key']);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('invalid operator secret key');
        }
        $sig = sodium_crypto_sign_detached($msg, $sk);
        return bin2hex($sig);
    }

    public static function verifyHash(string $hashHex, string $signatureHex, string $publicKeyHex): bool
    {
        if (!extension_loaded('sodium')) {
            return false;
        }
        $msg = self::hashToMessageStatic($hashHex);
        $sig = hex2bin($signatureHex);
        $pk = hex2bin($publicKeyHex);
        if ($sig === false || $pk === false) {
            return false;
        }
        if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($sig, $msg, $pk);
    }

    private function hashToMessage(string $hashHex): string
    {
        return self::hashToMessageStatic($hashHex);
    }

    private static function hashToMessageStatic(string $hashHex): string
    {
        $hashHex = strtolower(trim($hashHex));
        if (preg_match('/^[a-f0-9]{64}$/', $hashHex)) {
            $bin = hex2bin($hashHex);
            return $bin !== false ? $bin : $hashHex;
        }
        return $hashHex;
    }
}
