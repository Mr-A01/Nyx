# NYX Relay — Installation Guide

**Version:** 1.45.0-scale · **Protocol:** 3

This document describes how to deploy a **new NYX node** (PHP relay) from zero to production, including the **first-run wizard**, **Docker**, and **manual PHP** install.

---

## 1. What you get

| Component | Path | Role |
|-----------|------|------|
| Messenger UI | `public/client.html` | Live client |
| Identity UI | `public/identity.html` | Create / unlock identity |
| API | `routes/api/v3/modular.php` via `public/index.php` | REST v3 |
| Data | `data/` | Users, spaces, messages, lock file |
| Install wizard | `public/install/` | First-run only — **self-disables** |

---

## 2. Requirements

### Minimum

- PHP **8.1+** (8.2 recommended)
- Extensions: `json`, `openssl`, `pdo_sqlite` *(or `pdo_mysql`)*
- Recommended: `mbstring`, `curl`, `opcache`
- Apache with `mod_rewrite` **or** nginx + PHP-FPM
- Writable `data/` and `storage/`

### Optional

- Redis (scale cache / tips)
- MySQL 8+ / MariaDB 10.5+ instead of SQLite
- TLS terminator (Cloudflare, Caddy, nginx)

---

## 3. Quick start — PHP installer wizard

1. Upload the NYX tree so the web server document root points at **`public/`**  
   (or host under `/nyx/` with the included root `.htaccess` rewrite rules).

2. Ensure directories are writable by the PHP user:

```bash
mkdir -p data storage/logs
chmod -R 750 data storage
chown -R www-data:www-data data storage   # adjust user
```

3. Open the wizard in a browser:

```text
https://YOUR-DOMAIN/nyx/install/
# or if docroot is public/:
https://YOUR-DOMAIN/install/
```

4. Confirm requirements → set **APP_URL** → choose SQLite or MySQL → **Install & lock wizard**.

5. **Save** the displayed `ADMIN_TOKEN` and `FEDERATION_SECRET`.

6. Wizard creates:

- `.env`
- `data/install.lock`
- `public/install/INSTALLED`
- `public/install/.htaccess` → **Deny from all**

Further HTTP access to `/install/` returns **403**. To re-run install you must manually delete `data/install.lock` and `public/install/INSTALLED` (not recommended on a live node).

7. Open:

- Identity: `/identity.html`
- Messenger: `/client.html`
- Health: `/api/v3/ops/health`

---

## 4. Docker install

Files (shipped under `docker/`):

- `Dockerfile` — PHP 8.2 Apache, docroot `public/`
- `docker-compose.yml` — port **8080**, persistent volumes
- `.dockerignore`

### Build & run

From the **NYX repository root**:

```bash
docker compose -f docker/docker-compose.yml up -d --build
```

Open:

```text
http://localhost:8080/install/
```

Complete the wizard. Data persists in Docker volumes `nyx_data` / `nyx_storage`.

### Health

```bash
curl -s http://localhost:8080/api/v3/ops/health
```

### Stop / logs

```bash
docker compose -f docker/docker-compose.yml logs -f nyx
docker compose -f docker/docker-compose.yml down
```

---

## 5. Manual `.env` (without wizard)

Copy example and edit:

```bash
cp .env.example .env
```

Minimum keys:

```env
APP_NAME=NYX
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com/nyx
APP_KEY=base64:GENERATE_32_BYTES_BASE64

DB_DRIVER=sqlite
DB_DATABASE=data/nyx.sqlite

FEDERATION_ENABLED=true
ADMIN_TOKEN=long-random
FEDERATION_SECRET=long-random
```

Generate key:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
```

Then create the lock so the wizard stays off:

```bash
mkdir -p data
echo '{"installed_at":"manual","disabled":true}' > data/install.lock
echo 'disabled' > public/install/INSTALLED
printf 'Require all denied\nDeny from all\n' > public/install/.htaccess
```

---

## 6. Web server notes

### Apache

- `AllowOverride All` on `public/`
- Root `.htaccess` blocks `data|app|config|…` and routes UI to `public/`

### nginx (sketch)

```nginx
server {
  listen 443 ssl;
  server_name example.com;
  root /var/www/nyx/public;
  index index.php index.html;

  location / {
    try_files $uri $uri/ /index.php?$query_string;
  }
  location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
  }
  location ~ ^/(data|app|config|storage|routes|bootstrap)/ {
    deny all;
  }
}
```

---

## 7. First account

1. Open **Identity** → Create an Identity (or Sign In).
2. Unlock → redirect to **Messenger** (`client.html`).
3. Environment must stay **LIVE** (not demo).

API login (automation):

```bash
curl -s -X POST https://YOUR/nyx/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"alice","password":"…"}'
```

---

## 8. Post-install checklist

- [ ] `/api/v3/ops/health` → `status: ok`
- [ ] `/install/` → **403** / disabled page
- [ ] TLS enabled
- [ ] `APP_DEBUG=false`
- [ ] Backups of `data/` scheduled
- [ ] Admin token stored offline
- [ ] Federation peers configured only if needed

---

## 9. Upgrades

1. Put node in maintenance if required.
2. Backup `data/` and `.env`.
3. Replace `app/`, `public/`, `routes/`, `bootstrap/` from release.
4. **Do not** delete `data/install.lock` unless you intend to re-run the wizard.
5. Hit health endpoint.

---

## 10. Security reminders

- Never expose `data/`, `.env`, or package zip files.
- Installer must remain locked in production.
- Rotate `ADMIN_TOKEN` / `FEDERATION_SECRET` if leaked.
- Prefer SQLite only for single-node / moderate load; use MySQL + Redis for scale.
