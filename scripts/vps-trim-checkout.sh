#!/usr/bin/env bash
# ===========================================================================
# ATMS — trim the VPS working tree to what production actually runs.
#
# The VPS gets the app by cloning this repo, so everything tracked in git lands
# there: ~2.6 MB and ~200 files of documentation, session logs, and per-editor
# AI tooling that production never reads.
#
# None of it is web-exposed — Caddy's root is frontend/dist and nothing else —
# so this is housekeeping, with one exception that is not:
#
#   compose.override.yaml is the DEV override, and Docker Compose auto-loads it
#   for every `docker compose` command that does not pass explicit `-f` flags.
#   It sets APP_DEBUG=true, bind-mounts ./backend over the image (whose vendor/
#   is not in git, so the app would not boot), and mounts zzz-dev.ini, which
#   re-enables opcache.validate_timestamps — worth 20-40% of throughput per
#   docker/backend/php/zz-atms.ini. deploy.sh guards its own calls; scripts/*.sh
#   and anything typed by hand do not. Removing the file from the checkout means
#   there is nothing to load.
#
# Uses git sparse-checkout, so this survives `git pull` and needs no changes to
# the repo layout. Idempotent. Run once on the VPS, from the project root.
#
#   ./scripts/vps-trim-checkout.sh          # apply
#   ./scripts/vps-trim-checkout.sh --undo   # restore the full tree
#
# NOT for developer machines — it removes the files you work from.
# ===========================================================================
set -euo pipefail

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

git rev-parse --is-inside-work-tree >/dev/null 2>&1 || {
  echo "ERROR: not a git checkout — nothing to trim." >&2
  exit 1
}

if [[ "${1:-}" == "--undo" ]]; then
  git sparse-checkout disable
  echo "==> Sparse-checkout disabled; full tree restored."
  exit 0
fi

# Paths production does not read. Kept deliberately: backend/, frontend/,
# docker/, scripts/, compose*.yaml (except the override), Caddyfile, deploy.sh.
#
# backend/tests/ stays — CLAUDE.md's documented workflow runs the suite in the
# api container, and it is the only way to check a prod-shaped stack in place.
EXCLUDE=(
  /docs/
  /.kilo/
  /.kilo-to-qoder-staging/
  /.claude/
  /.agents/
  /.codex/
  /.qoder/
  /.zcode/
  /.vscode/
  /AGENTS.md
  /ATMS_UI_RULES.md
  /CLAUDE.md
  /kilo.json
  /.mcp.json
  /compose.override.yaml
)

# Non-cone mode: cone mode cannot express "everything except these".
git sparse-checkout init --no-cone
git sparse-checkout set '/*' "${EXCLUDE[@]/#/!}"

echo "==> Trimmed. Excluded from the working tree:"
printf '      %s\n' "${EXCLUDE[@]}"
echo ""
# `git ls-files` would lie here: sparse-checkout leaves excluded paths in the
# index carrying the skip-worktree bit (`S` in -v output). Filter those out to
# report what is actually on disk.
echo "==> Still on disk (production needs these):"
git ls-files -v | grep -v '^S' | cut -c3- | awk -F/ '{print $1}' | uniq -c | sort -rn | head -12

cat <<'NOTE'

Note: this removes the FILES, not the risk of a bare `docker compose` picking up
an override that comes back on a future clone. Belt and braces — also set this
in the VPS .env:

    COMPOSE_FILE=compose.yaml:compose.production.yaml

Undo with: ./scripts/vps-trim-checkout.sh --undo
NOTE
