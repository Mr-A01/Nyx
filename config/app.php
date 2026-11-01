<?php
declare(strict_types=1);

use Nyx\Core\Support\Env;

$envName = strtolower((string)(Env::get('APP_ENV', 'production') ?: 'production'));
$isProd = in_array($envName, ['production', 'prod'], true);

// FILTER_VALIDATE_BOOLEAN treats "false"/"0"/"off"/"no" as false (unlike (bool)"false" === true)
$rawDebug = Env::get('APP_DEBUG', null);
if ($rawDebug === null || $rawDebug === '') {
    $debug = !$isProd;
} else {
    $debug = filter_var($rawDebug, FILTER_VALIDATE_BOOLEAN);
}

return [
    'name' => (string)(Env::get('APP_NAME', 'NYX') ?: 'NYX'),
    'env' => $envName,
    'debug' => $debug,
    'url' => (string)(Env::get('APP_URL', '') ?: ''),
    'version' => (string)(Env::get('NYX_VERSION', '1.45.0-scale') ?: '1.45.0-scale'),
    'protocol' => (int)(Env::get('NYX_PROTOCOL', 3) ?: 3),
    'release_channel' => (string)(Env::get('NYX_RELEASE_CHANNEL', 'stable') ?: 'stable'),
    'timezone' => 'UTC',
];
