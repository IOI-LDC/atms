# Asset status vocabulary — true record (corrected 2026-08-15)

**Status:** ✅ Record corrected with the user on 2026-08-15; **design agreed
2026-08-16** (see §5). **Nothing is implemented.** No design was agreed
before the 2026-08-15/16 corrections — earlier drafts of this file and the
matching tracker entries contained false or unverifiable provenance and are
superseded by this record.

## 1. True record of LDC's vocabulary requests

LDC sent multiple separate requests to add or rename values in the asset
status vocabulary. LDC could not explain the workflow intent behind any of
them. The requested vocabulary, confirmed by the user on 2026-08-15:

| Value | Kind |
|---|---|
| At the Rig | new |
| Need Assembly | new |
| Missing Parts | new |
| Need Inspection | rename of "Under Inspection" |
| Need Maintenance | rename of "Under Maintenance" |
| LIH | requested — home not yet decided |
| DBR | requested — home not yet decided |
| Scrapped | **replaces Disposed — final.** Disposed is not used. |

Rename history that actually happened in code: `inactive` → display-only
"Retired" (2026-08-02) → `scraped` at DB level (2026-08-04). A further
`retired` rename was proposed and abandoned.

Inspections were first raised by LDC in the week of 2026-08-07. **User
decision:** excluded from Phase 1; inspection management moved to a
dedicated **Phase 1.5** with its own estimate. **(Cancelled 2026-08-16 —
inspection means PM; see §5.7.)**

## 2. What was wrong in earlier drafts (do not trust)

- Earlier drafts claimed a design was "AGREED" on 2026-08-14 and then
  "reopened" because the user disliked a new `disposition_status` field
  duplicating `maintenance_sub_status`. **Both claims are false.** No design
  was ever agreed, and the recorded reopen reason is not accurate.
- Earlier drafts claimed the user "agreed to drop Need Inspection explicitly".
  **False — never agreed.**
- Earlier drafts attributed LIH / DBR / Scrapped / Disposed to internal or
  ERP vocabulary rather than to LDC requests. **False — LDC requested them.**
- The 2026-07-31 recorded decision that `maintenance_status = withdrawn` and
  all `maintenance_sub_status` values are "ERP-owned" and "must never be
  surfaced, counted, or reported" is **superseded**. The final design
  (2026-08-16) drops LIH / DBR / Scrapped from the vocabulary entirely:
  their "out of ATMS" meaning is `is_active = false`, and **categorical
  reporting that distinguishes LIH vs DBR vs Scrapped is no longer possible
  from asset fields alone** — a distinguishing source (e.g. a
  withdrawal-reason value) would be needed; recorded as an open item in §6.
  The current code still implements the old decision (R-10B and R-11
  removed from the report catalogue).

## 3. Current code state (facts, verified 2026-08-15)

- `operational_status` (6 values): `ready_for_field`, `under_maintenance`,
  `down`, `scraped`, `under_inspection`, `lih`. Written automatically at
  MR approval, WO start/close/cancel; also hand-set. Consumed by the
  availability KPI, reports and CSV exports. A WO close never reverts a
  `scraped` asset.
- `maintenance_status`: `enrolled` / `withdrawn`, Admin/Manager hand-set.
  `withdrawn` gates MR create (422), WO assignment (409), MR approval (409)
  and PM evaluation **via the scheduled job** (`AssetPmAssignment::scopeEvaluable`).
  Direct/manual PM evaluation (`EvaluatePmRule`, the evaluate-all endpoint)
  checks neither `maintenance_status` nor `is_active` — both paths are
  covered by the gating fix in §6.
- `maintenance_sub_status` (7 values): `installed`, `ready`, `lih`, `dbr`,
  `disposed`, `scrapped`, `other`. Admin/Manager hand-set only; no workflow
  writes it. All 400 assets currently NULL. Note: `disposed` is dropped from
  the LDC vocabulary (Scrapped replaces it), so this enum needs trimming in
  the eventual design.
- All MR/WO data is test data and will be wiped at handover; assets reset to
  `ready_for_field`.

## 4. Proposals — never accepted (reference only)

The following designs were drafted by AI sessions. **None was ever agreed to
by the user.** Kept only so future sessions do not re-derive them.

