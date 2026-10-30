<?php
declare(strict_types=1);

return [
    'admin_token' => getenv('ADMIN_TOKEN') ?: '',
    'federation_secret' => getenv('FEDERATION_SECRET') ?: '',
    'rate_limit' => [
        'max' => (int)(getenv('RATE_LIMIT_MAX') ?: 120),
        'window' => (int)(getenv('RATE_LIMIT_WINDOW') ?: 60),
    ],
    'auth' => [
        'session_ttl_days' => 30,
        'min_password_length' => 8,
        'login_max_attempts' => 30,
        'login_window_sec' => 300,
        'register_max_attempts' => 10,
        'register_window_sec' => 600,
        'require_auth_for_mutations' => true,
    ],
];
