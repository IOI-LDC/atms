# Reports (Phase 1 — Basic Reporting)

> **Purpose:** Specify the operational reporting requirement for ATMS. This sits
> in the PRD's in-scope "Dashboard and basic reporting" bucket and explicitly
> **not** in the out-of-scope "Advanced BI/reporting" bucket (see
> [`PRD.md`](./PRD.md) §In/Out-of-Scope). Reports are **read-only, parameterised
> aggregations** over existing ATMS data — no new write surfaces, no data
> warehouse, no forecasting engine.
>
> **Status: ✅ APPROVED.** This requirement has been reviewed and approved by
> the Product Owner and Maintenance Manager. It is now the binding Phase 1
> reporting scope, subject to the deferred/Phase 2 boundaries recorded below.
>
> **Discussion status:** D-1 ✅ Resolved (R-5 deferred), D-2 ✅ Resolved (R-15
> operational-only fence confirmed), D-3 ✅ Resolved (assembly/component model
> is Phase 2; R-12 deferred). Overall approval complete.
>
> Sources verified against models in `backend/app/Models/` and the status model
> in [`03-backend/STATUS_MODEL.md`](../../03-backend/STATUS_MODEL.md).

---

## 1. Scope & Boundaries

**In scope**

- Parameterised list and summary reports over ATMS data (assets, maintenance
  requests, work orders, PM rules, PM suppressions, work-order form results,
  meter readings, locations, and parts usage).
- Common filter bar (date range, location, asset category, asset kind,
  operational/maintenance status, PM rule, technician).
- Export to CSV (and PDF as a later enhancement).
- Role access follows the dashboard convention: any authenticated role may view
  (`03-backend/RBAC.md`).

**Out of scope (Advanced BI)**

- Predictive analytics / RUL estimation.
- Custom report builder / drag-and-drop designer.
- Multi-system data warehouse / OLAP cubes.
- Cost roll-ups in currency (parts costing is owned by SM and is out of scope).

**Cross-subsystem boundary**

ATMS may show read-only parts-use and location data in an ATMS maintenance
report where that data is necessary for context. SM remains the owner of parts
and ERP-sync operations; AM remains the owner of location history and movement
operations. A report must not introduce a write workflow or make ATMS the
authoritative source for either domain.

---

## 2. Cross-cutting Conventions

| Concern | Convention |
|---|---|
| Timezone | All windows shown in company timezone (`Africa/Tripoli` default). Stored UTC, formatted client-side — matches `DASHBOARD_KPI_HANDOFF.md`. |
| Date windows | Default 30-day forward (PM/forecast) or 90-day backward (history/reliability), user-adjustable. |
| Null vs zero | Reuse the dashboard rule: `—` when there is no basis to compute, `0` only for real zeros. |
| Drill-down | Every summary count links to the underlying asset/MR/WO list with the same filters applied. |
| Reading-triggered PM caveat | PMs triggered by usage readings (hours/km) have **no fixed calendar due date**. Forward projections of reading-triggered PMs are **estimates** based on recent usage rate and must be labelled as such (or excluded by default). |
| Inspection-result caveat | Boolean form fields can be counted as true/false. Numeric pre/post values can show a change only when the same field and unit have valid numeric values. There are no acceptance limits or tolerances today, so numeric pass/fail reporting is out of scope. |

---

## 2.1 Discussion Decisions (pre-approval)

These points were raised by the reviewer and are recorded here because they
condition report approval or delivery scope. All three decisions are now
resolved.

### D-1 — R-5 needs a dependable downtime source  ✅ Resolved (Deferred)

**Concern (reviewer):** Asset Availability/Downtime needs a dependable downtime
start/end data source; it may not be safely derivable from status and WO dates
alone.

**Verified:** Correct. There is **no** asset-status history table.
`assets.operational_status` is a current-state enum only; the sole append-only
trail is `audit_logs` (`before_state`/`after_state` snapshots) — a forensic
audit table, not an analytical downtime ledger. WO `started_at`→`closed_at`
covers only maintenance windows and excludes non-WO downtime (awaiting
parts/approval, idle-down with no WO).

**Decision (2026-07-12): ✅ Resolved — Defer R-5.** R-5 is deferred in full. A
WO-only version will **not** be shipped as "availability/downtime" — it would
be materially misleading. The valid WO-duration views are already covered by
R-4 (MTTR) and R-14 (WO Backlog). R-5 is removed from all build tiers until a
dependable downtime source (e.g. an `asset_status_history` ledger) is approved
and built.

### D-2 — R-15 must stay operational workload only  ✅ Resolved (Confirmed)

**Concern (reviewer):** Workload by Technician must stay operational workload
only. It must **not** become technician productivity/labor reporting, which the
locked scope excludes.

**Verified:** Correct. "Labor tracking" is PRD out-of-scope (line 110).

