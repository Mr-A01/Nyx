<?php
declare(strict_types=1);

namespace Nyx\Modules\Settlement\Presentation\Http\Controllers;

use Nyx\Core\Http\Request;
use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Http\AuthContext;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Modules\Chain\Domain\LocalChain;
use Nyx\Modules\Settlement\Domain\SettlementBatchBuilder;

final class SettlementController
{
    private function store(): FileStore
    {
        return new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
    }

    private function builder(): SettlementBatchBuilder
    {
        $s = $this->store();
        return new SettlementBatchBuilder($s, new LocalChain($s));
    }

    /** Create and persist a settlement batch from work-chain range. */
    public function create(Request $r): JsonResponse
    {
        $uid = AuthContext::userId($r);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        // Prefer node operator; allow any authed user for export (read-only package)
        $b = $r->json() ?? [];
        $from = isset($b['from_height']) ? (int)$b['from_height'] : null;
        $to = isset($b['to_height']) ? (int)$b['to_height'] : null;
        $max = max(1, min(100, (int)($b['max_blocks'] ?? 50)));

        $result = $this->builder()->build($from, $to, $max);
        if (!empty($result['error'])) {
            return ApiEnvelope::error((string)$result['error'], 422);
        }
        $batch = $result['batch'];
        $batch['exported_by'] = $uid;
        $id = (string)$batch['batch_id'];
        $this->store()->put("settlement/batches/{$id}.json", $batch);
        $index = $this->store()->get('settlement/index.json') ?? ['ids' => []];
        $ids = is_array($index['ids'] ?? null) ? $index['ids'] : [];
        array_unshift($ids, $id);
        $ids = array_values(array_unique(array_slice($ids, 0, 200)));
        $this->store()->put('settlement/index.json', ['ids' => $ids, 'updated_ms' => ApiEnvelope::nowMs()]);

        return ApiEnvelope::ok([
            'batch_id' => $id,
            'content_hash' => $batch['content_hash'],
            'entry_merkle' => $batch['entry_merkle'],
            'from_height' => $batch['from_height'],
            'to_height' => $batch['to_height'],
            'block_count' => $batch['block_count'],
            'receipt_count' => $batch['receipt_count'],
            'status' => $batch['status'],
            'batch' => $batch,
        ]);
    }

    public function get(Request $r): JsonResponse
    {
        $id = preg_replace('/[^a-zA-Z0-9]/', '', (string)($r->query('id') ?? ($r->json()['id'] ?? $r->json()['batch_id'] ?? ''))) ?? '';
        if ($id === '') {
            return ApiEnvelope::error('id required', 422);
        }
        $batch = $this->store()->get("settlement/batches/{$id}.json");
        if (!is_array($batch)) {
            return ApiEnvelope::error('batch not found', 404);
        }
        return ApiEnvelope::ok($batch);
    }

    public function list(Request $r): JsonResponse
    {
        $index = $this->store()->get('settlement/index.json') ?? ['ids' => []];
        $ids = is_array($index['ids'] ?? null) ? $index['ids'] : [];
        $items = [];
        foreach (array_slice($ids, 0, 50) as $id) {
            $b = $this->store()->get("settlement/batches/{$id}.json");
            if (!is_array($b)) {
                continue;
            }
            $items[] = [
                'batch_id' => $b['batch_id'] ?? $id,
                'content_hash' => $b['content_hash'] ?? '',
                'from_height' => $b['from_height'] ?? 0,
                'to_height' => $b['to_height'] ?? 0,
                'block_count' => $b['block_count'] ?? 0,
                'receipt_count' => $b['receipt_count'] ?? 0,
                'status' => $b['status'] ?? 'exported',
                'created_ms' => $b['created_ms'] ?? 0,
                'bridge' => $b['bridge'] ?? null,
            ];
        }
        return ApiEnvelope::ok(['items' => $items, 'count' => count($items)]);
    }

    /**
     * Mark batch as submitted/finalized on an external chain (bridge operator).
     * Does not talk to chain RPC yet — records proof refs for later verifiers.
     */
    public function mark(Request $r): JsonResponse
    {
        $uid = AuthContext::userId($r);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $b = $r->json() ?? [];
        $id = preg_replace('/[^a-zA-Z0-9]/', '', (string)($b['id'] ?? $b['batch_id'] ?? '')) ?? '';
        if ($id === '') {
            return ApiEnvelope::error('id required', 422);
        }
        $batch = $this->store()->get("settlement/batches/{$id}.json");
        if (!is_array($batch)) {
            return ApiEnvelope::error('batch not found', 404);
        }
        $status = (string)($b['status'] ?? 'submitted');
        if (!in_array($status, ['exported', 'submitted', 'finalized', 'failed'], true)) {
            return ApiEnvelope::error('invalid status', 422);
        }
        $batch['status'] = $status;
        $batch['bridge'] = array_merge(is_array($batch['bridge'] ?? null) ? $batch['bridge'] : [], [
            'target' => (string)($b['target'] ?? $batch['bridge']['target'] ?? 'pending'),
            'tx_ref' => (string)($b['tx_ref'] ?? $batch['bridge']['tx_ref'] ?? ''),
            'submitted_ms' => $status === 'submitted' ? ApiEnvelope::nowMs() : ($batch['bridge']['submitted_ms'] ?? null),
            'finalized_ms' => $status === 'finalized' ? ApiEnvelope::nowMs() : ($batch['bridge']['finalized_ms'] ?? null),
            'updated_by' => $uid,
        ]);
        $this->store()->put("settlement/batches/{$id}.json", $batch);
        return ApiEnvelope::ok([
            'batch_id' => $id,
            'status' => $batch['status'],
            'bridge' => $batch['bridge'],
            'content_hash' => $batch['content_hash'] ?? '',
        ]);
    }

    /** Public summary of latest settlement readiness. */
    public function status(Request $r): JsonResponse
    {
        $tip = (new LocalChain($this->store()))->tip();
        $index = $this->store()->get('settlement/index.json') ?? ['ids' => []];
        $ids = is_array($index['ids'] ?? null) ? $index['ids'] : [];
        $latest = null;
        if ($ids !== []) {
            $b = $this->store()->get('settlement/batches/' . $ids[0] . '.json');
            if (is_array($b)) {
                $latest = [
                    'batch_id' => $b['batch_id'] ?? $ids[0],
                    'content_hash' => $b['content_hash'] ?? '',
                    'status' => $b['status'] ?? '',
                    'from_height' => $b['from_height'] ?? 0,
                    'to_height' => $b['to_height'] ?? 0,
                ];
            }
        }
        return ApiEnvelope::ok([
            'format' => SettlementBatchBuilder::FORMAT,
            'chain_height' => (int)$tip['height'],
            'tip_hash' => (string)$tip['tip_hash'],
            'batches' => count($ids),
            'latest' => $latest,
            'note' => 'Batches are portable JSON proofs; external L1/L2 submit is operator-side.',
        ]);
    }
}
