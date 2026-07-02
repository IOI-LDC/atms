# In Scope

## 1. Manual Asset Management

Assets are managed manually within ATMS. Administrators and Maintenance Managers
may create, update, and manage asset records directly. There is no ERP asset
source for this client deployment.

## 2. Manual Asset Registry

The system provides a full asset registry managed manually by Administrators and
Maintenance Managers. Assets can be created, updated, and soft-deactivated.
Operational information stored against each asset includes name, description,
category, serial number, model, manufacturer, operational status, asset
maintenance status, usage readings, attachments, and maintenance history.

The asset's **current location** is provided by the AM (Asset Movement)
subsystem — ATMS reads the current location from AM tables for display only. The
asset's location history is also owned by AM.

## 2a. Asset Assembly (Package / Component)

Certain assets are composed of other assets. For example, a mud motor contains
a power section, which itself contains a rotor and stator. Each component is a
full ATMS asset with its own MRs, WOs, readings, and history. Components can be
swapped in and out — a spare rotor can replace a worn rotor during motor
maintenance.

See [`ASSET_ASSEMBLY.md`](./ASSET_ASSEMBLY.md) for the full specification.

## 3. Parts Reference (read-only from SM)

Parts are owned by the SM (Store Management) subsystem. ERP syncs parts into SM
tables. ATMS reads parts from SM only to populate a Work Order part-request
form. Submission of that form flows into SM's order/stock workflow. ATMS does
not own parts, stock quantities, inventory, valuation, or warehouse operations.

Parts used on Work Orders are operational usage records of the SM-managed parts
catalogue. The MVP does not maintain stock-on-hand quantities, stock valuation,
procurement, warehouse transactions, or parts costs within ATMS.

## 4. Asset Usage Tracking

Each asset can have usage readings such as operating hours, kilometers, or other
usage/meter values. These readings will be used to support preventive
maintenance triggering and asset maintenance history.

Requesters may submit a reading as supporting information when creating a
Corrective Maintenance Request or from an asset record. Requester-submitted
readings are unverified until confirmed by an Administrator, Maintenance
Manager, or Technician. Only confirmed readings update the asset's current
meter value and participate in preventive maintenance calculations.

## 5. Asset Physical Location (read-only from AM)

ATMS reads the asset's current physical location from AM (Asset Movement)
tables for display purposes. Location changes and location history are owned by
AM and follow the AM movement workflow: Requester submits → Logistics approves →
Logistics confirms arrival → AM tables update. ATMS never writes location data
directly.

## 6. Preventive Maintenance Rules

The system will allow preventive maintenance rules to be configured for
individual ATMS-managed assets. These rules may be based on time intervals,
operating hours, kilometers, or other usage readings.

Each MVP PM Rule belongs to one individual asset. Category-level,
asset-type-level, unit/package-level, group, and template rules are excluded.

## 6a. Asset Creation and Management
Administrators and Maintenance Managers may create asset records manually
through the Asset Registry. Asset fields include name, description, category,
serial number, model, manufacturer, operational status, and asset maintenance
status. Assets may be updated at any time by Admin/Manager. Assets are
soft-deactivated (is_active = false) rather than deleted.
## 6b. Asset Maintenance Status

Each asset has an **Asset Maintenance Status** independent of ERP
disposal/financial treatment:

- **Active** — the asset is in operational use and eligible for maintenance
  workflows.

  For components and packages (`asset_kind = component` or `package`), Active
  assets carry one of: **Installed** (currently installed in a parent,
  `parent_asset_id` is set) or **Ready** (fully maintained spare available for
  installation, `parent_asset_id IS NULL`). Standalone assets
  (`asset_kind = asset`) use Active with no sub-status.

- **Inactive** — the asset is not in active service. Sub-statuses (purely
  informational, no workflow triggers): **LIH** (Lost in Hole), **DBR** (Damaged
  Beyond Repair), **Disposed**, **Scrapped**, **Other**.

See `ASSET_STATUS.md` for the full specification.

## 7. Automatic Preventive Maintenance Requests

When preventive maintenance criteria are met, the system will automatically
generate a preventive maintenance request. The request will then follow the
standard approval process before becoming a work order.

## 8. Corrective Maintenance Requests

Users will be able to raise corrective maintenance requests when an asset is
faulty, damaged, underperforming, or requires repair. The request will include
the asset, issue description, priority, location, and supporting notes where
required.

## 9. Maintenance Request Approval

