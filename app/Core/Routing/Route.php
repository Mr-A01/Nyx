<?php
declare(strict_types=1);

namespace Nyx\Core\Routing;

/** Route definition DTO (optional explicit registration). */
final class Route
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly mixed $handler,
    ) {}
}
