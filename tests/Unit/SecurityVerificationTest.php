<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Nyx\Core\Security\SecurityHeaders;
use Nyx\Core\Security\Authentication\AdminAuthenticator;
use Nyx\Core\Security\Authentication\PeerAuthenticator;
use Nyx\Core\Security\Crypto\SodiumBox;
use Nyx\Core\Security\PathGuard;

echo "=== Running Security & Crypto Verification ===\n";

// 1. SecurityHeaders
SecurityHeaders::apply();
echo "[PASS] SecurityHeaders applied successfully.\n";

// 2. AdminAuthenticator
$tmp = sys_get_temp_dir() . '/nyx_sec_test_' . uniqid();
mkdir($tmp, 0755, true);
$auth = new AdminAuthenticator($tmp);
$token = $auth->token();
if (empty($token)) { throw new Exception("Empty admin token"); }
if (!$auth->check($token)) { throw new Exception("Admin token check failed"); }
if ($auth->check('wrong')) { throw new Exception("Admin token accepted wrong value"); }
$newToken = $auth->rotate();
if (!$auth->check($newToken)) { throw new Exception("Rotated admin token check failed"); }
if ($auth->check($token)) { throw new Exception("Old token still accepted after rotation"); }
echo "[PASS] AdminAuthenticator verified.\n";

// 3. PeerAuthenticator
$peerTmp = sys_get_temp_dir() . '/nyx_peer_test_' . uniqid();
mkdir($peerTmp, 0755, true);
$peer = new PeerAuthenticator($peerTmp);
$secret = $peer->federationSecret();
if (empty($secret)) { throw new Exception("Empty federation secret"); }
$body = '{"type":"ping"}';
$sig = $peer->sign($body);
if (!$peer->verify($body, $sig)) { throw new Exception("Peer signature verification failed"); }
if ($peer->verify($body, 'bad-sig')) { throw new Exception("Peer accepted invalid signature"); }
echo "[PASS] PeerAuthenticator verified.\n";

// 4. SodiumBox
if (!SodiumBox::available()) { throw new Exception("Sodium extension not available"); }
$key = SodiumBox::secretKey();
$plain = 'Hello NYX E2EE Messenger';
$cipher = SodiumBox::encrypt($plain, $key);
$decrypted = SodiumBox::decrypt($cipher, $key);
if ($decrypted !== $plain) { throw new Exception("Sodium encryption/decryption mismatch"); }
if (SodiumBox::decrypt('invalid-base64', $key) !== null) { throw new Exception("Sodium decrypt should fail on invalid payload"); }
echo "[PASS] SodiumBox cryptographic primitives verified.\n";

// 5. PathGuard
$root = sys_get_temp_dir();
$safe = PathGuard::safeJoin($root, 'documents/report.pdf');
if ($safe === null) { throw new Exception("PathGuard rejected safe path"); }
$unsafe = PathGuard::safeJoin($root, '../../etc/passwd');
if ($unsafe !== null) { throw new Exception("PathGuard allowed traversal path"); }
echo "[PASS] PathGuard security guards verified.\n";

// Cleanup
@array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);
@array_map('unlink', glob("$peerTmp/*") ?: []);
@rmdir($peerTmp);

echo "=== All Security & Crypto Checks Passed Successfully ===\n";
