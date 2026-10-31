# NYX Settlement Bridge

## Purpose
Export **portable, hash-committed batches** of local `nyx-work-chain` blocks and ledger receipts so an operator can later submit them to an L1/L2 without forcing end-user wallets.

## Format
`nyx-settlement-batch-v1` JSON:
- `blocks[]` — work-chain segment (`from_height`…`to_height`)
- `receipts[]` — ledger receipts referenced by `tx_id` in block entries
- `entry_merkle` — Merkle root over entry digests
- `content_hash` — SHA-256 of canonical payload
- `bridge` — `{ target, tx_ref, submitted_ms, finalized_ms }`

## API
| Method | Path | Auth | Role |
|--------|------|------|------|
| GET | `/api/v3/settlement/status` | no | readiness summary |
| GET | `/api/v3/settlement/batches` | no* | list summaries |
| GET | `/api/v3/settlement/batch?id=` | no* | full batch |
| POST | `/api/v3/settlement/export` | yes | build + store batch |
| POST | `/api/v3/settlement/mark` | yes | set submitted/finalized + tx_ref |

\* Public read enables transparency; treat full receipt bodies as semi-public operational data on this mesh.

## Operator flow
1. Ensure mining/work has advanced chain height
2. `POST /settlement/export` with optional `from_height`, `to_height`, `max_blocks`
3. Archive `content_hash` / `entry_merkle`
4. Submit to external bridge (manual/oracle) → `POST /settlement/mark` with `status=submitted|finalized` and `tx_ref`

## Non-goals (this version)
- No on-chain RPC client embedded in PHP shared hosting
- No automatic token mint on L1
- No consensus across NYX nodes (each node exports its local chain)
