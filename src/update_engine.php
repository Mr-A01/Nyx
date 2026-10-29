<?php
// NYX Secure Update Engine 1.31 — Ed25519 signed manifests only
if (!defined('DATA_DIR')) return;
if (!defined('NYX_VERSION')) define('NYX_VERSION', '0');

function upd_trust_load(): array {
    $t = function_exists('store_get') ? store_get(DATA_DIR . '/.update_trust.json') : null;
    if (!is_array($t)) $t = ['keys' => [], 'min_version' => '1.0.0'];
    if (!isset($t['keys']) || !is_array($t['keys'])) $t['keys'] = [];
    return $t;
}

function upd_canon(string $version, string $sha256, int $ts): string {
    return 'nyx-update-v1|' . $version . '|' . $sha256 . '|' . $ts;
}

function upd_verify(string $version, string $sha256, int $ts, string $sigB64, string $kid): bool {
    $trust = upd_trust_load();
    $pkB64 = null;
    foreach ($trust['keys'] as $k) {
        if (($k['kid'] ?? '') === $kid) { $pkB64 = $k['pubkey_b64'] ?? null; break; }
    }
    if (!$pkB64) return false;
    $msg = upd_canon($version, $sha256, $ts);
    $sig = base64_decode($sigB64, true);
    $pk = base64_decode($pkB64, true);
    if ($sig === false || $pk === false || strlen($pk) !== 32 || strlen($sig) !== 64) return false;
    try {
        return sodium_crypto_sign_verify_detached($sig, $msg, $pk);
    } catch (Throwable $e) {
        return false;
    }
}

function upd_norm_ver(string $v): string {
    return preg_replace('/-.*$/', '', $v) ?: '0';
}

function upd_store_manifest(array $man): void {
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0750, true);
    if (function_exists('store_put')) store_put(DATA_DIR . '/.update_manifest.json', $man);
    else file_put_contents(DATA_DIR . '/.update_manifest.json', json_encode($man));
}

function upd_publish(string $bin, string $version, string $sigB64, string $kid, string $note = '', ?int $tsMs = null): array {
    if (strlen($bin) < 1000) return ['ok' => false, 'error' => 'payload too small'];
    if (!str_contains($bin, 'nyx-relay') && !str_contains($bin, 'NYX')) return ['ok' => false, 'error' => 'not a nyx payload'];
    $sha = hash('sha256', $bin);
    $ts = $tsMs !== null ? $tsMs : (function_exists('now_ms') ? now_ms() : (int)(microtime(true) * 1000));
    if (!upd_verify($version, $sha, $ts, $sigB64, $kid)) {
        return ['ok' => false, 'error' => 'signature verification failed'];
    }
    $dir = DATA_DIR . '/updates';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $path = $dir . '/' . preg_replace('/[^a-zA-Z0-9._\-]/', '', $version) . '.bin';
    if (file_put_contents($path, $bin, LOCK_EX) === false) return ['ok' => false, 'error' => 'write payload failed'];
    $man = [
        'version' => $version,
        'content_sha256' => $sha,
        'ts_ms' => $ts,
        'kid' => $kid,
        'signature_b64' => $sigB64,
        'note' => substr($note, 0, 200),
        'bytes' => strlen($bin),
        'publisher' => strtolower((string)($_SERVER['HTTP_HOST'] ?? 'local')),
    ];
    upd_store_manifest($man);
    // version lock
    @file_put_contents(DATA_DIR . '/.nyx_version_lock', $version);
    return ['ok' => true, 'manifest' => $man];
}

