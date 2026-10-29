<?php
declare(strict_types=1);

namespace Nyx\Core\Exceptions;

use Nyx\Core\Http\JsonResponse;
use Throwable;

final class Handler
{
    public function __construct(private bool $debug = false) {}

    public function render(Throwable $e): JsonResponse
    {
        $status = 500;
        $message = $this->debug ? $e->getMessage() : 'Internal Server Error';
        return JsonResponse::error($message, $status, $this->debug ? [
            'class' => $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ] : null);
    }
}
