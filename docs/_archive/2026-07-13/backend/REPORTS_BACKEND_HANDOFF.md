# Reports Pass 1 & 2 — Backend Completion & Frontend Handoff

> **Status:** ✅ Complete (18 endpoints; full suite last verified: 651 tests, 1,858 assertions)
> **Date:** 2026-07-12
> **Branch:** feat/reports-pass1-frontend (not pushed)
> **Plans:** `.kilo/plans/1783838549346-reports-pass1-backend.md`, `.kilo/plans/1783838549347-reports-pass2-backend.md`

---

## Overview

18 read-only report endpoints are available under `GET /api/reports/*`. Pass 1 delivered 6 Must-tier reports (R-1, R-2, R-7, R-8, R-10A, R-14). Pass 2 delivered 12 additional reports (R-3, R-4, R-6, R-9, R-13, R-15 through R-21). R-5, R-10B, R-11, and R-12 remain deferred (blocked by missing infrastructure or Phase 2 scope).

All endpoints require authentication (`auth:sanctum`) and the `dashboard:view` token ability. All 5 roles (Administrator, Maintenance Manager, Technician, Logistics, Requester) have access. Reports are **org-wide** — no per-role row scoping.

---

## Endpoints

### 1. Upcoming PM Schedule (R-1)
```
GET /api/reports/upcoming-pm
```
**Response shape:** `{ summary, items }` (non-paginated)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `days` | int (1–365) | 30 | Forward window in days |
| `location_id` | int? | — | Filter by asset location |
| `pm_rule_id` | int? | — | Filter by PM rule |

**Summary fields:** `total`, `by_trigger_type` (keyed by enum: `date`, `date_or_reading`), `by_due_week` (ISO week: `2026-W28`)

**Item fields:** `assignment` (includes `asset`, `pmRule` relations), `next_due_date` (ISO‑8601 date), `days_until_due` (int), `chain_status` (one of: `not_yet_generated`, `generated_mr_pending`, `wo_open`, `wo_completed`)

Date-triggered only (`PmTriggerType::DATE` + `DATE_OR_READING`). Reading-triggered PMs excluded. Never-triggered assignments excluded. Output sorted by `next_due_date` ascending.

---

### 2. Assets by Location (R-2)
```
GET /api/reports/assets-by-location
```
**Response shape:** `{ summary, items }` (non-paginated)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `fa_subclass_code` | string? | — | Filter by FA subclass code (matches `assets.fa_subclass_code` column) |
| `asset_kind` | enum? | — | `asset`, `package`, `component` |
| `operational_status` | enum? | — | `active`, `under_maintenance`, `down`, `inactive` |
| `include_inactive` | bool (0/1) | 0 | Include soft-deactivated assets |

**Summary fields:** `total_assets`, `total_locations` (excludes Unassigned bucket), `total_booked`

**Item fields per location:** `location_id`, `location_name`, `is_unassigned`, `asset_count`, `by_operational_status: { active, under_maintenance, down, inactive }`, `by_asset_kind: { standalone, package, component }`, `booked_count`

Assets with `current_location_id=null` appear in an **Unassigned** bucket (`is_unassigned: true`, `location_name: "Unassigned"`). Output sorted by location ID with Unassigned always last.

---

### 3. PM Compliance (R-7)
```
GET /api/reports/pm-compliance
```
**Response shape:** `{ summary, items }` (non-paginated)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `from` | date? | 90 days ago | Window start |
| `to` | date? | today | Window end (must be ≥ `from`) |
| `group_by` | enum? | `rule` | `rule`, `asset`, `location` |
| `location_id` | int? | — | Filter by asset location |
| `pm_rule_id` | int? | — | Filter by PM rule |

**Summary:** `compliant`, `total`, `percentage` (float|null — null when total=0)

**Item fields:** `group_key` (ID), `group_label` (name), `compliant`, `total`, `percentage`

