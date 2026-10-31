<?php
declare(strict_types=1);
namespace Nyx\Modules\Identity\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
final class AccountController {
    public function ensureMessaging(Request $r): JsonResponse {
        return ApiEnvelope::ok(['messaging' => true, 'ready' => true, 'protocol' => 3]);
    }
}