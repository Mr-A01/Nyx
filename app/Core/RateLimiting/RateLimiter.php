<?php
declare(strict_types=1);

namespace Nyx\Core\RateLimiting;

use Nyx\Shared\Infrastructure\Storage\FileStore;

/**
 * Simple fixed-window rate limiter backed by FileStore.
 */
final class RateLimiter
{
    public function __construct(
        private FileStore $store,
        private int $max = 180,
        private int $windowSeconds = 60,
    ) {}

    public function hit(string $bucket, ?int $max = null, ?int $windowSeconds = null): bool
    {
        $max = $max ?? $this->max;
        $windowSeconds = $windowSeconds ?? $this->windowSeconds;
        $key = 'rate/' . hash('sha256', $bucket) . '.json';
        $now = time();
        $data = $this->store->get($key) ?? ['hits' => [], 'window' => $windowSeconds];
        $hits = array_values(array_filter(
            $data['hits'] ?? [],
            static fn ($t) => is_int($t) && $t > $now - $windowSeconds
        ));
        if (count($hits) >= $max) {
            $this->store->put($key, ['hits' => $hits, 'window' => $windowSeconds, 'max' => $max]);
            return false;
        }
        $hits[] = $now;
        $this->store->put($key, ['hits' => $hits, 'window' => $windowSeconds, 'max' => $max]);
        return true;
    }
}
