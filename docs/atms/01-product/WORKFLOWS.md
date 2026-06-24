# Product Workflows

## Corrective Maintenance Workflow

Corrective Maintenance is user-initiated. Any authenticated user regardless of
role may create a Corrective Maintenance Request.

1. User identifies an asset fault or maintenance need.
2. User creates a Corrective Maintenance Request.
   While the request is pending_review, the creator (or Admin/Manager) may
   update the description, priority, and asset before it is reviewed.
3. Maintenance Manager reviews the request.
4. Maintenance Manager approves or rejects the request.
5. If approved, the system creates a Work Order.
6. The Work Order stores the Maintenance Request priority as it existed at conversion.
7. Technician or assigned user performs the work.
8. Parts used are recorded against the Work Order using parts from the SM catalogue.
9. Asset readings and status are updated where applicable.
10. Technician marks the Work Order as completed.
11. Maintenance Manager or Administrator reviews and closes the Work Order.
12. The closed Work Order appears in the asset maintenance history read model.

A Corrective Maintenance Request may be cancelled while it is
`pending_review`. Once approval atomically converts it into a Work Order, the
request cannot be cancelled; the Work Order cancellation workflow applies.

## Preventive Maintenance Workflow

Preventive Maintenance is system-initiated.

1. PM Rule is configured for an ATMS-managed asset.
2. System checks PM rules on a scheduled basis.
3. When criteria are met, the system checks that the PM Rule has no active maintenance chain.
4. If no active chain exists, the system creates one Preventive Maintenance Request.
5. Maintenance Manager reviews the request.
6. Maintenance Manager approves or rejects the request.
7. If approved, the system creates a Work Order.
8. The Work Order stores the Maintenance Request priority as it existed at conversion.
9. Technician or assigned user performs the work.
10. Parts used are recorded against the Work Order using parts from the SM catalogue.
11. Asset readings and status are updated where applicable.
12. Technician marks the Work Order as completed.
13. Maintenance Manager or Administrator reviews and closes the Work Order.
14. PM rule baseline is updated using closure date and/or latest reading.
15. The closed Work Order appears in the asset maintenance history read model.

An active maintenance chain exists when the PM Rule has:

- A `pending_review` Maintenance Request, or
- A converted Work Order in `open`, `in_progress`, or `completed`

Rejected or cancelled preventive requests create an occurrence suppression
record. The scheduler must not regenerate the same due occurrence. A future
occurrence may be generated only when its due date or reading is beyond the
recorded suppression boundary.

A Preventive Maintenance Request may be cancelled while it is `pending_review`.
Once approval atomically converts it into a Work Order, the request cannot be
cancelled; the Work Order cancellation workflow applies.

For a preventive request:

- Reject when the generated request is invalid or not applicable.
- Cancel when the request is valid but should not proceed.
- Both decisions require a reason and create a PM occurrence suppression.
- `suppressed_until_date` and `suppressed_until_reading` are nullable and are
  set according to the PM trigger and decision.

For `date_or_reading` rules:

- If only the date dimension generated the request, require a date suppression boundary.
- If only the reading dimension generated the request, require a reading suppression boundary.
- If both dimensions became due in the same evaluation, record both trigger dimensions and require both suppression boundaries.

## Location Update Workflow (owned by AM)

Asset location is owned by the AM (Asset Movement) subsystem. The AM workflow is:

1. Requester submits a movement request (asset + destination) in the AM frontend.
2. Logistics approves the request in the AM frontend.
3. After physical movement, Logistics confirms arrival in the AM frontend.
4. AM updates the asset current location and appends a location history record.
5. ATMS reads the current location from AM tables for display only.

ATMS does not write location data directly. This workflow does not create gate pass, shipment, custody, or transfer-approval records within ATMS.
## Asset Management Workflow

1. Administrator or Maintenance Manager opens the Asset Registry.
2. User selects "Add Asset" and fills in name, description, category, serial
   number, model, manufacturer, and operational status.
