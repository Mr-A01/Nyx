<?php
declare(strict_types=1);

return [
    'admin_token' => getenv('ADMIN_TOKEN') ?: '',
    'federation_secret' => getenv('FEDERATION_SECRET') ?: '',
    'rate_limit' => [
        'max' => (int)(getenv('RATE_LIMIT_MAX') ?: 180),
        'window' => (int)(getenv('RATE_LIMIT_WINDOW') ?: 60),
    ],
];
