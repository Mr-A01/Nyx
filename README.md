# NYX Mesh Node — v2.3.1 Stable

Self-hosted federated messenger relay + web client (unified build).

## Contents

- `public/client.html` — Messenger UI (latest)
- `public/vendor/sodium.js` — E2EE library
- `src/` — PHP relay core, identity UI, status UI
- `docs/INSTALL.md` — Install steps
- `install/install.sh` — Quick copy installer

## Quick install

```bash
bash install/install.sh /var/www/html/nyx
chmod -R 775 /var/www/html/nyx/data
```

## Verify

```bash
curl -s https://YOUR-DOMAIN/nyx/api/v3/ops/health
# → "ok": true
```

Open: `https://YOUR-DOMAIN/nyx/client.html`

## Reference node

https://acebet90.co/nyx/client.html
