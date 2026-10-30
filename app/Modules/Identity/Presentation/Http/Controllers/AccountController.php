<?php
declare(strict_types=1);

namespace Nyx\Modules\Identity\Presentation\Http\Controllers;

use Nyx\Core\Http\Request;
use Nyx\Core\Http\JsonResponse;

final class AccountController
{
    public function security(Request $request): array
    {
        return [
            'ok' => true,
            'user' => [
                'username' => 'alice',
                'displayName' => 'Alice',
                'email' => 'alice@nyx.local',
                'emailVerified' => true,
                'totpEnabled' => false,
                'fingerprint' => 'nyx_fp_123456789',
                'createdAt' => time() - 86400
            ]
        ];
    }
}
