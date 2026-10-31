# GitHub Issues (source of truth for the agent)

Without `GH_TOKEN` the agent cannot call the GitHub Issues API (SSH keys are git-only).

## One-time setup
1. GitHub → Settings → Developer settings → Personal access tokens
2. Classic token with `repo` scope
3. `export GH_TOKEN=ghp_...`
4. `bash tools/github_issues_sync.sh`

## Managed issues (closed in mr.a when fixed)
See `docs/ISSUES.md`. Each `Fixes NYX-xxx` commit maps to these titles for GitHub.
