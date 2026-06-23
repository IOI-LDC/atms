# Status Model

## Maintenance Request Statuses

### pending_review

The request has been created and is awaiting Maintenance Manager review. While
pending_review, the creator (or an Admin/Manager) may update the description,
priority, and asset. Once reviewed or cancelled, the request is immutable.

### rejected

The request has been rejected by the Maintenance Manager.

### converted

The request has been approved and atomically converted into exactly one Work
Order. There is no separate stored `approved` status.

### cancelled

The request has been cancelled while awaiting review, before approval and
conversion. Once a request is approved and atomically converted into a Work
Order, the Maintenance Request cannot be cancelled. The Work Order cancellation
workflow must be used instead.

`cancelled`, `rejected`, and `converted` are terminal Maintenance Request
statuses.

The MVP Maintenance Request status set is `pending_review`, `rejected`,
`converted`, and `cancelled`.

## Work Order Statuses

### open

The Work Order has been created from an approved Maintenance Request. It may be
unassigned initially.

### in_progress

Work has started. A Work Order cannot transition to `in_progress` unless it is
assigned to an active user with the Technician role.

### completed

The assigned Technician has submitted all required completion information.
Technician execution fields, parts used, readings, and attachments are locked
after this transition. The Work Order awaits final review by a Maintenance
Manager or Administrator.

### closed

The completed Work Order has been reviewed and finalized by a Maintenance
Manager or Administrator. Maintenance history and applicable PM baselines are
finalized. A closed Work Order is permanently immutable.

### cancelled

The Work Order has been cancelled by a Maintenance Manager or Administrator
with a required reason. Cancellation is allowed from `open`, `in_progress`, or
`completed`. A cancelled Work Order is terminal and read-only.

The MVP Work Order status set is `open`, `in_progress`, `completed`, `closed`,
and `cancelled`.

Normal transition:

`open → in_progress → completed → closed`

Cancellation transitions:

- `open → cancelled`
- `in_progress → cancelled`
- `completed → cancelled`

Closed Work Orders cannot be edited, cancelled, reopened, or transitioned to
another status.

## Asset Operational Statuses

These should be configurable as master data. Suggested defaults:

- Active
- Under Maintenance
- Down
- Inactive

Avoid financial lifecycle statuses such as capitalized/disposed unless shown as read-only ERP reference data.
