<?php
declare(strict_types=1);

namespace Nyx\Core\Security\Authentication;

final class PeerAuthenticator
{
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
}
