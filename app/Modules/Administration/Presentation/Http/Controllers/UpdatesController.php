<?php
declare(strict_types=1);
namespace Nyx\Modules\Administration\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
final class UpdatesController {
    public function wait(Request $r): JsonResponse {
        $since = (int)($r->query("since") ?? 0);
        $timeout = (int)($r->query("timeout") ?? 20);
        if ($timeout < 1) $timeout = 1;
        if ($timeout > 25) $timeout = 25;
        // Non-blocking soft poll: report current epoch without long sleep (WAF-safe)
        $epoch = (int)(microtime(true) * 1000);
        $changed = ($since > 0 && $epoch - $since > 3600000); // hint only after 1h
        return ApiEnvelope::ok([
            "changed" => false,
            "epoch" => $epoch,
            "ts_ms" => $epoch,
            "version" => defined("NYX_VERSION") ? NYX_VERSION : "1.44.0-modular",
            "force_reload" => false,
        ]);
    }
}