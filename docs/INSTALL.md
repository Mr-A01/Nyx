# Install NYX node v2.3.1

## Requirements
- PHP 8.1+ (`json`, `mbstring`, `curl`, `openssl`, `fileinfo`)
- Writable `data/` directory
- HTTPS recommended

## Steps

```bash
bash install/install.sh /path/to/nyx
mkdir -p /path/to/nyx/data
chmod -R 775 /path/to/nyx/data
```

Or copy manually:

```text
src/*.php, src/*.html  →  /nyx/
public/client.html     →  /nyx/client.html
public/vendor/sodium.js → /nyx/public/vendor/sodium.js
```

## Health check

```bash
curl -s https://YOUR-DOMAIN/nyx/api/v3/ops/health
```

Expected: `"ok": true`, `protocol: 3`.

## Client-only update

Replace `client.html` (+ `public/client.html` if used) and `sodium.js`.
