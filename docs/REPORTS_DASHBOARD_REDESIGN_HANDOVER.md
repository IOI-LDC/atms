# Codex Handover — Dashboard and Reports Total Redesign

**Owner:** Rawand Hawez  
**Date:** 2026-07-30  
**Status:** Product discovery and design only  
**Implementation authority:** Not granted

## Instruction to Codex

Read this document completely before taking action.

The owner is not satisfied with the current Dashboard and Reports experience and
wants both redesigned as a coherent product experience. Treat the current
frontend as a source of technical context, not as the approved design.

Your first assignment is to discover, propose, and document the new design.
Do not edit application code, create migrations, change APIs, or begin
implementation until you have presented the proposed design to the owner and
received explicit approval.

Do not ask questions that can be answered from the repository, database, tests,
or this handover. Investigate first. Ask the owner only for decisions that would
materially change the product.

## Mission

Design a new:

1. operational Dashboard at `/dashboard`; and
2. Reports experience at `/reports`, including the report catalogue and
   individual report interaction patterns.

The two areas should feel like one system. They should share terminology,
filters, identity presentation, drill-down behaviour, responsive rules, and
empty/error states.

This is a total product and UX redesign. Do not assume that the existing cards,
layout, charts, report catalogue presentation, information hierarchy, or
navigation must survive.

## Scope boundary

This handover authorizes discovery and design only.

The design phase must produce:

- the user and operational questions each page answers;
- role-specific needs and visibility;
- Dashboard information architecture;
- Reports information architecture and catalogue organisation;
- proposed KPIs, tables, charts, queues, and filters;
- drill-down and cross-navigation behaviour;
- export and printing behaviour;
- desktop and narrow-screen wireframes;
- loading, empty, partial-data, and error states;
- an API/data-capability map;
- clear acceptance criteria; and
- a list of genuinely unresolved decisions for the owner.

After the owner approves the design, prepare a separate implementation plan.
Do not treat approval of this handover as approval to implement.

## Protect the current workspace

The working tree contains uncommitted, in-progress Reports and Dashboard changes
from an interrupted implementation attempt. They include report-dimension work,
Parts Consumption changes, Dashboard identity rendering, tests, documentation,
and a new report-dimension resolver.

Before doing anything:

1. Run `git status --short`.
2. Review the complete diff.
3. Identify which changes belong to the interrupted attempt.
4. Do not discard, overwrite, stage, commit, merge, push, or deploy them.
5. Treat the two existing files under
   `docs/plans/2026-07-30-reports-dashboard*.md` as superseded. Do not execute
   those plans.

The redesign must be discussed independently of that partial implementation.
Some backend fixes may remain useful later, but they must not dictate the new
user experience.

## Read and inspect first

Start with the active manual:

1. `docs/README.md`
2. `docs/PRODUCT.md`
3. `docs/ENGINEERING.md`
4. `docs/API.md`
5. `docs/FRONTEND.md`
6. `docs/REQUIREMENTS.md`
7. `docs/IMPLEMENTATION_HISTORY.md`
8. `docs/DASHBOARD_REPORTS_PLACEHOLDER.md`

Then inspect:

- `frontend/src/views/DashboardPlaceholderView.vue`
- `frontend/src/views/reports/ReportsPlaceholderView.vue`
- `frontend/src/composables/useDashboard.ts`
- `frontend/src/composables/useDashboardKpis.ts`
- `frontend/src/composables/useReportCatalog.ts`
- `frontend/src/lib/reportOptions.ts`
- `frontend/src/router/index.ts`
- `backend/app/Http/Controllers/DashboardController.php`
- `backend/app/Http/Controllers/DashboardKpiController.php`
- `backend/app/Http/Controllers/ReportController.php`
- `backend/app/Queries/Dashboard/`
- `backend/app/Queries/Reports/`
- the related Dashboard and Reports feature tests.

Historical material may be used to understand available calculations and prior
thinking, but it is not automatically the new design requirement. In
particular:

- `docs/_archive/2026-07-13/legacy/atms/01-product/REPORTS.md`
- `docs/_archive/2026-07-13/legacy/atms/02-design/SCREEN_INVENTORY.md`
- `docs/_archive/2026-07-13/legacy/atms/04-technical/DASHBOARD_KPI_HANDOFF.md`

The active documentation is inconsistent with later code. Record the
inconsistencies during discovery; do not silently choose one source.

## Confirmed product and domain rules

These decisions survive the redesign.

