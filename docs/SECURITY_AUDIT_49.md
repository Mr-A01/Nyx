# Issue #49 — Remediation tracker (closed)

Audited against production modular tree. Critical and high items addressed across batches 1–6.

## Resolved (shipped on `main`)
| Area | Fix |
|------|-----|
| Account takeover via client `user_id` | Server-only IDs on register |
| Unauthenticated message events / sync gaps | Session + AccessGuard |
| Forward / thread / schedule ACL + E2EE order | Auth + canRead/canWrite |
| Collaborative edit marker bypass | Type from stored body only |
| Federation SSRF + gossip plaintext | URL allowlist + peer HMAC option + strip body |
| Devices / push / attachments | Ownership checks |
| Ledger transfer races | Ordered file locks |
| Admin-only posting policy | AccessGuard role check |
| X-Forwarded-For spoofing | `NYX_TRUST_PROXY` gate |
| Device bind omit-header | Strict mode rejects |
| Settlement finalize | Creator + confirm flag |
| Mining abuse | ActivityMeter + caps + nonce/ref anti-replay |

## Residual / product backlog (not open critical vulns)
- Protocol-level proofs beyond activity metering (future economics)
- Full dual-write SQLite cutover (FileStore remains source of truth by design)
- Push provider integration edge cases

See also root [SECURITY.md](../SECURITY.md).
