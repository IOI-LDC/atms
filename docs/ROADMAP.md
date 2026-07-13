# ATMS Roadmap and Open Decisions

<!--
MAINTENANCE:
- When a question is answered or a dependency is resolved, remove it from this
  file and update the durable decision in PRODUCT.md, ENGINEERING.md, OPERATIONS.md,
  or FUTURE_SCOPE.md as appropriate.
- When the delivery/release stage changes, update both "Current delivery stage"
  below and the "Current snapshot" in README.md.
- Product feature requests belong in REQUIREMENTS.md, not in this register.
-->

This is the active decision and external-dependency register. Remove an item once
resolved; do not retain a completed handoff or gap-analysis document. Product work
belongs in [REQUIREMENTS.md](REQUIREMENTS.md), not here.

## Current delivery stage

- The repository baseline records the Phase 1 application features as implemented;
  this documentation review did not rerun the full application test suite.
- Phase 2 and Phase 3 are future scope and are not active delivery work.
- UAT, deployment, and production-adoption status are not confirmed by the active
  repository documentation and require an external project-owner update.

## Next step

Confirm and record the current UAT/deployment state. After that administrative
confirmation, the next product action is to approve or reject R-001 in
[REQUIREMENTS.md](REQUIREMENTS.md). Do not begin Phase 2 or Phase 3 work implicitly.

## External dependencies

| Item | Needed outcome | Scope impact |
|---|---|---|
| ERP parts API | Page name and sample payload/field mapping | Parts sync quality and future SM work. |
| ERP consumption write-back | Confirm supported BC warehouse transaction and contract | Required before Phase 3 SM consumption write-back. |
| Asset ownership | Confirm whether ATMS remains the operational source for asset reference data or an ERP-sync design is revived | Do not reintroduce asset sync without this decision. |

## Completed Phase 1 work

Core ATMS workflows, PM assignment/evaluation, locations, booking, dashboard,
WO forms, audit viewing, Graph account email, and the implemented reports surface
are not roadmap items. Their current behavior is documented in the active files.

## Delivery rule

Phase 2/3 work does not enter a Phase 1 change implicitly. It requires an explicit
scope decision, a design, implementation/tests, and an update to the appropriate
active summary rather than a new handoff document.
