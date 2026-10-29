<?php
declare(strict_types=1);

return [
    'default' => getenv('DB_DRIVER') ?: 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => getenv('DB_DATABASE') ?: 'storage/data/nyx.sqlite',
        ],
    ],
];
