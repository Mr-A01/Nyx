<?php
declare(strict_types=1);
namespace Nyx\Modules\Messaging\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Storage\FileStore;

final class EventsController {
    public function poll(Request $r): JsonResponse {
        $uid = (string)($r->query('user_id') ?? ($r->json()['user_id'] ?? ''));
        $since = (int)($r->query('since_ms') ?? ($r->json()['since_ms'] ?? 0));
        if ($uid === '') return ApiEnvelope::error('user_id required', 422);
        $s = new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
        $now = ApiEnvelope::nowMs();
        $events = [];
        // memberships -> recent messages as events
        $root = $s->root() . '/memberships';
        if (is_dir($root)) {
            foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                if (!is_file($dir . '/' . $uid . '.json')) continue;
                $sid = basename($dir);
                $idx = $s->get("messages/{$sid}/_index.json") ?? ['ids' => []];
                $ids = array_slice($idx['ids'] ?? [], -15);
                foreach ($ids as $mid) {
                    $m = $s->get("messages/{$sid}/{$mid}.json");
                    if (!is_array($m)) continue;
                    $ts = (int)($m['created_ms'] ?? $m['timestamp'] ?? 0);
                    if ($ts <= $since) continue;
                    $events[] = [
                        'type' => 'message',
                        'message_id' => $m['id'] ?? $mid,
                        'conversation_id' => $sid,
                        'conv_id' => $sid,
                        'sender_id' => $m['sender_id'] ?? '',
                        'plaintext' => $m['body'] ?? $m['plaintext'] ?? '',
                        'ts' => $ts,
                        'attachments' => $m['attachments'] ?? [],
                    ];
                }
            }
        }
        usort($events, static fn($a, $b) => ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));
        $events = array_slice($events, -40);
        return ApiEnvelope::ok(['events' => $events, 'server_ms' => $now, 'since_ms' => $since, 'count' => count($events)]);
    }
}