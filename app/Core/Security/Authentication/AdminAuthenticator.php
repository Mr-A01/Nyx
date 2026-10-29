<?php
declare(strict_types=1);

namespace Nyx\Core\Security\Authentication;

use Nyx\Core\Support\Env;

final class AdminAuthenticator
{
    public function __construct(private string $dataDir) {}

    public function token(): string
    {
        $env = (string) Env::get('ADMIN_TOKEN', '');
        if ($env !== '') {
            return $env;
        }
        $f = rtrim($this->dataDir, '/') . '/.admin_token';
        if (is_file($f)) {
            return trim((string) file_get_contents($f));
        }
        // bootstrap once
        $tok = bin2hex(random_bytes(24));
        $dir = dirname($f);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($f, $tok, LOCK_EX);
        @chmod($f, 0640);
        return $tok;
    }

    public function check(?string $provided): bool
    {
        if ($provided === null || $provided === '') {
            return false;
        }
        return hash_equals($this->token(), $provided);
    }

    public function rotate(): string
    {
        $tok = bin2hex(random_bytes(24));
        $f = rtrim($this->dataDir, '/') . '/.admin_token';
        @file_put_contents($f, $tok, LOCK_EX);
        @chmod($f, 0640);
        return $tok;
    }
}
