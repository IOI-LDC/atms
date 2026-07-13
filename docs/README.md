# ATMS Documentation

<!--
MAINTENANCE:
- Update "Current snapshot" whenever the product phase, release stage, pending
  work, or recommended next action changes.
- When a requirement is implemented, remove it from REQUIREMENTS.md and append its
  verified outcome to IMPLEMENTATION_HISTORY.md before updating this snapshot.
- When a roadmap question or dependency is resolved, remove it from ROADMAP.md and
  reflect the durable decision in the relevant authoritative summary.
-->

This directory is the current operating manual for ATMS. Start here; do not use
`_archive/` for current behavior or implementation decisions.

## Current snapshot

**Last documentation verification:** 2026-07-13

- **What the project is:** ATMS is LDC's operational asset-maintenance system. It
  manages assets, maintenance requests, work orders, preventive maintenance,
  readings, locations, bookings, attachments, reports, and administration.
- **Current product phase:** Phase 1 — ATMS operational maintenance. The repository
  baseline records the Phase 1 features as implemented. This documentation review
  did not rerun the full application test suite. Phase 2 (Asset Movement and Asset
  Assembly) and Phase 3 (Store Management) have not been opened for implementation.
- **Release stage:** The active documentation does not contain authoritative evidence
  of the current UAT, deployment, or production-adoption state. Treat release status
  as **awaiting external confirmation**, not as production-confirmed.
- **Pending work:** Three unimplemented product items are recorded in
  [REQUIREMENTS.md](REQUIREMENTS.md). ERP dependencies and the asset-ownership
  decision are recorded in [ROADMAP.md](ROADMAP.md).
- **Recommended next action:** Confirm the actual UAT/deployment state with the
  project owner, record it here, then approve or reject R-001 before scheduling any
  new implementation. R-002 remains lower priority; R-003 requires a product
  decision before design.

## Read in this order

1. [PRODUCT.md](PRODUCT.md) — scope, workflows, roles, and non-negotiable rules.
2. [ENGINEERING.md](ENGINEERING.md) — codebase topology, data ownership, security,
   and backend conventions.
3. [API.md](API.md) — active HTTP surface and integration rules.
4. [FRONTEND.md](FRONTEND.md) — Vue application structure, routes, and UI rules.
5. [OPERATIONS.md](OPERATIONS.md) — runtime, deployment, backup, and test guidance.
6. [ROADMAP.md](ROADMAP.md) — open decisions and live follow-up work.
7. [FUTURE_SCOPE.md](FUTURE_SCOPE.md) — bounded Phase 2/3 work; not current scope.
8. [REQUIREMENTS.md](REQUIREMENTS.md) — captured work that is not implemented.
9. [IMPLEMENTATION_HISTORY.md](IMPLEMENTATION_HISTORY.md) — concise outcomes for
   requirements that have landed.

## Documentation rules

- The nine active summaries above are authoritative for current work. Code and tests remain the
  final authority when a detail is missing or conflicts.
- Keep durable behavior here. Do not create handoff, implementation-plan, meeting,
  or completion-report documents for work that has already landed.
- Put a genuinely open decision or external dependency in `ROADMAP.md`; remove it
  when resolved.
- Capture product work in `REQUIREMENTS.md`. Remove an entry when it lands and add
  its concise outcome to `IMPLEMENTATION_HISTORY.md`; never keep completed work in
  the active requirements list.
- Put future subsystem/phase scope in `FUTURE_SCOPE.md`, not in current ATMS
  behavior documents.
- Historical material is preserved under `_archive/2026-07-13/legacy/`. It is
  reference-only and intentionally excluded from this reading path.

## Product boundary

ATMS is the operational maintenance application in a product family that will
eventually include Store Management (SM) and Asset Movement (AM). Today it runs as
a Laravel API, Vue SPA, and PostgreSQL database. It is not an ERP, warehouse,
procurement, financial asset register, document-management system, or labor system.
