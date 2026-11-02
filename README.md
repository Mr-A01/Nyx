# NYX Messenger Pro

Federated **E2EE** messenger relay with a clean hexagonal / modular architecture.

## Highlights

- Protocol 3 relay with mesh federation & self-healing topology
- E2EE (libsodium) · identity · devices · conversations · messaging
- Secure signed updates · rate limiting · audit · privacy tools
- Shared-hosting friendly (`public/` as document root) **or** Docker/K8s

## Quick start (shared hosting)

1. Upload project so web root points at **`public/`**
2. `chmod -R 750 storage bootstrap/cache`
3. Copy `.env.example` → `.env` and set secrets
4. Open `/` (health) · `/client.html` · `/identity.html` · `/api/v3/...`

## Quick start (local)

```bash
composer install
cp .env.example .env
php -S 0.0.0.0:8080 -t public
```

## Architecture

See `docs/architecture/OVERVIEW.md` and `MODULE-MAP.md`.

| Layer | Role |
|-------|------|
| `app/Core` | Framework kernel (HTTP, routing, DI, security, queue…) |
| `app/Modules/*` | Business capabilities (Identity, Messaging, Federation…) |
| `app/Shared` | Cross-cutting domain primitives |
| `public/` | Web entry only |
| `legacy/` | Optional bridge to standalone relay |

## Namespace

`Nyx\` → `app/` (PSR-4)

## License

Proprietary — all rights reserved.


## Implementation strategy

1. **Parity first** — all Protocol 3 APIs run through `LegacyRelayKernel` (same code as production nodes).
2. **Componentize** — Core + Shared abstractions (`FileStore`, `RateLimiter`, Mesh/Update adapters).
3. **Extract modules** — move one route group at a time behind feature tests.
4. **Never dual-write blindly** — storage format stays identical (`storage/data`).

See `docs/architecture/IMPLEMENTATION-STATUS.md`.

## Docker

```bash
docker pull ghcr.io/mr-a01/nyx:latest
docker run -p 8080:80 -v nyx-data:/var/www/html/data ghcr.io/mr-a01/nyx:latest
```

Image builds on push to `main` via GitHub Actions → GHCR.
