<?php
declare(strict_types=1);

use Nyx\Core\Bootstrap\Application;
use Nyx\Core\Http\Request;
use Nyx\Core\Http\JsonResponse;

$app = Application::configure(NYX_BASE)
    ->withRouting(
        api: NYX_BASE . '/routes/api',
        web: NYX_BASE . '/routes/web/routes.php',
    )
    ->withMiddleware()
    ->withExceptions()
    ->create();

$request = Request::capture();
$response = $app->handle($request);
$response->send();
