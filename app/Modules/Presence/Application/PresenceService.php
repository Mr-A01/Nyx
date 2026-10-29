<?php
declare(strict_types=1);

namespace Nyx\Modules\Presence\Application;

use Nyx\Core\Validation\InputValidator;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Clock\SystemClock;

final class PresenceService
{
    public function __construct(private FileStore $store, private SystemClock $clock) {}

    public function touch(string $userId, string $status = 'online'): array
    {
        $uid = InputValidator::id($userId);
        if ($uid === null) {
            return ['ok' => false, 'error' => 'invalid_user'];
        }
        $ts = $this->clock->nowMs();
        $rec = ['user_id' => $uid, 'status' => $status, 'ts_ms' => $ts];
        $this->store->put('presence/' . hash('sha256', $uid) . '.json', $rec);
        return ['ok' => true, 'data' => $rec];
    }

    public function get(string $userId): ?array
    {
        $uid = InputValidator::id($userId);
        if ($uid === null) {
            return null;
        }
        return $this->store->get('presence/' . hash('sha256', $uid) . '.json');
    }
}
