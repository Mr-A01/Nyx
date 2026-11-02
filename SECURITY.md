# Security Policy

## Supported versions
`main` branch on GitHub and the production relay mesh.

## Reporting
1. Prefer a **private** channel to the maintainers when the issue is exploitable.
2. Otherwise open a GitHub issue with label guidance from maintainers — omit weaponized payloads.
3. Track remediation in `docs/SECURITY_AUDIT_49.md` when appropriate.

## Hardening expectations
- No client-controlled account identifiers on register
- Conversation membership checks on read/write paths
- Rate limits · path jail on storage · optional peer signatures on federation
