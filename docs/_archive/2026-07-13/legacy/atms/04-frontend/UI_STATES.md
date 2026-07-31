# UI State Rules

## Persistent Changes

Every user-initiated persistent change requires confirmation, including
ordinary create/edit operations, profile and settings changes, uploads,
assignments, status transitions, synchronization, and operational updates.

Validate before opening confirmation. The dialog must summarize the intended
change and use the exact action as its confirm label. Disable repeat submission
while the request is running. On failure, preserve entered data and show
actionable errors. On success, refresh affected data and show a concise toast.

Read-only navigation, search, filters, pagination, tabs, and local draft state
do not require confirmation. Automated jobs are recorded in status or activity
history without a user prompt.

## Maintenance Request

Show status badge for:

- Pending Review
- Rejected
- Converted to Work Order
- Cancelled

Primary action for Pending Review:

- Approve & Create Work Order
- Reject Request

Show `Cancel Request` for:

- A Requester's own pending corrective request
- Any pending request viewed by Maintenance Manager or Administrator

System-generated preventive requests show cancellation only to Maintenance
Manager or Administrator. Converted requests never show a request-cancellation
action.

## Work Order

Show status badge for:

- Open
- In Progress
- Completed
- Closed
- Cancelled

Primary action for open/in-progress work order:

- Mark Work Order Completed

Show this action only when the current user is the assigned Technician,
Maintenance Manager, or Administrator and the backend policy allows completion.

An unassigned Work Order remains `open`. Only Maintenance Manager or
Administrator can assign or reassign it, and only to an active Technician.
Starting work is unavailable until assignment.

Primary action for completed Work Order:

- Close Work Order

Show the close action only to Maintenance Manager or Administrator. Closed Work
Orders are read-only with no reopen action.

Show `Cancel Work Order` to Maintenance Manager or Administrator for `open`,
`in_progress`, or `completed` Work Orders. A cancellation reason is required.
Cancelled Work Orders are read-only.

## Asset

Show operational status badge and Asset Maintenance Status badge.

Asset Maintenance Status badges:
- Active (standalone assets: no sub-status)
- Active / Installed (component currently installed in a parent)
- Active / Ready (spare component, available for installation)
- Inactive / LIH (Lost in Hole)
- Inactive / DBR (Damaged Beyond Repair)
- Inactive / Disposed
- Inactive / Scrapped
- Inactive / Other

Operational status defaults:
- Active
- Under Maintenance
- Down
- Inactive
## ERP Sync

Show sync status:

- Running
- Success
- Partial
- Failed

## Error Handling

Validation errors should appear near fields.

System errors should appear as toast plus a readable message.

Empty states should guide users to the next valid action.
