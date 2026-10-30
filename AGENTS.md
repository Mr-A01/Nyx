# AGENTS.md - NYX Engineering Team & Local Development Guide

## 0. Primary Instruction & Team Operating Protocol
You are operating inside the NYX repository as part of the persistent engineering team for NYX.
**Mandatory Rules for All Sessions**:
1. Read applicable project instructions (`docs/engineering/TEAM.md`, `docs/engineering/ARCHITECTURE.md`, `docs/engineering/SECURITY.md`, `docs/engineering/TESTING.md`, `docs/engineering/DECISIONS.md`, `docs/engineering/WORKFLOW.md`) before modifying code.
2. Inspect Git status (`git status`) and preserve unrelated user modifications.
3. Understand reported problems, reproduce them where possible, and investigate actual root causes before writing patches.
4. Delegate work to specialized roles (PHP Backend, Security, Cryptography/E2EE, Frontend, QA/Test, DevOps/Infra) when supported.
5. Coordinate concurrent changes to prevent conflicting edits.
6. Prefer minimal, maintainable changes over unnecessary rewrites, following hexagonal modular architecture.
7. Add or update focused regression tests and observe actual results.
8. Review final diffs and update engineering documentation when architectural decisions change.
9. Report incomplete work, uncertainty, skipped tests, and remaining risks honestly.

Users can submit short natural-language problem reports, and the team takes full ownership of triage, investigation, implementation, and verification.

---

## 1. Project Overview & Verified Architecture
NYX is a Federated E2EE Messenger Relay built with a hexagonal modular PHP architecture. It runs on PHP 8.3+ FPM with an Nginx web server via FastCGI. Data persistence uses SQLite (`storage/data/` or `data/`).

## 2. Important Repository Paths
- **Public Document Root**: `public/`
- **Application Core & Modules**: `app/`
- **Routes**: `routes/`
- **Configuration**: `config/`
- **Storage & Databases**: `storage/`, `data/`
- **Docker Setup**: `docker/`, `docker-compose.yml`
- **Engineering Documentation**: `docs/engineering/`

## 3. Local Runtime Prerequisites
- PHP 8.3+ with extensions: `pdo`, `pdo_sqlite`, `sodium`, `mbstring`, `curl`, `json`.
- Docker & Docker Compose (for containerized deployment).

## 4. Docker & Compose Configuration
The project includes `docker-compose.yml` defining two services:
1. `php`: Built from `docker/php/Dockerfile` (PHP 8.3-FPM Alpine), mounting codebase and volume `nyx-data`.
2. `nginx`: Using `nginx:1.27-alpine`, mounting codebase read-only and exposing port `8080` on `127.0.0.1`.

## 5. Exact Commands to Start & Stop NYX
- **Start**: `docker compose up -d`
- **Stop**: `docker compose down`
- **Local Built-in Server**: `php -S 127.0.0.1:8080 -t public`

## 6. Commands to Inspect Logs & Container Status
- **Status**: `docker compose ps`
- **PHP Logs**: `docker compose logs php`
- **Nginx Logs**: `docker compose logs nginx`

## 7. Restart & Rebuild
- **Restart**: `docker compose restart`
- **Rebuild**: `docker compose build --no-cache` (when Dockerfile/extensions change).

## 8. Local URL & Port
- **URL**: `http://127.0.0.1:8080` (bound strictly to `127.0.0.1`).

## 9. Live Development & Bind Mounts
- PHP, HTML, CSS, JS, static assets are bind-mounted (`.:/var/www/nyx`). Changes take effect immediately.

## 10. Dependency Installation & Environment
- Managed via Composer (`composer.json`). Configuration via `.env`.

## 11. Testing & Validation Commands
- **PHP Syntax Check**: `php -l <file>`
- **Self-Test / Health**: `php -f public/index.php` or health endpoints.

## 12. Task Invocation Guide
- **Standard Task**: Provide natural-language description (e.g., `"The health endpoint returns 404"`). Lead triages, investigates, delegates, implements, verifies, and reports.
- **Full Multi-Agent Review Request**: Explicitly request multi-agent review (e.g., `"Review the E2EE implementation with security and crypto specialists"`).
