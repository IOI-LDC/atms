# Status Vocabulary + LDC Requests — Implementation Plan (v2)

> **For Claude:** Implement this plan task-by-task with superpowers:executing-plans if available; otherwise subagent-driven-development with a review checkpoint after each phase. READ the working-tree design doc first — it carries uncommitted refinements that are authoritative.

**Goal:** Ship the agreed 2026-08-16 status-vocabulary design, the `is_active` gating fix, the parts-quantity decrement, and LDC requests RQ1–RQ4 — safely, with no mixed-version outage.

**Architecture:** One reusable asset-eligibility guard (Phase 1) is the prerequisite. Phases 2–3 are independent and ship whenever. Phase 4 is a three-release expand/backfill/contract sequence (4a additive → 4b code switch + coordinated FE → 4c legacy removal), NOT one big-bang migration. Phases 5–8 follow.

**Tech Stack:** Laravel 13 + PHP 8.4, PostgreSQL, PHPUnit 12, Vue 3.5 + TS + Vite + Tailwind v4 + shadcn-vue, Pint. Commands: `docker exec atms-api php artisan test --compact <path>` (backend, from `backend/`), `cd frontend && npm run type-check`.

**Sources of truth:** `docs/plans/2026-08-07-operational-status-vocabulary.md` — **working tree** (has uncommitted refinements adding the "one reusable, explicit guard" requirement and MR/WO/PM scope; commit them in Phase 0). This plan supersedes v1 (`.kilo/plans/…` v1 content is obsolete).

---

## LDC answers (2026-08-16) — execution record

| # | Answer | Plan mapping |
|---|---|---|
| Q1 | Stays `failure` on rig move; **manual** location change gated to `ready_for_field` (+ `at_the_field` exits) | Phase 4b — gate on the standalone route only |
| Q2 | Leaving the field → `ready_for_field` **and** `condition_status = need_inspection` | Phase 4b — user-initiated moves only; WO-start moves exempt |
| Q3 | Condition labels flexible, any time | No validity matrix — confirmed |
| Q4 | MR approval offers **Tajoura Base**; default keep-current | Phase 4b Task 4.6 (was missing in v1) |
| Q5 | PM marking **during work AND at completion**; cumulative L3 ⊇ L2 ⊇ L1 | Phase 6 (mid-WO semantics defined below) |
| Q6 | **ERP is the quantity authority**; CSV updates interim; **recording a part on a WO decrements quantity** | Phase 3 + Phase 7 + TLD 🟠 trigger |
| Q7 | **NO** (answered 2026-08-16) — LDC does not want a withdrawn/out-of-service report | **Phase 8 cancelled**; R-11 not built |
| Q8 | `erp_part_code` is the only business identifier; **keep the table PK as the internal key** for ERP sync and CSV upload | Phase 7: export/upload resolve by `parts.id`; `erp_part_id` = ERP correlation only |

**Decisions made in this revision (record, implement):**
- D1 Deployment: Phase 4b uses a **documented, tested downtime cutover** (executable steps in 4b — build-without-start, drain/stop, one-off migrate container, start, smoke, abort/restore). (Fallback if zero-downtime is ever required: expand/contract.)
- D6 Q2 scope: LDC's answer is "leaving the field → `ready_for_field` + `need_inspection`" without distinguishing user vs workflow moves. **Default implemented: user-initiated exits only** — WO-start moves never stamp `need_inspection` (they're work, not operational return). **CONFIRM D6 with the user before 4b.**
- D7 Manual status subsets (pinned per endpoint): asset create/update and `setAssetStatus` allow `ready_for_field | under_maintenance | failure` (never `at_the_field` — location-derived only); WO cancel allows `failure | ready_for_field` only (use `Rule::in`, NOT `Rule::enum`).
- D2 Quantity precision: `work_order_parts.quantity` is stored at 2 decimals (`WorkOrderPart.php:19`), `parts.available_quantity` at 3 (`Part.php:37`). **Exact input rule:** `['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,2})?$/']` — the regex governs *precision*, `min:0.01` governs *magnitude*. ⚠️ Both are required: the regex alone matches `0`, `0.0` and `0.00`, and a zero-quantity line would pass the stock guard (`0 > available` is false), create a `work_order_parts` row and decrement nothing — a phantom consumption line. Today's `min:0.01` (`WorkOrderController.php:210`) prevents that; do NOT drop it. Precision failure and zero/negative are both 422, distinct from the insufficient-stock 409.
- D2b Decimal-string carry (**signature change, not just a rule**): `RecordWorkOrderPart::execute` currently declares `float $quantity` (`RecordWorkOrderPart.php:18`) and the controller casts `(float) $validated['quantity']` (`WorkOrderController.php:218`) — left as-is the value is floated at the boundary and D2's guarantee is void. Change the parameter to `string $quantity` and drop the cast. Only caller: `WorkOrderController::addPart` (`:204`) — a two-file change. The stock write is then raw SQL against the PostgreSQL numeric column —
  `UPDATE parts SET available_quantity = available_quantity - :qty` with `:qty` bound as a string. Restore is the inverse with the stored line quantity. Round-trip is exact; no `round()` on floats.