**Constraint to lock in spec:** R-15 metrics are limited to:
- WO counts (assigned, open, in-progress, completed, cancelled) per technician,
- backlog and aging per technician,
- avg WO duration per technician.

**Explicitly excluded:** hours worked, labor cost, utilization %, efficiency /
productivity scores, time-on-tools, or any ranking that functions as a
performance appraisal. No change to scope boundary required; this is a spec
fence on R-15 only.

**Decision (2026-07-12): ✅ Resolved — Confirmed.** The operational-only fence
is locked exactly as written.

### D-3 — R-12 depends on assembly/component delivery scope  ✅ Resolved

**Concern (reviewer):** Spare/Rotor Pool depends on the asset-assembly /
component model being in the agreed delivery scope.

**Verified:** Partially delivered. The component *columns/enums* exist in code
(`assets.asset_kind`, `assets.parent_asset_id`, and `MaintenanceSubStatus` has
`installed`/`ready`). However the dedicated `asset_assembly_history` table —
marked "Q3 DECIDED" in [`ASSET_ASSEMBLY.md`](./ASSET_ASSEMBLY.md) — has **no
migration** and is not built.

**Decision (2026-07-12): ✅ Resolved — Phase 2/deferred.** Assembly/component
is not part of the Phase 1 delivery scope. R-12 is deferred to Phase 2 and is
not an approvable Phase 1 report.

---

## 3. Requested Reports

### R-1. Upcoming PM Schedule (forward-looking)

**Question:** *Which assets have a PM due in the next 30 days?*

**Primary view:** summary count by due-week and by trigger type, drillable to a
row-per-PM list.

| Column | Source |
|---|---|
| Asset (name / tag / ERP code) | `assets` |
| Current location | `assets.current_location_id` → `locations` |
| PM rule | `pm_rules` via `asset_pm_assignments` |
| Trigger type | `pm_rules.trigger_type` (date vs reading) — `PmTriggerType` enum |
| Due date / projected due | date-triggered: next due; reading-triggered: **projected** (estimate) |
| Status | not-yet-generated / generated MR pending / WO open |
| Days until due | derived |

**Filters:** date horizon (default 30 days), location, asset category, PM rule,
trigger type, exclude/include reading-triggered estimates.

**Source tables:** `asset_pm_assignments`, `pm_rules`, `maintenance_requests`
(`trigger_date`, `triggered_by_date`), `asset_meter_readings` (for projection).

### R-2. Asset Distribution by Location

**Question:** *Where are our assets, and how many are at each location?*

**Primary view:** row-per-location summary, drillable to the asset list at that
location.

| Column | Source |
|---|---|
| Location name | `locations` |
| Asset count | `COUNT(assets)` grouped by `current_location_id` |
| By operational status | breakdown: Active / Under Maintenance / Down / Inactive |
| By maintenance status | Active vs Inactive (with LIH / DBR / Disposed / Scrapped counts) |
| By asset kind | standalone / component / package |
| Booked count | `assets.is_booked = true` |

**Filters:** asset category, asset kind, operational status, maintenance status,
include/exclude inactive.

**Source tables:** `assets`, `locations`.

---

## 4. Suggested Additional Reports

Grouped by theme. Priority reflects typical Oil & Gas (drilling) maintenance
value: **drilling downtime is extremely costly**, so reliability/availability and
PM-compliance reports rank highest.

### 4.1 Reliability & Availability  *(highest O&G value)*

| ID | Report | Question | Key source |
|---|---|---|---|
| R-3 | **MTBF / Failure Rate by dimension** | Where do failures concentrate (by asset / category / location)? | `maintenance_requests` where `is_failure = true` |
| R-4 | **MTTR by dimension** | What is our repair turnaround by asset / category / technician? | corrective `work_orders` (`assigned_at`→`closed_at`) |
| R-5 | **Asset Availability / Downtime** ⛔ Deferred (D-1) | Uptime % and total downtime hours per asset in period. | `assets.operational_status` — **deferred: no dependable source; valid WO-duration views covered by R-4 + R-14** |
| R-6 | **Bad-Actor / Breakdown Analysis** | Which assets, categories, or locations have the most confirmed failures? | corrective MRs where `is_failure = true`, grouped and sorted by count |

> R-3/R-4 are drill-down (by dimension) generalisations of the existing scalar
> dashboard KPIs (`DASHBOARD_KPI_HANDOFF.md` §3) — reuse the same definitions.
>
> **Failure-mode limitation:** ATMS records only the `is_failure` boolean; it
> has no failure-mode, mechanism, or cause taxonomy. R-6 can identify bad-actor
> assets, but it cannot claim a Pareto of failure modes or root causes. That
> needs a separately approved failure-classification requirement; it is not
> introduced by this reporting scope.