Compliant = linked WO is CLOSED AND `closed_at` date ≤ `trigger_date`. Reading-triggered PMs excluded from denominator. `to ≥ from` enforced (422 otherwise). Output sorted alphabetically by label (nulls last).

---

### 4. Overdue PM (R-8)
```
GET /api/reports/overdue-pm
```
**Response shape:** `{ summary, data, links, meta }` (cursor-paginated, 25/page)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `location_id` | int? | — | Filter by asset location |
| `pm_rule_id` | int? | — | Filter by PM rule |
| `priority` | enum? | — | `low`, `medium`, `high`, `critical` |
| `bucket` | enum? | — | `0-7`, `8-30`, `31-90`, `91+` |
| `per_page` | int (1–500) | 25 | Items per page |

**Overdue:** date-triggered PM MR with past `trigger_date`, not rejected/cancelled, WO absent or not CLOSED.

**Summary (`summary`):** `total` (scoped grand total), `by_bucket: { "0-7": N, "8-30": N, "31-90": N, "91+": N }`. The summary is **facet context**: always all 4 buckets over the full scoped set (location/priority/pm_rule filters applied), independent of the `bucket` row filter. The `bucket` filter narrows only the paginated rows.

**Pagination:** Cursor-based. Pass `?cursor=<urlencoded_cursor>` from `meta.next_cursor`. `links.next` preserves all current filters. Tie-breaker: `trigger_date` then `id`.

**Per-item:** Inherits `MaintenanceRequestResource` fields plus `days_overdue` (int) and `bucket` (string).

**⚠ Role-gating:** Logistics cannot see PM trigger fields. See §Field Visibility below.

---

### 5. Asset Status Distribution (R-10A)
```
GET /api/reports/asset-status-distribution
```
**Response shape:** `{ summary, items }` (non-paginated)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `asset_kind` | enum? | — | `asset`, `package`, `component` |
| `include_inactive` | bool (0/1) | 0 | Include soft-deactivated assets |

**Summary:** `total` (int)

**Items:** 4 fixed buckets (zeros filled):
```json
[
  { "status": "active", "count": N },
  { "status": "under_maintenance", "count": N },
  { "status": "down", "count": N },
  { "status": "inactive", "count": N }
]
```

---

### 6. Work Order Backlog (R-14)
```
GET /api/reports/wo-backlog
```
**Response shape:** `{ summary, data, links, meta }` (cursor-paginated, 25/page)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `location_id` | int? | — | Filter by asset location |
| `assigned_to` | int? | — | Filter by assignee user ID |
| `priority` | enum? | — | `low`, `medium`, `high`, `critical` |
| `status` | enum? | `both` | `open`, `in_progress`, `both` |
| `per_page` | int (1–500) | 25 | Items per page |

**Backlog:** WOs with status `open` or `in_progress` (closed/completed/cancelled excluded).

**Summary:** `total`, `by_bucket` (same 4 buckets), `by_priority: { "high": N, ... }`

**Pagination:** Cursor-based. Pass `?cursor=<urlencoded_cursor>` from `meta.next_cursor`. `links.next` preserves all current filters. Tie-breaker: `created_at` then `id`.

**Per-item:** Inherits `WorkOrderResource` fields plus `age_days` (int) and `bucket` (string).

**⚠ Role-gating:** Logistics cannot see assignee/timestamp/attachment fields. See §Field Visibility below.

---

## Pass 2 Endpoints

### 7. MTBF by Dimension (R-3)
```
GET /api/reports/mtbf
```
**Response shape:** `{ summary, items }` (non-paginated)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `from` | date? | 90 days ago | Window start |
| `to` | date? | today | Window end (must be ≥ `from`) |
| `group_by` | enum? | `asset` | `asset`, `category`, `location` |
| `location_id` | int? | — | Filter by asset location |
| `fa_subclass_code` | string? | — | Filter by FA subclass code |

**Summary:** `mtbf_days` (float|null), `failure_count` (int), `failure_rate_per_day` (float)