function upd_apply_bin(string $bin, array $man, bool $force = false): array {
    $version = (string)($man['version'] ?? '');
    $sha = (string)($man['content_sha256'] ?? '');
    $ts = (int)($man['ts_ms'] ?? 0);
    $sig = (string)($man['signature_b64'] ?? '');
    $kid = (string)($man['kid'] ?? '');
    if ($version === '' || $sha === '' || $sig === '' || $kid === '') return ['ok' => false, 'error' => 'incomplete manifest'];
    if (hash('sha256', $bin) !== $sha) return ['ok' => false, 'error' => 'sha256 mismatch'];
    if (!upd_verify($version, $sha, $ts, $sig, $kid)) return ['ok' => false, 'error' => 'bad signature'];
    $cur = defined('NYX_VERSION') ? NYX_VERSION : '0';
    if (!$force && version_compare(upd_norm_ver($version), upd_norm_ver($cur), '<=')) {
        return ['ok' => false, 'error' => "no upgrade: $version <= $cur"];
    }
    $trust = upd_trust_load();
    $min = (string)($trust['min_version'] ?? '1.0.0');
    if (!$force && version_compare(upd_norm_ver($version), upd_norm_ver($min), '<')) {
        return ['ok' => false, 'error' => "below min_version $min"];
    }
    // payload must declare matching version
    if (preg_match("/NYX_VERSION\s*=\s*'([^']+)'/", $bin, $m)) {
        if ($m[1] !== $version && !$force) return ['ok' => false, 'error' => 'embedded version mismatch'];
    }
    $target = __DIR__ . '/index.php';
    if (!is_file($target)) return ['ok' => false, 'error' => 'index.php missing'];
    $bak = __DIR__ . '/index.php.bak.' . date('YmdHis');
    if (!@copy($target, $bak)) return ['ok' => false, 'error' => 'backup failed'];
    if (file_put_contents($target, $bin, LOCK_EX) === false) {
        @copy($bak, $target);
        return ['ok' => false, 'error' => 'write failed, restored'];
    }
    // verify written
    $written = @file_get_contents($target);
    if ($written === false || hash('sha256', $written) !== $sha) {
        @copy($bak, $target);
        return ['ok' => false, 'error' => 'post-write verify failed, restored'];
    }
    @file_put_contents(DATA_DIR . '/.nyx_version_lock', $version);
    upd_store_manifest($man);
    return ['ok' => true, 'version' => $version, 'backup' => basename($bak), 'sha256' => $sha];
}

function upd_fetch_peer_manifest(string $peerBase): ?array {
    $url = rtrim($peerBase, '/') . '/api/v3/ops/update-check';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: NYX-Update/1.31']]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http < 200 || $http >= 300 || !$body) return null;
    $j = json_decode($body, true);
    $d = is_array($j) ? ($j['data'] ?? $j) : null;
    return is_array($d) ? $d : null;
}

function upd_fetch_peer_payload(string $peerBase, string $version, string $expectSha): ?string {
    // try JSON base64 first (WAF-friendlier), then raw
    $url = rtrim($peerBase, '/') . '/api/v3/ops/update-payload-b64?version=' . rawurlencode($version);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: NYX-Update/1.31']]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http >= 200 && $http < 300 && $body) {
        $j = json_decode($body, true);
        $d = is_array($j) ? ($j['data'] ?? $j) : null;
        if (is_array($d) && !empty($d['content_base64'])) {
            $bin = base64_decode($d['content_base64'], true);
            if ($bin !== false && hash('sha256', $bin) === $expectSha) return $bin;
        }
    }
    $url = rtrim($peerBase, '/') . '/api/v3/ops/update-payload?version=' . rawurlencode($version);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_HTTPHEADER => ['Accept: application/octet-stream', 'User-Agent: NYX-Update/1.31']]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http < 200 || $http >= 300 || $body === false || $body === '') return null;
    if (hash('sha256', $body) !== $expectSha) return null;
    return $body;
}