### Asset Identity

Whenever an asset is presented, treat its identity as one reusable package:

- Name is normal text.
- Serial Number is a badge.
- Size is a badge.
- Maintenance Category is a badge.
- Do not concatenate Name, Serial Number, Size, or Maintenance Category into one
  string.
- A badge contains only its value; do not prefix it with `SN:`, `Size:`, or
  `Category:`.
- Omit a badge when its value is unavailable.
- Keep MR and WO numbers separate from Asset Identity.
- Asset Tag remains a distinct visible/searchable identifier where the context
  requires it.

Use the shared `AssetIdentity` component and the backend
`AssetIdentityResource` when implementation is eventually authorized.

### Part Identity

Whenever a part is presented:

- Name is normal text.
- Supplier Part Number is a badge when available.
- Size is a badge when available.
- Maintenance Category is a badge when available.
- Do not concatenate those values into the part name.
- Omit unavailable badges.

Use the shared `PartIdentity` component and corresponding resource shape when
implementation is eventually authorized.

### ERP identifiers

- `erp_asset_code` and `erp_part_code` remain stored for integration and data
  maintenance.
- Maintenance users are not familiar with them.
- Do not display them or include them in ordinary frontend search.
- Supplier Part Number is not the ERP part code.

### Maintenance Category

- Maintenance Category is a controlled local ATMS vocabulary.
- It is used by both assets and parts.
- It is not currently ERP-owned.
- Values are maintained through controlled data imports, not casual free-text
  editing.
- Do not use the ambiguous word `Category` where the system means Maintenance
  Category.
- `fa_subclass_code` is a separate ERP-owned Asset Class and must not be
  relabelled as Maintenance Category.

### Size

- Store Size as an exact canonical numeric value, not a float.
- Display Size in O&G mixed-fraction notation, such as `6 3/4"` and `1 1/2"`.
- Equivalent inputs must resolve to the same canonical value.
- Asset Size and Part Size must be named separately wherever both appear.

### Reporting boundaries

- Reports are read-only operational views.
- Predictive analytics, remaining-useful-life claims, custom BI builders, data
  warehouses, financial/cost reporting, and labor-productivity scoring are
  outside current ATMS scope.
- Technician reporting may show operational workload, backlog, status, aging,
  and duration. It must not become performance appraisal, utilization, labor
  costing, or productivity ranking.
- Parts Consumption is quantity-based maintenance reporting. Store Management
  remains the future owner of stock issuance and costing.
- Null and zero are different: use an em dash when there is no basis to
  calculate; show zero only when zero is a real result.
- Preserve existing calculation definitions unless the owner explicitly
  approves a change.

## Existing backend capabilities

These are capabilities available to the redesign, not mandatory UI components.

### Dashboard data

The backend currently provides:

- `GET /api/dashboard` for role-adaptive operational counts and queue previews;
- `GET /api/dashboard/kpis` for aggregate KPIs and recent relocations.

Available data includes:

- pending Maintenance Requests;
- open Work Orders;
- overdue PM assignments;
- recently closed Work Orders;
- recently relocated assets;
- MTBF;
- MTTR;
- failure count and failure rate;
- PM compliance;
- average MR duration;
- average WO duration;
- current asset health/availability breakdowns; and
- workforce/backlog aggregates.

The redesign may use, combine, deprioritize, or omit these capabilities. It may
propose additional data only when it answers an approved operational question.

### Reports data

The backend currently exposes 18 authenticated, read-only report endpoints:

1. Upcoming PM
2. Assets by Location
3. PM Compliance
4. Overdue PM
5. Asset Status Distribution
6. Work Order Backlog
7. MTBF
8. MTTR
9. Bad Actors
10. PM Coverage
11. Asset Booking
12. Technician Workload
13. MR/WO Throughput
14. Parts Consumption
15. Asset Movement
16. Work Order Form Results
17. Meter Progression
18. PM Suppression

Do not assume that all 18 require identical presentation or equal prominence.
The redesign must decide how users discover, filter, understand, and move
between them.

Deferred ideas such as dependable downtime/availability history,
ERP-lifecycle reporting, Lost/Decommissioned analysis, and Spare/Rotor Pool
must not be presented as available without their missing source data or future
phase dependencies.

## Partially implemented report decisions

An interrupted implementation attempt began applying these approved data
contract improvements:

- replace generic report `category` with explicit `maintenance_category` and
  `asset_class`;
