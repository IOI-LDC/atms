# Final production VPS — data migration

**Scope, decided with LDC 2026-08-30:** the new VPS starts with **assets, parts
and configuration only**. No maintenance-request or work-order history moves.
The old VPS is not modified in any way — after cutover it *is* the archive of
everything that was left behind, and the rollback path.

`deploy.sh` is **not** used for the first boot of the new VPS: it seeds on an
empty database, and seeding would collide with the rows this migration imports.
It becomes the deploy tool again from the second release onwards (see
[After cutover](#after-cutover)).

---

## What moves, and what stays

Everything is classified once, here, so nobody re-derives it under pressure.
The split is by **foreign keys**, not by vibes: a row whose references point
into the MR/WO universe either moves with those references dropped or does not
move at all.

### Moves — configuration

| Table | Why |
|---|---|
| `roles` | Carried so `users.role_id` keeps its meaning. (The seeder would recreate them; carrying keeps ids exact.) |
| `employees` | `users.employee_id` points here. The directory UI was removed, the table was not. |
| `users` | Logins, password hashes, roles. The system cannot be operated without them — see the decision note below. |
| `locations` | Asset locations (Tajoura Base, rigs, wells). Self-referencing `parent_id` — loaded with triggers disabled like everything else. |
| `maintenance_categories` | Part + PM-rule + form-template categorisation. |
| `usage_reading_types` | Reading types for meters and reading-triggered PM rules. |
| `master_data_items` | Admin-editable vocabularies (asset conditions, sizes, …) including the default condition close resets to. |
| `fa_subclass_type_codes` | ERP classification codes. |
| `company_settings` | Company timezone. |
| `business_number_sequences` | The MR-…/WO-… number counters. Carried **deliberately**: numbering continues from the old system, so numbers already quoted on paper or in emails can never be reissued against different work. |
| `form_templates`, `form_template_fields`, `form_template_maintenance_category` | Inspection form templates. Completed WO forms stay behind; the templates are config. |
| `pm_rules`, `pm_rule_maintenance_category` | The PM schedule definitions. Per-asset schedule state moves separately below. |

### Moves — asset data

| Table | Why |
|---|---|
| `assets` | The fleet. Self-referencing `parent_asset_id` for assemblies. |
| `asset_location_histories` | Where each asset has been. |
| `asset_pm_assignments` | Per-asset schedule state: baselines (`last_triggered_date`, `last_triggered_reading`), activation. This is what keeps reading-based PM from firing immediately after cutover. |
| `bookings` | Asset reservations, current and future. |
| `asset_meter_readings` | Meter positions. **`work_order_id` and `maintenance_request_id` are set to NULL on load** — the columns are nullable, the readings are asset truth, and both referencing tables are excluded. The audit trail of *which WO took the reading* stays on the old VPS. |

### Moves — parts

| Table | Why |
|---|---|
| `parts` | The catalogue with **live** `available_quantity` balances (moved as they are — the workbook import must NOT be re-run on the new VPS, see the note in `deploy.sh`). |

### Deliberately stays on the old VPS

| Table(s) | Why |
|---|---|
| `maintenance_requests`, `work_orders`, `work_order_parts`, `work_order_forms`, `work_order_form_fields`, `work_order_pm_marks`, `work_order_meter_snapshots` | Excluded by decision. The old VPS keeps them readable. |
| `pm_occurrence_suppressions` | FKs `maintenance_requests` — it is MR-adjacent history. |
| `attachments` | On the current system every attachment belongs to an MR or a WO (verified 2026-08-30 — re-verify on prod with the query below). None move. |
| `audit_logs` | The audit trail *of the old system's* MR/WO activity. It is history, not config, and rows reference MR/WO subjects. The new system starts its own audit log. |
| `erp_sync_jobs`, `erp_sync_errors`, `api_clients` | Runtime/infra, effectively empty. |
| `cache`, `cache_locks`, `sessions`, `jobs`, `failed_jobs`, `job_batches`, `password_reset_tokens`, `personal_access_tokens`, `user_activation_tokens` | Runtime. Sessions and tokens do not survive a host move by design. |
| `migrations` | The new VPS builds the schema with `php artisan migrate` — never copy this table. |

**Consequence to communicate:** every report that measures MR/WO history —
MTBF, MTTR, PM compliance, backlog, consumption — starts empty on the new VPS
and builds forward. PM schedules themselves are unharmed: baselines travel with
`asset_pm_assignments`.

### The users decision

Users move (recommended, and what the commands below do). The alternative —
fresh accounts on the new VPS — is possible but ugly: `asset_location_histories`,
`asset_meter_readings`, `asset_pm_assignments`, `bookings` and `pm_rules` all
carry `*_user_id` columns, so going fresh means stripping or orphaning every one
of them. That is surgery with no upside; staff keeping their passwords is not a
problem. Inactive users move too and simply stay inactive.

---

## Before you start — on the OLD VPS

```bash
# ① Survey, and keep the output. It is the before-picture every count is checked against.
./scripts/survey-data.sh > survey-old-vps.txt

# ② Confirm nothing has changed about attachments since 2026-08-30.
#    If this ever shows attachable_type = 'asset', STOP and extend the plan:
#    those files live in the attachments volume and would need copying.
docker compose exec -T postgres psql -U atms -d atms -c \
  "SELECT attachable_type, count(*) FROM attachments GROUP BY 1"

# ③ Stop traffic. Postgres stays up.
docker compose stop api queue scheduler nginx

# ④ Full backup of everything, including what will not move. This is the archive.
./scripts/backup-postgres.sh

# ⑤ Export ONLY the tables that move — data only, no schema, triggers disabled.
docker compose exec -T postgres pg_dump -U atms -d atms \
  --data-only --no-owner --disable-triggers \
  -t roles -t employees -t users -t locations -t maintenance_categories \
  -t usage_reading_types -t master_data_items -t fa_subclass_type_codes \
  -t company_settings -t business_number_sequences \
  -t form_templates -t form_template_fields -t form_template_maintenance_category \
  -t pm_rules -t pm_rule_maintenance_category \
  -t assets -t asset_location_histories -t asset_pm_assignments \
  -t bookings -t asset_meter_readings -t parts \
  > atms-move-set.sql

# ⑥ Copy the dump to the new VPS (and to a second safe location).
scp atms-move-set.sql user@new-vps:/srv/atms/
```

`--disable-triggers` makes load order irrelevant and requires table ownership —
the compose `postgres` user owns the database, so this is fine.

---

## New VPS — schema, load, start

Prerequisites are the same one-time setup `deploy.sh` documents: Docker, the
repo at `/srv/atms`, `.env` filled from `.env.production.example`, Caddy
configured. Build the app image first so `artisan` runs on the new code:

```bash
cd /srv/atms
docker compose --env-file .env -f compose.yaml -f compose.production.yaml build

# ① Database only — do NOT start api/queue/scheduler yet.
docker compose --env-file .env -f compose.yaml -f compose.production.yaml up -d postgres

# ② Schema, explicitly WITHOUT seeding. Seeding here would collide with the
#    roles, master data and sequences the dump is about to import.
docker compose --env-file .env -f compose.yaml -f compose.production.yaml \
  run --rm api php artisan migrate --force

# ③ Load the move set in one transaction.
docker compose exec -T postgres psql -U atms -d atms -1 \
  -f - < atms-move-set.sql

# ④ Drop the WO/MR references on meter readings (see the table list above).
docker compose exec -T postgres psql -U atms -d atms -c \
  "UPDATE asset_meter_readings SET work_order_id = NULL, maintenance_request_id = NULL
   WHERE work_order_id IS NOT NULL OR maintenance_request_id IS NOT NULL"

# ⑤ Push every moved table's id sequence past its imported max, or the first
#    insert after cutover fails on a duplicate key.
docker compose exec -T postgres psql -U atms -d atms <<'SQL'
SELECT format(
  'SELECT setval(pg_get_serial_sequence(%L, ''id''), COALESCE((SELECT MAX(id) FROM %I), 1));',
  tablename, tablename)
FROM pg_tables
WHERE schemaname = 'public'
  AND tablename IN ('roles','employees','users','locations','maintenance_categories',
    'usage_reading_types','master_data_items','fa_subclass_type_codes',
    'company_settings','business_number_sequences','form_templates',
    'form_template_fields','form_template_maintenance_category','pm_rules',
    'pm_rule_maintenance_category','assets','asset_location_histories',
    'asset_pm_assignments','bookings','asset_meter_readings','parts')
\gexec
SQL

# ⑥ Start the whole stack, then build the caches deploy.sh normally builds:
docker compose --env-file .env -f compose.yaml -f compose.production.yaml up -d
docker compose exec -T api php artisan config:cache
docker compose exec -T api php artisan route:cache
docker compose exec -T api php artisan view:cache
```

The attachments volume starts empty — correct, because no moving table owns
attachment files (step ② of the old-VPS section proves it for the current data).

---

## Verify before opening it to users

```bash
# Counts per moved table, run on BOTH VPSes — every line must match, except
# audit-style drift if the old system was frozen properly (it was: step ③
# stopped traffic before the export).
docker compose exec -T postgres psql -U atms -d atms -c \
  "SELECT 'assets' t, count(*) FROM assets UNION ALL
   SELECT 'parts', count(*) FROM parts UNION ALL
   SELECT 'users', count(*) FROM users UNION ALL
   SELECT 'locations', count(*) FROM locations UNION ALL
   SELECT 'pm_rules', count(*) FROM pm_rules UNION ALL
   SELECT 'asset_pm_assignments', count(*) FROM asset_pm_assignments UNION ALL
   SELECT 'asset_meter_readings', count(*) FROM asset_meter_readings UNION ALL
   SELECT 'form_templates', count(*) FROM form_templates UNION ALL
   SELECT 'master_data_items', count(*) FROM master_data_items UNION ALL
   SELECT 'business_number_sequences', count(*) FROM business_number_sequences"

# No WO/MR references survived the trip:
docker compose exec -T postgres psql -U atms -d atms -c \
  "SELECT count(*) AS dangling_wo_refs FROM asset_meter_readings WHERE work_order_id IS NOT NULL"

# And the standing survey, to compare against survey-old-vps.txt:
./scripts/survey-data.sh > survey-new-vps.txt
```

In the browser:

1. **Log in** with an existing account — password hashes moved with the users.
2. **Asset list** loads with locations and conditions intact; an assembly's
   children still nest under their parent.
3. **Part list** shows the live quantities — spot-check three against the old
   VPS, they must be identical, not the workbook's June snapshot.
4. **PM schedules** on an asset detail page show assignments with baselines.
5. **Lists** (master data, form templates, reading types) are populated.
6. **Create a maintenance request** and approve it: the MR number **continues**
   the old sequence rather than restarting at 1, and the resulting WO can be
   assigned, started, completed and closed. This exercises the sequences and
   the fresh schema end to end. Cancel the test request afterwards.

### Rollback

Nothing on the old VPS was touched, so rollback is: point DNS back, restart the
old stack. The new VPS can be re-loaded from `atms-move-set.sql` at any time
(`migrate:fresh --force` first, then steps ③–⑥ again).

---

## After cutover

- **`deploy.sh` is the deploy tool again from the next release on.** Its
  first-boot seed step checks the users count and skips (users exist here).
- **Do not re-run `atms:import-parts`** on the new VPS without an explicit
  decision — it overwrites live balances (see the note in `deploy.sh`).
- **Keep the old VPS (or at least `backup-postgres.sh` output) until the LDC
  team confirm they no longer need MR/WO history.** It is the only copy of that
  history in the world; audit_logs, attachments and WO paperwork all live there.
- **Reports start from zero.** Tell the team before they ask: MTBF/MTTR/PM
  compliance measure the new system going forward, and no history was lost —
  it is on the old VPS.