- ERP-refresh interleave (recorded rule for the action docblocks): restore adds the stored line quantity unconditionally; a concurrent ERP refresh between record and remove means the local balance is stale, not corrupted — the next `SyncParts` overwrites (ERP is the authority, Q6).
- D3 Manual "Update status" picker **excludes `at_the_field`** (it is location-derived only) — frontend + `setAssetStatus` validation.
- D4 Mid-WO PM marking is **staged**: recorded during the WO, persisted at close, discarded on cancel (confirm with user before Phase 6).
- D5 ✅ **ANSWERED 2026-08-16 — required at CLOSE, not at completion** (the user corrected the recommendation). Any attachment satisfies it; uploads stay open through `completed`.

**Still open / activation steps:** `normal` default-name confirmation (before 4a seeding); LDC creates rig/well_site locations (ops activation — NOT a release gate).

---

## Phase 0 — Doc hygiene (before any code)

- Commit the working-tree design refinements (design doc, STATE, TLD, PRODUCT, ROADMAP, manual) with user approval — do NOT clobber them.
- Correct design §7 RQ3's stale "locally owned / no decrement" recommendation to match Q6 — **and the same stale lean in `docs/ROADMAP.md:45`** (the RQ3 external-dependency row still says "CSV locally owns `available_quantity`; ERP sync never overwrites").
- **Two more stale `ROADMAP.md` rows now answered:** `:43` (MR-approval location — answered by Q4: Tajoura Base, default keep-current) and `:44` (RQ1 marking flow — answered by Q5: during work AND at completion). Both still read as open LDC questions gating work.
- Correct the Q8 record wording (PK internal; `erp_part_id` correlation).
- Record triggers in `.kilo/TLD.md` 🟠: ERP quantity overwrite (trigger: `LDC_ERP_PARTS_API` configured), Q7/R-11, mid-WO persistence.
- `.kilo/STATE.md`/`.kilo/TLD.md` updated in EVERY phase commit (CLAUDE.md rule).

---

## Phase 1 — Unified asset-eligibility guard (`is_active` + `withdrawn`)

**Goal:** one reusable guard, **eight** surfaces, one test file. Mirrors `tests/Feature/MaintenanceStatus/MaintenanceStatusGuardTest.php`.

### Task 1.0: The guard
- Create `backend/app/Support/Assets/AssetWorkEligibility.php`:
  - `guard(Asset $asset, string $verb): void` — throws `DomainException` with **cause-distinct** messages: withdrawn → "…withdrawn from maintenance…", inactive → "This asset is deactivated…" (do NOT reuse the current "inactive asset" wording of the withdrawn checks — see `CreateCorrectiveMaintenanceRequest.php:32`, `AssignWorkOrder.php:24`).
  - Query twin for scopes: an `eligibleForWork()` constraint joining `maintenance_status = enrolled` AND `is_active = true`.

