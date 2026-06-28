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

# --- 5. Cache config/routes/views -------------------------------------------
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
