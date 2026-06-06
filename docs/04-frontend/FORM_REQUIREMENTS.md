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

## Location Update Form

Available to:

- Logistics
- Maintenance Manager
- Administrator

Fields:

- New location
- Effective date
- Reason/notes
