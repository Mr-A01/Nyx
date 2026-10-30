<?php
/**
 * NYX Update Socket Channel (SSE) — update events only.
 * Mount: /nyx/update_channel.php or rewrite to /api/v3/updates/stream
 */
declare(strict_types=1);
$dataDir = dirname(__DIR__) . '/storage/data';
if (!is_dir($dataDir)) {
    $dataDir = __DIR__ . '/../data';
}
@ini_set('zlib.output_compression', '0');
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
$notify = $dataDir . '/.update_notify';
$last = 0;
$start = time();
echo "event: hello\ndata: {\"channel\":\"nyx-updates\"}\n\n";
@flush();
while (time() - $start < 55) {
    $ts = is_file($notify) ? (int) @file_get_contents($notify) : 0;
    if ($ts > $last) {
        $last = $ts;
        $manFile = $dataDir . '/.update_manifest.json';
        $man = is_file($manFile) ? json_decode((string) file_get_contents($manFile), true) : [];
        echo "event: update\ndata: " . json_encode([
            'version' => $man['version'] ?? null,
            'target' => $man['target'] ?? null,
            'ts_ms' => $ts,
        ]) . "\n\n";
        @flush();
    } else {
        echo ": ping\n\n";
        @flush();
    }
    sleep(2);
}
echo "event: bye\ndata: {}\n\n";