- **2026-08-07 proposal:** 4-value operational axis (`ready_for_field`,
  `under_maintenance`, `down`, `retired`), a new LDC-owned `disposition_status`
  hand-set attribute backed by `master_data_items`, a "one resolved Status"
  rule, WO-close disposition capture, P2-010 un-deferred. Seed list dropped
  the Need-* labels.
- **2026-08-14 proposal:** 3-value operational axis, `scraped` moved to
  `maintenance_status = withdrawn` + `maintenance_sub_status = scrapped` +
  `is_active = false`, `under_inspection` removed pending Phase 1.5,
  `disposition_status` seed of 5 labels (`at_the_rig`, `missing_parts`,
  `need_maintenance`, `need_inspection`, `need_assembly`), P2-010 re-deferred.

## 5. Agreed design decisions (2026-08-15, revised 2026-08-16 after the LDC meeting)

Decisions agreed with the user. The 2026-08-16 revision supersedes the
earlier 8-value `condition_status` draft, and supersedes the status-value
changes shipped 2026-08-04 in
`docs/plans/2026-08-04-four-requirements.md` (which introduced
`under_inspection`, `lih` and `scraped`). Nothing is implemented yet —
this is the agreed direction; the implementation plan is the next step.

1. **All labels are informational only.** No label blocks MRs, work orders,
   PM, bookings or KPIs. The only automatic write is the reset-to-default
   on WO close (point 2); otherwise labels exist for display, filtering and
   reporting.
2. **New hand-set label field `assets.condition_status`** (UI label
   "Condition", `master_data_items` group `asset_conditions`) holds
   **3 values: `need_assembly`, `missing_parts`, `need_inspection`**.
   Visible on asset detail, a list filter, and a report/export dimension.
   "Need Maintenance" is **not** a label — the Maintenance Request is its
   record. LIH / DBR / Scrapped have **no label home** — removed; "out of
   ATMS" is `is_active = false`.

   **Default + reset rule (2026-08-16):** the vocabulary carries an
   explicit **default value** (proposed: `normal`, label "Normal"), seeded
   first, deactivation-protected (Unclassified-category sentinel pattern),
   and backfilled over the existing NULL rows. **When a WO is closed**
   (`operational_status` → `ready_for_field`), `condition_status` is reset
   to the default — this is the automatic staleness clearing. **Cancel does
   NOT reset.** Special warning: if the asset carried `need_inspection` and
   no PM was marked completed on this WO (RQ1), warn the closer — the
   normal close flow proceeds regardless. Proposed mechanics to pin in
   planning: `is_default` boolean on `master_data_items` so the close
   action resolves the default from the table instead of hardcoding it.
3. **The vocabulary is admin-editable** via `master_data_items` (same
   infrastructure as Maintenance Priorities), seeded with the 3 values.
   Adding or renaming a label is an Admin UI action — zero code, zero deploy.
   Backend validation must read the table, not a hardcoded `in:` list (the
   maintenance_priorities half-wiring lesson).
