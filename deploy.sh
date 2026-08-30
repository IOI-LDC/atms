#!/usr/bin/env bash
# ===========================================================================
# ATMS — VPS deploy script (Option A: Caddy + Docker, split subdomain)
# --------------------------------------------------------------------------
# Run on the VPS, from the project root (e.g. /srv/atms).
#
#   SPA:  https://atms.inova.krd     (Vue static files)
#   API:  https://atmsapi.inova.krd  (Laravel via Docker nginx)
#
# Prerequisites on the VPS (one-time):
#   sudo apt update && sudo apt install -y docker.io docker-compose-plugin caddy git
#   sudo usermod -aG docker $USER && newgrp docker
#   git clone <repo> /srv/atms && cd /srv/atms
#   cp .env.production.example .env && nano .env   # fill every secret
#   sudo cp Caddyfile /etc/caddy/Caddyfile && sudo systemctl reload caddy
#
# Idempotent — safe to re-run on every deploy.
#
# ⚠️ EXCEPTION — release 4b (status vocabulary), 2026-08-16.
#   That release narrows the OperationalStatus enum AND rewrites the rows still
#   carrying the old values. New code against un-migrated data throws on every
#   read of an affected asset, so the two must not overlap: traffic has to stop,
#   the migration runs from a one-off new-image container, and only then does the
#   new stack start. This script's ordering is wrong for that one release.
#   Follow docs/RELEASE-4b-CUTOVER.md instead, once, then resume using this
#   script as normal.
#
#   Step 0b enforces that rather than trusting anyone to have read this: while a
#   legacy value survives in `assets.operational_status`, the script aborts.
# ===========================================================================
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

# shellcheck disable=SC1091
set -a; [[ -f .env ]] && source .env; set +a

APP_HOST="${APP_HOST:-atms.inova.krd}"
API_HOST="${API_HOST:-atmsapi.inova.krd}"

# --- 0. Sanity checks -------------------------------------------------------
if [[ ! -f .env ]]; then
  echo "ERROR: .env missing. Copy .env.production.example and fill it in first." >&2
  exit 1
fi
if [[ -z "${APP_KEY:-}" ]]; then
  echo "ERROR: APP_KEY is empty. Generate one:" >&2
  echo "  docker compose run --rm api php artisan key:generate" >&2
  exit 1
fi
command -v docker >/dev/null 2>&1 || { echo "ERROR: docker not installed." >&2; exit 1; }

# --- 0b. Release 4b tripwire ------------------------------------------------
# This script starts the new image (step 2) before migrating (step 3). For every
# ordinary release that is fine. For 4b it is not: the narrowed OperationalStatus
# enum throws on every read of a row still carrying `down`/`scraped`/
# `under_inspection`/`lih`, so the app is broken for the window between the two.
#
# That used to be a comment at the top of this file and nothing else — safety by
# hoping the operator reads. This refuses to run instead, and names the document
# that describes the correct sequence.
if [[ -n "$(docker compose ps -q postgres 2>/dev/null || true)" ]]; then
  LEGACY_STATUSES=$(docker compose exec -T postgres \
    psql -U "${DB_USERNAME:-atms}" -d "${DB_DATABASE:-atms}" -tAc \
    "SELECT count(*) FROM assets WHERE operational_status IN ('down','scraped','under_inspection','lih')" \
    2>/dev/null | tr -d '[:space:]' || true)

  if [[ -n "$LEGACY_STATUSES" && "$LEGACY_STATUSES" != "0" ]]; then
    echo "ERROR: $LEGACY_STATUSES asset(s) still carry a legacy operational_status." >&2
    echo "       Release 4b must not be deployed with this script — it would start the" >&2
    echo "       new enum against un-migrated rows and break every read of those assets." >&2
    echo "       Follow docs/RELEASE-4b-CUTOVER.md once, then re-run this script." >&2
    exit 1
  fi
fi

# --- 1. Build the Vue SPA (uses frontend/.env.production for the API origin) -
echo "==> Building frontend (VITE_API_ORIGIN from frontend/.env.production)…"
(
  cd frontend
  [[ -d node_modules ]] || npm ci
  npm run build
)
[[ -d frontend/dist ]] || { echo "ERROR: frontend/dist missing after build." >&2; exit 1; }

# --- 2. Build + start the Docker stack --------------------------------------
echo "==> Bringing up Docker stack…"
docker compose --env-file .env \
  -f compose.yaml \
  -f compose.production.yaml \
  up -d --build

# --- 3. Migrate (idempotent) ------------------------------------------------
echo "==> Running database migrations…"
docker compose exec -T api php artisan migrate --force

# --- 4. Seed only on first boot (empty users table) -------------------------
USER_COUNT=$(docker compose exec -T \
  api php artisan tinker --execute 'echo \DB::table("users")->count();' \
  2>/dev/null | tr -d '[:space:]')
if [[ "$USER_COUNT" == "0" ]]; then
  echo "==> First boot — seeding database…"
  docker compose exec -T api php artisan db:seed --force
else
  echo "==> Users present ($USER_COUNT) — skipping seed."
fi

# --- 5. Cache config/routes/views --------------------------------------------
# The parts workbook import (atms:import-parts) was deliberately removed from
# this script: it was a one-time data migration and it OVERWRITES live stock —
# since Q6/Phase 3 quantities are live balances moved by work order consumption
# and quantity uploads, so re-applying the workbook on every deploy reverted
# them. Run it manually only when a freshly approved workbook must be applied:
#   docker compose exec api php artisan atms:import-parts --dry-run
#   docker compose exec api php artisan atms:import-parts
docker compose exec -T api php artisan config:cache
docker compose exec -T api php artisan route:cache
docker compose exec -T api php artisan view:cache

# --- 6. Reload Caddy (picks up Caddyfile changes if any) --------------------
if systemctl is-active --quiet caddy; then
  echo "==> Reloading Caddy…"
  sudo systemctl reload caddy
fi

# --- 7. Status + verification hints -----------------------------------------
echo ""
echo "==> Stack status:"
docker compose ps

echo ""
echo "==> Deploy complete. Verify:"
echo "    SPA :  https://${APP_HOST}"
echo "    API :  https://${API_HOST}/api/health/ready"
echo "    Logs:  docker compose logs -f api"
echo ""
echo "Sanctum cross-subdomain cookie auth requires these .env values to agree:"
echo "    SESSION_DOMAIN=.inova.krd"
echo "    SANCTUM_STATEFUL_DOMAINS=${APP_HOST}"
echo "    CORS_ALLOWED_ORIGINS=https://${APP_HOST}"
echo "If login 401s, that triple is the first thing to check."