**Per-dimension items:** `group_key`, `group_label`, `failure_count`, `mtbf_days`, `failure_rate_per_day`. Sorted by `failure_count` descending.

**Definition:** Corrective MR where `is_failure=true` within window. MTBF = window_days / failure_count.

---

### 8. MTTR by Dimension (R-4)
```
GET /api/reports/mttr
```
**Response shape:** `{ summary, items }` (non-paginated)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `from` | date? | 90 days ago | Window start |
| `to` | date? | today | Window end (must be ≥ `from`) |
| `group_by` | enum? | `asset` | `asset`, `category`, `technician` |
| `location_id` | int? | — | Filter by asset location |
| `fa_subclass_code` | string? | — | Filter by FA subclass code |
| `technician_id` | int? | — | Filter by technician |

**Summary:** `mttr_hours` (float|null), `repair_count` (int)

**Per-dimension items:** `group_key`, `group_label`, `repair_count`, `mttr_hours`. Sorted by `repair_count` descending.

**Definition:** Corrective WOs (MR is_preventive=false) with status CLOSED, both `assigned_at` and `closed_at` set. MTTR = mean hours between assigned_at and closed_at.

---

### 9. Bad-Actor Analysis (R-6)
```
GET /api/reports/bad-actors
```
**Response shape:** `{ summary, items }` (non-paginated)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `from` | date? | 90 days ago | Window start |
| `to` | date? | today | Window end (must be ≥ `from`) |
| `group_by` | enum? | `asset` | `asset`, `category`, `location` |
| `location_id` | int? | — | Filter by asset location |
| `fa_subclass_code` | string? | — | Filter by FA subclass code |
| `limit` | int? (1–100) | — | Cap returned items |

**Summary:** `total_failures` (int)

**Per-dimension items:** `group_key`, `group_label`, `failure_count`. Sorted by `failure_count` descending.

**Note:** ATMS has no failure taxonomy — identifies bad-actor assets by failure count only.

---

### 10. PM Coverage (R-9)
```
GET /api/reports/pm-coverage
```
**Response shape:** `{ summary, data, links, meta }` (cursor-paginated, 25/page)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `location_id` | int? | — | Filter by asset location |
| `asset_kind` | enum? | — | `asset`, `package`, `component` |
| `per_page` | int (1–500) | 25 | Items per page |

**Summary:** `total_assets`, `covered_assets`, `uncovered_assets`, `coverage_pct` (float|null)

**Data:** Uncovered assets (active assets with no active PM assignment), paginated. Each item includes `name`, `erp_asset_code`, `current_location` relation.

---

### 11. Booking (R-13)
```
GET /api/reports/booking
```
**Response shape:** `{ summary, items }` (non-paginated)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `location_id` | int? | — | Filter by asset location |
| `asset_kind` | enum? | — | `asset`, `package`, `component` |

**Summary:** `total_assets`, `booked_count`, `available_count`

**Items (per location):** `location_id`, `location_name`, `total_count`, `booked_count`, `available_count`

---

### 12. Technician Workload (R-15)
```
GET /api/reports/technician-workload
```
**Response shape:** `{ summary, data, meta }` (cursor-paginated, 25/page)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `from` | date? | 90 days ago | Window start |
| `to` | date? | today | Window end (must be ≥ `from`) |
| `technician_id` | int? | — | Filter by assignee user ID |
| `per_page` | int (1–500) | 25 | Items per page |

**Summary:** `total_work_orders`, `total_assigned`, `total_open`, `total_in_progress`, `total_completed`, `total_cancelled`, `total_backlog`, `avg_duration_hours`, `avg_backlog_age_days`.

**Per-technician rows:** `technician_id`, `technician_name`, `total_count`, `open_count`, `in_progress_count`, `completed_count`, `cancelled_count`, `backlog_count`, `avg_duration_hours` (float|null), `avg_backlog_age_days` (float|null). Completed counts and duration include WOs in either `completed` or `closed` status; backlog is `open` + `in_progress`. Sorted by `total_count DESC`, then `technician_id ASC` for stable cursor traversal.

