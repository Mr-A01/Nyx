<?php
declare(strict_types=1);

/** @var \Nyx\Core\Routing\Router $router */

use Nyx\Core\Http\JsonResponse;
use Nyx\Core\Http\Request;

$router->get('/api/v3/health', static function (Request $request): JsonResponse {
    return JsonResponse::ok([
        'service' => 'nyx-messenger-pro',
        'version' => defined('NYX_VERSION') ? NYX_VERSION : '1.40.0',
        'protocol' => defined('NYX_PROTOCOL') ? NYX_PROTOCOL : 3,
        'status' => 'ok',
        'time_ms' => (int) round(microtime(true) * 1000),
        'sodium' => extension_loaded('sodium'),
        'sqlite' => extension_loaded('pdo_sqlite') || extension_loaded('sqlite3'),
    ]);
});

$router->get('/api/v3/ops/info', static function (Request $request): JsonResponse {
    return JsonResponse::ok([
        'service' => 'nyx-messenger-pro',
        'architecture' => 'modular-hexagonal',
        'modules' => [
            'Identity', 'Devices', 'Conversations', 'Messaging', 'Federation',
            'Crypto', 'Attachments', 'Notifications', 'Moderation', 'Search',
            'Updates', 'Administration', 'Audit', 'Privacy', 'Presence',
        ],
        'version' => defined('NYX_VERSION') ? NYX_VERSION : '1.40.0',
        'protocol' => 3,
    ]);
});
