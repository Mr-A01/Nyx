# NYX Mining & Work-Chain

## Overview
Relay operators earn **NYX** credits for useful network work. Rewards are written to the off-chain ledger and sealed into a local **work-chain** (hash-linked, optionally operator-signed blocks).

> This is **not** global consensus. It is verifiable local history + mesh observability toward settlement.

## Work types
| Type | Rate (NYX / unit) |
|------|-------------------|
| relay_probe | 2 |
| peer_heartbeat | 1 |
| message_relay | 3 |
| storage_keep | 2 |
| selftest_pass | 5 |

Limits:
- Max **50 NYX** per submit
- Max **5000 NYX** per miner per UTC day

## Anti-abuse
| Control | Behavior |
|---------|----------|
| Session auth | Required |
| Daily cap | Enforced per user |
| `work_sig` | HMAC-SHA256 of canonical works with session token; invalid → 403. Require with `NYX_REQUIRE_WORK_SIG=true` |
| `nonce` | Optional unique string; replay → 409. Require with `NYX_REQUIRE_WORK_NONCE=true` |
| `ts_ms` | If present, must be within ±5 minutes |
| `ref` | Same type+ref cannot be claimed twice |

## Operator flow
1. Login → Bearer session  
2. `POST /api/v3/mining/register`  
3. `POST /api/v3/mining/submit`:
```json
{
  "works": [{ "type": "relay_probe", "units": 1, "ref": "probe-001" }],
  "nonce": "random-unique-string",
  "ts_ms": 1730000000000,
  "work_sig": "<hmac>"
}
```
4. Optional node tick: `POST /api/v3/mining/tick`

## Storage model
- **Primary:** JSON `FileStore` under `data/`
- **Optional index:** SQLite when `MESSAGE_DB=sqlite` (messages/tips only; never source of truth)

## Chain mesh
- `GET /api/v3/chain/tip`
- `GET /api/v3/chain/operator` — Ed25519 operator pubkey
- `POST /api/v3/chain/announce`
