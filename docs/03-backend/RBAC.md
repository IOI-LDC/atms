# RBAC Design

## Roles

- Administrator
- Maintenance Manager
- Technician
- Logistics
- Requester
- Viewer

Each user has exactly one role. Authorization must be implemented through
Laravel policies. A granular permission package, multiple roles per user, and
runtime-configurable permission assignment are excluded from MVP.

The six roles are seeded, immutable system data. Administrators may assign
these roles to users but may not create, rename, or delete roles. Frontend
navigation, record queries, and actions must reflect the same backend policy
rules.

## Permission Matrix Draft

| Capability | Administrator | Maintenance Manager | Technician | Logistics | Requester | Viewer |
|---|---:|---:|---:|---:|---:|---:|
| View dashboard | Yes | Yes | Yes | Limited | Limited | Yes |
| View assets | Yes | Yes | Yes | Yes | Basic active assets only | Yes |
| View asset maintenance history | Yes | Yes | Yes | No | No | Yes |
| View location history | Yes | Yes | Yes | Yes | No | Yes |
| View asset attachments | Yes | Yes | Yes | No | No | Yes |
| View mapped ERP reference fields | Yes | Yes | Yes | Yes | No | Yes |
| View raw ERP payload | Yes | No | No | No | No | No |
| Update asset location | Yes | Yes | No | Yes | No | No |
| Add meter readings | Yes | Yes | Yes | No | Unverified only | No |
| Confirm meter readings | Yes | Yes | Yes | No | No | No |
| View parts reference | Yes | Yes | Yes | No | Yes | Yes |
| Create corrective MR | Yes | Yes | Yes | No | Yes | No |
| Review/approve MR | Yes | Yes | No | No | No | No |
| Cancel own pending corrective MR | Yes | Yes | No | No | Yes | No |
| Cancel any pending MR | Yes | Yes | No | No | No | No |
| Create WO directly | No | No | No | No | No | No |
| Update assigned WO | Yes | Yes | Yes | No | No | No |
| Edit non-terminal WO execution details | Yes | Yes | Assigned only | No | No | No |
| Assign/reassign WO | Yes | Yes | No | No | No | No |
| Mark assigned WO completed | Yes | Yes | Assigned only | No | No | No |
| Close completed WO | Yes | Yes | No | No | No | No |
| Cancel non-closed WO | Yes | Yes | No | No | No | No |
| Manage PM rules | Yes | Yes | No | No | No | No |
| Manage users | Yes | No | No | No | No | No |
| Import SharePoint employees | Yes | No | No | No | No | No |
| Provision employee as ATMS user | Yes | No | No | No | No | No |
| Manage locations/master data | Yes | No | No | No | No | No |
| Run ERP sync | Yes | Yes | No | No | No | No |
| Manage ERP sync settings | Yes | No | No | No | No | No |
| View technical audit logs | Yes | No | No | No | No | No |

Entries marked `Optional` remain unresolved and require explicit approval before
implementation.

## Important Rules

- Work Orders are created only from approved Maintenance Requests.
- Maintenance Manager approval is required before WO creation.
- ERP-sourced fixed asset and part fields should not be editable by normal users.
- Local operational fields may be editable according to permissions.
- Requesters may view their own submitted Maintenance Requests.
- Requesters may cancel only their own user-created corrective Maintenance Requests while `pending_review`.
- Maintenance Manager and Administrator may cancel any `pending_review` Maintenance Request.
- System-generated preventive Maintenance Requests may be cancelled only by Maintenance Manager or Administrator.
- Requesters may search active assets and view only the basic asset fields needed to create a Corrective Maintenance Request.
- Requesters cannot view asset maintenance history, location history, attachments, or ERP raw/reference details.
- Raw ERP payloads are Administrator-only.
- Other roles with asset or part access receive mapped ERP reference fields only.
- Technicians may update only Work Orders assigned to them unless a final approved policy states otherwise.
- Only Administrator or Maintenance Manager may assign or reassign Work Orders.
- Work Orders may be assigned only to active Technician users.
- Assignment is required before transition to `in_progress`.
- Administrator and Maintenance Manager may edit execution details on non-closed, non-cancelled Work Orders for operational recovery.
- Technician execution edits remain limited to assigned Work Orders before completion.
- Every execution-detail change by any role must be written to the technical audit log with redacted before/after context.
- Technicians may mark eligible assigned Work Orders as completed.
- Only Maintenance Managers and Administrators may close completed Work Orders.
- Completed Work Orders are locked against Technician execution edits.
- Closed Work Orders are permanently immutable and cannot be reopened.
- Administrator and Maintenance Manager may cancel `open`, `in_progress`, or `completed` Work Orders with a required reason.
- Technicians cannot cancel Work Orders.
- Logistics, Maintenance Manager, and Administrator may update asset physical location.
- Logistics has no maintenance approval, Work Order execution, PM Rule, or administration permissions.
- Logistics has no Parts Reference access in MVP.
- Logistics authority is limited to asset physical location updates and location history.
- It does not include gate passes, shipments, transport documents, delivery notes, handovers, custody workflows, transfer approvals, chain-of-custody workflows, or other logistics modules.
- Requester-submitted meter readings remain unverified.
- Only Administrator, Maintenance Manager, or Technician may confirm a meter reading.
- Only confirmed meter readings update current meter values or participate in PM calculations.
- Only Administrator may create, edit, activate, or deactivate location definitions and master-data values.
- Logistics and Maintenance Manager may select existing active locations when recording asset location changes.
- Administrator and Maintenance Manager may trigger manual ERP sync runs.
- Only Administrator may manage ERP connection, credentials, adapter selection, and synchronization schedule settings.
- Only Administrator may view technical audit logs in MVP.
- Only Administrator may import SharePoint employees or provision them as ATMS users.
- Employee import does not grant application access.
- Users set their own passwords through one-time activation/reset links; self-registration is disabled.
