<?php
declare(strict_types=1);

namespace Nyx\Modules\Messaging\Domain\Enums;

final class MessageType
{
    public const TEXT = 'text';
    public const CIPHERTEXT = 'ciphertext';
    public const ATTACHMENT = 'attachment';
    public const AUDIO = 'audio';
    public const SYSTEM = 'system';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::TEXT, self::CIPHERTEXT, self::ATTACHMENT, self::AUDIO, self::SYSTEM];
    }
}