**Note:** Operational workload only — no productivity/labor metrics. `meta.next_cursor` / `meta.prev_cursor` are returned; this endpoint does not include a `links` object.

---

### 13. Throughput (R-16)
```
GET /api/reports/throughput
```
**Response shape:** `{ summary, data, meta }` (cursor-paginated, 25/page)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `from` | date? | 90 days ago | Window start |
| `to` | date? | today | Window end (must be ≥ `from`) |
| `status` | enum? | — | One MR or WO status; applies only to the source that supports it |
| `per_page` | int (1–500) | 25 | Daily rows per page |

**Summary:** `mr_created`, `mr_pending_review`, `mr_converted`, `mr_rejected`, `mr_cancelled`, `wo_created`, `wo_open`, `wo_in_progress`, `wo_completed`, `wo_closed`, `wo_cancelled`, `avg_conversion_hours` (float|null).

**Daily rows:** `date` plus the corresponding MR/WO lifecycle counts in the summary (except `avg_conversion_hours`), sorted by date descending. Each count is placed on the day of its actual lifecycle event: MR created/pending review uses `created_at`; converted/rejected uses `reviewed_at`; MR cancelled uses `cancelled_at`; WO created/open uses `created_at`; WO in progress uses `started_at`; WO completed/closed/cancelled use their matching timestamps.

**Note:** `avg_conversion_hours` is the mean interval from MR creation to the first linked WO creation for MRs converted in the selected window. `meta.next_cursor` / `meta.prev_cursor` are returned; this endpoint does not include a `links` object.

---

### 14. Parts Consumption (R-17)
```
GET /api/reports/parts-consumption
```
**Response shape:** `{ summary, data, links, meta }` (cursor-paginated, 25/page)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `from` | date? | 90 days ago | Window start |
| `to` | date? | today | Window end (must be ≥ `from`) |
| `part_id` | int? | — | Filter by part |
| `asset_id` | int? | — | Filter by asset |
| `fa_subclass_code` | string? | — | Filter by FA subclass code |
| `per_page` | int (1–500) | 25 | Items per page |

**Scope:** Parts consumed by completed/closed WOs only. Quantities aggregated by part + FA subclass code. Read-only ERP handoff — no inventory balance, cost, location, or automated posting.

**Summary:** `total_line_items`, `distinct_parts`, `distinct_work_orders`, `total_quantity` (float|null when unfiltered), `unit_of_measure` (string|null when unfiltered)

**Per-item:** `part_id`, `part_code`, `part_name`, `unit_of_measure`, `fa_subclass_code`, `total_quantity`, `line_item_count`, `work_order_count`

---

### 15. Asset Movement (R-18)
```
GET /api/reports/asset-movement
```
**Response shape:** `{ summary, data, links, meta }` (cursor-paginated, 25/page)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `from` | date? | 90 days ago | Window start |
| `to` | date? | today | Window end (must be ≥ `from`) |
| `asset_id` | int? | — | Filter by asset |
| `from_location_id` | int? | — | Filter by source location |
| `to_location_id` | int? | — | Filter by destination location |
| `per_page` | int (1–500) | 25 | Items per page |

**Summary:** `total_movements`, `unique_assets_moved`

**Per-item:** `id`, `asset_id`, `asset_name`, `asset_code`, `from_location_id`, `from_location_name`, `to_location_id`, `to_location_name`, `effective_at` (ISO-8601), `reason`, `notes`, `changed_by_user_id`, `changed_by_name`

**Deterministic ordering:** `(effective_at DESC, id DESC)`.

---

### 16. Form Results (R-19)
```
GET /api/reports/form-results
```
**Response shape:** `{ summary, data, links, meta }` (cursor-paginated, 25/page)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `from` | date? | 90 days ago | Window start |
| `to` | date? | today | Window end (must be ≥ `from`) |
| `asset_id` | int? | — | Filter by work-order asset |
| `fa_subclass_code` | string? | — | Filter by work-order asset FA subclass code |
| `field_uuid` | string? | — | Filter by form-field UUID |
| `per_page` | int (1–500) | 25 | Rows per page |

