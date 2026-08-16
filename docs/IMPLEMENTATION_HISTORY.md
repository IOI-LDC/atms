# Implementation History

<!--
MAINTENANCE:
- Append an entry only after the corresponding requirement is implemented and its
  verification evidence is fresh.
- Use the R-### identifier removed from REQUIREMENTS.md and record the durable
  outcome plus the focused verification performed.
- Do not copy plans, handoffs, meeting notes, or detailed file inventories here.
-->

This is a concise, append-only record of requirements removed from
[REQUIREMENTS.md](REQUIREMENTS.md) after implementation. It is not a handoff,
release plan, or detailed change log.

## Entry format

```md
## YYYY-MM-DD — Short outcome

One or two sentences stating what is now true.

- Requirement: R-###
- Changed: durable area(s), not every file
- Verified: focused test/build/operational check
```

## Before 2026-07-13

This lifecycle starts with the documentation consolidation. Earlier delivered work
is represented by the current operating summaries and preserved historical archive,
not reconstructed as retrospective handoff entries.

## 2026-07-25 — Workflow notifications are safe to enable in production

Email configuration no longer carries a personal address, deep links are built from
an explicit SPA base URL rather than the API host, and no notification can be
delivered for a transition that rolled back. Two of the four reported items were
resolved by decision rather than by code: the as-built Cc routing was accepted in
place of the 2026-07-04 routing, and scheduled preventive MR generation stays silent
because one run can create many requests at once.

- Requirement: R-007
- Changed: `ACCOUNT_EMAIL_BCC` has no default; new `atms.frontend_url` config with
  `App\Support\FrontendUrl` used by all ten user-facing link sites, including
  activation and password reset; every account-email notification implements
  `ShouldQueueAfterCommit`; the mailbox overlap key moved into `OverlapKeys`.
- Verified: full suite 679 passed (1949 assertions); Pint clean. Transaction safety
  is proven by a dedicated test that uses `DatabaseMigrations` and an inline queue,
  because `RefreshDatabase` holds a transaction that never commits and would make the
  assertion pass vacuously.

## 2026-07-25 — Test suite runs on its intended environment and queue driver

Tests now run as `APP_ENV=testing` on the `sync` queue, so queued jobs and
notifications execute during a test run instead of being written to the `jobs` table
and discarded. Two mechanisms were needed: `force="true"` on each `<env>`, because
PHPUnit does not override an existing environment variable, and a matching `<server>`
entry for each, because Laravel's `Env` repository reads `$_SERVER` before
`getenv()` — forcing `<env>` alone changed `getenv()` while `env()` kept returning the
container's value.

- Requirement: R-008
- Changed: `phpunit.xml` only; no application code.
- Verified: full suite 679 passed (1949 assertions), unchanged from before the switch,
  confirming no test depended on queued work never running.

## 2026-07-30 — Reports use explicit dimensions and the current dashboard gains Asset Identity

MTBF, MTTR, and Bad-Actor reports now group by explicit Maintenance Category,
Asset Class, and Size — rejecting the legacy `group_by=category` value — through a
shared dimension resolver, and Parts Consumption reports full Part Identity with
Asset Class and Asset Size across deterministic cursor pages. The current
`/dashboard` mosaic design gains shared Asset Identity rendering in all five
queues; `/dashboard-real` and its `DashboardView.vue` remain untouched as a
reference-only route.

- Requirement: R-007
- Changed: `AssetReportDimension` resolver behind the three reliability reports and
  their controller validation; Parts Consumption grouping, resource, and frontend
  contract; Asset Identity in the Pending MR, Open WO, Overdue PM, Recently Closed
  WO, and Relocation queues of the current `/dashboard` view. Dashboard KPI scope,
  `/dashboard-real`, and `DashboardView.vue` are unchanged.
- Verified: focused report and dashboard feature tests pass; frontend type-check and
  production build pass.

## 2026-08-01 — Maintenance Category becomes the ATMS routing key

ATMS routes behaviour only on the classification it owns. `assets
.maintenance_category_id` is now NOT NULL, defaulting to a seeded `UNCLASSIFIED`
category so ERP-created assets remain governable and visible rather than null. WO
form templates are selected by that category through a pivot that enforces at most
one active template per category; PM rules may cover categories, which expand into
ordinary per-asset assignments. `fa_subclass_code` remains an ERP-owned descriptive
field — it still drives asset tags and report filters, and no longer routes
anything. PM evaluation cost is now flat in the number of assignments.

