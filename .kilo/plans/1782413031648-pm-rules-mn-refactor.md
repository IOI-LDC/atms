# PM Rules — 1:1 to M:N Refactor

**Goal:** Replace the current per-asset PM rule model (`pm_rules.asset_id`) with a M:N template model where rules are reusable maintenance schedules assigned to multiple assets, each tracking its own compliance independently.

**Design Rule:** Architecture only. No code. The implementer has freedom on internal naming, file organisation, and implementation details as long as the contracts (schema, endpoints, resource shapes, RBAC) match this document.

---

## 1. Problem Statement

### Current state (committed, `50587a6`)

PM rules are bound 1:1 to an asset. `pm_rules.asset_id` is a required FK. A rule has a single `last_triggered_date` and `last_triggered_reading` — its compliance clock. Creating a rule requires immediately picking an asset. Every line of backend code (policy, resource, controller, evaluation job, WO closure), every endpoint, and every documentation page assumes this model.

### Why it's wrong

PM rules in this domain are **maintenance templates**, not asset-bound schedules. A monthly lubrication interval applies to dozens of pumps identically. The 1:1 model forces identical rules to be duplicated across every asset — creating maintenance overhead, hiding shared schedules from view, and making compliance tracking fragile (one pump's WO closure does not reset another pump's clock, but there's no structural grouping to express "these 12 pumps share the same schedule").

### The target model

- A **PM Rule** is a reusable template (~15 rows total). It defines a schedule: trigger type, interval, reading type, maintenance level. It has no `asset_id` and no per-asset state.
- An **Assignment** (pivot table `asset_pm_assignments`, model `AssetPmAssignment`) links a specific template to a specific asset. The pivot carries `last_triggered_date`, `last_triggered_reading`, and its own `is_active` flag — so each asset independently tracks its compliance against the shared schedule.
- Creating a template (Admin-only) and assigning it to an asset (Admin + Manager) are two distinct actions with two distinct permission checks.

### Why now

There is no production data. Refactoring the schema, policy model, and evaluation pipeline now avoids a painful migration later when assets have live PM history and the 1:1 model is entrenched in operational workflows.

### Scope note

`CLAUDE.md:296` lists "category-level or template-level PM rules" as out of scope for MVP. That ruling means "a rule that automatically applies to all assets of a given category or type" (e.g., every pump gets monthly lubrication with no explicit assignment step). The M:N model in this plan does **not** introduce category-level auto-apply: each template must be explicitly assigned to each asset by an Admin or Manager. `CLAUDE.md:182` ("PM rules apply to individual ATMS-managed assets only") remains true — every assignment links a concrete, individual asset to a concrete template.

This is a **reusable-schedule pattern**, not a category-auto-apply pattern. When implemented, a one-line scope-clarification note should be added to both `CLAUDE.md:296` and `docs/00-project-rules/SCOPE_CHANGE.md`. The interpretation of "template-level PM rules" in `CLAUDE.md:296` warrants explicit stakeholder sign-off — it's a close-to-the-line reading of a written exclusion.

---

## 2. Target Data Model

### `pm_rules` — template definition (drops asset ownership)

| Keep | Remove |
|---|---|
| `id` | ~~`asset_id`~~ |
| `name` | ~~`last_triggered_date`~~ |
| `maintenance_level` (nullable string, max 10) | ~~`last_triggered_reading`~~ |
| `description` | |
| `trigger_type` (`date`, `reading`, `date_or_reading`) | |
| `interval_days` | |
| `interval_reading` | |
| `usage_reading_type_id` | |
| `is_active` | |
| `created_by` | |
| `deactivated_by`, `deactivated_at` | |
| `reactivated_by`, `reactivated_at` | |
| `created_at`, `updated_at` | |

A rule now represents a pure schedule template — no asset, no compliance state.

### New: `asset_pm_assignments` — the assignment pivot

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint PK | |
| `asset_id` | FK → assets (cascade delete) | which asset this assignment belongs to |
| `pm_rule_id` | FK → pm_rules (**restrictOnDelete**) | which template is assigned |
| `last_triggered_date` | date nullable | per-asset baseline for date-triggered intervals |
| `last_triggered_reading` | decimal(12,2) nullable | per-asset baseline for reading-triggered intervals |
| `is_active` | boolean default true | deactivate THIS assignment without touching the template or other assets' assignments |
| `assigned_by` | FK → users | who created this assignment |
| `deactivated_by` | FK → users nullable | |
| `deactivated_at` | timestamp nullable | |
| `reactivated_by` | FK → users nullable | |
| `reactivated_at` | timestamp nullable | |
| `created_at`, `updated_at` | timestamps | |

**Unique constraint:** `UNIQUE(asset_id, pm_rule_id)` — the same template cannot be assigned twice to the same asset.

**`pm_rule_id` uses `restrictOnDelete`, not `cascadeOnDelete`.** Templates are never hard-deleted — only deactivated (`is_active = false`). `restrictOnDelete` protects assignment history and baseline data from accidental loss in the event a template row is ever removed. The Admin UI presents only "Deactivate" / "Reactivate," never "Delete."

### `pm_occurrence_suppressions` — no schema change

Current columns `pm_rule_id` + `asset_id` already identify a unique assignment. No structural change needed. Optional future cleanup: add `asset_pm_assignment_id` as a direct FK; defer to a separate task.

**Stale-suppression rule.** When an assignment is deactivated, any open suppression windows for that (`pm_rule_id`, `asset_id`) pair are cleared by the `DeactivateAssetPmAssignment` action — it sets `suppressed_until_date = now()` (or null) and `suppressed_until_reading = null` on all active suppressions for that pair. This prevents a reactivated assignment from being silently blocked by suppression windows created before deactivation (including windows deliberately set far into the future). The alternative of filtering at read time appears to work but does not — reactivation restores `is_active = true`, which would re-admit the old windows through any `is_active`-keyed filter. Clearing on deactivation is the deterministic fix.

**FK asymmetry note:** `asset_pm_assignments.pm_rule_id` uses `restrictOnDelete` while `pm_occurrence_suppressions.pm_rule_id` retains `cascadeOnDelete` from the original migration. Moot since templates are never hard-deleted; documented for awareness.

---

## 3. RBAC

Two-layer permission model. Template lifecycle is Admin territory. Assignment lifecycle is Admin + Manager.

| Action | Admin | Manager | Policy |
|---|---|---|---|
| Create / edit / deactivate / reactivate a **template** | Yes | No | `PmRulePolicy` |
| View template list | Yes | Yes | `PmRulePolicy::viewAny` |
| View template detail (includes assigned assets) | Yes | Yes | `PmRulePolicy::view` |
| Assign a template to an asset | Yes | Yes | `AssetPmAssignmentPolicy::create` |
| Deactivate / reactivate an **assignment** | Yes | Yes | `AssetPmAssignmentPolicy::deactivate` / `reactivate` |
| Evaluate an **assignment** (generate PM MR for that asset-rule pair) | Yes | Yes | `AssetPmAssignmentPolicy::evaluate` |
| Evaluate all active assignments (manual trigger) | Yes | Yes | `AssetPmAssignmentPolicy::evaluateAll` |
| View assignment list on Asset Detail | Yes | Yes | `AssetPmAssignmentPolicy::viewAny` |

**Template deactivation guard:** `DeactivatePmRule` currently calls `$locked->hasActiveChain()` on the `PmRule` model. Under M:N a template has no chain — chains live on assignments. The guard must call the new `PmRule::hasAnyActiveChain()` method instead (§7.4). Admin cannot deactivate a template if ANY active assignment for that template has an active MR/WO chain. If no assignments have active chains, deactivation proceeds — it's equivalent to "retire this schedule."

**Template deactivation is a read-level filter, not a cascade.** Deactivating a template sets `is_active = false`; it does NOT deactivate its assignments (their `is_active` is independent). The evaluation layer (§7.3, §7.6, §7.9) filters assignments whose template is inactive, so a retired template generates no new PM work despite its assignments remaining individually active. This keeps assignment history intact for audit while stopping the compliance clock.

### Initial-baseline policy

When a template is first assigned to an asset, the pivot's `last_triggered_date` and `last_triggered_reading` are null. `PmDueCalculator::isDueByDate` returns `true` when the baseline is null (`PmDueCalculator.php:28–30`) — so a freshly created assignment is immediately due. Under the 1:1 model this edge case was rarely hit; under M:N, bulk-assigning one template to many assets would generate N MRs on the next daily evaluation.

**Decision:** the `POST /api/assets/{asset}/pm-assignments` handler sets `last_triggered_date` to `now()` on creation, and `last_triggered_reading` to the asset's latest confirmed reading for that rule's reading type (if available). This gives every new assignment one full grace interval before its first PM is due. The response includes these initial baseline values so the UI can display them immediately without a re-fetch.

---

## 4. Schema Changes (migrations)

Two migration files:

### Migration 1: Create `asset_pm_assignments`

Creates the pivot table with all columns, FKs (including `restrictOnDelete` on `pm_rule_id`), and the `UNIQUE(asset_id, pm_rule_id)` constraint.

### Migration 2: Drop columns from `pm_rules`

Drop `asset_id`, `last_triggered_date`, `last_triggered_reading`. These columns are unused (dev data only, no production migration needed).

---

## 5. Endpoint Contracts

### 5.1 Template CRUD — prefix `/api/pm-rules`

| Method | Path | Auth | Notes |
|---|---|---|---|
| `GET` | `/pm-rules` | Admin + Mgr | cursor-paginated template list; filters: `is_active`, `sort` (`name`, `created_at`, `is_active`). Response: `PmRuleResource` collection |
| `POST` | `/pm-rules` | Admin | body: `name` (req), `maintenance_level` (opt, max 10), `description` (opt), `trigger_type` (req), `interval_days` (req if date/combined), `interval_reading` (req if reading/combined), `usage_reading_type_id` (req if reading/combined). Response `201`: `PmRuleResource` |
| `GET` | `/pm-rules/{rule}` | Admin + Mgr | template detail + assigned assets list. Response: `PmRuleResource` with `assignments` whenLoaded |
| `PATCH` | `/pm-rules/{rule}` | Admin | same body shape as POST (all optional). Response `200`: `PmRuleResource` |
| `POST` | `/pm-rules/{rule}/deactivate` | Admin | uses `hasAnyActiveChain()` guard (§7.4); 409 if any assignment has an active MR/WO chain. On success, sets `is_active = false` — a retired template stops generating PM work at the evaluation layer (§3, §7.6) without deactivating individual assignments. |
| `POST` | `/pm-rules/{rule}/reactivate` | Admin | |
| `GET` | `/pm-rules/{rule}/assignments` | Admin + Mgr | all `AssetPmAssignmentResource` rows for this template. For Admin to see coverage |

**Removed endpoints** (no longer meaningful under M:N):
- `POST /pm-rules/{rule}/evaluate` — was single-rule evaluation (1 rule = 1 asset). Replaced by assignment-scoped evaluate.
- `POST /pm-rules/evaluate` — replaced by `POST /pm-rules/evaluate-all` (iterates assignments).

### 5.2 Assignment CRUD — prefix `/api/assets/{asset}/pm-assignments`

| Method | Path | Auth | Notes |
|---|---|---|---|
| `GET` | `/assets/{asset}/pm-assignments` | Admin + Mgr | lists assignments for this asset; supports `?is_active=1/0` (default: active only), each with computed `next_due_date`, `next_due_reading`, `progress_percentage`, `pm_status`. Response: `AssetPmAssignmentResource` collection |
| `POST` | `/assets/{asset}/pm-assignments` | Admin + Mgr | body: `{ pm_rule_id }` (required, must exist and be active). Sets initial baselines per §3. Response `201`: `AssetPmAssignmentResource` |
| `GET` | `/assets/{asset}/pm-assignments/{assignment}` | Admin + Mgr | single assignment detail. Response: `AssetPmAssignmentResource` |
| `POST` | `/assets/{asset}/pm-assignments/{assignment}/deactivate` | Admin + Mgr | 409 if active MR/WO chain for THIS assignment; on success, clears open suppression windows per §2 |
| `POST` | `/assets/{asset}/pm-assignments/{assignment}/reactivate` | Admin + Mgr | |
| `POST` | `/assets/{asset}/pm-assignments/{assignment}/evaluate` | Admin + Mgr | if due → 201 with MR; if not → 200 "not due" |

**Route-model binding:** `{assignment}` resolves by `asset_pm_assignments.id`. To prevent cross-asset access (e.g., `/assets/A/pm-assignments/{assignment_belonging_to_B}`), either use scoped binding or validate in the controller that `$assignment->asset_id === $asset->id`. Mirror the pattern used by `WorkOrderPartController` for consistency.

### 5.3 Manual evaluate-all

`AssetPmAssignmentController::evaluateAll` is routed at `POST /api/pm-rules/evaluate-all` — it lives outside its controller's normal `/assets/{asset}/pm-assignments` prefix because it is not asset-scoped, but `AssetPmAssignmentController` is its natural home (it operates on assignments). `Gate::authorize('evaluateAll', AssetPmAssignment::class)` gates it.

| Method | Path | Auth | Notes |
|---|---|---|---|
| `POST` | `/pm-rules/evaluate-all` | Admin + Mgr | iterates all active `AssetPmAssignment` rows, generates MRs for due ones. Response: `{ evaluated: N, generated: M }` |

**Breaking change from current API:** existing `POST /api/pm-rules/evaluate` returns a plain string `{"message": "Evaluated N rules, generated M requests."}`. The new `evaluate-all` endpoint uses structured `{ evaluated, generated }`. The frontend composable `usePmRules.ts` must be updated to parse the new shape. **Route ordering:** `POST /api/pm-rules/evaluate-all` (2 path segments) is a literal path — Laravel matches literal routes before parameterised ones of the same segment count, but this path cannot collide with any `POST /api/pm-rules/{pmRule}/*` route (3 segments). Register it in the standard order; the comment is just a clarity note.

### 5.4 Maintenance-request `pm_rule_id` filter

`GET /api/maintenance-requests?pm_rule_id={id}` continues to work. The filter matches `maintenance_requests.pm_rule_id` which still references `pm_rules.id`. No change needed.

---

## 6. Resources

### 6.1 `PmRuleResource` — template shape

Template fields only — no asset, no computed status, no per-asset baselines.

```
{
  id, name, maintenance_level, description, trigger_type, is_active,
  interval_days, interval_reading,
  usage_reading_type: { id, name, unit } | null,   // whenLoaded
  assignments_count: int,                           // active assignment count
  created_by: { id, name } | null,                  // Admin + Manager only
  created_at, updated_at
}
```

**`show` endpoint only** (whenLoaded): `assignments` — collection of `AssetPmAssignmentResource` (all assignments for this template, for Admin coverage view).

### 6.2 `AssetPmAssignmentResource` — assignment shape

This is the resource the Asset Detail screen loads. It carries per-asset compliance state AND nests the rule template so the UI can display schedule info.

```
{
  id, asset_id, pm_rule_id,
  is_active,
  last_triggered_date, last_triggered_reading,
  next_due_date,                   // computed: baseline + interval (null if not date-based or no baseline)
  next_due_reading,                // computed: baseline + interval (null if not reading-based or no baseline)
  progress_percentage,             // 0-100, max of date/reading progress; null if no baseline or no confirmed reading
  pm_status: "ok" | "soon" | "due", // computed: due if PmDueCalculator::isDue true; else >=80% = due, >=60% = soon, <60% = ok
  rule: {                          // nested — always loaded
    id, name, maintenance_level, trigger_type,
    interval_days, interval_reading,
    usage_reading_type: { id, name, unit } | null
  },
  assigned_by: { id, name },
  assigned_at,                     // created_at of the pivot row
  suppressions: [...]              // whenLoaded (show endpoint only)
}
```

**Computed-field logic** is structurally identical to the current `PmRuleResource` — it reads baselines from the pivot row and fetches the latest confirmed `AssetMeterReading` for the asset + reading-type pair — but the `PmDueCalculator` must be refactored to accept an `AssetPmAssignment` instead of a `PmRule` (§7.6).

---

## 7. Refactored Logic

### 7.1 WO closure baseline reset

`CloseWorkOrder::execute` currently finds the originating `PmRule` via `mr->pm_rule_id` and updates its baselines.

**Refactored:** find the originating **assignment** via `mr->pm_rule_id` + `mr->asset_id` (uniquely identifies an `AssetPmAssignment` row). If found, update the pivot's `last_triggered_date` and `last_triggered_reading`. Audit-log the pivot row.

### 7.2 Cumulative reset

Same logic, pivot-scoped. When closing an L3 WO for asset X:

1. Find the originating assignment (L3 template + asset X).
2. Parse the template's `maintenance_level` → numeric level.
3. Find all OTHER active `AssetPmAssignment` rows for **the same asset** whose rule's `maintenance_level` parses to a lower numeric L-level.
4. Reset THEIR pivot baselines to `now()` and the latest confirmed reading for that rule's reading type.
5. Audit-log each reset.

Custom free-text levels are skipped (unchanged).

### 7.3 `EvaluatePmRulesJob` (daily scheduler)

Currently iterates `PmRule::where('is_active', true)`. **Refactored:** iterates `AssetPmAssignment::where('is_active', true)->whereHas('pmRule', fn ($q) => $q->where('is_active', true))` — all active assignments whose template is also active. A retired template's assignments are skipped regardless of assignment state.

For each assignment:
- Load the template + latest confirmed reading.
- `PmDueCalculator::isDue()` using the pivot's baselines (refactored signature, §7.6).
- `hasActiveChain()` scoped to this assignment (§7.4).
- If due and no active chain → generate a preventive MR (same shape as today: `is_preventive = true`, `pm_rule_id = template.id`, `asset_id = assignment.asset_id`).

### 7.4 `hasActiveChain` — moves to the pivot model

```php
AssetPmAssignment::hasActiveChain(): bool
```
Checks: `pending_review` MR where `pm_rule_id = this.pm_rule_id AND asset_id = this.asset_id AND is_preventive = true`, OR active WO (`open|in_progress|completed`) linked to such an MR.

The **template** (`PmRule`) gets a `hasAnyActiveChain(): bool` method — checks whether ANY assignment row for this template has an active chain. Used for the Admin template-deactivation guard (§3).

### 7.5 `EvaluatePmRule` action — assignment-scoped rewrite

This action (`app/Actions/Pm/EvaluatePmRule.php`) currently takes a `PmRule`, locks it, reads `$locked->asset_id` (line 44 for the reading query, line 59 for MR creation), and uses `$locked->name` (line 63 for the MR description). Under M:N it must take an `AssetPmAssignment` instead, read `$assignment->asset_id`, and obtain the template name via `$assignment->pmRule->name`. All call sites (manual evaluate endpoint, daily job) pass the assignment.

### 7.6 `PmDueCalculator` — signature and baseline source change

The calculator currently accepts `PmRule $rule` and reads baselines directly off the model (`PmDueCalculator.php:28`, `49–50`, `109–125`). Every call site breaks:

- `EvaluatePmRule` action (i.e., manual evaluation of an assignment)
- `EvaluatePmRulesJob` (daily scheduler)
- `OverduePmQuery` (dashboard widget)
- Computed fields in `AssetPmAssignmentResource` (moved from `PmRuleResource`)

**Refactored:** `PmDueCalculator` takes an `AssetPmAssignment` (which eager-loads its `pmRule`). The calculator reads interval config from `$assignment->pmRule` and baselines from the pivot row. The `isDueByDate`, `isDueByReading`, `isTriggeredByDate`, `isTriggeredByReading` methods are unchanged algorithmically — only the baseline source changes. The initial `isDue()` guard changes from `! $rule->is_active` (line 15) to `! $assignment->is_active || ! $assignment->pmRule->is_active` — both the assignment and its template must be active for evaluation to proceed.

### 7.7 `CreatePmSuppression` — asset_id sourced from MR, not rule

`app/Actions/Pm/CreatePmSuppression.php:32` reads `'asset_id' => $rule->asset_id` to populate the suppression row. After Migration 2, `PmRule` has no `asset_id`. **Fix:** use `'asset_id' => $maintenanceRequest->asset_id` — the MR already carries the correct asset ID. The suppression remains keyed by `(pm_rule_id, asset_id)` as it is today; only the source of the asset ID changes.

### 7.8 `DeactivateAssetPmAssignment` — clears suppression windows

`DeactivateAssetPmAssignment::execute` deactivates the pivot row AND runs a side-effect: update all `PmOccurrenceSuppression` rows for this `(pm_rule_id, asset_id)` pair that have a `suppressed_until_date >= now()` (i.e., still-active windows), setting `suppressed_until_date = now()` and `suppressed_until_reading = null`. This is the deterministic stale-suppression fix from §2 — it prevents a reactivated assignment from being blocked by windows created before deactivation, including future-dated windows. Audit-log each cleared window. Call this from the deactivate endpoint in `AssetPmAssignmentController`.

### 7.9 `OverduePmQuery` / `DashboardController` — rewrite for assignments

Current `OverduePmQuery::execute` queries `PmRule::where('is_active', true)->with('asset')` and filters via `PmDueCalculator::isDue($rule)`. **Refactored:** queries `AssetPmAssignment::where('is_active', true)->whereHas('pmRule', fn ($q) => $q->where('is_active', true))->with(['asset', 'pmRule'])` and filters via `PmDueCalculator::isDue($assignment)` (which itself double-gates on template is_active per §7.6).

`DashboardController.php:64–65` builds the `overdue_pm_rules` response key and wraps results in `PmRuleResource`. The resource changes to `AssetPmAssignmentResource`. The response key renames to `overdue_pm_assignments` — a **frontend+doc breaking change** (the Dashboard composable reads this key).

### 7.10 Deactivate / reactivate Actions

- `DeactivatePmRule` / `ReactivatePmRule` — stay on `PmRule` (template-level). Admin only. The guard method changes from `hasActiveChain()` (which no longer exists on `PmRule`) to the new `hasAnyActiveChain()` (§7.4).
- `DeactivateAssetPmAssignment` / `ReactivateAssetPmAssignment` — operate on the pivot. Admin + Manager. `DeactivateAssetPmAssignment` includes the suppression-window clearing side-effect (§7.8).

---

## 8. Frontend Impact (described, not specified)

The implementer has freedom on component decomposition, styling, and internal naming. The table below captures what each screen must do. The existing 1:1 frontend is substantial; these are **rewrites**, not tweaks.

| Screen | Today | After |
|---|---|---|
| **PM Rules tab** (Admin) | Creates rules with asset; list has asset column + status | Manages **templates** (~15 rows); Admin creates/edits templates. Columns: name, level badge, trigger type, interval, assignments count, active/inactive. No per-asset status column. |
| **Asset Detail** | No PM section | New **"PM Rules" section**: lists assigned templates with `pm_status` indicator (🟢🟡🔴), last triggered, evaluate button per row. **"Assign Rule"** button (Admin + Manager) opens a search/select of available templates. Manager can deactivate individual assignments. Default filter = active, with a toggle to show inactive (so deactivated assignments remain reachable for reactivation). |
| **Dashboard → overdue PM** | Lists overdue pm_rules rows; reads `overdue_pm_rules` response key | Lists overdue **assignments** (asset-rule pairs). Reads `overdue_pm_assignments` response key. Each row: asset name, rule name, overdue status. |
| **Evaluate All** button | On PM Rules list | On PM Rules tab (Admin + Manager). Triggers `POST /pm-rules/evaluate-all`. Parses `{ evaluated, generated }` (structured) instead of the old string message. |

Asset Detail is the natural home for assignment management — that's where a Manager thinks about an asset's maintenance schedule.

---

## 9. Implementation Plan

All work items ordered by dependency. The implementer may batch independent items within a phase.

### 9.1 Schema & Models

| # | Work Item | Depends On |
|---|---|---|
| S1 | Migration: create `asset_pm_assignments` table (all columns, `UNIQUE`, `restrictOnDelete` on `pm_rule_id`) | — |
| S2 | Migration: drop `asset_id`, `last_triggered_date`, `last_triggered_reading` from `pm_rules` | S1 |
| S3 | `PmRule` model: remove `asset()` relation, add `assets()` (belongsToMany → `->using(AssetPmAssignment::class)->withPivot(['last_triggered_date', 'last_triggered_reading', 'is_active'])`), remove fillable for dropped columns, add `assignments()` (hasMany `AssetPmAssignment`), add `hasAnyActiveChain()` | S2 |
| S4 | `AssetPmAssignment` model: fillable, casts, relations (`asset`, `pmRule`, `assignedBy`), `hasActiveChain()`, scoped `latestConfirmedReading()` | S1 |

### 9.2 Policy & Resources

| # | Work Item | Depends On |
|---|---|---|
| P1 | `PmRulePolicy`: keep `viewAny`/`view` (Admin+Mgr), keep all mutations (Admin only). Remove evaluate methods. Add `viewAssignments` (Admin+Mgr) | S3 |
| P2 | `AssetPmAssignmentPolicy`: `viewAny`/`view`/`create`/`deactivate`/`reactivate`/`evaluate`/`evaluateAll` — all Admin+Mgr | S4 |
| P3 | `PmRuleResource`: template-only shape (remove computed fields, add `assignments_count`). Template `show` loads assignments via `whenLoaded` | S3 |
| P4 | `AssetPmAssignmentResource`: per-asset assignment shape with computed fields (move logic from current `PmRuleResource`) + nested `rule` | S4 |

### 9.3 Controllers & Routes

| # | Work Item | Depends On |
|---|---|---|
| C1 | `PmRuleController`: strip `asset_id` from store validation, remove evaluate methods, add `assignments` endpoint, return `PmRuleResource` | P1 |
| C2 | `AssetPmAssignmentController`: full CRUD for assignments under `/api/assets/{asset}/pm-assignments` with scoped route-model binding (§5.2) and `?is_active` filter on GET; evaluate endpoint; `evaluateAll` method routed at `/pm-rules/evaluate-all` (§5.3). `POST` sets initial baselines per §3. `deactivate` includes suppression-window clearing (§7.8). | P2, P4 |
| C3 | Routes: add assignment routes, remove old evaluate routes, wire `evaluate-all` as described in §5.3, add scoped binding for `{assignment}` | C1, C2 |

### 9.4 Refactored Logic

| # | Work Item | Depends On |
|---|---|---|
| R1 | `PmDueCalculator`: refactor signature from `PmRule` to `AssetPmAssignment`. Read baselines from pivot; read interval config from `$assignment->pmRule`. All 4 call sites updated. | S4 |
| R2 | `EvaluatePmRule` action: rewrite to take `AssetPmAssignment` instead of `PmRule`. Read `$assignment->asset_id` and `$assignment->pmRule->name`. | R1 |
| R3 | `EvaluatePmRulesJob`: refactor to iterate `AssetPmAssignment::where('is_active', true)` rows | R1, R2 |
| R4 | `CloseWorkOrder`: refactor baseline reset to pivot (`mr->pm_rule_id + mr->asset_id`), cumulative reset to sibling `AssetPmAssignment` rows on the same asset | S4 |
| R5 | `CreatePmSuppression`: change `$rule->asset_id` → `$maintenanceRequest->asset_id` at line 32 | S2 |
| R6 | `DeactivatePmRule` action: change guard from `$locked->hasActiveChain()` to `$locked->hasAnyActiveChain()` | S3 |
| R7 | `OverduePmQuery`: refactor `PmRule` collection → `AssetPmAssignment` collection. Filter via refactored `PmDueCalculator`. | R1 |
| R8 | `DashboardController`: rename response key `overdue_pm_rules` → `overdue_pm_assignments`; wrap results in `AssetPmAssignmentResource` | R7, P4 |
| R9 | `DeactivateAssetPmAssignment` action: implement suppression-window clearing side-effect (§7.8). Audit-log each cleared window. | S4 |
| R10 | Wire template-`is_active` guard into evaluation: `EvaluatePmRulesJob` queries `whereHas('pmRule', is_active)` (§7.3); `PmDueCalculator::isDue` gates on `! $assignment->pmRule->is_active` (§7.6); `OverduePmQuery` filters via `whereHas` (§7.9). | S4 |

### 9.5 Documentation

Every doc that currently describes the 1:1 model must be updated. This table is the "docs-gap close" deliverable.

| # | Document | What Changes |
|---|---|---|
| D1 | `CLAUDE.md:296` | Add scope-clarification note: M:N assignment model is not "category-level" / "template-level" in the out-of-scope sense (§1). Requires stakeholder sign-off. |
| D2 | `CLAUDE.md:182` | Update: "PM rules are reusable templates assigned to individual ATMS-managed assets" |
| D3 | `docs/00-project-rules/SCOPE_CHANGE.md` | Record M:N refactor as an explicitly-allowed refinement of the per-asset constraint |
| D4 | `docs/03-backend/RBAC.md L66–67, L129–130, L132–146` | Split PM row into "Manage templates" (Admin) + "Assign rules / evaluate" (Admin+Mgr). Soft-update the "Known gap — Manager access" block: note that assignment management is now reachable via Asset Detail (Manager already sees Asset Management in the sidebar), and that template-viewing from the Admin tab is deferred (see F10). |
| D5 | `docs/atms/04-technical/BACKEND_API_REFERENCE.md L973–1115` | Entire PM section: `POST` drops `asset_id` (req); `PmRuleResource` loses asset/last_triggered/computed fields; new `AssetPmAssignmentResource` + `/assets/{asset}/pm-assignments` routes; `evaluate-all` endpoint replaces old evaluate. Retain the suppression snapshot fields (`decision_type`, `triggered_by_date`/`_reading`, `trigger_date`, `trigger_reading_value`, `trigger_reading_type_id`) in the rewritten resource sub-shapes — these exist in the current code and are easy to drop in a wholesale rewrite. Document template-deactivation semantics: a retired template stops all PM evaluation (job, calculator, overdue query) but does not cascade-deactivate its assignments. |
| D6 | Dashboard doc (widgets table) L262/270/275 | `overdue_pm_rules` → `overdue_pm_assignments`; wrapped in `AssetPmAssignmentResource` |
| D7 | `docs/03-backend/JOBS_AND_SCHEDULER.md L17–24` | "PM Rule Evaluation" iterates assignments, not rules |
| D8 | `docs/atms/01-product/ROLES_AND_PERMISSIONS.md` | Manager role: add "assign PM rules to assets, evaluate assignments" |
| D9 | `docs/atms/01-product/WORKFLOWS.md §1` | Steps 1, 14: template model, per-assignment baseline reset, cumulative reset on assignments |
| D10 | `docs/atms/02-design/SCREEN_INVENTORY.md §7c` | Template management (Admin) + Assignment on Asset Detail (Admin + Manager) |
| D11 | `docs/atms/02-design/NAVIGATION.md §7` | Admin tab: PM Rules = template management |
| D12 | `docs/atms/04-frontend/ROUTES.md` | Asset Detail route now includes PM assignments section |
| D13 | `docs/atms/04-technical/BACKEND_API_HANDOFF.md` | PmRule TS interface (template), AssetPmAssignment interface, assignment routes |

### 9.6 Frontend

Existing files that become rewrites under M:N (not tweaks — the data model under them changes fundamentally):

| # | File | Change |
|---|---|---|
| F1 | `views/pm-rules/PmRulesView.vue` (325 lines) | Rewrite: template list — no asset column, no per-row status, new columns (assignments count, interval), form loses asset_id |
| F2 | `views/pm-rules/PmRuleDetailView.vue` (357 lines) | Rewrite: template detail + assigned-asset coverage table, no per-asset computed fields |
| F3 | `components/pm-rules/PmRuleForm.vue` (377 lines) | Rewrite: form drops asset_id field, drops asset creation mode |
| F4 | `composables/usePmRules.ts` | Rewrite: template CRUD methods, new `evaluate-all` response shape, remove old evaluate methods |
| F5 | `lib/pmSchedule.ts` | This helper is currently called from two contexts: the template list (F1, reading `PmRuleResource` where intervals are top-level) and the Asset Detail (F7, reading `AssetPmAssignmentResource` where intervals are nested under `rule`). The implementation must accept either shape — branch on presence of the `rule` wrapper or accept a normalised input. A one-line swap of `rule.interval_*` → `assignment.rule.interval_*` would break the template list. |
| F6 | `types/index.ts` | Add `PmRule` (template), `AssetPmAssignment` interfaces; update `overdue_pm_assignments` key |
| F7 | Asset Detail view | New: "PM Rules" section — assignment list with computed status, Assign Rule button, per-row evaluate/deactivate/reactivate. Default-filter active, toggle to show inactive (so deactivated assignments remain reachable per §5.2). |
| F8 | Dashboard composable | Update `overdue_pm_rules` → `overdue_pm_assignments` key, resource shape |
| F9 | `views/admin/AdminView.vue` | PM Rules tab now shows template management, not per-asset rules |
| F10 | `components/app/AppSidebar.vue` | Manager reaches PM assignments via Asset Detail (which they already see per `AppSidebar.vue:70`). Making the Admin tab reachable to Managers for template-viewing is **deferred** — recorded in `docs/03-backend/RBAC.md` Known gap and `.kilo/STATE.md` Open Follow-ups. |

### 9.7 Tests

Existing test files that must be updated for the new model:

| # | File | Change |
|---|---|---|
| T1 | `tests/Unit/Pm/PmDueCalculatorTest.php` | Signature change: `PmRule` → `AssetPmAssignment` |
| T2 | `tests/Feature/ReadModels/PmRuleResourceTest.php` | Resource becomes template-only |
| T3 | `tests/Feature/Pm/PmWorkflowTest.php` | 1:1 assertions → M:N assertions |
| T4 | `tests/Feature/Jobs/EvaluatePmRulesJobTest.php` | Iterates assignments, not rules |

New tests to write:

| # | Test | Covers |
|---|---|---|
| T5 | Assignment CRUD (create, read, deactivate, reactivate) | `AssetPmAssignmentController` |
| T6 | Assignment evaluate (due → 201 with MR, not-due → 200, active-chain → 409) | `AssetPmAssignmentController` |
| T7 | Evaluate-all (iterates assignments, structured response) | `POST /pm-rules/evaluate-all` |
| T8 | Cumulative reset on pivot (L3 close → L1/L2 baselines reset; custom level skipped) | `CloseWorkOrder` |
| T9 | Template deactivation guard (`hasAnyActiveChain` blocks 409, no-chain → 200) | `DeactivatePmRule` |
| T10 | Manager can assign / evaluate / deactivate assignment | `AssetPmAssignmentPolicy` |
| T11 | Manager cannot create / edit / deactivate template | `PmRulePolicy` |
| T12 | Initial baseline set on assignment create | `POST /assets/{asset}/pm-assignments` |
| T13 | Suppression creation uses MR asset_id, not rule asset_id | `CreatePmSuppression` |
| T14 | Deactivating assignment clears active suppression windows; a future-dated window set before deactivation does NOT block a freshly-reactivated assignment | `DeactivateAssetPmAssignment` |
| T15 | Route scoping: cross-asset assignment access rejected | route-model binding |
| T16 | Retired template's active assignment does not generate an MR (job) and is not shown as overdue (dashboard); reactivating the template re-enables both | template-`is_active` guard (§7.3, §7.6, §7.9) |

---

## 10. Risk Notes

1. **`pm_rule_id` filter on MR index still works.** The MR table stores `pm_rule_id` (the template ID) — not the assignment ID. This is correct: an MR says "I was generated by template X for asset Y." No migration needed on `maintenance_requests`.

2. **Suppressions are assignment-scoped by construction.** `pm_occurrence_suppressions` has `pm_rule_id + asset_id` which uniquely maps to an `AssetPmAssignment` row. Adding a direct `asset_pm_assignment_id` FK is a nice-to-have but not blocking.

3. **Frontend asset-detail PM section requires a `GET /api/pm-rules?is_active=true` endpoint to populate the "Assign Rule" template picker.** The template list endpoint is already available; the frontend composable just needs to call it.

4. **The `assignments_count` on `PmRuleResource` prevents N+1.** Use a scoped count: `withCount(['assignments' => fn ($q) => $q->where('is_active', true)])`. A plain `withCount('assignments')` counts all rows (active + inactive); the resource explicitly says "active assignment count."

5. **Manager can now create PM-related records (assignments).** The "assign a template to an asset" action is conceptually different from "define a new maintenance schedule" — it's a maintenance-planning action, not an administration action. This split future-proofs the RBAC: if the system later adds more workflow around assignment (e.g., "requires Maintenance Manager approval to assign a rule"), the policy boundary is already drawn correctly.

6. **`GET /api/pm-rules/{rule}/assignments` vs `GET /api/assets/{asset}/pm-assignments`.** Two different lenses on the same pivot table. The first answers "which assets use this template?" (Admin coverage view). The second answers "which rules apply to this asset?" (Asset Detail view). Both are needed; they return the same resource shape.

7. **No production data migration needed.** The tables are dev-only. Both migration files can be destructive (drop-column) with no data loss concern.

8. **`belongsToMany` on `PmRule` needs explicit `->using(AssetPmAssignment::class)->withPivot([...])`.** The default pivot model won't carry `last_triggered_date`, `last_triggered_reading`, or `is_active`. The `assets()` relation must declare these pivot columns explicitly.

9. **Response-key rename is a frontend break.** `overdue_pm_rules` → `overdue_pm_assignments` in the Dashboard response. The frontend composable must be updated in the same deployment as the backend change. Coordinate with §9.6 F8.

10. **`belongsToMany assets()` via `withPivot` exposes baselines for admin coverage, but the primary query path for evaluation is `AssetPmAssignment` directly.** The `belongsToMany` is for template management views ("which assets have this template?"). All compute-heavy paths (evaluation, overdue, WO closure) query the pivot directly for performance and correct scoping.