**Summary:** `total_fields`, `boolean_true_count`, `boolean_false_count`, `numeric_pre_post_count`, `numeric_comparisons`.

**Numeric comparisons:** `numeric_comparisons` is an array grouped by `field_uuid` + `label` + `unit`. Each entry includes `comparison_count`, `avg_pre_value`, `avg_post_value`, and `avg_change`. Only valid numeric pre/post pairs are counted.

**Per-row fields:** `id`, `field_uuid`, `label`, `field_type` (`boolean`/`numeric`/`text`), `has_pre_post`, `unit`, `pre_value`, `post_value`, `notes`, plus minimal `work_order: { id, number }` and `asset: { id, name, erp_asset_code, fa_subclass_code }` context. Rows are ordered by `field_uuid`, then `id`.

**Note:** The date filter is applied to the related work order's `created_at`. Field types are from the `FormFieldType` enum. Numeric comparisons are same-field+unit only; no pass/fail labels.

---

### 17. Meter Progression (R-20)
```
GET /api/reports/meter-progression
```
**Response shape:** `{ summary, data, links, meta }` (cursor-paginated, 25/page)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `from` | date? | 90 days ago | Window start |
| `to` | date? | today | Window end (must be ≥ `from`) |
| `asset_id` | int? | — | Filter by asset |
| `usage_reading_type_id` | int? | — | Filter by reading type |
| `per_page` | int (1–500) | 25 | Items per page |

**Summary:** `total_readings`, `confirmed_readings` (int)

**Per-item (via Resource):** `id`, `asset_id`, `asset_name`, `reading_type_name`, `reading_type_unit`, `reading_value` (float), `previous_reading_value` (float|null), `delta` (float|null — reading_value minus previous), `reading_at` (ISO-8601), `source`. Confirmed-only (confirmed_at IS NOT NULL).

**Deterministic ordering:** `(reading_at DESC, id DESC)`.

---

### 18. PM Suppression Register (R-21)
```
GET /api/reports/pm-suppression
```
**Response shape:** `{ summary, data, links, meta }` (cursor-paginated, 25/page)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `from` | date? | 90 days ago | Window start |
| `to` | date? | today | Window end (must be ≥ `from`) |
| `pm_rule_id` | int? | — | Filter by PM rule |
| `asset_id` | int? | — | Filter by asset |
| `decision_type` | string? | — | Filter by decision type (e.g. `approved`, `rejected`, `cancelled`) |
| `per_page` | int (1–500) | 25 | Items per page |

**Summary:** `total_suppressions` (int)

**Per-item (via Resource):** Suppression audit data including `id`, `pmRule`, `asset`, `maintenanceRequest`, `trigger_type`, `suppressed_until_date`, `suppressed_until_reading`, `decision_type`, `decidedBy`, `decided_at` (ISO-8601), `reason`, `triggered_by_date`, `triggered_by_reading`, `trigger_date`, `trigger_reading_value`.

**Deterministic ordering:** `(decided_at DESC, id DESC)`.

---

## Auth & Access

| Aspect | Detail |
|--------|--------|
| Middleware | `auth:sanctum` + `EnsureTokenAbilities` |
| Required ability | `dashboard:view` |
| Gate check | `Gate::authorize('viewDashboard', User::class)` |
| Roles | All 5: Administrator, Maintenance Manager, Technician, Logistics, Requester |
| Row scoping | None — org-wide data for all roles |

---

## Field Visibility (Role-Gated)

R-8 and R-14 inherit field visibility from `MaintenanceRequestResource` / `WorkOrderResource`. Fields may be **absent** from JSON, not null — check with `v-if="'fieldName' in item"`.

### R-8 (Overdue PM) — hidden from Logistics

