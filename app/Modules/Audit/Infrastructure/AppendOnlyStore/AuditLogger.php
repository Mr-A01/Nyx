<?php
declare(strict_types=1);

namespace Nyx\Modules\Audit\Infrastructure\AppendOnlyStore;

final class AuditLogger
{
    public function __construct(private string $logDir) {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0750, true);
        }
    }

    public function log(string $action, array $context = []): void
    {
        $line = json_encode([
            'ts_ms' => (int) round(microtime(true) * 1000),
            'action' => $action,
            'context' => $context,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $file = rtrim($this->logDir, '/') . '/audit-' . gmdate('Y-m-d') . '.log';
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
