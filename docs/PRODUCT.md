# ATMS Product and Workflow Summary

## Purpose and scope

ATMS manages operational asset maintenance: assets, maintenance requests (MRs),
work orders (WOs), preventive-maintenance (PM) rules, meter readings, attachments,
locations, bookings, dashboard metrics, operational reports, and administration.

Assets are operated in ATMS today. Parts are exposed through the current parts
catalogue and ERP-sync path; inventory, purchasing, costing, stock movement, and
warehouse execution are not ATMS responsibilities. Current location updates are
direct operational actions. A formal movement-request workflow is future AM scope.

## Core workflow

```text
Corrective: requester/technician/admin/manager creates MR
            -> manager/admin approves or rejects
            -> approval creates WO
            -> assign -> start -> complete -> close

Preventive:  active per-asset PM assignment becomes due
             -> scheduler or explicit evaluation creates MR
             -> same approval-to-WO flow
```

MR statuses are `pending_review`, `rejected`, `converted`, and `cancelled`.
WO statuses are `open`, `in_progress`, `completed`, `closed`, and `cancelled`.
Closed WOs are immutable. Only Administrators and Maintenance Managers may close
or cancel WOs. A requester may cancel only their own pending corrective MR.

## Roles

| Role | Primary responsibility |
|---|---|
| Administrator | System configuration, users, master data, PM templates, audit access, and full operational administration. |
| Maintenance Manager | Reviews MRs, manages PM assignments, assigns and closes WOs, and oversees maintenance data. |
| Technician | Creates corrective MRs, performs assigned WOs, records permitted readings and execution information. |
| Logistics | Updates asset locations; has operational visibility but no MR/WO approval authority. |
| Requester | Creates and tracks their corrective MRs; has limited operational visibility. |

The service role is machine-to-machine only. Exact authorization is enforced by
Laravel policies; do not infer permission from a screen alone.

## Permission and visibility model

| Capability | Administrator | Maintenance Manager | Technician | Logistics | Requester |
|---|---:|---:|---:|---:|---:|
| View active assets and operational records | Yes | Yes | Yes | Yes | Yes |
| Create or edit assets | Yes | Yes | No | No | No |
| Direct location update / booking | Yes | Yes | No | Yes | No |
| Create corrective MR | Yes | Yes | Yes | No | Yes |
| Approve or reject MR | Yes | Yes | No | No | No |
| Assign, close, or cancel WO | Yes | Yes | No | No | No |
| Start or complete an assigned WO | Yes | Yes | Assigned only | No | No |
| Manage PM templates | Yes | View/assign/evaluate | No | No | No |
| Manage WO-form templates | Yes | No | No | No | No |
| Admin users, locations, lists, audit logs, settings | Yes | Limited user visibility | No | No | No |

The table is an orientation aid. Route-specific checks, record visibility, and
machine-token access must be read from the relevant Laravel policy before changing
an authorization rule.

## Important business rules

- PM templates are created and maintained by Administrators. Administrators and
  Maintenance Managers assign, deactivate, reactivate, and evaluate templates for
  individual assets. Category/type auto-application is not supported.
- PM triggers are `date`, `reading`, or `date_or_reading`. Confirmed readings are
  the only readings used by reading-based PM calculations.
- Meter confirmations reject values lower than the last confirmed value. A future
  reset, if approved, must be a separate audited workflow.
- Assets use `enrolled` or `withdrawn` maintenance status. Operational status is
  distinct; do not conflate either with booking state.
- Booking is independent of maintenance workflow. A location change or asset
  inactivation releases a booking.
- Attachments use persistent application storage. Access and deletion are policy
  controlled and every operational change is auditable.
- WO forms are implemented: an Administrator manages templates by FA subclass;
  a WO snapshots its form, supports an explicit sync/defer decision, and cannot
  complete until all required fields are filled.

## Workflow detail

### Corrective maintenance

Any permitted user creates a corrective MR with the affected asset, priority, and
problem description. While it is `pending_review`, an Administrator or Maintenance
Manager may update it; the creator of a corrective request may update their own
request when policy allows. Approval atomically creates the WO and carries the MR
priority forward. Rejection requires a reason. A pending corrective request may be
cancelled only by its owner where permitted or by an Administrator/Manager.

The WO is assigned before work starts. The assigned Technician, Administrator, or
Maintenance Manager records execution data, permitted parts, readings, asset status,
attachments, and WO-form values. A completed WO is reviewed and closed by an
Administrator or Maintenance Manager. Closing updates the derived maintenance
history; it does not create a second editable history record.

### Preventive maintenance

A PM rule is a reusable template. It has no operational effect until assigned to a
specific asset. Each assignment owns its own baseline. Scheduled or manual
evaluation creates at most one active maintenance chain for that assignment.

For a due PM occurrence, rejection or cancellation records suppression boundaries
so the scheduler does not recreate the same occurrence. Date-triggered, reading-
triggered, and `date_or_reading` rules require the matching suppression dimensions.
PM baseline updates occur on WO closure using the assignment, not by altering the
template globally.

### Readings, locations, bookings, and forms

- Requesters may submit supporting readings; their readings remain unconfirmed.
  Administrators, Maintenance Managers, and Technicians may confirm them.
- Confirmed readings are append-only and monotonically non-decreasing per asset and
  reading type. A valid correction is a new reading, not editing history.
- Phase 1 location update is a direct action that writes a location-history row.
  It has no movement request, arrival confirmation, gate-pass, or custody flow.
- Booking reserves an asset for a job/project and is released by location change or
  asset inactivation. It does not replace maintenance status.
- An active WO-form template is selected by FA subclass. A WO snapshots it at
  creation; syncing newer template changes is explicit and may be deferred. Fields
  with `has_pre_post` require both values; other required fields require their post
  value before the WO can complete.

## Reporting

Reports are authenticated, read-only, organisation-wide operational views. They do
not introduce a data warehouse, forecasting, financial analysis, Power BI, or a
custom report builder. The active endpoint catalogue is in [API.md](API.md).

## Explicit exclusions

- Labor hours, rates, costs, productivity, timesheets, or technician wallets.
- Procurement, purchasing, inventory valuation, warehouse transactions, and parts
  costing.
- Financial fixed-asset management or ERP-led financial lifecycle.
- Gate passes, shipment documents, custody workflows, and a general document
  management system.
- Advanced checklist scoring, approval/versioning, photo-checklist requirements,
  and defect-generation engines.

Future scope is deliberately separated in [FUTURE_SCOPE.md](FUTURE_SCOPE.md).
