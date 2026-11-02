<?php
declare(strict_types=1);
namespace Nyx\Modules\Messaging\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Events\EventBus;
use Nyx\Shared\Infrastructure\Messaging\AccessGuard;
use Nyx\Shared\Infrastructure\Messaging\MessageIntegrity;
use Nyx\Shared\Infrastructure\Http\AuthContext;
final class MessageController {
    private function store(): FileStore { return new FileStore(defined('DATA_DIR')?DATA_DIR:''); }
    private function bus(): EventBus { return new EventBus($this->store()); }

    /** @var array<string,array> request-local user cache — zero extra network, minimal disk */
    private array $userCache = [];

    private function resolveUser(string $uid): array {
        $uid = strtolower(trim($uid));
        if ($uid === '') return ['user_id' => '', 'username' => '', 'display_name' => ''];
        if (isset($this->userCache[$uid])) return $this->userCache[$uid];
        $s = $this->store();
        $row = $s->get("users/{$uid}.json");
        if (!is_array($row)) {
            $row = $s->get("identity/usernames/{$uid}.json");
        }
        if (!is_array($row)) {
            $out = ['user_id' => $uid, 'username' => $uid, 'display_name' => $uid];
            $this->userCache[$uid] = $out;
            return $out;
        }
        $id = (string)($row['user_id'] ?? $uid);
        $uname = (string)($row['username'] ?? $id);
        $dname = (string)($row['display_name'] ?? $row['displayName'] ?? $uname);
        $out = ['user_id' => $id, 'username' => $uname, 'display_name' => $dname];
        $this->userCache[$uid] = $out;
        if ($id !== $uid) $this->userCache[$id] = $out;
        return $out;
    }

    private function enrichSender(array $mm): array {
        // Skip if already enriched (avoids work + keeps compact payloads clean)
        if (!empty($mm['display_name']) || !empty($mm['sender_name'])) return $mm;
        $sid = (string)($mm['sender_id'] ?? '');
        if ($sid === '' || $sid === 'system') return $mm;
        $u = $this->resolveUser($sid);
        $mm['display_name'] = $u['display_name'];
        $mm['sender_name'] = $u['display_name'];
        $mm['username'] = $u['username'];
        $mm['sender_username'] = $u['username'];
        return $mm;
    }

    /** Batch enrich — one pass, shared cache */
    private function enrichBatch(array $messages): array {
        $now = ApiEnvelope::nowMs();
        $out = [];
        foreach ($messages as $m) {
            if (!is_array($m)) continue;
            $exp = (int)($m['expire_at_ms'] ?? 0);
            if ($exp > 0 && $exp < $now) {
                $m['deleted'] = 1;
                $m['body'] = '';
                $m['ciphertext'] = null;
                $m['expired'] = true;
            }
            $out[] = $this->enrichSender($m);
        }
        return $out;
    }

