<?php
/** @var \Nyx\Core\Routing\Router $router */
use Nyx\Modules\Identity\Presentation\Http\Controllers\AccountController;
$account=AccountController::class;
$router->post('/api/v1/account/ensure-messaging',[$account,'ensureMessaging']);
$router->get('/api/v1/account/ensure-messaging',[$account,'ensureMessaging']);
