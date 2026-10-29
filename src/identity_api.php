<?php
// NYX Identity API routes — included from index when path starts with /api/v1/
if (!defined('DATA_DIR')) { http_response_code(500); exit; }

function id_session_from_bearer(): ?array {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $tok = '';
    if (preg_match('/^Bearer\s+(.+)$/i', $hdr, $m)) $tok = trim($m[1]);
    if ($tok === '') { $b = body(); $tok = (string)($b['session'] ?? $b['token'] ?? ''); }
    if ($tok === '') return null;
    $s = store_get(DATA_DIR . '/identity/sessions/' . hash('sha256', $tok) . '.json');
    return is_array($s) ? $s : null;
}
function id_provision_relay_user(string $username, string $display = ''): array {
    $username = strtolower($username);
    $uf = DATA_DIR . '/users/' . hash('sha256', $username) . '.json';
    $existing = store_get($uf);
    if (is_array($existing) && !empty($existing['public_key'])) return $existing;
    if (!function_exists('sodium_crypto_sign_keypair')) {
        $pk = base64_encode(hash('sha256', 'nyx-pk|' . $username, true));
        $rec = ['identity_id' => $username, 'public_key' => $pk, 'display_name' => $display ?: $username, 'created_at_ms' => now_ms(), 'from_identity_gateway' => true];
        store_put($uf, $rec); return $rec;
    }
    $kp = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($kp);
    $pk = sodium_crypto_sign_publickey($kp);
    $rec = ['identity_id' => $username, 'public_key' => base64_encode($pk), 'display_name' => $display !== '' ? $display : $username, 'created_at_ms' => now_ms(), 'updated_at_ms' => now_ms(), 'from_identity_gateway' => true];
    store_put($uf, $rec);
    if (!is_dir(DATA_DIR . '/identity/signkeys')) @mkdir(DATA_DIR . '/identity/signkeys', 0750, true);
    store_put(DATA_DIR . '/identity/signkeys/' . hash('sha256', $username) . '.json', ['username' => $username, 'sk_b64' => base64_encode($sk), 'pk_b64' => base64_encode($pk), 'created_ms' => now_ms()]);
    return $rec;
}
function id_sign_payload(string $username, string $payload): ?string {
    $kf = DATA_DIR . '/identity/signkeys/' . hash('sha256', strtolower($username)) . '.json';
    $k = store_get($kf);
    if (!$k || empty($k['sk_b64'])) { id_provision_relay_user($username); $k = store_get($kf); }
    if (!$k || empty($k['sk_b64'])) return null;
    $sk = base64_decode($k['sk_b64'], true);
    if ($sk === false || strlen($sk) < 64) return null;
    try { return base64_encode(sodium_crypto_sign_detached($payload, $sk)); } catch (Throwable $e) { return null; }
}
function id_user_path(string $u): string { return DATA_DIR . '/identity/users/' . hash('sha256', strtolower($u)) . '.json'; }
function id_load_user(string $u): ?array { return store_get(id_user_path($u)); }
function id_save_user(string $u, array $user): void { store_put(id_user_path($u), $user); }
function id_event(string $u, string $type, string $detail): void {
    $dir = DATA_DIR . '/identity/events'; if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents($dir . '/' . date('Ymd') . '.jsonl', json_encode(['ts' => now_ms(), 'u' => $u, 'type' => $type, 'detail' => $detail], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
function id_mask_email(string $e): string {
    $p = explode('@', $e, 2); if (count($p) < 2) return '•••';
    return ($p[0][0] ?? '•') . str_repeat('•', max(1, strlen($p[0]) - 1)) . '@' . $p[1];
}
function id_session_path(string $id): string { return DATA_DIR . '/identity/sessions/' . hash('sha256', $id) . '.json'; }

/** Deterministic HMAC key — validates sessions without shared filesystem (multi-node safe). */
function id_session_secret(): string {
    static $s = null;
    if ($s !== null) return $s;
    $f = DATA_DIR . '/.session_hmac_key';
    if (is_file($f)) {
        $raw = trim((string)@file_get_contents($f));
        if ($raw !== '') { $s = hash('sha256', $raw, true); return $s; }
    }
    // Fallback: same on every identical deploy of this codebase
    $s = hash('sha256', 'NYX_SESSION_HMAC_V1|testnet|amnbet-mesh', true);
    return $s;
}

function id_session_verify_hmac(string $tok): ?array {
    if (!str_starts_with($tok, 'nxs1.')) return null;
    $raw = base64_decode(strtr(substr($tok, 5), '-_', '+/'), true);
    if ($raw === false) return null;
    $parts = explode('|', $raw);
    if (count($parts) !== 4) return null;
    [$u, $exp, $nonce, $sig] = $parts;
    $u = strtolower(trim($u));
    if ($u === '' || !ctype_alnum(str_replace(['_', '-'], '', $u))) return null;
    if (time() > (int)$exp) return null;
    $payload = $u . '|' . $exp . '|' . $nonce;
    if (!hash_equals(hash_hmac('sha256', $payload, id_session_secret()), $sig)) return null;
    return [
        'id' => $tok,
        'username' => $u,
        'created_ms' => ((int)$exp - 86400 * 30) * 1000,
        'last_seen' => now_ms(),
        'hmac' => true,
    ];
}

function id_verify_password(array $user, string $pw): bool {
    if (empty($user['pwhash']) || $pw === '') return false;
    $h = (string)$user['pwhash'];
    $ok = false;
    if (str_starts_with($h, '$argon2') && function_exists('sodium_crypto_pwhash_str_verify')) {
        try { $ok = sodium_crypto_pwhash_str_verify($h, $pw); } catch (Throwable $e) { $ok = false; }
    }
    if (!$ok && str_starts_with($h, '$argon2') && function_exists('password_verify')) {
        $ok = password_verify($pw, $h);
    }
    if (!$ok && str_starts_with($h, '$pbkdf2-sha256$')) {
        $parts = explode('$', $h);
        if (count($parts) >= 5) {
            $iters = 300000;
            if (preg_match('/i=(\d+)/', $parts[2], $mm)) $iters = (int)$mm[1];
            $salt = base64_decode(strtr($parts[3], '-_', '+/'));
            $expect = $parts[4];
            if ($salt !== false) {
                $calc = hash_pbkdf2('sha256', $pw, $salt, $iters, 32, true);
                $b64 = rtrim(strtr(base64_encode($calc), '+/', '-_'), '=');
                $ok = hash_equals($expect, $b64);
            }
        }
    }
    return $ok;
}
function id_issue_session(string $u, array $user): array {
    $u = strtolower(trim($u));
    $exp = time() + 86400 * 30;
    $nonce = bin2hex(random_bytes(8));
    $payload = $u . '|' . $exp . '|' . $nonce;
    $sig = hash_hmac('sha256', $payload, id_session_secret());
    $sid = 'nxs1.' . rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
    // Best-effort local index (optional on multi-node)
    try {
        if (!is_dir(DATA_DIR . '/identity/sessions')) @mkdir(DATA_DIR . '/identity/sessions', 0750, true);
        store_put(id_session_path($sid), [
            'id' => $sid, 'username' => $u, 'created_ms' => now_ms(), 'last_seen' => now_ms(),
            'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120), 'hmac' => true,
        ]);
    } catch (Throwable $e) {}
    id_event($u, 'session.created', 'Session established (hmac)');
    $relay = function_exists('id_provision_relay_user') ? id_provision_relay_user($u, (string)($user['display_name'] ?? '')) : ['identity_id' => $u, 'public_key' => null];
    return [
        'session' => $sid, 'token' => $sid, 'username' => $u,
        'displayName' => $user['display_name'] ?? '',
        'messaging' => [
            'identity_id' => $relay['identity_id'] ?? $u,
            'public_key' => $relay['public_key'] ?? null,
            'auth' => 'session',
        ],
    ];
}

function id_require_session(): array {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ''; $tok = '';
    if (preg_match('/^Bearer\s+(.+)$/i', $hdr, $m)) $tok = trim($m[1]);
    if ($tok === '') { $b = body(); $tok = (string)($b['session'] ?? $b['token'] ?? ''); }
    $tok = trim($tok);
    if ($tok === '') json_err('session required', 401);
    // 1) Local file (legacy 32-hex or cached hmac token)
    $s = store_get(id_session_path($tok));
    if (is_array($s) && !empty($s['username'])) {
        $s['last_seen'] = now_ms();
        try { store_put(id_session_path($tok), $s); } catch (Throwable $e) {}
        return $s;
    }
    // 2) Stateless HMAC token (multi-node safe)
    $hmac = id_session_verify_hmac($tok);
    if ($hmac) return $hmac;
    json_err('session invalid', 401);
}
function id_reserved(string $u): bool {
    return in_array($u, ['admin','root','system','nyx','support','security','api','mail','help','mod','official'], true);
}
function id_totp_ok(string $secret, string $code): bool {
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
    $bits = '';
    for ($i = 0; $i < strlen($secret); $i++) { $v = strpos($map, $secret[$i]); if ($v === false) continue; $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT); }
    $key = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) $key .= chr(bindec(substr($bits, $i, 8)));
    $time = time();
    for ($w = -1; $w <= 1; $w++) {
        $bin = pack('N*', 0, intdiv($time, 30) + $w);
        $hash = hash_hmac('sha1', $bin, $key, true);
        $off = ord($hash[19]) & 0x0f;
        $otp = ((ord($hash[$off]) & 0x7f) << 24) | ((ord($hash[$off + 1]) & 0xff) << 16) | ((ord($hash[$off + 2]) & 0xff) << 8) | (ord($hash[$off + 3]) & 0xff);
        if (hash_equals(str_pad((string)($otp % 1000000), 6, '0', STR_PAD_LEFT), $code)) return true;
    }
    return false;
}
function id_send_code(string $to, string $purpose, string $u): array {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    if (!is_dir(DATA_DIR . '/identity/email')) @mkdir(DATA_DIR . '/identity/email', 0750, true);
    store_put(DATA_DIR . '/identity/email/' . hash('sha256', $to) . '.json', [
        'hash' => hash('sha256', $code . '::' . $to), 'purpose' => $purpose, 'username' => $u,
        'expiry' => time() + 600, 'attempts' => 0, 'to' => $to,
    ]);
    @mail($to, $purpose === 'recovery' ? 'NYX — account recovery' : 'NYX — verify your email',
        "Your NYX code is: $code\nValid for 10 minutes.\n",
        'From: noreply@' . ($_SERVER['HTTP_HOST'] ?? 'nyx.local') . "\r\nContent-Type: text/plain; charset=UTF-8");
    $out = ['ok' => true, 'generic' => true, 'expires_min' => 10, 'maskedTo' => id_mask_email($to)];
    if (str_contains(defined('NYX_VERSION') ? NYX_VERSION : '', 'testnet')) $out['dev_code'] = $code;
    return $out;
}

if ($method === 'GET' && $path === '/api/v1/identity/username') {
    rate_check('id_user_' . client_ip(), 30, 60);
    $u = strtolower(trim((string)($_GET['u'] ?? '')));
    if (!preg_match('/^[a-z0-9_]{3,32}$/', $u) || id_reserved($u)) json_out(['status' => 'invalid']);
    json_out(['status' => id_load_user($u) ? 'taken' : 'available']);
}
if ($method === 'POST' && $path === '/api/v1/identity/register') {
    rate_check('id_reg_' . client_ip(), 8, 60);
    $b = body();
    $u = strtolower(trim((string)($b['username'] ?? '')));
    $pwhash = (string)($b['pwhash'] ?? $b['pwhashStr'] ?? $b['pwhash_verifier'] ?? '');
    $email = trim((string)($b['email'] ?? ''));
    $display = substr(trim((string)($b['displayName'] ?? $b['display_name'] ?? '')), 0, 64);
    $kid = substr((string)($b['kid'] ?? ''), 0, 64);
    $fp = substr((string)($b['fingerprint'] ?? ''), 0, 128);
    if (!preg_match('/^[a-z0-9_]{3,32}$/', $u) || id_reserved($u)) json_err('This username cannot be used.', 422);
    $pwPlain = (string)($b['password'] ?? '');
    if ($pwPlain !== '' && function_exists('sodium_crypto_pwhash_str')) {
        $pwhash = sodium_crypto_pwhash_str($pwPlain, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);
    } elseif ($pwPlain !== '' && defined('PASSWORD_ARGON2ID')) {
        $pwhash = password_hash($pwPlain, PASSWORD_ARGON2ID);
    }
    if ($pwhash === '' || !(str_starts_with($pwhash, '$argon2') || str_starts_with($pwhash, '$pbkdf2'))) json_err('Invalid password verifier', 422);
    if (id_load_user($u)) json_err('Username already taken.', 409);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Invalid email', 422);
    if (!is_dir(DATA_DIR . '/identity/users')) @mkdir(DATA_DIR . '/identity/users', 0750, true);
    $user = [
        'username' => $u, 'display_name' => $display, 'email' => $email !== '' ? $email : null,
        'email_verified' => false, 'must_verify_email' => $email !== '',
        'pwhash' => $pwhash, 'secondary_hash' => $b['secondaryHash'] ?? null,
        'totp_secret' => null, 'totp_enabled' => false, 'passkey' => $b['passkeyCred'] ?? null,
        'recovery_hashes' => [], 'fingerprint' => $fp, 'kid' => $kid, 'created_ms' => now_ms(),
    ];
    id_save_user($u, $user);
    id_event($u, 'account.created', 'Identity registered');
    $emailOut = null;
    if ($email !== '') { $emailOut = id_send_code(strtolower($email), 'verify', $u); id_event($u, 'email.code_sent', 'Verification code issued'); }
    $sessOut = id_issue_session($u, $user);
    json_out(array_merge(['id' => $kid ?: $u, 'username' => $u, 'email' => $emailOut], $sessOut));
}
if ($method === 'POST' && $path === '/api/v1/auth/login') {
    $b = body();
    $u = strtolower(trim((string)($b['username'] ?? '')));
    $pw = (string)($b['password'] ?? '');
    rate_check('id_login_' . client_ip() . '_' . substr(hash('sha256', $u), 0, 8), 5, 15);
    $user = id_load_user($u);
    $ok = false;
    if ($user && !empty($user['pwhash'])) {
        $h = $user['pwhash'];
        if (str_starts_with($h, '$argon2') && function_exists('sodium_crypto_pwhash_str_verify')) {
            try { $ok = sodium_crypto_pwhash_str_verify($h, $pw); } catch (Throwable $e) { $ok = false; }
        } elseif (str_starts_with($h, '$argon2') && function_exists('password_verify')) {
            $ok = password_verify($pw, $h);
        } elseif (str_starts_with($h, '$pbkdf2-sha256$')) {
            // Client-format PBKDF2 verifier (testnet / offline)
            $parts = explode('$', $h);
            if (count($parts) >= 5) {
                $iters = 300000;
                if (preg_match('/i=(\d+)/', $parts[2], $mm)) $iters = (int)$mm[1];
                $salt = base64_decode(strtr($parts[3], '-_', '+/'));
                $expect = $parts[4];
                if ($salt !== false) {
                    $calc = hash_pbkdf2('sha256', $pw, $salt, $iters, 32, true);
                    $b64 = rtrim(strtr(base64_encode($calc), '+/', '-_'), '=');
                    $ok = hash_equals($expect, $b64);
                }
            }
        }
    }
    if (!$ok) { id_event($u ?: 'unknown', 'auth.failed', 'Invalid credentials'); json_err('Invalid username or password.', 401); }
    if (!empty($user['email']) && empty($user['email_verified']) && !empty($user['must_verify_email'])) {
        json_out(['needEmailVerify' => true, 'username' => $u, 'maskedEmail' => id_mask_email((string)$user['email'])]);
    }
    $mfa = !empty($user['totp_enabled']);
    $out = ['username' => $u, 'mfaRequired' => $mfa, 'hasSecondary' => !empty($user['secondary_hash']), 'displayName' => $user['display_name'] ?? $u];
    if (!$mfa) {
        $sess = id_issue_session($u, $user);
        // Always expose session + token for messenger bridge
        $out = array_merge($out, $sess);
        if (empty($out['session']) && !empty($out['token'])) $out['session'] = $out['token'];
        if (empty($out['token']) && !empty($out['session'])) $out['token'] = $out['session'];
    }
    json_out($out);
}
if ($method === 'POST' && $path === '/api/v1/auth/session') {
    $b = body();
    $u = strtolower(trim((string)($b['username'] ?? '')));
    $pw = (string)($b['password'] ?? '');
    rate_check('id_sess_' . client_ip(), 10, 60);
    $user = id_load_user($u);
    if (!$user || !id_verify_password($user, $pw)) json_err('Invalid username or password.', 401);
    if (!empty($user['totp_enabled'])) json_err('MFA required', 403);
    if (!empty($user['email']) && empty($user['email_verified']) && !empty($user['must_verify_email'])) {
        json_out(['needEmailVerify' => true, 'username' => $u]);
    }
    json_out(id_issue_session($u, $user));
}
if ($method === 'POST' && $path === '/api/v1/auth/mfa/verify') {
    $b = body();
    $u = strtolower(trim((string)($b['username'] ?? '')));
    rate_check('id_mfa_' . client_ip(), 6, 20);
    $user = id_load_user($u);
    if (!$user) json_err('Invalid authentication code.', 401);
    $code = preg_replace('/\s+/', '', (string)($b['code'] ?? ''));
    $rc = strtoupper(trim((string)($b['recoveryCode'] ?? $b['recovery_code'] ?? '')));
    $ok = false;
    if ($rc !== '') {
        $h = hash('sha256', $rc . '::' . $u);
        $hashes = $user['recovery_hashes'] ?? [];
        $idx = array_search($h, $hashes, true);
        if ($idx !== false) { array_splice($hashes, (int)$idx, 1); $user['recovery_hashes'] = $hashes; id_save_user($u, $user); $ok = true; }
    } elseif ($code !== '' && !empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
        $ok = id_totp_ok((string)$user['totp_secret'], $code);
    } elseif (empty($user['totp_enabled'])) {
        $pw = (string)($b['password'] ?? '');
        if ($pw !== '' && function_exists('sodium_crypto_pwhash_str_verify')) {
            try { $ok = sodium_crypto_pwhash_str_verify($user['pwhash'], $pw); } catch (Throwable $e) { $ok = false; }
        }
    }
    if (!$ok) json_err('Invalid authentication code.', 401);
    $issued = id_issue_session($u, is_array($user) ? $user : ['display_name' => '']);
    json_out($issued);
}
if ($method === 'POST' && $path === '/api/v1/email/verify/start') {
    rate_check('id_em_' . client_ip(), 6, 60);
    $b = body();
    $u = strtolower(trim((string)($b['username'] ?? '')));
    $emailIn = strtolower(trim((string)($b['email'] ?? '')));
    $purpose = (($b['purpose'] ?? 'verify') === 'recovery') ? 'recovery' : 'verify';
    $user = $u !== '' ? id_load_user($u) : null;
    $to = $user ? strtolower((string)($user['email'] ?? '')) : '';
    if ($to === '' && $emailIn !== '') {
        $to = $emailIn;
        foreach (scandir(DATA_DIR . '/identity/users') ?: [] as $fn) {
            if (!str_ends_with($fn, '.json')) continue;
            $cand = store_get(DATA_DIR . '/identity/users/' . $fn);
            if ($cand && strtolower((string)($cand['email'] ?? '')) === $to) { $user = $cand; $u = $cand['username']; break; }
        }
    }
    $generic = ['ok' => true, 'generic' => true, 'expires_min' => 10];
    if ($user && $to !== '') { $generic = id_send_code($to, $purpose, $u); id_event($u, 'email.code_sent', 'purpose=' . $purpose); }
    json_out($generic);
}
if ($method === 'POST' && $path === '/api/v1/email/verify/confirm') {
    rate_check('id_emc_' . client_ip(), 10, 60);
    $b = body();
    $to = strtolower(trim((string)($b['email'] ?? $b['to'] ?? '')));
    $code = preg_replace('/\s+/', '', (string)($b['code'] ?? ''));
    $rec = store_get(DATA_DIR . '/identity/email/' . hash('sha256', $to) . '.json');
    if (!$rec) json_err('No pending verification. Request a new code.', 400);
    if (time() > (int)($rec['expiry'] ?? 0)) { @unlink(DATA_DIR . '/identity/email/' . hash('sha256', $to) . '.json'); json_err('This code has expired. Request a new one.', 410); }
    $rec['attempts'] = (int)($rec['attempts'] ?? 0) + 1;
    if ($rec['attempts'] > 5) { @unlink(DATA_DIR . '/identity/email/' . hash('sha256', $to) . '.json'); json_err('Too many attempts. Request a new code.', 429); }
    store_put(DATA_DIR . '/identity/email/' . hash('sha256', $to) . '.json', $rec);
    if (!hash_equals((string)$rec['hash'], hash('sha256', $code . '::' . $to))) json_err('Incorrect code. Check the digits and try again.', 400);
    $u = (string)($rec['username'] ?? '');
    $user = id_load_user($u);
    if ($user && ($rec['purpose'] ?? '') === 'verify') {
        $user['email_verified'] = true; $user['must_verify_email'] = false; id_save_user($u, $user); id_event($u, 'email.verified', 'Email verified');
    }
    @unlink(DATA_DIR . '/identity/email/' . hash('sha256', $to) . '.json');
    $out = ['verified' => true, 'username' => $u, 'purpose' => $rec['purpose'] ?? 'verify'];
    // Auto-issue session so client can enter workspace without a second login
    if ($u !== '') {
        $user = id_load_user($u) ?: ['display_name' => ''];
        $issued = id_issue_session($u, is_array($user) ? $user : ['display_name' => '']);
        $sid = $issued['session'];
        $user = id_load_user($u) ?: [];
        $relay = function_exists('id_provision_relay_user') ? id_provision_relay_user($u, (string)($user['display_name'] ?? $u)) : null;
        $out['session'] = $sid;
        $out['token'] = $sid;
        $out['displayName'] = $user['display_name'] ?? $u;
        $out['messaging'] = $relay ? ['identity_id' => $relay['identity_id'] ?? $u, 'public_key' => $relay['public_key'] ?? null, 'auth' => 'session'] : null;
    }
    json_out($out);
}
if ($method === 'POST' && $path === '/api/v1/mfa/totp/enroll') {
    $s = id_require_session();
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $secret = ''; $raw = random_bytes(20);
    for ($i = 0; $i < 20; $i++) $secret .= $alphabet[ord($raw[$i]) & 31];
    store_put(DATA_DIR . '/identity/email/totp_pending_' . hash('sha256', $s['id']) . '.json', ['secret' => $secret, 'username' => $s['username'], 'exp' => time() + 600]);
    json_out(['secret' => $secret, 'otpauthUrl' => 'otpauth://totp/NYX:' . rawurlencode($s['username']) . '?secret=' . $secret . '&issuer=NYX&algorithm=SHA1&digits=6&period=30']);
}
if ($method === 'POST' && $path === '/api/v1/mfa/totp/confirm') {
    $s = id_require_session();
    $b = body();
    $code = preg_replace('/\s+/', '', (string)($b['code'] ?? ''));
    $pending = store_get(DATA_DIR . '/identity/email/totp_pending_' . hash('sha256', $s['id']) . '.json');
    if (!$pending || time() > (int)($pending['exp'] ?? 0)) json_err('Enrollment expired. Start again.', 410);
    if (!id_totp_ok((string)$pending['secret'], $code)) json_err('Invalid authentication code.', 401);
    $user = id_load_user($s['username']);
    $user['totp_secret'] = $pending['secret']; $user['totp_enabled'] = true;
    $codes = []; $hashes = [];
    for ($i = 0; $i < 10; $i++) {
        $cs = strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
        $codes[] = $cs; $hashes[] = hash('sha256', $cs . '::' . $s['username']);
    }
    $user['recovery_hashes'] = $hashes; id_save_user($s['username'], $user);
    @unlink(DATA_DIR . '/identity/email/totp_pending_' . hash('sha256', $s['id']) . '.json');
    id_event($s['username'], 'mfa.enrolled', 'TOTP enabled');
    json_out(['ok' => true, 'recoveryCodes' => $codes]);
}
if ($method === 'POST' && $path === '/api/v1/recovery/request') {
    rate_check('id_rec_' . client_ip(), 5, 60);
    $b = body();
    $raw = strtolower(trim((string)($b['usernameOrEmail'] ?? $b['username'] ?? '')));
    $u = ''; $emailIn = '';
    if (str_contains($raw, '@')) $emailIn = $raw; else $u = $raw;
    $user = $u !== '' ? id_load_user($u) : null;
    $to = $user ? strtolower((string)($user['email'] ?? '')) : '';
    if ($to === '' && $emailIn !== '') {
        $to = $emailIn;
        foreach (scandir(DATA_DIR . '/identity/users') ?: [] as $fn) {
            if (!str_ends_with($fn, '.json')) continue;
            $cand = store_get(DATA_DIR . '/identity/users/' . $fn);
            if ($cand && strtolower((string)($cand['email'] ?? '')) === $to) { $user = $cand; $u = $cand['username']; break; }
        }
    }
    $generic = ['ok' => true, 'generic' => true, 'expires_min' => 10];
    if ($user && $to !== '') { $generic = id_send_code($to, 'recovery', $u); id_event($u, 'recovery.requested', 'Recovery code issued'); }
    json_out($generic);
}
if ($method === 'POST' && $path === '/api/v1/recovery/reset') {
    rate_check('id_rr_' . client_ip(), 5, 60);
    $b = body();
    $u = strtolower(trim((string)($b['username'] ?? '')));
    $code = preg_replace('/\s+/', '', (string)($b['code'] ?? ''));
    $newHash = (string)($b['newPasswordVerifier'] ?? $b['pwhash'] ?? '');
    $user = id_load_user($u);
    if (!$user || $newHash === '' || !str_starts_with($newHash, '$argon2')) json_err('Recovery failed.', 400);
    $to = strtolower((string)($user['email'] ?? ''));
    $rec = $to !== '' ? store_get(DATA_DIR . '/identity/email/' . hash('sha256', $to) . '.json') : null;
    if (!$rec || ($rec['purpose'] ?? '') !== 'recovery' || time() > (int)$rec['expiry']) json_err('Recovery failed.', 400);
    if (!hash_equals((string)$rec['hash'], hash('sha256', $code . '::' . $to))) json_err('Recovery failed.', 400);
    $user['pwhash'] = $newHash; $user['totp_enabled'] = false; $user['totp_secret'] = null; $user['secondary_hash'] = null;
    id_save_user($u, $user);
    foreach (scandir(DATA_DIR . '/identity/sessions') ?: [] as $fn) {
        if (!str_ends_with($fn, '.json')) continue;
        $s = store_get(DATA_DIR . '/identity/sessions/' . $fn);
        if ($s && ($s['username'] ?? '') === $u) @unlink(DATA_DIR . '/identity/sessions/' . $fn);
    }
    @unlink(DATA_DIR . '/identity/email/' . hash('sha256', $to) . '.json');
    id_event($u, 'password.reset', 'Credentials rotated');
    json_out(['ok' => true]);
}
if ($method === 'GET' && $path === '/api/v1/account/security') {
    $s = id_require_session();
    $user = id_load_user($s['username']);
    if (!$user) json_err('Account not found', 404);
    $sessions = [];
    foreach (scandir(DATA_DIR . '/identity/sessions') ?: [] as $fn) {
        if (!str_ends_with($fn, '.json')) continue;
        $ss = store_get(DATA_DIR . '/identity/sessions/' . $fn);
        if ($ss && ($ss['username'] ?? '') === $s['username']) $sessions[] = ['id' => $ss['id'], 'createdAt' => $ss['created_ms'] ?? 0, 'lastSeen' => $ss['last_seen'] ?? 0, 'ua' => $ss['ua'] ?? ''];
    }
    $events = [];
    $ef = DATA_DIR . '/identity/events/' . date('Ymd') . '.jsonl';
    if (is_file($ef)) {
        foreach (array_reverse(@file($ef, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
            $e = json_decode($line, true);
            if (is_array($e) && ($e['u'] ?? '') === $s['username']) { $events[] = ['time' => $e['ts'] ?? 0, 'type' => $e['type'] ?? '', 'detail' => $e['detail'] ?? '']; if (count($events) >= 30) break; }
        }
    }
    json_out(['user' => [
        'username' => $user['username'], 'displayName' => $user['display_name'] ?? '', 'email' => $user['email'] ?? null,
        'emailVerified' => !empty($user['email_verified']), 'totpEnabled' => !empty($user['totp_enabled']),
        'passkey' => !empty($user['passkey']), 'fingerprint' => $user['fingerprint'] ?? '', 'kid' => $user['kid'] ?? '',
        'createdAt' => $user['created_ms'] ?? 0, 'recoveryRemaining' => count($user['recovery_hashes'] ?? []),
    ], 'sessions' => $sessions, 'events' => $events, 'currentSession' => $s['id']]);
}
if ($method === 'POST' && $path === '/api/v1/session/revoke') {
    $s = id_require_session();
    $id = (string)(body()['id'] ?? '');
    $target = store_get(id_session_path($id));
    if ($target && ($target['username'] ?? '') === $s['username']) { @unlink(id_session_path($id)); id_event($s['username'], 'session.revoked', 'Session revoked'); }
    json_out(['ok' => true]);
}
if ($method === 'POST' && $path === '/api/v1/session/revoke-all') {
    $s = id_require_session();
    foreach (scandir(DATA_DIR . '/identity/sessions') ?: [] as $fn) {
        if (!str_ends_with($fn, '.json')) continue;
        $ss = store_get(DATA_DIR . '/identity/sessions/' . $fn);
        if ($ss && ($ss['username'] ?? '') === $s['username'] && ($ss['id'] ?? '') !== $s['id']) @unlink(DATA_DIR . '/identity/sessions/' . $fn);
    }
    id_event($s['username'], 'session.revoked_all', 'All other sessions revoked');
    json_out(['ok' => true]);
}
if ($method === 'POST' && $path === '/api/v1/account/password') {
    $s = id_require_session();
    $b = body();
    $user = id_load_user($s['username']);
    if (!$user) json_err('Account not found', 404);
    $ok = false;
    if (function_exists('sodium_crypto_pwhash_str_verify')) {
        try { $ok = sodium_crypto_pwhash_str_verify($user['pwhash'], (string)($b['current_password'] ?? '')); } catch (Throwable $e) { $ok = false; }
    }
    if (!$ok) json_err('Current password is incorrect.', 401);
    $newHash = (string)($b['newPasswordVerifier'] ?? $b['pwhash'] ?? '');
    if ($newHash === '' || !str_starts_with($newHash, '$argon2')) json_err('Invalid new verifier', 422);
    $user['pwhash'] = $newHash; id_save_user($s['username'], $user);
    id_event($s['username'], 'password.changed', 'Password changed');
    json_out(['ok' => true]);
}

if ($method === 'POST' && $path === '/api/v1/account/sign') {
    $s = id_require_session();
    $b = body();
    $payload = (string)($b['payload'] ?? '');
    if ($payload === '' || strlen($payload) > 20000) json_err('payload required');
    $sig = id_sign_payload($s['username'], $payload);
    if ($sig === null) json_err('signing unavailable', 503);
    $relay = id_provision_relay_user($s['username']);
    json_out(['signature' => $sig, 'identity_id' => $relay['identity_id'] ?? $s['username'], 'public_key' => $relay['public_key'] ?? null]);
}


if ($method === 'POST' && $path === '/api/v1/account/ensure-messaging') {
    $s = id_require_session();
    $u = strtolower($s['username']);
    $user = id_load_user($u) ?: [];
    $relay = id_provision_relay_user($u, (string)($user['display_name'] ?? $u));
    json_out([
        'username' => $u,
        'identity_id' => $relay['identity_id'] ?? $u,
        'public_key' => $relay['public_key'] ?? null,
        'session_ok' => true,
    ]);
}

json_err('Not Found', 404);
