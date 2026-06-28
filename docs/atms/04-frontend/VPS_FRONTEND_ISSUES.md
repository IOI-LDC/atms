# VPS Frontend Issues Tracker

> **Purpose:** Live log of frontend issues found during VPS deployment testing.
> **Status key:** `[ ]` = open, `[x]` = fixed (rebuild/redeploy to verify), `[!]` = needs backend fix (out of frontend scope).
> **Format:** Module → Issue description, expected behavior, actual behavior, severity.

---

## Summary

| Module           | Issues | Fixed (frontend) | Needs backend |
|------------------|--------|------------------|---------------|
| Material Request | 5      | 4                | 0 (1 optional*) |
| Work Order       | 3      | 3                | 0             |
| Asset            | 1      | 1                | 0             |

\* All reported issues are resolved. The only outstanding item is an **optional** backend nicety for
MR-05: a policy-driven `can_delete` flag on `AttachmentResource` so the Delete button is *surfaced*
to non-admin owners (the backend already permits the action).

---

## Material Request (MR)

### [x] MR-01 — Asset filter search returns no results — **RESOLVED (backend)**

- **Location:** Create MR → Asset filter/selector
- **Actual (before):** Searching for "motor" returned nothing; "Motor" returned results.
- **Expected:** Case-insensitive search.
- **Severity:** High
- **Fix (backend):** index queries now use `LOWER(col) LIKE` (portable across PostgreSQL + SQLite).
  Frontend already sent `search` correctly — no frontend change.

---

### [x] MR-02 — Newly created MR not shown without page refresh — verified on VPS

- **Location:** MR list page after creating a new MR (sheet closes).
- **Fix:** `doCreate()` now refreshes every already-loaded tab slice (My Requests / Pending Approval /
  All Requests), not just My Requests. (`composables/useMaintenanceRequests.ts`)

---

### [x] MR-03 — Attachments now open in a new tab — verified on VPS

- **Location:** MR detail → Attachments (also WO detail).
- **Fix:** New `lib/attachments.ts → openAttachmentInNewTab()` fetches the file as a blob and opens its
  object URL in a new tab (API forces `Content-Disposition: attachment`, so a plain link always
  downloads). Link is now a shadcn `Button` labelled **Open**.

---

### [x] MR-04 — MR view layout + label — verified on VPS

- **Location:** MR View / Edit screen.
- **Fix:** Asset field spans the full row (`detail-field-block`); label changed to "Approved by".
  (`MaintenanceRequestDetailView.vue`)

---

### [x] MR-05 — Delete attachments — **RESOLVED (backend); 1 tiny backend nicety for owner-delete UI**

- **Location:** MR detail → Attachments section
- **Actual (before):** No delete button existed at any stage.
- **Expected:**
  - Users can delete their own attachments while the MR is still pending / not yet converted.
  - Admin users can delete any attachment at any stage.
- **Severity:** Medium
- **Frontend done:** Each attachment now has a **Delete** action → confirmation dialog → `DELETE
  /attachments/{id}` → list refresh. Shown to Admin/Manager, which is exactly what the current
  backend `AttachmentPolicy::delete` authorises (any attachment, any status). So **"Admin can delete
  any attachment at any stage" is fully delivered.** (`useMaintenanceRequestDetail.ts`,
  `MaintenanceRequestDetailView.vue`)
- **Backend done:** `AttachmentPolicy::delete` now allows the uploader to delete while the parent MR
  is `pending_review` (Admin/Manager still any time).
- **Frontend:** the Delete button is now per-attachment via `canDeleteAttachment(a)`, which prefers a
  backend `can_delete` flag and falls back to Admin/Manager until that flag ships.
  (`useMaintenanceRequestDetail.ts`, `MaintenanceRequestDetailView.vue`)
- **Remaining nicety (backend, to surface owner-delete in the UI):** add a policy-driven
  `can_delete` boolean to `AttachmentResource` (`$request->user()?->can('delete', $this->resource)`).
  Until then, a non-admin owner won't *see* the Delete button (the payload doesn't expose ownership),
  though the backend permits the action.

---

## Work Order (WO)

### [x] WO-01 — WO view layout — verified on VPS

- **Location:** WO View / Edit screen.
- **Fix:** Asset field spans the full row (`detail-field-block`). (`WorkOrderDetailView.vue`)

---

### [x] WO-02 — No technician assignment step during MR approval — **frontend done (rebuild to verify)**