| Hidden field | 
|--------------|
| `trigger_date` |
| `triggered_by_date` |
| `triggered_by_reading` |
| `trigger_reading_value` |
| `is_preventive` |
| `work_order` |
| `created_by` |
| `reviewed_by` |
| `has_attachments` |

### R-14 (WO Backlog) — hidden by role

| Field | Admin | Mgr | Tech | Logistics | Requester |
|-------|-------|-----|------|-----------|-----------|
| `assigned_to` | ✅ | ✅ | ✅ | ❌ | ✅ |
| `assigned_by` | ✅ | ✅ | ❌ | ❌ | ❌ |
| `started_at` | ✅ | ✅ | ✅ | ❌ | ✅ |
| `completed_at` | ✅ | ✅ | ✅ | ❌ | ✅ |
| `completion_notes` | ✅ | ✅ | ✅ | ❌ | ✅ |
| `closed_at` | ✅ | ✅ | ✅ | ❌ | ✅ |
| `cancelled_at` | ✅ | ✅ | ✅ | ❌ | ✅ |
| `cancellation_reason` | ✅ | ✅ | ✅ | ❌ | ✅ |
| `has_attachments` | ✅ | ✅ | ✅ | ❌ | ❌ |
| `created_at` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `maintenance_request` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `parts` | ✅ | ✅ | ✅ | ❌ | ✅ |
| `form` | ✅ | ✅ | ✅ | ❌ | ❌ |

---

## Enums Reference

**Priority:** `low`, `medium`, `high`, `critical`

**Aging buckets:** `0-7` (0–7d), `8-30` (8–30d), `31-90` (31–90d), `91+` (91+d). Ages always non-negative.

**Operational status:** `active`, `under_maintenance`, `down`, `inactive`

**Asset kind:** `asset`, `package`, `component`

**Work order status:** `open`, `in_progress`, `completed`, `closed`, `cancelled`

**Chain status (R-1):** `not_yet_generated`, `generated_mr_pending`, `wo_open`, `wo_completed`

---

## Pagination

- R-8, R-9, R-14 through R-21 are cursor-based (NOT offset). `meta.next_cursor` is string|null.
- Pass: `?cursor=<urlencoded_value>` (NOT a header or body param).
- Resource-backed endpoints expose `links.next`; R-15 and R-16 expose cursor metadata only.
- Current filters and `per_page` are retained when a next-page URL is returned.
- Each endpoint uses deterministic ordering appropriate to its row type.

---

## Backend Source Files

| File | Purpose |
|------|---------|
| `app/Http/Controllers/ReportController.php` | 18 endpoint methods |
| `app/Queries/Reports/AgingBuckets.php` | Shared bucket/days helper |
| `app/Queries/Reports/UpcomingPmReportQuery.php` | R-1 query |
| `app/Queries/Reports/AssetsByLocationReportQuery.php` | R-2 query |
| `app/Queries/Reports/PmComplianceReportQuery.php` | R-7 query |
| `app/Queries/Reports/OverduePmReportQuery.php` | R-8 query |
| `app/Queries/Reports/OperationalStatusDistributionReportQuery.php` | R-10A query |
| `app/Queries/Reports/WorkOrderBacklogReportQuery.php` | R-14 query |
| `app/Queries/Reports/MtbfReportQuery.php` | R-3 query |
| `app/Queries/Reports/MttrReportQuery.php` | R-4 query |
| `app/Queries/Reports/BadActorReportQuery.php` | R-6 query |
| `app/Queries/Reports/PmCoverageReportQuery.php` | R-9 query |
| `app/Queries/Reports/BookingReportQuery.php` | R-13 query |
| `app/Queries/Reports/TechnicianWorkloadReportQuery.php` | R-15 query |
| `app/Queries/Reports/ThroughputReportQuery.php` | R-16 query |
| `app/Queries/Reports/PartsConsumptionReportQuery.php` | R-17 query |
| `app/Queries/Reports/AssetMovementReportQuery.php` | R-18 query |
| `app/Queries/Reports/FormResultsReportQuery.php` | R-19 query |
| `app/Queries/Reports/MeterProgressionReportQuery.php` | R-20 query |
| `app/Queries/Reports/PmSuppressionReportQuery.php` | R-21 query |
| `app/Http/Resources/UpcomingPmItemResource.php` | R-1 item shape |
| `app/Http/Resources/OverduePmReportItemResource.php` | R-8 item shape (extends MR Resource) |
| `app/Http/Resources/WorkOrderBacklogItemResource.php` | R-14 item shape (extends WO Resource) |
| `app/Http/Resources/PartsConsumptionReportItemResource.php` | R-17 item shape |
| `app/Http/Resources/MeterProgressionReportItemResource.php` | R-20 item shape |
| `app/Http/Resources/PmSuppressionReportItemResource.php` | R-21 item shape |
| `app/Http/Resources/FormResultReportItemResource.php` | R-19 item shape |
| `database/migrations/2026_07_12_142507_add_report_indexes.php` | 4 report composite indexes (Pass 1) |
| `database/migrations/2026_07_12_180412_add_asset_location_histories_index.php` | R-18 cursor pagination index |
| `routes/api.php` | `reports/` prefix group (all 18 endpoints) |
| `tests/Feature/Reports/` | 19 report test files; full suite last verified: 651 tests, 1,858 assertions |

