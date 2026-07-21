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

## R-004 — MR edit view supports attachments

**Status:** Captured

**Priority:** High

**Source:** Product discussion, 2026-07-17

**Problem:** The `attachments` table and the MR attachment API endpoints (`GET / POST
/api/maintenance-requests/{maintenanceRequest}/attachments`) already exist, but the
frontend maintenance-request edit/detail view does not expose an attachment section.
Users cannot upload, view, download, or delete attachments on a submitted or pending
MR from the UI.

**Desired outcome:** The MR detail view exposes a standard attachment section,
consistent with the existing attachment UX on assets, work orders, and parts.

**Scope:** The `/maintenance/requests/:requestId` frontend view, `MaintenanceRequest`
attachment composable/hook, and new feature tests for the attachment flow on an MR.

**Out of scope:** Backend API changes (endpoints already exist), batch uploads,
inline preview, or attachment limits beyond the existing per-file 20 MB / accepted
MIME types.

**Acceptance criteria:**
1. A user viewing an MR detail page can see any existing attachments.
2. A user with permission can upload one or more attachments on a pending or
   submitted MR.
3. A user with permission can download an existing attachment.
4. A user with permission can soft-delete an attachment.
5. The UX matches the existing attachment patterns on assets, work orders, and parts.
6. The flow has focused feature tests covering list, upload, download, and delete.

**Next action:** Product owner approves the requirement. Once approved, add the
attachment section to the MR detail view using the existing attachment composables
and the already-available API endpoints.

## R-005 — `fa_subclass_code` is ERP-owned and read-only in ATMS

**Status:** Approved

**Priority:** Medium

**Source:** Product discussion, 2026-07-19

**Problem:** `fa_subclass_code` is reference data owned by LDC ERP, but ATMS
currently treats it as a locally editable field. The asset edit form sends it in
the asset `PATCH` payload (`useAssetDetail.ts`, `doSave`) and the backend accepts
it as an updatable attribute (`AssetController::update`). A user can therefore set
an ATMS value that disagrees with ERP, with no path to reconcile the two.

**Desired outcome:** ATMS displays `fa_subclass_code` but never writes it. The
value shown always reflects what ERP supplied.

**Scope:** The asset edit form field (rendered read-only with an ERP-managed
hint), removal of `fa_subclass_code` from the frontend update payload, removal
from the backend updatable-attribute list and its update validation, and tests
asserting the field is rejected or ignored on `PATCH /api/assets/{asset}`.

**Out of scope:** The Admin master-data list at `/admin/fa-subclass-type-codes`,
which manages the code list itself and remains editable. Reports that *filter by*
`fa_subclass_code` are unaffected — this requirement concerns writing the value on
an asset, not reading or filtering it. Building ERP asset sync is a separate
Phase 3 decision (see `ROADMAP.md`).

**Acceptance criteria:**
1. The asset edit form shows `fa_subclass_code` as read-only, labelled as
   ERP-managed.
2. The frontend asset update payload does not contain `fa_subclass_code`.
3. `PATCH /api/assets/{asset}` does not change the stored `fa_subclass_code`,
   regardless of role, including Administrator.
4. Existing values still display on the asset detail view and still drive report
   filtering.
5. A feature test asserts the value is unchanged after a `PATCH` that includes it.

**Next action:** Confirm whether an out-of-range `fa_subclass_code` already exists
on any asset from prior manual edits before making the field read-only; a stale
local value would otherwise become permanently uncorrectable through the UI.

## R-006 — Withdrawn assets are not selectable for new work

**Status:** Approved

**Priority:** Medium

**Source:** Product discussion, 2026-07-19

**Problem:** `maintenance_status` defaults to `enrolled`; a `withdrawn` asset has
left the maintenance program. No asset picker filters on it today —
`useAssetSearch` requests an unfiltered `/assets` page — so withdrawn assets are
offered wherever an asset is chosen, including when raising new maintenance work
against them.

**Desired outcome:** Only `enrolled` assets are selectable in the pickers that
start new work. Withdrawn assets remain fully visible in the asset list and asset
detail view, and remain selectable in report filters so their history stays
reachable.

**Scope:** `useAssetSearch` (accept a `maintenance_status` constraint), the
`AssetCombobox` consumers that create work — maintenance-request create, work-order
create, and the asset-move locator — and focused tests for each. The backend
`/assets` endpoint already supports the `maintenance_status` filter
(`AssetController::index`), so no API change is expected.

**Out of scope:** Report filter comboboxes, which must continue to offer withdrawn
assets. Hiding withdrawn assets from the asset list or asset detail view. Changing
the meaning of the `withdrawn` sub-statuses, which stay informational with no
workflow trigger.

**Acceptance criteria:**
1. Maintenance-request create, work-order create, and asset-move pickers list only
   `enrolled` assets.
2. Report filter comboboxes still list withdrawn assets.
3. The asset list and asset detail view still show withdrawn assets.
4. A record that already references an asset withdrawn *after* the record was
   created still renders that asset correctly, and editing the record does not
   silently drop the selection.
5. Each changed picker has a test asserting a withdrawn asset is absent and an
   enrolled one is present.

**Next action:** Decide the behaviour for criterion 4 — whether an in-flight MR or
WO whose asset was withdrawn mid-workflow may still be edited and closed normally.
Confirm before implementation; the rest of the requirement does not depend on it.
