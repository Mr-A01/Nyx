# NYX Issue Tracker (mr.a)

Reference IDs in commits: `Fixes NYX-xxx`.

## Open
| ID | Status | Title | Severity |
|----|--------|-------|----------|
| — | — | none | — |

## Closed
| ID | Fixed in | Title |
|----|----------|-------|
| NYX-008 | mr.a | Group create returns solo_owner + invite_hint |
| NYX-007 | mr.a | Attachment dir checked in selftest; upload routes verified |
| NYX-006 | mr.a | Offline sodium roundtrip test (`tests/unit/SodiumRoundtripTest.php`) |
| NYX-005 | mr.a | CSP + object-src none + upgrade-insecure-requests (SPA still needs inline) |
| NYX-004 | mr.a | Discover enriches owner_username / owner_display (no more silent @unknown) |
| NYX-002 | mr.a | Root HTML stubs intentional (rewrite → public/); not user-facing pages |
| NYX-003 | mr.a | Deep selftest (storage, sodium, client, random, attachments_dir) |
| NYX-001 | mr.a | Password min aligned to 15 (UI + server) |
| NYX-000 | 1.45.0-scale | `/?json=1` returned HTML due to DirectoryIndex index.html |

## Notes
- NYX-002: real UI lives only under `public/`. Root `*.html` are placeholders so misconfigured DirectoryIndex cannot serve stale pages.
- NYX-005: full removal of `unsafe-inline` needs SPA nonce pipeline — future hardening, not a defect.
