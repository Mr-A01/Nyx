<?php
declare(strict_types=1);

namespace Nyx\Modules\Chain\Presentation\Http\Controllers;

use Nyx\Core\Http\Request;
use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Http\AuthContext;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Modules\Chain\Domain\LocalChain;
use Nyx\Modules\Chain\Domain\NodeOperator;

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
            'network' => LocalChain::NETWORK,
            'version' => LocalChain::VERSION,
            'node' => $host,
            'height' => (int)$tip['height'],
            'tip_hash' => (string)$tip['tip_hash'],
            'total_work' => (int)($tip['total_work'] ?? 0),
            'updated_ms' => (int)($tip['updated_ms'] ?? 0),
            'last_type' => $tip['last_type'] ?? null,
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

    public function range(Request $r): JsonResponse
    {
        $from = (int)($r->query('from') ?? ($r->json()['from'] ?? 1));
        $to = (int)($r->query('to') ?? ($r->json()['to'] ?? 0));
        $max = max(1, min(100, (int)($r->query('max') ?? 50)));
        if ($to < 1) {
            $to = (int)$this->chain()->tip()['height'];
        }
        $blocks = $this->chain()->range($from, $to, $max);
        return ApiEnvelope::ok([
            'from' => $from,
            'to' => $to,
            'count' => count($blocks),
            'blocks' => $blocks,
        ]);
    }

    public function verify(Request $r): JsonResponse
    {
        $depth = (int)($r->query('depth') ?? ($r->json()['depth'] ?? 64));
        // depth=0 → full chain
        if ($depth < 0) {
            $depth = 64;
        }
        return ApiEnvelope::ok($this->chain()->verifyTip($depth));
    }

    public function stats(Request $r): JsonResponse
    {
        return ApiEnvelope::ok($this->chain()->stats());
    }

    public function reindex(Request $r): JsonResponse
    {
        $uid = AuthContext::userId($r);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        return ApiEnvelope::ok($this->chain()->reindex());
    }


    /** Public operator identity (Ed25519) for verifying block signatures. */
    public function operator(Request $r): JsonResponse
    {
        $store = new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
        try {
            $op = new NodeOperator($store);
            $pk = $op->publicKeyHex();
            return ApiEnvelope::ok([
                'alg' => 'ed25519',
                'public_key' => $pk,
                'network' => LocalChain::NETWORK,
                'node' => strtolower((string)($_SERVER['HTTP_HOST'] ?? '')),
                'purpose' => 'work-chain block operator_sig verification',
            ]);
        } catch (\Throwable $e) {
            return ApiEnvelope::error('operator key unavailable', 503);
        }
    }

    /** Accept peer tip announcements (mesh observability, not full consensus). */
    public function gossip(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $peer = strtolower(trim((string)($b['node'] ?? $b['host'] ?? '')));
        $height = (int)($b['height'] ?? 0);
        $tip = (string)($b['tip_hash'] ?? '');
        if ($peer === '' || $height < 0 || $tip === '') {
            return ApiEnvelope::error('node, height, tip_hash required', 422);
        }
        $peer = preg_replace('/[^a-z0-9.\-]/', '', $peer) ?? '';
        if ($peer === '') {
            return ApiEnvelope::error('invalid node', 422);
        }
        $store = new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
        $rec = [
            'node' => $peer,
            'height' => $height,
            'tip_hash' => substr($tip, 0, 64),
            'total_work' => (int)($b['total_work'] ?? 0),
            'version' => (int)($b['version'] ?? LocalChain::VERSION),
            'seen_ms' => ApiEnvelope::nowMs(),
            'url' => substr((string)($b['url'] ?? ''), 0, 200),
        ];
        $store->put("chain/peers/{$peer}.json", $rec);
        return ApiEnvelope::ok(['accepted' => true, 'peer' => $rec, 'local' => $this->chain()->tip()]);
    }

    public function mesh(Request $r): JsonResponse
    {
        $store = new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
        $dir = $store->root() . '/chain/peers';
        $items = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                $rec = json_decode((string)@file_get_contents($f), true);
                if (is_array($rec)) {
                    $items[] = $rec;
                }
            }
        }
        usort($items, static fn($a, $b) => ((int)($b['height'] ?? 0)) <=> ((int)($a['height'] ?? 0)));
        $local = $this->chain()->tip();
        return ApiEnvelope::ok([
            'local' => [
                'height' => (int)$local['height'],
                'tip_hash' => (string)$local['tip_hash'],
                'total_work' => (int)($local['total_work'] ?? 0),
                'version' => LocalChain::VERSION,
                'node' => strtolower((string)($_SERVER['HTTP_HOST'] ?? '')),
            ],
            'peers' => $items,
            'count' => count($items),
        ]);
    }

    /** Push local tip to configured topology peers (best-effort). */
    public function announce(Request $r): JsonResponse
    {
        $store = new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
        $tip = $this->chain()->tip();
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $payload = json_encode([
            'node' => $host,
            'height' => (int)$tip['height'],
            'tip_hash' => (string)$tip['tip_hash'],
            'total_work' => (int)($tip['total_work'] ?? 0),
            'version' => LocalChain::VERSION,
            'url' => 'https://' . $host . '/nyx',
        ]);
        $topo = $store->get('topology/nodes.json');
        $nodes = is_array($topo['nodes'] ?? null) ? $topo['nodes'] : [];
        $results = [];
        foreach (array_slice($nodes, 0, 12) as $n) {
            if (!is_array($n)) {
                continue;
            }
            $base = rtrim((string)($n['url'] ?? ''), '/');
            if ($base === '' || str_contains($base, $host)) {
                continue;
            }
            $url = $base . '/api/v3/chain/gossip';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_POSTFIELDS => $payload,
            ]);
            curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $results[] = ['url' => $url, 'http' => $code, 'ok' => $code >= 200 && $code < 300];
        }
        return ApiEnvelope::ok(['announced' => count($results), 'local' => $tip, 'results' => $results]);
    }
}
