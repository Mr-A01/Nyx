<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Nyx\Core\Http\Request;
use Nyx\Core\Security\Authentication\SessionAuthenticator;
use Nyx\Core\Security\Authentication\PeerAuthenticator;
use Nyx\Core\Support\Env;
use Nyx\Shared\Infrastructure\Storage\FileStore;

echo "=== Running Comprehensive Security Regression Tests ===\n";

function assertTrue(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new Exception($msg);
    }
}

$cases = [
    ['true', true], ['TRUE', true], ['1', true], ['yes', true], ['on', true],
    ['false', false], ['FALSE', false], ['0', false], ['no', false], ['off', false],
];
foreach ($cases as [$raw, $expect]) {
    putenv('NYX_TEST_BOOL=' . $raw);
    $_ENV['NYX_TEST_BOOL'] = $raw;
    $got = filter_var(Env::get('NYX_TEST_BOOL'), FILTER_VALIDATE_BOOLEAN);
    assertTrue($got === $expect, "bool({$raw}) expected " . var_export($expect, true) . ' got ' . var_export($got, true));
}
putenv('NYX_TEST_BOOL');
unset($_ENV['NYX_TEST_BOOL']);
assertTrue(Env::bool('NYX_TEST_BOOL_UNSET', false) === false, 'unset default false');
// Critical regression: (bool)"false" === true in PHP — Env path must not
putenv('APP_DEBUG=false');
$_ENV['APP_DEBUG'] = 'false';
assertTrue(filter_var(Env::get('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN) === false, 'APP_DEBUG=false');
assertTrue(Env::bool('APP_DEBUG', true) === false, 'Env::bool false string');
putenv('APP_DEBUG');
unset($_ENV['APP_DEBUG']);
echo "[PASS] Env boolean parsing (false string safe).\n";

function computeDebug(?string $appDebug, string $appEnv): bool
{
    if ($appDebug === null) {
        putenv('APP_DEBUG');
        unset($_ENV['APP_DEBUG']);
    } else {
        putenv('APP_DEBUG=' . $appDebug);
        $_ENV['APP_DEBUG'] = $appDebug;
    }
    putenv('APP_ENV=' . $appEnv);
    $_ENV['APP_ENV'] = $appEnv;
    $envName = strtolower((string)(Env::get('APP_ENV', 'production') ?: 'production'));
    $isProd = in_array($envName, ['production', 'prod'], true);
    $raw = Env::get('APP_DEBUG', null);
    if ($raw === null || $raw === '') {
        return !$isProd;
    }
    return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
}
assertTrue(computeDebug('false', 'production') === false, 'prod+false');
assertTrue(computeDebug(null, 'production') === false, 'prod+unset');
assertTrue(computeDebug('true', 'production') === true, 'prod+true');
assertTrue(computeDebug(null, 'development') === true, 'dev+unset');
assertTrue(computeDebug('false', 'development') === false, 'dev+false');
putenv('APP_DEBUG'); putenv('APP_ENV');
unset($_ENV['APP_DEBUG'], $_ENV['APP_ENV']);
echo "[PASS] config debug matrix (prod safe by default).\n";

$tmp = sys_get_temp_dir() . '/nyx_sec_regression_' . uniqid();
mkdir($tmp, 0755, true);
$store = new FileStore($tmp);
$auth = new SessionAuthenticator($store);
$now = (int)floor(microtime(true) * 1000);

$validToken = bin2hex(random_bytes(16));
$expiredToken = bin2hex(random_bytes(16));
$store->put("sessions/{$validToken}.json", [
    'token' => $validToken, 'user_id' => 'usr_123', 'username' => 'alice', 'expires_ms' => $now + 60000,
]);
$store->put("sessions/{$expiredToken}.json", [
    'token' => $expiredToken, 'user_id' => 'usr_456', 'username' => 'bob', 'expires_ms' => $now - 1000,
]);

$reqValid = new Request('GET', '/api/v3/auth/me', [], [], ['Authorization' => 'Bearer ' . $validToken], []);
assertTrue(($auth->validate($reqValid)['user_id'] ?? '') === 'usr_123', 'valid');
echo "[PASS] Valid session accepted.\n";

$reqExpired = new Request('GET', '/api/v3/auth/me', [], [], ['Authorization' => 'Bearer ' . $expiredToken], []);
assertTrue($auth->validate($reqExpired) === null, 'expired');
echo "[PASS] Expired session rejected.\n";

$live = bin2hex(random_bytes(16));
$store->put("sessions/{$live}.json", [
    'token' => $live, 'user_id' => 'usr_789', 'username' => 'carol', 'expires_ms' => $now + 60000,
]);
$reqLive = new Request('GET', '/api/v3/auth/me', [], [], ['Authorization' => 'Bearer ' . $live], []);
assertTrue($auth->validate($reqLive) !== null, 'pre-revoke');
assertTrue($auth->revoke($live) === true, 'revoke');
assertTrue($auth->validate($reqLive) === null, 'post-revoke');
assertTrue($auth->revoke($live) === false, 'revoke-idempotent');
echo "[PASS] Session revoke flow.\n";

$missing = bin2hex(random_bytes(16));
$reqMissing = new Request('GET', '/api/v3/auth/me', [], [], ['Authorization' => 'Bearer ' . $missing], []);
assertTrue($auth->validate($reqMissing) === null, 'missing');
echo "[PASS] Non-existent session rejected.\n";

$peerAuth = new PeerAuthenticator($tmp);
$body = '{"action":"gossip","nodes":[]}';
$sig = $peerAuth->sign($body);
assertTrue($peerAuth->verify($body, $sig), 'sig');
assertTrue(!$peerAuth->verify($body . 'x', $sig), 'tamper');
echo "[PASS] Peer signature + tamper.\n";

$nonce = bin2hex(random_bytes(12));
assertTrue($peerAuth->verifyAndConsume($body, $sig, $nonce), 'nonce1');
assertTrue(!$peerAuth->verifyAndConsume($body, $sig, $nonce), 'replay');
assertTrue($peerAuth->verifyAndConsume($body, $sig, bin2hex(random_bytes(12))), 'nonce2');
echo "[PASS] Peer replay protection.\n";

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($tmp);
echo "=== All Security Regression Tests Passed Successfully ===\n";
