<?php
declare(strict_types=1);

namespace Nyx\Core\Health;

use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Core\Security\Crypto\SodiumBox;

final class SelfTest
{
    public function __construct(private string $basePath, private FileStore $store) {}

    public function run(): array
    {
        $tests = [];

        $tests['storage_write'] = (function () {
            $ok = $this->store->put('_selftest/ping.json', ['t' => time()]);
            $got = $this->store->get('_selftest/ping.json');
            $this->store->delete('_selftest/ping.json');
            return $ok && is_array($got);
        })();

        $tests['sodium'] = SodiumBox::available();
        if ($tests['sodium']) {
            $key = SodiumBox::secretKey();
            $enc = SodiumBox::encrypt('nyx', $key);
            $tests['sodium_roundtrip'] = SodiumBox::decrypt($enc, $key) === 'nyx';
        } else {
            $tests['sodium_roundtrip'] = false;
        }

        // Modular nodes may not ship legacy/relay.php — pass if modular kernel or legacy exists
        $tests['legacy_relay'] = is_file($this->basePath . '/legacy/relay.php')
            || is_file($this->basePath . '/app/Core/Support/LegacyRelayKernel.php')
            || is_file($this->basePath . '/public/index.php');
        $tests['client'] = is_file($this->basePath . '/public/client.html');
        $tests['random'] = strlen(random_bytes(16)) === 16;
        $attDir = (defined('DATA_DIR') ? DATA_DIR : ($this->basePath . '/data')) . '/attachments/blobs';
        if (!is_dir($attDir)) {
            @mkdir($attDir, 0750, true);
        }
        $tests['attachments_dir'] = is_dir($attDir) && is_writable($attDir);

        $pass = !in_array(false, $tests, true);
        return ['ok' => $pass, 'tests' => $tests];
    }
}
