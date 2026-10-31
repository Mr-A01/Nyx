<?php
declare(strict_types=1);

namespace Nyx\Modules\Settlement\Domain;

use Nyx\Modules\Chain\Domain\LocalChain;
use Nyx\Shared\Infrastructure\Storage\FileStore;

/**
 * Builds portable settlement batches from local work-chain + ledger receipts.
 * Format is chain-agnostic JSON for future L1/L2 bridges (no wallet UX required for end users).
 */
final class SettlementBatchBuilder
{
    public const FORMAT = 'nyx-settlement-batch-v1';

    public function __construct(private FileStore $store, private LocalChain $chain) {}

    /**
     * @return array{batch: array<string,mixed>, error?: string}
     */
    public function build(?int $fromHeight = null, ?int $toHeight = null, int $maxBlocks = 50): array
    {
        $tip = $this->chain->tip();
        $tipH = (int)$tip['height'];
        if ($tipH < 1) {
            return ['batch' => [], 'error' => 'chain empty'];
        }

        $to = $toHeight !== null && $toHeight > 0 ? min($toHeight, $tipH) : $tipH;
        $from = $fromHeight !== null && $fromHeight > 0 ? $fromHeight : max(1, $to - $maxBlocks + 1);
        if ($from > $to) {
            return ['batch' => [], 'error' => 'invalid height range'];
        }

        $blocks = [];
        $receiptIds = [];
        $entryDigests = [];
        for ($h = $from; $h <= $to; $h++) {
            $b = $this->chain->getBlock($h);
            if (!is_array($b)) {
                return ['batch' => [], 'error' => "missing block {$h}"];
            }
            $blocks[] = $b;
            foreach ($b['entries'] ?? [] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $digest = hash('sha256', json_encode($entry, JSON_UNESCAPED_SLASHES));
                $entryDigests[] = $digest;
                if (!empty($entry['tx_id'])) {
                    $receiptIds[] = (string)$entry['tx_id'];
                }
            }
        }

        $receipts = [];
        foreach (array_unique($receiptIds) as $txId) {
            $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $txId) ?? '';
            if ($safe === '') {
                continue;
            }
            $rec = $this->store->get("ledger/receipts/{$safe}.json");
            if (is_array($rec)) {
                $receipts[] = $rec;
            }
        }

        sort($entryDigests);
        $merkle = $this->foldHashes($entryDigests);
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'node'));
        $now = (int)(microtime(true) * 1000);
        $batchId = substr(hash('sha256', $host . '|' . $from . '|' . $to . '|' . $merkle . '|' . $now), 0, 24);

        $body = [
            'format' => self::FORMAT,
            'batch_id' => $batchId,
            'network' => 'nyx-work-chain',
            'node' => $host,
            'created_ms' => $now,
            'from_height' => $from,
            'to_height' => $to,
            'tip_hash_at_export' => (string)$tip['tip_hash'],
            'block_count' => count($blocks),
            'receipt_count' => count($receipts),
            'blocks' => $blocks,
            'receipts' => $receipts,
            'entry_merkle' => $merkle,
        ];
        // Canonical hash excludes status fields added later
        $body['content_hash'] = hash('sha256', json_encode([
            'format' => $body['format'],
            'node' => $body['node'],
            'from_height' => $from,
            'to_height' => $to,
            'blocks' => $blocks,
            'receipts' => $receipts,
            'entry_merkle' => $merkle,
        ], JSON_UNESCAPED_SLASHES));

        $body['status'] = 'exported';
        $body['bridge'] = [
            'target' => 'pending', // e.g. ethereum, optimism, cosmos — set on submit
            'tx_ref' => null,
            'submitted_ms' => null,
            'finalized_ms' => null,
        ];

        return ['batch' => $body];
    }

    /** @param list<string> $hashes */
    private function foldHashes(array $hashes): string
    {
        if ($hashes === []) {
            return str_repeat('0', 64);
        }
        $layer = $hashes;
        while (count($layer) > 1) {
            $next = [];
            $n = count($layer);
            for ($i = 0; $i < $n; $i += 2) {
                if ($i + 1 < $n) {
                    $next[] = hash('sha256', $layer[$i] . $layer[$i + 1]);
                } else {
                    $next[] = hash('sha256', $layer[$i] . $layer[$i]);
                }
            }
            $layer = $next;
        }
        return $layer[0];
    }
}
