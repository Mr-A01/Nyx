<?php
declare(strict_types=1);

namespace Nyx\Modules\Chain\Presentation\Http\Controllers;

use Nyx\Core\Http\Request;
use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Modules\Chain\Domain\LocalChain;

final class ChainController
{
    private function chain(): LocalChain
    {
        return new LocalChain(new FileStore(defined('DATA_DIR') ? DATA_DIR : ''));
    }

    public function tip(Request $r): JsonResponse
    {
        $c = $this->chain();
        $tip = $c->tip();
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'node'));
        return ApiEnvelope::ok([
            'network' => 'nyx-work-chain',
            'node' => $host,
            'height' => (int)$tip['height'],
            'tip_hash' => (string)$tip['tip_hash'],
            'updated_ms' => (int)($tip['updated_ms'] ?? 0),
        ]);
    }

    public function block(Request $r): JsonResponse
    {
        $h = (int)($r->query('height') ?? ($r->json()['height'] ?? 0));
        if ($h < 1) {
            return ApiEnvelope::error('height required', 422);
        }
        $b = $this->chain()->getBlock($h);
        if (!$b) {
            return ApiEnvelope::error('block not found', 404);
        }
        return ApiEnvelope::ok($b);
    }

    public function verify(Request $r): JsonResponse
    {
        $depth = max(1, min(128, (int)($r->query('depth') ?? 32)));
        return ApiEnvelope::ok($this->chain()->verifyTip($depth));
    }
}