### 4.2 PM Management

| ID | Report | Question | Key source |
|---|---|---|---|
| R-7 | **PM Compliance report** | On-time completion % by rule / asset / location / period. Reading-triggered PMs are excluded because they have no calendar due date. | date-triggered MRs vs WO `closed_at` ≤ `trigger_date` |
| R-8 | **Overdue PM report** | Which PMs are past due and not closed, by aging bucket? | MR `trigger_date` < now, terminal-status filter |
| R-9 | **PM Coverage / Gaps** | Which active assets have **no** active PM assignment? | `assets` (active) anti-joined to `asset_pm_assignments` |

> R-9 directly answers "are we maintaining everything we should be?" — a common
> audit finding in O&G maintenance programs.

### 4.3 Asset Status & Fleet

| ID | Report | Question | Key source |
|---|---|---|---|
| R-10A | **Operational Status Distribution** | How is the fleet split across operational states? | `operational_status` |
| R-10B | **Maintenance Lifecycle Status Distribution** ⛔ Phase 2/deferred | How is the fleet split across ERP-derived maintenance lifecycle states? | ERP-derived `maintenance_status` / `maintenance_sub_status` |
| R-11 | **Lost / Decommissioned Assets** ⛔ Phase 2/deferred | Counts of LIH, DBR, Disposed, Scrapped in period. | ERP-derived lifecycle state |
| R-12 | **Spare / Rotor Pool** ⛔ Phase 2/deferred | Components Ready (spare) vs Installed — spare availability by category. | Phase 2 assembly/component model |
| R-13 | **Asset Booking / Availability** | Booked vs freely-available assets, by location. | `assets.is_booked` |

> R-11/R-12 are **drilling-specific**: "Lost in Hole" events and spare-rotor
> availability are operationally and financially material.

### 4.4 Workload & Backlog

| ID | Report | Question | Key source |
|---|---|---|---|
| R-14 | **WO Backlog / Aging** | Open + in-progress WOs by age bucket and priority. | `work_orders.status`, age = now − `created_at` |
| R-15 | **Workload by Technician** ✅ (D-2) | Assigned vs completed WOs, avg duration, per technician — **operational workload only; no productivity/labor metrics (fence locked, see D-2)** | `work_orders.assigned_to_user_id`, statuses, durations |
| R-16 | **MR / WO Throughput** | Counts by status over period + avg conversion time. | `maintenance_requests`, `work_orders` lifecycle timestamps |

### 4.5 Parts & Movement

| ID | Report | Question | Key source |
|---|---|---|---|
| R-17 | **Parts Consumption** | Quantities used by asset / category / location / period (top consumers). | `work_order_parts` |
| R-18 | **Asset Movement Log** | Relocations in period, by from → to route and category. | `asset_location_histories` (AM-owned) |

> R-17 is **quantity only** — parts costing is owned by SM and out of scope.

### 4.6 Inspection, Readings & PM Audit

| ID | Report | Question | Key source |
|---|---|---|---|
| R-19 | **Work Order Form Results** | What pre/post inspection results were recorded by asset, FA subclass, field, and period? | `work_order_form_fields` joined to work order and asset |
| R-20 | **Meter Reading Progression** | How have confirmed readings changed over time by asset and reading type? | confirmed `asset_meter_readings` |
| R-21 | **PM Suppression Register** | Which PM occurrences were suppressed or overridden, by whom, when, and why? | `pm_occurrence_suppressions` with PM rule, asset, MR, user, and reading-type context |

**R-19 rules:** Report field values and pre/post deviation only. Boolean fields
may be summarised as true/false. Numeric fields may be compared only within the
same field and unit after numeric validation. Do not label numeric values as
pass/fail unless an approved acceptance-limit model is added.

**R-20 rules:** Show confirmed readings, timestamps, and deltas. A projected
date for a reading-triggered PM remains an estimate under the §2 convention; no
remaining-useful-life or predictive-maintenance claim is in scope.

**R-21 rules:** Include the decision type, trigger basis, suppression-until
date/reading, decision maker, decision time, and reason. This is an
audit/compliance report, not a workflow for changing a decision.

### 4.7 Opportunities Not Included in ATMS Phase 1

- **ERP Sync Health:** `erp_sync_jobs` and `erp_sync_errors` support an
  operational health view, but ERP parts sync is SM-owned. Specify it in SM
  administration/monitoring requirements rather than adding it to ATMS reports.
- **Location Dwell / Churn:** `asset_location_histories` can support dwell-time
  analysis, but location history and movement are AM-owned and AM is not yet
  built. Reconsider it with the AM reporting scope; R-18 remains the limited
  read-only movement context available to ATMS.
- **Hierarchy roll-up:** Current `parent_asset_id` supports only a current-state
  roll-up. Lifecycle-aware component analysis is Phase 2 and remains part of
  deferred R-12 rather than a separate Phase 1 report.