    private function appendIndex(FileStore $s, string $convId, string $id): void {
        $key="messages/{$convId}/_index.json"; $idx=$s->get($key); if(!is_array($idx))$idx=['ids'=>[]];
        $ids=is_array($idx['ids']??null)?$idx['ids']:[];
        if(!in_array($id,$ids,true)){ $ids[]=$id; if(count($ids)>5000)$ids=array_slice($ids,-5000); $idx['ids']=$ids; $idx['updated_ms']=ApiEnvelope::nowMs(); $s->put($key,$idx); }
    }
    private function fanoutToPeers(array $record): void {
        $s=$this->store(); $topo=$s->get('topology/nodes.json'); $nodes=is_array($topo['nodes']??null)?$topo['nodes']:[];
        // Do not fan-out plaintext; only ciphertext / opaque fields
        $out = $record;
        if (!empty($out['ciphertext'])) { $out['body']=''; $out['plaintext']=null; }
        $payload=json_encode(['messages'=>[$out],'source'=>$_SERVER['HTTP_HOST']??'local'], JSON_UNESCAPED_SLASHES);
        $self=strtolower((string)($_SERVER['HTTP_HOST']??'')); if(str_contains($self,':'))$self=explode(':',$self,2)[0];
        foreach($nodes as $n){
            if(!is_array($n)||empty($n['url'])) continue;
            $base=rtrim((string)$n['url'],'/'); $host=strtolower((string)($n['host']??parse_url($base,PHP_URL_HOST)?:''));
            if($host!==''&&$self!==''&&$host===$self) continue;
            if(!function_exists('curl_init')) continue;
            $ch=curl_init($base.'/api/v3/federation/gossip');
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>4,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_POST=>true,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-NYX-Relay: '.($self?:'nyx')],
                CURLOPT_POSTFIELDS=>$payload]);
            @curl_exec($ch); @curl_close($ch);
        }
    }
    public function send(Request $request): JsonResponse {
        $body=$request->json()??[];
        $convId=(string)($body['conv_id']??$body['conversation_id']??'');
        $cipher=(string)($body['ciphertext']??'');
        $text=(string)($body['body']??$body['text']??$body['plaintext']??'');
        if($convId===''||($text===''&&$cipher==='')) return ApiEnvelope::error('conv_id and body or ciphertext required',422);

        $authUser = (string)(($GLOBALS['nyx_auth']['user_id'] ?? '') ?: '');
        if ($authUser === '') {
            try { $authUser = AuthContext::userId($request, $body); } catch (\Throwable $e) {}
        }
        if ($authUser === '') return ApiEnvelope::error('unauthorized', 401);

        $silent = !empty($body['silent']);
        $expireAt = (int)($body['expire_at_ms'] ?? $body['ttl_ms'] ?? 0);
        if ($expireAt > 0 && $expireAt < 10000000000) $expireAt = ApiEnvelope::nowMs() + $expireAt; // relative ms
        $scheduleAt = (int)($body['send_at_ms'] ?? $body['scheduled_at'] ?? 0);
        if ($scheduleAt > ApiEnvelope::nowMs()) {
            // defer to scheduled outbox
            $sid = bin2hex(random_bytes(12));
            $this->store()->put("scheduled/{$authUser}/{$sid}.json", [
                'id' => $sid, 'user_id' => $authUser, 'conv_id' => $convId,
                'body' => $text !== '' ? $text : null, 'ciphertext' => $cipher !== '' ? $cipher : null,
                'send_at_ms' => $scheduleAt, 'silent' => $silent, 'expire_at_ms' => $expireAt,
                'created_ms' => ApiEnvelope::nowMs(), 'status' => 'pending',
            ]);
            return ApiEnvelope::ok(['scheduled' => true, 'id' => $sid, 'send_at_ms' => $scheduleAt]);
        }
        $guard = AccessGuard::make();
        $sp = $guard->space($convId);
        if (!$guard->canWrite($convId, $authUser)) {
            // Heal ONLY empty spaces (no membership files) — prevent owner hijack
            if (is_array($sp) && (string)($sp['owner_id'] ?? '') === '' && $authUser !== '') {
                $mroot = $this->store()->root() . '/memberships/' . $convId;
                $existing = is_dir($mroot) ? (glob($mroot . '/*.json') ?: []) : [];
                if (count($existing) === 0) {
                    $sp['owner_id'] = $authUser;
                    try {
                        $this->store()->put("spaces/{$convId}.json", $sp);
                        $this->store()->put("memberships/{$convId}/{$authUser}.json", [
                            'space_id' => $convId, 'user_id' => $authUser, 'role' => 'owner',
                            'joined_ms' => ApiEnvelope::nowMs(),
                        ]);
                    } catch (\Throwable $e) {}
                }
            } elseif (is_array($sp) && $authUser !== '' && !$guard->isPublicSpace($sp)) {
                // Member missing but user is authenticated creator attempt: soft join as member if no members yet
                $memRoot = $this->store()->root() . '/memberships/' . $convId;
                $hasMem = is_dir($memRoot) && count(glob($memRoot . '/*.json') ?: []) > 0;
                if (!$hasMem) {
                    try {
                        $this->store()->put("memberships/{$convId}/{$authUser}.json", [
                            'space_id' => $convId, 'user_id' => $authUser, 'role' => 'owner',
                            'joined_ms' => ApiEnvelope::nowMs(),
                        ]);
                        $sp['owner_id'] = $authUser;
                        $this->store()->put("spaces/{$convId}.json", $sp);
                    } catch (\Throwable $e) {}
                }
            }
            if (!$guard->canWrite($convId, $authUser)) {
                return ApiEnvelope::error('forbidden', 403);
            }
        }

        // Private spaces / DMs should prefer ciphertext (soft enforce: public allows plaintext)
        $needE2ee = $guard->requiresE2ee($sp, $convId);
        if ($needE2ee && $cipher === '' && $text !== '') {
            // Accept plaintext only if client explicitly marks insecure_ok (legacy) — default reject
            if (empty($body['insecure_ok'])) {
                return ApiEnvelope::error('e2ee_required', 422);
            }
        }

        $e2ee = $cipher !== '';
        if ($e2ee) {
            $text = '';
        }

        $id = (string)($body['id'] ?? $body['message_id'] ?? UlidLikeId::generate());
        $now = ApiEnvelope::nowMs();
        $sender = $authUser;
        $recipient = (string)($body['recipient_id'] ?? $body['to'] ?? '');
        $contentHash = (string)($body['content_hash'] ?? $body['contentHash'] ?? '');
        $signature = (string)($body['signature'] ?? '');

        // Idempotent resend
        $s = $this->store();
        $existing = $s->get("messages/{$convId}/{$id}.json");
        if (is_array($existing)) {
            return ApiEnvelope::ok($this->enrichSender(MessageIntegrity::compact($existing)) + ['stored' => true, 'deduped' => true]);
        }

        $record = [
            'id' => $id,
            'conv_id' => $convId,
            'sender_id' => $sender,
            'recipient_id' => $recipient,
            'body' => $text,
            'ciphertext' => $cipher ?: null,
            'content_hash' => $contentHash ?: null,
            'signature' => $signature ?: null,
            'created_ms' => $now,
            'edited_ms' => null,
            'deleted' => 0,
            'reply_to' => $body['reply_to'] ?? $body['reply_to_id'] ?? $body['replyTo'] ?? $body['reply_to_message_id'] ?? null,
            'attachments' => $body['attachments'] ?? [],
            'e2ee' => $e2ee,
            'silent' => !empty($body['silent']),
            'expire_at_ms' => (int)($body['expire_at_ms'] ?? 0) ?: null,
            'stored' => true,
        ];
        if (!empty($record['expire_at_ms']) && $record['expire_at_ms'] < 10000000000) {
            $record['expire_at_ms'] = $now + (int)$record['expire_at_ms'];
        }
        // no fat payload blob — saves disk + bandwidth
        MessageIntegrity::make()->sealNew($convId, $record);
        $s->put("messages/{$convId}/{$id}.json", $record);
        $this->appendIndex($s, $convId, $id);
        try { $this->bus()->publish("conv:{$convId}", ['type' => 'message', 'message_id' => $id, 'conv_id' => $convId, 'created_ms' => $now]); } catch (\Throwable $e) {}
        try { $this->fanoutToPeers($record); } catch (\Throwable $e) {}
        // Lightweight server notifications for other members (skip silent / self)
        if (empty($record['silent'])) {
            try { $this->notifyPeersOfMessage($convId, $sender, $record); } catch (\Throwable $e) {}
        }
        return ApiEnvelope::ok(array_merge($this->enrichSender(MessageIntegrity::compact($record)), ['stored' => true]));
    }

    /** Notify up to 20 other members — best-effort, never breaks send */
    private function notifyPeersOfMessage(string $convId, string $sender, array $record): void
    {
        $s = $this->store();
        $members = [];
        $mdir = $s->root() . '/memberships/' . $convId;
        if (is_dir($mdir)) {
            foreach (glob($mdir . '/*.json') ?: [] as $mf) {
                $members[] = basename($mf, '.json');
                if (count($members) >= 20) break;
            }
        }
        if ($members === []) {
            $sp = $s->get("spaces/{$convId}.json");
            if (is_array($sp)) {
                $members = $sp['member_ids'] ?? $sp['memberIds'] ?? $sp['members'] ?? [];
                if (!is_array($members)) $members = [];
                $members = array_slice($members, 0, 20);
            }
        }
        $senderL = strtolower($sender);
        $preview = '';
        if (empty($record['ciphertext'])) {
            $preview = mb_substr((string)($record['body'] ?? ''), 0, 80);
        } else {
            $preview = 'Encrypted message';
        }
        $from = $this->resolveUser($sender);
        $title = (string)($from['display_name'] ?? $from['username'] ?? $sender);
        foreach ($members as $mid) {
            $mid = strtolower(trim((string)$mid));
            if ($mid === '' || $mid === $senderL) continue;
            $nid = bin2hex(random_bytes(6));
            $nrec = $s->get("notifications/{$mid}.json");
            if (!is_array($nrec)) $nrec = ['user_id' => $mid, 'items' => []];
            $items = is_array($nrec['items'] ?? null) ? $nrec['items'] : [];
            array_unshift($items, [
                'id' => $nid,
                'user_id' => $mid,
                'kind' => 'message',
                'title' => $title,
                'desc' => $preview,
                'conv_id' => $convId,
                'message_id' => $record['id'] ?? null,
                'ts' => (int)($record['created_ms'] ?? ApiEnvelope::nowMs()),
                'read' => false,
            ]);
            if (count($items) > 80) $items = array_slice($items, 0, 80);
            $nrec['items'] = $items;
            $nrec['updated_ms'] = ApiEnvelope::nowMs();
            $s->put("notifications/{$mid}.json", $nrec);
        }
    }

    public function sync(Request $request): JsonResponse {
        $json = $request->json() ?? [];
        $convId = (string)($request->query('conv_id') ?? $request->query('conversation_id') ?? $json['conv_id'] ?? $json['conversation_id'] ?? '');
        if ($convId === '') return ApiEnvelope::error('conv_id required', 422);

        $authUser = (string)(($GLOBALS['nyx_auth']['user_id'] ?? '') ?: '');
        if ($authUser === '') {
            try { $authUser = AuthContext::userId($request, $json); } catch (\Throwable $e) {}
        }
        if ($authUser === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $guard = AccessGuard::make();
        if (!$guard->canRead($convId, $authUser)) {
            return ApiEnvelope::error('forbidden', 403);
        }

        $minTs = (int)($request->query('since_ms') ?? $json['since_ms'] ?? 0);
        $before = (int)($request->query('before_ms') ?? $json['before_ms'] ?? 0);
        $limit = (int)($request->query('limit') ?? $json['limit'] ?? 50);
        if ($limit < 1) $limit = 50;
        if ($limit > 100) $limit = 100;

        $s = $this->store();
        $idx = $s->get("messages/{$convId}/_index.json") ?? ['ids' => []];
        $ids = is_array($idx['ids'] ?? null) ? $idx['ids'] : [];
        if ($ids === []) {
            $root = $s->root() . '/messages/' . $convId;
            if (is_dir($root)) {
                foreach (glob($root . '/*.json') ?: [] as $f) {
                    $bn = basename($f, '.json');
                    if ($bn !== '_index' && $bn !== '_chain' && !str_ends_with($bn, '_reactions')) $ids[] = $bn;
                }
            }
        }

        $messages = [];
        foreach ($ids as $mid) {
            $mm = $s->get("messages/{$convId}/{$mid}.json");
            if (!is_array($mm) || !empty($mm['deleted'])) continue;
            $ts = (int)($mm['created_ms'] ?? 0);
            if ($minTs > 0 && $ts <= $minTs) continue;
            if ($before > 0 && $ts >= $before) continue;
            if (!empty($mm['ciphertext'])) {
                $mm['body'] = '';
                $mm['plaintext'] = null;
            }
            // Always attach compact reactions (username preferred for UI labels)
            $rk = $s->get("messages/{$convId}/{$mid}_reactions.json");
            if (is_array($rk)) {
                $map = [];
                foreach (($rk['items'] ?? []) as $it) {
                    $em = (string)($it['emoji'] ?? '');
                    if ($em === '') continue;
                    $label = (string)($it['username'] ?? $it['display_name'] ?? $it['user_id'] ?? '');
                    if ($label === '') continue;
                    if (!isset($map[$em])) $map[$em] = [];
                    if (!in_array($label, $map[$em], true)) $map[$em][] = $label;
                }
                if ($map) $mm['reactions'] = $map;
            }
            $messages[] = MessageIntegrity::compact($mm);
        }
        usort($messages, static fn($a, $b) => ((int)($a['created_ms'] ?? 0)) <=> ((int)($b['created_ms'] ?? 0)));
        $total = count($messages);
        if ($total > $limit) $messages = array_slice($messages, -$limit);
        $tip = MessageIntegrity::make()->tip($convId);
        $messages = $this->enrichBatch($messages);
        return ApiEnvelope::ok([
            'conv_id' => $convId,
            'messages' => $messages,
            'count' => count($messages),
            'has_more' => $total > $limit,
            'oldest_ms' => $messages[0]['created_ms'] ?? null,
            'newest_ms' => $messages ? ($messages[count($messages) - 1]['created_ms'] ?? null) : null,
            'limit' => $limit,
            'chain_tip' => $tip['tip_hash'] ?? '',
        ]);
    }

    /** Long-poll: hold until new messages after since_ms or timeout (max 25s). */
    public function wait(Request $request): JsonResponse {
        $json = $request->json() ?? [];
        $convId = (string)($request->query('conv_id') ?? $json['conv_id'] ?? '');
        if ($convId === '') return ApiEnvelope::error('conv_id required', 422);
        $authUser = (string)(($GLOBALS['nyx_auth']['user_id'] ?? '') ?: '');
        if ($authUser === '') {
            try { $authUser = AuthContext::userId($request, $json); } catch (\Throwable $e) {}
        }
        if ($authUser === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        if (!AccessGuard::make()->canRead($convId, $authUser)) {
            return ApiEnvelope::error('forbidden', 403);
        }
        $since = (int)($request->query('since_ms') ?? $json['since_ms'] ?? 0);
        $timeout = (int)($request->query('timeout') ?? $json['timeout'] ?? 20);
        if ($timeout < 1) $timeout = 1;
        if ($timeout > 25) $timeout = 25;
        $deadline = microtime(true) + $timeout;
        // reuse sync logic with short sleep loop
        while (microtime(true) < $deadline) {
            $requestLike = $request;
            // inline check tip
            $tip = MessageIntegrity::make()->tip($convId);
            $tipMs = (int)($tip['updated_ms'] ?? 0);
            if ($tipMs > $since) {
                // build mini json for sync
                $fake = new Request('GET', $request->path(), array_merge($request->query() ?? [], ['conv_id' => $convId, 'since_ms' => (string)$since, 'limit' => '50']), [], [], []);
                // Fall back: call sync with mutated globals — simpler: duplicate light fetch
                $s = $this->store();
                $idx = $s->get("messages/{$convId}/_index.json") ?? ['ids' => []];
                $ids = is_array($idx['ids'] ?? null) ? $idx['ids'] : [];
                $messages = [];
                foreach ($ids as $mid) {
                    $mm = $s->get("messages/{$convId}/{$mid}.json");
                    if (!is_array($mm) || !empty($mm['deleted'])) continue;
                    $ts = (int)($mm['created_ms'] ?? 0);
                    if ($since > 0 && $ts <= $since) continue;
                    if (!empty($mm['ciphertext'])) $mm['body'] = '';
                    $messages[] = MessageIntegrity::compact($mm);
                }
                usort($messages, static fn($a, $b) => ((int)($a['created_ms'] ?? 0)) <=> ((int)($b['created_ms'] ?? 0)));
                if ($messages) {
                    $messages = $this->enrichBatch($messages);
                    return ApiEnvelope::ok([
                        'conv_id' => $convId,
                        'messages' => $messages,
                        'count' => count($messages),
                        'waited' => true,
                        'newest_ms' => $messages[count($messages) - 1]['created_ms'] ?? null,
                        'chain_tip' => $tip['tip_hash'] ?? '',
                    ]);
                }
            }
            usleep(400000); // 400ms
        }
        return ApiEnvelope::ok([
            'conv_id' => $convId,
            'messages' => [],
            'count' => 0,
            'waited' => true,
            'timeout' => true,
            'newest_ms' => $since,
            'chain_tip' => MessageIntegrity::make()->tip($convId)['tip_hash'] ?? '',
        ]);
    }

    public function edit(Request $request): JsonResponse {
        $body = $request->json() ?? [];
        $id = (string)($body['id'] ?? $body['message_id'] ?? '');
        $convId = (string)($body['conv_id'] ?? $body['conversation_id'] ?? '');
        $text = (string)($body['body'] ?? $body['text'] ?? $body['plaintext'] ?? '');
        $cipher = (string)($body['ciphertext'] ?? '');
        if ($id === '' || $convId === '' || ($text === '' && $cipher === '')) {
            return ApiEnvelope::error('id, conv_id, body/ciphertext required', 422);
        }
        $authUser = (string)(($GLOBALS['nyx_auth']['user_id'] ?? '') ?: '');
        if ($authUser === '') {
            try { $authUser = \Nyx\Shared\Infrastructure\Http\AuthContext::userId($request, $body); } catch (\Throwable $e) {}
        }
        if ($authUser === '') return ApiEnvelope::error('unauthorized', 401);
        $authUser = strtolower(trim($authUser));
        $s = $this->store();
        $authIds = [$authUser];
        try {
            $row = $s->get("users/{$authUser}.json");
            if (!is_array($row)) $row = $s->get("identity/usernames/{$authUser}.json");
            if (is_array($row)) {
                if (!empty($row['user_id'])) $authIds[] = strtolower((string)$row['user_id']);
                if (!empty($row['username'])) $authIds[] = strtolower((string)$row['username']);
            }
        } catch (\Throwable $e) {}
        $authIds = array_values(array_unique(array_filter($authIds)));
        $m = $s->get("messages/{$convId}/{$id}.json");
        if (!$m) return ApiEnvelope::error('message not found', 404);
        $sender = strtolower((string)($m['sender_id'] ?? ''));
        $isAuthor = $sender !== '' && in_array($sender, $authIds, true);
        // Use canonical hash for membership checks
        $authUser = $authIds[0];
        foreach ($authIds as $aid) {
            if (is_array($s->get("memberships/{$convId}/{$aid}.json"))) { $authUser = $aid; break; }
        }
        // Collaborative special messages: any space member may update votes / todo / rsvp payload
        $curBody = (string)($m['body'] ?? $m['plaintext'] ?? '');
        $isCollab = str_starts_with($curBody, '§POLL§') || str_starts_with($curBody, '§QUIZ§')
            || str_starts_with($curBody, '§TODO§') || str_starts_with($curBody, '§EVENT§')
            || str_starts_with($text, '§POLL§') || str_starts_with($text, '§QUIZ§')
            || str_starts_with($text, '§TODO§') || str_starts_with($text, '§EVENT§');
        if (!$isAuthor && !$isCollab) {
            return ApiEnvelope::error('forbidden', 403);
        }
        if (!$isAuthor && $isCollab) {
            // Member check
            $mem = $s->get("memberships/{$convId}/{$authUser}.json");
            if (!is_array($mem)) {
                // try username session -> already resolved? also check by listing
                $ok = false;
                $mdir = $s->root() . '/memberships/' . $convId;
                if (is_dir($mdir)) {
                    foreach (glob($mdir . '/*.json') ?: [] as $mf) {
                        if (strtolower(basename($mf, '.json')) === strtolower($authUser)) { $ok = true; break; }
                    }
                }
                if (!$ok) return ApiEnvelope::error('forbidden', 403);
            }
        }
        // Public messages: allow edit by author only; keep prev integrity_hash as parent_edit_of
        $m['parent_integrity'] = $m['integrity_hash'] ?? null;
        if ($cipher !== '') {
            $m['ciphertext'] = $cipher;
            $m['body'] = '';
            $m['e2ee'] = true;
        } else {
            $m['body'] = $text;
        }
        $m['edited_ms'] = ApiEnvelope::nowMs();
        $m['content_hash'] = (string)($body['content_hash'] ?? $m['content_hash'] ?? '');
        // re-seal as new chain tip entry metadata only (edit does not rewrite history hashes of older msgs)
        $tip = MessageIntegrity::make()->tip($convId);
        $m['edit_of'] = $id;
        $s->put("messages/{$convId}/{$id}.json", $m);
        try { $this->fanoutToPeers($m); } catch (\Throwable $e) {}
        return ApiEnvelope::ok(MessageIntegrity::compact($m));
    }

    public function delete(Request $request): JsonResponse {
        $body = $request->json() ?? [];
        $id = (string)($body['id'] ?? $body['message_id'] ?? '');
        $convId = (string)($body['conv_id'] ?? $body['conversation_id'] ?? '');
        if ($id === '' || $convId === '') return ApiEnvelope::error('id and conv_id required', 422);
        $authUser = (string)(($GLOBALS['nyx_auth']['user_id'] ?? '') ?: '');
        if ($authUser === '') return ApiEnvelope::error('unauthorized', 401);
        $s = $this->store();
        $m = $s->get("messages/{$convId}/{$id}.json");
        if (!$m) return ApiEnvelope::error('message not found', 404);
        $sender = (string)($m['sender_id'] ?? '');
        $guard = AccessGuard::make();
        $sp = $guard->space($convId);
        $isAdmin = false;
        if (is_array($sp)) {
            $mem = $s->get("memberships/{$convId}/{$authUser}.json");
            $role = is_array($mem) ? strtolower((string)($mem['role'] ?? '')) : '';
            $isAdmin = in_array($role, ['owner', 'admin'], true);
        }
        if ($sender !== $authUser && !$isAdmin) {
            return ApiEnvelope::error('forbidden', 403);
        }
        $m['deleted'] = 1;
        $m['deleted_by'] = $authUser;
        $m['deleted_ms'] = ApiEnvelope::nowMs();
        $m['body'] = '';
        $m['ciphertext'] = null;
        $s->put("messages/{$convId}/{$id}.json", $m);
        return ApiEnvelope::ok(['id' => $id, 'deleted' => true]);
    }

    
    public function clear(Request $request): JsonResponse {
        // alias to space clear via messages path
        $body=$request->json()??[];
        $convId=(string)($body['conv_id']??$body['conversation_id']??'');
        $uid=AuthContext::userId($request,$body);
        if($convId===''||$uid==='') return ApiEnvelope::error('conv_id and user_id required',422);
        $now=ApiEnvelope::nowMs();
        $this->store()->put("clears/{$uid}/{$convId}.json",['conversation_id'=>$convId,'user_id'=>$uid,'cleared_ms'=>$now]);
        return ApiEnvelope::ok(['conv_id'=>$convId,'cleared_ms'=>$now]);
    }
    public function react(Request $request): JsonResponse {
        $body=$request->json()??[];
        $id=(string)($body['id']??$body['message_id']??'');
        $convId=(string)($body['conv_id']??$body['conversation_id']??'');
        $emoji=(string)($body['emoji']??'');
        $user=AuthContext::userId($request,$body);
        $user=strtolower(trim($user));
        $username='';
        $s=$this->store();
        try {
            $row=$s->get("users/{$user}.json");
            if(!is_array($row)) $row=$s->get("identity/usernames/{$user}.json");
            if(is_array($row)) {
                if(!empty($row['user_id'])) $user=strtolower((string)$row['user_id']);
                $username=strtolower((string)($row['username']??''));
            }
        } catch(\Throwable $e) {}
        $action=strtolower((string)($body['action']??'add'));
        if($id===''||$convId===''||$emoji==='') return ApiEnvelope::error('required',422);
        $key="messages/{$convId}/{$id}_reactions.json";
        $rec=$s->get($key)??['items'=>[]];
        if(!is_array($rec['items']??null)) $rec['items']=[];
        if($action==='remove'||$action==='toggle'){
            $filtered=[];
            $had=false;
            foreach($rec['items'] as $it){
                $em=(string)($it['emoji']??''); $u=(string)($it['user_id']??'');
                if($em===$emoji && strtolower($u)===strtolower($user)){
                    $had=true;
                    if($action==='remove') continue; // drop
                    if($action==='toggle') continue; // drop for toggle-off
                    continue;
                }
                $filtered[]=$it;
            }
            if($action==='toggle' && !$had){
                $filtered[]=['emoji'=>$emoji,'user_id'=>$user,'username'=>$username,'display_name'=>$username,'ts'=>ApiEnvelope::nowMs()];
                $action='add';
            } elseif($action==='toggle' && $had){
                $action='remove';
            }
            $rec['items']=$filtered;
        } else {
            // add — avoid duplicate same user+emoji
            $exists=false;
            foreach($rec['items'] as $it){
                if((string)($it['emoji']??'')===$emoji && strtolower((string)($it['user_id']??''))===strtolower($user)){ $exists=true; break; }
            }
            if(!$exists) $rec['items'][]=['emoji'=>$emoji,'user_id'=>$user,'username'=>$username,'display_name'=>$username,'ts'=>ApiEnvelope::nowMs()];
        }
        $s->put($key,$rec);
        // normalized map for clients
        $map=[];
        foreach($rec['items'] as $it){
            $em=(string)($it['emoji']??''); $u=(string)($it['user_id']??'');
            if($em==='') continue;
            if(!isset($map[$em])) $map[$em]=[];
            if($u!=='' && !in_array($u,$map[$em],true)) $map[$em][]=$u;
        }
        return ApiEnvelope::ok(['items'=>$rec['items'],'reactions'=>$map,'action'=>$action,'message_id'=>$id,'conversation_id'=>$convId,'emoji'=>$emoji,'user_id'=>$user]);
    }
    public function pin(Request $request): JsonResponse {
        $body=$request->json()??[]; $id=(string)($body['id']??$body['message_id']??''); $convId=(string)($body['conv_id']??$body['conversation_id']??'');
        $s=$this->store(); $key="pins/{$convId}.json"; $rec=$s->get($key)??['ids'=>[]]; if(!in_array($id,$rec['ids']??[],true)) $rec['ids'][]=$id; $s->put($key,$rec); return ApiEnvelope::ok($rec);
    }
    public function unpin(Request $request): JsonResponse {
        $body=$request->json()??[]; $id=(string)($body['id']??$body['message_id']??''); $convId=(string)($body['conv_id']??$body['conversation_id']??'');
        $s=$this->store(); $key="pins/{$convId}.json"; $rec=$s->get($key)??['ids'=>[]]; $rec['ids']=array_values(array_filter($rec['ids']??[],fn($x)=>$x!==$id)); $s->put($key,$rec); return ApiEnvelope::ok($rec);
    }
    public function listPins(Request $request): JsonResponse {
        $convId=(string)($request->query('conv_id')??''); return ApiEnvelope::ok($this->store()->get("pins/{$convId}.json")??['ids'=>[]]);
    }
    public function receipt(Request $request): JsonResponse {
        $body=$request->json()??[]; $id=(string)($body['id']??$body['message_id']??''); $convId=(string)($body['conv_id']??$body['conversation_id']??'');
        $s=$this->store(); $key="receipts/{$convId}/{$id}.json"; $rec=$s->get($key)??['items'=>[]]; $rec['items'][]=['user_id'=>(string)($body['user_id']??''),'type'=>(string)($body['type']??'delivered'),'ts'=>ApiEnvelope::nowMs()]; $s->put($key,$rec); return ApiEnvelope::ok($rec);
    }
    public function markRead(Request $request): JsonResponse {
        $body=$request->json()??[]; $user=AuthContext::userId($request,$body); $convId=(string)($body['conv_id']??$body['conversation_id']??$body['space_id']??'');
        $rec=['user_id'=>$user,'conv_id'=>$convId,'read_ms'=>(int)($body['up_to_ms']??ApiEnvelope::nowMs())]; $this->store()->put('readstate/'.$user.'/'.$convId.'.json',$rec); return ApiEnvelope::ok($rec);
    }
    public function readState(Request $request): JsonResponse {
        $json = $request->json() ?? [];
        $user = (string)($request->query('user_id') ?? ($json['user_id'] ?? ''));
        $convId = (string)($request->query('conv_id') ?? $request->query('conversation_id') ?? ($json['conv_id'] ?? $json['conversation_id'] ?? ''));
        if ($convId === '') return ApiEnvelope::error('conversation_id required', 422);
        $cursors = [];
        $s = $this->store();
        $safeConv = method_exists($this, 'safeId') ? $this->safeId($convId) : preg_replace('/[^a-zA-Z0-9_\-]/', '_', $convId);
        if ($user !== '') {
            $safeUser = method_exists($this, 'safeId') ? $this->safeId($user) : preg_replace('/[^a-zA-Z0-9_\-]/', '_', $user);
            $rec = $s->get('readstate/' . $safeUser . '/' . $safeConv . '.json') ?? ['user_id' => $user, 'conv_id' => $convId, 'read_ms' => 0];
            $cursors[] = ['user_id' => $user, 'conv_id' => $convId, 'read_ms' => (int)($rec['read_ms'] ?? 0), 'message_id' => $rec['message_id'] ?? null];
        } else {
            $base = method_exists($s, 'root') ? $s->root() : (defined('DATA_DIR') ? DATA_DIR : '');
            $dir = $base . '/readstate';
            if (is_dir($dir)) {
                foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $udir) {
                    $uid = basename($udir);
                    $f = $udir . '/' . $safeConv . '.json';
                    if (!is_file($f)) continue;
                    $rec = json_decode((string)file_get_contents($f), true);
                    if (!is_array($rec)) continue;
                    $cursors[] = ['user_id' => $uid, 'conv_id' => $convId, 'read_ms' => (int)($rec['read_ms'] ?? 0)];
                }
            }
        }
        return ApiEnvelope::ok(['conversation_id' => $convId, 'conv_id' => $convId, 'cursors' => $cursors, 'count' => count($cursors)]);
    }
    public function events(Request $request): JsonResponse {
        $json = $request->json() ?? [];
        $user = AuthContext::userId($request, $json);
        if ($user === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $convId = (string)($request->query('conv_id') ?? $request->query('conversation_id') ?? $json['conv_id'] ?? $json['conversation_id'] ?? '');
        $since = (int)($request->query('since_ms') ?? $json['since_ms'] ?? 0);
        $s = $this->store();
        $events = [];
        $now = ApiEnvelope::nowMs();
        $guard = AccessGuard::make();
        $convs = [];
        if ($convId !== '') {
            if (!$guard->canRead($convId, $user)) {
                return ApiEnvelope::error('forbidden', 403);
            }
            $convs[] = $convId;
        } else {
            // Only conversations the authenticated user belongs to
            $root = $s->root() . '/memberships';
            if (is_dir($root)) {
                foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                    $cid = basename($dir);
                    if ($guard->isMember($cid, $user) || $guard->canRead($cid, $user)) {
                        $convs[] = $cid;
                    }
                }
            }
            $convs = array_slice($convs, 0, 40);
        }
        foreach ($convs as $cid) {
            try {
                $busEv = $this->bus()->since('conv:' . $cid, $since);
                if (is_array($busEv)) {
                    foreach ($busEv as $ev) {
                        if (!is_array($ev)) continue;
                        $ev['conversation_id'] = $ev['conversation_id'] ?? $ev['conv_id'] ?? $cid;
                        $events[] = $ev;
                    }
                }
            } catch (\Throwable $e) {}
            // Fallback: recent messages from index
            $idx = $s->get('messages/' . $cid . '/_index.json');
            $ids = is_array($idx) && !empty($idx['ids']) ? $idx['ids'] : [];
            $ids = array_slice($ids, -30);
            foreach ($ids as $mid) {
                $m = $s->get('messages/' . $cid . '/' . $mid . '.json');
                if (!is_array($m)) continue;
                $ts = (int)($m['created_ms'] ?? 0);
                if ($since > 0 && $ts <= $since) continue;
                $events[] = [
                    'type' => 'message',
                    'message_id' => $m['id'] ?? $mid,
                    'conversation_id' => $cid,
                    'conv_id' => $cid,
                    'sender_id' => $m['sender_id'] ?? '',
                    'plaintext' => $m['body'] ?? $m['plaintext'] ?? '',
                    'ciphertext' => $m['ciphertext'] ?? null,
                    'attachments' => $m['attachments'] ?? [],
                    'ts' => $ts,
                    'created_ms' => $ts,
                    'e2ee' => !empty($m['ciphertext']),
                ];
            }
        }
        usort($events, static fn($a, $b) => ((int)($a['ts'] ?? $a['created_ms'] ?? 0)) <=> ((int)($b['ts'] ?? $b['created_ms'] ?? 0)));
        if (count($events) > 80) $events = array_slice($events, -80);
        return ApiEnvelope::ok(['events' => $events, 'count' => count($events), 'server_ms' => $now]);
    }

public function stream(Request $request): void {
        $user = (string)($request->query('user_id') ?? '');
        $since = (int)($request->query('since_ms') ?? 0);
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', 'off');
        while (ob_get_level() > 0) { @ob_end_flush(); }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        echo "retry: 4000\n\n";
        @flush();
        $end = time() + 22;
        $last = $since;
        $s = $this->store();
        while (time() < $end) {
            if (connection_aborted()) break;
            $events = [];
            $now = (int) floor(microtime(true) * 1000);
            $convs = [];
            if ($user !== '') {
                $root = $s->root() . '/memberships';
                if (is_dir($root)) {
                    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                        if (is_file($dir . '/' . $user . '.json')) $convs[] = basename($dir);
                    }
                }
                $convs = array_slice($convs, 0, 25);
            }
            foreach ($convs as $cid) {
                $idx = $s->get('messages/' . $cid . '/_index.json');
                $ids = (is_array($idx) && !empty($idx['ids'])) ? array_slice($idx['ids'], -12) : [];
                foreach ($ids as $mid) {
                    $m = $s->get('messages/' . $cid . '/' . $mid . '.json');
                    if (!is_array($m)) continue;
                    $ts = (int)($m['created_ms'] ?? 0);
                    if ($last > 0 && $ts <= $last) continue;
                    $events[] = [
                        'type' => 'message',
                        'message_id' => $m['id'] ?? $mid,
                        'conversation_id' => $cid,
                        'conv_id' => $cid,
                        'sender_id' => $m['sender_id'] ?? '',
                        'plaintext' => $m['body'] ?? '',
                        'ciphertext' => $m['ciphertext'] ?? null,
                        'attachments' => $m['attachments'] ?? [],
                        'ts' => $ts,
                        'created_ms' => $ts,
                    ];
                    if ($ts > $last) $last = $ts;
                }
            }
            if ($events) {
                echo 'data: ' . json_encode(['events' => $events, 'server_ms' => $now], JSON_UNESCAPED_UNICODE) . "\n\n";
            } else {
                echo ": ka\n\n";
            }
            @flush();
            usleep(900000);
        }
        echo 'data: ' . json_encode(['done' => true, 'server_ms' => (int) floor(microtime(true) * 1000)]) . "\n\n";
        @flush();
        exit;
    }

