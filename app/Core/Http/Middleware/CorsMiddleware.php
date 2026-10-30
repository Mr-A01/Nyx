<?php
declare(strict_types=1);

namespace Nyx\Core\Http\Middleware;

use Nyx\Core\Http\Request;
use Nyx\Core\Http\Response;

final class CorsMiddleware
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private array $allowedOrigins = []) {}

    public function handle(Request $request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        $origin = $request->header('Origin') ?? '';
        if ($origin !== '' && (in_array($origin, $this->allowedOrigins, true) || $this->allowedOrigins === ['*'])) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Vary', 'Origin');
            $response->header('Access-Control-Allow-Credentials', 'true');
        }
        $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Device-Id, Accept, X-Nyx-Client');
        $response->header('Access-Control-Allow-Methods', 'GET, POST, PUT, OPTIONS');
        return $response;
    }
}
