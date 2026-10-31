# NYX Relay — Node Operations Manual

## Architecture (hexagonal)

```
public/          HTTP entry (index.php, HTML clients)
routes/api/v3/   Route map → Controllers
app/
  Core/          Kernel, routing, security headers, env
  Modules/       Identity, Messaging, Conversations, Presence,
                 Federation, Crypto, Devices, Search, Admin…
  Shared/        FileStore, SqliteStore, MessageIntegrity, envelopes
data/            Runtime JSON + sqlite tips/index
```

**Protocol:** 3 (E2EE-aware messaging; server strips plaintext when ciphertext present on DMs).

---

## Core HTTP surfaces

| Area | Examples |
|------|----------|
| Auth | `POST /api/v1/auth/login`, `GET /api/v3/auth/me` |
| DM | `POST /api/v3/dm/open` (`peer` | `username` | `user_id`) |
| Messages | `POST /api/v3/messages/send`, `GET /api/v3/messages/sync` |
| Spaces | `GET /api/v3/spaces/mine`, `POST /api/v3/spaces/create`, members, invite |
| Security | sessions, recovery-codes, step-up, freeze |
| Crypto | keys, safety-number, conversation badge, status |
| Search | `GET /api/v3/search/global?q=` |
| Ops | `GET /api/v3/ops/health`, `GET /api/v3/ops/info` |
| Federation | peers, topology |
| Presence | heartbeat, typing, batch / status |

All authenticated routes expect:

```http
Authorization: Bearer <session-token>
```

---

## Data layout

```
data/
  install.lock          # wizard disabled
  nyx.sqlite            # optional index / tips
  users/*.json
  identity/usernames/*.json
  spaces/*.json
  memberships/{space_id}/*.json
  messages/{conv_id}/…
  sessions/
  security/
  crypto/
```

**Rule:** display names are resolved server-side on send/sync (`display_name`) so clients do not spam `/users/get`.

---

## Federation

- Enable with `FEDERATION_ENABLED=true`.
- Share `FEDERATION_SECRET` only with trusted peers.
- Peers list: `GET /api/v3/federation/peers`.
- Topology: `GET /api/v3/federation/topology`.

Mesh limits (env): `MESH_MAX_FRIENDS`, `MESH_MAX_PEERS`.

---

## Backups

Minimum consistent backup set:

1. `data/` (entire tree)
2. `.env`
3. Optional: `storage/logs` for forensics

Restore = stop writers → replace `data/` + `env` → health check.

---

## Troubleshooting

| Symptom | Check |
|---|--------|
| Install always open | Missing `data/install.lock` / writable data |
| 500 on API | `storage/logs`, PHP error log, `.env` `APP_KEY` |
| DM “unavailable” in UI | Client membership vs `user_id`/`username` |
| Raw sender hashes | Message enrichment / compact fields |
| 522 from CDN | Origin timeout — not app logic |

---

## Versioning

- `NYX_VERSION` in `.env` / `ops/info`
- UI cache-bust with `?v=` query on static HTML when deploying client updates
