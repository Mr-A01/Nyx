<?php
declare(strict_types=1);

namespace Nyx\Modules\Identity\Presentation\Http\Controllers;

use Nyx\Core\Http\Request;
use Nyx\Core\Http\JsonResponse;

final class IdentityController
{
    public function challenge(Request $request): array
    {
        return ['challenge' => bin2hex(random_bytes(32)), 'ok' => true];
    }

    public function register(Request $request): array
    {
        $data = $request->json() ?? [];
        $username = $data['username'] ?? 'user_' . substr(bin2hex(random_bytes(4)), 0, 6);
        return [
            'ok' => true,
            'username' => $username,
            'session' => bin2hex(random_bytes(24)),
            'token' => bin2hex(random_bytes(24)),
            'displayName' => $data['displayName'] ?? $username,
            'messaging' => ['enabled' => true]
        ];
    }

    public function session(Request $request): array
    {
        $data = $request->json() ?? [];
        $username = $data['username'] ?? 'demo_user';
        return [
            'ok' => true,
            'username' => $username,
            'session' => bin2hex(random_bytes(24)),
            'token' => bin2hex(random_bytes(24)),
            'displayName' => $username,
            'messaging' => ['enabled' => true]
        ];
    }

    public function login(Request $request): array
    {
        return $this->session($request);
    }

    public function usernameLookup(Request $request): array
    {
        return ['available' => true, 'ok' => true];
    }
}
