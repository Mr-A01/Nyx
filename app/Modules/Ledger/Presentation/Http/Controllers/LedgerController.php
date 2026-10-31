<?php
declare(strict_types=1);

namespace Nyx\Modules\Ledger\Presentation\Http\Controllers;

use Nyx\Core\Http\Request;
use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Http\AuthContext;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Core\Security\Crypto\SodiumBox;

/**
 * Off-chain credit ledger — foundation for future token settlement.
 * Does not require a wallet; balances live next to identity data.
 */
final class LedgerController
{
    private function store(): FileStore
    {
        return new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
    }

    private function balKey(string $uid): string
    {
        return "ledger/balances/{$uid}.json";
    }

    private function balance(string $uid): array
    {
        $rec = $this->store()->get($this->balKey($uid));
        if (!is_array($rec)) {
            $rec = ['user_id' => $uid, 'available' => 0, 'held' => 0, 'currency' => 'NYX', 'updated_ms' => 0];
        }
        return $rec;
    }

    public function me(Request $r): JsonResponse
    {
        $uid = AuthContext::userId($r);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        return ApiEnvelope::ok($this->balance($uid));
    }

    public function history(Request $r): JsonResponse
    {
        $uid = AuthContext::userId($r);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $dir = $this->store()->root() . '/ledger/tx/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $uid);
        $items = [];
        if (is_dir($dir)) {
            $files = glob($dir . '/*.json') ?: [];
            rsort($files);
            foreach (array_slice($files, 0, 50) as $f) {
                $rec = json_decode((string)@file_get_contents($f), true);
                if (is_array($rec)) {
                    $items[] = $rec;
                }
            }
        }
        return ApiEnvelope::ok(['user_id' => $uid, 'items' => $items, 'count' => count($items)]);
    }

    /**
     * Signed internal transfer (tip / boost). Optional sodium signature in body.sig.
     */
    public function transfer(Request $r): JsonResponse
    {
        $b = $r->json() ?? [];
        $from = AuthContext::userId($r, $b);
        if ($from === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $to = strtolower(trim((string)($b['to'] ?? $b['to_user_id'] ?? $b['username'] ?? '')));
        if ($to === '') {
            return ApiEnvelope::error('to required', 422);
        }
        $urow = $this->store()->get("users/{$to}.json");
        if (!is_array($urow)) {
            $urow = $this->store()->get("identity/usernames/{$to}.json");
        }
        if (is_array($urow) && !empty($urow['user_id'])) {
            $to = strtolower((string)$urow['user_id']);
        }
        $amount = (int)($b['amount'] ?? 0);
        if ($amount <= 0 || $amount > 1_000_000) {
            return ApiEnvelope::error('invalid amount', 422);
        }
        if ($from === $to) {
            return ApiEnvelope::error('cannot transfer to self', 422);
        }
        $src = $this->balance($from);
        if ((int)$src['available'] < $amount) {
            return ApiEnvelope::error('insufficient balance', 402);
        }
        $dst = $this->balance($to);
        $now = ApiEnvelope::nowMs();
        $txId = substr(hash('sha256', $from . '|' . $to . '|' . $amount . '|' . $now . '|' . random_int(1, PHP_INT_MAX)), 0, 24);
        $src['available'] = (int)$src['available'] - $amount;
        $src['updated_ms'] = $now;
        $dst['available'] = (int)$dst['available'] + $amount;
        $dst['updated_ms'] = $now;
        $memo = substr((string)($b['memo'] ?? $b['reason'] ?? ''), 0, 200);
        $tx = [
            'id' => $txId,
            'from' => $from,
            'to' => $to,
            'amount' => $amount,
            'currency' => 'NYX',
            'memo' => $memo,
            'created_ms' => $now,
            'type' => 'transfer',
        ];
        if (SodiumBox::available() && !empty($b['sig'])) {
            $tx['client_sig'] = substr((string)$b['sig'], 0, 256);
        }
        $this->store()->put($this->balKey($from), $src);
        $this->store()->put($this->balKey($to), $dst);
        $this->store()->put("ledger/tx/{$from}/{$txId}.json", $tx);
        $this->store()->put("ledger/tx/{$to}/{$txId}.json", $tx);
        $this->store()->put("ledger/receipts/{$txId}.json", $tx);
        return ApiEnvelope::ok(['tx' => $tx, 'balance' => $src]);
    }

    /** Bootstrap credits for new users / demos (idempotent small grant). */
    public function faucet(Request $r): JsonResponse
    {
        $uid = AuthContext::userId($r);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $bal = $this->balance($uid);
        if (!empty($bal['faucet_claimed'])) {
            return ApiEnvelope::ok(array_merge($bal, ['claimed' => false, 'note' => 'already claimed']));
        }
        $grant = 100;
        $bal['available'] = (int)$bal['available'] + $grant;
        $bal['faucet_claimed'] = true;
        $bal['updated_ms'] = ApiEnvelope::nowMs();
        $this->store()->put($this->balKey($uid), $bal);
        $txId = 'faucet_' . substr(hash('sha256', $uid), 0, 12);
        $tx = [
            'id' => $txId, 'from' => 'system', 'to' => $uid, 'amount' => $grant,
            'currency' => 'NYX', 'memo' => 'welcome grant', 'created_ms' => $bal['updated_ms'], 'type' => 'faucet',
        ];
        $this->store()->put("ledger/tx/{$uid}/{$txId}.json", $tx);
        return ApiEnvelope::ok(['claimed' => true, 'grant' => $grant, 'balance' => $bal, 'tx' => $tx]);
    }

    /** Export recent receipts for the user (chain-ready JSON batch). */
    public function exportReceipts(Request $r): JsonResponse
    {
        $uid = AuthContext::userId($r);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $limit = max(1, min(200, (int)($r->query('limit') ?? 50)));
        $dir = $this->store()->root() . '/ledger/tx/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $uid);
        $items = [];
        if (is_dir($dir)) {
            $files = glob($dir . '/*.json') ?: [];
            rsort($files);
            foreach (array_slice($files, 0, $limit) as $f) {
                $rec = json_decode((string)@file_get_contents($f), true);
                if (is_array($rec)) {
                    $items[] = $rec;
                }
            }
        }
        $batchId = substr(hash('sha256', $uid . '|' . ApiEnvelope::nowMs() . '|' . count($items)), 0, 20);
        $payload = [
            'format' => 'nyx-ledger-receipts-v1',
            'batch_id' => $batchId,
            'user_id' => $uid,
            'exported_ms' => ApiEnvelope::nowMs(),
            'count' => count($items),
            'receipts' => $items,
        ];
        $payload['content_hash'] = hash('sha256', json_encode($items, JSON_UNESCAPED_SLASHES));
        $this->store()->put("ledger/exports/{$uid}/{$batchId}.json", $payload);
        return ApiEnvelope::ok($payload);
    }

}
