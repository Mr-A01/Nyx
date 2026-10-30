<?php
declare(strict_types=1);
namespace Nyx\Modules\Messaging\Presentation\Http\Controllers;

use Nyx\Core\Http\Request;
use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Http\AuthContext;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;

/** Bookmarks, scheduled outbox, message TTL helpers, threads. */
final class MessageExtrasController
{
    private function store(): FileStore
    {
        return new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
    }

    private function uid(Request $r, array $b = []): string
    {
        $u = AuthContext::userId($r, $b);
        return $u !== '' ? $u : (string)($b['user_id'] ?? '');
    }

    public function bookmark(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $uid = $this->uid($r, $b);
        if ($uid === '') return ApiEnvelope::error('unauthorized', 401);
        $mid = (string)($b['message_id'] ?? $b['id'] ?? '');
        $cid = (string)($b['conv_id'] ?? $b['conversation_id'] ?? '');
        $action = strtolower((string)($b['action'] ?? 'add'));
        if ($mid === '') return ApiEnvelope::error('message_id required', 422);
        $rec = $this->store()->get("bookmarks/{$uid}.json");
        if (!is_array($rec)) $rec = ['user_id' => $uid, 'items' => []];
        $items = array_values(array_filter($rec['items'] ?? [], static fn($x) => is_array($x) && ($x['message_id'] ?? '') !== $mid));
        if ($action !== 'remove' && $action !== 'delete') {
            $items[] = [
                'message_id' => $mid,
                'conv_id' => $cid,
                'note' => substr((string)($b['note'] ?? ''), 0, 200),
                'saved_ms' => ApiEnvelope::nowMs(),
            ];
        }
        $rec['items'] = $items;
        $rec['updated_ms'] = ApiEnvelope::nowMs();
        $this->store()->put("bookmarks/{$uid}.json", $rec);
        return ApiEnvelope::ok(['user_id' => $uid, 'items' => $items, 'count' => count($items), 'action' => $action]);
    }

    public function bookmarks(Request $r): JsonResponse
    {
        $uid = $this->uid($r);
        if ($uid === '') return ApiEnvelope::error('unauthorized', 401);
        $rec = $this->store()->get("bookmarks/{$uid}.json");
        $items = is_array($rec) ? ($rec['items'] ?? []) : [];
        return ApiEnvelope::ok(['user_id' => $uid, 'items' => $items, 'count' => count($items)]);
    }

    /** Queue a scheduled message (delivered by client poll or cron). */
    public function schedule(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $uid = $this->uid($r, $b);
        if ($uid === '') return ApiEnvelope::error('unauthorized', 401);
        $cid = (string)($b['conv_id'] ?? $b['conversation_id'] ?? '');
        $text = (string)($b['body'] ?? $b['text'] ?? '');
        $cipher = (string)($b['ciphertext'] ?? '');
        $when = (int)($b['send_at_ms'] ?? $b['scheduled_at'] ?? 0);
        if ($cid === '' || ($text === '' && $cipher === '') || $when < ApiEnvelope::nowMs()) {
            return ApiEnvelope::error('conv_id, body/ciphertext, future send_at_ms required', 422);
        }
        $id = UlidLikeId::generate('sch');
        $rec = [
            'id' => $id,
            'user_id' => $uid,
            'conv_id' => $cid,
            'body' => $text !== '' ? $text : null,
            'ciphertext' => $cipher !== '' ? $cipher : null,
            'send_at_ms' => $when,
            'silent' => !empty($b['silent']),
            'created_ms' => ApiEnvelope::nowMs(),
            'status' => 'pending',
        ];
        $this->store()->put("scheduled/{$uid}/{$id}.json", $rec);
        return ApiEnvelope::ok($rec);
    }

    public function scheduledList(Request $r): JsonResponse
    {
        $uid = $this->uid($r);
        if ($uid === '') return ApiEnvelope::error('unauthorized', 401);
        $items = [];
        $dir = $this->store()->root() . "/scheduled/{$uid}";
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                $rec = json_decode((string)@file_get_contents($f), true);
                if (is_array($rec) && ($rec['status'] ?? '') === 'pending') $items[] = $rec;
            }
        }
        usort($items, static fn($a, $b) => ($a['send_at_ms'] ?? 0) <=> ($b['send_at_ms'] ?? 0));
        return ApiEnvelope::ok(['user_id' => $uid, 'items' => $items, 'count' => count($items)]);
    }

    public function scheduleCancel(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $uid = $this->uid($r, $b);
        $id = (string)($b['id'] ?? '');
        if ($uid === '' || $id === '') return ApiEnvelope::error('id required', 422);
        $rec = $this->store()->get("scheduled/{$uid}/{$id}.json");
        if (!is_array($rec)) return ApiEnvelope::error('not found', 404);
        $rec['status'] = 'cancelled';
        $this->store()->put("scheduled/{$uid}/{$id}.json", $rec);
        return ApiEnvelope::ok(['cancelled' => true, 'id' => $id]);
    }

    /** Due scheduled messages for this user (client or worker claims them). */
    public function scheduleDue(Request $r): JsonResponse
    {
        $uid = $this->uid($r);
        if ($uid === '') return ApiEnvelope::error('unauthorized', 401);
        $now = ApiEnvelope::nowMs();
        $due = [];
        $dir = $this->store()->root() . "/scheduled/{$uid}";
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                $rec = json_decode((string)@file_get_contents($f), true);
                if (!is_array($rec) || ($rec['status'] ?? '') !== 'pending') continue;
                if ((int)($rec['send_at_ms'] ?? 0) <= $now) {
                    $rec['status'] = 'due';
                    $this->store()->put("scheduled/{$uid}/{$rec['id']}.json", $rec);
                    $due[] = $rec;
                }
            }
        }
        return ApiEnvelope::ok(['items' => $due, 'count' => count($due)]);
    }

    /** Thread messages under a parent. */
    public function thread(Request $r): JsonResponse
    {
        $cid = (string)($r->query('conv_id') ?? '');
        $parent = (string)($r->query('parent_id') ?? $r->query('reply_to') ?? '');
        if ($cid === '' || $parent === '') return ApiEnvelope::error('conv_id and parent_id required', 422);
        $items = [];
        $dir = $this->store()->root() . "/messages/{$cid}";
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                $m = json_decode((string)@file_get_contents($f), true);
                if (!is_array($m)) continue;
                $rt = (string)($m['reply_to'] ?? $m['parent_id'] ?? '');
                if ($rt === $parent || (string)($m['id'] ?? '') === $parent) $items[] = $m;
            }
        }
        usort($items, static fn($a, $b) => ((int)($a['created_ms'] ?? 0)) <=> ((int)($b['created_ms'] ?? 0)));
        return ApiEnvelope::ok(['conv_id' => $cid, 'parent_id' => $parent, 'messages' => $items, 'count' => count($items)]);
    }
}