4. **`operational_status` becomes a 4-value machine axis:**
   `ready_for_field`, `under_maintenance`, `failure`, `at_the_field`.

   `down` is renamed to `failure` (2026-08-16): LDC reads "down" as
   "waiting for parts", which collides with `condition_status =
   missing_parts`. "Failure" names the fault itself; causes live on the
   condition label.

   - `at_the_field` is set automatically when the asset's location is a
     `rig` or `well_site` — applied by `UpdateAssetLocation` (the single
     location-*change* choke point) **and** by `CreateAsset` (initial
     placement writes `current_location_id` independently, so the rule
     needs a shared helper on both paths). Aligns 1:1 with
     `AssetDeployment::DEPLOYED`. ⚠️ **Data prerequisite:** only 1 location
     exists (Tajoura Base, yard) — all 400 assets sit on it and no
     rig/well_site location has been created, so the rule is inert until
     LDC creates rig/well_site locations.
   - WO start → `under_maintenance` (forced, unchanged) and physically
     moves the asset to a workshop/yard — `at_the_field` and
     `under_maintenance` cannot coexist.
   - WO close → **always `ready_for_field`** (the closer's choice is
     removed). A repair that did not fix the asset is expressed by a new
     MR, which sets `failure`.
   - WO cancel → caller choice `failure` | `ready_for_field` (unchanged).
   - Corrective MR approval → `failure` (unchanged); preventive approval
     changes nothing (unchanged).
   - MR approval may optionally move the asset to a yard/workshop location
     ("Tajoura Base") or keep current — the existing `StartWorkOrder`
     optional-location transfer pattern (location history row + audit in
     the same transaction). Pins pending: offered location set (literal
     Tajoura Base vs any active yard/workshop), default, preventive
     approvals included.
   - `scraped` and `lih` leave the axis entirely — **no replacement**;
     their "out of ATMS" meaning is `is_active = false`. `under_inspection`
     leaves the axis, replaced by `condition_status = need_inspection`.
   - Manual "Update status" stays as an override.

   Removal risks and conditions (verified against code and data):

   - Behavioral risk: none beyond the guard — no workflow *writes* the
     three removed values; the only *workflow-level* reader is the
     `CloseWorkOrder` SCRAPED skip-guard, which is removed with them (the
     KPI/report readers are listed under Consumer drift below).
   - Data risk: trivial — live data is 397 `ready_for_field`, 2 `down`
     (→ `failure` via the rename migration), 1 `scraped`, 0
     `under_inspection`, 0 `lih`; the single `scraped` row is migrated
     before the enum case is removed; all MR/WO data is test data wiped at
     handover.
   - Consumer drift: KPI `by_status`, R-10A, Asset Distribution, R-1,
     filters and the WO "Update status" dialog must ship in the same
     backend+frontend release (PHP enum removal is compile-time enforced
     on the backend).
   - Replacement safety net: the `is_active = false` MR/WO/PM gating defect
     must be fixed (make it block like `withdrawn`), since scrapping is
     now represented by `is_active`, not by an operational value with a
     close-guard. The fix must cover the preventive MR approval path and
     direct/manual PM evaluation, while letting an open WO be finished,
     not started.
5. **`assets.erp_status` is removed** (decision 2026-08-15). Dead weight:
   all 400 rows carry the column default `'active'`; no workflow logic
   reads it — but it is serialized in `AssetResource.php:53` and seeded by
   `MaintenanceRequestDemoSeeder.php:62`, so those are included in the
   removal; the ERP asset import writes `is_active` directly and no longer
   touches `erp_status`. Removed with the vocabulary change: drop the
   column, remove from `$fillable`/serialization, frontend type/view, and
   manual. `erp_raw_data` and `erp_last_synced_at` stay for any future
   ERP-sync revival. Parts keep their own ERP-synced `erp_status` —
   untouched.
6. **`maintenance_sub_status` is deprecated — assembly state becomes
   derived.** One field cannot be both machine-written (assembly
   transitions) and admin-editable (labels) — that is the
   `operational_status` category error recreated one level down. Value
   fate: `lih`/`dbr`/`scrapped`/`disposed`/`other` **all die** — no label
   home remains; `installed`/`ready` are **derived from `parent_asset_id`**
   when P2-001 builds assembly (strict bijection per
   `docs/_archive/2026-07-13/legacy/atms/01-product/ASSET_STATUS.md` rule
   6: installed ⇔ parent set, ready ⇔ parent NULL — **component/package
   kinds only**; standalone assets never show installed/ready; pin at
   P2-001 design). No code change now —
   0 rows; the only readers are display/validation surfaces (AssetResource
   serialization, `AssetController` validation, the `AssetDetailView`
   picker) and no workflow decision reads it — those surfaces are removed
   with the column (label-field release or P2-001).
7. **Phase 1.5 (inspection management) is cancelled (2026-08-16).**
   "Inspection" for LDC means **PM** — not third-party certificates. The
   P2-011 certificate register and its TLD trigger are removed;
   `docs/FUTURE_SCOPE.md` keeps a cancellation marker. Instead, inspection
   is a **form filled in and attached on the WO**, plus marking which PM
   was executed (RQ1/RQ2).

