# Product Workflows

## Corrective Maintenance Workflow

Corrective Maintenance is user-initiated.

1. User identifies an asset fault or maintenance need.
2. User creates a Corrective Maintenance Request.
3. Maintenance Manager reviews the request.
4. Maintenance Manager approves or rejects the request.
5. If approved, the system creates a Work Order.
6. The Work Order stores the Maintenance Request priority as it existed at conversion.
7. Technician or assigned user performs the work.
8. Parts used are recorded against the Work Order.
9. Asset readings and status are updated where applicable.
10. Technician marks the Work Order as completed.
11. Maintenance Manager or Administrator reviews and closes the Work Order.
12. The closed Work Order appears in the asset maintenance history read model.

A Corrective Maintenance Request may be cancelled while it is
`pending_review`. Once approval atomically converts it into a Work Order, the
request cannot be cancelled; the Work Order cancellation workflow applies.

## Preventive Maintenance Workflow

Preventive Maintenance is system-initiated.

1. PM Rule is configured for an asset.
2. System checks PM rules on a scheduled basis.
3. When criteria are met, the system checks that the PM Rule has no active maintenance chain.
4. If no active chain exists, the system creates one Preventive Maintenance Request.
5. Maintenance Manager reviews the request.
6. Maintenance Manager approves or rejects the request.
7. If approved, the system creates a Work Order.
8. The Work Order stores the Maintenance Request priority as it existed at conversion.
9. Technician or assigned user performs the work.
10. Parts used are recorded against the Work Order.
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

## Location Update Workflow

1. Logistics, Maintenance Manager, or Administrator opens the asset record.
2. User selects new physical location.
3. User enters effective date and optional reason/notes.
4. System updates the asset current location.
5. System records the change in asset location history.

This workflow records physical location only. It does not create logistics,
gate pass, shipment, custody, or transfer-approval records.

## Asset Usage Reading Workflow

1. User opens the asset usage/meter screen or enters a supporting reading while creating a Corrective Maintenance Request.
2. User selects reading type, such as operating hours or kilometers.
3. User enters reading value and reading date/time.
4. System stores the reading with the submitting user and source.
5. If submitted by an Administrator, Maintenance Manager, or Technician, the reading may be confirmed immediately.
6. If submitted by a Requester, the reading remains unverified until an Administrator, Maintenance Manager, or Technician confirms it.
7. Confirmation rejects a reading lower than the latest confirmed reading for the same asset and reading type.
8. Only confirmed readings update the asset's current meter value.
9. PM rule evaluation uses only the latest confirmed readings.

No reading status workflow is required. A reading is considered confirmed only
when its confirmation user and timestamp are present.

Confirmed readings are append-only and monotonically non-decreasing per asset
and reading type. MVP has no decreasing-reading override or edit-in-place path.
Corrections require a new valid reading and an Administrator audit note.

## Attachment Workflow

1. User opens asset or part record.
2. User uploads attachment.
3. User enters optional description/category.
4. System stores file and links it to the parent record.
5. Authorized users can view or download the attachment.