public function forward(Request $request): JsonResponse {
        $body = $request->json() ?? [];
        $to = (string)($body["to_conversation_id"] ?? $body["to_conv_id"] ?? $body["to"] ?? $body["conversation_id"] ?? "");
        $from = (string)($body["from_conversation_id"] ?? $body["from"] ?? "");
        $mid = (string)($body["message_id"] ?? $body["id"] ?? "");
        $user = strtolower(trim(AuthContext::userId($request, $body)));
        if ($user === "") return ApiEnvelope::error("unauthorized", 401);
        if ($to === "" || $mid === "" || $from === "") return ApiEnvelope::error("from_conversation_id, to_conversation_id and message_id required", 422);
        $guard = AccessGuard::make();
        if (!$guard->canRead($from, $user)) return ApiEnvelope::error("forbidden", 403);
        if (!$guard->canWrite($to, $user)) return ApiEnvelope::error("forbidden", 403);
        $s = $this->store();
        $src = $s->get("messages/{$from}/{$mid}.json");
        if (!is_array($src)) return ApiEnvelope::error("source message not found", 404);
        $id = bin2hex(random_bytes(12));
        $now = ApiEnvelope::nowMs();
        $record = [
            "id" => $id, "message_id" => $id, "conv_id" => $to, "conversation_id" => $to,
            "sender_id" => $user,
            "body" => $src["body"] ?? $src["plaintext"] ?? "",
            "ciphertext" => $src["ciphertext"] ?? null,
            "attachments" => $src["attachments"] ?? [],
            "created_ms" => $now,
            "forwarded_from" => [
                "sender_id" => $src["sender_id"] ?? "",
                "message_id" => $mid,
                "conversation_id" => $from,
                "forwarded_by" => $user,
            ],
            "e2ee" => !empty($src["ciphertext"]),
            "stored" => true,
        ];
        $s->put("messages/{$to}/{$id}.json", $record);
        $this->appendIndex($s, $to, $id);
        try { $this->bus()->publish("conv:{$to}", ["type"=>"message","message_id"=>$id,"conv_id"=>$to,"conversation_id"=>$to,"sender_id"=>$user,"created_ms"=>$now,"ts"=>$now,"ciphertext"=>$record["ciphertext"],"plaintext"=>$record["body"],"attachments"=>$record["attachments"],"forwarded_from"=>$record["forwarded_from"]]); } catch (\Throwable $e) {}
        $enriched = $this->enrichSender(MessageIntegrity::compact($record));
        return ApiEnvelope::ok(array_merge($enriched, ["message" => $enriched, "id" => $id, "message_id" => $id, "created_ms" => $now, "stored" => true]));
    }
}
