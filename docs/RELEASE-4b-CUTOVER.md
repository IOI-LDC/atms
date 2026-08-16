# Release 4b — status vocabulary cutover

**This release requires a maintenance window.** Ten to fifteen minutes is
enough. It is the only release in the vocabulary programme that does.

`./deploy.sh` is safe to run for ordinary releases and deliberately is **not**
changed for this one: it brings the stack up and migrates in the order that
suits an additive change, which is the wrong order here. Follow the sequence
below instead, once, and then resume using `deploy.sh` as normal.

---

## Why a window is needed

4a was additive — the code accepted the new values while the database still held
the old ones, so old and new could run side by side. 4b is the switch:

- `OperationalStatus` narrows from eight cases to four.
- A migration rewrites every row still carrying `down`, `scraped`,
  `under_inspection` or `lih`.

Those two must not overlap. **New code against un-migrated data throws on every
read of an affected asset** — the enum cast has no case to deserialize into, and
the asset list 500s rather than degrading. The reverse (old code, new data) is
harmless but pointless.

So: stop traffic, migrate, start the new image.

---

## Preflight (run before the window, then again inside it)

```bash
docker compose exec -T api php artisan tinker --execute="
\$scraped = DB::table('assets')->whereIn('operational_status',['scraped','lih'])->pluck('id');
echo 'rows to deactivate: '.\$scraped->count().PHP_EOL;
echo 'their active bookings: '.DB::table('bookings')->whereIn('asset_id',\$scraped)->where('status','active')->count().PHP_EOL;
echo 'their open work orders: '.DB::table('work_orders')->whereIn('asset_id',\$scraped)->whereNotIn('status',['closed','cancelled'])->count().PHP_EOL;
foreach (DB::table('assets')->selectRaw('operational_status, count(*) c')->groupBy('operational_status')->get() as \$r) {
  echo '  '.\$r->operational_status.' = '.\$r->c.PHP_EOL;
}
"
```

**Derive the set by value, never by id.** Ids captured on 2026-08-16 (155
`scraped`; 353 and 410 `down`) are recorded for reference only — a work-order
close or a manual status change between then and the window moves rows in and
out of these sets.

**What matters, and what does not:**

| Check | Blocking? |
|---|---|
| Open work orders on `scraped`/`lih` assets | **Yes** — those assets are about to be deactivated. Close or cancel them first. |
| Active bookings on `scraped`/`lih` assets | No — the migration releases them itself. Worth knowing about, since the people holding those bookings should be told. |
| Open work orders on `down` assets | **No.** `down → failure` is a pure rename; the work order carries on unaffected. |

> An earlier draft of the plan asserted 0 bookings and 0 open work orders across
> *both* value sets. That over-asserts and would block the release for work
> orders a rename cannot disturb. Only the deactivated set matters.

---

## Cutover sequence

```bash
# ① Build the new image WITHOUT starting it.
docker compose build

# ② Drain traffic and stop the application containers.
#    Postgres stays up — the migration needs it.
docker compose stop api queue scheduler

# ③ Back up. This is the abort path; the migration's own down() reverses the
#    renames but deliberately does not un-deactivate retired assets.
./scripts/backup.sh          # or your usual pg_dump

# ④ Migrate from a ONE-OFF NEW-IMAGE container.
#    Never from the old running container — it has no such migration class.
docker compose run --rm api php artisan migrate --force

# ⑤ Start the new stack.
docker compose up -d

# ⑥ Rebuild and publish the SPA (its status vocabulary changed too).
cd frontend && npm ci && npm run build && cd ..
```

### Smoke checks (⑦)

```bash
# No legacy value survived. The migration itself refuses to finish otherwise,
# but confirm against the running stack.
docker compose exec -T api php artisan tinker --execute="
echo 'legacy rows: '.DB::table('assets')->whereIn('operational_status',['down','scraped','under_inspection','lih'])->count().PHP_EOL;
foreach (DB::table('assets')->selectRaw('operational_status, count(*) c')->groupBy('operational_status')->get() as \$r) {
  echo '  '.\$r->operational_status.' = '.\$r->c.PHP_EOL;
}
"
```

Then, in the browser:

1. **Asset list loads** and the Status column reads Ready for Field / Under
   Maintenance / Failure / At the Field. A blank column means the SPA was not
   rebuilt at step ⑥.
2. **Asset detail** shows a **Condition** field, and the edit sheet offers the
   four seeded conditions.
3. **Lists → Asset Conditions** serves the vocabulary and refuses to deactivate
   the default row.
4. **Close a work order** — the dialog no longer asks for an asset status, and
   the asset comes out Ready for Field with Condition reset to Normal.
5. **Cancel a work order** — the choice is Failure or Ready for Field.
6. **Dashboard** asset-health card shows four states with counts that sum to the
   fleet.

### Abort

If a smoke check fails and the cause is not obvious:

```bash
docker compose stop api queue scheduler
git checkout <previous-tag>
docker compose build
# restore the step ③ snapshot
./scripts/restore.sh <snapshot>
docker compose up -d
```

Restore from the snapshot rather than running `migrate:rollback`. The rollback
reverses `failure → down` correctly, but `scraped` and `lih` both became
`ready_for_field` + `is_active = false`, which is indistinguishable from an asset
legitimately deactivated while ready — un-deactivating on that guess would put
retired equipment back into the pool.

---

## After the window

- **Tell whoever uses the system that "Down" now reads "Failure".** It is the
  same state under a better name, but it is the change people will notice first.
- **`at_the_field` stays inert until LDC creates rig or well_site locations.**
  That is an ops activation step, not a release gate — the rule simply never
  fires while the only location is a yard. Once those locations exist, moving an
  asset to one sets the status automatically.
- **Release 4c** drops the now-unread **`erp_status`** column only. Ordinary
  `deploy.sh` release, once 4b has run cleanly for a few days.

  **`maintenance_sub_status` is deliberately retained.** Its readers were removed
  in 4b — nothing writes or serves it — but the column stays until Phase 2
  Assembly is specified. The recorded design (🟠 P2-001) derives `installed` /
  `ready` from `parent_asset_id`, which needs no stored sub-status, but the
  column holds 400 NULLs and costs nothing to keep. Dropping it early buys
  nothing and would have to be undone if that spec changes its mind.