- **Location:** MR approval flow (Approve & Create Work Order)
- **Actual (before):** Approving created the WO unassigned; the manager had to navigate to the WO to assign.
- **Expected:** The approval dialog should include a Technician picker so the WO is assigned at the point of approval.
- **Severity:** Medium
- **Fix (frontend + backend):** The Approve dialog now has an optional **Assign to** picker (active
  Technicians and Maintenance Managers; defaults to "Leave unassigned"). Approve now sends
  `assignee_id` and the backend creates + assigns the WO in **one transaction** — an ineligible
  assignee rolls back the whole approval (MR stays `pending_review`, no WO created). Backend added
  optional `assignee_id` to `POST /maintenance-requests/{id}/approve` with tests.
  (`useMaintenanceRequestDetail.ts`, `MaintenanceRequestDetailView.vue`)

---

### [x] WO-03 — Assign/reassign in WO Edit — **RESOLVED (frontend + backend)**

- **Location:** WO Edit screen → Assign field
- **Expected:**
  - Unassigned `open` WO: "Unassigned" + picker for an active Technician or Maintenance Manager.
  - Already-assigned WO: current assignee + picker to reassign (while `open` **or** `in_progress`).
  - Only Admin and Maintenance Manager see/use the field.
  - `Start` blocked until someone is assigned.
  - Assignable roles: active Technician AND active Maintenance Manager.
- **Severity:** Medium
- **Already worked:** field gated to Admin/Manager; Managers can load `/users`; `Start` already
  blocked until assigned.
- **Frontend done:** reassign now allowed while `in_progress` (was `open`-only); the assignee picker
  now lists active Technicians **and** Maintenance Managers, each tagged with its role.
  (`useWorkOrderDetail.ts`, `WorkOrderDetailView.vue`)
- **Picker endpoint bug fixed:** both the WO assign picker and the MR approve picker were calling
  `GET /users` (404, swallowed → empty list); the real route is `GET /admin/users` (auth `viewAny` =
  Admin/Manager). This pre-existing bug is why the assignee list was always empty.
  (`useWorkOrderDetail.ts`, `useMaintenanceRequestDetail.ts`)
- **Backend done:** `AssignWorkOrder` + `StartWorkOrder` now accept an active Technician or
  Maintenance Manager (centralised in `User::isWorkOrderAssignee()`), with tests covering
  manager-assignable, inactive-manager-rejected, ineligible-role-rejected, and the full
  assigned-manager lifecycle.
- **Ref:** `UI_STATES.md` §Work Order — Admin/Manager assign/reassign, active Technician or Maintenance Manager.

---

## Asset

### [x] AS-01 — Location shows "#undefined" — **RESOLVED (frontend + backend)**

- **Backend fix shipped:** `AssetController::locationHistory()` now eager-loads
  `->with(['fromLocation', 'toLocation'])`; regression test
  `LocationWorkflowTest::test_location_history_endpoint_returns_location_names` covers it (incl. the
  null-from initial-placement case). Names appear after frontend redeploy.

<details><summary>Original diagnosis</summary>

- **Location:** Asset View/Edit page AND Location History table/sheet
- **Actual (before):** Location renders as "Location #undefined" in the Location History table (From
  and To) and the Location History sheet.
- **Expected:** Show the actual location name (e.g. "Warehouse A").
- **Severity:** High
- **Root cause:** The frontend tried to resolve location names from the Admin-only
  `/admin/locations` endpoint using `*_location_id` fields that the API response **doesn't expose**,
  and in doing so overwrote the real `from_location`/`to_location` objects with `undefined` →
  "#undefined". Worse, the backend `AssetController::locationHistory()` doesn't eager-load the
  `fromLocation`/`toLocation` relations, so the `AttachmentLocationHistoryResource`'s `whenLoaded`
  objects are **omitted entirely** — the response carries no location data at all.
- **Frontend done:** Removed the broken `/admin/locations` resolution and `*_location_id` fallback;
  the history now consumes the API's `from_location`/`to_location` objects directly and shows "—"
  when absent (no more "#undefined"). (`useAssetDetail.ts`, `AssetDetailView.vue`,
  `LocationHistorySheet.vue`)
- **Backend follow-up (flagged — required for names to appear):** Eager-load the relations in
  `AssetController::locationHistory()`, e.g. `$asset->locationHistories()->with(['fromLocation',
  'toLocation'])->orderByDesc('effective_at')->get()`. The resource already returns the objects via
  `whenLoaded`. Until this ships, the history shows "—" instead of names.

</details>

