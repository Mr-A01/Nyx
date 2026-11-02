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

## Batch 3
- Push/notifications residual user_id body fallbacks removed
- Space get: private spaces require canRead; members requires membership
- Mining optional work_sig (HMAC with session token); NYX_REQUIRE_WORK_SIG
- Settlement mark: only batch creator; finalize needs confirm_finalize
- Dockerfile + GHCR workflow (#50)

## Batch 4
- PushController fully session-bound (subscribe/unsubscribe/list/prefs/markRead)
- Attachment download requires owner (or public flag)
- GitHub Pages site under docs/site + scoped workflow

## Batch 5
- Mining: nonce replay protection, timestamp window, ref dedup
- SqliteStore documented as optional secondary index only
- MINING.md updated with anti-abuse controls
