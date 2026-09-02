# VPS Provisioning — single host, same-origin

Fresh-install runbook for a new VPS running the current production topology:
one host (`assets.ldc.com.ly`), Caddy in front, Docker Compose behind it, SPA
and API same-origin (SPA at `/`, API at `/api`).

**This doc is day-0 setup only** — an empty database, ready to seed or accept
data. If this VPS is *replacing* one already running ATMS and existing
assets/parts/config data must move over, stop after step 6 and follow
[PROD-VPS-MIGRATION.md](PROD-VPS-MIGRATION.md) instead of letting `deploy.sh`
seed — that doc's schema step explicitly skips seeding so the imported rows
don't collide with it.

---

## 1. DNS

Point `assets.ldc.com.ly` (A record, or CNAME) at the new VPS's public IP.
Caddy cannot issue a TLS certificate until this resolves — do this first so
propagation isn't the last thing you're waiting on.

## 2. System packages

```bash
sudo apt update && sudo apt install -y docker.io docker-compose-v2 caddy git
sudo usermod -aG docker "$USER" && newgrp docker
```

`docker-compose-v2` is Ubuntu's apt package name for the `docker compose`
plugin — `docker-compose-plugin` (Docker's own naming) isn't in Ubuntu's repos.

**Node.js is also required, on the host, not just in Docker.** `deploy.sh`
builds the SPA with `npm run build` directly on the VPS (only the Laravel side
runs in containers). Ubuntu 24.04's apt `nodejs` is v18, too old for this
frontend's `engines` field (`^20.19.0 || >=22.12.0`), so install from
NodeSource instead:

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt-get install -y nodejs
node --version   # confirm >=22.12 (or use the 20.x setup script for ^20.19)
```

## 3. Firewall

Only Caddy's listeners need to be public:

- **80/tcp, 443/tcp** — Caddy (443 serves the app; 80 is the ACME HTTP
  challenge and redirects to 443).
- **22/tcp** — SSH, ideally key-only and rate-limited.

Everything else stays internal. The Docker `nginx` container publishes
`WEB_PORT`/`BACKEND_PORT` (default `8080`) to `127.0.0.1` only — Caddy reaches
it over loopback, so that port must **not** be reachable from outside the
host. PostgreSQL is never published to the host at all (see `compose.yaml`).

## 4. Clone and configure

```bash
git clone <repo> /srv/atms && cd /srv/atms
cp .env.production.example .env
```

Fill in `.env`:

- `APP_KEY` — generate after the containers exist (next command).
- `DB_PASSWORD` — 32+ random chars (`openssl rand -base64 48`).
- `GRAPH_*` — a separate Entra ID app registration from `LDC_ERP_*`, restricted
  to the `notification@ldc.com.ly` mailbox by an Exchange Application Access
  Policy (see [OPERATIONS.md](OPERATIONS.md#email-delivery) — without the
  policy the credential can send as any mailbox in the tenant).
- `LDC_ERP_*` — leave blank if ERP credentials aren't ready yet; the sync job
  fails open and the rest of the app works.
- `APP_URL`, `APP_HOST`, `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`,
  `CORS_ALLOWED_ORIGINS`, `FRONTEND_URL` — already correct in
  `.env.production.example` for `assets.ldc.com.ly`; change only if the host
  differs from that.

```bash
docker compose run --rm api php artisan key:generate
```

## 5. Caddy

```bash
sudo cp Caddyfile /etc/caddy/Caddyfile
sudo systemctl reload caddy
```

Caddy issues the certificate automatically on first request once DNS resolves
and 80/443 are reachable — no separate certbot step.

## 6. First deploy

```bash
./deploy.sh
```

This builds the SPA (reading `frontend/.env.production` for `VITE_API_ORIGIN`),
brings up the Docker stack, runs migrations, seeds **only** if the `users`
table is empty, caches config/routes/views, and reloads Caddy. On a genuinely
fresh VPS this is the whole install — stop here.

If this VPS is taking over from an existing one, **do not let this seed**:
follow [PROD-VPS-MIGRATION.md](PROD-VPS-MIGRATION.md) instead, which brings the
stack up without seeding and loads the moved data first.

## 7. Verify

- `https://assets.ldc.com.ly` loads the SPA.
- `https://assets.ldc.com.ly/api/health/ready` → `200`.
- `https://assets.ldc.com.ly/up` → `200` (Laravel's framework health check,
  unprefixed — distinct from the two above).
- Log in and confirm the session cookie is scoped to `assets.ldc.com.ly` (no
  `Domain=` mismatch, no 401/419 loop).
- A non-`/api` deep link (e.g. `/dashboard`) falls through to the SPA rather
  than 404ing — confirms the Caddy matcher ordering in `Caddyfile` is correct.
- `./scripts/smoke-compose.sh` and `./scripts/test-integration.sh` from the
  repo root on the VPS — both hit `localhost:80` directly, bypassing Caddy, so
  they're unaffected by the public hostname.

## 8. Ongoing operations

Backup/restore, the queue-worker restart rule, and email configuration are
covered in [OPERATIONS.md](OPERATIONS.md) — this doc is provisioning only, not
a full ops reference.
