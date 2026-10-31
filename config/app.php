<?php
declare(strict_types=1);

return [
    'name' => getenv('APP_NAME') ?: 'NYX',
    'env' => getenv('APP_ENV') ?: 'production',
    'debug' => (bool)(getenv('APP_DEBUG') ?: (getenv('APP_ENV') !== 'production' && getenv('APP_ENV') !== 'prod')),
    'url' => getenv('APP_URL') ?: '',
    'version' => getenv('NYX_VERSION') ?: '1.42.0-modular',
    'protocol' => (int)(getenv('NYX_PROTOCOL') ?: 3),
    'release_channel' => getenv('NYX_RELEASE_CHANNEL') ?: 'stable',
    'timezone' => 'UTC',
];