3. System creates the asset record and logs the creation.
4. At any time, Admin/Manager may edit asset operational details. Location changes are handled through the AM subsystem.
5. Assets are never deleted — they are soft-deactivated (is_active = false).
4. At any time, Admin/Manager may edit asset operational details. Location changes are handled through the AM subsystem.
5. Assets are never deleted — they are soft-deactivated (is_active = false).
5. Assets are never deleted — they are soft-deactivated (is_active = false).

## Asset Usage Reading Workflow

1. User opens the asset usage/meter screen or enters a supporting reading while creating a Corrective Maintenance Request.
2. User selects reading type, such as operating hours or kilometers.
3. User enters reading value and reading date/time.
4. System stores the reading with the submitting user and source.
5. If submitted by an Administrator, Maintenance Manager, or Technician, the reading may be confirmed immediately.
6. If submitted by a Requester or Logistics user, the reading remains unverified until an Administrator, Maintenance Manager, or Technician confirms it.
7. Confirmation rejects a reading lower than the latest confirmed reading for the same asset and reading type.
8. Only confirmed readings update the asset's current meter value.
9. PM rule evaluation uses only the latest confirmed readings.

No reading status workflow is required. A reading is considered confirmed only
when its confirmation user and timestamp are present.

Confirmed readings are append-only and monotonically non-decreasing per asset
and reading type. MVP has no decreasing-reading override or edit-in-place path.
Corrections require a new valid reading and an Administrator audit note.

## Attachment Workflow

1. User opens asset record.
2. User uploads attachment.
3. User enters optional description/category.
4. System stores file and links it to the parent record.
5. Authorized users can view or download the attachment.

## Assembly Operations Workflow

### Component Installation

1. Authorized user (Administrator, Maintenance Manager, or assigned Technician on
   an active WO) selects a spare component: `asset_kind = component` or `package`,
   `parent_asset_id IS NULL`, `Active/Ready`.
2. User confirms the target parent asset and initiates installation.
3. System validates: no cycle (component is not the parent or an ancestor),
   component is not already installed, parent's asset_kind allows children.
4. System sets `component.parent_asset_id = <parent>`, inserts an
   `asset_assembly_history` row (`installed_at = now`, `removed_at = NULL`).
5. System updates component maintenance sub-status to `Installed`.
6. Audit log records `asset.component_installed` with component, parent, and user.

### Component Removal

1. Authorized user selects the installed component for removal.
2. User provides a removal reason (e.g. "preventive swap", "rotor worn", "upgrade")
   and post-removal disposition (`Ready`, `DBR`, `Disposed`, or `Scrapped`).
3. System sets `removed_at = now` on the active assembly history row, calculates
   and stores `accumulated_hours` for this installation period.
4. System sets `component.parent_asset_id = NULL`.
5. System updates component maintenance sub-status based on disposition.
6. Audit log records `asset.component_removed`.

### Component Swap (atomic)

1. A swap combines removal and installation in a single transaction.
2. Steps 1–6 of removal and 1–5 of installation execute together.
3. The old component's sub-status follows the post-removal disposition; the new
   component's sub-status is set to `Installed`.

### Parent WO Component Cross-Check

1. When a parent WO is opened (e.g. Motor service at 500 hrs), the WO detail
   screen queries all direct child components and displays their PM status:
   - 🟢 **OK** — well within PM interval
   - 🟡 **Soon** — approaching PM interval
   - 🔴 **Due / Overdue** — at or past interval
2. Technician or Manager may decide to act on yellow/red items while the asset
   is already in the workshop.
3. The parent WO does **not** auto-create component MRs. The "Create MR for
   Component" action is a manual decision by the Maintenance Manager or
   Administrator.
4. Indicators are computed from: (parent's current confirmed reading − reading
   at component's install time from `asset_assembly_history`) vs the component's
   own PM rule interval.

### Removed Component Maintenance

1. If a removed component needs refurbishment, a separate Corrective MR is
   created on that component asset.
2. The MR follows the standard Corrective Maintenance Workflow (approval → WO →
   execution → closure).
3. After refurbishment, the component's Asset Maintenance Status returns to
   `Active/Ready` and can be reinstalled in any compatible parent.
