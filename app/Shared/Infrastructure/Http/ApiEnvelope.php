<?php
declare(strict_types=1);
namespace Nyx\Shared\Infrastructure\Http;
use Nyx\Core\Http\JsonResponse;
final class ApiEnvelope {
    public static function ok(mixed $data, int $status = 200): JsonResponse {
        return JsonResponse::ok($data, $status);
    }
    public static function error(string $message, int $status = 400, mixed $extra = null): JsonResponse {
        return JsonResponse::error($message, $status, $extra);
    }
    public static function nowMs(): int {
        return (int) floor(microtime(true) * 1000);
    }
}
