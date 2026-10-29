<?php
declare(strict_types=1);

namespace Nyx\Modules\Updates\Infrastructure\Manifest;

use Nyx\Shared\Infrastructure\Storage\FileStore;

final class UpdateService
{
    public function __construct(private FileStore $store) {}

    public function currentCheck(): array
    {
        $man = $this->store->get('.update_manifest.json');
        return [
            'current_version' => defined('NYX_VERSION') ? NYX_VERSION : '1.40.0',
            'protocol' => defined('NYX_PROTOCOL') ? NYX_PROTOCOL : 3,
            'manifest' => $man,
            'update_available' => false,
        ];
    }
}