---

## Known Issues & Recommendations

### R-2: `category` filter replaced with `fa_subclass_code`

**Status:** ✅ Fixed

**Problem:** The `category` filter queried `assets.category`, which is **empty for all assets**. The ERP CSV import (`ImportErpAssetsCommand`) never populates `category` — it writes to `fa_subclass_code` instead. The frontend confirms this in `assetColumns.ts`:

> `fa_subclass_code` replaces `category` — the `category` column in the DB is empty for all assets; the real classification lives in `fa_subclass_code`.

The filter was accepted by the API but would always return zero results. The frontend explicitly omitted it from the R-2 UI for this reason (`useAssetsByLocationReport.ts`).

**Solution implemented:** Switched the R-2 filter from `category` to `fa_subclass_code`:

| Area | Change |
|------|--------|
| `ReportController::assetsByLocation()` | Replaced `'category' => ['nullable', 'string', 'max:100']` with `'fa_subclass_code' => ['nullable', 'string', 'max:255']` (matches Assets index validation) |
| `AssetsByLocationReportQuery::handle()` | Replaced `$filters['category']` with `$filters['fa_subclass_code']` and filter on `fa_subclass_code` |
| R-2 test | Replaced `test_category_filter_applies` with `test_fa_subclass_code_filter_applies` |

**Validation approach:** Used plain string validation (`['nullable', 'string', 'max:255']`) to match the Assets index endpoint, which also uses plain string validation for `fa_subclass_code`. The `fa_subclass_type_codes` lookup table exists but is not enforced via `Rule::exists()` — this allows filtering on codes that may not yet be in the lookup table.

**Note:** `AssetIndexQuery` has the same gap (filters on `category` instead of `fa_subclass_code`) but that's a separate fix outside report scope.

### Location-picker gap — all roles can now list locations

**Status:** ✅ Fixed

**Problem:** `LocationPolicy::viewAny()` only allowed Administrator, Maintenance Manager, and Logistics. Technician and Requester received 403 on `GET /locations`, preventing them from populating the location dropdown in report filters.

**Solution implemented:** Opened `/locations` read to all 5 report-access roles:

| File | Change |
|------|--------|
| `app/Policies/LocationPolicy.php` | Added `RoleCode::TECHNICIAN` and `RoleCode::REQUESTER` to `viewAny()` |
| `tests/Feature/Locations/ListActiveLocationsTest.php` | Moved Technician/Requester from unauthorized test to authorized test; removed `test_unauthorized_roles_are_forbidden()` |

Locations are organizational structure (names, types, codes) — not sensitive data. Every role that can view reports should be able to filter by location.
