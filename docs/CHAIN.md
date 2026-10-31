# NYX Work-Chain (v2)

## What it is
An **append-only, hash-linked, Merkle-committed** log of useful network work (mining rewards, etc.) kept **per relay**.

It is **not** a global BFT/PoW consensus chain shared by all users. It is designed so each node can:
1. Prove local reward history (`/chain/verify`)
2. Export portable batches (`/settlement/export`)
3. Gossip tips to peers (`/chain/announce`)

## Block v2 fields
| Field | Meaning |
|-------|---------|
| version | `2` |
| network | `nyx-work-chain` |
| height | monotonic |
| prev_hash | parent block hash (genesis = 64 zeros) |
| merkle_root | Merkle root of `entries[]` |
| work_score | sum of rewards in entries |
| hash | SHA-256 of canonical JSON (without `hash`) |

Legacy v1 blocks remain verifiable with the previous hash formula.

## API
- `GET /api/v3/chain/tip`
- `GET /api/v3/chain/block?height=`
- `GET /api/v3/chain/range?from=&to=`
- `GET /api/v3/chain/verify?depth=` (`0` = full)
- `GET /api/v3/chain/stats`
- `POST /api/v3/chain/reindex` (auth)
- `POST /api/v3/chain/gossip` / `announce` / `GET mesh`
