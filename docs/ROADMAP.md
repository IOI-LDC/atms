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
confirmation, the next product actions are: (1) implement the independent
`is_active` MR/WO/PM gating fix, then RQ4 and the parts stock decrement —
none of these wait on LDC; (2) implement the status-vocabulary/condition
slice and RQ1–RQ3 — **LDC answered the blocking questions on 2026-08-16**
(`at_the_field` precedence and field-exit rules, MR-approval location, PM
marking flow, parts quantity ownership), so this is no longer externally
gated; (3) approve or reject R-001 in [REQUIREMENTS.md](REQUIREMENTS.md).
**Every LDC question is now answered** — Q7 came back **No** on 2026-08-16:
they do not want a withdrawn/out-of-service report, so R-11 is cancelled
rather than deferred. The agreed design lives in
[the status-vocabulary plan](plans/2026-08-07-operational-status-vocabulary.md);
execution order and per-phase detail live in its companion,
`.kilo/plans/2026-08-16-status-vocabulary-implementation.md`. Do not begin
Phase 2 or Phase 3 work implicitly.

## External dependencies

| Item | Needed outcome | Scope impact |
|---|---|---|
| ERP parts API | Page name and sample payload/field mapping | Parts sync quality and future SM work. |
| ERP consumption write-back | Confirm supported BC warehouse transaction and contract | Required before Phase 3 SM consumption write-back. |
| Asset ownership | **Answered 2026-08-17: ERP becomes authoritative.** A weekly ERP sync covering **both assets and parts** is planned for **Phase 3 — roughly six months out, subject to LDC budget.** | Until then ATMS is the operational source for asset reference data and ERP status reaches it only through the manual `atms:import-erp-assets` CSV import. Design decisions taken now must not make that sync harder to add — see 🟠 D-024. |
| Exchange Application Access Policy | LDC IT restricts the notification Entra application to `notification@ldc.com.ly` | Required before enabling production email; today the credential can send as any tenant mailbox. |

## Completed Phase 1 work

Core ATMS workflows, PM assignment/evaluation, locations, booking, dashboard,
WO forms, audit viewing, Graph email for both account and MR/WO workflow
notifications, and the implemented reports surface are not roadmap items. Their
current behavior is documented in the active files.

Email is **live** as of 2026-07-26: `ACCOUNT_EMAIL_TRANSPORT=graph`, verified by a
direct send and by a queued send processed by the worker container. Any workflow
action now emails the real `ldc.com.ly` recipients for that transition. The
Application Access Policy dependency above remains open, so the credential can
currently send as any mailbox in the tenant.

**2026-09-02: official hostname resolved.** The client's environment has a single
available host, `assets.ldc.com.ly` — the SPA is served at `/`, the API under
`/api`, same-origin (no split subdomain). `FRONTEND_URL`/`APP_URL` on the deployed
backend are `https://assets.ldc.com.ly`. See [OPERATIONS.md](OPERATIONS.md) and
[VPS-PROVISIONING.md](VPS-PROVISIONING.md).

## Delivery rule

Phase 2/3 work does not enter a Phase 1 change implicitly. It requires an explicit
scope decision, a design, implementation/tests, and an update to the appropriate
active summary rather than a new handoff document.
