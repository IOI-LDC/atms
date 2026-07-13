# Active Requirements

<!--
MAINTENANCE:
- This file contains only work that is not implemented.
- When a requirement is implemented and verified, remove its complete R-### entry
  and append a concise outcome with the same ID to IMPLEMENTATION_HISTORY.md.
- When a requirement question is answered, update its status, scope, acceptance
  criteria, priority, and next action. Remove rejected requirements rather than
  leaving completed or closed entries here.
-->

This file contains work that is not implemented. Capture a meeting outcome or a
development observation here before it becomes a plan. Do not add completed work:
remove it and record the outcome in [IMPLEMENTATION_HISTORY.md](IMPLEMENTATION_HISTORY.md).

## Lifecycle

`Captured` means the problem is recorded. `Clarifying` means a decision or detail
is missing. `Approved` means the requirement is ready to schedule; it is still not
an implementation plan. Use `ROADMAP.md` for dependencies and cross-phase decisions.

## R-001 — Asset search matches printed asset tag

**Status:** Captured

**Priority:** High

**Source:** Development observation, 2026-07-12

**Problem:** Logistics users may know the printed `asset_tag` but asset search is
described as name/code oriented.

**Desired outcome:** A documented asset search matches a full or partial printed
tag case-insensitively while retaining existing name/code behavior.

**Scope:** Asset index query, its tests, and search placeholder/copy where used.

**Out of scope:** QR scanning, asset assembly, and movement-workflow changes.

**Acceptance criteria:** A matching tag returns the asset; name/code search,
authorization, and cursor behavior still work.

**Next action:** Product owner approves or rejects the requirement. If approved,
verify every frontend search consumer before writing the implementation plan.

## R-002 — Recent relocations operational view

**Status:** Captured

**Priority:** Low

**Source:** Frontend observation, 2026-07-12

**Problem:** Dashboard relocation data is a short, windowed summary, while the
location workflow may need a larger most-recent list.

**Desired outcome:** Provide an approved, read-only way to view the latest location
changes in deterministic newest-first order.

**Scope:** Product decision, endpoint/read model, and location workflow UI only if
approved.

**Out of scope:** Formal AM movement approval, shipment records, or historical data
correction.

**Acceptance criteria:** The approved endpoint limits results, preserves access
rules, orders equal timestamps deterministically, and has focused feature coverage.

**Next action:** Confirm whether users need more than the existing dashboard
relocation summary. Do not design an endpoint until that need is confirmed.

## R-003 — Audited meter-reset workflow decision

**Status:** Clarifying

**Priority:** Medium

**Source:** Product observation, 2026-07-12

**Problem:** A real meter/counter replacement can require a new lower baseline,
while ordinary confirmed readings must never decrease.

**Desired outcome:** Decide whether ATMS supports a privileged meter reset. If yes,
define a separate audited action rather than an `allow_decrease` bypass.

**Scope:** Product decision, authorization, mandatory reason/effective date, audit
record, transactional baseline update, and tests if approved.

**Out of scope:** Weakening ordinary confirmation monotonicity.

**Acceptance criteria:** Either reject the requirement explicitly or approve a
workflow whose post-reset readings are still monotonic against the new baseline.

**Next action:** Ask the project owner whether real meter or counter replacement is
an operational scenario ATMS must support. No implementation work starts before the
answer is recorded.