8. **Final mapping of LDC's 8 requested values:**

   | LDC value | Home |
   |---|---|
   | At the Rig | `operational_status.at_the_field` (auto when the location is rig/well_site — changes and initial placement) |
   | Need Maintenance | the MR pipeline (pending MR = the need; approval → `failure`) |
   | Need Assembly | `condition_status.need_assembly` |
   | Missing Parts | `condition_status.missing_parts` |
   | Need Inspection | `condition_status.need_inspection` (reset + warn on close) |
   | LIH | nowhere — `is_active`/`withdrawn` carry the meaning |
   | DBR | nowhere — `is_active`/`withdrawn` carry the meaning |
   | Scrapped | nowhere — `is_active = false` is the control |

## 6. Settled follow-ups, recorded recommendations, and remaining open decisions

**Settled:** field name `assets.condition_status` (UI "Condition", group
`asset_conditions`, 3 values); explicit default value with reset-on-close
(close only; warn when `need_inspection` + PM not marked); `down` renamed
to `failure` (LDC reads "down" as waiting-for-parts, which is
`missing_parts`); Scrapped has no automatic effect — `is_active = false`
(Admin-set) is the out-of-ATMS control and its MR/WO/PM gating fix must cover
MR create, **approval (corrective and preventive)**, WO assignment **and
WO start**, plus **direct/manual PM evaluation** (`EvaluatePmRule` and the
evaluate-all endpoint — today they check neither status), while letting an
open WO be finished, not started. Implementation must use one reusable,
explicit guard across the scheduler, direct evaluation, evaluate-all and
MR/WO entry points rather than a global model scope; WO close
always `ready_for_field`; WO cancel keeps caller choice; `at_the_field`
set when the location is rig/well_site (location changes **and** initial
placement); MR approval optionally moves the asset to a yard/workshop
location; Phase 1.5 cancelled (inspection = PM); the PM level ladder is
**cumulative — L3 ⊇ L2 ⊇ L1** (user, 2026-08-16; matches the existing
close-cascade).

**Open, with recorded recommendations (pending user/LDC confirmation):**

1. `at_the_field` precedence: moving a `failure` asset to a rig —
   recommended: keep `failure` (a fault survives relocation). Leaving the
   field: rig/well_site → yard/building — recommended: `ready_for_field`
   only if currently `at_the_field`; → workshop — recommended: no status
   write (workshop transitions stay WO-owned).
2. condition_status ↔ operational_status link: recommended — the
   reset-on-close rule is the whole link; no validity matrix.
3. MR-approval location pins: recommended — any active yard/workshop
   location, default keep-current, offered on both approval types.
4. P2-001 edge case: leaning — parent-as-asset-row (decide at P2-001
   design).
5. Reporting: R-10B not restorable (its data source is deprecated);
   **R-11 is cancelled — LDC answered Q7 "No" on 2026-08-16**: they do not
   want a withdrawn / out-of-service report. Recorded rather than dropped
   because the reason matters if it is ever revived: categorical
   LIH/DBR/Scrapped reporting needs a distinguishing source (e.g. a
   withdrawal-reason value), and none exists today — the agreed vocabulary
   gives those labels no home, so the field would have to be added and
   populated going forward before such a report is possible.
   `condition_status` joins R-1 and the Asset Distribution report + CSV as a
   filter/dimension **in the vocabulary release itself**.

**Implementation order (recorded):** 1) the `is_active` MR/WO/PM gating fix
(live defect, independent of LDC answers, prerequisite for removing
`scraped`); 2) RQ4 (fully specified, independent); 3) the vocabulary axis
+ `condition_status` (after LDC answers); 4) RQ2/RQ3/RQ1 (RQ1 after the
level deep dive, RQ3 after the quantity-ownership answer).

## 7. Four additional LDC requests (2026-08-16 meeting) — agreed, to be planned

### RQ1 — Mark L1/L2/L3 PM during a WO

The maintenance team performs and marks **L1, L2 or L3 PM while working on
a WO**. **Level model settled (user, 2026-08-16): the ladder is
cumulative — L3 ⊇ L2 ⊇ L1.** Marking L2 means L1+L2 are done; marking L3
means all three. The existing machinery already implements this:
`POST /work-orders/{id}/close` accepts `serviced_pm_assignment_id`
(resets date + reading baselines **for the marked level and everything
below it**, cancels pending PM requests; `decision_type =
'performed_under_repair'`). Levels are already data, not code:
`pm_rules.maintenance_level` is a string column (`varchar(10)`), and the
rule form offers L1–L4 plus a custom level — future levels need no schema
change.

