# NYX Engineering Team & Local Development Guide

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
- Docker & Docker Compose.

## 4. Testing & Validation
- **PHP Syntax Check**: `php -l <file>`
- **Unit Tests**: PHPUnit / custom test runner.
