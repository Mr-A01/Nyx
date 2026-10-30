# NYX Issue Tracker (mr.a)

Tracked in-repo until GitHub Issues UI is used interactively.  
Reference IDs in commits: `Fixes NYX-xxx`.

| ID | Status | Title | Severity |
|----|--------|-------|----------|
| NYX-001 | closed | Password policy mismatch (UI 15 vs server config 12) | medium |
| NYX-002 | open | Static HTML stubs remain at package root on hosts | low |
| NYX-003 | closed | Self-test payload too shallow (only peers+version) | medium |
| NYX-004 | open | Discover shows `@unknown` for public group owners | low |
| NYX-005 | open | CSP still allows `unsafe-inline` / `unsafe-eval` | medium |
| NYX-006 | open | No automated E2EE cross-device test in CI | high |
| NYX-007 | open | Attachment upload path not verified in live QA | medium |
| NYX-008 | open | Group create allows 0 extra members without confirmation UX polish | low |

## Closed
| ID | Fixed in | Title |
|----|----------|-------|
| NYX-001 | mr.a | Password min aligned to 15 (UI + server) |
| NYX-003 | mr.a | Deep selftest (storage, sodium, client, random) |
| NYX-000 | 1.45.0-scale | `/?json=1` returned HTML due to DirectoryIndex index.html |

