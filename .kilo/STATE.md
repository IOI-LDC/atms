# Session State — 2026-06-28

> **For AI agents:** Read this at the start of every session. It tells you what
> was done, what is decided, what is blocked, and what to tackle next.

## Session — 2026-08-04 (latest)

### ✅ Done — four requirements implemented and verified

All four requirements captured this session are now **implemented, migrated,
and verified** (docs updated in `docs/PRODUCT.md`, `docs/API.md`,
`docs/README.md`, and the user manual):

1. **User provisioning — direct creation (the decision below is implemented).**
   `POST /admin/users` + the frontend Create User dialog create users directly
   (name, email, role); account starts with a random password and
   `is_active: false`; an activation email with a one-time link lets the
   recipient set their own password. Email must belong to `@ldc.com.ly`
   (case-insensitive; allowlist config `atms.allowed_email_domains`, env
   `ATMS_ALLOWED_EMAIL_DOMAINS`, plumbed through compose.yaml to api/queue/
   scheduler). Employee-directory infra (EmployeeDirectorySource contract,
   employee endpoints/UI) removed; employee model/table remain as legacy data.
   Tests updated.
2. **Asset location at Tajoura Base.** New location "Tajoura Base" (code `TJB`,
   type `yard`); all assets relocated via migration; asset-movement history
   recorded.
3. **Operational status vocabulary.** DB values renamed `active` →
   `ready_for_field`, `inactive` → `scraped`; new `under_inspection` and `lih`;
   six values total (Ready for Field, Under Maintenance, Down, Under
   Inspection, Scraped, Lost in Hole). WO close/cancel asset-status choice:
   `down` | `ready_for_field` (pre-seeded to `ready_for_field`). Migrations
   effective on VPS via deploy.sh; frontend labels and tests updated.
4. **Meter-reading delta.** WO "Record meter reading" form enters the delta
   (amount operated since the last reading); Total (current meter reading)
   auto-calculates as `last reading + delta`, shown read-only; stored
   `reading_value` remains the absolute total. Edit dialog still edits the
   total.

### Historical context — the 2026-08-04 requirements capture

**User provisioning decision: no SharePoint directory, direct creation.**
Original plan: connect to LDC SharePoint employee list as the user directory
for provisioning. Decision: **set aside** (implemented 2026-08-04). The
SharePoint transport was never implemented (stubbed to throw), and the CSV
export is a development aid, not a production source.

The adopted approach: Administrator creates users directly by entering name,
email, and role. The email must belong to `@ldc.com.ly` (case-insensitive, with
an allowlist config for exceptions). The system creates the account with a
random password and `is_active: false`, then sends an activation email to the
given address. The recipient proves mailbox ownership by clicking the
activation link and setting their own password — that is the verification.

The Tajoura Base relocation and the operational-status changes were captured
earlier the same day as pending work (including an open question about DB
values vs display labels); both were implemented with DB-level migrations as
listed under "Done" above.

---

## Session — 2026-08-03

### Admin table visual fixes: centered-header alignment + wider Template columns

User-reported visual defects on Admin > PM Rules and Admin > WO Forms:

1. **Centered columns had left-aligned headers** (Level/Assets on PM Rules,
   Fields on WO Forms). Root cause: `.app-data-table-thead th` (specificity
   0,1,1, `text-align: left`) out-ranked `.app-data-table-th-center` (0,1,0),
   so data centered while headers stayed flush left. Fixed by re-scoping the
   center rule to `.app-data-table-thead th.app-data-table-th-center` — this
   also repairs the same latent misalignment on every centered column app-wide
   (Active columns etc.).
2. **Template column widened 180/200 → 320 px** on both tables
   (`minWidth` is the real width under `table-layout: fixed`).

Files: `style.css`, `PmRulesView.vue`, `WoFormsView.vue`. `vue-tsc --build`
clean. Frontend-only — needs rebuild/deploy to reach the VPS.

---

### Index `per_page` cap raised 100 → 5000 (assets/parts load-time fix)

User-reported slowness from the VPS: the Assets page took >2 s and the Parts
page ~2.1 s to load. Diagnosis (measured, not guessed):

- DB query for all 400 assets with eager loads: **5–7 ms** — the database is
  not the bottleneck.
- Warm HTTP request through nginx+FPM (local Docker): **~40 ms**.
- Against the VPS (`atmsapi.inova.krd`): **~310–440 ms per request**, of which
  ~150–300 ms is TLS/network and ~140 ms server processing.
- The frontend (`dataTableSource.fetchList`) loads the full set by following
  cursor pagination, but six index queries capped `per_page` at 100 while
  `WorkOrderIndexQuery`/`MaintenanceRequestIndexQuery` already allowed 5000.
  Result: 400 assets = 4 sequential round trips; 743 parts = 8.

**Change:** cap raised to 5000 in `AssetIndexQuery`, `PartIndexQuery`,
`EmployeeIndexQuery`, `PmRuleIndexQuery`, `FormTemplateIndexQuery`, and
`BuildAssetMaintenanceHistory` — now uniform across all index endpoints.
Frontend `FETCH_LIMIT` comments updated to match. New regression tests:
`AssetIndexPaginationTest`, `PartIndexPaginationTest` (105 rows returned in one
page; cap pinned at 5000). Full suite **1046 passed**, Pint clean.

Expected effect on VPS: Assets 4 requests → 1 (~350 ms), Parts 8 → 1 (~350 ms).
Committed `185c7c5`, pushed, deployed — verified live: assets now load in a
single 22.5 kB request (~0.7 s total, was >2 s).

---

## Session — 2026-08-02

### Phase 1 tidy-up per user decisions: Retired rename, D-007 deleted, no activity feed

User decisions from the Phase-1 "what's next" review, all handled this session:

