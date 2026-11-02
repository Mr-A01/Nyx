<?php
declare(strict_types=1);

namespace Nyx\Modules\Federation\Presentation\Http\Controllers;

use Nyx\Core\Http\Request;
use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Core\Security\Authentication\PeerAuthenticator;

final class FederationController
{
    private function store(): FileStore
    {
        return new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
    }

    public function peers(Request $r): JsonResponse
    {
        $topo = $this->store()->get('topology/nodes.json') ?? ['nodes' => []];
        return ApiEnvelope::ok(['nodes' => $topo['nodes'] ?? [], 'count' => count($topo['nodes'] ?? [])]);
    }

    public function topology(Request $r): JsonResponse
    {
        $topo = $this->store()->get('topology/nodes.json') ?? ['nodes' => []];
        return ApiEnvelope::ok($topo);
    }

    /** Reject private/link-local/metadata targets (SSRF). */
    private function isSafePeerUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }
        // Block literal IPs that are private / loopback / link-local / metadata
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
            if (str_starts_with($host, '169.254.') || $host === '0.0.0.0') {
                return false;
            }
        }
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            return false;
        }
        // Disallow non-standard high-risk internal ports optionally — allow 80/443/8080/8443
        if (!in_array($port, [80, 443, 8080, 8443], true) && $port < 1024) {
            return false;
        }
        return true;
    }

    public function registerPeer(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $url = rtrim((string)($b['url'] ?? $b['base'] ?? ''), '/');
        if ($url === '') {
            return ApiEnvelope::error('url required', 422);
        }
        if (!$this->isSafePeerUrl($url)) {
            return ApiEnvelope::error('peer url not allowed', 422);
        }
        $host = (string)($b['host'] ?? parse_url($url, PHP_URL_HOST) ?: '');
        $host = strtolower(preg_replace('/[^a-z0-9.\-]/', '', $host) ?? '');
        if ($host === '') {
            return ApiEnvelope::error('invalid host', 422);
        }
        $s = $this->store();
        $topo = $s->get('topology/nodes.json') ?? ['nodes' => []];
        $nodes = is_array($topo['nodes'] ?? null) ? $topo['nodes'] : [];
        $found = false;
        foreach ($nodes as &$n) {
            if (!is_array($n)) {
                continue;
            }
            if (($n['url'] ?? '') === $url || ($n['host'] ?? '') === $host) {
                $n['url'] = $url;
                $n['host'] = $host;
                $n['added_ms'] = ApiEnvelope::nowMs();
                $found = true;
                break;
            }
        }
        unset($n);
        if (!$found) {
            $nodes[] = ['url' => $url, 'host' => $host, 'added_ms' => ApiEnvelope::nowMs()];
        }
        // Cap topology size
        if (count($nodes) > 64) {
            $nodes = array_slice($nodes, -64);
        }
        $topo['nodes'] = $nodes;
        $topo['updated_ms'] = ApiEnvelope::nowMs();
        $s->put('topology/nodes.json', $topo);
        return ApiEnvelope::ok(['registered' => true, 'host' => $host, 'count' => count($nodes)]);
    }

    /**
     * Ingest gossiped messages — requires peer signature when secret is configured
     * or when NYX_REQUIRE_PEER_SIG is true. Never trust client sender_id as authority.
     */
    public function gossip(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $dataRoot = defined('DATA_DIR') ? DATA_DIR : '';
        $require = filter_var(getenv('NYX_REQUIRE_PEER_SIG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        $sig = (string)($r->header('X-Nyx-Peer-Sig') ?? ($b['peer_sig'] ?? ''));
        $nonce = (string)($r->header('X-Nyx-Peer-Nonce') ?? ($b['peer_nonce'] ?? ''));
        $rawBody = json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $peerAuth = new PeerAuthenticator($dataRoot);
        $hasSecret = is_file(rtrim($dataRoot, '/') . '/.federation_secret') || is_file(rtrim($dataRoot, '/') . '/federation_secret.txt');
        if ($require || $hasSecret || $sig !== '') {
            if (!$peerAuth->verifyAndConsume((string)$rawBody, $sig !== '' ? $sig : null, $nonce !== '' ? $nonce : null)) {
                return ApiEnvelope::error('invalid peer signature', 403);
            }
        }

        $messages = $b['messages'] ?? [];
        if (!is_array($messages)) {
            $messages = [];
        }
        if ($messages === [] && !empty($b['id']) && !empty($b['conv_id'])) {
            $messages = [$b];
        }
        $s = $this->store();
        $stored = 0;
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $convId = preg_replace('/[^a-zA-Z0-9._\\-:]/', '', (string)($msg['conv_id'] ?? '')) ?? '';
            $id = preg_replace('/[^a-zA-Z0-9._\\-:]/', '', (string)($msg['id'] ?? $msg['message_id'] ?? '')) ?? '';
            if ($convId === '' || $id === '') {
                continue;
            }
            // Only allow ciphertext / content_hash — strip spoofable plaintext body on wire
            $safe = [
                'id' => $id,
                'message_id' => $id,
                'conv_id' => $convId,
                'conversation_id' => $convId,
                'sender_id' => (string)($msg['sender_id'] ?? 'federated'),
                'ciphertext' => $msg['ciphertext'] ?? null,
                'content_hash' => $msg['content_hash'] ?? null,
                'signature' => $msg['signature'] ?? null,
                'created_ms' => (int)($msg['created_ms'] ?? ApiEnvelope::nowMs()),
                'e2ee' => !empty($msg['ciphertext']),
                'stored' => true,
                'via' => 'gossip',
            ];
            // Do not accept plaintext over federation
            $safe['body'] = '';
            $path = "messages/{$convId}/{$id}.json";
            if ($s->get($path)) {
                continue;
            }
            $s->put($path, $safe);
            $key = "messages/{$convId}/_index.json";
            $idx = $s->get($key);
            if (!is_array($idx)) {
                $idx = ['ids' => []];
            }
            $ids = is_array($idx['ids'] ?? null) ? $idx['ids'] : [];
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
                $idx['ids'] = array_slice($ids, -5000);
                $idx['updated_ms'] = ApiEnvelope::nowMs();
                $s->put($key, $idx);
            }
            $stored++;
        }
        $qid = UlidLikeId::generate();
        return ApiEnvelope::ok(['queued' => true, 'id' => $qid, 'stored' => $stored, 'received' => count($messages)]);
    }

    public function heal(Request $r): JsonResponse
    {
        return ApiEnvelope::ok(['healed' => true, 'time_ms' => ApiEnvelope::nowMs()]);
    }
}
