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
confirmation, the next product actions are: (1) implement the agreed
status-vocabulary design and RQ1–RQ4 once LDC answers the open questions —
recorded order: the `is_active` MR/WO gating fix, then RQ4 (see
[docs/plans/2026-08-07-operational-status-vocabulary.md](plans/2026-08-07-operational-status-vocabulary.md));
(2) approve or reject R-001 in [REQUIREMENTS.md](REQUIREMENTS.md). Do not
begin Phase 2 or Phase 3 work implicitly.

## External dependencies

| Item | Needed outcome | Scope impact |
|---|---|---|
| ERP parts API | Page name and sample payload/field mapping | Parts sync quality and future SM work. |
| ERP consumption write-back | Confirm supported BC warehouse transaction and contract | Required before Phase 3 SM consumption write-back. |
| Asset ownership | Confirm whether ATMS remains the operational source for asset reference data or an ERP-sync design is revived | Do not reintroduce asset sync without this decision. |
| Status vocabulary — `at_the_field` rules | LDC answers: precedence when a `failure` asset moves to a rig; status when leaving the field | Gates the 4-value operational axis slice (recorded recommendations: keep `failure`; `ready_for_field` only from `at_the_field`; no write on workshop moves). |
| Status vocabulary — MR-approval location choice | LDC answers: which locations are offered, the default, and whether preventive approvals participate | Gates the MR-approval location option (recommendations: any active yard/workshop; default keep-current; both approval types). |
| RQ1 — PM marking flow | LDC answers: mark during the WO or at completion | Gates RQ1 (the cumulative L3 ⊇ L2 ⊇ L1 ladder is already settled). |
| RQ3 — parts quantity ownership | LDC answers: is the uploaded CSV the official stock source | Gates the RQ3 upload (recommendation: yes — CSV locally owns `available_quantity`; ERP sync never overwrites). |
| Official SPA hostname | Confirm the permanent LDC subdomain. The SPA is hosted at `https://atms.inova.krd` for now, which is what `FRONTEND_URL` should be set to on the deployed backend; treat it as provisional. | Email deep links point wherever `FRONTEND_URL` says, so the value must be revisited when the permanent host is issued. |
| Exchange Application Access Policy | LDC IT restricts the notification Entra application to `notification@ldc.com.ly` | Required before enabling production email; today the credential can send as any tenant mailbox. |

## Completed Phase 1 work

Core ATMS workflows, PM assignment/evaluation, locations, booking, dashboard,
WO forms, audit viewing, Graph email for both account and MR/WO workflow
notifications, and the implemented reports surface are not roadmap items. Their
current behavior is documented in the active files.

Email is **live** as of 2026-07-26: `ACCOUNT_EMAIL_TRANSPORT=graph`, verified by a
direct send and by a queued send processed by the worker container. Any workflow
action now emails the real `ldc.com.ly` recipients for that transition. The two
dependencies above remain open — the SPA hostname is provisional, and the Application
Access Policy is still unset, so the credential can currently send as any mailbox in
the tenant.

## Delivery rule

Phase 2/3 work does not enter a Phase 1 change implicitly. It requires an explicit
scope decision, a design, implementation/tests, and an update to the appropriate
active summary rather than a new handoff document.
