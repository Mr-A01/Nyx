# NYX Relay · 1.45.0-modular

Federated E2EE messenger relay (Protocol 3) — modular MVC / hexagonal layout.

## Structure

```
NYX/
├── app/
│   ├── Core/                 # HTTP, routing, container, security
│   ├── Modules/              # Business modules (Messaging, Identity, Federation, …)
│   └── Shared/               # Shared infrastructure
├── public/                   # Web document root
│   ├── index.php             # Front controller
│   ├── install.php           # One-time PHP installer
│   ├── client.html / status.html / identity.html
│   └── vendor/
├── routes/api/v3/            # API route maps
├── storage/ · data/          # Runtime data (writable)
├── docker/ · docker-compose.yml
├── docs/ · tests/ · config/
└── .env.example
```

## Install — shared hosting (PHP)

1. Upload so the **document root** is `public/` (or keep parent `.htaccess` rewrite).
2. Open `https://your-domain/install.php` and fix any failed checks.
3. Delete `public/install.php`.
4. Copy `.env.example` → `.env` if missing; set secrets.
5. Open `/` or `/client.html`.

Requires PHP **8.2+** with `sodium`, `sqlite3`/`pdo_sqlite`, `curl`, `mbstring`, `json`.

## Install — Docker

```bash
docker compose up -d --build
# → http://localhost:8080
```

Standalone single container:

```bash
docker build -f docker/Dockerfile.standalone -t nyx-relay .
docker run --rm -p 8080:8080 -v nyx-data:/app/storage/data nyx-relay
```

## Health

`GET /api/v3/ops/health` → `{ ok: true, version, peers_configured, ... }`

## Security notes

- E2EE is client-side (Sodium / WebCrypto); relay stores ciphertext only for E2EE rooms.
- Never commit `.env` or `storage/data/*` user content.
- Remove `install.php` after setup.