Design consequence: the marking UI is a **single "highest level performed"
picker**, not a multi-select — listing the asset's active PM assignments
labeled by level.

Deep dive still required:

- "While they work" — mid-WO entry vs close-time marking.
- Cascade ordering must **become generic** (numeric level comparison) —
  today `CloseWorkOrder` hardcodes L1–L4 matches (`:279`) — so future
  levels keep cascading.
- The inspection form (RQ2) is the attachment carrier.

### RQ2 — Attachment at WO completion (related to RQ1)

Inspection is a **form** filled in and attached to the WO, with the
executed PM marked. Verified: WO attachments already exist backend +
frontend with **no status restriction** (`GET/POST
/work-orders/{id}/attachments`; `uploadToWorkOrder` policy allows
Admin/Manager + assigned technician). There is **no WO reopen mechanism**
(no route, no status) — and none is needed: wire the attach step into the
completion flow instead. True reopen would require reversing close
side-effects (PM baselines, snapshots) — deferred unless LDC asks.

### RQ3 — Parts CSV download + quantity update upload

- **Download:** parts list CSV export does not exist (report streaming
  exists; the parts index is JSON-only).
- **Upload:** `ImportPartsCommand` already validates a CSV
  (`EXPECTED_HEADERS`) and **updates existing parts** (rejects unknown ERP
  ids) including `available_quantity` — CLI only. RQ3 = an in-app Admin
  upload path on the same pattern.
- **ANSWERED 2026-08-16 (Q6) — supersedes the earlier recommended lean.**
  The lean recorded here was "`available_quantity` becomes locally owned;
  the CSV upload is the master writer; the ERP sync never overwrites it;
  consumption stays check-only (no decrement)". LDC reversed **both**
  halves:
  - **ERP remains the quantity authority.** `SyncParts` keeps overwriting
    `available_quantity` wholesale; the CSV upload is an **interim** writer
    only, until the ERP sync is live. Trigger for the overwrite becoming
    live: `LDC_ERP_PARTS_API` configured (tracked in `.kilo/TLD.md` 🟠).
  - **Recording a part on a WO must decrement the quantity**, and removing
    the line restores it — so the consumption report and on-hand stock stay
    honest between ERP refreshes. This is no longer check-only.
- **Q8 — part identifier (answered 2026-08-16).** `erp_part_code` is the
  only identifier the Maintenance team uses, and the only one that belongs
  on screens and in exports. It is **not** a matching key: it is not
  guaranteed unique and LDC edits it. Keying stays split — the **live ERP
  sync matches on the external `erp_part_id`** (`SyncParts` does
  `firstOrNew(['erp_part_id' => …])`; `ImportPartsCommand` keys the same
  way), while **ATMS-internal relationships and the RQ3 CSV round-trip use
  `parts.id`**, the table primary key. Do not re-key the ERP sync onto
  `parts.id`, and do not match uploads on `erp_part_code` alone.

### RQ4 — Show the ERP part code everywhere

`erp_part_code` (the "No." column in LDC's parts Excel; the codes LDC
actually uses, e.g. `7HF 400ML`, `1001`) exists in the DB for all 734
parts and is already inside the WO part-line payload. Planned changes:

- Backend: expose `erp_part_code` to all roles in `PartResource` (today
  Admin-only); add to `PartIdentityResource` (today deliberately absent);
  make it searchable in `PartIndexQuery` (today deliberately not
  searchable).
- Frontend: parts table column ("Part No."); `PartDetailView` view-page
  field + read-only edit-sheet field; `erp_part_code` badge in
  `PartIdentityBadges` (new `identity-badge-part-code` CSS class);
  combobox search placeholder update; widen the Add Part dialog
  (`dialog-md` on its `DialogContent`).
- Tests to update: `PartResourceTest`, `IdentityResourceTest`,
  `PartsConsumptionReportTest` (they currently pin the Admin-only/hidden
  behavior).