- allow MTBF and Bad Actors to group by Asset, Maintenance Category, Asset
  Class, Size, or Location;
- allow MTTR to group by Asset, Maintenance Category, Asset Class, Size, or
  Technician;
- use a stable Maintenance Category code/ID as the group key;
- use canonical Size as the group key and O&G notation as the label;
- retain explicit `Uncategorised`, `Unclassified`, and `Unspecified` groups;
- make Parts Consumption expose Part Identity, Asset Class, Asset Size, and Part
  Size without ERP codes; and
- keep Parts Consumption cursor pagination deterministic across part, asset
  class, and asset size.

These are data-contract decisions, not an approved Reports UI. Audit and test
the partial implementation before deciding whether it is safe to retain.

## Redesign questions Codex must resolve

Answer everything discoverable from code and data before asking the owner.

### Dashboard

- What decisions should each role be able to make from the Dashboard?
- Which items require immediate action versus passive monitoring?
- Which KPIs are genuinely useful to LDC Maintenance?
- What period should each KPI use, and can the user change it?
- Should Maintenance Category, Size, Location, Asset Class, or date filters
  apply to the Dashboard?
- Which cards should drill into a filtered list or report?
- Should different roles receive different default layouts or only different
  data visibility?
- Which information belongs above the fold?
- How should unavailable or insufficient data be explained?

### Reports

- Should the landing page be organised by operational question, asset lifecycle,
  theme, priority, or role?
- Which reports should be prominent, secondary, hidden, or deferred?
- Which filters are global and which are report-specific?
- Category filtering must mean Maintenance Category. Decide where Asset Class is
  also required.
- Decide where Size is a filter, where it is a grouping dimension, and where it
  is display-only.
- Decide whether filters are single-select or multi-select.
- Define table, chart, summary, and drill-down behaviour per report family.
- Define URL state so filtered reports can be bookmarked and shared.
- Confirm CSV needs and determine whether printable/PDF output is required now.
- Define behaviour for large result sets, cursor pagination, and exports.

## Required design process

### Stage 1 — Current-state audit

Produce a concise audit of:

- current user experience;
- available APIs and calculations;
- role and authorization behaviour;
- existing filters and exports;
- data-quality limitations;
- documentation/code contradictions; and
- the interrupted working-tree changes.

Do not change files during this audit.

### Stage 2 — Product requirements

Translate operational needs into a short requirements document. Clearly label:

- confirmed requirements;
- recommendations;
- assumptions;
- open decisions; and
- out-of-scope items.

Do not treat current widgets or endpoints as requirements merely because they
exist.

### Stage 3 — Design alternatives

Present two or three coherent alternatives for the Dashboard and Reports
experience. Each alternative must include:

- information architecture;
- role behaviour;
- filtering model;
- navigation and drill-down model;
- desktop and narrow-screen layout;
- strengths;
- trade-offs; and
- backend impact.

Recommend one alternative and explain why it best fits LDC Maintenance.

### Stage 4 — Owner approval

Present the recommended design in reviewable sections. Obtain explicit owner
approval before creating implementation tasks.

### Stage 5 — Design package

After approval, create:

- an approved design document;
- page-level wireframes;
- a widget/report-to-API mapping;
- finalized acceptance criteria;
- a test strategy; and
- a separate implementation plan.

Implementation still requires explicit authorization.

## Design quality expectations

- Lead with operational questions and decisions, not charts.
- Use plain Maintenance terminology.
- Avoid dense executive-BI styling.
- Make urgent work visually distinguishable without relying only on colour.
- Reuse the application design system and shared identity components.
- Prefer progressive disclosure over placing every metric on one page.
- Make filters understandable and resettable.
- Preserve filter context during drill-down and back navigation.
- Ensure keyboard accessibility and readable responsive layouts.
- Do not show misleading precision or fabricate trends.
- Explain why a metric is unavailable.

## Definition of done for the handover task

The next Codex task is complete only when:

1. the current state and data capabilities have been audited;
2. unresolved product decisions have been isolated from technical questions;
3. two or three redesign alternatives have been presented;
4. the owner has selected and approved a design;
5. the approved design and wireframes have been documented; and
6. no implementation has started without separate authorization.

## First response expected from Codex

After reading the repository and this handover, respond with:

1. a concise statement of the redesign objective;
2. the most important documentation/code inconsistencies found;
3. what is already technically possible;
4. the first material product decision that cannot be answered from the
   repository; and
5. one focused question for the owner.

Do not present an implementation plan in the first response.