All maintenance requests, whether preventive or corrective, will be reviewed by
the Maintenance Manager. The Maintenance Manager can approve or reject the
request. Approved requests will be converted into work orders.

## 10. Work Order Management

Once a maintenance request is approved, the system will create a work order. The
work order will be assigned, executed, updated, and closed through a simple
workflow.

## 11. Parts Usage on Work Orders

Users will be able to record parts used during maintenance. Parts are selected
from the SM parts catalogue. The quantity used is recorded against the work
order and submitted to SM's workflow as a part-request.

## 12. Work Order Closure

After maintenance work is completed, the work order will be closed. Closure will
capture final work notes, parts used, updated asset readings where applicable,
and final asset condition/status.

If the Work Order has an attached WO Form instance, all required form fields
(both pre-maintenance and post-maintenance values per `has_pre_post`) must be
filled before the WO can transition to completed. See [WO_FORMS.md](./WO_FORMS.md).

## 13. Asset Attachments

The system will support attachments against assets. These may include user
manuals, maintenance instructions, datasheets, safety instructions, calibration
certificates, warranty documents, photos, or other technical references.

## 14. Maintenance History

Each asset will have a maintenance history view showing preventive and
corrective Maintenance Requests, Work Orders, parts used, usage readings,
location changes (from AM tables), attachments, and closure notes. The view is
derived from those authoritative source records rather than copied into a
duplicate history table.

## 15. Dashboard and Basic Reporting

The system will include a basic operational dashboard showing pending
maintenance requests, open work orders, overdue preventive maintenance, recently
closed work orders, and assets due for maintenance.

## 16. User Roles and Access Control

The system includes **five** fixed user roles: Administrator, Maintenance
Manager, Technician, Logistics, and Requester. The legacy Viewer role has been
merged into Requester — all users are Requesters at minimum. Access is
controlled based on the user's single assigned role.

Administrators may import employees from the client's SharePoint List into a
local employee directory. Importing an employee does not grant application
access. Administrators explicitly select which employees become users, assign
one fixed role, and send an activation link so each user sets their own
password.

## 17. Account Email Delivery

Production activation and password-reset emails will be delivered through
Microsoft Power Automate. These security emails are the only external email
notifications included in MVP.

## 18. System Settings

The system will include settings for managing users, locations, asset usage
reading types, preventive maintenance rules, ERP parts sync configuration
(SM-owned), and dropdown/master-data items used across the system.

## 19. Dropdown / Master Data Management

Administrators will be able to manage genuinely-configurable dropdown values:
Maintenance Priorities (urgency levels), Usage Reading Types (meter/reading
types), and FA Subclass Type Codes (ERP classification). Asset/WO/sub-statuses
are Enum-backed state machines — configurable vocab; free-form editing there
would break workflow transitions. Locations are managed under the dedicated
Locations sidebar item, not here. See
`.kilo/plans/1783001396791-admin-lists-dropdowns-cleanup.md`.

## 20. SharePoint Portal Link

The company's SharePoint portal may contain a normal link that opens the
separately hosted product web application. The SharePoint portal can remain
available to all internal users, but portal access does not grant application
access. The backend continues to require its own Laravel login and fixed-role
authorization.

## 21. Work Order Execution Forms

Configurable pre/post-maintenance forms are captured during Work Order execution.
Each form is mapped to the asset's `fa_subclass_code` (from the
`fa_subclass_type_codes` master-data table). Forms are defined by an
Administrator using a **FormTemplate** (one active template per FA subclass),
featuring boolean, numeric (with optional display unit), and text field types.

Fields may include a `has_pre_post` flag: `true` captures both a pre-maintenance
value (entered when work starts) and a post-maintenance value (entered at
completion); `false` captures a single value.

On Work Order creation, the current template is **snapshotted (copied)** into the
WO. If the template later changes, the WO detail screen offers a **"Sync to
latest"** button with an accept/defer prompt — new fields are appended empty,
removed fields are dropped, and unchanged fields (matched by a stable per-field
`uuid`) keep their filled values.

A **completion gate** prevents a WO from transitioning `in_progress → completed`
unless all required fields are filled. For `has_pre_post` fields both pre and
post are required; for single-value fields the single value is required. The gate
applies only when a form instance exists.

Only the **Administrator** manages form templates. Pre/post values are filled by
the assigned Technician, Maintenance Manager, or Administrator.

See [WO_FORMS.md](./WO_FORMS.md) for the full specification.
