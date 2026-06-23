# Work Order Detail Page — Design

Date: 2026-06-23
Status: approved
Branch: `feat/frontend-redesign`

## Overview

Replace the placeholder `WorkOrderDetailView.vue` ("coming soon") with a full-lifecycle Work Order execution and closure page. Follows the same single-scroll stacked-card layout as `MaintenanceRequestDetailView.vue` but covers the richer WO feature set: assign technician, start/complete/close/cancel transitions, inline parts-used management, meter reading recording, asset status updates, and attachment upload.

## Architecture

Mirrors the MR detail page: a **composable** (`useWorkOrderDetail.ts`) owns all state + actions; the `.vue` is view + orchestration only.

### New file

- `frontend/src/composables/useWorkOrderDetail.ts` — single-instance-scoped composable (one WO per mount), returns refs + action functions.

### Edited files

- `frontend/src/views/work-orders/WorkOrderDetailView.vue` — replaces the placeholder.

### Types

No new types needed. `WorkOrder`, `WorkOrderPart`, `WorkOrderStatus`, `UserRef`, `Attachment` already exist in `types/index.ts`.

### Composable API surface

| Concern | Exposed refs / functions |
|---|---|
| Load | `record, loading, error, notFound, forbidden, load(id)` |
| Attachments (view) | `attachments, attachmentsLoading` |
| Attachment upload | `uploadOpen, uploadLoading, uploadFiles, fileInputRef, addFiles, removeFile, openUpload, doUpload` |
| Edit description | `editing, saving, draft, editError, validationErrors, startEdit, cancelEdit, saveEdit` |
| Assign technician | `assignOpen, assignLoading, technicians, techniciansLoading, selectedTechId, openAssign, doAssign` |
| State transitions | `canStart, canComplete, canClose, canCancel, canEdit, canAssign` + `startLoading, completeOpen, completeLoading, completionNotes, openComplete, doComplete, doClose, closeLoading, cancelOpen, cancelLoading, cancelReason, openCancel, doCancel` |
| Parts | `addPartOpen, addPartLoading, partDraft, removeTarget, removeLoading, openAddPart, doAddPart, openRemovePart, doRemovePart` + `partsSearch, searchParts` |
| Meter readings | `readingTypes, recordReadingOpen, readingLoading, readingDraft, openRecordReading, doRecordReading` |
| Asset status | `assetStatusOpen, assetStatusLoading, selectedStatus, openSetAssetStatus, doSetAssetStatus` |
| Derived | `sinceLastService` (latestConfirmedReading − pmRule.lastTriggeredReading, when WO sourced from a reading-triggered PM rule) |

After every successful mutation (assign/start/complete/close/cancel/saveEdit/addPart/removePart/recordReading/setAssetStatus/upload), the composable **reloads** `GET /api/work-orders/{id}` so the record, parts, and timestamps stay consistent with the audit log — same pattern as MR detail.

The composable also fetches:
- `GET /api/users` for the technician picker (filters client-side to `role === 'technician' && is_active`).
- `GET /api/usage-reading-types` for the reading-type select.
- `GET /api/parts` for the parts-used picker (searchable).

## Page layout (single-scroll stacked cards)

```
[← Back]
[WO-1234]  CM Work Order          [status badge] [priority badge]
(terminal banner if closed/cancelled)

┌─ Details ───────────────────────────────────┐  [Edit] (if canEdit)
│  Asset · Source MR · Created ·               │
│  Assigned to · Assigned by ·                 │
│  Started · Completed · Closed                │
│  Read-only grid with detail-grid fields      │
└──────────────────────────────────────────────┘

┌─ Work notes ────────────────────────────────┐
│  (read: description + completion_notes)      │
│  (edit: Textarea for description)            │
│  [cancel] [save] (in edit mode)              │
└──────────────────────────────────────────────┘

┌─ Related maintenance request ───────────────┐
│  → MR-123 (RouterLink)                       │
└──────────────────────────────────────────────┘

┌─ Parts used ────────────────────────────────┐  [Add Part] (if canEdit)
│  table: code | name | qty | notes | [×]     │
│  empty-state if none                         │
└──────────────────────────────────────────────┘

┌─ Updated readings ──────────────────────────┐  [Record reading] (if canEdit)
│  (asset's latest confirmed readings per type)│
│  "Since last service: 180h / 500h" (if PM)  │
└──────────────────────────────────────────────┘

┌─ Final asset status ────────────────────────┐  [Update status] (if canSetAssetStatus)
│  current operational_status displayed        │
└──────────────────────────────────────────────┘

┌─ Attachments ───────────────────────────────┐  [Upload] (if canEdit)
│  (view list + upload capability)             │
└──────────────────────────────────────────────┘

[Assign…] [Start] [Complete…] [Close] [Cancel]   ← status-aware action bar
```

