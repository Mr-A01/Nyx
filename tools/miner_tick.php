#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Cron-friendly miner tick against a NYX node.
 *
 * Usage:
 *   php tools/miner_tick.php https://acebet90.co/nyx BEARER_TOKEN
 *   NYX_BASE=https://acebet90.co/nyx NYX_TOKEN=... php tools/miner_tick.php
 *
 * Recommended crontab (every 15 min):
 *   */15 * * * * php /path/to/nyx/tools/miner_tick.php https://YOUR/nyx "$TOKEN" >> /var/log/nyx-miner.log 2>&1
 */
$base = rtrim($argv[1] ?? (getenv('NYX_BASE') ?: ''), '/');
$token = $argv[2] ?? (getenv('NYX_TOKEN') ?: '');
if ($base === '' || $token === '') {
    fwrite(STDERR, "Usage: php tools/miner_tick.php <baseUrl> <bearerToken>\n");
    exit(2);
}
$url = $base . '/api/v3/mining/tick';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ],
    CURLOPT_POSTFIELDS => '{}',
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
echo gmdate('c') . " HTTP {$code} {$body}\n";
exit(($code >= 200 && $code < 300) ? 0 : 1);
