#!/usr/bin/env bash
# Requires: export GH_TOKEN=ghp_... (repo scope)
# Creates/updates NYX project issues on GitHub and can close them.
set -euo pipefail
REPO="${GITHUB_REPO:-Mr-A01/Nyx}"
API="https://api.github.com"
if [[ -z "${GH_TOKEN:-}" ]]; then
  echo "GH_TOKEN is required to manage GitHub Issues."
  echo "Create a classic PAT with 'repo' scope and: export GH_TOKEN=..."
  exit 1
fi
auth=(-H "Authorization: Bearer $GH_TOKEN" -H "Accept: application/vnd.github+json")

create_issue() {
  local title="$1" body="$2" labels="$3"
  curl -sS "${auth[@]}" -X POST "$API/repos/$REPO/issues" \
    -d "$(jq -n --arg t "$title" --arg b "$body" --argjson l "$(jq -nc --arg s "$labels" '$s|split(",")')" '{title:$t, body:$b, labels:$l}')"
}

close_issue() {
  local num="$1"
  curl -sS "${auth[@]}" -X PATCH "$API/repos/$REPO/issues/$num" -d '{"state":"closed"}'
}

echo "GitHub Issues sync ready for $REPO"