All cards reuse existing semantic classes: `page-section`, `page-header`, `page-title`, `page-subtitle`, `page-actions`, `data-card`, `data-card-header`, `data-card-title`, `detail-card-actions`, `data-card-content`, `detail-grid`, `detail-field`, `detail-field-label`, `detail-field-value`, `detail-field-block`, `detail-section`, `detail-section-title`, `detail-callout`, `detail-banner`, `detail-actions`. Status badges reuse `woStatusClass`/`woStatusLabel`/`priorityClass`/`priorityLabel` from `displayHelpers.ts`.

The parts-used table adds one new semantic class family: `.detail-table` / `.detail-table-head` / `.detail-table-row` / `.detail-table-cell` in the central stylesheet — generic, reusable for future in-card tables.

## State machine + permission gates

The action bar computes visible buttons from `record.status` AND role. The **backend gate remains authoritative**; client gates are UX hints only.

| Status | Admin/Manager | Assigned Technician | Other |
|---|---|---|---|
| `open`, unassigned | Assign…, Cancel | *(hint: "awaiting assignment")* | read-only |
| `open`, assigned | Reassign…, Start, Cancel | Start | read-only |
| `in_progress` | Reassign…, Complete…, Cancel | Complete… | read-only |
| `completed` | Close, Cancel | *(read-only)* | read-only |
| `closed` / `cancelled` | terminal banner | terminal banner | terminal banner |

**Permission rules** (derived from RBAC + status model):

- **Assign/Reassign** — Admin/Manager only.
- **Start** — Admin/Manager OR assigned Tech. Requires WO to be `open` AND have an assignee.
- **Complete** — Admin/Manager OR assigned Tech. Requires WO to be `in_progress`.
- **Close** — Admin/Manager only. Requires WO to be `completed`.
- **Cancel** — Admin/Manager only. From `open`, `in_progress`, or `completed`. Requires a reason.
- **Edit description** — Admin/Manager always; assigned Tech only while `open` or `in_progress`. Locked at `completed` (per RBAC line 89).
- **Add/remove parts** — same gate as description edit.
- **Record reading** — Admin/Manager/Tech/Requester (current backend gate: `Gate::authorize('create', AssetMeterReading::class)`. Requester-submitted readings remain unverified, Tech-submitted are immediately confirmed per RBAC line 103).
- **Set asset status** — Admin/Manager OR assigned Tech (via the new WO-scoped endpoint). Only while WO is non-terminal.
- **Upload attachments** — Admin/Manager/Tech (current backend gate).

## Assign sub-feature

Action-bar button `Assign…` (or `Reassign…` if already assigned) → `Dialog` with a searchable `Select`/`Combobox` of active Technician users. Confirming calls `POST /api/work-orders/{id}/assign { user_id }`.

**Technician list source:** `GET /api/users` filtered client-side to `role === 'technician' && is_active`. Depends on backend prerequisite #1 (broader `UserPolicy::viewAny`).

The current assignee is displayed read-only in the Details grid, with a small "Reassign" affordance via the action bar.

## Parts sub-feature

**Parts card** shows a detail-table when `record.parts.length > 0`, else an empty-state line. Columns: `part.erp_part_code`, `part.name`, `quantity`, `part.unit_of_measure`, `notes`, remove `×` button per row.

**Add Part** button in the card header → `Dialog` with:
- Part picker (searchable, sourced from `GET /api/parts`, using an `AssetCombobox`-style pattern to search as the user types)
- Quantity `<Input type="number" min="0.01" step="any">`
- Optional notes `<Textarea>`
- Confirm → `POST /api/work-orders/{id}/parts` → reload record.

**Remove** is a per-row icon button → `AlertDialog` confirm ("Remove this part?") → `DELETE /api/work-orders/{id}/parts/{partLine}` → reload record.

No inline **edit** of existing rows — the backend has no update-part endpoint; the correction path is remove + re-add.

Locked when WO status is `completed`, `closed`, or `cancelled`.

## Updated readings

Shows the asset's **latest confirmed readings** per usage reading type (fetched via `GET /api/assets/{id}/meter-readings`) and a derivable "since last service" display when the WO is sourced from a reading-triggered PM rule.

**Record reading** button in the card header (if `canEdit`) → `Dialog` with:
- Reading type `<Select>` (populated from `GET /api/usage-reading-types`)
- Reading value `<Input type="number" step="any">`
- Reading date `<Input type="date">`
- Optional notes `<Textarea>`
- Confirm → `POST /api/assets/{asset}/meter-readings { usage_reading_type_id, reading_value, reading_at, source: 'manual', notes }`. Passes `maintenance_request_id` = the WO's source MR id for audit traceability. → reload record + refresh readings display.

**Since last service** is a client-side derivation: `latest_confirmed_reading(reading_type) − pm_rule.last_triggered_reading`. Only shown when:
- The WO's `maintenance_request.is_preventive` is true, AND
- The source PM rule has `trigger_type` = `READING` or `DATE_OR_READING`, AND
- `last_triggered_reading` is non-null.

The derivation requires no new backend work — `PmDueCalculator::isDueByReading` already computes the same delta internally. The frontend needs the PM rule's `last_triggered_reading` and `interval_reading`, which the `PmRuleResource` already exposes.

