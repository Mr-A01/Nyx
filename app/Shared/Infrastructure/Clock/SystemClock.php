<?php
declare(strict_types=1);

namespace Nyx\Shared\Infrastructure\Clock;

final class SystemClock
{
    public function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
