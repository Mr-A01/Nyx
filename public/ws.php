<?php
declare(strict_types=1);
/**
 * NYX WebSocket gateway for live messages.
 * Auth: ?token=SESSION  Subscribe: ?conv_id=ID&since_ms=0
 * Frames (server→client JSON text): ready | auth_ok | messages | ping | bye | error
 */
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
@set_time_limit(0);
@ignore_user_abort(true);
while (ob_get_level() > 0) { @ob_end_clean(); }

define('NYX_BASE', dirname(__DIR__));
define('NYX_DATA_DIR', is_dir(NYX_BASE . '/data') ? NYX_BASE . '/data' : NYX_BASE . '/storage/data');
define('DATA_DIR', NYX_DATA_DIR);
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Nyx\\')) return;
    $file = NYX_BASE . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});

$upgrade = strtolower((string)($_SERVER['HTTP_UPGRADE'] ?? ''));
$key = (string)($_SERVER['HTTP_SEC_WEBSOCKET_KEY'] ?? '');

function nyx_ws_encode(string $payload): string {
    $len = strlen($payload);
    $frame = chr(0x81);
    if ($len < 126) {
        $frame .= chr($len);
    } elseif ($len < 65536) {
        $frame .= chr(126) . pack('n', $len);
    } else {
        $frame .= chr(127) . pack('J', $len);
    }
    return $frame . $payload;
}

function nyx_ws_send(string $payload): void {
    echo nyx_ws_encode($payload);
    if (function_exists('flush')) { flush(); }
}

if ($upgrade !== 'websocket' || $key === '') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(426);
    echo json_encode([
        'ok' => false,
        'error' => ['message' => 'websocket_upgrade_required'],
        'path' => '/nyx/ws',
        'usage' => 'wss://host/nyx/ws?token=SESSION&conv_id=ID&since_ms=0',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
header('HTTP/1.1 101 Switching Protocols');
header('Upgrade: websocket');
header('Connection: Upgrade');
header('Sec-WebSocket-Accept: ' . $accept);
header('Sec-WebSocket-Version: 13');
header('X-Accel-Buffering: no');
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
flush();

$token = (string)($_GET['token'] ?? '');
$convId = (string)($_GET['conv_id'] ?? '');
$sinceMs = (int)($_GET['since_ms'] ?? 0);
$authUser = '';

try {
    $store = new \Nyx\Shared\Infrastructure\Storage\FileStore(DATA_DIR);
    if ($token !== '') {
        $sess = $store->get('sessions/' . $token . '.json');
        if (!is_array($sess)) {
            $sess = $store->get('sessions/' . hash('sha256', $token) . '.json');
        }
        if (is_array($sess)) {
            $authUser = (string)($sess['user_id'] ?? $sess['username'] ?? '');
        }
    }
} catch (Throwable $e) {}

nyx_ws_send(json_encode(['type' => 'ready', 'service' => 'nyx-ws', 'version' => '1.45.0-ws'], JSON_UNESCAPED_SLASHES));
if ($authUser !== '') {
    nyx_ws_send(json_encode(['type' => 'auth_ok', 'user_id' => $authUser], JSON_UNESCAPED_SLASHES));
} else {
    nyx_ws_send(json_encode(['type' => 'error', 'message' => 'unauthorized'], JSON_UNESCAPED_SLASHES));
}

if ($convId !== '' && $authUser !== '') {
    $guard = \Nyx\Shared\Infrastructure\Messaging\AccessGuard::make();
    if (!$guard->canRead($convId, $authUser)) {
        nyx_ws_send(json_encode(['type' => 'error', 'message' => 'forbidden', 'conv_id' => $convId], JSON_UNESCAPED_SLASHES));
        $convId = '';
    } else {
        nyx_ws_send(json_encode(['type' => 'subscribed', 'conv_id' => $convId], JSON_UNESCAPED_SLASHES));
    }
}

$deadline = time() + 50;
while ($convId !== '' && time() < $deadline && !connection_aborted()) {
    try {
        $tip = \Nyx\Shared\Infrastructure\Messaging\MessageIntegrity::make()->tip($convId);
        $tipMs = (int)($tip['updated_ms'] ?? 0);
        if ($tipMs > $sinceMs) {
            $store = new \Nyx\Shared\Infrastructure\Storage\FileStore(DATA_DIR);
            $idx = $store->get("messages/{$convId}/_index.json") ?? ['ids' => []];
            $ids = is_array($idx['ids'] ?? null) ? $idx['ids'] : [];
            $batch = [];
            $maxTs = $sinceMs;
            foreach ($ids as $mid) {
                $mm = $store->get("messages/{$convId}/{$mid}.json");
                if (!is_array($mm) || !empty($mm['deleted'])) continue;
                $ts = (int)($mm['created_ms'] ?? 0);
                if ($ts <= $sinceMs) continue;
                if (!empty($mm['ciphertext'])) {
                    $mm['body'] = '';
                }
                $batch[] = \Nyx\Shared\Infrastructure\Messaging\MessageIntegrity::compact($mm);
                if ($ts > $maxTs) {
                    $maxTs = $ts;
                }
            }
            if ($batch) {
                $sinceMs = $maxTs;
                nyx_ws_send(json_encode([
                    'type' => 'messages',
                    'conv_id' => $convId,
                    'messages' => $batch,
                    'newest_ms' => $maxTs,
                    'chain_tip' => $tip['tip_hash'] ?? '',
                ], JSON_UNESCAPED_SLASHES));
            } else {
                $sinceMs = max($sinceMs, $tipMs);
            }
        }
    } catch (Throwable $e) {
        nyx_ws_send(json_encode(['type' => 'error', 'message' => 'pump_error'], JSON_UNESCAPED_SLASHES));
    }
    nyx_ws_send(json_encode(['type' => 'ping', 't' => (int) floor(microtime(true) * 1000)], JSON_UNESCAPED_SLASHES));
    usleep(400000);
}

nyx_ws_send(json_encode(['type' => 'bye', 'reason' => 'cycle'], JSON_UNESCAPED_SLASHES));
exit;
