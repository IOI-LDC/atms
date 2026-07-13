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
- Production account emails use Graph `sendMail`; development and tests use the
  fake account-email transport.
- Set trusted Sanctum stateful domains and CORS/cookie settings for the deployed SPA
  and API hosts.
- Retain database and attachment volumes across deploys.
- Monitor queue workers, scheduler execution, Graph credential expiry, ERP sync
  errors, disk capacity, and backup completion.

## Deploy and update

Production uses the production Compose override, not the local development
override:

```sh
docker compose build
docker compose -f compose.yaml -f compose.production.yaml up -d
docker compose exec api php artisan migrate --force
docker compose exec api php artisan db:seed --force
```

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
