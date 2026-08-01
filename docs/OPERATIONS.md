# ATMS Operations Summary

## Runtime

ATMS runs through Docker Compose with PostgreSQL-backed queues and persistent
storage for attachments. The application must be served behind HTTPS in production;
the database and internal application services must not be exposed publicly.

Use the repository Compose configuration as the exact operational command source.
Do not introduce Redis, MinIO, SMTP, or a mock ERP service as implicit defaults.

The production topology has a public reverse proxy plus internal API, PostgreSQL,
queue-worker, and scheduler services. Persistent `pgdata` and `attachments`
volumes must survive a deploy. Only HTTPS should be externally exposed; PostgreSQL
and PHP-FPM must remain private to the Docker network.

## Production configuration

- Configure separate credentials for ERP and Microsoft Graph; they are different
  Entra ID applications.
- Production email uses Graph `sendMail`; development and tests use the fake
  transport. See "Email delivery" below.
- Set trusted Sanctum stateful domains and CORS/cookie settings for the deployed SPA
  and API hosts.
- Retain database and attachment volumes across deploys.
- Monitor queue workers, scheduler execution, Graph credential expiry, ERP sync
  errors, disk capacity, and backup completion.

## Email delivery

`ACCOUNT_EMAIL_TRANSPORT` selects the transport: `fake` records sends in memory and
delivers nothing, `graph` sends real mail. It is the single switch that makes ATMS
start emailing real users, and it governs workflow notifications as well as account
mail — do not flip it as part of an unrelated deploy.

**Email settings live in the root `.env` only.** Compose reads that file and injects
`FRONTEND_URL`, `ACCOUNT_EMAIL_*`, and `GRAPH_*` into the api, queue, and scheduler
containers. A value set in `backend/.env` instead is silently ignored, because Compose
injects its own value as a real environment variable and Laravel reads `$_SERVER`
before the dotenv file — the symptom is empty credentials at send time rather than a
configuration error. To remove the ambiguity, `backend/.env` deliberately carries none
of these keys and points here instead. After any change run `docker compose up -d`,
then confirm with `docker compose exec api php artisan config:show account-email`.

Operational constraints:

- **Email requires a running queue worker.** Notifications are queued, so with no
  worker they accumulate silently instead of failing visibly.
- **Exchange Online throttles concurrent application access to a single mailbox** to
  roughly three or four connections, returning `429 ApplicationThrottled` and
  gateway `504`s under parallel load. Sends are therefore serialized behind one
  mailbox-wide queue lock and retry on `429` honouring `Retry-After`. Do not remove
  the lock or scale sends by adding parallel workers.
- **Deep links in email come from `FRONTEND_URL`**, which must be the address users
  actually browse, and falls back to `APP_URL` when unset. A wrong value produces mail
  that delivers successfully with unusable links.
- **`ACCOUNT_EMAIL_BCC` silently copies every message** to the configured address. It
  has no default, so leaving it empty is safe; set it only if a monitoring copy is
  deliberately wanted.
- **The mailbox needs an Exchange Application Access Policy** restricting the Entra
  application to `notification@ldc.com.ly`. Without it the credential can send as any
  mailbox in the tenant.
- **Track the Graph client secret or certificate expiry.** Expiry stops all email,
  including password reset, which locks users out of self-recovery.

Before enabling `graph` in an environment, confirm real user email addresses are in
place. Workflow notifications resolve recipients by role, so an unreviewed user table
will mail whoever holds the Manager or Technician role. Addresses on `ldc.com.ly` are
real; any remaining `atms.local` or `atms.internal` address is a placeholder that will
bounce, and only matters while the account holding it is active.

Set `FRONTEND_URL` per environment: `https://atms.inova.krd` on the deployed backend
(provisional — see [ROADMAP.md](ROADMAP.md)), and the local dev server address when
running locally.

## Deploy and update

Production uses the production Compose override, not the local development
override:

```sh
docker compose build
docker compose -f compose.yaml -f compose.production.yaml up -d
docker compose exec api php artisan migrate --force
docker compose exec api php artisan db:seed --force
docker compose exec api php artisan atms:import-parts --dry-run
docker compose exec api php artisan atms:import-parts
```

`deploy.sh` performs the parts dry run and import automatically after migrations
and first-boot seeding. It pins the approved CSV by SHA-256 and aborts before the
data update if validation or the hash check fails. The importer is idempotent, so
later deploys validate the same source and leave matching rows unchanged.

### The queue worker holds code in memory — restart it after every code change

`queue` and `scheduler` are long-running PHP processes. They load application
classes **once, at boot**, and keep serving from that copy. A container that was
already running when new code landed will keep executing the old code, and a job
referencing anything new fails with errors that look impossible against the source
on disk — `Undefined constant …`, `Class … not found`, a method that is plainly
there.

A full `docker compose up -d` after a build restarts them, so a normal deploy is
safe. It is the **partial** update that bites: editing files against a running
stack, or restarting only `api`.

```sh
docker compose restart queue scheduler
```

Failures of this kind are silent from the UI — the request succeeds and the queued
work never happens. After fixing one, check what was lost and re-drive it:

```sh
docker compose exec api php artisan queue:failed     # inspect before flushing
docker compose exec api php artisan queue:retry all  # or queue:flush, then re-dispatch
```

This is not hypothetical: on 2026-08-01 every `ReconcilePmCategoryAssignmentsJob`
failed this way against a worker booted before the job existed, so PM rules showed
their category coverage while producing no assignments at all.

Initial setup additionally requires an application key, production environment
values, and an initial Administrator. Required service credentials include
`LDC_ERP_*` for parts integration and separate `GRAPH_*` credentials for the
notification mailbox. Never commit real environment values.

## Backup and restore

Back up both PostgreSQL and persistent attachment storage. A valid restore test
uses an isolated environment: stop workers, restore the database, restore
attachments, bring the application up, and verify both data and files. A database
backup alone is not sufficient.

The supported backup sequence is daily database and attachment backup, weekly copy,
and retention pruning. Stop queue/scheduler workers before restore, restore database
and attachments, verify both backup manifests, then restart workers. The backup
scripts intentionally do not back up `.env`, secrets, Compose files, or source code;
protect those through a separate secure operational process.

## Verification

- For a PHP change, run the smallest relevant PHPUnit test command in the backend
  container/runtime and format modified PHP with Pint.
- For a frontend change, run the relevant checks and production build from
  `frontend/`.
- For configuration/deployment work, validate the rendered Compose configuration
  and perform the documented health checks.
- Always run `git diff --check` before handoff. Do not claim a deployment, build,
  or test result without fresh command output.

Health probes are `GET /api/health/live` and `GET /api/health/ready`. A readiness
failure should be investigated as database or storage availability before treating
it as a frontend problem.
