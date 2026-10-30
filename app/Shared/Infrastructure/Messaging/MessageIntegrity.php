<?php
declare(strict_types=1);

namespace Nyx\Shared\Infrastructure\Messaging;

use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Storage\SqliteStore;
use Nyx\Shared\Infrastructure\Cache\RedisClient;

final class MessageIntegrity
{
    public function __construct(private FileStore $store) {}

    public static function make(): self
    {
        $root = defined('DATA_DIR') ? DATA_DIR : (defined('NYX_DATA_DIR') ? NYX_DATA_DIR : '');
        return new self(new FileStore($root));
    }

    public function tipKey(string $convId): string
    {
        return "messages/{$convId}/_chain.json";
    }

    public function tip(string $convId): array
    {
        // Redis hot tip
        if (\class_exists(RedisClient::class) && RedisClient::enabled()) {
            $raw = RedisClient::get('nyx:tip:' . $convId);
            if ($raw) {
                $j = json_decode($raw, true);
                if (is_array($j)) return $j;
            }
        }
        if (\class_exists(SqliteStore::class) && SqliteStore::enabled()) {
            $t = SqliteStore::tip($convId);
            if (is_array($t)) {
                return [
                    'tip_hash' => (string)($t['tip_hash'] ?? ''),
                    'count' => (int)($t['count'] ?? 0),
                    'last_id' => $t['last_id'] ?? null,
                    'updated_ms' => (int)($t['updated_ms'] ?? 0),
                ];
            }
        }
        $t = $this->store->get($this->tipKey($convId));
        return is_array($t) ? $t : ['tip_hash' => '', 'count' => 0, 'last_id' => null, 'updated_ms' => 0];
    }

    public function hashRecord(array $m, string $prevHash): string
    {
        $canon = [
            'id' => (string)($m['id'] ?? ''),
            'conv_id' => (string)($m['conv_id'] ?? ''),
            'sender_id' => (string)($m['sender_id'] ?? ''),
            'created_ms' => (int)($m['created_ms'] ?? 0),
            'body' => (string)($m['body'] ?? ''),
            'ciphertext' => (string)($m['ciphertext'] ?? ''),
            'content_hash' => (string)($m['content_hash'] ?? ''),
            'prev_hash' => $prevHash,
        ];
        return hash('sha256', json_encode($canon, JSON_UNESCAPED_SLASHES));
    }

    public function sealNew(string $convId, array &$record): void
    {
        $tip = $this->tip($convId);
        $prev = (string)($tip['tip_hash'] ?? '');
        $record['prev_hash'] = $prev;
        $record['integrity_hash'] = $this->hashRecord($record, $prev);
        $newTip = [
            'tip_hash' => $record['integrity_hash'],
            'count' => (int)($tip['count'] ?? 0) + 1,
            'last_id' => $record['id'] ?? null,
            'updated_ms' => (int)($record['created_ms'] ?? 0),
        ];
        $this->store->put($this->tipKey($convId), $newTip);
        if (\class_exists(RedisClient::class) && RedisClient::enabled()) {
            RedisClient::set('nyx:tip:' . $convId, json_encode($newTip), 86400);
            RedisClient::publish('nyx:conv:' . $convId, json_encode([
                'type' => 'message',
                'conv_id' => $convId,
                'message_id' => $record['id'] ?? null,
                'created_ms' => $record['created_ms'] ?? null,
            ]));
        }
        if (\class_exists(SqliteStore::class) && SqliteStore::enabled()) {
            SqliteStore::setTip($convId, $newTip);
            SqliteStore::indexMessage($record);
        }
    }

    public static function compact(array $m): array
    {
        $out = [
            'id' => $m['id'] ?? null,
            'conv_id' => $m['conv_id'] ?? null,
            'sender_id' => $m['sender_id'] ?? null,
            'created_ms' => $m['created_ms'] ?? null,
            'edited_ms' => $m['edited_ms'] ?? null,
            'deleted' => !empty($m['deleted']) ? 1 : 0,
            'body' => !empty($m['ciphertext']) ? '' : (string)($m['body'] ?? ''),
            'ciphertext' => $m['ciphertext'] ?? null,
            'e2ee' => !empty($m['e2ee']) || !empty($m['ciphertext']),
            'content_hash' => $m['content_hash'] ?? null,
            'prev_hash' => $m['prev_hash'] ?? null,
            'integrity_hash' => $m['integrity_hash'] ?? null,
            'reply_to' => $m['reply_to'] ?? null,
            'attachments' => $m['attachments'] ?? [],
        ];
        if (!empty($m['silent'])) {
            $out['silent'] = true;
        }
        if (!empty($m['expire_at_ms'])) {
            $out['expire_at_ms'] = (int)$m['expire_at_ms'];
        }
        if (!empty($m['expired'])) {
            $out['expired'] = true;
        }
        if (!empty($m['reactions'])) {
            $out['reactions'] = $m['reactions'];
        }
        if (!empty($m['reply_to'])) {
            $out['reply_to'] = $m['reply_to'];
            $out['reply_to_id'] = $m['reply_to'];
        }
        if (!empty($m['forwarded_from'])) {
            $out['forwarded_from'] = $m['forwarded_from'];
        }
        // preserve display enrichment if already present
        foreach (['display_name', 'displayName', 'username'] as $k) {
            if (!empty($m[$k])) $out[$k] = $m[$k];
        }
        return $out;
    }
}
