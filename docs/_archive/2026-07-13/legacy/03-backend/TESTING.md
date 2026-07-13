# Testing

Backend tests are **PHPUnit only** (no Pest). They run on **PostgreSQL**, not
SQLite, against a dedicated `atms_testing` database — so they always run inside
the `atms-api` Docker container. There is no host PHP install.

## Why PostgreSQL, not SQLite

The baseline data migration issues PostgreSQL-only syntax
(`TRUNCATE ... RESTART IDENTITY CASCADE`, `public.*` schema), and running on the
same engine as production prevents driver-specific bugs (case-sensitive `LIKE`,
etc.) from being masked by SQLite. See `phpunit.xml` and `config/database.php`
for the full rationale.

## Test database

| Setting | Value |
|---|---|
| Connection | `testing` (set via `DB_CONNECTION=testing` in `phpunit.xml`) |
| Database | `atms_testing` (env `DB_TEST_DATABASE`, must exist and be empty) |
| Host / credentials | Reuse `DB_*` — no extra secrets |

`RefreshDatabase` migrates `atms_testing` on every test; it never touches the
live `atms` database. A separate connection name is used (rather than overriding
`DB_DATABASE`) because containers inject `DB_DATABASE` as an OS env var that
shadows `phpunit.xml` `<env>` values.

If `atms_testing` does not exist yet, create it once:

```bash
docker exec atms-postgres psql -U atms -d postgres -c "CREATE DATABASE atms_testing;"
```

## Running tests

All commands run inside the running `atms-api` container. The stack must be up
(`docker compose up -d`).

```bash
# Full suite
docker exec atms-api php artisan test --compact

# One file
docker exec atms-api php artisan test --compact tests/Feature/MaintenanceRequests/MaintenanceRequestWorkflowTest.php

# One method (recommended after editing a related file)
docker exec atms-api php artisan test --compact --filter=test_approving_corrective_request_requires_is_failure

# One suite
docker exec atms-api php artisan test --compact --testsuite Feature
```

> Run the **minimal** number of tests needed — filter to the file or method you
> touched. Run the full suite only when finishing up or when a change may have
> wide reach.

`--compact` keeps output short. When a test fails, drop `--compact` for the full
assertion diff and source snippet.

## Prerequisites

- **Stack up:** `docker compose up -d` (the `api` and `postgres` services must be
  running). Check with `docker ps --filter name=atms-`.
- **`atms_testing` database exists** (see above).
- **Baked-in code:** the container image bakes the source at build time. Source
  is **not** volume-mounted in the production-style `compose.yaml`, so after
  editing test files you must either rebuild (`docker compose build api`) or, for
  the dev override, rely on `compose.override.yaml` if it mounts the source. Run
  `docker exec atms-api cat /var/www/html/tests/Feature/<File>.php` to confirm
  the container sees your latest edits before trusting a green run.

## Writing tests

- Create with `php artisan make:test --phpunit {Name}` (feature test by default;
  add `--unit` for a unit test). Run inside the container:
  `docker exec atms-api php artisan make:test --phpunit SomethingTest`.
- Use model **factories** (and their states) to build models — never construct
  rows by hand when a factory exists. Faker: follow existing `$this->faker` /
  `fake()` convention in sibling tests.
- Every change must be tested: add or update a test, then run the affected tests.
- Cover happy paths, failure paths, and edge cases.
- Do not remove tests or test files without approval.

## Formatting after editing PHP

```bash
docker exec atms-api vendor/bin/pint --dirty --format agent
```

Run this after any PHP edit so the style matches the project baseline.

## Common failure pattern: required fields on approval/closure

State-machine endpoints (e.g. `POST /maintenance-requests/{id}/approve`) add
required fields as the domain evolves. The most recent example is `is_failure`,
required when approving a **corrective** MR in `pending_review`:

```php
// Corrective MR approval — is_failure is required
$this->actingAs($manager)
    ->postJson("/api/maintenance-requests/{$mr->id}/approve", ['is_failure' => true])
    ->assertOk();

// Preventive MR approval — no is_failure needed
$this->actingAs($manager)
    ->postJson("/api/maintenance-requests/{$mr->id}/approve")
    ->assertOk();
```

If you see a `422 "The X field is required."` on a workflow endpoint, check the
controller's validation rules — a test that drives that endpoint must now send
the field. When a test asserts a **409** (business-rule rejection), make sure to
send the newly-required validation fields too, otherwise validation fires 422
first and shadows the intended 409.

## Smoke / integration scripts

```bash
./scripts/test-integration.sh   # full integration test against a running stack
./scripts/smoke-compose.sh      # Docker Compose smoke test
./scripts/security-smoke.sh     # security smoke test
```

These complement the unit/feature suite; they do not replace it.
