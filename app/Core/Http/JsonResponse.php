<?php
declare(strict_types=1);

namespace Nyx\Core\Http;

final class JsonResponse extends Response
{
    public static function ok(mixed $data = null, int $status = 200): self
    {
        return new self([
            'ok' => true,
            'data' => $data,
            'error' => null,
        ], $status);
    }

    public static function error(string $message, int $status = 400, mixed $extra = null): self
    {
        $err = ['message' => $message];
        if ($extra !== null) {
            $err['details'] = $extra;
        }
        return new self([
            'ok' => false,
            'data' => null,
            'error' => $err,
        ], $status);
    }

    public function __construct(mixed $payload, int $status = 200, array $headers = [])
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $headers['X-Content-Type-Options'] = 'nosniff';
        $headers['X-NYX-Relay'] = defined('NYX_VERSION') ? NYX_VERSION : '1.40.0';
        parent::__construct(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            $headers
        );
    }
}