### Task 1.1: Wire all eight surfaces
- MR create (`CreateCorrectiveMaintenanceRequest.php:31`) → 422 (controller maps, `MaintenanceRequestController.php:85`)
- MR approve corrective + preventive (`ApproveMaintenanceRequestAndCreateWorkOrder.php:29`) → 409
- WO assign (`AssignWorkOrder.php:23`) → 409
- WO start (`StartWorkOrder.php` — new check) → 409
- `EvaluatePmRule.php:28` (direct) — skip
- `AssetPmAssignmentController.php:145-153` (evaluate-all batch) — scope via the query twin
- **Scheduler**: `AssetPmAssignment::scopeEvaluable` (`:79-85`) gains the `is_active` condition; both `EvaluatePmRulesJob.php:50` and `EvaluatePmAssignmentsJob.php:41` inherit it. Keep `UpcomingPmReportQuery` mirrored (it documents the same population).
- **Asset location change (8th surface)**: `AssetLocationController.php:24-25` already hand-rolls its own check — `is_active` only, no `withdrawn`, 422, and wording ("Cannot update location for an inactive asset.") that collides with Task 1.0's cause-distinct rule. Replace it with the guard. Phase 4b adds the Q1 gate to this same controller, so leaving a second eligibility check beside it recreates exactly the fragmentation this phase exists to end. **Verified: no test pins that message or the 422**, so the status code is a free choice — use **409** (precondition failure on an existing resource, matching the house pattern) and note the change in the release notes as a minor API contract shift.
- Replace **all four** existing inline checks with the guard (both axes, one place): the three `withdrawn` checks (`CreateCorrectiveMaintenanceRequest.php:31`, `ApproveMaintenanceRequestAndCreateWorkOrder.php:29`, `AssignWorkOrder.php:23`) **plus** the `is_active` check at `AssetLocationController.php:24-25`.

### Task 1.2: Tests + verification
- `backend/tests/Feature/Assets/InactiveAssetGuardTest.php` (new): all eight surfaces RED first, each asserted for **both** axes (`withdrawn` and `is_active`) and for the cause-distinct message; plus finish-not-start (open WO on a now-inactive asset may close/cancel).
- Run: `docker exec atms-api php artisan test --compact tests/Feature/Assets tests/Feature/MaintenanceStatus tests/Feature/Pm`; Pint touched paths (`docker exec atms-api vendor/bin/pint app/Support/Assets/AssetWorkEligibility.php app/Actions/... app/Models/AssetPmAssignment.php app/Http/Controllers/AssetLocationController.php app/Http/Controllers/AssetPmAssignmentController.php tests/Feature/Assets/InactiveAssetGuardTest.php` — `--dirty` is a no-op here).
- Commit + STATE/TLD row.

---

## Phase 2 — RQ4: expose `erp_part_code` (independent; v1 verified — execute essentially as written)

- `backend/app/Http/Resources/PartResource.php:42` — move `erp_part_code` to base `$data` (keep `erp_raw_data` admin-only).
- `backend/app/Http/Resources/PartIdentityResource.php:20-21` — add the field; drop the "intentionally absent" docblock.
- `backend/app/Queries/Parts/PartIndexQuery.php:71-85` — add `->orWhereRaw('LOWER(erp_part_code) LIKE ?', [$term])`; drop the "NOT searchable" comment.
- `frontend/src/types/index.ts` — `Part.erp_part_code: string | null`; add to `PartIdentity`.
- `frontend/src/components/app/PartIdentityBadges.vue` — new `identity-badge-part-code` badge (first).
- `frontend/src/style.css` — extend the mono badge rule.
- `frontend/src/lib/partColumns.ts` — "Part No." column, `searchFields: ['erp_part_code']`.
- `frontend/src/views/parts/PartDetailView.vue` — view field + read-only edit-sheet field.
- `frontend/src/components/app/PartCombobox.vue:154` — placeholder includes "code".
- `frontend/src/views/work-orders/WorkOrderDetailView.vue:1115` — `<DialogContent class="dialog-md">` (wider modal).
- Tests: `PartResourceTest.php:76-91` (hasKey), `:189-213` (searchable now), `IdentityResourceTest.php:129-170`, `PartsConsumptionReportTest.php:204-207` (hidden assertions).
- Verify: `docker exec atms-api php artisan test --compact <three files>`; `cd frontend && npm run type-check && npm run build`.
- Commit + STATE/TLD.

---

## Phase 3 — Stock decrement / restore (independent)

