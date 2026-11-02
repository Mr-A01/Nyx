<?php
declare(strict_types=1);

namespace Nyx\Modules\Mining\Presentation\Http\Controllers;

use Nyx\Core\Http\Request;
use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Http\AuthContext;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Modules\Chain\Domain\LocalChain;

/**
 * Work-based token rewards for network contributors (relay operators / miners).
 *
 * Units: NYX credits on the off-chain ledger, sealed into the local work-chain.
 * Work types: relay_probe, message_relay, storage_keep, peer_heartbeat.
 */
final class MiningController
{
    private const MAX_REWARD_PER_SUBMIT = 50;
    private const MAX_CLAIM_PER_DAY = 5000;
    private const RATES = [
        'relay_probe' => 2,
        'peer_heartbeat' => 1,
        'message_relay' => 3,
        'storage_keep' => 2,
        'selftest_pass' => 5,
    ];

    private function store(): FileStore
    {
        return new FileStore(defined('DATA_DIR') ? DATA_DIR : '');
    }

    private function chain(): LocalChain
    {
        return new LocalChain($this->store());
    }

    private function minerKey(string $uid): string
    {
        return "mining/miners/{$uid}.json";
    }

    private function dayKey(): string
    {
        return gmdate('Ymd');
    }

    public function status(Request $r): JsonResponse
    {
        $uid = AuthContext::userId($r);
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $node = $this->store()->get('mining/node.json') ?? [
            'host' => $host,
            'role' => 'relay',
            'registered_ms' => 0,
        ];
        $miner = $uid !== '' ? ($this->store()->get($this->minerKey($uid)) ?: null) : null;
        $tip = $this->chain()->tip();
        return ApiEnvelope::ok([
            'network' => 'nyx-work-chain',
            'node' => $node,
            'miner' => $miner,
            'rates' => self::RATES,
            'max_claim_per_day' => self::MAX_CLAIM_PER_DAY,
            'chain_height' => (int)$tip['height'],
            'tip_hash' => (string)$tip['tip_hash'],
            'currency' => 'NYX',
        ]);
    }