1. **D-007 — `views/locations/AssetLocationUpdateView.vue` DELETED** (user: "No
   longer needed"). Zero references remained in the codebase (grep-verified);
   `git rm`, tracked in TLD.md as D-007 ✅.
2. **`operational_status = 'inactive'` now displays as "Retired"** — display-only
   rename, user-approved, no migration / no API change. Touched:
   `operationalStatusLabel` (badges), `reportOptions` (report filters),
   `useDashboardKpis` legend, `useReportCatalog` copy, the WO "Update Asset
   Status" and asset edit-form selects, `assetColumns` docblock, and the manual
   (§5.9 table + terminal-state section, §6.4, §9.1, R-10A, Appendix A).
   `is_active = false` keeps "Inactive" — the collision is resolved.
3. **No activity feed — ruled out by the user.** "Recent asset moves" is the
   final dashboard closing column; the previously flagged `audit_logs`-backed
   activity-feed endpoint will NOT be built. Decision recorded in STATE/TLD.
4. **Data repairs — user will reassign manually** ("L3 Maintenance Motors" PM
   rule categories; the 2 deactivated WO Form templates). No code action.
5. **Manual accuracy pass (2026-08-01→02):** user manual aligned to the code for
   the WO workflow — transition-table actors (Managers assignable/startable/
   completable), the start location gate + one-way move ("closing does not move
   the asset back"), cancel `asset_status` nuance, §8.10 actions. The All Assets
   table's Status column swapped from `maintenance_status` (400/400 `enrolled`
   = zero information) to **`operational_status`** — new `AssetOperationalStatus`
   type, filter options, badge cell, §9.1 corrected. The in-app manual renderer
   now emits ordered-list `start="N"` so step lists interrupted by sub-bullets
   no longer restart at 1; 19 grouping/hygiene edits (glued `---` rendered as
   literal text, glued headings, wrapped step lines, stale §13.2 dropdown
   groups, close-status FAQ, cross-ref ordering).
6. **All of the above is UNCOMMITTED** (plus the earlier close-status-picker
   docs, STATE/TLD updates, manual updates) — awaiting commit instruction.

---

## Session — 2026-08-01

### Close now asks for the asset's next operational status — and what the audit trail revealed

User-reported defect: closing a WO left the asset "down", which read as wrong.
Investigation first: close has **always** auto-reverted to `active`
(`ApplyWorkOrderAssetStatusTransition` on close), and the audit trail proves it
ran — asset 410 went `under_maintenance → active` the moment WO-000001 closed
(2026-07-30 11:56:48). The asset read `down` afterwards for a legitimate
reason: **a second corrective WO (WO-000002, created 5 minutes later) was still
open**, and its approval had re-set the asset `down`. There was **zero test
coverage** of the close→status behaviour, and nothing in the UI told the closer
what would happen to the asset. Both now fixed.

⚠️ **Data-integrity find, unresolved:** asset 410's status was written to `down`
at 2026-08-01 16:00:42 with **no audit entry** — the WO lifecycle, the WO
"Update Asset Status" button, and the asset edit form all audit their writes.
That write came from an import or a direct DB edit. Hand-editing statuses
bypasses the workflow every report is built on.

**Design (user-directed):** the close dialog now asks **"Asset status after
close"** — pre-seeded **Active** ("back in service"), the only other option
**Down** ("still faulty"); **no Inactive option** (retiring is an
asset-management decision, not a close decision). Decisions, so they are not
reopened: (a) **pre-seeded Active**, not blank — the closer actively switches
to Down only when the repair did not restore the asset; (b) backend
`asset_status` is **optional** (`in:down,active`), absent = `active`, so
existing callers behave exactly as before; (c) the never-un-retire-`inactive`
guard is unchanged; (d) **trust the closer** — no concurrency guard yet
(see P2-010).

**Implementation:** `CloseWorkOrder::execute()` gained
`?OperationalStatus $assetStatus` (defaults `ACTIVE`; the skip list narrowed to
`[INACTIVE]` — the `current === target` equality check already covers the
ACTIVE no-op). `WorkOrderController@close` validates and passes it through.
Frontend: `closeAssetStatus` ref in `useWorkOrderDetail` (pre-seeded, reset on
open, always sent), shadcn `Select` in the close dialog mirroring cancel's copy.

**Verified:** 5 new tests in `WorkOrderLifecycleTest` (2 RED-driven — `down`
keeps it down, invalid values 422; 3 pin existing behaviour — `active`/absent
revert, `inactive` untouched). Full suite **1042 passed (3119 assertions)**;
Pint, `vue-tsc`, and `oxfmt` clean. Committed as `1d94767` together with the
meter-readings/start-location work that was already in the tree, per user
request.

**Deferred to Phase 2 (Asset Assembly)** — see `.kilo/TLD.md` P2-009 / P2-010:
(1) `waiting_for_parts` flag + close guard + **time-waiting KPI** (the KPI
forces timestamped transitions — `waiting_for_parts_at` / cleared-at — not a
bare boolean); (2) **single open WO per asset**, enforced at MR approval
(409 naming the conflicting WO, asset-row lock against races). Live data shows
no violations today, but nothing enforces it.

---

## Session — 2026-08-01

### Work Order page: asset location shown, workshop transfer forced on start, readings attributed

Three defects found in live testing, all on `WorkOrderDetailView`. **1037 tests
pass (3089 assertions)**, Pint clean, `vue-tsc --build` clean. Verified against
the live stack over HTTP, not tests alone.

**1 — The work order never showed where the asset was.** `WorkOrderResource`'s
asset fragment now carries `current_location {id, name, code, type}` beside
`operational_status` (`AssetIdentityResource` untouched — its shared shape is
deliberately strict). `type` rides along because the page *routes on it*, not
just displays it. Eager-loaded in `WorkOrderController@show` and
`WorkOrderIndexQuery`. The rail's "Asset status" card gained **Current
location** with a tinted `locationTypeClass` badge; `Current status` was
badged at the same time (it was bare text here but badged in `AssetDetailView`).

**2 — Assets were being "repaired" on rigs. ⚠️ `POST /work-orders/{id}/start`
is now a hard gate.** Nothing in the WO lifecycle had ever touched
`assets.current_location_id`, so an asset started work while still recorded at
Rig A — and `AssetDeployment` buckets `rig`/`well_site` as **DEPLOYED**, so the
dashboard counted it as earning while it was in pieces. Start now refuses
(`DomainException` → 409) unless the asset is at a `workshop` or `yard`, or a
`location_id` of one of those types is supplied in the same request, which is
applied through `UpdateAssetLocation` inside the same transaction with reason
`Started work order WO-xxxx`.

> **Decisions, so they are not reopened.** (a) **Hard block, not a warning** —
> the user chose this over a "Start anyway" escape; a soft prompt leaves exactly
> the bad data that prompted the work. (b) **Workshops *and* yards** are valid
> work locations, not workshops alone. (c) **Whoever may start the WO may
> perform the move, technicians included** — `AssetPolicy::updateLocation`
> (admin/manager/logistics) is deliberately *not* consulted, because this is a
> work-order transition, not a logistics move. The standalone
> `POST /assets/{id}/location` route keeps its narrower policy. (d) A **null or
> unrecognised** location type counts as "must choose", matching
> `AssetDeployment`'s habit of surfacing unclassified rather than absorbing it.

> ⚠️ **Live-data consequence the user must plan for: 396 of 400 assets have no
> location at all.** So in practice *every* work order start hits the dialog
> today, not just the rig edge case. That is the guard working as designed — it
> is how location data starts getting populated — but it is a workflow change
> for every technician, not a rare prompt. Revisit only if LDC pushes back.

Blast radius on the suite was real: `createAsset()` helpers made assets with no
location, and start is a setup step in ~20 tests across 7 files. Added
`TestCase::workshopLocation()` (shared `firstOrCreate` on code `WS`) and pointed
every helper at it. New `StartWorkOrderLocationTest` (14 tests) pins the guard,
the recorded move, the technician permission, and both audit events.

**3 — The readings table claimed work it had not done.** The card listed every
reading the asset ever had, with no way to tell which belonged to the job on
screen. `asset_meter_readings` gained a nullable `work_order_id`
(`nullOnDelete` — a reading is a measurement of the *asset* and outlives the WO
that prompted it) plus an `(asset_id, work_order_id)` index. Existing rows stay
null and read as history, which is accurate. The card now shows **Recorded on
this work order** first and a collapsed **Asset reading history (N earlier)**
below; table markup extracted to `WoReadingsTable.vue` so the two cannot drift.
`sinceLastService` still derives from the **full** list — it is a meter
progression, not a per-WO figure. Edit/delete stayed on history rows: the
complaint was attribution, not permissions.

> `RecordMeterReading`'s new `$workOrderId` is **appended last**. The existing
> `?int $maintenanceRequestId` sits at position 7 and callers pass positionally.

**Adjacent fix, load-bearing for the guard:** `workshop_yard` was selectable in
`LocationForm.vue` and seeded by `LocationSeeder`, but is not a `LocationType`
case — so such a location silently dropped out of every utilisation figure and
would have failed the new start guard for no reason. Removed from the form
options; seeder corrected to `yard` (which is what the prod baseline and live DB
already hold for that row). The `displayHelpers` label survives so legacy rows
still render.

---

## Session — 2026-08-01 (later)

### Maintenance Category as the ATMS routing key — all four phases BUILT

The 2026-07-31 design landed in the agreed order: **P0 D-013 → P1 category NOT
NULL → P2 D-011 → P3 D-012**. Backend **1019 tests pass (3007 assertions)**,
Pint clean, `vue-tsc --build` clean. Not committed — the working tree also held
another session's CSV-export/reports work, so committing was left to the user.

**Two tracker facts were wrong and are now corrected.** `maintenance_category_id`
had **0 nulls of 400**, not 2 — the backfill had already happened. And the
"blocked on 16 uncommitted files" note was stale; `cc53090` had landed.

**P0 — D-013 PM evaluation now costs a fixed number of queries.**
`PmDueCalculator`'s batch branches existed for months with no caller. Now:
`PmEvaluationBatch` builds the readings/suppressions maps in a fixed number of
queries (latest-by-`reading_at` in two grouped aggregates, matching the
per-assignment path); `PmEvaluationRunner` checks due-ness **before** opening a
transaction, so the `lockForUpdate` is paid only for assignments that actually
look due — the old loop locked all 1,600 to discover nothing was due;
`EvaluatePmRulesJob` chunks ids and fans out `EvaluatePmAssignmentsJob` per
chunk; `isTriggeredByDate`/`isTriggeredByReading` accept the collections they
used to hardcode `null` for. `AssetPmAssignment::scopeEvaluable` is now the one
definition of the evaluated population (`UpcomingPmReportQuery` mirrors it).
`evaluateAll` on the controller was chunked through the same runner.

> **Suppression payloads are flattened to arrays on purpose.** The calculator
> compares `suppressed_until_date` against `now()->toDateString()`; the model
> casts that column to Carbon, and a Carbon-vs-string comparison in PHP does not
> mean what it looks like — every date suppression would have read as active.

`PmEvaluationScaleTest` pins it: query count is **identical** at 5 and 25
assignments, and under 15 in total.

**P1 — `assets.maintenance_category_id` is NOT NULL, defaulting to a seeded
`UNCLASSIFIED` category.** ⚠️ **The sentinel was the decision, and the reason is
the ERP sync:** `ImportErpAssetsCommand::mapRow()` creates assets with no
category and there is no subclass→category mapping to infer one from, so a bare
NOT NULL would have broken every new ERP asset. The migration captures the
sentinel's real id and sets it as the column DEFAULT, so a fresh test database
and production agree by construction. Also: blank category cells in
`atms:import-assets` now mean "leave what is there" instead of clearing;
`PATCH /assets/{id}` rejects an explicit null category (422); unknown
`fa_subclass_code` values are **recorded with type code `UNK`** instead of
failing the row (the admin screen that was the only remedy is gone).
`MaintenanceCategoryController` refuses to deactivate Unclassified.

> **Consequence worth knowing: the "Uncategorised" report bucket is now
> unreachable for assets.** An unclassified asset appears as the real
> `UNCLASSIFIED` category — so it is counted in `total_groups`, filterable, and
> visible, which is exactly why the sentinel was chosen over a bare NOT NULL.
> Parts keep a nullable category, so the null branches in report and
> compatibility code stay live for them.

**P2 — D-011 WO Forms route by Maintenance Category.** `form_templates
.fa_subclass_code` dropped; new `form_template_maintenance_category` pivot with
**mirrored `is_active`** + partial unique index
`form_template_active_category_unique ON (maintenance_category_id) WHERE
is_active`. `FormTemplateCategoryPivot` owns the mirror and the conflict guard,
which throws a message naming the colliding category *and* the template holding
it. Existing templates migrated **deactivated with no categories**, and
reactivation now refuses a template with no categories at all. Coverage is
**editable** after creation (unlike the immutable `fa_subclass_code` it
replaced). `SnapshotFormTemplateIntoWorkOrder` + `SyncWorkOrderFormToLatest`
re-pointed. The 4 `/admin/fa-subclass-type-codes` routes and their controller are
**deleted**; `/list-options/fa_subclass_type_codes` stays (report filters).

**P3 — D-012 PM rules assignable to categories.** `pm_rule_maintenance_category`
pivot, plus `origin` (`manual`|`category`) and `source_maintenance_category_id`
on `asset_pm_assignments`. `ReconcilePmCategoryAssignments` expands the link into
rows; `ReconcilePmCategoryAssignmentsJob` runs it off the request, overlap-keyed
**per scope** (`pm-category-reconcile:rule-7`) so two edits to one rule cannot
interleave while unrelated ones still run in parallel. Both directions chunk.
Hooks: rule created/updated/deactivated/reactivated, asset category /
`is_active` / `maintenance_status` changed, and the workbook import (**once at
the end, one job per rule** — never per row).

> **`asset_pm_assignments.assigned_by` is now nullable, and that is load-bearing.**
> A null actor on `assigned_by`/`deactivated_by` means *reconciliation did this*,
> a filled one means *a person did*. That distinction is the entire precedence
> rule: reconciliation may restore a row it withdrew itself, but must never
> reactivate one a human deactivated — otherwise a per-asset opt-out silently
> reverts on the next sync. An assignment with an open MR/WO is skipped, not
> forced.

**Frontend.** New shared `components/app/MaintenanceCategoryPicker.vue`
(searchable multi-select; categories claimed by another active form show
disabled *with the holder's name* rather than vanishing) used by both the WO
Form sheet and the PM rule sheet. `WoFormsView` column is now "Maintenance
Categories"; PM rule form gained an "Applies To" picker shared across an L1–L4
batch; asset PM rows show a **"By category"** badge so a schedule nobody
assigned is explicable. `useWoForms` dropped its FA-subclass fetch and gained
`saveError` for the 409. In-app user manual §8.7 and the asset field table
updated.

**Contract changes for the frontend/API consumers:** `FormTemplateResource
.fa_subclass_code` → `maintenance_categories[]`; `POST/PATCH /admin/wo-forms
/templates` take `maintenance_category_ids[]`; template list filter
`?fa_subclass_code=` → `?maintenance_category_id=`; `PmRuleResource` gained
`maintenance_categories`; `AssetPmAssignmentResource` gained `origin` +
`source_maintenance_category` (additive).

### Same session — defects found in use, after the four phases landed

**🐛 Every `ReconcilePmCategoryAssignmentsJob` had been failing silently.** The
`queue` container had been up 46 hours, so it was serving `OverlapKeys` from
before the new constant existed — `Undefined constant …PM_CATEGORY_RECONCILE`
against code that was plainly on disk. PM rules showed their coverage while
producing **no assignments at all**, and nothing surfaced in the UI. Restarting
`queue` and re-dispatching produced 515 assignments. **A long-running worker
holds classes from boot; restart `queue` and `scheduler` after any job or Action
change.** Written up in `docs/OPERATIONS.md`.

**🐛 Editing a PM rule from the list wiped its category coverage.**
`PmRuleIndexQuery` did not eager-load `maintenanceCategories`, so the edit sheet
opened with an empty selection and posted `[]` on save. This is what emptied
"L3 Maintenance Motors" — **its coverage is genuinely lost and needs
re-assigning.** Fixed, with a regression test that opens the list and re-saves.
Any list feeding an edit form must load every relation that form submits back.

**🐛 A non-modal sheet keeps the table behind it clickable.** Both the WO Form and
PM Rule sheets reset their state only on the open transition, so clicking "Edit"
on a second row swapped `editing` while `open` stayed true and the form kept the
previous record's values — writing them over the newly selected one on save.
`WoFormsView` already guarded this with `if (formOpen.value) return`;
`PmRulesView` did not. Both sheets now re-initialise when the edited record's
identity changes, excluding the create→edit flip.

**Picker usability (user-directed).** Selected categories now **pin to the top**
of `MaintenanceCategoryPicker` and are shaded — with ~25 categories in a fixed
height box, a purely alphabetical list hid a record's own categories below the
fold, which read as "not selected". Also fixed: `AppDataTable.toSearchable()`
ignored arrays, so the new Maintenance Categories column matched no search; and
two `<Label for>` attributes in `PmRuleForm` pointed at ids that never existed.

> **Trade-off left open:** the pin-to-top sort is live, so unticking a row makes
> it drop out from under the cursor. Freeze the order while the picker is open if
> that proves annoying.

> ⚠️ **Process note: `pint --dirty` does nothing in this container.** `.git`
> lives at the repo root, outside the `backend/` mount, so Pint finds no git
> repo and reports "0 files". Pass explicit paths instead —
> `docker exec atms-api vendor/bin/pint app/... tests/...`. CLAUDE.md's
> `--dirty` instruction is silently a no-op.

## Session — 2026-08-01

### Reports: FA Subclass swept out, two new reports, CSV export started

**1. FA Subclass removed from every report — Maintenance Category replaces it.**
Extends the D-011 principle from *routing* to *reporting*: ATMS reports group and
filter only on fields ATMS governs. `fa_subclass_code` is still populated on
400/400 assets and still exposed on the **asset detail** API (`AssetResource`)
and screens — this change is scoped to reports.

- `AssetReportDimension`: `asset_class` case **deleted**; the docblock now says
  not to reinstate it.
- Filter param renamed across 7 reports: `fa_subclass_code` (string) →
  `maintenance_category_id` (int, `exists:maintenance_categories,id`). IDs match
  the `location_id` / `asset_id` convention; group **keys** still use the stable
  category `code` per the existing rule.
- `group_by=asset_class` now returns **422** on MTBF, MTTR, and Bad Actor, each
  with a test that guards against reinstatement.
- Response contracts changed: `AssetStatusReportItemResource` dropped
  `fa_subclass_code`; `PartsConsumptionReportItemResource` renamed `asset_class`
  → `asset_maintenance_category`; `FormResultReportItemResource` renamed
  `asset.asset_class` → `asset.maintenance_category`.
- `PartsConsumptionReportQuery` now joins `maintenance_categories` **twice** —
  the part's category and, aliased as `asset_categories`, the asset's. They are
  different things and both appear on every row.
- Frontend: 7 composables, 7 views, `types/index.ts`, and
  `DIMENSION_GROUP_BY_OPTIONS` / `MTTR_GROUP_BY_OPTIONS` updated. New
  `toMaintenanceCategoryIdFilterOptions()` keys by **id** for server-side report
  filters, beside the existing name-keyed helper used by client-side tables.

**2. R-2 generalised: "Assets by Location" → Asset Distribution.** Same
aggregate, now pivotable by **location | maintenance_category | size** via
`?group_by=`. All three are plain columns on `assets`, so one grouped aggregate
serves every dimension — deliberately unlike MTBF/MTTR/Bad Actor, which resolve
per row in PHP because they group *activity* by its asset's attributes.
`AssetsByLocationReportQuery` → `AssetDistributionReportQuery`; row shape
`location_id`/`location_name` → generic `group_key`/`group_label`, and
`summary.total_locations` → `total_groups`. Sizes sort **numerically** (the
column is `numeric(9,5)`) and label through `Size::format()` into O&G notation.
`GET /api/reports/assets-by-location` and `/reports/assets-by-location` both
still resolve — the old paths are kept as aliases. **DashboardView consumes this
report** and was migrated with it.

**3. R-22 Most-Used Assets — new.** Answers "which asset has been used the
most", against one usage reading type at a time. The three existing
`usage_reading_types` rows map exactly onto the ask: Operating Hours, Kilometer
Driven, Depth.

> **The measurement decision, because it is easy to get wrong:** these meters are
> cumulative, so usage is a *difference*, not a sum. `usage = end_value −
> baseline_value`, where the baseline is the last confirmed reading **before**
> the window, falling back to the window's floor for a newly-metered asset. A
> naive max-minus-min inside the window reports **zero** for any asset with a
> single reading in range — which is exactly the busy-asset case (one reading
> out, one reading back). Only confirmed readings count, matching
> `PmDueCalculator`. Meters are assumed monotonic; a meter replacement would
> understate usage, and ATMS has no reset concept to detect that.

Units never mix: the reading type is a required dimension, and `reading_type.unit`
travels with the response so the UI and CSV can label every number. Groupable by
maintenance category or size; ranked top-N, and the summary spans **all** assets,
not just the rows shown.

**4. CSV export (D-010) — mechanism built and proven on 3 of 21 reports.**
`?format=csv` on the **existing** endpoint rather than new `/export` routes, so
authorization, filter validation, and sorting are reused and the file provably
matches the table above it. `App\Support\Reports\CsvReportStreamer` bakes in
three decisions: a **UTF-8 BOM** (without it Excel renders Arabic asset and
location names as mojibake), timestamps in `config('atms.company_timezone')`
rather than UTC, and true streaming so unbounded listings stay flat in memory.
Column maps live in `ReportCsvColumns` with human headers ("Asset Tag", not
`asset_tag`) in a deliberate order.

Cursor-paginated queries expose a `stream` closure beside `paginator`
(`(clone $rows)->lazy(500)`) so the export is the **whole** result set, not one
page — verified by a test that exports 12 rows with `per_page=5`.

**Wired so far:** R-1 Assets Status (cursor/streaming), R-2 Asset Distribution,
R-22 Most-Used Assets. **Remaining: 18 reports** — 10 cursor (Asset Movement,
Meter Progression, Overdue PM, Parts Consumption, PM Coverage, PM Suppression,
Technician Workload, Throughput, Form Results, WO Backlog) each needing the
3-line `stream` closure plus a column map, and 8 aggregate (MTBF, MTTR, Bad
Actor, PM Compliance, Upcoming PM, Operational Status, Booking) needing only a
column map. **No frontend Export button exists yet on any report.**

> ⚠️ **Process note:** running bare `pint` (not `pint --dirty`) reformatted ~40
> unrelated files, and `npm run format` runs oxfmt over all of `src/`. Both were
> reverted to keep the diff reviewable. Use `pint --dirty`, and check
> `git status` after `npm run format`.

**5. R-2 reworked again — multi-dimensional grouping.** The first build gave a
single-dimension dropdown; the actual requirement was **all three at once**.
`group_by` is now an **array** (`?group_by[]=maintenance_category&group_by[]=size&group_by[]=location`),
producing one row per distinct combination — 82 rows across the live 400 assets.
A bare `group_by=location` string still works, so the dashboard and old links are
unaffected.

- Row shape changed again: `group_key`/`group_label` → **`groups: [{dimension,
  key, label, is_unassigned}]`**, one entry per requested dimension in the
  requested order. `DashboardView` reads `groups[0]`.
- **Order is meaningful and preserved.** Column order follows the order the user
  selects, not a canonical order. `ToggleGroup` reports its value as an unordered
  set, so `onDimensionsChange` reconciles: existing selections keep position,
  new ones append. Without that the columns would silently follow DOM order.
- `summary.total_groups` counts only rows where **no** dimension is a null
  bucket, which keeps "N locations" reading the same as before multi-grouping.
- `api.ts buildUrl()` gained array support (`key[]=a&key[]=b`) — it previously
  stringified arrays to `"a,b"`. Shared client change, additive.

**6. `/reports` now lists all 20 live reports.** It rendered `mustTierThemes`
(7 Pass-1 reports) while 13 built, routable reports were reachable only by URL —
including the new R-22. Switched to `availableThemes`; `mustTierThemes` removed
as dead. **`/reports-verification` is now redundant and can be deleted** — it
existed only to show the full catalogue.

**7. UI fixes across reports.**

- **Table header/data misalignment — a specificity bug affecting all 14 reports
  with numeric columns.** `.report-table thead th` is **(0,1,2)** and beat
  `.report-table-num` at **(0,1,0)**, so numeric *headers* stayed left-aligned
  over right-aligned figures. Fixed with `.report-table th.report-table-num,
  .report-table td.report-table-num` **(0,2,1)**. Audited the other two failure
  modes as well — `<th>`/`<td>` counts and numeric-class positions — both clean
  across all 21 tables.
- **Asset picker moved last and made to grow** in all 5 reports that have one
  (`.report-filter-asset`, `flex: 1 1 22rem`). At the 11rem default both the
  input *and its dropdown* truncated, because `.asset-combobox-panel` inherits
  `--reka-popover-trigger-width`.
- R-2's dimension picker rebuilt from checkboxes to `toggle-group` chips with a
  position badge; selected state uses a **tinted wash**, not the primary fill —
  the shared Toggle's `bg-muted` on-state was too quiet, but a solid fill was too
  heavy. Overridden from the feature side so no other Toggle changed.
- R-2's "include deactivated assets" checkbox **removed** (UI only — the
  `include_inactive` param still exists on the endpoint). Other reports keep
  theirs.

**8. Export button — live on the 3 CSV-capable reports only.** No backend
change. `api.download()` added to the shared client (fetch → blob → anchor, not
`<a href>`: a plain navigation bypasses `VITE_API_ORIGIN` and turns an auth error
into raw text replacing the app). `useReportCsvExport` composable; filename comes
from the server's `Content-Disposition`.

> **Export sends the *applied* filters, not the current form state.** Each view
> keeps `appliedFilters` and updates it inside `runLoad()`. Otherwise editing a
> filter without pressing Apply would download a file that disagrees with the
> table on screen — unacceptable for a file forwarded to LDC.

**Verified:** 983 backend tests (2912 assertions), Pint clean, `vue-tsc` clean.
Commit `cc53090` carries 72 files of the above.

## Session — 2026-07-31

### Maintenance Category as the ATMS routing key — D-011 / D-012 / D-013 designed, not yet built

**The governing principle (this generalises — apply it to any future routing key).**
`assets.fa_subclass_code` was never "dropped from the DB" — it is populated on
400/400 assets and is a filter dimension in 7 reports. What changed is
**operational control**: it is written by the ERP sync
(`ImportErpAssetsCommand:153`, from ERP's `faSubclassCode`), so ATMS cannot
govern it. `maintenance_categories` is ATMS-owned — Admin-editable, and its own
model docblock says *"Local ATMS data, unrelated to any ERP classification."*

> **A field ATMS does not control must not route behaviour ATMS is accountable
> for.** Describing is fine (reports may filter on subclass forever); controlling
> is not (forms and PM rules may not).

Consequences already agreed:

- The 4 `/admin/fa-subclass-type-codes` CRUD routes + controller are **deleted**
  (`routes/api.php:111-114`). Verified: the only consumer is the WO Forms
  dropdown at `useWoForms.ts:76`, which D-011 removes; no backend test hits them.
  `/list-options/fa_subclass_type_codes` is a **different** controller and stays —
  report filters need it.
- `ImportAssetsCommand:136-189` hard-rejects an asset whose `fa_subclass_code`
  isn't in the lookup table — ATMS gating on ERP-owned vocabulary, with the only
  remedy being a route whose UI no longer exists. **Decided: auto-create the
  lookup row on sight.** The list mirrors ERP's vocabulary; it should follow it,
  not fence it.
- **`assets.maintenance_category_id` must become NOT NULL** (2 of 400 are null
  today). Under this principle it is the *only* ATMS-owned handle on an asset, so
  an asset without one is an asset ATMS cannot govern at all — no form, no PM, no
  remedy. Mirrors what `90bdead` did for location code.

**D-011 — WO Forms route by category.** `form_templates.fa_subclass_code` →
`form_template_maintenance_category` pivot (many-to-many: one form serves several
categories). Assets carry exactly one category, so resolution stays deterministic
**only if** at most one *active* template exists per category — the uniqueness
guarantee moves from the templates table to the pivot. **Chosen enforcement:**
mirror `is_active` onto the pivot + partial unique index
`ON (maintenance_category_id) WHERE is_active`, matching the existing
`form_templates_active_subclass_unique` backstop pattern (422 first, index as
backstop). Rejected: validation-only (loses the guarantee the current design
deliberately has) and a DB trigger (nothing else here uses triggers). Re-point
`SnapshotFormTemplateIntoWorkOrder` **and** `SyncWorkOrderFormToLatest` (incl. its
"…for this asset **subclass**" message) and the `FormTemplateIndexQuery` filter.
Existing templates migrate **deactivated** for manual reassignment — there is no
subclass→category mapping (25 categories vs 20 subclasses). **This cost grows
with delay:** every template built before D-011 lands is another hand-remap.

**D-012 — PM Rules assignable to a category.** `pm_rule_maintenance_category`
pivot, plus `origin` (`manual`|`category`) and a source-category column on
`asset_pm_assignments`.

- **Materialize, don't resolve.** Per-asset PM state is inherent — each
  assignment owns its `last_triggered_date` / `last_triggered_reading` — so a
  category link can only *create and maintain* rows, never replace them. The
  deciding argument is the baseline: `CreateAssetPmAssignment:18` stamps
  `last_triggered_date = now()` so a newly-assigned asset gets one full interval
  of grace. Dynamic resolution has **no moment** at which to do that; every asset
  would land either immediately overdue or never due. Materializing also leaves
  `EvaluatePmRulesJob`, the L1–L4 cascade in `CloseWorkOrder:131`, and all four PM
  reports untouched.
- **Precedence rule (decided):** reconciliation only ever *creates* and
  *deactivates-on-leaving*. It never reactivates an assignment a human
  deactivated (`deactivated_by IS NOT NULL` → leave alone), otherwise a per-asset
  opt-out silently reverts on the next sync.
- **Reconciliation is the real work,** not the pivot: asset created, asset
  changes category, category added to / removed from a rule, and the bulk import
  path (reconcile **once at the end**, never per row).
- Expansion is a **queued, batched job with one audit entry for the operation** —
  not N `pm_assignment.created` rows for an N-asset category.

**D-013 — `EvaluatePmRulesJob` does not scale; fix first, standalone.**
`PmDueCalculator::isDue()` already accepts pre-loaded `$readings` /
`$suppressions` collections to avoid N+1 — **the batch path was built and is
unused.** The job does `->get()` on every active assignment
(`EvaluatePmRulesJob:31`) and `EvaluatePmRule:34` calls `isDue($locked)` with one
argument, so each assignment costs ~6–12 queries inside its own transaction even
when nothing is due — and `isTriggeredByDate` / `isTriggeredByReading` hardcode
`null`, re-running the same queries a second time. `maintenance_level` is an
**L1–L4** scheme, so ~4 rules/asset is the *designed* shape: 400 assets ≈ 1,600
assignments ≈ 10–19k queries against `timeout = 300`. It will not finish. D-012 is
precisely what turns a handful of assignments into thousands in one click, so
this lands first: `chunkById`, pass the collections, extend the two
`isTriggeredBy*` methods to accept them, fan out per-chunk child jobs.

> **Note on sizing:** today's counts (2 form templates, 3 PM rules, 1 assignment)
> are development-stage artefacts, **not** the target state — the app is still
> being built out. Every decision above is sized for production volume, not for
> what is in the dev database.

**Agreed order:** P0 D-013 → P1 category NOT NULL → P2 D-011 → P3 D-012, then
D-010 CSV export (unaffected by any of it).

- **D-008 Proper Booking model — BUILT (full-stack).** Replaced the bare `is_booked`
  boolean toggle with a dedicated `bookings` table. Schema: `asset_id`, `booked_by`
  (FK→users), `booked_from`/`booked_until` (dates), `booking_reference` (nullable),
  `notes` (nullable), `status` enum (`active`/`cancelled`/`released`), `cancelled_at`,
  timestamps. Composite index on `(asset_id, status, booked_from, booked_until)`.
  - **Backend:** `BookingStatus` enum; `Booking` model with `active`/`coveringDate`/
    `overlapping` scopes; `BookingResource`; `BookingPolicy` (Admin/Manager/Logistics);
    `CreateAssetBooking` (overlap detection), `CancelAssetBooking`, `ReleaseAssetBookings`
    actions; rewritten `AssetBookingController` with 3 endpoints
    (`GET/POST /assets/{id}/bookings`, `POST /assets/{id}/bookings/{booking}/cancel`);
    `Asset.is_booked` is now a derived accessor (`getIsBookedAttribute`);
    `Asset.booted()` releases bookings on deactivation/withdrawal;
    `UpdateAssetLocation` no longer clears booking (location change ≠ release);
    `BookingReportQuery`, `AssetHealthKpiQuery`, `AssetUtilisationQuery`,
    `AssetsByLocationReportQuery` all migrated to query the bookings table;
    data migration backfills existing `is_booked=true` rows then drops the column.
  - **Frontend:** `Booking` TS interface; `useAssetDetail` composable rewritten
    (form-based create with date pickers + reference + notes, cancel confirm dialog,
    `loadBookings` on mount); `AssetDetailView.vue` booking form dialog + cancel
    dialog replace the old one-click toggle.
  - **Frontend enhancements (same session):** Bookings card moved to right rail
    (compact Reference + Status rows, clickable); row click → detail Dialog showing
    all fields; Edit button → pre-filled form Dialog (`PUT` endpoint);
    overlap → 409 with `conflicts` array → inline warning + "Book Anyway" force
    button; `AssetIdentityBadges` gained a `<slot>` so the "Booked" badge renders
    inline with serial/size/category badges in the asset list; `DatePicker` gained
    `disablePortal` prop for use inside modal Dialogs (§8.3 fix); `.status-released`
    badge class added (grey, same as cancelled).
  - **Backend enhancements:** `UpdateAssetBooking` action + `PUT /assets/{id}/bookings/{booking}`
    route; `BookingOverlapException` carries conflicting bookings; `store` accepts
    `force: true` to bypass overlap; `BookingPolicy@update` added.
  - **Verified:** 933 tests passed (2759 assertions), Pint clean, `vue-tsc --build`
    clean. Old `ToggleAssetBooking` action is now dead code (can be deleted).
  - **API contract change:** `POST /assets/{id}/book` and `/unbook` are **removed**.
    New endpoints return `BookingResource` (201 on create). `AssetResource.is_booked`
    is still emitted (derived) — no frontend list-view breakage.

- **Git history reset by the user.** The repository was re-initialised: a single
  `Initial commit` (1075 tracked files) on `main`, empty reflog. **No prior commit
  or file version is recoverable** — there is no diff baseline, and `git checkout`
  cannot restore a previous state of any file. Commit at natural stopping points.
- **`CLAUDE.md` rewritten from scratch** at the repo root, derived only from live
  code, config, and a verified test run. `backend/CLAUDE.md` and
  `frontend/CLAUDE.md` are gone — **one root file now**, by user decision. Covers:
  container-only backend commands (no host PHP/Composer), the
  controller→Action→Resource flow, cursor pagination everywhere, the root-`.env`
  precedence trap, and the `phpunit.xml` forced-`<env>`+`<server>`-twin rule.
  Closes deferred item **D-002**.
- **Verified baseline on the new initial commit:** backend **911 passed (2666
  assertions, 24s)**; frontend `vue-tsc --build` clean.
- **Frontend route cleanup.** `/locations2` and `views/locations/LocationsView.vue`
  deleted (nothing referenced either; `ManageLocationsView` survives via
  `LogisticsLocationView`). ⚠️ **`views/locations/AssetLocationUpdateView.vue` is
  now orphaned** — `LocationsView` was its only consumer. Left in place pending a
  keep/delete decision.
- **`/dashboard-real` and `/reports-real` gated with `meta: { requiresAdmin: true }`.**
  They carried no guard, so they shipped in the production bundle and were
  reachable by any authenticated user who typed the URL. They exist purely for
  internal verification and are **never** to reach the client product.

### Dashboard — BUILT 2026-07-31 (design notes below it)

**`/dashboard` is THE dashboard — final, client-facing, the only one that ships.**
Components renamed to stop the placeholder confusion recurring:

| Route | Component | Status |
|---|---|---|
| `/dashboard` | `DashboardView.vue` | **Final.** Rebuilt to the approved layout |
| `/dashboard-verification` | `DashboardVerificationView.vue` | Admin-only, disposable, delete after sign-off |

**Backend (926 → 933 tests passing, Pint clean):**

- **`App\Enums\AssetDeployment`** — ⚠️ **the single source of truth for "out for
  work vs idle."** If LDC defines deployment differently, change
  `forLocationType()` and nothing else. Mapping: `rig` + `well_site` → DEPLOYED,
  `yard` + `building` → IDLE, `workshop` → MAINTENANCE. Workshop is deliberately
  its own bucket — counting maintenance as idle makes the maintenance function
  look like dead time.
- **`App\Enums\LocationType`** — the `locations.type` vocabulary. Deliberately
  **not** cast on the Location model: LDC can add a type at any time and a cast
  would throw on hydration. Read via `tryFrom()`; an unknown type is reported as
  `unclassified` rather than absorbed into a bucket, so a new type is visible
  instead of silently distorting the percentage. A test asserts every type in the
  database maps.
- **`AssetUtilisationQuery`** — population is active + enrolled. Denominator
  (`eligible`) excludes DOWN / UNDER_MAINTENANCE and anything unlocated;
  `unlocated` is reported separately so the data gap stays visible instead of
  being hidden inside a ratio.
- **`ProgramReadinessQuery`** — PM coverage, location recorded, baseline reading.
- `AssetHealthKpiQuery` gained `by_booking` (the second status axis). All of it is
  served from the existing
  `GET /api/dashboard/kpis` under new `utilisation` and `readiness` keys —
  window-independent by design, since the dashboard has no date range.

**Frontend:** `DashboardView.vue` rebuilt to the 12-column grid
(triad → full → pair → pair → triad → pair); derived values live in
`useDashboardKpis` (`utilisationSegments`, `utilisationBasis`, `readinessMetrics`,
`statusAxes`), not the view. New `components/ui/segmented-bar` primitive holds the
data-driven segment widths so no feature file carries an inline style. Empty
states are written copy ("No failures yet"), never an em-dash.

**⛔ SCOPE RULE (user decision 2026-07-31): withdrawal is ERP territory.**
`maintenance_status = withdrawn` and every `maintenance_sub_status`
(`lih`, `dbr`, `disposed`, `scrapped`, `other`, `installed`, `ready`) are owned and
managed in the ERP. **ATMS must not surface, count, or report them** — do not add a
"Withdrawn" axis, a disposal count, or a sub-status breakdown to any dashboard or
report. `by_maintenance_status` was built and then **removed** for this reason.
The `maintenance_status = enrolled` filter stays as an internal population guard
(withdrawn assets are excluded from ATMS metrics); it is never displayed.

**Asset status card = plain count rows** (user decision, after two rejected bar
treatments). Four operational rows — **Active, Under Maintenance, Down, Inactive,
always all four even at zero** — then an `<hr>`, then **Booked**. Each row carries a
7px status dot; no bars in this card. Booking sits below the separator because it
is a **different axis, not a fifth operational state**: an asset can be Booked and
Under Maintenance at once, so the counts either side of the rule deliberately do
not sum. ⚠️ Two earlier attempts were rejected — a per-axis progress fill (each row
a different numerator, so one visual meant three things) and 100%-stacked bars per
axis. Don't reintroduce either.

**RESOLVED 2026-08-02 (user decision):** `operational_status = 'inactive'` now
renders as **Retired** — a display-only rename, no migration, no API change
(badges, report filters, dashboard legend, catalogue copy, WO/asset selects,
manual). The record-level `is_active = false` keeps its "Inactive" wording —
the collision is gone.

✅ **DECIDED 2026-08-02 (user):** **no activity feed.** "Recent asset moves" is
the final closing column of the dashboard pair — do not build an
`audit_logs`-backed activity feed.

### Reports — R-1 BUILT 2026-07-31, export still open

**`/reports` is THE reports index** — `ReportsView.vue`, final and client-facing.
The earlier catalogue-driven index is now `/reports-verification` +
`ReportsVerificationView.vue` (admin-only, disposable). Same rename treatment as
the dashboard; do not add a third.

**⛔ Two catalogue entries REMOVED, not deferred (2026-07-31):** R-10B Maintenance
Lifecycle Status Distribution and R-11 Lost / Decommissioned Assets both reported
on `withdrawn` + its sub-statuses (LIH, DBR, Disposed, Scrapped) — ERP territory.
They were labelled "deferred to Phase 2", which told the next session to build them
eventually. Gone, with a comment in `useReportCatalog.ts` explaining why.
⚠️ **R-12 Spare / Rotor Pool correctly stays deferred** — it uses `installed`/`ready`,
which are *enrolled* sub-statuses tied to the Phase 2 asset-assembly model, not
withdrawal. Catalogue is now 20 entries: 19 available, 2 deferred (R-5, R-12).

**R-1 Assets Status Report — BUILT (LDC's "Report 1").** `GET /api/reports/asset-status`,
cursor-paginated, at `/reports/asset-status`. It is the only **listing** in the
catalogue; every other report is an analysis. Columns are exactly LDC's ask: Asset
Tag, Name, Type, Status, Location, Assigned To, Last Update, Created Date. Filters:
location, status, type, booking, and a date range. New `AssetStatusReportQuery`,
`AssetStatusReportItemResource`, `useAssetStatusReport`, `AssetStatusReport.vue`.
**13 tests; suite 933 → 946 passing.** Pint + `vue-tsc` clean.

Two interpretations are **pinned in the query docblock and stated in the UI** under
the filter bar, because the schema cannot answer the alternatives:

1. **The date range filters `updated_at` (or `created_at`), not status history.**
   Operational status is overwritten in place, so "status as it stood on 1 June" is
   unanswerable without a status-history table (D-009). The report returns *current*
   status for assets created/updated in the range.
2. **"Assigned To" = the technician on the asset's open work order.** Assets have no
   custodian column; a WO assignee is the only person ATMS associates with an asset.
   Null when nothing is open.

Both still need LDC confirmation (🔵 #5). If they meant point-in-time status, R-1
needs D-009 first — it is a schema change, not a report change.

**NEXT: CSV export (D-010).** Nothing in the codebase exports anything. CSV streams
from the existing cursor queries, so building it for R-1 gives all 19 reports the
same capability. PDF can reuse the `PartRequestPrintView` print-route pattern; xlsx
needs a new dependency.

### Dashboard + Reports redesign — design notes

LDC issued dashboard and reporting requirements; clarification questions are with
them. Findings from reading the schema against those requirements:

- **"Asset status" is three independent axes in ATMS**, not one. LDC's
  available / in use / maintenance / disposed maps across `is_booked`,
  `operational_status`, and `maintenance_status` + `maintenance_sub_status`. An
  asset can be booked *and* under maintenance *and* enrolled at once, so "count by
  status" needs three breakdowns or LDC must nominate one axis.
- ⚠️ **Disposal DOES exist in the model.** `MaintenanceSubStatus` already defines
  `DISPOSED` and `SCRAPPED`, alongside `MaintenanceStatus::WITHDRAWN` (enforced —
  withdrawn assets are blocked from MR creation, approval, and WO assignment) and
  an `erp_status` column. Unused today (all 400 assets `enrolled`/`active`), but
  the reply to LDC saying disposal "will not be an ATMS status" overstates it.
- **No asset status history table.** `asset_location_histories` gives location over
  time; `operational_status`/`maintenance_status` are overwritten in place, with
  past values surviving only inside `audit_logs` before/after blobs. **A
  date-filtered status report is therefore unanswerable** without a schema
  addition — only "current status, created/updated in range".
- **No export capability exists anywhere** (the sole download path is attachments).
  CSV, xlsx, and PDF are all net-new. CSV streams cheaply from the existing cursor
  queries; PDF has a working precedent in `PartRequestPrintView.vue` (standalone
  print-styled route, browser print, no library); xlsx needs a new dependency.
- **Report 1 field "Assigned To" has no source** — assets have no custodian column;
  only work orders have an assignee.
- **Date-range is a REPORTS-only control (user decision 2026-07-31).** The
  dashboard is current-state only, no date filters.

**Asset utilisation — agreed new metric, definable on existing data.**
`locations.type` already carries the taxonomy: `rig` + `well_site` = deployed,
`yard` + `building` = idle, `workshop` = maintenance. Point-in-time utilisation
(deployed ÷ eligible, excluding down/under-maintenance) belongs on the dashboard;
the windowed **rate** (asset-days deployed ÷ asset-days eligible, reconstructed
from `asset_location_histories.effective_at`) belongs in reports.
⚠️ **Blocked on data: 396 of 400 assets have `current_location_id = NULL`** and
only 5 movement rows exist, so utilisation reads ~0% until location data is
captured.

**Proper Booking — REQUIRED (decided 2026-07-31, redesigned 2026-07-31 as separate table).** `is_booked` is a bare boolean toggled by `ToggleAssetBooking`, but Operations book **up to three months ahead** for future jobs. Today ATMS cannot say what a booking is *for*, *when* it runs, or *who* committed the asset, and cannot detect overlaps. "Booked but still on yard" therefore carries **no** signal — it is the normal state for most of a booking's life.

**Redesigned as a dedicated `bookings` table** (not columns on `assets`) so full history is preserved:

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint PK | |
| `asset_id` | FK → assets | Which asset is committed |
| `booked_by` | FK → users | Who made the commitment (accountability) |
| `booked_from` | date | Start of the commitment window |
| `booked_until` | date | End of the commitment window |
| `booking_reference` | string, nullable | The job/project the asset is committed to |
| `notes` | text, nullable | Free-form context |
| `status` | enum: `active`, `cancelled`, `released` | Lifecycle state |
| `cancelled_at` | timestamp, nullable | When cancelled/released |
| `timestamps` | | created_at / updated_at |

`is_booked` on `assets` becomes **derived** — EXISTS an active booking whose window covers today — and the stored boolean column is dropped after data migration. This unlocks: commitments by month, upcoming mobilisations, overlap/double-booking detection, a truthful "available" count, and full audit history of who booked what and when.

**Key behaviours:**
- Overlap detection: reject a new booking if an active booking on the same asset overlaps the requested date range.
- Auto-release: asset deactivation or withdrawal from maintenance sets matching active bookings to `released`.
- History preserved: cancelled/released rows are never deleted.
- Location change does NOT auto-release (corrected from earlier doc — code only released on deactivation/withdrawal).

**Build before go-live** — migrating live booking data afterwards is far more expensive. Overlap-blocking pending LDC answer (external blocker #5b).

**Layout agreed (visual proposal):** 12-column grid, rhythm
triad → full → pair → pair → triad → full; every row divides into equal siblings.
Bands: Attention · Utilisation (hero) · Reliability | Process Performance ·
Fleet status | By location · Program readiness · Recent activity. State colour
confined to 7px dots and thin bar segments — no filled cards. Empty states are
written copy ("No failures yet"), not em-dashes, so the first months don't look
broken. Mockup: https://claude.ai/code/artifact/50ede17e-5c1c-4854-8055-b1ea8627f974
- **"Program readiness" band is a deliberate addition** (PM coverage 1/400,
  location recorded 4/400, baseline reading 8/400). It is the only band with
  meaningful numbers pre-adoption and it drives the data capture that makes every
  other metric work. Intended to be dropped once coverage approaches 100%.
- **Equipment Reliability + Process Performance stay on the dashboard** (user
  decision) despite currently having no data to show.

## Decision update — 2026-07-11

- **Microsoft Graph `sendMail` is the only ATMS production email transport.**
  Power Automate is retired and must not be implemented, configured, or retained
  as a fallback. Development and automated tests use the fake transport.
- ~~**Phase 1 email scope is limited to account activation and password reset.**
  Operational MR/WO emails are outside the current Phase 1 scope.~~
  **SUPERSEDED 2026-07-25** — 8 operational MR/WO notifications are built and
  tested. See the 2026-07-25 session entry below.
- ~~**Required backend follow-up:** implement Graph behind
  `AccountEmailTransport`, wire `ACCOUNT_EMAIL_TRANSPORT=graph`, add the
  `GRAPH_*` configuration, tests, queue serialization and 429 retry handling,
  then remove the legacy Power Automate class, configuration, binding, and tests.~~
  **DONE** — committed `618a8fe`.

## Session — 2026-07-25

- **Operational MR/WO email notifications — BUILT, UNCOMMITTED, NOT LIVE.**
  8 workflow notifications wired into the owning Actions: MR submitted → all
  active Managers; MR approved → requester + assignee; MR rejected → requester;
  WO assigned → technician; WO started → Managers; WO completed → Managers;
  WO closed → technician (cc Managers); WO cancelled → assignee (no-op when
  unassigned). This **expands** the 2026-07-11 Phase 1 email scope above.
  - **Contract generalised:** `AccountEmailTransport::send()` went from
    `(string $recipient, string $subject, string $actionUrl)` to a single
    `array $message` of `{ to[], cc?[], subject, templateData }`. The channel,
    both account notifications, the Graph transport, and the fake transport were
    all migrated. Graph now renders the shared Blade template straight from
    `templateData` instead of hardcoding account-email copy.
  - **New:** `app/Notifications/Concerns/AccountEmailNotification` trait
    (`via()`, `tries = 10`, `backoff [30,120,300]`, shared
    `WithoutOverlapping('account-email-graph-mailbox')` lock) — all notifications
    use it, so the Exchange ~3–4 concurrent-connection cap is respected.
    Config gained `account-email.bcc`.
  - **Verified:** 16 notification tests (9 workflow + 7 Graph), 97 MR/WO
    lifecycle tests, Pint clean. `ACCOUNT_EMAIL_TRANSPORT=fake` everywhere →
    **nothing is delivered yet.**
- **Docs updated to match (2026-07-25):** `docs/PRODUCT.md` (new §Notifications
  with the routing matrix), `docs/ENGINEERING.md` (notification code layout +
  security bullets), `docs/OPERATIONS.md` (new §Email delivery — transport
  switch, throttle/serialization, BCC, `APP_URL` deep links, Application Access
  Policy, secret expiry), `docs/ROADMAP.md` (2 new external deps: official SPA
  hostname, Exchange Application Access Policy), `docs/REQUIREMENTS.md`
  (**R-007** — pre-go-live hardening), `docs/README.md` (snapshot), `CLAUDE.md`
  (§Email notifications rewritten + `ACCOUNT_EMAIL_BCC` env row).
- **R-007 hardening pass — DONE same session.** 4 reported defects: 2 fixed in
  code, 2 closed by decision.
  1. ✅ **BCC default removed.** `ACCOUNT_EMAIL_BCC` no longer defaults to
     `rawand.hawez@inova.krd`; unset = no BCC. A test asserts the config
     declares no default, so the personal address can't creep back.
  2. ✅ **Deep links no longer use the API host.** New `atms.frontend_url`
     (`FRONTEND_URL`, falls back to `APP_URL`) + `App\Support\FrontendUrl::to()`,
     applied to **all 10** user-facing link sites — the 8 workflow actions plus
     `ProvisionEmployeeUser` (activation) and `AuthController::forgotPassword`
     (reset), which had the same bug and weren't in the original 4.
     `url()` stays for API URLs (attachment downloads).
  3. ⛔ **Cc routing: DECISION REVISED 2026-07-25 — as-built routing accepted.**
     The 2026-07-04 plan (Cc Admins on MR submitted; Cc actor on WO
     assigned/completed) is **withdrawn**, not a defect. Only WO closed has a Cc
     (Managers). Don't "restore" the old plan in a future session.
  4. ✅ **After-commit dispatch.** All 10 notifications now implement
     `ShouldQueueAfterCommit` instead of `ShouldQueue`.
     ⚠️ **Do NOT put `public bool $afterCommit = true;` in the trait** — that was
     the first attempt and it fatals: `Illuminate\Bus\Queueable` already declares
     `public $afterCommit` (default `null`), and a trait property with a
     different default is an incompatible composition. The interface is the
     correct mechanism. A trait can't add an interface, so
     `GraphAccountEmailTransportTest` walks `app/Notifications` and asserts every
     class using the trait implements it.
  - Also moved the mailbox lock key into `OverlapKeys::ACCOUNT_EMAIL` (was a
    hardcoded string) to match the existing convention.
  - **Verified:** full suite **679 passed (1949 assertions)**, Pint clean.
- **Product decision (2026-07-25): scheduled PM evaluation stays silent.** A run
  can create many preventive MRs at once; Managers see due PM work on the
  dashboard and MR list. Recorded in `docs/PRODUCT.md` as a decision, not a gap.
- **Two testing gotchas found the hard way — both now in `CLAUDE.md`:**
  1. ✅ **FIXED (R-008).** The suite was silently running as `APP_ENV=local` on
     the `database` queue driver, so queued jobs went to the `jobs` table and
     **never executed in tests**. Root cause took two steps to pin down:
     `force="true"` on `<env>` is necessary (PHPUnit won't override an existing
     env var) **but not sufficient** — Laravel's `Env` repository reads
     `$_SERVER` *before* `getenv()`/`$_ENV`, and the container's values sit in
     `$_SERVER` too (`variables_order=EGPCS`). Forcing `<env>` alone changed
     `getenv()` while `env()` still returned `local`/`database`. Fix = forced
     `<env>` **plus** a `<server>` twin for every value. ⚠️ **Anything added to
     `phpunit.xml` needs both.** Full suite 679 passed unchanged afterwards, so
     nothing had been relying on jobs not running.
  2. `RefreshDatabase` holds a transaction that never commits →
     **after-commit callbacks never fire**, so a rollback assertion under it
     passes vacuously. `NotificationTransactionSafetyTest` uses
     `DatabaseMigrations` + inline queue and pairs the negative assertion with a
     positive control. Caught only because the positive control was written.
- **`.env` updated:** added `FRONTEND_URL=http://localhost:5173` and empty
  `ACCOUNT_EMAIL_BCC`; refreshed the stale "keep fake until the transport is
  built" comment. `.env.example` documents all three with warnings.
- **Go-live prerequisites — status 2026-07-26 (user-confirmed):**
  - **SPA host:** deployed at `https://atms.inova.krd` — set `FRONTEND_URL` to
    this on the VPS backend. **Provisional**, may change when LDC issues the
    permanent subdomain. Local `.env` stays `http://localhost:5173`.
  - **Real addresses:** all `ldc.com.ly` addresses are real. Active users now
    cover every workflow path (1 admin, 1 manager, 1 technician, all real) after
    the user converted a manager to Technician. One active administrator remains
    on the placeholder `atms.local` domain; the two `atms.internal` accounts
    (admin + service) are inactive so are never addressed.
  - ✅ **EMAIL IS LIVE (2026-07-26).** `ACCOUNT_EMAIL_TRANSPORT=graph`. Verified
    two ways: direct transport send, and a queued send processed by the worker
    container (0 failed jobs). Workflow actions now mail real recipients.
    - **Blocker found and fixed first:** `compose.yaml` injects `GRAPH_*`,
      `FRONTEND_URL`, and `ACCOUNT_EMAIL_*` from the **ROOT `.env`**, which had
      none of them — so Compose passed empty strings that shadowed the real
      values in `backend/.env` (`$_SERVER` beats dotenv; same precedence trap as
      the phpunit bug). ⚠️ **Email config belongs in the root `.env`.** Added
      `GRAPH_*` + `FRONTEND_URL=https://atms.inova.krd` there and dropped the
      dead `POWER_AUTOMATE_*` block.
    - **Also fixed in `compose.yaml`:** `FRONTEND_URL`/`ACCOUNT_EMAIL_BCC` were
      passed to **no** service, and the scheduler had `ACCOUNT_EMAIL_TRANSPORT`
      but **no `GRAPH_*`**. All three services now get the full set.
    - **And in `config/atms.php`:** `frontend_url` uses `env('FRONTEND_URL') ?:
      env('APP_URL')` — an `env()` default would never fire against Compose's
      empty string, yielding relative links. Test covers it.
  - **Exchange Application Access Policy:** ⏳ still outstanding — LDC IT action.
    `Mail.Send` is granted tenant-wide, so the app can currently send as **any**
    mailbox. Fix is `New-ApplicationAccessPolicy -AppId <GRAPH_CLIENT_ID>
    -PolicyScopeGroupId <group containing notification@ldc.com.ly>
    -AccessRight RestrictAccess`, verified with `Test-ApplicationAccessPolicy`.


## Session — 2026-07-11

- **Asset API location filter correction — implementation applied, verification pending.**
  `GET /api/assets?location_id={id}` preserves the public parameter and now filters
  `assets.current_location_id` in `AssetIndexQuery` instead of the nonexistent
  `assets.location_id`. Regression tests cover selected-location filtering and
  requester active-asset scoping. The delivery team will run the focused test.
- **G-09 Effective Date UI mismatch — DONE.** Removed the disabled,
  non-submitted datetime control from `UpdateLocationSheet`. Phase 1 moves take
  effect immediately, and backend `effective_at = now()` remains authoritative.
  Updated the relevant location UI/specification docs. Frontend type-check and
  production build pass.

## Session — 2026-07-05

- **`is_failure` failure-classification flag for corrective MRs — DONE (backend + frontend).** Nullable boolean on corrective MRs marking a real failure vs. no-fault-found/duplicate/etc. Classified **twice** by qualified roles (not the requester): required at **MR approval** (`POST /maintenance-requests/{id}/approve` — 422 if missing for corrective in `pending_review`), optional override at **WO closure** (`POST /work-orders/{id}/close`). Preventive MRs never classified (`null`). MTBF + Failure Rate now count `is_failure = true` (not every corrective event); MTTR unchanged.
  - **Renamed `is_fault` → `is_failure`** wire-level (column, payloads, audit `close_work_order_update_mr_is_failure`) — "Failure" is the correct reliability term (MTBF = Mean Time Between **Failures**). Migration **recreated, not patched — no deprecation window**.
  - **⚠️ Contract note (bit both sides):** `WorkOrderResource` embeds `maintenance_request` as a **partial** `{ id, number, is_preventive, is_failure }` — carries **`is_preventive`, not `type`**. Corrective-origin detection keys off `is_preventive === false`.
  - **Backend files:** migration (backfills CONVERTED corrective MRs → `true`, pending-review stay `null`); `MaintenanceRequest` (`$fillable`+`$casts`); approve action (conditional-required; `use ($isFailure)` closure-capture bug caught in test); close action (corrective-origin override + audit); `MaintenanceRequestResource` (always) + `WorkOrderResource` (`whenLoaded('maintenanceRequest')`); `ReliabilityKpiQuery` (MTBF/failure_rate → `is_failure=true`). 34 WO-lifecycle tests green; Pint clean.
  - **Frontend files (7):** `types/index.ts` (`is_failure` + new `WorkOrderMaintenanceRequestRef`), `useMaintenanceRequestDetail.ts` (`approveIsFailure`, required-gate, payload), `useWorkOrderDetail.ts` (`closeIsFailure`, `isCorrectiveOrigin` via `is_preventive`, `originTypeLabel`, close-as-dialog, omit-key-unless-chosen), `MaintenanceRequestDetailView.vue` + `WorkOrderDetailView.vue` (badges incl. **WO command-bar badge next to status/priority per user request**, Approve/Close Select dialogs), `displayHelpers.ts` (`failureLabel`/`failureClass`), `style.css` (`.status-failure`/`.status-no-failure`/`.status-unclassified`). `vue-tsc --build` + `npm run build` green; oxfmt clean.
  - **Docs:** `user-manual.md` §6.2/§7.0/§7.5/§8.5 (Rawand). **Frontend uncommitted in the working tree — user said DO NOT COMMIT (2026-07-05).**
- **Dropped redundant `maintenance_requests.type` column — `is_preventive` is now the single stored source of truth.** Closes the guardrail gap flagged 2026-07-03 (bare-varchar `type` without an Enum cast). Rather than dress the redundant column up as a `MaintenanceRequestType` Enum, the column was removed entirely: `is_preventive` (boolean) already encodes the same fact and is what every authoritative consumer (policies, lifecycle actions, dashboard KPIs, PM chain-prevention) already trusted. `type` is now **derived** inline in `MaintenanceRequestResource` and `MaintenanceHistoryResource` (`$this->is_preventive ? 'preventive' : 'corrective'`) — API output shape unchanged, non-breaking. The `?type=preventive|corrective` list filter is translated server-side to `where(is_preventive, …)` so existing consumers keep working.
  - **Migration:** `2026_07_05_000000_drop_type_from_maintenance_requests_table` (drops `type`; `down()` re-adds it `->after('asset_id')`). Applied to live Postgres (4 corrective MRs preserved). SQLite `:memory:` tests apply it via `migrate:fresh`.
  - **Files touched:** migration; `MaintenanceRequest` model (removed `type` from `$fillable`); both Resources (derive `type`); `MaintenanceRequestIndexQuery` (filter translation); `CreateCorrectiveMaintenanceRequest` + `EvaluatePmRule` (removed redundant `type` writes — keep `is_preventive`); `MaintenanceRequestDemoSeeder` (drives off a single boolean pool); 19 test files (removed redundant `'type' => …` keys from MR create arrays / one `assertDatabaseHas`).
  - **Tests:** full suite **483 passed (1292 assertions)** — identical to baseline. Pint clean. No fresh log errors.
  - **Docs updated:** `user-manual.md` (data-model table — `is_preventive` promoted to main fields as the discriminator; `type` marked derived; PM-generation narrative reworded); `BACKEND_API_REFERENCE.md` (added data-model note + derived-field marker + `?type=` translation note); `BACKEND_API_HANDOFF.md` (TS `type` field annotated as derived).
  - **Frontend impact:** none required — the API still emits both `type` (derived) and `is_preventive`. Frontend team confirmed they will voluntarily drop `is_preventive` from their TS interface and key the one `v-if` off `record.type === 'preventive'`. Logged in `.kilo/TLD.md` 🟡.

## Session — 2026-07-04

- **Email transport pivoted to Microsoft Graph `sendMail` (replacing the Power Automate plan).** SMTP AUTH ruled out empirically — LDC M365 tenant `SmtpClientAuthenticationDisabled` → `535 5.7.139` (creds valid; policy block). XOAUTH2-over-SMTP is not a supported M365 app-only path. Power Automate is retired and will not be used. Chose **Graph `sendMail`** (OAuth2 client credentials), sending from `notification@ldc.com.ly`, unaffected by the SMTP AUTH policy.
  - **Azure provisioning DONE (2026-07-04):** separate Entra app from `LDC_ERP_*` (Client `6dd70b5f-…`, Tenant `a8a21afa-…`, Object `ffbb837a-…`); `Mail.Send` (Application) + tenant-wide admin consent granted; probe delivered test mail to both recipients (HTTP 202). Config in `backend/.env` as `GRAPH_TENANT_ID/CLIENT_ID/CLIENT_SECRET/MAILBOX`; `ACCOUNT_EMAIL_TRANSPORT` stays `fake` until the transport is built.
  - **Template:** shared Blade view `resources/views/emails/atms-notification.blade.php` (client-provided HTML adapted; amber `#d97706` accent, navy `#21274b` header, **no logo**, dynamic CTA). 3 scenarios rendered + test-sent (202 each): MR Created, WO Assigned, WO Completed.
  - **Routing decided:** MR Created → To: all active Managers, Cc: all Admins. WO Assigned/Reassigned → To: new assignee, Cc: action taker (notify on any change). WO Completed → To: all active Managers, Cc: completer. Greeting = To recipient only. From-name "ATMS Notifications", **no Reply-To**.
  - **Superseded 2026-07-11:** Graph is the production implementation behind `AccountEmailTransport` for the in-scope activation and password-reset emails. Operational MR/WO notifications are outside current Phase 1.
  - **Throttle finding (important):** Exchange Online throttles concurrent app access per mailbox (~3–4) → `429 ApplicationThrottled` (and gateway `504`s) when blasting parallel sends. Production dispatch MUST be **serialized via the queue** + **retry-on-429 honouring `Retry-After`**.
  - **Docs updated:** `NOTIFICATIONS.md` (full rewrite), `ARCHITECTURE.md`, `CLAUDE.md`, `README.md`, `IMPLEMENTATION_PLAN.md`, `DEPLOYMENT.md`, `PHASE_1_GAP_ANALYSIS.md` (I-03, R-06).
  - **NOT built yet (next, TDD):** Graph implementation behind `AccountEmailTransport` for activation/reset, queue serialization + 429 retry, configuration/binding, tests, and removal of the legacy Power Automate transport. Operational MR/WO Mailables and action wiring are future scope.
  - **Pre-release checklist (email):** frontend base URL NOT final (temp `atms.inova.krd` → official LDC subdomain); real user emails (demo has fakes); serialize+retry; prod secret/cert; Application Access Policy; queue worker.
- **Self-service password change — DONE (committed `a03b078`).** `POST /api/auth/change-password` (authenticated; no current-password required per product decision); `ChangeUserPassword` action (invalidates all sessions + tokens, audits `user.password_changed`); `ChangePasswordRequest`; `UserPolicy::changePassword`. 7 tests; full suite **483 passed (1292 assertions)**.

## Session — 2026-07-03

- **Dashboard KPIs endpoint — DONE (backend, uncommitted).** New `GET /api/dashboard/kpis` serves the 9-card dashboard's Row 2 (MTBF / MTTR / Failure Rate) + Row 3 (PM Compliance / Avg MR Duration / Avg WO Duration) plus a "Recently Relocated Assets" widget (latest 5 `asset_location_histories`). Visible to **every authenticated role** (reuses the existing `viewDashboard` gate, which is `fn (User $user): bool => true`); payload is **not** role-filtered — Row 1 counts stay on the existing role-adaptive `GET /api/dashboard` (decision (a): KPIs = aggregate numbers for all; record lists stay role-scoped on `/dashboard`).
  - **Decisions locked:** rolling **90-day** window; MTBF = **calendar** basis (`90 / corrective failures`); MTTR = `assigned_at → closed_at` on **corrective** WOs; PM Compliance = **date-triggered** PMs only, on-time = `wo.closed_at::date ≤ mr.trigger_date` (no grace); relocated = latest 5 within the window.
  - **Files:** `DashboardKpiController` (thin: Gate → 2 query classes → `DashboardKpiResource`, `$wrap=null` for a flat object matching `/dashboard`), `app/Queries/Dashboard/Kpis/ReliabilityKpiQuery` + `ProcessPerformanceKpiQuery`, `app/Queries/Dashboard/RecentlyRelocatedAssetsQuery`, `app/Http/Resources/DashboardKpiResource`. Route added under the auth group.
  - **"Failure" = `maintenance_requests.is_preventive = false`** (boolean) — deliberately avoided the raw `type` string. `maintenance_requests.type` is still a bare varchar without an Enum cast (pre-existing guardrail gap — flagged as a separate cleanup; create `MaintenanceRequestType` enum + cast).
  - **Resource enhancement:** `AssetLocationHistoryResource` now exposes an `asset` fragment (`whenLoaded`) so the relocated widget can show asset name/tag/code without a second fetch. Safe — the existing `/assets/{asset}/location-history` endpoint doesn't load `asset`, so its response is unchanged.
  - **Tests:** `tests/Feature/Dashboard/DashboardKpiTest` — 11 tests (auth/401, every-role access, structure, each KPI's math incl. corrective-only filtering + window exclusion, empty→null state, relocated top-5 + asset identity). Full suite **476 passed (1278 assertions)**. Pint clean. No fresh log errors.
  - **Gotcha for future tests:** `created_at`/`updated_at` are **not** in the models' `$fillable` (guarded) — passing them via `create()` is silently ignored. Use `forceCreate([...])` when a test needs an explicit `created_at`. Also `work_orders.maintenance_request_id` is NOT NULL.
- **Docs updated:** `BACKEND_API_REFERENCE.md` (§Dashboard — full `/dashboard/kpis` endpoint), `BACKEND_API_HANDOFF.md` (TS types `DashboardKpiResponse`/`RelocatedAssetItem` + quick-ref row), new focused `DASHBOARD_KPI_HANDOFF.md` (self-contained frontend handover: 9-card mapping, null handling, formatting), `.kilo/TLD.md` (🟡 Recently Completed), `CLAUDE.md` (New endpoints table).

## Session — 2026-07-02

- **Parts Management UI (G-02) — DONE (committed `56bd463`).** Replaced the two "coming soon" stubs with full implementations: `PartsView.vue` (searchable/filterable table via `AppDataTable`, category filter derived live from data) + `PartDetailView.vue` (overview card, ERP reference rail for Admin/Manager incl. raw ERP JSON, attachments upload + per-attachment delete). New `useParts`/`usePartDetail`/`usePartSearch` composables, `partColumns`, and `PartCombobox`. Removed `__mockParts.ts` + all `// MOCK(PARTS)` blocks; the WO parts-used picker now reads live `GET /parts`. Backend: `PartSeeder` (55 O&G drilling-maintenance parts across 11 categories) registered in `DatabaseSeeder` + feature tests. Placeholder `erp_part_id`/`erp_raw_data` are NULL so `SyncErpPartsJob` overwrites cleanly when the ERP parts endpoint lands. Closes critical gap **G-02** from `docs/PHASE_1_GAP_ANALYSIS.md`.
- **Phase reorganisation decided (2026-07-02):** SM decoupled into **Phase 3** (largest, most uncertain scope — pending VJ's BC Store Order answer). Phase 2 = AM movement + Asset Assembly + Component PM cross-check + ERP parts write-back + Asset tag QR generation. Manual Asset Creation (G-01 Add Asset + G-04 `CreateAsset` dropped lifecycle fields) **deferred to Phase 3 or cancelled** — data-integrity concerns: with ERP as the likely source of truth for asset reference data (Phase 3 SM work), manual create risks duplicates/drift; and the create button is disabled in production so G-04's dropped fields have no live impact. See updated `.kilo/TLD.md` Phase 2/3 tables.
- **Admin Lists & Dropdowns cleanup — DONE (backend + frontend, parallel implementation).** `.kilo/plans/1783001396791-admin-lists-dropdowns-cleanup.md`. Trimmed the Admin "Lists & Dropdowns" tab from 8 groups to 3 genuinely-configurable ones (`maintenance_priorities`, `usage_reading_types`, `fa_subclass_type_codes`) — the other 5 were Enum-backed state machines (`WorkOrderStatus`, `OperationalStatus`, `MaintenanceSubStatus`) or dead concepts (`asset_categories`, `maintenance_categories`), decorative no-ops since `master_data_items` was empty. New public read path `GET /api/list-options/{group}` (auth-only, not Admin-gated — see CLAUDE.md New endpoints) lets every role read active-only priorities/reading-types/FA-subclasses without the Admin-gated `/admin/master-data/*` CRUD. Backend: `ListOptionController` + route + `maintenance_priorities` seed migration (4 rows: low/medium/high/critical) — 7 tests passing (20 assertions), confirmed via `docker exec atms-api php artisan test`. Frontend: new `useListOptions.ts` composable (fallback `DEFAULT_PRIORITIES` on fetch failure); `mrColumns.ts`/`woColumns.ts` dropped static priority arrays, `WorkOrdersView.vue`/`MaintenanceRequestDetailView.vue`/`WorkOrdersListView.vue` now merge live priorities into filter/select options; `useMaintenanceRequestDetail.ts` draft `priority` widened `Priority`→`string` (now dynamic data). **Bug fixed in passing:** the hardcoded FA-subclass filter list (`assetColumns.ts`) had drifted to 18 codes vs. 20 in the DB — missing `ROTOR`/`STATOR`. Fixed by fetching the live list; kept a display-only `FA_SUBCLASS_LABELS` lookup (repurposed from the old hardcoded array) so friendly labels ("Mud Motor") are preserved, falling back to the raw code for anything uncurated. Also preserved the "Critical — immediate attention required" picker hint via a new `priorityPickerLabel()` helper. Docs updated: `ROUTES.md` §Admin, `SCREEN_INVENTORY.md` §7b. Both sides uncommitted in the working tree as of this session.
- **Asset status enum rename — DONE (backend + frontend).** `maintenance_status` `Active`/`Inactive`→`enrolled`/`withdrawn`; `maintenance_sub_status` PascalCase→lowercase (`installed`,`ready`,`lih`,`dbr`,`disposed`,`scrapped`,`other`). Reason: kill the `operational_status='active'` collision. Rolled out as 3 plans (`.kilo/plans/1782944404943/44/45`). Backend done: both enums, `LegacyAssetStatusNormalizer` (`normalize`+`normalizeSubStatus`, both `?string`; validation accepts both cases), 2 migrations. Frontend done: 6 files (`types/index.ts`, `useAssetDetail.ts` L83+L227, `AssetDetailView.vue`, `displayHelpers.ts`, `assetColumns.ts`, `content/user-manual.md`) — type-check + build green, sweep clean. Display labels: enrolled→"In maintenance program", withdrawn→"Withdrawn". **Ordering: backend-shim-first (NOT atomic)** — shim decouples FE/BE timing. **PENDING: Plan 3** (`1782944404945`) removes both shims ~14 days after Plan 2 deploy (≈mid-July 2026); un-skips `legacy→422` test stubs. Untouched: `operational_status`, `is_active`.
- **Docs clean-up (2026-07-02):** `TDL.md` (added G-13 gap entry), `STATUS_MODEL.md` (L90 — fixed "configurable as master data" → Enum-backed state machine contradiction), `NAVIGATION.md` (L162-165 — corrected lists description), and `IN_SCOPE.md` (L185-188 — same). `SCREEN_INVENTORY.md` §7b and `ROUTES.md` §Admin were already aligned from the Lists implementation. All docs now match the dynamic-config model.
- **WO Detail frontend review:** reading-type URL fixed (`/admin/usage-reading-types`), WorkOrderResource now ships `asset.operational_status`, upload dialog has `.dialog-md` (user prefers wrap/trim — pending). Mock parts catalogue (8 items) in `src/lib/__mockParts.ts` + `// MOCK(PARTS)` blocks — **remove** when Parts API ships.
- **WO Form layout**: Sheet (A) vs tighter-card (B) — recommended Sheet. Pending user decision.
- **Attachment delete**: `DELETE /api/attachments/{id}` (generic, not WO-scoped). `can_delete` shipped by AttachmentResource.
- **Meter reading edit/delete**: backend shipped + frontend wired. PATCH/DELETE under `/assets/{asset}/meter-readings/{reading}`, Admin/Manager/Tech, confirmed-locked (409). Frontend: `useWorkOrderDetail.ts` (`canManageReadings`, `openEditReading/doEditReading`, `openDeleteReading/doDeleteReading`) + `WorkOrderDetailView.vue` readings-table actions column + Edit/Delete dialogs. Editable fields: value, read_at, notes (type read-only). Actions hidden for confirmed readings.
- **Environment**: PHP not on PATH; pint/tests require `php` binary.
- **Asset operational status → AUTOMATIC (replaces Option A suggestion approach)**. Backend-driven via `ApplyWorkOrderAssetStatusTransition` action (audit `asset.status_updated` w/ `source=work_order_lifecycle`). Mapping: CM MR approved → `down` (skip if already `under_maintenance`); PM approved → no change; WO start → `under_maintenance` (forced, all WOs); WO close → `active` (only if currently down/UM — never un-retire `inactive`); WO cancel → caller chooses `down`|`active` (new `asset_status` param on `POST /work-orders/{id}/cancel`). Hooks: `ApproveMaintenanceRequestAndCreateWorkOrder`, `StartWorkOrder`, `CloseWorkOrder`, `CancelWorkOrder` (+ controller). Frontend cancel dialog now requires the Down/Active choice. Manual 'Update status…' setter remains as override. **Reverted** the earlier suggestion-banner code. Backend tests + pint NOT run (no PHP on PATH) — needs `vendor/bin/pint` + WorkOrderLifecycleTest updates.


## Last Session Accomplished

- **VPS Frontend Testing — Bug Tracker (2026-06-28): ALL 9 ISSUES RESOLVED.**
  - `docs/atms/04-frontend/VPS_FRONTEND_ISSUES.md` — live tracker for frontend bugs
    found during VPS deployment testing.
  - **MR (5):** MR-01 case-insensitive asset search ✅ (backend `LOWER(col) LIKE`);
    MR-02 list refresh after create ✅; MR-03 attachments open in new tab ✅ (blob +
    object URL — API forces `Content-Disposition: attachment`); MR-04 layout +
    "Approved by" ✅; MR-05 delete attachments ✅ (backend policy allows owner-delete
    while `pending_review`; `AttachmentResource` exposes an unconditional policy-driven
    `can_delete` flag (+ `attachable` eager-load); frontend gates per-attachment via
    `canDeleteAttachment(a)`).
  - **WO (3):** WO-01 layout ✅; WO-02 assign-at-approval ✅ (atomic — `/approve`
    accepts `assignee_id`); WO-03 assign/reassign ✅ (reassign while `in_progress`;
    picker lists active Technicians **and** Managers; backend `AssignWorkOrder` +
    `StartWorkOrder` accept both via `User::isWorkOrderAssignee()`). Also fixed a
    pre-existing bug: pickers called `/users` (404) → now `/admin/users`. Assign
    control is an icon button in the WO Details card header.
  - **Asset (1):** AS-01 location "#undefined" ✅ (frontend consumes
    `from_location`/`to_location` objects directly; backend eager-loads them).
  - **No leftovers** — all 9 VPS issues fully resolved (frontend + backend).

- **Power Automate Notification Integration — HISTORICAL, SUPERSEDED 2026-07-11:**
  - **Do not implement this design.** Power Automate is retired; Microsoft Graph
    `sendMail` is the only production email transport. The following bullets are
    retained only as session history.
  - Created `docs/03-backend/NOTIFICATIONS.md` — full spec for email delivery via
    company-standard Microsoft Power Automate.
  - Architecture: ATMS event → queued job → HTTP POST (JSON) → Power Automate
    HTTP trigger → email. No DB polling, push-based.
  - 5 notification triggers documented with full payload contracts:
    - Phase 1: MR Created, WO Assigned/Reassigned
    - Phase 3: SM Order Submitted, SM Order Approved, SM Order Rejected
  - Laravel implementation: `SendNotificationToPowerAutomate` queued job, event
    listeners, retry/failure handling.
  - Power Automate setup checklist.

- **Docs README Updated (2026-06-28):**
  - `docs/README.md` — updated folder structure to include new files, replaced old
    activation-only Power Automate line with full notification integration summary,
    added "Key Documents" table with new entries.

## Next Steps — Prioritized Execution Order (2026-06-28)

Ordered by value and unblocking. **B** = backend (this agent), **F** = frontend
(team), ⏳ = blocked on an external dependency.

### ✅ DONE — VPS Frontend Fixes + WO Assignment (2026-06-28)

- **VPS issues (MR-01..05, WO-01..03, AS-01):** all resolved (see "Last Session
  Accomplished"). Frontend changes need a **rebuild/redeploy** to appear on the VPS.
- **WO Assign + Assign-at-approval:** both shipped (atomic `/approve` w/ `assignee_id`;
  WO detail assign/reassign; Technician OR Manager assignable).
- **MR-05 `can_delete` flag:** ✅ shipped (unconditional policy-driven flag +
  `attachable` eager-load + tests). Frontend already consumes it — owner Delete
  buttons now surface automatically.

### Remaining Frontend Builds (F) — stub views with backend already implemented
- ~~**Parts Management UI**~~ — ✅ **DONE (2026-07-02, committed `56bd463`).** See session log above.
- **System Settings** — `SystemSettingsView.vue` stub; backend done.
- **Audit Logs** — `AuditLogsView.vue` stub; backend done.
- **Manager → Admin-area access** — decided but not implemented: `AppSidebar.vue`
  Admin items still `visibleTo: isAdmin` (lines 86, 93); router still has
  `requiresAdmin` guards (lines 118, 127). Grant Managers access (see Open Follow-ups).

### Notification Testing — ✅ Graph probe passed (2026-07-04)
- Graph `sendMail` probe delivered test mail to both recipients (HTTP 202).
- Azure app provisioned (separate Entra app from `LDC_ERP_*`), `Mail.Send` (Application) consented.
- Remaining before prod: Application Access Policy (restrict app to mailbox), official
  LDC frontend subdomain for links, prod secret/cert, queue-serialized dispatch. See
  `docs/03-backend/NOTIFICATIONS.md` pre-release checklist. (Supersedes the
  2026-06-29 Power Automate webhook test plan.)

### ✅ Asset Booking — Frontend wiring (F) DONE (2026-06-30)
- Backend complete (`POST /assets/{id}/book` + `/unbook`, `is_booked` in
  AssetResource, auto-release on move/inactivation).
- **Frontend shipped:** Book/Unbook button + amber "Booked" badge in the Asset
  Detail header (gated Admin/Manager/Logistics via `canToggleBooking`); confirm
  dialog before toggle; 409 handled via toast. Inline "Booked" badge in the Asset
  List Name cell. (`useAssetDetail.ts`, `AssetDetailView.vue`, `AssetsView.vue`,
  `types`, `style.css` `.status-booked`.) Rebuild/redeploy to see it.

### P2 — Parts catalogue from ERP ⏳ BLOCKED
- **Goal:** populate the parts list (SM-owned) from BC, the same way Assets are
  pulled.
- **Backend — EXISTS (pipeline):** `SyncErpPartsJob`, `LdcErpHttpSource`. Cannot
  run without the ERP parts endpoint.
- **Blocked on ERP team (TDL #1, #2, #8):**
  1. Parts / M&S / consumables **read URL** (OData page name).
  2. **Field mapping** (sample response rows).
  3. QTY-on-consumption write-back feasibility + handoff format.
- **Action:** chase VJ/ERP; once #1 + #2 land, wire `SyncErpPartsJob`, document
  the mapping, and the WO parts picker gets real data.

### Existing backlog (low urgency, no dependencies — slot in opportunistically)
- #6 Rename `frontend/` → `atms/` + update Docker/nginx (infra).
- #7 Create `sm/` and `am/` Vue 3 scaffolds (Phase 8/9).

### Suggested execution order
**System Settings + Audit Logs views → Manager admin-area access → Notification
testing.** Asset Booking frontend ✅ done. Parts Management UI ✅ done (G-02 closed).
G-01 (Add Asset) + G-04 (`CreateAsset` dropped fields) deferred to Phase 3 / cancelled
(data-integrity decision). P2 parts data stays ERP-blocked. #6 / #7 anytime.

---

## Phase 1 pending review
Phase 1 core is **COMPLETE**. VPS bug fixes and WO assignment enhancements are
**done** (2026-06-28). **Parts Management UI (G-02) closed (2026-07-02).**
Remaining: stub-view frontend builds (System Settings, Audit Logs), Manager
admin-area access, and notification integration testing. G-01 (Add Asset) and G-04
(`CreateAsset` dropped fields) deferred to Phase 3 / cancelled (data-integrity
concerns). G-03 (location picker for non-Admins) still open.

---

## Key Decisions (do not reopen unless new information)

| Topic | Decision |
|---|---|
| Subsystem architecture | ATMS / SM / AM — one backend, one DB |
| RBAC roles | 5 human + 1 system: Admin, Manager, Tech, Logistics, Requester + **SERVICE** (non-user-assignable, M2M tokens only) |
| Service user | `service@atms.internal`, seeded, never logs in via SPA. Immutable. |
| Asset source | ATMS-managed only — no ERP asset sync |
| Parts ownership | SM — ERP syncs into SM. ATMS reads only. |
| Location ownership | AM — ATMS reads from AM tables only. |
| ERP auth | Entra ID OAuth2 `client_credentials`, `x-www-form-urlencoded` |
| ERP sync strategy | Full pull every time. No pagination. No incremental sync. |
| ERP field boundary | Sync writes ERP columns only. Local fields never touched. |
| Asset tag format | `L-BBB-CCC-XXXX` (final 2026-06-25) — 4 segments with dashes. Size code truncated to 3 chars rightmost. RTR/STR detected by description keyword. Immutable after create (Admin override with reason allowed, clearing forbidden). |
| Asset tag ownership codes | `L` = LDC (we maintain), `X` = External (we don't) |
| Asset maintenance status | `enrolled`/`withdrawn` (renamed from `Active`/`Inactive` to kill the `operational_status='active'` collision) — gates MR/WO/PM workflows. Sub-statuses `installed`/`ready`/`lih`/`dbr`/`disposed`/`scrapped`/`other` (lowercased), informational only. Display labels: enrolled→"In maintenance program", withdrawn→"Withdrawn". Input shims (`LegacyAssetStatusNormalizer`) accept both cases until Plan 3 removes them. (2026-07-02) |
| Asset operational status | Separate axis from maintenance_status — informational only, no workflow gating. |
| Asset booking | Dedicated `bookings` table (redesigned 2026-07-31). Date-ranged (`booked_from`/`booked_until`), job reference, booked-by user, status lifecycle (`active`/`cancelled`/`released`). `is_booked` on assets is derived (active booking covering today). Overlap detection rejects conflicts. Auto-releases on deactivation/withdrawal only (NOT location change). Does NOT gate MR/WO/PM. Toggled by Admin/Manager/Logistics. Supersedes the 2026-06-27 bare-boolean design. |
| Employee directory source | CSV-backed (`CsvEmployeeDirectorySource`, `EMPLOYEE_CSV_PATH`), not DB import. `EMPLOYEE_VISIBLE_EMP_IDS` whitelist controls who appears in the list. Provisioning upserts a single Employee row to DB. (2026-06-27) |
| Migration strategy for erp_asset_id | Edit original migration (SQLite `:memory:` runs `migrate:fresh`). Production one-time `ALTER TABLE DROP COLUMN`. |
| Mock ERP | Fully deleted. `LdcErpHttpSource` skips sync gracefully when `LDC_ERP_PARTS_API` is empty. |
| API token abilities | Read-only (`['read']`) blocked on POST/PUT/PATCH/DELETE → 403. Write (`['read','write']`) allowed all. SPA session never blocked. |
| Git commit convention | When the user says "commit ALL" (capitalized), use `git add .` — stage everything including untracked files, then commit. |
| Notifications / Email | Phase 1 activation and password-reset emails are delivered via **Microsoft Graph `sendMail`** (OAuth2 client credentials) from `notification@ldc.com.ly`. SMTP AUTH is ruled out (tenant `SmtpClientAuthenticationDisabled` → `535 5.7.139`); Power Automate is retired and will not be used. Queued, throttle-aware transport (serialize per mailbox + retry on 429). Operational MR/WO emails are outside current Phase 1. |
| WO assignable roles | Admin/Manager can assign WO to active Technician OR Maintenance Manager (small teams, overloaded tech). Assignment authority remains solely Admin/Manager. (2026-06-28) |

## Pending — Blocked on ERP Team 🔴

| # | Item | Tracker |
|---|---|---|
| 1 | Parts API page name (BC custom API page) | `docs/05-delivery/TDL.md` |
| 2 | Parts field mapping (response schema) | `docs/05-delivery/TDL.md` |
| 3 | `componentOfMainAsset` sample with non-null parent | `docs/05-delivery/TDL.md` |
| 4 | **Store Order / Store Management in BC** — does it exist and is it used at LDC? Can we query store orders by number through OData? | VJ (ERP Consultant) |

## Pending — Backend Team (future)

| # | Item |
|---|---|
| 6 | Rename `frontend/` → `atms/` + update Docker/nginx |
| 7 | Create `sm/` and `am/` Vue 3 scaffolds |

## Known Inconsistencies

- ~~**`CLAUDE.md`** references old `frontend/` paths~~ — resolved 2026-07-31; the
  file was rewritten from live code. The `frontend/` → `atms/` rename (D-001)
  remains deferred, so current paths are correct as written.

> ✅ **Phase 1 complete (2026-06-25)** — 8 tasks implemented, 304 tests passing, 2 rounds code review resolved, all documentation updated. See `.kilo/plans/1782388457617-phase1-backend-cleanup-and-features.md` for full execution log and post-review fixes.

## When Starting a New Session

1. Read this file first.
2. Check `.kilo/TLD.md` for active tasks, deferred items, and cross-team awareness.
3. Check `docs/05-delivery/TDL.md` for external blocker details.
4. Check `docs/atms/04-frontend/VPS_FRONTEND_ISSUES.md` for open frontend bugs.
5. The authoritative source-of-truth is `docs/00-project-rules/authoritative-sources.md`.
6. Key docs map:
   - ATMS product: `docs/atms/01-product/`
   - Backend: `docs/03-backend/`
   - Frontend: `docs/atms/04-frontend/`
   - API: `docs/atms/04-technical/`
   - Notifications: `docs/03-backend/NOTIFICATIONS.md`
   - ERP: `docs/03-backend/ERP_SYNC.md`
   - Assembly: `docs/atms/01-product/ASSET_ASSEMBLY.md`
   - Tags: `docs/atms/01-product/ASSET_TAG.md`
   - Phase 1 plan: `.kilo/plans/1782388457617-phase1-backend-cleanup-and-features.md`
   - VPS issues: `docs/atms/04-frontend/VPS_FRONTEND_ISSUES.md`
7. ERP test: source `backend/.env`, then the curl commands commented in that file.

## Implementation Phases (2026-06-24)

### Phase 1 — ATMS Core ✅ COMPLETE (2026-06-25)
- Asset registry + tags + maintenance status
- Corrective + Preventive MR → WO workflow
- Parts catalogue (read-only from SM tables, ERP-synced)
- Simple asset location update by Logistics (no workflow)
- 5(+1)-role RBAC with SERVICE for M2M API tokens
- Dashboard, reporting, attachments
- API bearer tokens with ability-based access control
- Real ERP adapter (LdcErpHttpSource)

### Phase 2 — AM + Assembly + integrations (future)
- Asset Assembly (parent/child, install/remove/swap)
- Component PM cross-check indicators
- AM: Movement request workflow with approval chain
- ERP parts write-back (SM GR → BC ERP; ERP team must confirm consumption API contract)
- Asset tag QR code generation on asset detail page

### Phase 3 — SM Subsystem (future, decoupled 2026-07-02)
- SM architecture + parts catalogue design (full local build vs. BC Store Order integration — pending VJ reply)
- SM: Order → Approval → Dispatch → GR, inventory, Virtual Store
- Manual Asset Creation + lifecycle-field persistence (G-01 Add Asset button + G-04 `CreateAsset` dropped fields) — deferred-to-Phase-3-or-cancelled decision (data-integrity concerns)

### Deferred entirely from Phase 1
- Asset Assembly (parent_asset_id, assembly_history, component hours) — Phase 2
- Component PM cross-check indicators — Phase 2
- SM Order workflow, inventory, stock movement, Virtual Store — **Phase 3**
- AM movement approval workflow — Phase 2
- ERP parts write-back — Phase 2
- Asset tag QR code generation — Phase 2
- MinIO object storage

## Parts Table Decision (on hold — 2026-06-24)

`work_order_parts` (WO consumption log) is always needed, regardless of VJ's answer.
The parts catalogue source depends on VJ:

| VJ says | Parts list source | Local tables needed |
|---|---|---|
| BC Store Order live | BC OData query by store order ID | `work_order_parts` only (references BC part IDs) |
| BC Store Order NOT live | Need our own catalogue | `parts` table (ERP-synced) + `work_order_parts` (FK to `parts.id`) |

**Decision:** Defer `parts` table until VJ replies. Build `work_order_parts` in Phase 1,
with a placeholder parts picker using demo data if VJ hasn't replied by then.

## Open Follow-ups

- **Manager access to PM template viewing (decided, pending implementation):**
  Under the M:N model, **assignment** management (assign/evaluate/deactivate/
  reactivate a template on an asset) is reachable by a Maintenance Manager from
  the **Asset Detail** screen — so the Manager's `AssetPmAssignmentPolicy`
  permissions are no longer dormant. The remaining gap is **template viewing**:
  PM Rules (template management) lives under the Admin sidebar item
  (`visibleTo: isAdmin`), and a Manager — who holds `view`/`viewAny` via
  `PmRulePolicy` and passes the `requiresAdminOrManager` guard on
  `/admin/pm-rules` — has no UI path to view templates. Template creation is
  `POST /api/pm-rules` (Admin-only). **Agreed direction: grant the Manager full
  Admin-area access** (all three tabs). To implement: `AppSidebar.vue`
  `visibleTo`, the `requiresAdmin` guards on `/admin/lists` & `/admin/users` in
  `router/index.ts`, and confirm the Admin endpoints' policies match the intended
  scope. **Frontend work — out of the backend agent's scope.** Canonical note:
  `docs/03-backend/RBAC.md` (Known gap). Pointers in SCREEN_INVENTORY.md §7c and
  NAVIGATION.md §7.
