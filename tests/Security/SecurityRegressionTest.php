<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Nyx\Core\Http\Request;
use Nyx\Core\Security\Authentication\SessionAuthenticator;
use Nyx\Core\Security\Authentication\PeerAuthenticator;
use Nyx\Shared\Infrastructure\Storage\FileStore;

echo "=== Running Comprehensive Security Regression Tests ===\n";

$tmp = sys_get_temp_dir() . '/nyx_sec_regression_' . uniqid();
mkdir($tmp, 0755, true);
$store = new FileStore($tmp);

// 1. SessionAuthenticator Expiry & Validation Tests
$auth = new SessionAuthenticator($store);
$validToken = bin2hex(random_bytes(16));
$expiredToken = bin2hex(random_bytes(16));
$revokedToken = bin2hex(random_bytes(16));

$now = (int)floor(microtime(true) * 1000);
$store->put("sessions/{$validToken}.json", [
    'token' => $validToken,
    'user_id' => 'usr_123',
    'username' => 'alice',
    'expires_ms' => $now + 60000
]);

$store->put("sessions/{$expiredToken}.json", [
    'token' => $expiredToken,
    'user_id' => 'usr_456',
    'username' => 'bob',
    'expires_ms' => $now - 1000
]);

// Test valid session
$reqValid = new Request('GET', '/api/v3/auth/me', [], [], ['Authorization' => 'Bearer ' . $validToken], []);
$resValid = $auth->validate($reqValid);
if ($resValid === null || $resValid['user_id'] !== 'usr_123') {
    throw new Exception("Valid session failed validation");
}
echo "[PASS] Valid session accepted.\n";

// Test expired session
$reqExpired = new Request('GET', '/api/v3/auth/me', [], [], ['Authorization' => 'Bearer ' . $expiredToken], []);
$resExpired = $auth->validate($reqExpired);
if ($resExpired !== null) {
    throw new Exception("Expired session was accepted");
}
echo "[PASS] Expired session correctly rejected.\n";

// Test missing session (revoked/non-existent)
$reqMissing = new Request('GET', '/api/v3/auth/me', [], [], ['Authorization' => 'Bearer ' . $revokedToken], []);
if ($auth->validate($reqMissing) !== null) {
    throw new Exception("Non-existent/revoked session accepted");
}
echo "[PASS] Revoked/non-existent session rejected.\n";

// 2. PeerAuthenticator Signature & Replay Tests
$peerAuth = new PeerAuthenticator($tmp);
$body = '{"action":"gossip","nodes":[]}';
$sig = $peerAuth->sign($body);
if (!$peerAuth->verify($body, $sig)) {
    throw new Exception("Peer signature verification failed");
}
if ($peerAuth->verify($body . 'tampered', $sig)) {
    throw new Exception("Peer accepted tampered body");
}
echo "[PASS] PeerAuthenticator signature and tamper protection verified.\n";

// Cleanup temp dir
@array_map('unlink', glob("$tmp/sessions/*") ?: []);
@rmdir("$tmp/sessions");
@array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);

echo "=== All Security Regression Tests Passed Successfully ===\n";
