<?php
declare(strict_types=1);

namespace Nyx\Core\Health;

final class SystemHealth
{
    public function __construct(private string $basePath, private string $dataDir) {}

    public function report(): array
    {
        $storageOk = is_dir($this->dataDir) && is_writable($this->dataDir);
        $legacy = is_file($this->basePath . '/legacy/relay.php');
        return [
            'service' => 'nyx-messenger-pro',
            'version' => defined('NYX_VERSION') ? NYX_VERSION : '1.40.0',
            'protocol' => defined('NYX_PROTOCOL') ? NYX_PROTOCOL : 3,
            'status' => ($storageOk && $legacy) ? 'ok' : 'degraded',
            'checks' => [
                'php' => PHP_VERSION,
                'sodium' => extension_loaded('sodium'),
                'sqlite' => extension_loaded('pdo_sqlite') || extension_loaded('sqlite3'),
                'curl' => extension_loaded('curl'),
                'mbstring' => extension_loaded('mbstring'),
                'storage_writable' => $storageOk,
                'legacy_relay' => $legacy,
                'mesh_engine' => is_file($this->basePath . '/legacy/mesh_engine.php'),
                'update_engine' => is_file($this->basePath . '/legacy/update_engine.php'),
                'identity_api' => is_file($this->basePath . '/legacy/identity_api.php'),
                'client' => is_file($this->basePath . '/public/client.html'),
            ],
            'time_ms' => (int) round(microtime(true) * 1000),
        ];
    }
}
