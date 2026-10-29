# Implementation status (live)

## Protocol surface (usable now)

| Capability | Path | Backend |
|------------|------|---------|
| Health / limits | `GET /` | Legacy relay |
| Federation topology | `GET /api/v3/federation/topology` | Legacy + mesh |
| Update check | `GET /api/v3/ops/update-check` | Legacy + update engine |
| Identity API | `/api/v1/*` | Legacy identity_api |
| Web client | `/client.html` | Static |
| Status UI | `/status.html` | Static |

## Modular surface (`/api/v3/modular/*`)

| Endpoint | Status |
|----------|--------|
| GET health | ✅ |
| GET selftest | ✅ |
| POST messages/send | ✅ |
| POST presence/touch | ✅ |
| POST devices/register | ✅ |
| POST moderation/report | ✅ |
| POST attachments/meta | ✅ |
| POST privacy/erase | ✅ (admin token) |

## Security controls shipped

- Security headers (nosniff, frame deny, referrer, permissions-policy, HSTS on HTTPS)
- Env-based secrets (`ADMIN_TOKEN`, `FEDERATION_SECRET`, `APP_KEY`)
- AdminAuthenticator + token bootstrap/rotate
- PeerAuthenticator HMAC helper
- InputValidator (length, id charset, URL scheme)
- PathGuard (no `..`)
- SodiumBox encrypt/decrypt
- RateLimiter component
- Audit append-only logs
- storage/ denied via `.htaccess`

## Known environment notes

- Some shared hosts lack `mbstring` / `curl` / `pdo_sqlite` — code degrades safely (strlen fallback; sqlite optional).
