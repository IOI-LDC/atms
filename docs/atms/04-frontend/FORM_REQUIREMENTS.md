# Form Requirements

## Attachment Upload Rules

- Maximum 20 MB per file.
- Accept PDF, common images, Word, and Excel documents.
- Reject executable and archive formats.
- Display backend validation messages for file type and size failures.

## Corrective Maintenance Request Form

Fields:

- Asset
- Priority
- Fault/issue title
- Description
- Current location, read-only or selectable
- Supporting meter reading, optional and saved as unverified
- Attachments, optional

## Review Maintenance Request Form

Actions:

- Approve & Create Work Order
- Reject Request

Fields for rejection:

- Rejection reason
- Suppressed until date, when applicable
- Suppressed until reading, when applicable

Cancelling a preventive request uses the same suppression boundary fields.
Corrective request cancellation does not use PM suppression fields.

For `date_or_reading` rules, show and require fields according to the captured
trigger dimensions. If both date and reading triggered the request, show and
require both suppression boundaries.

## Work Order Completion Form

Available to:

- The Technician assigned to the eligible Work Order
- Maintenance Manager
- Administrator

Fields:

- Work notes
- Parts used
- Updated asset reading, optional depending on asset/rule
- Final asset status
- Attachments, optional

Submitting this form marks the Work Order as completed and locks Technician
execution edits.

## Work Order Close Action

Available to:

- Maintenance Manager
- Administrator

Closing is a final confirmation action on a completed Work Order. It finalizes
the Work Order, updates applicable PM baselines, and makes the Work Order
permanently immutable. The asset maintenance history view reflects the source
record without copying it into a separate history record.

## Work Order Cancellation Form

Available to:

- Maintenance Manager
- Administrator

Available only for `open`, `in_progress`, or `completed` Work Orders.

Required field:

- Cancellation reason

## PM Rule Form

Fields:

- Asset
- Rule title
- Trigger type
- Reading type, if usage-based
- Interval value
- Interval days, if date-based
- Active/inactive

Use explicit `Deactivate Rule` and `Reactivate Rule` actions. Do not show a
physical delete action.

Disable deactivation while the rule has an active maintenance chain and explain
that the pending request or non-terminal Work Order must be resolved first.

## Meter Reading Form

Fields:

- Reading type
- Reading value
- Reading date/time
- Notes

Requester submissions are labeled `Unverified`. Administrator, Maintenance
Manager, and Technician can confirm an unverified reading. No additional
reading statuses are shown.

The UI must explain that confirmed values cannot decrease and cannot be edited
or deleted. Corrections require a new valid reading.

## Asset Location Update Form (UpdateLocationSheet)

**Available to:** Logistics, Maintenance Manager, Administrator.

**Accessible from:**
- `Locations` sidebar → "Asset Location Update" tab (dedicated screen)
- `Asset Detail` → "Location History" drill-down

**Context:** In Phase 1, this is a direct location update — no movement
request, no approval chain, no arrival confirmation. The backend
`UpdateAssetLocation` Action writes a location history record automatically.

**Endpoint:** `POST /api/assets/{asset}/location`

**Request Payload:**
| Field | Type | Required | Notes |
|---|---|---|---|
| `location_id` | int | Yes | Must reference an active location |
| `reason` | string | No | Brief reason for the location change |
| `notes` | string | No | Additional context |

**Frontend Form Fields:**

| Field | Control | Required | Validation |
|---|---|---|---|
| Asset | Read-only display | N/A | Pre-populated from selected asset row (tag + name) |
| Current Location | Read-only display | N/A | Shown for context; "No location assigned" if null |
| New Location | Select dropdown | Yes | Active locations only. Exclude current location from options. |
| Effective Date | DateTime picker | Yes | Defaults to now. Must be a valid date. |
| Reason | Text input | No | Free text, max 255 characters |
| Notes | Textarea | No | Free text |

**Submission flow:**
1. User selects an asset row and clicks "Update Location".
2. `UpdateLocationSheet` opens (side sheet).
3. Form validates → "Confirm Location Change" dialog opens summarising the
   change (e.g., "Move asset T-001-ABC-1234 from Workshop to Rig A?").
4. User confirms → `POST /api/assets/{asset}/location` is dispatched.
5. Success → toast, sheet closes, asset list refreshes, location history
   refreshes.
6. Error → validation errors shown inline, domain errors (409) shown as
   alert/toast. Form data preserved.

**Validation rules (backend-enforced, mirrored in frontend):**
- Asset must be active (`is_active = true`).
- Target location must be active (`is_active = true`).
- `location_id` must exist in the `locations` table.

**Error examples:**
- `422`: "Cannot update location for an inactive asset."
- `422`: "Cannot assign an inactive location."
- `422`: `location_id` validation errors (missing, non-existent).

## Location Create / Edit Form (LocationForm)

**Available to:** Administrator only.

**Accessible from:** `Locations` sidebar → "Manage Locations" tab.

**Endpoint:**
- Create: `POST /api/admin/locations`
- Update: `PATCH /api/admin/locations/{location}`

**Request/Form Fields:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | Yes | Display name |
| `type` | string | Yes | Free text (e.g., "workshop", "yard", "rig", "well_site", "building") |
| `code` | string | No | Short code (e.g., "WS", "RA") |
| `parent_id` | int | No | Self-referencing FK to `locations.id`, nullable |
| `description` | text | No | Free text |
| `is_active` | bool | No | Defaults to `true` on create |

**Activate / Deactivate:**
- Deactivating a location hides it from the "Asset Location Update" picker
  but preserves it in location history records.
- Uses `PATCH /api/admin/locations/{location}` with `is_active: false/true`.

## Install Component Form

Available to: Administrator, Maintenance Manager, Technician (on assigned, non-terminal Work Order)

Fields:
- Target parent asset (pre-populated from context)
- Component to install (search/select from spares: asset_kind = component|package, parent_asset_id IS NULL, Active/Ready)
- Notes (optional)

## Remove Component Form

Available to: Administrator, Maintenance Manager, Technician (on assigned, non-terminal Work Order)

Fields:
- Component to remove (pre-populated from context)
- Removal reason (required: "preventive swap", "worn", "damaged", "upgrade", "other")
- Post-removal sub-status (required: Ready, DBR, Disposed, Scrapped)
- Notes (optional)

## Swap Component Form

Available to: Administrator, Maintenance Manager, Technician (on assigned, non-terminal Work Order)

Fields:
- Component to remove (pre-populated from context)
- Removal reason (required)
- Post-removal sub-status (required)
- Replacement component (search/select from spares)
- Notes (optional)

## Create MR for Component (from Parent WO)

Available to: Maintenance Manager, Administrator

Context:
- Opened from the parent WO detail screen for a yellow/red PM status component
- Pre-populates asset (the component), description, and references the parent WO
