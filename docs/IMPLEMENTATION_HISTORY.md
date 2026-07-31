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
