<?php
declare(strict_types=1);

namespace Nyx\Modules\Devices\Application\UseCases;

use Nyx\Core\Validation\InputValidator;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Clock\SystemClock;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;

final class RegisterDevice
{
    public function __construct(
        private FileStore $store,
        private SystemClock $clock,
        private UlidLikeId $ids,
    ) {}

    public function handle(array $input): array
    {
        $userId = InputValidator::id($input['user_id'] ?? null);
        $name = InputValidator::string($input['name'] ?? 'device', 80, 1);
        if ($userId === null || $name === null) {
            return ['ok' => false, 'error' => 'invalid_input'];
        }
        $deviceId = $this->ids->generate('dev');
        $ts = $this->clock->nowMs();
        $rec = [
            'id' => $deviceId,
            'user_id' => $userId,
            'name' => $name,
            'created_at_ms' => $ts,
            'last_seen_ms' => $ts,
            'push_token' => InputValidator::string($input['push_token'] ?? '', 512, 0),
        ];
        $this->store->put('devices/' . hash('sha256', $deviceId) . '.json', $rec);
        $idxPath = 'devices/user_' . hash('sha256', $userId) . '.json';
        $idx = $this->store->get($idxPath) ?? ['ids' => []];
        $idx['ids'][] = $deviceId;
        $idx['ids'] = array_values(array_unique($idx['ids']));
        $this->store->put($idxPath, $idx);
        return ['ok' => true, 'data' => $rec];
    }
}
