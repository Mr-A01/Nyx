<?php
declare(strict_types=1);

namespace Nyx\Core\Security\Authentication;

/**
 * HMAC peer auth for federation gossip + optional nonce replay protection.
 */
final class PeerAuthenticator
{
    private const NONCE_TTL_MS = 600_000; // 10 minutes

    public function __construct(private string $dataDir) {}

    public function federationSecret(): string
    {
        $f = rtrim($this->dataDir, '/') . '/.federation_secret';
        if (is_file($f)) {
            $s = trim((string) file_get_contents($f));
            if ($s !== '') {
                return $s;
            }
        }
        $s = bin2hex(random_bytes(32));
        @file_put_contents($f, $s, LOCK_EX);
        @chmod($f, 0640);
        return $s;
    }

    public function sign(string $body): string
    {
        return hash_hmac('sha256', $body, $this->federationSecret());
    }

    public function verify(string $body, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }
        $expected = $this->sign($body);
        return hash_equals($expected, $signature);
    }

    /**
     * Verify HMAC and consume a one-time nonce (replay protection).
     * Without nonce, behaves like verify() only (backward compatible).
     */
    public function verifyAndConsume(string $body, ?string $signature, ?string $nonce = null): bool
    {
        if (!$this->verify($body, $signature)) {
            return false;
        }
        if ($nonce === null || $nonce === '') {
            return true;
        }
        $nonce = preg_replace('/[^a-zA-Z0-9_\-]/', '', $nonce) ?? '';
        if (strlen($nonce) < 8 || strlen($nonce) > 128) {
            return false;
        }
        $dir = rtrim($this->dataDir, '/') . '/federation/nonces';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $path = $dir . '/' . hash('sha256', $nonce) . '.json';
        if (is_file($path)) {
            return false; // replay
        }
        $now = (int) floor(microtime(true) * 1000);
        @file_put_contents($path, json_encode([
            'nonce' => $nonce,
            'used_ms' => $now,
            'expires_ms' => $now + self::NONCE_TTL_MS,
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
        $this->gcNonces($dir, $now);
        return true;
    }

    private function gcNonces(string $dir, int $now): void
    {
        // Opportunistic light GC
        if (random_int(1, 20) !== 1) {
            return;
        }
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            $rec = json_decode((string) @file_get_contents($f), true);
            if (!is_array($rec) || $now > (int)($rec['expires_ms'] ?? 0)) {
                @unlink($f);
            }
        }
    }
}
