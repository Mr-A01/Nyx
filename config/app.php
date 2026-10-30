<?php
declare(strict_types=1);

return [
    'name' => getenv('APP_NAME') ?: 'NYX',
    'env' => getenv('APP_ENV') ?: 'production',
    'debug' => true,
    'url' => getenv('APP_URL') ?: '',
    'version' => getenv('NYX_VERSION') ?: '1.40.0',
    'protocol' => (int)(getenv('NYX_PROTOCOL') ?: 3),
    'release_channel' => getenv('NYX_RELEASE_CHANNEL') ?: 'stable',
    'timezone' => 'UTC',
];