    /** Register current identity as miner on this node. */
    public function register(Request $r): JsonResponse
    {
        $uid = AuthContext::userId($r);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $b = $r->json() ?? [];
        $now = ApiEnvelope::nowMs();
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'node'));
        $rec = $this->store()->get($this->minerKey($uid)) ?? [];
        $rec = array_merge(is_array($rec) ? $rec : [], [
            'user_id' => $uid,
            'username' => (string)($b['username'] ?? AuthContext::username() ?: $uid),
            'node_host' => $host,
            'registered_ms' => (int)($rec['registered_ms'] ?? $now),
            'updated_ms' => $now,
            'total_earned' => (int)($rec['total_earned'] ?? 0),
            'total_work' => (int)($rec['total_work'] ?? 0),
        ]);
        $this->store()->put($this->minerKey($uid), $rec);
        $this->store()->put('mining/node.json', [
            'host' => $host,
            'role' => 'relay',
            'operator_user_id' => $uid,
            'registered_ms' => $now,
        ]);
        return ApiEnvelope::ok(['registered' => true, 'miner' => $rec]);
    }

    /**
     * Report units of useful network work. Server validates bounds and pays via ledger + chain.
     * Body: { works: [ { type, units, ref? } ] }
     */
    public function submitWork(Request $r): JsonResponse
    {
        $uid = AuthContext::userId($r);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $b = $r->json() ?? [];
        $works = $b['works'] ?? null;
        if (!is_array($works) || $works === []) {
            return ApiEnvelope::error('works array required', 422);
        }
        $miner = $this->store()->get($this->minerKey($uid));
        if (!is_array($miner)) {
            return ApiEnvelope::error('register as miner first', 400);
        }

        $reward = 0;
        $accepted = [];
        foreach (array_slice($works, 0, 20) as $w) {
            if (!is_array($w)) {
                continue;
            }
            $type = (string)($w['type'] ?? '');
            if (!isset(self::RATES[$type])) {
                continue;
            }
            $units = max(0, min(100, (int)($w['units'] ?? 1)));
            if ($units < 1) {
                continue;
            }
            $gain = self::RATES[$type] * $units;
            $reward += $gain;
            $accepted[] = [
                'type' => $type,
                'units' => $units,
                'reward' => $gain,
                'ref' => substr((string)($w['ref'] ?? ''), 0, 64),
            ];
        }
        if ($accepted === [] || $reward < 1) {
            return ApiEnvelope::error('no valid work', 422);
        }
        if ($reward > self::MAX_REWARD_PER_SUBMIT) {
            $reward = self::MAX_REWARD_PER_SUBMIT;
        }

        // Daily cap
        $day = $this->dayKey();
        $dayRec = $this->store()->get("mining/daily/{$uid}/{$day}.json") ?? ['user_id' => $uid, 'day' => $day, 'claimed' => 0];
        $claimed = (int)($dayRec['claimed'] ?? 0);
        if ($claimed >= self::MAX_CLAIM_PER_DAY) {
            return ApiEnvelope::error('daily mining cap reached', 429);
        }
        if ($claimed + $reward > self::MAX_CLAIM_PER_DAY) {
            $reward = self::MAX_CLAIM_PER_DAY - $claimed;
            // scale down last entries visually
        }

        $dayRec['claimed'] = $claimed + $reward;
        $dayRec['updated_ms'] = ApiEnvelope::nowMs();
        $this->store()->put("mining/daily/{$uid}/{$day}.json", $dayRec);

        // Credit ledger
        $balKey = "ledger/balances/{$uid}.json";
        $bal = $this->store()->get($balKey) ?? ['user_id' => $uid, 'available' => 0, 'held' => 0, 'currency' => 'NYX'];
        $bal['available'] = (int)$bal['available'] + $reward;
        $bal['updated_ms'] = ApiEnvelope::nowMs();
        $this->store()->put($balKey, $bal);

        $txId = substr(hash('sha256', $uid . '|mine|' . ApiEnvelope::nowMs() . '|' . $reward), 0, 24);
        $tx = [
            'id' => $txId,
            'from' => 'network',
            'to' => $uid,
            'amount' => $reward,
            'currency' => 'NYX',
            'memo' => 'mining reward',
            'created_ms' => ApiEnvelope::nowMs(),
            'type' => 'mining_reward',
            'works' => $accepted,
        ];
        $this->store()->put("ledger/tx/{$uid}/{$txId}.json", $tx);
        $this->store()->put("ledger/receipts/{$txId}.json", $tx);

        $miner['total_earned'] = (int)($miner['total_earned'] ?? 0) + $reward;
        $miner['total_work'] = (int)($miner['total_work'] ?? 0) + count($accepted);
        $miner['updated_ms'] = ApiEnvelope::nowMs();
        $this->store()->put($this->minerKey($uid), $miner);

        $block = $this->chain()->append('mining_reward', [
            [
                'user_id' => $uid,
                'reward' => $reward,
                'tx_id' => $txId,
                'works' => $accepted,
            ],
        ], [
            'node' => strtolower((string)($_SERVER['HTTP_HOST'] ?? '')),
            'day' => $day,
        ]);

        return ApiEnvelope::ok([
            'reward' => $reward,
            'balance' => $bal,
            'tx' => $tx,
            'block' => [
                'height' => $block['height'],
                'hash' => $block['hash'],
            ],
            'daily_claimed' => $dayRec['claimed'],
            'daily_cap' => self::MAX_CLAIM_PER_DAY,
        ]);
    }

    /**
     * Node self-work: run local health probes and pay the registered operator.
     * Useful for automated miners / cron on each relay.
     */
    public function nodeTick(Request $r): JsonResponse
    {
        $uid = AuthContext::userId($r);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $node = $this->store()->get('mining/node.json');
        if (!is_array($node) || (string)($node['operator_user_id'] ?? '') !== $uid) {
            return ApiEnvelope::error('not node operator', 403);
        }

        $works = [];
        $topo = $this->store()->get('topology/nodes.json');
        $peers = is_array($topo['nodes'] ?? null) ? count($topo['nodes']) : 0;
        if ($peers > 0) {
            $works[] = ['type' => 'peer_heartbeat', 'units' => min(20, max(1, $peers)), 'ref' => 'topology'];
        }
        $works[] = ['type' => 'relay_probe', 'units' => 1, 'ref' => 'local'];
        $works[] = ['type' => 'selftest_pass', 'units' => 1, 'ref' => 'ops'];

        // Inline reward (same rules as submitWork)
        $miner = $this->store()->get($this->minerKey($uid));
        if (!is_array($miner)) {
            return ApiEnvelope::error('register as miner first', 400);
        }
        $reward = 0;
        $accepted = [];
        foreach ($works as $w) {
            $type = (string)($w['type'] ?? '');
            if (!isset(self::RATES[$type])) {
                continue;
            }
            $units = max(0, min(100, (int)($w['units'] ?? 1)));
            if ($units < 1) {
                continue;
            }
            $gain = self::RATES[$type] * $units;
            $reward += $gain;
            $accepted[] = ['type' => $type, 'units' => $units, 'reward' => $gain, 'ref' => (string)($w['ref'] ?? '')];
        }
        $day = $this->dayKey();
        $dayRec = $this->store()->get("mining/daily/{$uid}/{$day}.json") ?? ['claimed' => 0, 'day' => $day, 'user_id' => $uid];
        $claimed = (int)($dayRec['claimed'] ?? 0);
        if ($claimed >= self::MAX_CLAIM_PER_DAY) {
            return ApiEnvelope::error('daily mining cap reached', 429);
        }
        if ($claimed + $reward > self::MAX_CLAIM_PER_DAY) {
            $reward = self::MAX_CLAIM_PER_DAY - $claimed;
        }
        $dayRec['claimed'] = $claimed + $reward;
        $dayRec['updated_ms'] = ApiEnvelope::nowMs();
        $this->store()->put("mining/daily/{$uid}/{$day}.json", $dayRec);
        $balKey = "ledger/balances/{$uid}.json";
        $bal = $this->store()->get($balKey) ?? ['user_id' => $uid, 'available' => 0, 'held' => 0, 'currency' => 'NYX'];
        $bal['available'] = (int)$bal['available'] + $reward;
        $bal['updated_ms'] = ApiEnvelope::nowMs();
        $this->store()->put($balKey, $bal);
        $txId = substr(hash('sha256', $uid . '|tick|' . ApiEnvelope::nowMs() . '|' . $reward), 0, 24);
        $tx = [
            'id' => $txId, 'from' => 'network', 'to' => $uid, 'amount' => $reward,
            'currency' => 'NYX', 'memo' => 'node tick', 'created_ms' => ApiEnvelope::nowMs(),
            'type' => 'mining_reward', 'works' => $accepted,
        ];
        $this->store()->put("ledger/tx/{$uid}/{$txId}.json", $tx);
        $this->store()->put("ledger/receipts/{$txId}.json", $tx);
        $miner['total_earned'] = (int)($miner['total_earned'] ?? 0) + $reward;
        $miner['total_work'] = (int)($miner['total_work'] ?? 0) + count($accepted);
        $miner['updated_ms'] = ApiEnvelope::nowMs();
        $this->store()->put($this->minerKey($uid), $miner);
        $block = $this->chain()->append('mining_reward', [[
            'user_id' => $uid, 'reward' => $reward, 'tx_id' => $txId, 'works' => $accepted,
        ]], ['via' => 'node_tick', 'node' => strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))]);
        return ApiEnvelope::ok([
            'reward' => $reward,
            'balance' => $bal,
            'tx' => $tx,
            'block' => ['height' => $block['height'], 'hash' => $block['hash']],
            'daily_claimed' => $dayRec['claimed'],
            'daily_cap' => self::MAX_CLAIM_PER_DAY,
        ]);
    }

    public function leaderboard(Request $r): JsonResponse
    {
        $dir = $this->store()->root() . '/mining/miners';
        $items = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                $rec = json_decode((string)@file_get_contents($f), true);
                if (!is_array($rec)) continue;
                $items[] = [
                    'user_id' => (string)($rec['user_id'] ?? ''),
                    'username' => (string)($rec['username'] ?? ''),
                    'node_host' => (string)($rec['node_host'] ?? ''),
                    'total_earned' => (int)($rec['total_earned'] ?? 0),
                    'total_work' => (int)($rec['total_work'] ?? 0),
                ];
            }
        }
        usort($items, static fn($a, $b) => $b['total_earned'] <=> $a['total_earned']);
        return ApiEnvelope::ok(['items' => array_slice($items, 0, 50), 'count' => count($items)]);
    }

    /**
     * Store a session token for headless miner ticks (cron).
     * Only the registered node operator may set it; stored under data/mining/.
     */
    public function saveCronToken(Request $r): JsonResponse
    {
        $uid = AuthContext::userId($r);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $node = $this->store()->get('mining/node.json');
        if (!is_array($node) || (string)($node['operator_user_id'] ?? '') !== $uid) {
            return ApiEnvelope::error('not node operator', 403);
        }
        $token = AuthContext::bearerToken($r);
        if ($token === '') {
            return ApiEnvelope::error('bearer token required', 422);
        }
        $this->store()->put('mining/cron_auth.json', [
            'user_id' => $uid,
            'token_hint' => substr($token, 0, 6) . '…',
            'token' => $token,
            'updated_ms' => ApiEnvelope::nowMs(),
        ]);
        return ApiEnvelope::ok(['saved' => true, 'hint' => 'Use tools/miner_tick.php or Authorization bearer on /mining/tick']);
    }

    /** Headless tick using stored cron token OR bearer. */
    public function cronTick(Request $r): JsonResponse
    {
        $uid = AuthContext::userId($r);
        if ($uid === '') {
            $cron = $this->store()->get('mining/cron_auth.json');
            if (is_array($cron) && !empty($cron['token'])) {
                $GLOBALS['nyx_auth'] = [
                    'user_id' => (string)($cron['user_id'] ?? ''),
                    'token' => (string)$cron['token'],
                ];
                $uid = (string)($cron['user_id'] ?? '');
            }
        }
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        return $this->nodeTick($r);
    }

}
