# Issue #49 — Remediation tracker

## Batch 1 (merged)
- Account takeover via client user_id on register
- messages/events unauthenticated leak
- sync/wait explicit 401
- forward ACL + no global scan
- federation SSRF + gossip hardening
- Space actor_id body spoof
- strict device omit-header

## Batch 2 (this PR)
- Schedule path only after canWrite + E2EE policy
- Collaborative edit type from stored body only
- MessageExtras: auth-only uid, schedule ACL/E2EE, thread canRead
- Devices: list/touch/revoke ownership
- Ledger transfer file locks (ordered)
- AccessGuard posting=admins enforced (owner/admin/mod only)
- clientIp: X-Forwarded-For only if NYX_TRUST_PROXY=true
- Attachments meta requires session owner
- Mining per-submit reward cap

## Still open / partial
- Full mining work verification (protocol-level proofs)
- Settlement multi-party finalize policy
- Push subscription edge cases
- Rate-limit nested GC completeness
- SQLite vs FileStore dual-store consistency