## Final asset status

Displays the WO asset's current `operational_status` (badge-styled). **Update status** button (if `canEdit && canSetAssetStatus`) → `Select` dropdown for the new status (`active` / `under_maintenance` / `down` / `inactive`). Confirm calls `POST /api/work-orders/{id}/asset-status` (backend prerequisite #2). Reloads record + refreshes asset info on success.

Depends on the new WO-scoped backend endpoint.

## Attachment upload

**Upload** button in the Attachments card header (if `canEdit`) → inline expansion showing the `FileInput` component + file list pattern already used in the create-MR `Sheet` (`WorkOrdersView.vue` lines 196–210). Files are staged locally; confirming calls `api.upload(POST /api/work-orders/{id}/attachments, FormData)` → reloads attachment list + record on success.

The existing MR detail page stays view-only (no change). The WO detail gets the richer upload treatment because WO execution may involve third-party workshop documentation.

## Async / error / empty / terminal states

- `loading` → "Loading work order…"
- `notFound` (404) → "Work order not found."
- `forbidden` (403) → "You don't have permission to view this work order."
- `error` → message in `.error-state[role="alert"]`
- Parts empty → "No parts recorded."
- Attachments empty → "No attachments."
- Terminal (`closed`/`cancelled`) → read-only `.detail-banner`; action bar hidden; all add/edit controls hidden.
- Every mutation: disable its button while pending, preserve input on failure, **confirm before persistent mutation** via `Dialog` or `AlertDialog`. Confirm button labeled with the exact action name. `toast.success` on success, `toast.error` on failure.

## Styling

Reuses existing semantic classes almost entirely. One new portable baseline:

- `.detail-table` / `.detail-table-head` / `.detail-table-row` / `.detail-table-cell` — compact in-card table, added to `frontend/src/style.css`. Generic enough for future in-card tables.

No Tailwind utilities, no inline styles, no raw `<button>`/`<input>`/`<select>` in the feature file. All controls are shadcn-vue primitives (`Button`, `Input`, `Label`, `Textarea`, `Select`, `Dialog`, `AlertDialog`, `FileInput`). No new UX patterns beyond the detail-table and the attachment upload flow (which reuses the existing create-MR pattern).

## Backend prerequisites

Two backend changes must ship before the frontend WO-detail build is unblocked:

### 1. Broaden `GET /api/users` to Maintenance Manager (read only)

**File:** `backend/app/Policies/UserPolicy.php`, method `viewAny`.
**Change:** add `|| $user->hasRole(RoleCode::MAINTENANCE_MANAGER)`.
**Why:** RBAC line 48 grants "Assign/reassign WO" to Admin/Manager. The technician picker fetches `GET /api/users`. Today the policy returns Admin-only, so a Manager assigning a WO gets 403.
**Scope of change:** one-line policy change. All write operations (`update`/`manage`/`resetPassword`/`deactivate`/`reactivate`) remain Admin-only.

### 2. New endpoint: `POST /api/work-orders/{workOrder}/asset-status`

**Why:** WORKFLOWS step 9 has the Technician updating asset status during WO execution. `AssetPolicy::update` is Admin/Manager-only, so the Tech gets 403 via `PATCH /assets/{id}`. Add a WO-scoped endpoint that permits the assigned Tech (and Admin/Manager) to change only `operational_status`, only while the WO is non-terminal.

**Request:** `{ "operational_status": "active" | "under_maintenance" | "down" | "inactive" }`

**Policy:** Admin, Manager, OR the WO is assigned to this user. Suggested: `WorkOrderPolicy::setAssetStatus`.

**Status guard:** reject 409 if `closed` or `cancelled`.

**Behavior:** set `workOrder->asset->operational_status`, save, audit-log with WO context. Do not touch any other asset field. Return `{ data: asset }`.

**No other new backend endpoints needed.** All other WO actions (show/assign/start/complete/close/cancel/update/addPart/removePart), meter readings, parts list, usage reading types, and attachment upload already exist.

## Out of scope

- **Resettable physical meters** (Meter A = cumulative / Meter B = resets after each maintenance) — not supported by the current schema and not part of this feature. The "since last service" display in the readings section covers the operational need without schema changes. A separate design pass is recommended if/when physical resettable meters become a requirement.
- **Layout option C (sticky action rail)** — the user noted it may fit better long-term but chose the MR-style stacked-card layout (option A) for consistency. Documented as a future-redesign option.
- **Editing existing parts-used lines** — the backend has no update-part endpoint; remove + re-add is the correction path.
- **Viewer role** — still present in the backend (enum, seeder, policies, resources, tests). A full removal is deferred to a separate task.
- **Frontend role-gating in the composable** — `isViewer` is intentionally absent from the auth store per the "Viewer = Requester" decision in `CLAUDE.md`. Viewer users will see read-only WO views (no action bar, no edit controls) via the backend's `updateExecution` gate returning 403, which the composable catches.