---

## 5. Suggested Priority for Phasing

| Tier | Reports | Rationale |
|---|---|---|
| **Must (with R-1, R-2)** | R-7 PM Compliance, R-8 Overdue PM, R-10A Operational Status Distribution, R-14 WO Backlog | Directly answer day-to-day "what needs attention" + reuse existing dashboard logic. |
| **Should** | R-3/R-4 MTBF/MTTR by dimension, R-6 Bad-Actor Analysis *(no failure-mode Pareto)*, R-9 PM Coverage, R-15 Technician workload *(operational only, D-2)*, R-21 PM Suppression Register | Core reliability, program-coverage, and audit visibility using existing ATMS data. |
| **Could** | R-13 Booking, R-16 Throughput, R-17 Parts, R-18 Movement, R-19 Form Results, R-20 Meter Progression | Valuable, lower-urgency operational context; R-19/R-20 retain their data-quality caveats. |
| **Deferred / Phase 2** | R-5 Availability/Downtime *(D-1)*, R-10B Maintenance Lifecycle Status, R-11 Lost/Decommissioned, R-12 Spare Pool *(D-3)* | Deferred because they require a dependable downtime source, ERP-derived lifecycle state, or Phase 2 assembly scope. |

---

## 6. Implementation Notes (non-binding)

- Reuse the dashboard query/controller patterns; reports are read-only GET
  endpoints, one per report group, returning both `summary` and `items`.
- Reuse `OperationalStatus`, `MaintenanceStatus`/`MaintenanceSubStatus`,
  `AssetKind`, `WorkOrderStatus`, `MaintenanceRequestStatus`, `PmTriggerType`
  enums for filtering/grouping — do not hardcode status strings.
- Forward-looking reading-triggered PM projection (R-1) requires a usage-rate
  assumption; keep it labelled as an estimate or hide behind a flag.
- Do not build an ATMS report for ERP-sync health or AM location dwell time:
  those requirements belong to SM and AM respectively (§4.7).

---

## 7. Approval & Change Log

### Approval

This document is **APPROVED**. Implementation may proceed within the Phase 1
scope and boundaries recorded in this document.

| Role | Name | Decision | Date | Notes |
|---|---|---|---|---|
| Product Owner | Product Owner | ✅ Approved | 2026-07-12 | Approval given in project discussion. |
| Maintenance Manager | Maintenance Manager | ✅ Approved | 2026-07-12 | Approval given in project discussion. |

> Decisions: `✅ Approved` · `🟡 Under Discussion` · `🔴 Rejected` · `⏳ Pending`
> The status banner above reflects the recorded approvals.

### Change Log

| Date | Author | Change |
|---|---|---|
| 2026-07-12 | Kilo (initial draft) | Created draft catalog: R-1 Upcoming PM, R-2 Assets by Location, plus R-3…R-18 suggested reports. Status set to UNDER DISCUSSION — APPROVAL REQUIRED. |
| 2026-07-12 | Reviewer (discussion) | Raised D-1 (R-5 downtime source), D-2 (R-15 operational-only fence), D-3 (R-12 assembly-scope dependency). R-5/R-12 marked conditional/blocked, R-15 fenced to operational workload. Approval still pending resolution of D-1/D-2/D-3. |
| 2026-07-12 | Reviewer (decisions) | D-1 ✅ Resolved — R-5 deferred in full (no WO-only variant; R-4 + R-14 cover valid WO-duration views). D-2 ✅ Resolved — R-15 operational-only fence confirmed exactly as written. D-3 ✅ Resolved — assembly/component is Phase 2; R-12 deferred. |
| 2026-07-12 | Codex (research reconciliation) | Added R-19 Work Order Form Results, R-20 Meter Reading Progression, and R-21 PM Suppression Register. Limited R-6 to bad-actor analysis because no failure taxonomy exists. Recorded form-result constraints and excluded ERP Sync Health (SM-owned) and Location Dwell/Churn (AM-owned) from ATMS Phase 1. |
| 2026-07-12 | Codex (review corrections) | Corrected R-3 to `is_failure = true` to match the dashboard KPI. Clarified R-7 excludes reading-triggered PMs from calendar-based compliance and enumerated R-10 maintenance sub-statuses. |
| 2026-07-12 | Reviewer (report decisions) | Approved R-1 through R-4, R-6 through R-10A, and R-13 through R-21 for Phase 1 subject to formal approval. Deferred R-5, R-10B, R-11, and R-12 to Phase 2. R-15 operational-only fence remains confirmed. |
| 2026-07-12 | Product Owner / Maintenance Manager | ✅ Approved | Formal approval recorded; Phase 1 implementation is cleared to proceed within the documented scope and deferred boundaries. |
