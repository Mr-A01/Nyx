<?php
declare(strict_types=1);

namespace Nyx\Modules\Messaging\Application\UseCases\Send;

use Nyx\Core\Validation\InputValidator;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;
use Nyx\Shared\Infrastructure\Clock\SystemClock;
use Nyx\Shared\Infrastructure\Storage\FileStore;

final class SendMessageHandler
{
    public function __construct(
        private FileStore $store,
        private UlidLikeId $ids,
        private SystemClock $clock,
    ) {}

    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool,error?:string,data?:array}
     */
    public function handle(array $input): array
    {
        $conv = InputValidator::id($input['conversation_id'] ?? $input['conv'] ?? null, 160);
        $sender = InputValidator::id($input['sender_id'] ?? $input['user_id'] ?? null, 128);
        $clientId = InputValidator::string($input['client_id'] ?? '', 128, 0) ?? '';
        $text = InputValidator::string($input['text'] ?? $input['body'] ?? '', 8000, 0);
        $ciphertext = InputValidator::string($input['ciphertext'] ?? '', 350000, 0);

        if ($conv === null || $sender === null) {
            return ['ok' => false, 'error' => 'invalid_conversation_or_sender'];
        }
        if (($text === null || $text === '') && ($ciphertext === null || $ciphertext === '')) {
            return ['ok' => false, 'error' => 'empty_message'];
        }

        $id = $this->ids->generate('msg');
        $ts = $this->clock->nowMs();
        $evt = [
            'id' => $id,
            'conversation_id' => $conv,
            'sender_id' => $sender,
            'client_id' => $clientId,
            'text' => $text,
            'ciphertext' => $ciphertext,
            'created_at_ms' => $ts,
            'accepted_at_ms' => $ts,
        ];

        $msgPath = 'messages/' . hash('sha256', $id) . '.json';
        $this->store->put($msgPath, $evt);

        $idxPath = 'messages/conv_' . hash('sha256', $conv) . '.json';
        $idx = $this->store->get($idxPath) ?? ['ids' => []];
        $ids = $idx['ids'] ?? [];
        $ids[] = $id;
        // keep last 2000
        if (count($ids) > 2000) {
            $ids = array_slice($ids, -2000);
        }
        $this->store->put($idxPath, ['ids' => $ids, 'updated_at_ms' => $ts]);

        return ['ok' => true, 'data' => ['message' => $evt]];
    }
}
