# Roles and Permissions

## Access Control Decision

ATMS uses a simple fixed-role access model for MVP. Each user has exactly one
assigned role from the six system-defined roles below.

The MVP does not support:

- Multiple roles per user
- Custom role creation
- Custom permission sets
- Granular permission management
- A permission matrix administration UI

Role behavior is enforced by backend policies and middleware and reflected in
frontend navigation, record visibility, and available actions.

## Roles

### Administrator

Manages users and assigns one of the six fixed roles. Manages locations, master
data/dropdowns, ERP sync settings, and company settings. Can view all records.
May activate or deactivate user accounts but cannot physically delete them.
May import employees from SharePoint, select employees for ATMS access, assign
a fixed role, and send activation or password-reset links.

### Maintenance Manager

Reviews and approves or rejects Maintenance Requests. Creates Work Orders
through approval, assigns Work Orders, manages PM Rules, and manages operational
maintenance data. Reviews completed Work Orders and closes them after final
validation. May trigger manual ERP synchronization but cannot change ERP
connection or schedule settings. May cancel non-closed Work Orders with a
required reason. May edit execution details on non-terminal Work Orders for
operational recovery; all such changes are audited.

### Technician

Views assigned Work Orders, updates work progress, records parts used, adds
attachments, updates readings and asset status where permitted, and submits
completion information. May mark assigned Work Orders as completed but cannot
close them.

### Logistics

Views assets and asset location history. Records physical asset location
changes. Has no Maintenance Request approval, Work Order execution, PM Rule, or
administration permissions. Does not have Parts Reference access in the MVP.

### Requester / User

Creates Corrective Maintenance Requests and views their own submitted requests.
May search active assets and view basic asset information needed to create a
request. Cannot view asset maintenance history, location history, attachments,
or ERP raw/reference details. May submit unverified meter readings as supporting
information but cannot confirm them. May cancel their own corrective requests
while those requests are pending review.

### Viewer

Read-only access to assets, maintenance requests, Work Orders, parts reference,
and dashboard data where permitted. May view mapped ERP reference fields but
cannot view raw ERP payloads.

## Permission Principles

- Authorization is enforced through Laravel policies using the user's single role.
- The six roles are seeded system data and cannot be created, renamed, or deleted through the application.
- Administrators assign one fixed role to each user.
- User accounts are activated/deactivated and never physically deleted.
- Deactivated users cannot authenticate, while their historical ownership and audit references remain intact.
- Imported employee records do not automatically become ATMS users.
- Only Administrator can provision an imported employee as an ATMS user and assign a role.
- Users set their own password through a one-time activation or reset link; Administrators do not set or view user passwords.
- Self-registration is not supported.
- Normal users cannot create Work Orders directly.
- Work Orders are created only from approved Maintenance Requests.
- Only Maintenance Manager or Administrator can approve Maintenance Requests.
- Only Logistics, Maintenance Manager, or Administrator can update asset location.
- Only Administrator can create or edit location definitions and master-data values.
- Administrator and Maintenance Manager can trigger manual ERP synchronization.
- Only Administrator can manage ERP connection and synchronization schedule settings.
- Only Administrator can change company settings such as the display timezone.
- Only Administrator can view raw ERP payloads.
- Only authorized users can create or edit PM Rules.
- Parts and fixed assets are primarily ERP-sourced reference data.
- Technicians can mark only their assigned Work Orders as completed.
- Only Maintenance Manager or Administrator can assign or reassign Work Orders.
- A Work Order must be assigned to an active Technician before it can move to in progress.
- Administrator and Maintenance Manager may edit execution details on non-closed, non-cancelled Work Orders; every change is audited.
- Only Maintenance Managers and Administrators can close completed Work Orders.
- Only Maintenance Managers and Administrators can cancel open, in-progress, or completed Work Orders, with a required reason.
- Technicians cannot cancel Work Orders.
- Requesters may cancel their own user-created corrective Maintenance Requests while pending review.
- Maintenance Managers and Administrators may cancel any pending-review Maintenance Request.
- System-generated preventive Maintenance Requests may be cancelled only by Maintenance Manager or Administrator.
- Closed Work Orders are permanently immutable and cannot be reopened.
- Administrator, Maintenance Manager, and Technician may confirm meter readings.
- Requesters may submit unverified meter readings but cannot confirm them.
- The Logistics role is limited to asset physical location updates and location history.
- It does not introduce gate passes, shipments, transport documents, delivery notes, handovers, custody approvals, chain-of-custody workflows, or other logistics modules.
- Logistics does not have Parts Reference access in MVP.