### Task 3.1: Decrement on record (TDD)
- RED: rewrite `PartCompatibilityTest.php:385` → `test_recording_consumption_decrements_available_quantity` (qty 1, 5 → `'4.000'`). Fractional round-trip (`1.5` → `'3.500'` → restore `'5.000'`).
- GREEN `RecordWorkOrderPart::execute`: **first apply D2b** — change the signature `float $quantity` → `string $quantity` (`RecordWorkOrderPart.php:18`) and drop the `(float)` cast at `WorkOrderController.php:218` (sole caller). Then: lock the part row (`lockForUpdate`), guard, insert the line with the validated 2-decimal string, then the stock write as a prepared raw update:
  ```php
  DB::update('UPDATE parts SET available_quantity = available_quantity - ? WHERE id = ?', [$quantity, $partId]);
  ```
  (PostgreSQL numeric arithmetic with a string binding — no PHP float math; `$quantity` is the validated decimal string.)

### Task 3.2: Insufficient stock rejected (TDD)
- RED: qty 3 vs stock 2 → 409 `Insufficient stock: only 2.000 available.`; no line; stock unchanged. Keep the existing zero-stock message untouched (`PartCompatibilityTest.php:373`). Separate 422 tests, all validation failures (NOT insufficient stock): over-precision input (`1.005`), **`quantity: 0`**, **`quantity: 0.00`**, and negative input.
- GREEN: controller rule per D2 — `['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,2})?$/']`. Keep `numeric` and `min:0.01`; the regex is additive for precision only. Guard compares the stored-precision value against stock.

### Task 3.3: Restore on remove — precision-pinned (TDD)
- RED: add qty 2 (10 → `'8.000'`), delete line → `'10.000'`. Add fractional round-trip (record `1.5`, restore `1.5`), exact-boundary (stock == requested OK; +0.001 rejected), over-precision (3-decimal quantity rejected by validation), concurrent-consumption (two parallel records serialize on the part lock).
- GREEN `DeleteWorkOrderPart::execute` — read the stored line quantity BEFORE delete; keep the audit event; restore via prepared SQL:
  ```php
  $partLine = WorkOrderPart::where('id', $workOrderPartId)
      ->where('work_order_id', $workOrderId)->lockForUpdate()->firstOrFail();
  $quantity = $partLine->quantity;
  $before = $partLine->toArray();
  $part = Part::where('id', $partLine->part_id)->lockForUpdate()->firstOrFail();
  $partLine->delete();
  DB::update('UPDATE parts SET available_quantity = available_quantity + ? WHERE id = ?', [$quantity, $part->id]);
  $logger->log('delete_work_order_part', $partLine, $before, []);
  ```
