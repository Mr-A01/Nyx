<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Nyx\Core\Security\SecurityHeaders;
use Nyx\Core\Security\Authentication\AdminAuthenticator;
use Nyx\Core\Security\Authentication\PeerAuthenticator;
use Nyx\Core\Security\Crypto\SodiumBox;
use Nyx\Core\Security\PathGuard;

final class SecurityTest extends TestCase
{
    public function testSecurityHeaders(): void
    {
        // Headers apply without throwing
        SecurityHeaders::apply();
        $this->assertTrue(true);
    }

    public function testAdminAuthenticator(): void
    {
        $tmp = sys_get_temp_dir() . '/nyx_sec_test_' . uniqid();
        mkdir($tmp, 0755, true);
        $auth = new AdminAuthenticator($tmp);
        $token = $auth->token();
        $this->assertNotEmpty($token);
        $this->assertTrue($auth->check($token));
        $this->assertFalse($auth->check('wrong'));
        
        $newToken = $auth->rotate();
        $this->assertNotEmpty($newToken);
        $this->assertTrue($auth->check($newToken));
        $this->assertFalse($auth->check($token));
        
        // Cleanup
        array_map('unlink', glob("$tmp/*") ?: []);
        rmdir($tmp);
    }

    public function testPeerAuthenticator(): void
    {
        $tmp = sys_get_temp_dir() . '/nyx_peer_test_' . uniqid();
        mkdir($tmp, 0755, true);
        $peer = new PeerAuthenticator($tmp);
        $secret = $peer->federationSecret();
        $this->assertNotEmpty($secret);
        
        $body = '{"type":"ping"}';
        $sig = $peer->sign($body);
        $this->assertTrue($peer->verify($body, $sig));
        $this->assertFalse($peer->verify($body, 'bad-sig'));
        
        array_map('unlink', glob("$tmp/*") ?: []);
        rmdir($tmp);
    }

    public function testSodiumBox(): void
    {
        $this->assertTrue(SodiumBox::available());
        $key = SodiumBox::secretKey();
        $plain = 'Hello NYX E2EE Messenger';
        $cipher = SodiumBox::encrypt($plain, $key);
        $decrypted = SodiumBox::decrypt($cipher, $key);
        $this->assertEquals($plain, $decrypted);
        $this->assertNull(SodiumBox::decrypt('invalid-base64', $key));
    }

    public function testPathGuard(): void
    {
        $root = sys_get_temp_dir();
        $safe = PathGuard::safeJoin($root, 'documents/report.pdf');
        $this->assertNotNull($safe);
        
        $unsafe = PathGuard::safeJoin($root, '../../etc/passwd');
        $this->assertNull($unsafe);
    }
}
