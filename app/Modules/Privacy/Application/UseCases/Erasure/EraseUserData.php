<?php
declare(strict_types=1);

namespace Nyx\Modules\Privacy\Application\UseCases\Erasure;

use Nyx\Core\Validation\InputValidator;
use Nyx\Shared\Infrastructure\Storage\FileStore;

/**
 * Best-effort local erasure for a user id (profiles, presence, notif prefs, devices index).
 * Full message tombstoning remains in legacy relay for protocol consistency.
 */
final class EraseUserData
{
    public function __construct(private FileStore $store) {}

    public function handle(string $userId): array
    {
        $uid = InputValidator::id($userId);
        if ($uid === null) {
            return ['ok' => false, 'error' => 'invalid_user'];
        }
        $h = hash('sha256', $uid);
        $removed = [];
        foreach ([
            "users/{$h}.json",
            "profiles/{$h}.json",
            "presence/{$h}.json",
            "notif_prefs/{$h}.json",
            "devices/user_{$h}.json",
            "push/user_{$h}.json",
        ] as $path) {
            if ($this->store->delete($path)) {
                $removed[] = $path;
            }
        }
        return ['ok' => true, 'removed' => $removed];
    }
}
