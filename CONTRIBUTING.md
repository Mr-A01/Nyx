# Contributing to NYX

## Workflow
1. Open or pick a **GitHub Issue**
2. Create a branch: `feat/…`, `fix/…`, or `docs/…`
3. Implement · test on a live node when security-sensitive
4. Open a **Pull Request** into `main`
5. Squash-merge after checks; **delete the branch**

## Code style
- PHP 8.1+ · `declare(strict_types=1);`
- Auth only via `AuthContext` / session — never trust body `user_id`
- `FileStore` is source of truth; SQLite is optional index only
- Prefer small, reviewable PRs

## Security
See [SECURITY.md](SECURITY.md). Do not file exploit details in public issues when avoidable.
