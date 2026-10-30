<?php
declare(strict_types=1);

/** @var \Nyx\Core\Routing\Router $router */

use Nyx\Core\Http\JsonResponse;
use Nyx\Core\Http\Request;

$router->get('/api/v3/federation/topology', static function (Request $request): JsonResponse {
    // Placeholder — wire to Federation module Mesh engine
    return JsonResponse::ok([
        'self' => null,
        'version' => defined('NYX_VERSION') ? NYX_VERSION : '1.40.0',
        'max_friends' => 6,
        'friends' => [],
        'known' => [],
        'algorithm' => 'self_heal_fof',
        'note' => 'Wire Nyx\\Modules\\Federation mesh engine here',
    ]);
});
