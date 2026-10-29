<?php
declare(strict_types=1);

namespace Nyx\Modules\Attachments\Application\UseCases\Upload;

use Nyx\Core\Validation\InputValidator;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Clock\SystemClock;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;

final class StoreAttachmentMeta
{
    public function __construct(
        private FileStore $store,
        private SystemClock $clock,
        private UlidLikeId $ids,
    ) {}

    public function handle(array $input): array
    {
        $owner = InputValidator::id($input['owner_id'] ?? null);
        $mime = InputValidator::string($input['mime'] ?? 'application/octet-stream', 120, 3);
        $size = isset($input['size']) ? (int) $input['size'] : 0;
        $name = InputValidator::string($input['name'] ?? 'file', 255, 1);
        if ($owner === null || $mime === null || $name === null || $size < 0 || $size > 25_000_000) {
            return ['ok' => false, 'error' => 'invalid_input'];
        }
        $id = $this->ids->generate('att');
        $rec = [
            'id' => $id,
            'owner_id' => $owner,
            'mime' => $mime,
            'size' => $size,
            'name' => $name,
            'created_at_ms' => $this->clock->nowMs(),
            'sha256' => InputValidator::string($input['sha256'] ?? '', 64, 0),
        ];
        $this->store->put('attachments/' . hash('sha256', $id) . '.json', $rec);
        return ['ok' => true, 'data' => $rec];
    }
}
