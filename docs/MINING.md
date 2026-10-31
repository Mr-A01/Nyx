# NYX Mining & Work-Chain

## Overview
Relay operators earn **NYX** credits for useful network work. Rewards are written to the off-chain ledger and sealed into a local **work-chain** (hash-linked blocks).

## Work types
| Type | Rate |
|------|------|
| relay_probe | 2 |
| peer_heartbeat | 1 |
| message_relay | 3 |
| storage_keep | 2 |
| selftest_pass | 5 |

Daily cap: **5000 NYX** per miner.

## Operator flow
1. Create identity / login → session token
2. `POST /api/v3/mining/register`
3. `POST /api/v3/mining/submit` with `{ "works": [ { "type": "relay_probe", "units": 1 } ] }`
4. Or automated: `POST /api/v3/mining/tick` (operator only)

## Cron
```bash
php tools/miner_tick.php https://YOUR_DOMAIN/nyx "$SESSION_TOKEN"
# every 15 minutes
*/15 * * * * php /path/to/nyx/tools/miner_tick.php https://YOUR_DOMAIN/nyx "$TOKEN"
```

Optional: `POST /api/v3/mining/cron-token` with operator bearer to store token server-side, then `POST /api/v3/mining/cron`.

## Chain mesh
- `GET /api/v3/chain/tip` — local tip
- `POST /api/v3/chain/gossip` — accept peer tip
- `GET /api/v3/chain/mesh` — local + peers
- `POST /api/v3/chain/announce` — push tip to topology peers

This is **not** global consensus yet; it is verifiable local history + mesh observability toward settlement (#33).
