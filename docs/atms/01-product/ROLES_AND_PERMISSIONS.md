# Roles and Permissions

## Access Control Decision

ATMS uses a simple fixed-role access model for MVP. Each user has exactly one
assigned role from the **five human** system-defined roles below (plus a non-human **SERVICE** role for M2M API tokens). The legacy **Viewer**
role has been merged into **Requester** — all users are Requesters at minimum.

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

Manages users and assigns one of the five fixed roles. Manages locations, master
data/dropdowns, ERP parts sync settings (SM-owned), and company settings. Can
view all records. May activate or deactivate user accounts but cannot physically
delete them. May import employees from SharePoint, select employees for access,
assign a fixed role, and send activation or password-reset links.

May manage all assembly operations: install, remove, swap components, change
asset_kind, and set parent_asset_id.

May book and unbook assets (reserving them for a Job/Project); booking is
released automatically when the asset is moved or deactivated.


### Maintenance Manager

Reviews and approves or rejects Maintenance Requests. Creates Work Orders
through approval, assigns Work Orders, views PM Rules and runs manual evaluation, and manages operational
maintenance data. Reviews completed Work Orders and closes them after final
validation. May trigger manual ERP parts synchronization (SM-owned) but cannot
change ERP connection or schedule settings. May cancel non-closed Work Orders
with a required reason. May edit execution details on non-terminal Work Orders
for operational recovery; all such changes are audited.

May install, remove, or swap components within asset assemblies. May change
asset_kind and parent_asset_id. May create MRs for child components from a
parent Work Order screen.

May book and unbook assets (reserving them for a Job/Project); booking is
released automatically when the asset is moved or deactivated.


### Technician

Views assigned Work Orders, updates work progress, records parts used (from SM
catalogue), adds attachments, updates readings and asset status where permitted,
and submits completion information. May mark assigned Work Orders as completed
but cannot close them.

May perform component install, remove, or swap as part of executing an assigned,
non-terminal Work Order. May create Corrective Maintenance Requests for removed
components that require refurbishment.


### Logistics

Owns the AM (Asset Movement) workflow externally: approves asset movement
requests and confirms arrivals via the AM frontend. Within ATMS, views assets
and asset location history. May also create Corrective Maintenance Requests and
view their own submitted requests, identical to the Requester role in that
regard. May book and unbook assets to reserve them for a Job/Project (booking
auto-releases on location change or deactivation). Has no Maintenance Request
approval, Work Order execution, PM Rule, or administration permissions within
ATMS. Does not have Parts Reference access in the MVP.

May view assembly history for components where they have view access to the
asset.


### Requester

Creates Corrective Maintenance Requests and views their own submitted requests.
May search active assets and view basic asset information needed to create a
request. May view asset maintenance history, location history, attachments, and
mapped ERP reference fields (merged-in Viewer capabilities). May submit
unverified meter readings as supporting information but cannot confirm them. May
cancel their own corrective requests while those requests are pending review.

May view assembly history for components where they have view access to the
asset. May view the parent asset reference and Installed/Ready status on
component detail screens.

## Permission Principles

- Authorization is enforced through Laravel policies using the user's single role.
- The five roles are seeded system data and cannot be created, renamed, or deleted through the application.
- Administrators assign one fixed role to each user.
- User accounts are activated/deactivated and never physically deleted.
- Deactivated users cannot authenticate, while their historical ownership and audit references remain intact.
- Imported employee records do not automatically become ATMS users.
- Only Administrator can provision an imported employee as a user and assign a role.
- Users set their own password through a one-time activation or reset link; Administrators do not set or view user passwords.
- Self-registration is not supported.
- All authenticated users can create Corrective Maintenance Requests.
- Normal users cannot create Work Orders directly.
- Work Orders are created only from approved Maintenance Requests.
- Only Maintenance Manager or Administrator can approve Maintenance Requests.
- Asset physical location and location history are owned by AM. Within ATMS, Logistics, Maintenance Manager, and Administrator can view asset location.
- Only Administrator can create or edit location definitions and master-data values.
- Administrator and Maintenance Manager can trigger manual ERP parts synchronization (SM-owned).
- Only Administrator can manage ERP connection and synchronization schedule settings.
- Only Administrator can change company settings such as the display timezone.
- Only Administrator can view raw ERP payloads.
- Only Administrator can create, edit, deactivate, or reactivate PM Rule **templates**. Administrator and Maintenance Manager may view templates, assign a template to an asset, and evaluate/deactivate/reactivate an assignment (per asset).
- Parts reference data is owned by SM. ATMS reads parts from SM tables for work order part-request forms.
- Assets are managed fully within ATMS — there is no ERP asset source.
- Technicians can mark only their assigned Work Orders as completed.

- Only Administrator or Maintenance Manager may change asset_kind.
- Only Administrator or Maintenance Manager may directly set parent_asset_id outside of a Work Order.
- The assigned Technician may execute component install, remove, or swap as part of an active Work Order.
- All authenticated users may view asset assembly history where they have view access to the component.
- Maintenance Manager and Administrator may create MRs for child components from the parent WO detail screen.

- Only Maintenance Manager or Administrator can assign or reassign Work Orders.
- A Work Order must be assigned to an active Technician before it can move to in progress.
- Administrator and Maintenance Manager may edit execution details on non-closed, non-cancelled Work Orders; every change is audited.
- Only Maintenance Managers and Administrators can close completed Work Orders.
- Only Maintenance Managers and Administrators can cancel open, in-progress, or completed Work Orders, with a required reason.
- Technicians cannot cancel Work Orders.
- Any user may cancel their own user-created corrective Maintenance Request while it is pending review.
- Maintenance Managers and Administrators may cancel any pending-review Maintenance Request.
- System-generated preventive Maintenance Requests may be cancelled only by Maintenance Manager or Administrator.
- Closed Work Orders are permanently immutable and cannot be reopened.
- Administrator, Maintenance Manager, and Technician may confirm meter readings.
- Requesters and Logistics users may submit unverified meter readings but cannot confirm them.
- Logistics owns the AM movement workflow (submit → approve → confirm arrival) externally in the AM frontend. Within ATMS, Logistics has no maintenance approval, Work Order execution, PM Rule, or administration permissions.
- Logistics does not have Parts Reference access in MVP.

