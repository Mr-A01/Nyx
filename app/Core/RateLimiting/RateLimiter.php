<?php
declare(strict_types=1);

namespace Nyx\Core\RateLimiting;

use Nyx\Shared\Infrastructure\Storage\FileStore;

final class RateLimiter
{
    public function __construct(
        private FileStore $store,
        private int $max = 180,
        private int $windowSeconds = 60,
    ) {}

    public function hit(string $bucket): bool
    {
        $key = 'rate/' . hash('sha256', $bucket) . '.json';
        $now = time();
        $data = $this->store->get($key) ?? ['hits' => [], 'window' => $this->windowSeconds];
        $hits = array_values(array_filter(
            $data['hits'] ?? [],
            static fn ($t) => is_int($t) && $t > $now - $this->windowSeconds
        ));
        if (count($hits) >= $this->max) {
            $this->store->put($key, ['hits' => $hits, 'window' => $this->windowSeconds]);
            return false;
        }
        $hits[] = $now;
        $this->store->put($key, ['hits' => $hits, 'window' => $this->windowSeconds]);
        return true;
    }
}