function upd_auto_from_peers(): array {
    $cur = defined('NYX_VERSION') ? NYX_VERSION : '0';
    $log = [];
    $peers = [];
    if (function_exists('mesh_load_state')) {
        $st = mesh_load_state();
        foreach (($st['friends'] ?? []) as $f) {
            if (($f['status'] ?? '') === 'active' && !empty($f['base_url'])) $peers[] = $f['base_url'];
        }
    }
    if (!$peers && function_exists('store_get')) {
        $idx = store_get(DATA_DIR . '/peers/_index.json') ?? ['ids' => []];
        foreach (($idx['ids'] ?? []) as $pid) {
            $p = store_get(DATA_DIR . '/peers/' . $pid . '.json');
            if (!empty($p['base_url'])) $peers[] = $p['base_url'];
        }
    }
    // also MESH_SEEDS
    if (defined('MESH_SEEDS') && is_array(MESH_SEEDS)) {
        foreach (MESH_SEEDS as $s) $peers[] = $s;
    }
    $peers = array_values(array_unique($peers));
    $best = null;
    foreach ($peers as $pb) {
        $chk = upd_fetch_peer_manifest($pb);
        if (!$chk || empty($chk['manifest'])) continue;
        $man = $chk['manifest'];
        $ver = (string)($man['version'] ?? '');
        if ($ver === '' || version_compare(upd_norm_ver($ver), upd_norm_ver($cur), '<=')) continue;
        if (empty($man['has_signature']) && empty($man['signature_b64'])) continue;
        // need full manifest with signature — update-check may strip sig; fetch full from peer file endpoint
        $fullUrl = rtrim($pb, '/') . '/api/v3/ops/update-manifest';
        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: NYX-Update/1.31']]);
        $body = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($http >= 200 && $http < 300 && $body) {
            $j = json_decode($body, true);
            $full = is_array($j) ? ($j['data'] ?? $j) : null;
            if (is_array($full) && !empty($full['signature_b64'])) $man = $full;
        }
        if (empty($man['signature_b64']) || empty($man['content_sha256']) || empty($man['kid'])) continue;
        if (!upd_verify($ver, $man['content_sha256'], (int)$man['ts_ms'], $man['signature_b64'], $man['kid'])) {
            $log[] = ['peer' => $pb, 'event' => 'reject_bad_sig', 'version' => $ver];
            continue;
        }
        if ($best === null || version_compare(upd_norm_ver($ver), upd_norm_ver($best['man']['version']), '>')) {
            $best = ['peer' => $pb, 'man' => $man];
        }
    }
    if (!$best) return ['ok' => true, 'updated' => false, 'current' => $cur, 'log' => $log];
    $man = $best['man'];
    $bin = upd_fetch_peer_payload($best['peer'], $man['version'], $man['content_sha256']);
    if ($bin === null) {
        foreach ($peers as $alt) {
            if ($alt === $best['peer']) continue;
            $bin = upd_fetch_peer_payload($alt, $man['version'], $man['content_sha256']);
            if ($bin !== null) { $best['peer'] = $alt; break; }
        }
    }
    if ($bin === null) {
        $log[] = ['peer' => $best['peer'], 'event' => 'payload_fetch_failed', 'version' => $man['version']];
        return ['ok' => false, 'updated' => false, 'error' => 'payload fetch failed', 'log' => $log];
    }
    $res = upd_apply_bin($bin, $man, false);
    $res['source_peer'] = $best['peer'];
    $res['log'] = $log;
    return $res;
}