- Requirement: tracker items D-011, D-012, D-013 (not R-### requirements)
- Changed: `assets.maintenance_category_id` constraint and default;
  `form_template_maintenance_category` and `pm_rule_maintenance_category` pivots;
  WO form snapshot/sync resolution; `ReconcilePmCategoryAssignments` plus its
  queued job and the hooks that trigger it; PM evaluation batching and fan-out;
  removal of the four `/admin/fa-subclass-type-codes` CRUD routes; admin UI for
  both pickers.
- Verified: 1020 backend tests (3011 assertions) pass; Pint clean on touched paths;
  frontend `vue-tsc --build` clean; category expansion exercised against the live
  development register (515 assignments across two rules).
- Follow-up: the two pre-existing WO form templates migrated deactivated with no
  categories and required manual reassignment; PM rule "L3 Maintenance Motors" lost
  its coverage to a defect fixed the same day and needs re-assigning.

## 2026-08-04 — Reading-based PM triggers parked; PM is date-only

New PM rules can only be created with a `date` trigger. The `reading` and
`date_or_reading` options were removed from the rule form because nothing in the
system could satisfy them: readings could only be recorded on the Work Order page,
and no UI ever confirmed one, so the reading dimension of a rule could never fire.
Nothing was deleted — the API, `PmDueCalculator`, and existing rules' reading
configuration are untouched, and existing `date_or_reading` rules keep running on
their date dimension.

- Requirement: tracker item D-014
- Changed: `TRIGGER_OPTIONS` in `PmRuleForm.vue` (frontend only); the edit path
  renders the trigger read-only so existing rules still open correctly
- Verified: frontend type-check clean; existing `date_or_reading` rules confirmed
  to open and save unchanged
- Follow-up: re-enable once the LDC Job Management system feeds per-job asset
  usage into ATMS — and after D-018 (below) is fixed

## 2026-08-04 — Closing a work order confirms its meter readings

Meter-reading confirmation is a by-product of the work-order lifecycle rather than
a task of its own. Closing confirms every unverified reading taken on that work
order, oldest-first; a reading that still fails a monotonicity guard is skipped and
audited as `meter_reading.confirm_skipped` rather than blocking the close. There is
no manual verify action in the UI and none is intended: closing is an
Administrator/Maintenance Manager action that the technician who took the reading
cannot perform, so the close is the second pair of eyes.

- Requirement: not an R-### requirement — arose from investigating why reading-based
  PM never fired
- Changed: new `ConfirmWorkOrderReadings` action, called by `CloseWorkOrder` before
  its PM branch; new `meter_reading.confirm_skipped` audit event
- Verified: 1059 backend tests pass; Pint clean; exercised against the live
  development database inside a rolled-back transaction — out-of-order readings all
  confirmed, an impossible one skipped, the close still succeeded

## 2026-08-05 — Repair/Service vocabulary, stored delta, meter snapshots, service-on-repair

Four changes shipped together. (1) The UI distinguishes **Repair** from **Service**
on the request Type value rather than on list titles, since the request list holds
both kinds. (2) `asset_meter_readings.entered_delta` stores the figure the operator
typed, so a wrong total can be traced to a mistyped delta rather than a bad base,
and a technician who knows only the delta can correct their own entry. (3)
`work_order_meter_snapshots` records the asset's meter position per reading type at
close, which is what makes "usage since the last repair" answerable — "since the
last service" was already derivable and is now surfaced. (4) A repair work order can
declare that a preventive service was performed alongside it, resetting that
schedule's baselines and retiring the PM request already raised for it with
suppression `performed_under_repair`.

- Requirement: not R-### requirements — direct product requests
- Changed: two migrations (`work_order_meter_snapshots`, `entered_delta`);
  `CloseWorkOrder` gained a declared-service branch and a snapshot step;
  `CancelMaintenanceRequest` gained an optional decision type; `mrTypeLabel` in the
  frontend now renders Repair/Service
- Verified: 1073 backend tests (3302 assertions) pass; Pint clean across 20 files;
  frontend type-check clean; exercised end-to-end against the live development
  schema in a rolled-back transaction — readings confirmed, delta preserved,
  snapshots written for two reading types, PM baseline reset, pending PM request
  cancelled citing the work order
- Follow-up: D-018 — with no prior reading for a type, the entered delta is stored
  as the absolute total, seeding a wrong odometer. Silent, and must be fixed before
  reading-based PM is unparked
