<?php
declare(strict_types=1);

namespace Nyx\Modules\Moderation\Application;

use Nyx\Core\Validation\InputValidator;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Clock\SystemClock;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;

final class ReportMessage
{
    public function __construct(
        private FileStore $store,
        private SystemClock $clock,
        private UlidLikeId $ids,
    ) {}

    public function handle(array $input): array
    {
        $reporter = InputValidator::id($input['reporter_id'] ?? null);
        $target = InputValidator::id($input['message_id'] ?? null);
        $reason = InputValidator::string($input['reason'] ?? 'unspecified', 500, 1);
        if ($reporter === null || $target === null || $reason === null) {
            return ['ok' => false, 'error' => 'invalid_input'];
        }
        $id = $this->ids->generate('rpt');
        $rec = [
            'id' => $id,
            'reporter_id' => $reporter,
            'message_id' => $target,
            'reason' => $reason,
            'details' => InputValidator::string($input['details'] ?? '', 2000, 0),
            'created_at_ms' => $this->clock->nowMs(),
            'status' => 'open',
        ];
        $this->store->put('reports/' . hash('sha256', $id) . '.json', $rec);
        return ['ok' => true, 'data' => $rec];
    }
}
