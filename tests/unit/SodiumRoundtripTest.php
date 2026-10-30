<?php
declare(strict_types=1);
/**
 * Lightweight offline crypto check — run: php tests/unit/SodiumRoundtripTest.php
 * Closes the gap until full E2EE multi-device CI is available.
 */
$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

use Nyx\Core\Security\Crypto\SodiumBox;

$failures = 0;
function assert_true(bool $cond, string $msg): void {
    global $failures;
    if (!$cond) {
        echo "FAIL: $msg\n";
        $failures++;
    } else {
        echo "OK: $msg\n";
    }
}

assert_true(SodiumBox::available(), 'libsodium available');
if (SodiumBox::available()) {
    $key = SodiumBox::secretKey();
    $enc = SodiumBox::encrypt('nyx-core-check', $key);
    assert_true(is_string($enc) && $enc !== '', 'encrypt returns ciphertext');
    assert_true(SodiumBox::decrypt($enc, $key) === 'nyx-core-check', 'decrypt roundtrip');
}

exit($failures > 0 ? 1 : 0);
