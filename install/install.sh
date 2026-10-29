#!/usr/bin/env bash
set -euo pipefail
TARGET="${1:-./nyx}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$TARGET/data" "$TARGET/public/vendor"
cp -f "$ROOT/src/"*.php "$TARGET/" 2>/dev/null || true
cp -f "$ROOT/src/"*.html "$TARGET/" 2>/dev/null || true
cp -f "$ROOT/public/client.html" "$TARGET/client.html"
mkdir -p "$TARGET/public"
cp -f "$ROOT/public/client.html" "$TARGET/public/client.html"
cp -f "$ROOT/public/vendor/sodium.js" "$TARGET/public/vendor/sodium.js"
chmod -R u+rwX "$TARGET/data" || true
echo "Installed NYX node to: $TARGET"
echo "Check: $TARGET/api or /nyx/api/v3/ops/health"
