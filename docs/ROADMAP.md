# NYX Messenger Pro — Roadmap & Recommendations

## Already production-capable

- Full Protocol 3 relay (legacy kernel parity)
- Mesh / federation / updates / identity APIs
- Web client + status + identity pages
- Modular Core (DI, router, validation, rate limit, security headers, sodium helpers)
- Modular features: health, selftest, messages send, presence, devices, moderation reports, attachment meta, privacy erase (admin)
- Audit log append-only
- `.env` secrets, path guards, admin/peer auth helpers

## Recommended next iterations (priority)

### P0 — Security & ops
1. **Content-Security-Policy** tuned for client.html (strict nonces)
2. **WAF allowlist** for deploy IPs; remove export PHP scripts from webroot after use
3. **Backup job**: nightly zip of `storage/data` off-box
4. **Admin token rotation** schedule + alert if default/empty
5. **Dependency pin**: `composer.lock` + CI phpstan level 6

### P1 — Product completeness
1. Extract **Messaging sync/edit/delete** from relay into modules with parity tests
2. **E2EE key directory** (prekeys) modular service
3. **Web Push** VAPID delivery queue worker (`bin/worker`)
4. **Search FTS** rebuild + query module
5. **Attachment binary upload** with virus-scan hook + size quotas per user

### P2 — Scale & federation
1. Peer **anti-entropy** dashboard in status.html
2. **Topology auto-heal** cron (`bin/cron-tick`)
3. Multi-region **read replicas** for SQLite→Postgres migration path
4. Observability: OpenTelemetry traces on federation hops

### P3 — UX
1. Split `client.html` into ES modules under `public/assets/js`
2. Service worker offline outbox
3. FA/EN i18n wiring from `resources/lang`

## Architecture rule

> Ship features behind parity tests against `legacy/relay.php`. Never remove a legacy route until modular coverage ≥ legacy behavior for that route group.