- Controller: `quantity` rule = `['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,2})?$/']` (Task 3.2's 422 tests) — **extending**, not replacing, today's `numeric|min:0.01` at `WorkOrderController.php:208-213`.
- **Audit:** add `available_quantity` before/after to the record/delete audit payloads (stock mutations must be auditable).
- Update stale comments: `RecordWorkOrderPart.php:53`, `PartIdentityResource.php:17`, `PartIdentity.vue:13`, manual parts section.
- Verify: `docker exec atms-api php artisan test --compact tests/Feature/Parts tests/Feature/WorkOrders/WorkOrderLifecycleTest.php tests/Feature/Reports/PartsConsumptionReportTest.php`.
- Commit + STATE/TLD + 🟠 trigger (ERP overwrite).

---

## Phase 4 — Vocabulary: expand → switch → contract (gated on Phase 1 only)

**Release 4a — additive schema + compatibility (safe with old code; ACCEPTS new values, WRITES nothing new):**
1. `OperationalStatus` enum gains `failure` and `at_the_field` **alongside** the legacy cases (old code keeps working).
2. Add `assets.condition_status` (nullable), `master_data_items.is_default` (boolean, default false), partial unique index `UNIQUE (group_key) WHERE is_default` (one active default per group), seed group `asset_conditions` — **`normal` seeded FIRST** with `is_default = true`, then `need_assembly`, `missing_parts`, `need_inspection`.
3. Backfill `condition_status = 'normal'` over NULLs. **Decided: the column stays nullable** — step 5 covers every creation path, so NOT NULL buys a table lock for no behavioural gain. (Recorded as a decision, not a deferral: CLAUDE.md requires every deferred item to carry a trigger, and this one had none.)
4. Migration test `tests/Feature/Migrations/StatusVocabularyTest.php` on the `RenameOperationalStatusValuesTest` pattern (up + down + idempotency); every migration gets a `down()` or an explicit "irreversible" comment with reason.
5. `CreateAsset.php:17` + both import commands: resolve the default condition on creation paths. **No at_the_field writes yet** (import derivation moves to 4b). `MasterDataItem`: `is_default` fillable + boolean cast.
6. `MasterDataController`: NEW group allowlist — must contain **both** `maintenance_priorities` (the only group that exists today; omitting it breaks the MR priority admin screen) **and** `asset_conditions`. The "Unclassified pattern" actually lives in `MaintenanceCategoryController.php:61-68` — mirror it for the default condition. **Keep stored values immutable** — allow label renames only (`MasterDataController.php:91` currently permits `value` edits at `:96` → orphan risk for any asset pointing at the old value).

**Release 4b — code switch + coordinated FE (downtime cutover, D1):**
- **Executable cutover sequence:** ① build the new image WITHOUT starting it (`docker compose build`); ② drain traffic and stop `api`, `queue`, `scheduler`; ③ run migrations from a **one-off new-image container** (`docker compose run --rm api php artisan migrate` — the old image lacks the new migration classes, so never run it from the old container); ④ start the new stack (`docker compose up -d`); ⑤ smoke checks (asset read with `failure` value, condition picker serves `asset_conditions`, close flow); ⑥ documented abort path (old image + restore from backup snapshot) in the release notes.
- **Value migration lives HERE (in 4b's migration, before the enum narrows):**
  ```sql
  UPDATE assets SET operational_status = 'failure' WHERE operational_status = 'down';      -- 2 rows (ids 353, 410)
  UPDATE assets SET operational_status = 'ready_for_field', is_active = false
    WHERE operational_status = 'scraped';                                                  -- 1 row (id 155, tag L-DHT-812-0576)
  ```
  ⚠️ The id comments are **annotation only** — the statements filter by value, which is correct and must stay that way. A WO close or manual status set between now and 4b changes which rows carry these values, so **the preflight derives the set by value, then asserts** — never "check these ids". (Ids captured 2026-08-16; id 155 re-confirmed live, 353/410 and the tag are from a single unverified snapshot.)
  ⚠️ Raw SQL bypasses Eloquent hooks — `Asset::booted`'s booking auto-release does NOT fire. Preflight: `SELECT id, asset_tag, operational_status FROM assets WHERE operational_status IN ('down','scraped')`, then assert 0 active bookings and 0 open WOs across that derived set (0/0 when last checked); if either is non-zero, release/cancel via the API first. **Migration test includes a fixture with an active booking** proving the release path.
- `OperationalStatus` → 4 cases (`ready_for_field`, `under_maintenance`, `failure`, `at_the_field`).
- **Imports (4b, not 4a):** the workbook import (`ImportAssetsCommand`) maps `down → failure` automatically, **rejects** `scraped`/`under_inspection`/`lih` with an actionable message, and derives `at_the_field` from the location column. `ImportErpAssetsCommand` has **no location input** (`:145` hardcodes two statuses) — it cannot derive `at_the_field`; it keeps writing `ready_for_field`/`under_maintenance` and `at_the_field` arises from location changes. Regenerate the committed `assets.csv` legacy values (or rely on the mapping).
- Status writes: close always `ready_for_field` (drop the optional param: `CloseWorkOrder.php:39` + `WorkOrderController.php:156,163-165,173`); cancel caller choice `failure|ready_for_field` (`WorkOrderController.php:188` literal → `Rule::in(['failure', 'ready_for_field'])`, per D7); approval → `failure`; **at_the_field helper** keyed off `AssetDeployment::forLocationType($loc->type) === DEPLOYED` (not literal rig/well_site — `AssetDeployment.php:43`), shared by `UpdateAssetLocation` + `CreateAsset`, with `$applyStatusRules = false` passed from `StartWorkOrder.php:107-113` (yard AND workshop moves are WO-owned; `WORK_LOCATION_TYPES` is `[WORKSHOP, YARD]` — the leaving-field rule must never fire inside WO start).
- **Q1 gate (user-initiated moves only — BOTH manual paths):** the manual-location gate is a **shared user-move guard** applied to `PATCH /assets/{id}` (`AssetController.php:120`) AND `POST /assets/{id}/location` (`AssetLocationController.php:14`), never inside `UpdateAssetLocation` (which `StartWorkOrder.php:107-113` also calls — workflow moves bypass the gate). Manual moves allowed for `ready_for_field` (any destination) and for `at_the_field` → yard/building (Q2 exit); `failure`/`under_maintenance` manual moves rejected (409); UI may show the control, disabled. Tests through BOTH endpoints.
- **Q2 rule (per D6, pending user confirmation):** user-initiated exit from the field → `ready_for_field` + `condition_status = need_inspection`. The item is seeded — resolve it strictly; if it is somehow missing/deactivated, **fail loudly** (audit + visible warning on the asset), never silently skip. WO-start moves do NOT stamp `need_inspection` unless D6 confirmation says otherwise.
- **Close reset:** close sets condition = the `is_default` item; warn (non-blocking) when the previous condition was `need_inspection` and no PM was marked (RQ1); cancel never resets.
- **Task 4.6 (Q4):** MR approval accepts an optional location (stable identifier for Tajoura Base; keep-current default; corrective + preventive; location-history row + audit in the same transaction — the `StartWorkOrder` optional-transfer pattern). Tests: default, explicit move, invalid location, both MR types, rollback.
- **Manual override (D3/D7):** `setAssetStatus` validation + WO dialog allow `ready_for_field | under_maintenance | failure` (never `at_the_field`); cancel validation uses `Rule::in(['failure', 'ready_for_field'])`, NOT `Rule::enum` (which would accept all four). Asset create/update (`AssetController.php:57,113`) get the same three-value subset.
- **Condition API contract (concrete tasks, not implied):** `AssetController` validates `condition_status` via `Rule::in` resolved from **active** `asset_conditions` rows; `AssetResource` exposes `condition_status` + its label; `ListOptionController.php:24` gains the `asset_conditions` group (currently hardcoded groups only); frontend `useListOptions.ts` gains `loadAssetConditions()`; `MasterDataItem` gets `is_default` in fillable + boolean cast (4a). Tests: picker serves seeded labels, inactive labels excluded, default resolution, close reset, cancel preservation, warning behavior.
- **Consumer manifest (floor list — regenerate from `rg` immediately before 4b implementation):** `CloseWorkOrder.php:59-64` guard removal + docblocks `:18-21`, `ApplyWorkOrderAssetStatusTransition.php:18-21`, `AssetHealthKpiQuery.php:51-55` (`by_status.down → by_status.failure` — API contract rename, in release notes), `AssetUtilisationQuery.php:129` (DOWN), `AssetDistributionReportQuery.php:138-141,217-220` (`down_count → failure_count`), `OperationalStatusDistributionReportQuery.php:9`, `AssetStatusReportQuery.php:25`, `ReportCsvColumns.php:64-71` + `ReportCsvExportTest.php`, `AssetController.php:59,115` (+ maintenance_sub_status validation removal), `AssetResource.php:38`, `Asset.php:33,56` fillable/cast, frontend `types`, `displayHelpers`, `assetColumns`, `AssetDetailView` (status 4 + condition picker fed by list options; sub-status picker removed), `WorkOrderDetailView` close dialog (no choice + warning), `useWorkOrderDetail.ts:236` payload types, `reportOptions`, R-1/Asset Distribution views, `DashboardView.vue`, `AssetStatusReport.vue`, `useDashboardKpis`, `useLists.ts:21-40` (`asset_conditions` LIST_GROUPS entry — otherwise no Admin UI), user manual (enumerate: `:511, :1102, :1148-1150, :1171-1195` "scraped is terminal" subsection, `:1735-1740, :1821-1825, :1918`, glossary `:3624-3640`), `docs/API.md:55-59` **and `:78`** (the `/work-orders/{workOrder}/asset-status` row also says "limited to `down` | `ready_for_field`" — two stale spots, not one), `docs/PRODUCT.md:207`.
- **Reporting (mandatory in this release, not Phase 8):** `condition_status` as filter + dimension in R-1, Asset Distribution, and CSV exports. R-11 is cancelled (Q7 = No) and R-10B stays removed.
- Tests: full sweep — the five v1-named files PLUS `AssetUtilisationTest.php`, `AssetStatusReportTest.php`, `ReportCsvExportTest.php`, `StartWorkOrderLocationTest.php`, `RenameOperationalStatusValuesTest.php:50,75` (comment or migrate the raw `scraped` assertion), contract tests for the renamed API keys.

**Release 4c — legacy removal (after 4b is verified live):**
- **Column drops only** (the value migration happened in 4b): drop `assets.maintenance_sub_status` and `assets.erp_status` (readers already gone in 4b), delete `app/Enums/MaintenanceSubStatus.php`, remove the enum's legacy remnants if any were kept for compatibility.
- Preflight: abort on unknown statuses or non-null deprecated data in production.

---

## Phase 5 — RQ2: attachment before close ✅ SHIPPED 2026-08-16
- **D5 is a precondition**, and confirming it needs a second answer: **what counts as the qualifying attachment.** "At least one attachment on the WO" is satisfied by any file already there — a photo uploaded at start — so it does not express what design §5.7/§7 RQ2 means (*the inspection form*). Expressing that needs a marker: an attachment category/type, or the RQ1 PM mark as the carrier. Pin this in the D5 conversation, not in review.
- If "required at completion" is confirmed, add a backend gate in `CompleteWorkOrder.php:19` (completion currently checks only status/auth/form completeness at `:25-40`) — apply the agreed qualifying-attachment definition, return a specified 409 with message, and test a DIRECT API completion without one. If not confirmed, make the UI step optional and skip the gate.
- UI: complete dialog attach step; backend upload unchanged (`AttachmentPolicy.php:50` verified).
- Commit + STATE/TLD.

## Phase 6 — RQ1: PM level marking ✅ SHIPPED 2026-08-17

Built to `docs/plans/2026-08-16-rq1-pm-level-marking-mini-spec.md`, all five of
its §4 decisions confirmed. `work_order_pm_marks` (unique per WO) staged and
applied at close, discarded on cancel; `PUT`/`DELETE /work-orders/{id}/pm-mark`
under `updateExecution`; the cascade generalised from `L1–L4` to any `L<number>`
(custom levels cascade to nothing, by design); the Need Inspection close warning
narrowed to "and no PM level was recorded".

## Phase 7 — RQ3: parts CSV down/up (DESIGN GATE — not executable as written)
Execute only after a mini-spec pins: export route/controller + exact CSV columns (`parts.id`, `erp_part_code`, name, quantity, …) + error contract; upload route/request/action + UI + tests. Q8 semantics pinned: **live ERP matching still uses external `erp_part_id`; ATMS relationships and CSV uploads use `parts.id`.** Ownership: ERP authority; overwrite trigger recorded 🟠 (Phase 3).

## Phase 8 — CANCELLED (Q7 answered "No", 2026-08-16)

LDC does not want a withdrawn / out-of-service report, so R-11 is not built and
this phase has no remaining content.

If it is ever revived, the constraint that killed the interesting version still
holds: a report **grouped by reason** (lost in hole / damaged beyond repair /
scrapped / disposed) is not recoverable from asset data. Those labels had no
home in the agreed vocabulary, so a withdrawal-reason field has to be added and
populated going forward *before* such a report is possible — and existing
retired assets would carry no reason at all. A plain count of deactivated assets
remains trivially buildable at any time.

---

## Rollout rules (revised)
- **Only Phase 1 gates Phase 4.** Phases 2 and 3 ship independently, whenever.
- ✅ Rig/well_site locations exist on dev as of 2026-08-17 (`RIG A`, `Well A`), so `at_the_field` is live rather than inert. Verify prod separately.
- Every phase commit updates `.kilo/STATE.md` + `.kilo/TLD.md` (not at the end).
- Backend commands: `docker exec atms-api php artisan …`; frontend: `cd frontend && npm run type-check && npm run build`. Pint with explicit touched paths.
- **Audit coverage required in tests:** stock mutations (before/after `available_quantity` on record/remove), `condition_status` changes (manual set, close reset, field-exit auto-set), and approval location moves (location history + audit row in the same transaction).
- **Frontend verification beyond type-check/build:** focused component/feature tests for the approval-location dialog, the close warning line, condition filters on R-1/Asset Distribution, the attachment-at-completion flow, and the reduced status/condition pickers — the changed workflows must be asserted, not just compiled.