function upd_dispatch(string $method, string $path): bool {
    if ($method === 'GET' && $path === '/api/v3/ops/update-manifest') {
        $man = function_exists('store_get') ? store_get(DATA_DIR . '/.update_manifest.json') : null;
        if (!is_array($man)) { if (function_exists('json_err')) json_err('no manifest', 404); return true; }
        if (function_exists('json_out')) json_out($man);
        return true;
    }
    if ($method === 'GET' && $path === '/api/v3/ops/update-payload-b64') {
        $ver = preg_replace('/[^a-zA-Z0-9._\-]/', '', (string)($_GET['version'] ?? ''));
        if ($ver === '') { json_err('version required'); return true; }
        $man = store_get(DATA_DIR . '/.update_manifest.json');
        if (!is_array($man) || ($man['version'] ?? '') !== $ver) { json_err('manifest mismatch', 404); return true; }
        $pathBin = DATA_DIR . '/updates/' . $ver . '.bin';
        if (!is_file($pathBin)) { json_err('payload missing', 404); return true; }
        $bin = file_get_contents($pathBin);
        if ($bin === false || hash('sha256', $bin) !== ($man['content_sha256'] ?? '')) { json_err('payload corrupt', 500); return true; }
        json_out(['version' => $ver, 'sha256' => $man['content_sha256'], 'content_base64' => base64_encode($bin)]);
        return true;
    }
    if ($method === 'GET' && $path === '/api/v3/ops/update-payload') {
        $ver = preg_replace('/[^a-zA-Z0-9._\-]/', '', (string)($_GET['version'] ?? ''));
        if ($ver === '') { json_err('version required'); return true; }
        $man = store_get(DATA_DIR . '/.update_manifest.json');
        if (!is_array($man) || ($man['version'] ?? '') !== $ver) { json_err('manifest mismatch', 404); return true; }
        $pathBin = DATA_DIR . '/updates/' . $ver . '.bin';
        if (!is_file($pathBin)) { json_err('payload missing', 404); return true; }
        $bin = file_get_contents($pathBin);
        if ($bin === false || hash('sha256', $bin) !== ($man['content_sha256'] ?? '')) { json_err('payload corrupt', 500); return true; }
        header('Content-Type: application/octet-stream');
        header('X-Nyx-Version: ' . $ver);
        header('X-Nyx-SHA256: ' . $man['content_sha256']);
        http_response_code(200);
        echo $bin;
        exit;
    }
    if ($method === 'POST' && $path === '/api/v3/admin/update-publish') {
        $b = body();
        require_admin($b);
        $bin = base64_decode((string)($b['content_base64'] ?? ''), true);
        if ($bin === false) json_err('content_base64 required');
        $res = upd_publish($bin, (string)($b['version'] ?? ''), (string)($b['signature_b64'] ?? ''), (string)($b['kid'] ?? ''), (string)($b['note'] ?? ''), isset($b['ts_ms']) ? (int)$b['ts_ms'] : null);
        if (empty($res['ok'])) json_err($res['error'] ?? 'publish failed', 400);
        if (function_exists('admin_audit')) admin_audit('update-publish', 'admin', ['version' => $b['version'] ?? '']);
        json_out($res);
        return true;
    }
    if ($method === 'POST' && $path === '/api/v3/admin/update-auto') {
        $b = body();
        require_admin($b);
        json_out(upd_auto_from_peers());
        return true;
    }

    if ($method === 'POST' && $path === '/api/v3/admin/deploy-chunk') {
        $b = body();
        require_admin($b);
        $name = basename((string)($b['name'] ?? 'client.html'));
        $allow = ['client.html','messenger.html','status.html','identity.html','landing.html','index.php'];
        if (!in_array($name, $allow, true)) json_err('name not allowed');
        $idx = (int)($b['index'] ?? -1);
        $total = (int)($b['total'] ?? 0);
        $chunk = base64_decode((string)($b['chunk_b64'] ?? ''), true);
        if ($idx < 0 || $total < 1 || $chunk === false) json_err('bad chunk');
        $dir = DATA_DIR . '/_chunks/' . preg_replace('/[^a-z0-9._\-]/i', '', $name);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        file_put_contents($dir . '/' . $idx . '.part', $chunk, LOCK_EX);
        $have = 0;
        for ($i = 0; $i < $total; $i++) if (is_file($dir . '/' . $i . '.part')) $have++;
        if ($have < $total) {
            json_out(['status' => 'partial', 'have' => $have, 'total' => $total]);
            return true;
        }
        $bin = '';
        for ($i = 0; $i < $total; $i++) {
            $p = file_get_contents($dir . '/' . $i . '.part');
            if ($p === false) json_err('missing part ' . $i, 500);
            $bin .= $p;
        }
        $target = __DIR__ . '/' . $name;
        if (is_file($target)) @copy($target, $target . '.bak.' . date('YmdHis'));
        if (file_put_contents($target, $bin, LOCK_EX) === false) json_err('write failed', 500);
        // cleanup
        for ($i = 0; $i < $total; $i++) @unlink($dir . '/' . $i . '.part');
        @rmdir($dir);
        if (function_exists('admin_audit')) admin_audit('deploy-chunk', 'admin', ['name' => $name, 'bytes' => strlen($bin)]);
        json_out(['status' => 'deployed', 'name' => $name, 'bytes' => strlen($bin)]);
        return true;
    }

    return false;
}
