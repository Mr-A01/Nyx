<?php
declare(strict_types=1);

namespace Nyx\Modules\Conversations\Domain\Enums;

final class ConversationType
{
    public const DM = 'dm';
    public const GROUP = 'group';
    public const CHANNEL = 'channel';
    public const SAVED = 'saved';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DM, self::GROUP, self::CHANNEL, self::SAVED];
    }
}
