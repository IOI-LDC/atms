# ATMS User Manual

ATMS (Asset Maintenance Tracking System) is the operational maintenance
subsystem of the product family. It manages the full lifecycle of maintenance:
from identifying a fault or due service, through manager review and work order
execution, to final closure and permanent maintenance history.

ATMS is one of three subsystems that share a single backend and database:

- **ATMS** (this system) — assets, maintenance requests, work orders, and
  preventive maintenance rules.
- **SM** (Store Management) — parts catalogue, inventory, and stock movement.
- **AM** (Asset Movement) — asset location tracking and movement workflow.

Understanding which subsystem owns which data is essential to using ATMS
correctly. ATMS is the master system for assets and the operational maintenance
layer. Parts reference data is owned by SM (synced from the ERP). Asset location
is owned by AM (read by ATMS for display only).

---

## 1. Introduction

### 1.1 What ATMS Does

ATMS provides a single, clear workflow for all maintenance on operational
assets:

**Maintenance Request → Review and Approval → Work Order → Execution → Closure → Maintenance History**

There are two paths into this workflow:

- **Corrective Maintenance (CM):** A user identifies a fault, damage, or
  performance issue with an asset and manually creates a Corrective Maintenance
  Request.
- **Preventive Maintenance (PM):** The system automatically generates a
  Preventive Maintenance Request when a configured schedule (time, operating
  hours, kilometers, or other usage readings) becomes due for an asset.

Both paths converge at the Maintenance Manager review step and follow the same
Work Order lifecycle thereafter.

### 1.2 What ATMS Does Not Do

ATMS is intentionally a simple operational maintenance tracking system. The
following are explicitly excluded and remain in other systems:

- **Financial asset management** (capitalisation, depreciation, disposal
  accounting) — remains in the ERP.
- **Procurement and purchasing** — not handled in ATMS.
- **Warehouse / inventory management** — owned by the SM subsystem.
- **Parts costing and financial tracking** — parts usage is recorded
  operationally, not financially.
- **Labor tracking** — technician hours, timesheets, and productivity are not
  tracked.
- **Technician wallet / personal stock** — parts held personally by technicians
  are not tracked.
- **Asset physical movement workflow** — owned by the AM subsystem (movement
  requests, approvals, arrival confirmations happen in the AM frontend).
- **Gate passes, shipments, transport documents** — not in scope.
- **Handover management** — shift and crew handovers are excluded.
- **Advanced governance / audit campaigns** — a lightweight technical audit log
  exists, but a full governance module is excluded.
- **Advanced checklist management** — configurable **Work Order Execution Forms**
  (WO Forms) with boolean, numeric, and text fields, pre/post-maintenance
  capture per FA subclass, snapshot and sync-to-latest are now included. Advanced
  items such as mandatory photo checklists, pass/fail scoring, and
  checklist-based defect generation remain excluded.
- **Full document management system** — basic attachments are supported, but
  versioning, approvals, and document lifecycle management are excluded.
- **Native mobile application** — the system is a responsive web application.
- **QR code / barcode scanning** — asset tags are designed for future QR
  encoding, but scanning is not part of the initial release.
- **IoT / automatic meter reading** — all readings are entered manually.
- **Advanced predictive maintenance** — PM is based on simple schedules (date,
  hours, kilometers, readings), not AI or condition-based monitoring.
- **Multi-level approval workflow** — a single Maintenance Manager review step
  gates MR-to-WO conversion.
- **External workflow notifications** — only account activation and
  password-reset emails are sent (via Microsoft Power Automate).
- **Advanced reporting and BI** — basic dashboard KPIs are provided; no custom
  report builders or BI integration.
- **ERP write-back** — the system does not update the ERP with maintenance or
  financial records.
- **Offline mode** — a continuous network connection is required.
- **Multi-tenant SaaS** — the system is deployed for a single client
  environment.

### 1.3 Core Design Principles

These principles shape every feature and workflow decision in ATMS:

1. **Operational Simplicity.** The product should feel like a maintenance
   tracking tool, not an ERP clone. Screens use operational labels, avoid
   financial language, and keep the main workflow front and centre.
2. **One Main Workflow.** Everything flows through Maintenance Request →
   Approval → Work Order → Closure. There is no way to create a Work Order
   directly — this ensures every maintenance action has a documented request and
   a review step.
3. **ERP Data Is Reference Only.** Parts data displayed in ATMS comes from the
   ERP (via SM). Users should not feel they are editing official ERP master
   records. ERP-owned fields are read-only; only local operational fields may be
   edited.
4. **Statuses Are Always Visible.** Every main object — asset, maintenance
   request, work order, PM rule — displays a clear status badge showing its
   current state.
5. **Confirmation Before Every Persistent Change.** Any action that writes to
   the database or modifies a file requires explicit user confirmation through a
   dialog. This protects against accidental changes.
6. **Role-Based Access Throughout.** The sidebar, tabs, record lists, and
   available actions all adapt to the current user's role. Backend authorization
   remains the final authority — hiding a button in the UI does not replace
   server-side policy enforcement.
7. **No Deletion, Only Deactivation.** Assets, users, PM rules, and attachments
   are deactivated rather than deleted. Historical records remain intact for
   audit and reference.

---

## 2. Getting Started

### 2.1 How Accounts Are Created

ATMS does not support self-registration. All user accounts are provisioned by an
Administrator:

1. The Administrator imports employees from the client's SharePoint employee
   directory into the local employee list. Importing an employee does **not**
   grant application access — it simply makes the employee record available for
   provisioning.
2. The Administrator selects an imported employee and provisions them as a user,
   assigning one of the five fixed roles: Administrator, Maintenance Manager,
   Technician, Logistics, or Requester.
3. The system sends an activation email to the user through Microsoft Power
   Automate. This email contains a one-time activation link.
4. The user clicks the activation link, sets their own password, and gains
   access.

Administrators do not set or view user passwords. Each user sets their own
password during activation. If a user forgets their password, they can request a
password reset, which sends a new token through the same email channel.

### 2.2 Logging In

1. Open the ATMS application in your browser.
2. Enter your work email address and password.
3. If your credentials are correct and your account is active, the system sets a
   session cookie and redirects you to the Dashboard.
4. If your account has not been activated yet, you will see a message directing
   you to complete activation first.

Login is rate-limited: after 5 failed attempts within one minute from the same
IP address and email combination, further attempts are temporarily blocked.

### 2.3 Activating Your Account

When an Administrator provisions your account, you will receive an email with an
activation link. Follow these steps:

1. Click the activation link in the email, or copy the token and navigate to the
   activation page.
2. Enter your email address, the activation token, and your new password.
3. Confirm your password.
4. After successful activation, you can log in with your email and new password.

Activation tokens are one-time use and expire. Rate limiting applies: 5
activation attempts per minute.

### 2.4 Resetting a Forgotten Password

1. On the login page, select "Forgot Password."
2. Enter your email address.
3. If the email matches an active user account, the system sends a password
   reset email through Power Automate.
4. Click the link in the email.
5. Enter your email, the reset token, and a new password.
6. After a successful reset, you can log in with your new password.

### 2.5 Logging Out

Click your user avatar and name in the top-right corner of the header bar to
open the user menu, then select **Sign out**. The system invalidates your
current session. You will need to log in again to access the application.

### 2.6 Machine-to-Machine API Access

A sixth, non-human **Service** role exists for machine-to-machine (M2M) API
token authentication. This role is never assigned to human users and never logs
in through the web interface. Administrators can create API clients with specific
token abilities (read/write scopes). Service tokens are used by external systems
that need programmatic access to ATMS endpoints.

---

## 3. Your Role & What You Can Do

ATMS uses five fixed human roles. Each user has exactly one role. The legacy
Viewer role has been merged into Requester — every user is a Requester at
minimum.

Roles are seeded, immutable system data. Administrators assign roles to users
but cannot create, rename, or delete roles. The system does not support multiple
roles per user, custom permission sets, or a permission management interface.

### 3.1 Administrator

The Administrator has full access to every part of the system.

**What an Administrator can do:**

- **User Management:** Import employees from SharePoint, provision employees as
  users, assign fixed roles, activate and deactivate user accounts, force
  password resets. Cannot deactivate, reset, or edit their own account through
  admin endpoints.
- **Asset Management:** Create assets, update any asset field, change asset
  kind, set parent-child assembly relationships, manage asset maintenance status
  and sub-statuses, book and unbook assets.
- **Maintenance Workflow:** Create corrective maintenance requests, update any
  pending MR, cancel any pending MR, approve or reject MRs, assign Work Orders,
  edit execution details on non-terminal WOs (all changes audited), mark
  completed WOs, close completed WOs, cancel non-closed WOs with required
  reason, record and confirm meter readings, update asset operational status via
  WO.
- **Assembly Operations:** Install, remove, and swap components. Change asset
  kind. Directly set parent asset relationships outside of a Work Order. Create
  MRs for child components from the parent WO screen.
- **PM Rules:** Create, edit, deactivate, and reactivate PM rule templates.
  Assign templates to assets. Evaluate, deactivate, and reactivate assignments.
- **System Management:** Manage locations and master data items, manage company
  settings and display timezone, manage ERP sync settings and trigger manual
  sync, view technical audit logs, manage API clients, view raw ERP payloads.
- **Parts:** View parts catalogue. Update local part fields (name, description,
  unit, category, active status). Cannot edit ERP-owned fields.

### 3.2 Maintenance Manager

The Maintenance Manager is the operational gatekeeper of the maintenance
workflow.

**What a Maintenance Manager can do:**

- **Asset Management:** Create assets, update asset operational fields, change
  asset kind, set parent-child assembly relationships, manage asset maintenance
  status and sub-statuses, book and unbook assets.
- **Maintenance Workflow:** Create corrective MRs, update any pending MR, cancel
  any pending MR, approve MRs (creating Work Orders), reject MRs with a reason,
  assign and reassign Work Orders to active Technicians, edit execution details
  on non-terminal WOs (all changes audited), mark WOs as completed, close
  completed WOs, cancel non-closed WOs with required reason, record and confirm
  meter readings, update asset operational status via WO.
- **Assembly Operations:** Same as Administrator — install, remove, swap
  components, change asset kind, set parent relationships, create MRs for child
  components from parent WO screen.
- **PM Rules:** Assign templates to assets and evaluate, deactivate, or
  reactivate assignments — all managed from the **Asset Detail → PM Rules**
  section. **PM rule template creation and editing is Administrator-only by
  design;** Managers work with assignments rather than the template library.
- **ERP Sync:** Trigger manual ERP parts sync runs. Cannot change ERP connection
  settings or schedule.
- **Parts:** View parts catalogue. Update local part fields. Cannot edit
  ERP-owned fields.
- **User Awareness:** View user list (for WO assignment purposes). Cannot
  manage, create, or deactivate users.

**What a Maintenance Manager cannot do:**

- Manage users, employees, locations, master data, company settings, or API
  clients.
- View raw ERP payloads (receives mapped reference fields only).
- View technical audit logs.
- Manage ERP sync settings or schedule.
- Create or edit PM rule templates (Administrator-only by design).

### 3.3 Technician

The Technician executes assigned Work Orders in the field.

**What a Technician can do:**

- **Maintenance Workflow:** Create corrective MRs, update their own pending
  corrective MRs, view their assigned Work Orders, start assigned WOs (moves
  from `open` to `in_progress`), update execution details on assigned WOs before
  completion, add parts to assigned WOs from the SM catalogue, record and
  confirm meter readings, mark their assigned WOs as completed, update asset
  operational status through an assigned non-terminal WO.
- **Assembly Operations:** Install, remove, and swap components as part of
  executing an assigned non-terminal Work Order.
- **Asset Visibility:** View all assets, asset maintenance history, location
  history, assembly history, and attachments.
- **Parts:** View the SM parts catalogue for part selection on Work Orders.

**What a Technician cannot do:**

- Approve or reject Maintenance Requests.
- Cancel Maintenance Requests.
- Assign or reassign Work Orders — only Administrators and Maintenance Managers
  can assign WOs to Technicians.
- Close completed Work Orders — only Administrators and Maintenance Managers can
  close WOs.
- Cancel Work Orders — only Administrators and Maintenance Managers can cancel
  WOs.
- Create assets or edit asset master data.
- Book or unbook assets.
- Manage PM rules or assignments.
- Manage users, employees, locations, or master data.
- View technical audit logs.

### 3.4 Logistics

The Logistics role is focused on asset physical location. The movement workflow
itself (submit, approve, confirm arrival) is performed in the AM (Asset
Movement) frontend. Within ATMS, Logistics has a focused set of capabilities.

**What a Logistics user can do:**

- **Asset Visibility:** View all assets, asset location history, and assembly
  history (where they have view access to the component). View mapped ERP
  reference fields.
- **Location Updates:** Update asset physical location and book/unbook assets.
- **Maintenance Requests:** Create corrective MRs and view their own submitted
  requests (identical to the Requester role in this regard). Submit unverified
  meter readings.
- **Locations Section:** Access the dedicated Locations sidebar item for asset
  location updates.

**What a Logistics user cannot do:**

- Approve, reject, or cancel any Maintenance Request.
- View, execute, or close Work Orders.
- Manage PM rules or assignments.
- Confirm meter readings (can only submit unverified readings).
- View the Parts catalogue (Parts Management is not visible to Logistics in MVP).
- Manage users, employees, locations definitions, master data, or company
  settings.
- View technical audit logs.

### 3.5 Requester

The Requester is the base role assigned to every user. The legacy Viewer role
has been merged in — Requesters can now view asset maintenance history, location
history, and attachments.

**What a Requester can do:**

- **Maintenance Requests:** Create corrective MRs, view their own submitted MRs,
  update their own pending MRs (description, priority, and asset only), cancel
  their own pending corrective MRs.
- **Asset Visibility:** Search and view active assets for the purpose of
  creating a MR. View asset maintenance history, location history, and
  attachments. View mapped ERP reference fields.
- **Meter Readings:** Submit unverified meter readings as supporting information
  (when creating a MR or from an asset record). Cannot confirm readings.
- **Assembly History:** View assembly history for components where they have
  view access.
- **Dashboard:** View the operational dashboard.

**What a Requester cannot do:**

- Approve, reject, or cancel any MR other than their own pending corrective
  requests.
- View, create, execute, or close Work Orders (the Work Orders sidebar item is
  hidden).
- Create or edit assets.
- Book or unbook assets.
- Manage PM rules or assignments.
- Confirm meter readings.
- Access the Admin, Settings, Locations, or Parts Management sections.

---

## 4. Navigating the System

### 4.1 Application Shell

The application is built around a three-part shell:

- **Left sidebar** — role-aware navigation with eight primary items and the ATMS
  logo at the top.
- **Header bar** — runs across the top of the main content area and contains
  three controls aligned to the right:
  - **Sidebar toggle** (menu icon, left side of header) — collapses the sidebar
    to icon-only mode on desktop; on mobile, opens the sidebar as a slide-in
    sheet.
  - **User Manual button** (book icon) — opens the User Manual in a dedicated
    page. Available to all authenticated users.
  - **User menu** (your avatar initials, name, role label, and a chevron) —
    clicking opens a dropdown that shows your email address and a **Sign out**
    action.
- **Main content area** — the workspace where pages, lists, forms, and details
  are rendered. The content changes when you navigate via the sidebar, tabs, or
  drill-down links.

### 4.2 Sidebar Structure

The sidebar is a flat single-level navigation panel with eight primary items.
There are no nested dropdown menus. Each sidebar item is shown or hidden based
on your role.

The sidebar header displays the **ATMS logo** and brand mark. When collapsed,
only the logo is visible.

| #   | Sidebar Item         | Type         | Visible To                            |
| --- | -------------------- | ------------ | ------------------------------------- |
| 1   | Dashboard            | Direct Link  | Everyone                              |
| 2   | Maintenance Requests | Tabbed Group | Everyone                              |
| 3   | Work Orders          | Tabbed Group | Admin, Manager, Technician            |
| 4   | Asset Management     | Tabbed Group | Admin, Manager, Technician, Logistics |
| 5   | Parts Management     | Tabbed Group | Admin, Manager, Technician            |
| 6   | Locations            | Tabbed Group | Admin, Manager, Logistics             |
| 7   | Admin                | Tabbed Group | Admin only                            |
| 8   | Settings             | Tabbed Group | Admin only                            |

The sidebar can be collapsed to icon-only mode on desktop using the sidebar
toggle in the header bar. On mobile, the sidebar becomes a slide-in sheet. The
active sidebar item is highlighted with a background accent. For tabbed groups,
the item stays highlighted while you are on any of its tabs.

### 4.3 Tabbed Content Areas

When you click a primary sidebar item that contains sub-items (a "Tabbed
Group"), the sidebar remains static and the main content area header displays a
horizontal row of tabs. Each tab filters the content below it. Individual tabs
are shown or hidden based on your role.

Tab state is driven by URL query parameters (`?tab=active`), which means browser
back and forward buttons work correctly and you can bookmark or share links to
specific tabs.

### 4.4 Drill-Down Navigation

Detail pages for specific records — asset detail, work order detail, maintenance
request review, part detail — are accessed by clicking on a row in their
respective list screens. These drill-down pages do not appear in the sidebar.
They open as full-page views with their own sections and actions.

Screens without sidebar entries:

| Screen                     | Accessed From                               |
| -------------------------- | ------------------------------------------- |
| Asset Detail               | All Assets list, Asset Location Update list |
| Usage & Meter Readings     | Asset Detail                                |
| Location History           | Asset Detail, Asset Location Update list    |
| Maintenance History        | Asset Detail                                |
| Attachments                | Asset Detail, Part Detail                   |
| Review Maintenance Request | Pending Approval / All Requests lists       |
| Work Order Detail          | Active / Completed / Closed WO lists        |
| Part Detail                | All Parts list                              |
| Asset Assembly History     | Asset Assembly tab                          |

### 4.5 Role Visibility Summary by Section

| Section              | Requester | Technician | Logistics | Manager | Admin |
| -------------------- | --------- | ---------- | --------- | ------- | ----- |
| Dashboard            | Yes       | Yes        | Yes       | Yes     | Yes   |
| Maintenance Requests | Yes       | Yes        | Yes       | Yes     | Yes   |
| Work Orders          | —         | Yes        | —         | Yes     | Yes   |
| Asset Management     | —         | Yes        | Yes       | Yes     | Yes   |
| Parts Management     | —         | Yes        | —         | Yes     | Yes   |
| Locations            | —         | —          | Yes       | Yes     | Yes   |
| Admin                | —         | —          | —         | —       | Yes   |
| Settings             | —         | —          | —         | —       | Yes   |

---

## 5. Core Concepts

### 5.1 What Is an Asset?

An asset is any physical item that ATMS tracks for maintenance purposes. Assets
are the central entity in ATMS — every maintenance request, work order, meter
reading, and PM rule ultimately revolves around an asset. Assets are managed
fully within ATMS; there is no ERP asset source for this deployment (assets are
created manually or imported from a client-provided CSV, not synced from the
ERP).

#### The Asset Data Model

Every asset carries the following fields, stored in the `assets` table:

**Identification & Description:**

| Field | Type | Purpose |
|---|---|---|
| `erp_asset_code` | string (unique) | The asset's identifier in the client's ERP system. Links the ATMS asset to its ERP financial record. Required on creation. |
| `name` | string (required) | Human-readable name (e.g. "Mud Motor 6 3/4\" Lobe"). |
| `description` | text (nullable) | Free-text notes about the asset. |
| `category` | string (nullable) | User-defined grouping (e.g. "Downhole Tools", "Surface Equipment"). |
| `serial_number` | string (nullable) | Manufacturer serial number. Critical for asset tag generation. |
| `model` | string (nullable) | Manufacturer model designation. |
| `manufacturer` | string (nullable) | Manufacturer name or ERP vendor code. |
| `fa_subclass_code` | string (nullable, max 20) | ERP Fixed Asset subclass code — maps to the asset's accounting classification in the ERP (e.g. "MTR" for Mud Motor). This code drives asset tag type codes and WO Form template matching. Populated during ERP import; may be manually set. |

**Status Fields — Four Orthogonal Dimensions:**

An asset has **four independent status dimensions** that answer different
questions. They are stored as separate columns and operate independently — a
change to one does not automatically affect another (with the exception of
auto-clear rules for booking).

| Field | Enum | Question It Answers | Values |
|---|---|---|---|
| `operational_status` | `OperationalStatus` | **Is the asset working right now?** | `active`, `under_maintenance`, `down`, `inactive` |
| `maintenance_status` | `MaintenanceStatus` | **Is the asset in the maintenance program?** | `enrolled`, `withdrawn` |
| `maintenance_sub_status` | `MaintenanceSubStatus` | **Where is it in the program lifecycle?** | `installed`, `ready`, `lih`, `dbr`, `disposed`, `scrapped`, `other` |
| `is_booked` | boolean | **Is it reserved for a future job?** | `true` (reserved), `false` (available) |

And a fifth dimension for the asset record itself:

| Field | Type | Question It Answers |
|---|---|---|
| `is_active` | boolean | **Does the asset record exist in the active registry?** `true` = active, `false` = deactivated (hidden from lists, new actions blocked) |

**Why four separate statuses?** Each answers a different operational question:
- An asset can be `operational_status = active` (working fine) while
  `maintenance_status = enrolled` (in the program, being monitored by PM rules).
- It can be `operational_status = under_maintenance` (currently in the workshop)
  while `is_booked = true` (reserved for a job next week — booking survives
  maintenance events).
- It can be `maintenance_status = withdrawn` (removed from the program) while
  `operational_status = active` (still working, just not tracked by PM).
- `is_active = false` overrides everything — a deactivated asset is effectively
  invisible to all workflows regardless of any other status.

For detailed explanations, see:
- **Section 5.9** — Asset Operational Status (`operational_status`)
- **Section 5.4** — Asset Maintenance Status (`maintenance_status` and `maintenance_sub_status`)
- **Section 5.5** — Asset Booking (`is_booked`)

**Hierarchy & Assembly:**

| Field | Type | Purpose |
|---|---|---|
| `asset_kind` | `AssetKind` enum | Declares the asset's role in assembly: `asset` (standalone), `package` (can have children), `component` (installable). See Section 5.2. |
| `parent_asset_id` | FK → `assets.id`, nullable | If set, this asset is installed inside the parent. `NULL` = standalone, root, or spare. See Section 5.6. |
| `asset_tag` | string (max 15, unique) | Human-readable physical label. See Section 5.3. |

**ERP Reference:**

| Field | Type | Purpose |
|---|---|---|
| `erp_raw_data` | JSON (nullable) | The complete, unmodified ERP record as received during import. Hidden from API responses for non-Administrators. |
| `erp_last_synced_at` | timestamp (nullable) | When the ERP record was last refreshed. |
| `erp_status` | string (nullable) | The asset's status as recorded in the ERP (reference only, not used for ATMS logic). |

**Location (owned by AM, displayed by ATMS):**

| Field | Type | Purpose |
|---|---|---|
| `current_location_id` | FK → `locations.id`, nullable | The asset's current physical location. Updated via AM workflows or the ATMS Locations section. |

**Timestamps & Metadata:**

| Field | Type | Purpose |
|---|---|---|
| `created_at` / `updated_at` | timestamps | Standard Laravel timestamps. |
| `asset_tag_generated_at` | timestamp (nullable) | When the asset tag was auto-generated (set during initial save). |
| `asset_tag_override_reason` | text (nullable) | Audit reason if an Administrator manually changed the asset tag after creation. |

#### Asset Activation vs. Deactivation

Assets are never deleted from the database. Instead, they are **soft-deactivated**
(`is_active = false`):

- Deactivated assets are hidden from the asset registry list (they do not appear
  in "All Assets" unless explicitly filtered).
- New Maintenance Requests cannot be created for deactivated assets.
- New Work Orders cannot be created for deactivated assets.
- PM rules do not evaluate against deactivated assets.
- **All historical records are preserved:** past MRs, WOs, meter readings,
  location changes, assembly history, and attachments remain fully visible and
  searchable.

Deactivation is reversible — an Administrator or Maintenance Manager can
reactivate an asset (`is_active = true`) at any time, restoring it to the active
registry.

**Auto-clear rule:** If a deactivated asset was booked (`is_booked = true`), the
booking is automatically cleared during deactivation. You cannot reserve an asset
that has been removed from the active registry.

#### ERP Asset Code — The Bridge to Financial Records

The `erp_asset_code` is the single field that connects an ATMS asset to its
counterpart in the client's ERP (Enterprise Resource Planning) system. It serves
as a foreign key in the business sense:

- During ERP import (`php artisan import:erp-assets`), the system reads asset
  records from the ERP and creates or updates ATMS assets matched on this code.
- The code is **required** when creating an asset and must be **unique** across
  the entire system.
- The `fa_subclass_code` is extracted from the ERP record and stored on the asset
  for classification purposes — it drives asset tag type codes and WO Form
  template matching.

This code is the **only** ERP-owned identifier on the asset. All other asset
fields (name, description, status, readings, history) are managed entirely within
ATMS.

#### FA Subclass Code — Asset Classification

The `fa_subclass_code` is a short string (up to 20 characters) that classifies
the asset according to the ERP's Fixed Asset subclass taxonomy. Examples:

| Code | Meaning |
|---|---|
| `MTR` | Mud Motor |
| `MWD` | MWD/LWD Tools |
| `DHT` | Downhole Tools |
| `JRS` | Jars |
| `MEQ` | Machinery / Equipment |

The FA subclass code drives two system features:
1. **Asset Tag generation** — the 3-letter type code segment of the asset tag
   (e.g. `L-MTR-634-1234`) is derived from the FA subclass code via an Admin
   mapping table. See Section 5.3.
2. **WO Form template matching** — when a Work Order is created, the system
   checks whether the asset's FA subclass has an active `FormTemplate`. If so,
   that template is snapshotted into the WO as a WO Form. See Section 8.6a.

The mapping from FA subclass code to the 3-letter type code abbreviation is
managed in the Admin settings and is separate from the ERP import.
### 5.2 Asset Kinds

Every asset in ATMS carries an `asset_kind` that declares what role it can play in
the assembly hierarchy. An asset's kind determines whether it can be installed
inside a parent, contain children, or exist as a standalone unit. The kind also
controls which maintenance sub-statuses are available for that asset.

There are three asset kinds, defined by the `App\Enums\AssetKind` enum:

| Kind          | Database Value  | Can Have Parent? | Can Have Children? | Typical Example                                            |
| ------------- | --------------- | ---------------- | ------------------ | ---------------------------------------------------------- |
| **Asset**     | `asset`         | No               | No                 | Rotor, Stator (indivisible leaf unit)                      |
| **Package**   | `package`       | Yes              | Yes                | Motor, Power Section (can be both parent and child)        |
| **Component** | `component`     | Yes              | No                 | Radial Bearing (designed to be installed, has no children) |

#### Asset (`asset`)

A standalone, indivisible unit. An `asset`-kind asset is the default kind for all
assets in the system. It represents equipment that:

- Exists independently — it is never installed inside another asset
  (`parent_asset_id` is always `NULL`).
- Cannot contain sub-assets — it is a leaf node in any assembly hierarchy.
- Carries **no enrolled sub-status** — when enrolled in the maintenance program,
  the maintenance sub-status field is hidden in the UI because standalone assets
  do not have an installed/spare distinction.

Typical examples: a standalone pump, generator, or vehicle that is maintained as
a complete unit without interchangeable internal components tracked separately in
ATMS.

**Default and ERP import behavior:**

- The `asset_kind` column defaults to `'asset'` at the database level. All assets
  created through the ERP import command are set to `asset` kind automatically.
- If an Administrator or Maintenance Manager later determines that an imported
  asset should be treated as a package or component (for example, because it
  contains or is part of a larger assembly), they must manually update its kind
  through the asset edit form.

#### Package (`package`)

An asset that can both **contain children** and **be installed inside a parent**.
A package is the most flexible kind — it can appear at any level of the assembly
tree. A package represents a sub-assembly that is itself a maintainable unit but
can also be broken down into smaller maintainable components.

Key characteristics:

- Can have child assets (its `id` appears in other assets' `parent_asset_id`).
- Can have a parent asset (its own `parent_asset_id` may refer to another
  package).
- When enrolled, supports both sub-statuses: **Installed** (`installed`) when
  inside a parent, and **Ready** (`ready`) when available as a spare.
- A package at the top of an assembly tree (no parent) is called a **Root**.

Typical example: a "Power Section" that contains a Rotor and Stator, but is
itself installed inside a Motor. The Power Section is maintained as a unit but
its internal components are also tracked individually in ATMS.

#### Component (`component`)

An asset designed to be installed inside a parent, but which **cannot** have
children of its own. A component is always a leaf node in an assembly tree. Like
packages, components support the enrolled sub-statuses:

- **Installed** (`installed`) — currently installed inside a parent, with
  `parent_asset_id` set.
- **Ready** (`ready`) — a spare, not currently installed, with `parent_asset_id`
  set to `NULL`.

Typical example: a Radial Bearing that is installed inside a Bearing Assembly
package. The bearing is tracked individually in ATMS with its own maintenance
history, but it cannot contain further sub-assets.

#### When to use Package vs. Component

Use **`package`** when the asset needs to both contain children AND be
installable inside a parent. A package is already allowed to have a parent — the
`package` kind covers both capabilities.

Use **`component`** when the asset only needs to be installable, but will never
contain its own children. This is the simpler designation for leaf-level
replaceable parts.

Use **`asset`** when the asset stands alone and is neither installed inside
anything nor composed of tracked sub-assets.

#### How Asset Kind Interacts with Maintenance Sub-Statuses

The asset kind directly controls which enrolled sub-statuses are available in the
UI (see Section 5.4 for the full maintenance status model):

| Asset Kind     | Enrolled Sub-Statuses Available        | Parent Requirement                                     |
| -------------- | --------------------------------------- | ------------------------------------------------------ |
| `asset`        | *(none)* — sub-status field is hidden   | N/A (standalone, no parent)                            |
| `package`      | `installed`, `ready`                    | `installed` → `parent_asset_id` must be set            |
| `component`    | `installed`, `ready`                    | `installed` → `parent_asset_id` must be set            |

**Consistency rules (enforced):**

- Setting sub-status to `installed` requires `parent_asset_id` to be set — a
  component cannot be "installed" if it has no parent.
- Setting sub-status to `ready` requires `parent_asset_id` to be `NULL` — a
  component cannot be "ready" (spare) while still installed.
- Standalone assets (`asset_kind = asset`) have no sub-status at all — the field
  is hidden in the UI and carries no meaning.

#### Who Can Change Asset Kind

Asset kind is classified as a **maintenance lifecycle field**, alongside
`maintenance_status` and `maintenance_sub_status`. Only the following roles may
change an asset's kind:

| Role                | Can Change Asset Kind? |
| ------------------- | ---------------------- |
| Administrator       | Yes                    |
| Maintenance Manager | Yes                    |
| Technician          | No                     |
| Logistics           | No                     |
| Requester           | No                     |

If a user without the required role attempts to set `asset_kind` (during asset
creation or update), the backend returns a `403 Forbidden` response with the
message: *"Only administrators and maintenance managers can change lifecycle
fields."* The field is not displayed in the UI for unauthorized roles.

#### Choosing the Right Kind — Decision Guide

When creating or editing an asset, use the following decision flow to select the
correct kind:

1. **Will this asset ever be installed inside another asset?**
   - **No** → use `asset` (standalone).
   - **Yes** → continue to question 2.

2. **Will this asset ever contain sub-assets (children) that are individually tracked in ATMS?**
   - **No** → use `component` (installable leaf).
   - **Yes** → use `package` (installable container).

You can always upgrade an `asset` or `component` to `package` later if the
operational need changes — for example, if a previously standalone pump is later
designated as part of a larger skid assembly and you want to track its internal
parts individually. The reverse (downgrading a `package` to `component`) is only
possible if the asset currently has no children (`childAssets` count is zero).
### 5.3 Asset Tags

Each asset carries a unique, human-readable **asset tag** — a short code
designed for physical labelling and future QR code encoding. The format is:

```
L - BBB - CCC - XXXX
│    │      │      └─ Serial suffix: last 4 characters of the serial number
│    │      └──────── Size code: encoded inch measurement, or 000 if N/A
│    └─────────────── Type code: 3-letter abbreviation from the FA subclass
└──────────────────── Ownership: L (LDC-owned) / X (External)
```

**Segment details:**

- **Ownership (1 char):** `L` for LDC-owned assets (LDC is responsible for
  maintenance). `X` for external assets (rented, third-party, or client-owned —
  LDC is not responsible).
- **Type code (3 chars):** Derived from the ERP FA subclass code via an Admin
  mapping table. Example mappings: `MTR` for Mud Motor, `MWD` for MWD/LWD tools,
  `DHT` for Downhole Tools, `JRS` for Jars, `MEQ` for machinery/equipment.
- **Size code (3 chars):** Encoded from the first inch measurement found in the
  ERP description. `958` for 9 5/8", `634` for 6 3/4", `800` for 8", `000` if
  no physical size is discernible or the subclass is flagged as having no
  physical size.
- **Serial suffix (4 chars):** Last 4 alphanumeric characters of the serial
  number, uppercased. Padded with leading zeros if shorter than 4 characters.
  Special characters are stripped.

**Rules:**

- The system suggests a tag when an asset is created, based on the rules above.
  The Administrator reviews and can manually override before saving.
- Once saved, the tag is immutable unless explicitly overridden with an audited
  reason. This ensures the physical label always matches the digital record.
- The tag is unique across the entire system (database unique constraint).
- The tag is the primary lookup field for asset identification. A future QR code
  scan would perform a `WHERE asset_tag = ?` query.

### 5.4 Asset Maintenance Status

Each asset has an **Asset Maintenance Status** that represents its
maintenance-service state. This status is completely independent of ERP
disposal, financial treatment, capitalization, or depreciation. An asset can be
Withdrawn (`disposed`) in ATMS while still appearing in ERP financial records.

> The two states are stored as `enrolled` and `withdrawn`. They are displayed in
> the UI as **"In maintenance program"** (`enrolled`) and **"Withdrawn"**
> (`withdrawn`). (These were renamed from the former "Active"/"Inactive" so that
> maintenance status is never confused with an asset's separate _operational_
> status, which has its own "active" value.)

#### Enrolled — displayed as "In maintenance program"

The asset is in operational use and eligible for maintenance workflows:

- PM rules evaluate against enrolled assets.
- Corrective MRs can be created for enrolled assets.
- Work Orders can be created against enrolled assets.

**Enrolled sub-statuses** (for components and packages only):

| Sub-status                  | Meaning                                                                                                                     |
| --------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| _(none)_                    | Default for standalone assets. Normal operation.                                                                            |
| **Installed** (`installed`) | Component is currently installed inside a parent. `parent_asset_id` is set.                                                 |
| **Ready** (`ready`)         | Component is fully maintained and available for installation. Not currently installed (`parent_asset_id` is null). A spare. |

#### Withdrawn — displayed as "Withdrawn"

The asset is not in active maintenance service. PM rules do not evaluate against
withdrawn assets. CM and WO creation are blocked. The asset remains viewable in
the registry and its full maintenance history is preserved.

**Withdrawn sub-statuses** (purely informational — no workflow triggers):

| Sub-status                        | Meaning                                                                               |
| --------------------------------- | ------------------------------------------------------------------------------------- |
| **Lost in Hole** (`lih`)          | Physically inaccessible (e.g., downhole equipment that cannot be retrieved).          |
| **Damaged Beyond Repair** (`dbr`) | Repair is not economically or technically feasible.                                   |
| **Disposed** (`disposed`)         | Formally disposed per organizational policy (independent of ERP disposal accounting). |
| **Scrapped** (`scrapped`)         | Dismantled, sold for scrap, or otherwise removed from the operational pool.           |
| **Other** (`other`)               | Any other reason, with a free-text note for context.                                  |

**Key rules:**

- Only Administrator or Maintenance Manager may change an asset's maintenance
  status. All status changes are explicit — there are no automatic transitions
  based on maintenance events, readings, or time.
- Sub-statuses carry no business logic. "Lost in Hole" does not block PM
  evaluation (the Withdrawn parent state already does that). "Damaged Beyond
  Repair" does not trigger any workflow.
- Withdrawn assets may be re-enrolled at any time by Admin or Manager.
- `installed` requires `parent_asset_id` to be set; `ready` requires
  `parent_asset_id` to be null. These sub-statuses only apply to `asset_kind =
component` or `package`.
- Swapping a component auto-updates its sub-status: `ready` → `installed` on
  install; `installed` → `ready` on removal (or to a Withdrawn sub-status if
  decommissioned).

### 5.5 Asset Booking

Booking is an **availability marker** used by Operations to guarantee that a
specific asset is reserved for a future Job or Project. It prevents
double-allocation — ensuring an asset promised to a client is not inadvertently
reassigned or relocated.

**How booking works:**

- `is_booked` is a boolean field (`true` = reserved, `false` = freely
  available).
- Booking does **not** gate any maintenance workflow. A booked asset can still
  have MRs created, WOs opened, and PM triggered against it.
- Booking is completely independent of both operational status and maintenance
  status. A booked asset can be active, under maintenance, or down.
- No Job/Project/client reference is stored in ATMS — "what" the asset is booked
  for lives in the external Job/Project system.
- Booking auto-clears (`is_booked = false`) when:
  - The asset's location changes (via any path).
  - The asset is deactivated (`is_active = false`).
  - The asset's maintenance status becomes Withdrawn (`withdrawn`).
- Booking survives maintenance events (WO creation, completion, closure) — only
  location change or inactivation releases it.

**Who can toggle booking:**

| Role                | Book | Unbook |
| ------------------- | ---- | ------ |
| Administrator       | Yes  | Yes    |
| Maintenance Manager | Yes  | Yes    |
| Logistics           | Yes  | Yes    |
| Technician          | No   | No     |
| Requester           | No   | No     |

### 5.6 Asset Assembly (Packages, Components, and Parent-Child Relationships)

> **Related:** See Section 5.2 for the full definition of each asset kind
> (`asset`, `package`, `component`) and the rules that govern which assets can
> have parents and children.

Some assets are composed of other assets. For example, a mud motor contains a
power section, which itself contains a rotor and stator. Each component in the
assembly is a **full ATMS asset** with its own MRs, WOs, readings, and history.
This is not a passive parts list — it is a hierarchy of independently
maintainable assets.

**Key concepts:**

| Term          | `asset_kind` Value | Definition                                                                                                      |
| ------------- | ------------------ | --------------------------------------------------------------------------------------------------------------- |
| **Asset**     | `asset`            | A single indivisible unit. Cannot contain sub-assets. Leaf node only.                                           |
| **Package**   | `package`          | An asset that can contain child assets. A package may also be installed as a component inside a larger package. |
| **Component** | `component`        | An asset that can be installed inside a parent. Cannot contain children.                                        |
| **Root**      | `package`          | A package with no parent — sits at the top of an assembly tree.                                                 |

These are not mutually exclusive — a Power Section is both a Package (contains
Rotor + Stator) and a Component (installed inside the Motor).

**Example assembly tree:**

```
Motor (Package, Root)
 ├── Power Section (Package, Component)
 │    ├── Rotor (Asset, Component)
 │    └── Stator (Asset, Component)
 └── Bearing Assembly (Package, Component)
      ├── Radial Bearing (Asset, Component)
      └── Thrust Bearing (Asset, Component)
```

**Assembly rules:**

- One parent at a time. `parent_asset_id` is a single nullable foreign key.
- Swap is explicit: remove old component (sets `parent_asset_id` to null, closes
  its assembly history row), install new component (sets `parent_asset_id` to
  the parent, inserts a new history row). Both happen in a single operation.
- Cycle prevention: a component's parent cannot be itself or any of its own
  descendants. Enforced in application logic.
- Spare components: a component with `parent_asset_id = null` and
  `maintenance_sub_status = ready` is available for installation.

**How assembly interacts with maintenance:**

- **One WO per asset.** A parent asset's WO triggers the swap and updates
  component statuses. If a removed component needs its own maintenance
  (refurbishment), a separate Corrective MR must be created on that component.
- **Component operating hours** are derived, not stored. The system calculates
  runtime by comparing the parent's current confirmed reading against the
  reading at the time the component was installed. When removed, total
  accumulated runtime for that installation period is stored in the assembly
  history row.
- **Independent PM schedules.** Parent PM and component PM run independently.
  Both parent and component each have their own PM rules, and each generates its
  own MR when due. The parent's 500-hr PM does not auto-create a component MR.
- **Cross-check at parent service.** When a parent WO is open, the detail screen
  displays all child components with a PM status indicator:
  - 🟢 **OK** — well within PM interval.
  - 🟡 **Soon** — approaching PM interval.
  - 🔴 **Due / Overdue** — at or past interval.

  The Technician or Manager may decide to act on yellow/red items while the
  asset is already in the workshop. A "Create MR for Component" action is
  available (Admin/Manager only) for any yellow/red component. This is a manual
  human decision, not an automatic cascade.

- **Cumulative maintenance:** When a higher-level PM Work Order closes (e.g.,
  L2, L3, or L4), the baselines of all active lower-level PM assignments on the
  same asset are reset. This maintains cumulative maintenance alignment — closing
  L3 resets L1 and L2 baselines. This applies only to standard L1-L4 levels;
  custom free-text levels are independent.

### 5.7 Parts Data Ownership

Parts displayed in ATMS belong to the **SM (Store Management)** subsystem. ERP
syncs parts into SM tables on a scheduled basis (weekly) or when manually
triggered by an Admin or Manager.

**What ATMS can do with parts:**

- Read parts from SM tables to populate Work Order part-request forms.
- Display ERP reference fields (part code, status) as read-only data.
- Update local operational fields: `name`, `description`, `unit_of_measure`,
  `category`, `is_active` (Admin/Manager only).

**What ATMS cannot do with parts:**

- Edit ERP-owned fields: `erp_part_id`, `erp_part_code`, `erp_status`,
  `erp_raw_data`, `erp_last_synced_at`. These are managed exclusively by the ERP
  sync process.
- Manage stock quantities, inventory, valuation, or warehouse operations — all
  owned by SM.
- View raw ERP payloads (Administrator only).

### 5.8 Asset Location Data Ownership

Asset current location and location history are owned by the **AM (Asset
Movement)** subsystem. Within ATMS:

- The asset's current location is displayed for reference.
- Location history is readable.
- The dedicated Location sidebar section allows certain roles to update asset
  location directly (via `POST /api/assets/{asset}/location`).

The full movement workflow — submit movement request, logistics approval, and
arrival confirmation — takes place in the AM frontend, not ATMS. The Logistics
role within ATMS can read asset locations and update them directly from the
Locations section, but the formal movement approval chain belongs to AM.

### 5.9 Asset Operational Status

Every asset carries an `operational_status` — a field that answers one simple
question: **is the asset working right now?**

This is distinct from maintenance status (is it enrolled in the program?) and
booking (is it reserved?). Operational status describes the asset's **current
functional state** — whether it is available for use, currently being repaired,
broken and awaiting repair, or permanently retired from service.

Operational status is defined by the `App\Enums\OperationalStatus` enum with four
values:

| DB Value | Display Label | Meaning |
|---|---|---|
| `active` | **Active** | The asset is fully operational and available for normal use. |
| `under_maintenance` | **Under Maintenance** | The asset is currently in the workshop being serviced. Work is in progress. |
| `down` | **Down** | The asset has a known fault or failure and is not operational. It is waiting to be repaired. |
| `inactive` | **Inactive** | The asset has been permanently retired, decommissioned, or removed from the operational pool. It will not be used again. |

#### How Operational Status Changes

Operational status is driven primarily by **Work Order events**. The system
automatically transitions the asset's operational status at key points in the WO
lifecycle. In addition, authorized users can manually set the status at any time
during a WO.

##### Automated Transitions

The `ApplyWorkOrderAssetStatusTransition` action runs at these lifecycle points:

| Event | Target Status | Logic |
|---|---|---|
| **Corrective MR approved** → WO created | `down` | A corrective request means someone reported a fault — the asset is now confirmed as faulty. **Skip if** the asset is already `under_maintenance` (e.g., a concurrent PM is in progress). **Preventive MRs do not trigger this** — a scheduled service does not mean the asset was broken. |
| **WO started** (`open` → `in_progress`) | `under_maintenance` | Work has begun in the workshop. The asset is now being actively serviced. **Always applied** — this transition is not conditional. |
| **WO closed** (`completed` → `closed`) | `active` | The work is done and reviewed — the asset should be fully operational again. **Skip if** the asset is already `active` (no change needed) or `inactive` (never auto-reactivate a retired asset). |
| **WO cancelled** | Caller-chosen | When cancelling a WO, the user must decide: is the asset still faulty? Choose `down` if the fault remains, or `active` if the WO was a false alarm. |

##### Manual Override

At any time while a WO is `open` or `in_progress`, an authorized user can
manually set the asset's operational status via the "Update Asset Status" action
on the WO detail screen. This calls `POST /api/work-orders/{wo}/asset-status`.

**Who can manually set operational status via WO:**

| Role | Can Set Status? |
|---|---|
| Administrator | Yes — any non-closed, non-cancelled WO |
| Maintenance Manager | Yes — any non-closed, non-cancelled WO |
| Assigned Technician | Yes — their own assigned WO only |
| Logistics | No |
| Requester | No |

Manual override is blocked on closed and cancelled WOs (the WO is terminal and no
further changes are permitted).

**What happens on manual override:**
- The asset's `operational_status` is immediately updated to the chosen value.
- The change is written to the technical audit log.
- The previous and new values are recorded.
- No other asset fields are affected.

##### No Direct Editing Outside a WO

Operational status cannot be changed through the asset edit form. The
`operational_status` field is not exposed in the asset create/update endpoints
(`POST /api/assets`, `PATCH /api/assets/{asset}`). The only paths to change
operational status are:
1. Automated WO lifecycle transitions (approve → down, start → under_maintenance,
   close → active, cancel → chosen).
2. Manual override through the WO's "Update Asset Status" action.

This design ensures operational status always reflects a maintenance event —
there is always a WO that explains *why* the status changed.

#### How Operational Status Differs from Other Status Fields

This is a common point of confusion. Here is the complete distinction:

| Question | Field | Example |
|---|---|---|
| **Is the asset working right now?** | `operational_status` | `under_maintenance` — it's in the workshop |
| **Is the asset in the maintenance program?** | `maintenance_status` | `enrolled` — PM rules are watching it |
| **What's its lifecycle sub-state?** | `maintenance_sub_status` | `installed` — it's inside a parent assembly |
| **Is it reserved for a future job?** | `is_booked` | `true` — Operations has promised it to a client |
| **Does it exist in the active registry?** | `is_active` | `true` — it appears in the asset list |

**Real-world scenario combining them all:**

> Motor MTR-001 has an active PM rule (500-hr service). A Requester notices
> unusual vibration and creates a Corrective MR. The Manager approves it → a WO
> is created and the asset is automatically set to `operational_status = down`
> (a fault was reported). The Technician starts the WO → asset becomes
> `under_maintenance`. During the WO, the Technician discovers a worn bearing and
> replaces it. The WO is closed → asset transitions to `operational_status =
> active`.
>
> Throughout this entire process, `maintenance_status` remained `enrolled` (the
> asset never left the maintenance program) and `is_booked` may have been `true`
> the whole time (booking survives maintenance events — the Operations team still
> expects this motor for a job next week).

#### Inactive — The Terminal Operational State

`operational_status = inactive` is the only terminal operational state. It means
the asset has been permanently removed from service:

- It appears in the registry but with a clear "Inactive" badge.
- It cannot have new Maintenance Requests created against it.
- It cannot have new Work Orders created against it.
- PM rules stop evaluating it.
- All historical records (MRs, WOs, readings, attachments) are preserved.

**How an asset becomes inactive:**
- A user sets `inactive` via the WO "Update Asset Status" action during a
  decommissioning WO.
- The automated WO lifecycle transitions will **never** set an asset to
  `inactive` — this must be a deliberate human decision.
- The automated `close → active` transition **skips** assets already at
  `inactive` — the system will never accidentally reactivate a retired asset.

**How to reactivate an inactive asset:**
- Set the status to `active` via a WO's "Update Asset Status" action.
- This requires a deliberate decision — the system will not do it automatically.

#### Operational Status vs. Maintenance Status — Why Both Exist

`operational_status` is **transient** — it changes frequently as WOs are created,
started, and closed. It reflects what's happening *right now*.

`maintenance_status` is **persistent** — it reflects a long-term management
decision about whether the asset participates in the maintenance program.

An asset can be:
- `operational_status = active` AND `maintenance_status = enrolled` — working,
  being monitored by PM (normal state).
- `operational_status = under_maintenance` AND `maintenance_status = enrolled` —
  in the workshop, but still in the program. PM rules still watch it but won't
  fire because there's an active WO.
- `operational_status = active` AND `maintenance_status = withdrawn` — working
  fine, but the organization decided to stop tracking it for maintenance (perhaps
  it's being sold or transferred).
- `operational_status = inactive` AND `maintenance_status = withdrawn` — retired
  and removed from the program entirely.

---

## 6. Dashboard

The Dashboard — titled **Maintenance Control Center** — is the landing page for
all authenticated users. It provides a comprehensive operational overview with
reliability analytics, process metrics, action-required alerts, and quick-access
controls.

**Visible to:** Everyone.

**Route:** `/dashboard`

### 6.1 Page Layout

The Dashboard uses a two-column split layout:

- **Left panel (main workspace):** Contains the analytics KPIs section and
  detailed data cards (action-required lists and activity tracking).
- **Right panel (command center sidebar):** Contains role-driven quick actions
  and live operational status tiles.

A "Refresh" button in the page header lets you reload all dashboard data
manually. A window indicator shows the analysis time frame (e.g., "Last 90
days") with the actual date range.

### 6.2 Analytics & Reliability KPIs

The top of the dashboard displays six Key Performance Indicators organized into
two groups. These KPIs are calculated over a rolling **90-day window** and are
visible to all roles. Metrics that cannot be computed due to insufficient data
display a dash (—), never zero.

#### Reliability Performance

| KPI              | Description                                                                                                                                | Unit  |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------ | ----- |
| **MTBF**         | Mean Time Between Failures — the average interval between corrective maintenance events during the window. Calculated on a calendar basis. | days  |
| **MTTR**         | Mean Time To Repair — the average clock time from WO creation through closure for corrective Work Orders completed in the window.          | hours |
| **Failure Rate** | Total number of corrective failures (closed corrective WOs) in the window, expressed as a count and a per-day average.                     | count |

#### Process Efficiency

| KPI                 | Description                                                                                                                    | Unit  |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------ | ----- |
| **PM Compliance**   | Percentage of Preventive Maintenance assignments completed on time (WO closed by or before the PM due date) during the window. | %     |
| **Avg MR Duration** | Average elapsed time from Maintenance Request creation to its final resolution (conversion, rejection, or cancellation).       | hours |
| **Avg WO Duration** | Average elapsed time from Work Order creation to closure for WOs closed during the window.                                     | hours |

### 6.3 Action Required

Role-adaptive data cards appear in the left workspace when you have items
requiring attention. Each card shows a count badge and a scrollable list of
items. Cards only appear when the backend determines your role has relevant
items — absence of a card means none apply.

| Card                             | Content                                                                                           | Link Target                                  |
| -------------------------------- | ------------------------------------------------------------------------------------------------- | -------------------------------------------- |
| **Pending Maintenance Requests** | MRs in `pending_review` status awaiting Manager review.                                           | MR detail page (`/maintenance/requests/:id`) |
| **Open Work Orders**             | WOs in `open` or `in_progress` status. Assigned WOs for Technicians; all WOs for Managers/Admins. | WO detail page (`/work-orders/:id`)          |
| **Overdue PM Assignments**       | PM assignments that have passed their due date or reading threshold.                              | Asset detail page (`/assets/:id`)            |

### 6.4 Activity & Tracking

Two additional data cards provide visibility into recent operational events:

| Card                            | Description                                                                                                 | Data Source        |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------- | ------------------ |
| **Recently Relocated Assets**   | Assets whose physical location changed in the last 90 days, showing the from → to route and effective date. | KPI endpoint       |
| **Recently Closed Work Orders** | Work Orders closed within the last 30 days, showing WO number, asset, assignee, and closure date.           | Dashboard endpoint |

### 6.5 Command Center (Right Sidebar)

The right sidebar provides two panels:

#### Actions Hub

A set of role-driven shortcut buttons that link directly to frequently used
screens. Each action routes to the same default tab as the sidebar navigation
item for that section.

| Action          | Visible To                            | Target                                     |
| --------------- | ------------------------------------- | ------------------------------------------ |
| **Assets**      | Admin, Manager, Technician, Logistics | `/assets?tab=all-assets`                   |
| **New MR**      | Everyone                              | `/maintenance` (new request form)          |
| **Locations**   | Admin, Manager, Logistics             | `/locations?tab=asset-location-update`     |
| **Work Orders** | Admin, Manager, Technician            | `/work-orders` (role-adaptive default tab) |

#### Operational Status

Live KPI tiles displayed as compact stat cards. Each tile shows an icon, label,
and count, and acts as a direct link to the corresponding filtered view — for
example, clicking "Pending MR" navigates to `/maintenance?tab=pending-approval`.

| Tile                 | Visible When                   | Links To                            |
| -------------------- | ------------------------------ | ----------------------------------- |
| **Pending MR**       | Pending MR summary key present | `/maintenance?tab=pending-approval` |
| **Open Work Orders** | Open WO summary key present    | `/work-orders?tab=open`             |
| **Overdue PM Rules** | Overdue PM summary key present | `/admin/pm-rules`                   |

Tiles only appear when the corresponding summary data is available for your role
— the backend omits keys entirely for roles that should not see them.

### 6.6 Role Filtering

Dashboard data is role-filtered. Each role sees counts and lists relevant to
their responsibilities. For example:

- **Technician** sees Work Orders assigned to them; Pending MRs are filtered to
  their role-appropriate view.
- **Maintenance Manager** sees all pending MRs requiring review and all active
  WOs across the team.
- **Requester** sees their own submitted MRs and a limited set of dashboard
  widgets relevant to their permissions.
- **Logistics** sees location-related activity but no Work Order data.

The analytics KPIs (Section 6.2) and relocated-assets feed are the same for
every role — they represent system-wide operational health over the 90-day
window.

---

## 7. Maintenance Requests

The Maintenance Requests section is the entry point to the maintenance workflow.
It manages both Corrective (user-initiated) and Preventive (system-generated)
requests. Every Work Order in ATMS originates from a Maintenance Request — there
is no way to create a WO directly.

**Sidebar:** Tabbed Group — visible to everyone.

**Route:** `/maintenance`

### 7.0 The Maintenance Request Data Model

Before diving into workflows, it helps to understand the fields that make up a
Maintenance Request, stored in the `maintenance_requests` table:

**Core Fields:**

| Field | Type | Purpose |
|---|---|---|
| `number` | string | Auto-generated sequence: `MR-XXXXXX` (6-digit zero-padded). Generated by `BusinessNumberSequence`. Globally unique. |
| `type` | string | `corrective` (user-created) or `preventive` (system-generated from a PM rule). |
| `asset_id` | FK → assets | The asset this maintenance request is for. Required. |
| `description` | text (required) | What is wrong, observed symptoms, context. |
| `priority` | string | One of `low`, `medium`, `high`, `critical`. Affects ordering in lists and signals urgency to the Manager. |
| `status` | `MaintenanceRequestStatus` enum | Current state in the review lifecycle. See Section 7.3. |
| `created_by` | FK → users | Who submitted the MR (user for CM, system user for PM). |

**PM-Specific Fields (only populated for preventive MRs):**

| Field | Type | Purpose |
|---|---|---|
| `is_preventive` | boolean | Canonical flag: `true` = system-generated PM, `false` = user-created CM. |
| `pm_rule_id` | FK → pm_rules | Which PM rule generated this request. |
| `triggered_by_date` | boolean | Was the date threshold crossed when this MR was generated? |
| `triggered_by_reading` | boolean | Was the reading threshold crossed when this MR was generated? |
| `trigger_date` | date | The trigger date at generation time (for audit). |
| `trigger_reading_value` | decimal | The trigger reading value at generation time (for audit). |

**Decision Fields (populated when the MR is reviewed):**

| Field | Type | Purpose |
|---|---|---|
| `reviewed_by` | FK → users | Who approved or rejected the MR. |
| `reviewed_at` | timestamp | When the decision was made. |
| `rejection_reason` | text | Required when status = `rejected`. |
| `cancelled_by` | FK → users | Who cancelled the MR. |
| `cancelled_at` | timestamp | When the cancellation occurred. |
| `cancellation_reason` | text | Reason for cancellation (optional for CM, may be required for PM). |

### 7.1 Corrective Maintenance (CM) Workflow

Corrective Maintenance is for assets that are faulty, damaged, underperforming,
or require repair. Any authenticated user, regardless of role, may create a
Corrective Maintenance Request.

**Step-by-step:**

1. **User identifies an issue.** An asset is not working correctly, shows
   damage, or needs attention.
2. **User creates a Corrective MR** from the "New Request" tab. Required fields:
   - **Asset** — select from active assets using the searchable asset selector.
   - **Issue description** — what is wrong, observed symptoms, context.
   - **Priority** — how urgent the request is (see Section 7.3a).
   - **Location** — where the asset is currently located (read from AM).
   - **Supporting notes** — any additional detail.
   - **Supporting reading** — optional unverified meter reading (Requester can
     submit; readings from Admin, Manager, or Technician may be confirmed
     immediately).
3. **MR enters `pending_review` status.** It now appears in the Manager's
   "Pending Approval" tab.
4. **While `pending_review`,** the MR can be edited (description, priority, and
   asset only) by its creator or an Admin/Manager. Edits do not change the MR
   status.
5. **Maintenance Manager reviews the MR.** The Manager evaluates: is this a
   valid maintenance request? Is the priority correct? Is it ready for a Work
   Order?
6. **Manager makes a decision:**
   - **Approve** → the MR is atomically converted to a Work Order. MR status
     becomes `converted`. A new WO is created with status `open`. The MR's
     priority is copied to the WO. For corrective MRs, the asset's
     `operational_status` is automatically set to `down` (unless already
     `under_maintenance`).
   - **Reject** → the MR status becomes `rejected` (terminal). A reason is
     required.
   - **Cancel** → the request is withdrawn. MR status becomes `cancelled`
     (terminal).

**Who can cancel a Corrective MR:**

- The creator can cancel their own pending corrective MR while it is
  `pending_review`.
- Administrator or Maintenance Manager can cancel any pending MR.
- Once an MR is approved and converted to a WO, it cannot be cancelled. The Work
  Order cancellation workflow must be used instead.

### 7.2 Preventive Maintenance (PM) Workflow

Preventive Maintenance is system-initiated based on configured PM rules and
assignments. Users do not manually create PM requests. For a complete walkthrough
of the PM system — including rule templates, assignments, baselines, trigger
types, evaluation, suppression, and cumulative maintenance — see **Section 12**.

**How PM requests are generated (summary):**

1. A **PM Rule template** is configured by an Administrator (schedule
   definition, asset-agnostic).
2. An Administrator or Maintenance Manager **assigns** the template to a
   specific ATMS-managed asset. This seeds the asset's own baseline — the
   starting point from which intervals are measured.
3. The system runs **daily evaluation** (scheduled job at 06:00 Africa/Tripoli)
   of all active PM assignments. An assignment is evaluated only if **both** the
   assignment and its parent template are active.
4. When criteria are met (date threshold reached, reading threshold reached, or
   whichever comes first for `date_or_reading` rules), the system checks for an
   **active maintenance chain**. An active chain exists when:
   - A `pending_review` MR already exists for the same asset + template, or
   - A converted WO in `open`, `in_progress`, or `completed` status exists.
5. If no active chain exists and the threshold is met, the system creates one
   Preventive Maintenance Request with `type = preventive`, `is_preventive =
   true`, and `pm_rule_id` set. The MR follows the same Manager review and WO
   lifecycle as a corrective MR.
6. The Maintenance Manager reviews the PM request the same as any other MR. They
   may approve (creating a WO), reject, or cancel.

**PM rejection and cancellation — suppression rules:**

- Both rejecting and cancelling a preventive MR create an **occurrence
  suppression record**. This prevents the system from immediately regenerating
  the same PM request on the next daily evaluation.
- `suppressed_until_date` and `suppressed_until_reading` are set according to
  the PM trigger type and the decision.
- For `date_or_reading` rules:
  - If only the date dimension generated the request, a date suppression
    boundary is required.
  - If only the reading dimension generated the request, a reading suppression
    boundary is required.
  - If both dimensions became due in the same evaluation, both suppression
    boundaries are required.
- A future occurrence may be generated only when its due date or reading is
  beyond the recorded suppression boundary.

**Preventive MR cancellation:**

- Administrator or Maintenance Manager may cancel a pending preventive MR.
- The creator of a system-generated MR is the system itself — Requesters cannot
  cancel preventive MRs.
- If the originating MR was preventive and the resulting WO is later cancelled
  (rather than the MR), **no suppression is created**. The PM assignment
  continues to be evaluated normally. To suppress PM, the MR must be rejected or
  cancelled before WO creation.

### 7.3 Maintenance Request Statuses

The `MaintenanceRequestStatus` enum defines four states:

| Status           | DB Value          | Meaning                                                                                                                   | Terminal? |
| ---------------- | ----------------- | ------------------------------------------------------------------------------------------------------------------------- | --------- |
| **Pending Review** | `pending_review` | Submitted/generated and awaiting Manager review. Creator or Admin/Manager may edit description, priority, and asset.      | No        |
| **Converted**      | `converted`      | Approved and atomically converted into exactly one Work Order. There is no separate stored "approved" status — the MR's `converted` status and the WO's existence are created in the same database transaction. | Yes       |
| **Rejected**       | `rejected`       | Declined by the Maintenance Manager with a required reason. For preventive MRs, creates a suppression record to prevent immediate regeneration. | Yes       |
| **Cancelled**      | `cancelled`      | Withdrawn while awaiting review, before approval and conversion. Once approved and converted, the MR cannot be cancelled — the WO cancellation workflow must be used instead. For preventive MRs, creates a suppression record. | Yes       |

**Status transition diagram:**

```
                   ┌─────────────┐
                   │ pending_review│
                   └──────┬──────┘
                          │
          ┌───────────────┼───────────────┐
          │               │               │
          ▼               ▼               ▼
   ┌──────────┐   ┌──────────┐   ┌───────────┐
   │ converted │   │ rejected  │   │ cancelled  │
   │ (terminal)│   │ (terminal)│   │ (terminal) │
   └─────┬─────┘   └──────────┘   └───────────┘
         │
         ▼
   Work Order created (status: open)

Once approved, the MR is permanently read-only. The only way to reverse the
approval is to cancel the resulting Work Order — which cancels the WO, not the
MR. The MR remains at `converted`.
```

**Rules enforced at each transition:**

- **Approve:** MR must be `pending_review`. Asset must have `maintenance_status =
  enrolled`. The MR and WO are created in one atomic transaction — you cannot
  have an approved MR without a WO.
- **Reject:** MR must be `pending_review`. `rejection_reason` is required. For
  preventive MRs, suppression fields (`suppressed_until_date` and/or
  `suppressed_until_reading`) must be provided based on which trigger dimension
  fired.
- **Cancel:** MR must be `pending_review`. For preventive MRs, suppression
  fields are required (same validation as reject). For corrective MRs,
  suppression is not applicable.

### 7.3a Maintenance Request Priorities

The priority field communicates urgency to the Maintenance Manager. There is no
dedicated priority enum — it is a plain string with four accepted values:

| Value | Display Label | Typical Use |
|---|---|---|
| `low` | **Low** | Minor issues, cosmetic defects, non-urgent improvements. Can wait. |
| `medium` | **Medium** | Standard maintenance. Default for all auto-generated PM requests. |
| `high` | **High** | Significant performance issue, impending failure, urgent attention needed. |
| `critical` | **Critical** | Immediate safety hazard, production stopped, mission-critical failure. |

Priority is set by the person creating the MR (for corrective requests) or
defaults to `medium` for system-generated preventive requests. When the MR is
approved, the priority is copied to the resulting Work Order. The Manager may
adjust priority during review by editing the MR before approving.

There is no automated escalation or SLA based on priority — it is an
informational and sorting field that helps the Manager prioritise their review
queue.

### 7.4 Maintenance Request Tabs

The Maintenance Requests page has four tabs, shown/hidden by role:

| Tab                  | Visible To     | Content                                                                           |
| -------------------- | -------------- | --------------------------------------------------------------------------------- |
| **New Request**      | Everyone       | Side-sheet form for creating a new Corrective MR.                                 |
| **My Requests**      | Everyone       | All MRs created by the current user.                                              |
| **Pending Approval** | Admin, Manager | All MRs with status `pending_review`. Row actions: Approve, Reject, Cancel, Edit. |
| **All Requests**     | Admin, Manager | Every MR regardless of status, with search and filters.                           |

### 7.5 Maintenance Request Detail (Drill-Down)

Clicking an MR row opens the full-page Review Maintenance Request screen. This
is the primary workspace for the Maintenance Manager during the review step.

**Sections displayed:**

- Request overview (MR number, type PM/CM, status, priority, dates).
- Asset details (tag, name, category, current location, maintenance status).
- Request description and supporting notes.
- Origin information: created by (user or system), PM rule trigger details where
  applicable.
- Attachments (fault photos, supporting documents).

**Actions available (role and status dependent):**

- **Approve & Create Work Order** — visible when MR is `pending_review` and user
  is Admin or Manager.
- **Reject Request** — visible when MR is `pending_review` and user is Admin or
  Manager. Requires a reason (and suppression boundaries for PM).
- **Cancel Request** — visible when MR is `pending_review` and user is the
  creator (corrective only) or Admin/Manager.
- **Edit** — visible when MR is `pending_review` and user is the creator or
  Admin/Manager. Editable fields: description, priority, and asset.

Once an MR moves to a terminal status (`converted`, `rejected`, `cancelled`),
all actions are hidden and the record becomes read-only.

### 7.6 Design Rationale for the MR Workflow

**Why can't Work Orders be created directly?**
Requiring a Maintenance Request before every Work Order ensures every
maintenance action has a documented reason and a review step. This prevents
unapproved work, creates a complete audit trail, and ensures the Maintenance
Manager has visibility into all maintenance activity. The single-step Manager
approval (rather than multi-level approval) keeps the process fast and simple
for operational staff.

**Why is approval atomic (MR → WO in one step)?**
When a Manager approves an MR, a Work Order is created immediately in the same
transaction. There is no intermediate "approved but waiting" state. This
eliminates a status that would create confusion and a gap where an approved MR
could be forgotten before WO creation.

**Why can Requesters cancel only their own corrective MRs?**
A Requester who created a CM by mistake should be able to withdraw it. But
system-generated preventive MRs require a Manager's judgment to cancel, since
the system determined the maintenance is due based on configured rules. Allowing
any user to cancel a PM request would undermine the preventive maintenance
program.

**Why must preventive rejections and cancellations create suppression records?**
Without suppression, the next daily evaluation would immediately detect the
asset is still due and generate another identical MR. Suppression tells the
scheduler "this specific occurrence has been reviewed and decided upon — don't
regenerate it." The next occurrence will fire only when the asset crosses a
future due threshold.

## 8. Work Orders

Work Orders are the execution phase of the maintenance workflow. Every Work
Order is created from an approved Maintenance Request. There is no path to
create a Work Order directly — the MR → WO link is the only entry point and it
is **atomic** (the MR approval and WO creation happen in a single database
transaction).

**Sidebar:** Tabbed Group — visible to Admin, Manager, Technician.

**Route:** `/work-orders`

### 8.0 The Work Order Data Model

Work Orders are stored in the `work_orders` table. Key fields:

| Field | Type | Purpose |
|---|---|---|
| `number` | string | Auto-generated: `WO-XXXXXX` (6-digit zero-padded). Generated by `BusinessNumberSequence`. Globally unique. |
| `maintenance_request_id` | FK → maintenance_requests | **The origin MR.** Each WO has exactly one parent MR. This is the immutable 1:1 link — you can always trace a WO back to its originating request. |
| `asset_id` | FK → assets | The asset being worked on (copied from the MR). |
| `status` | `WorkOrderStatus` enum | Current state. See Section 8.1. |
| `priority` | string | Copied from the MR at creation. Values: `low`, `medium`, `high`, `critical`. |
| `description` | text | Work description (copied from the MR, may be updated during execution). |
| `assigned_to` | FK → users, nullable | The Technician responsible for executing the WO. Required before the WO can start. |
| `assigned_at` | timestamp, nullable | When the WO was assigned. |
| `started_at` | timestamp, nullable | When work began (`open` → `in_progress`). |
| `completed_at` | timestamp, nullable | When the Technician submitted completion. |
| `closed_at` | timestamp, nullable | When the Manager finalized the WO. |
| `cancelled_at` | timestamp, nullable | When the WO was cancelled. |
| `cancellation_reason` | text, nullable | Required reason when status = `cancelled`. |
| `execution_details` | text, nullable | Technician's work notes, findings, actions taken. Editable while non-terminal. All changes audited. |
| `created_at` / `updated_at` | timestamps | Standard Laravel timestamps. |

### 8.1 Work Order Lifecycle

Work Orders follow a strict lifecycle with five states, defined by the
`WorkOrderStatus` enum:

| DB Value | Display Label | Meaning |
|---|---|---|
| `open` | **Open** | Created from an approved MR. May be unassigned. Awaiting assignment and work commencement. |
| `in_progress` | **In Progress** | Work has started. Must be assigned to an active Technician. |
| `completed` | **Completed** | Technician has submitted all work. Awaiting Manager review and closure. |
| `closed` | **Closed** | Reviewed and finalized by Manager. **Permanently immutable.** |
| `cancelled` | **Cancelled** | Cancelled by Manager with required reason. Terminal and read-only. |

**Complete transition diagram:**

```
                          ┌──────────┐
                          │   open    │
                          └─────┬─────┘
                                │
                   ┌────────────┼────────────┐
                   │            │            │
                   ▼            ▼            ▼
            ┌───────────┐  assign       cancelled
            │ cancelled  │  technician    (terminal)
            │ (terminal) │     │
            └───────────┘     ▼
                        ┌─────────────┐
                        │ in_progress  │
                        └──────┬───────┘
                               │
                   ┌───────────┼───────────┐
                   │           │           │
                   ▼           ▼           ▼
            ┌───────────┐ complete   cancelled
            │ cancelled  │    │       (terminal)
            │ (terminal) │    ▼
            └───────────┘ ┌───────────┐
                          │ completed  │
                          └─────┬──────┘
                                │
                      ┌─────────┼─────────┐
                      │         │         │
                      ▼         ▼         │
               ┌──────────┐ cancelled    │
               │  closed   │ (terminal)  │
               │ (terminal)│             │
               │ PERMANENT │             │
               └──────────┘             │
                                        │
                   (closed can NEVER be reopened,
                    edited, cancelled, or transitioned)
```

**Rules for each transition:**

| Transition | Who Can Do It | Conditions |
|---|---|---|
| `open` → assign | Admin/Manager | Assignee must be an active user with Technician role |
| `open` → `in_progress` | Assigned Technician | Must be assigned first. Asset `operational_status` → `under_maintenance`. |
| `open` → `cancelled` | Admin/Manager | `cancellation_reason` required |
| `in_progress` → `completed` | Assigned Technician | All required WO Form fields must be filled. Technician fields locked after. |
| `in_progress` → `cancelled` | Admin/Manager | `cancellation_reason` required |
| `completed` → `closed` | Admin/Manager | **Side effects run** (see Section 8.5). Asset `operational_status` → `active` (unless already `active` or `inactive`). |
| `completed` → `cancelled` | Admin/Manager | `cancellation_reason` required. Asset `operational_status` set to caller-chosen value. |

### 8.2 Work Order Assignment

- Only Administrators and Maintenance Managers can assign or reassign Work
  Orders.
- A WO may be assigned only to an active user with the Technician role.
- Assignment is required before the WO can transition to `in_progress`.
- Reassignment may occur while the WO is `open` or `in_progress`.
- Assignment is tracked: `assigned_to`, `assigned_at`, and the assignment
  history is audited.

### 8.3 Work Order Execution

During `in_progress`, the assigned Technician can:

- **Update execution details** — work notes, findings, actions taken.
- **Add parts used** — select parts from the SM catalogue and record quantities.
  This submits a part-request into SM's ordering workflow.
- **Remove parts** — delete part lines that were added in error.
- **Record readings** — submit and confirm meter readings against the asset.
- **Update asset operational status** — set the asset's `operational_status`
  through the WO-scoped endpoint (`POST /api/work-orders/{wo}/asset-status`).
- **Perform assembly operations** — install, remove, or swap components as part
  of the WO.
- **Upload attachments** — completion photos, repair evidence, supporting
  documents.

**After completion** (`completed` status):

- Technician execution fields, parts, readings, and attachments are locked.
- The Technician can no longer edit the WO.
- Only Admin or Manager can close or cancel the WO.

**Execution detail edits by Admin/Manager:**

- Administrator and Maintenance Manager may edit execution details on
  non-closed, non-cancelled WOs for operational recovery.
- Every execution-detail change by any role is written to the technical audit
  log with redacted before/after context.

### 8.4 Work Order Part Recording

Parts used on a WO are selected from the SM parts catalogue:

1. From the WO detail screen, the Technician (or Admin/Manager) opens the "Add
   Part" form.
2. They search and select a part from the SM catalogue.
3. They enter the quantity used.
4. The part line is recorded against the WO.

This creates an operational usage record. The part-request submission flows into
SM's order/stock workflow for fulfilment.

Parts can be added or removed at any time before the WO is closed. After
closure, part lines are permanently locked.

### 8.5 Work Order Closure — Side Effects

Closure is the final review step where the Manager confirms the work is complete
and the system finalizes all records. The following happens **atomically** when
a WO is closed (all in one database transaction):

**1. WO Status Finalized:**
- WO status → `closed` (permanently immutable).
- `closed_at` timestamp set.
- All WO fields, parts, readings, and attachments permanently locked.

**2. Asset Operational Status Updated:**
- Asset's `operational_status` → `active` (the work is done, the asset should
  be operational again).
- **Skip if** the asset is already `active` (no change needed) or `inactive`
  (never auto-reactivate a retired asset).
- This means: close a WO on a `down` asset → it becomes `active`. Close a WO on
  an `under_maintenance` asset → it becomes `active`.

**3. PM Baseline Reset (if the WO originated from a Preventive MR):**
- The `AssetPmAssignment` that generated the PM MR has its baseline reset:
  - `last_triggered_date` = today (the closure date)
  - `last_triggered_reading` = latest confirmed reading at closure time
- This restarts the PM interval — the next due date/reading is calculated from
  this new baseline.

**4. Cumulative Maintenance Cascade (if applicable):**
- If the closed WO's PM rule has a standard level (`L1`-`L4`), all **lower-
  level** active assignments on the **same asset** also have their baselines
  reset.
- Example: closing an L3 WO resets L1 and L2 baselines (prevents redundant L1
  MR the day after an L3 overhaul).
- Custom free-text levels are independent and do not cascade.

**5. Audit Log Entry:** The closure is recorded with the closing user, timestamp,
and affected fields.

**Closure steps for the Manager:**
1. The WO must be in `completed` status — the Technician has submitted all work.
2. A Maintenance Manager or Administrator opens the WO.
3. They review: work notes, parts used, readings updated, final asset status.
4. They select "Close Work Order" and confirm.
5. The WO becomes `closed` — permanently immutable.

Only Administrators and Maintenance Managers can close WOs. Technicians complete
the work but cannot close — this separation ensures a second set of eyes reviews
the work before it is finalized.

### 8.6 Work Order Cancellation

Cancellation is a terminal decision available to Administrator and Maintenance
Manager only. A required reason must be provided. Cancellation is available from
`open`, `in_progress`, or `completed` status — but not from `closed`.

**On cancellation:**
- WO status → `cancelled` (terminal, read-only).
- `cancellation_reason` and `cancelled_at` recorded.
- Asset `operational_status` set to a caller-chosen value: `down` (the fault
  still exists, a new MR will be needed) or `active` (the WO was a false alarm,
  the asset was never actually faulty).

**PM interaction:** If the originating MR was a preventive request, cancelling
the WO does **not** automatically suppress future PM occurrences — the PM
assignment continues to be evaluated normally. To suppress PM, the MR must be
rejected or cancelled **before WO creation**, which creates a suppression
record.

Technicians cannot cancel Work Orders.

### 8.7 Work Order Execution Form (WO Form)

When a Work Order is created for an asset, the system checks whether the asset's
FA subclass (`fa_subclass_code`) has an active **FormTemplate**. If so, the
template is snapshotted (copied) into the WO as a **WO Form**. If no active
template exists, the WO has no form and execution proceeds normally.

**What the form captures:**

The form contains fields of three types: **boolean** (true/false), **numeric**
(with an optional display unit such as PSI, °C, or hours), and **text**. Each
field has a `has_pre_post` flag:

- **`has_pre_post = true`** — captures a **pre-maintenance value** (entered when
  work starts, at `in_progress`) and a **post-maintenance value** (entered at
  completion). Example: "Mud motor hours reading" — record hours before and
  after work.
- **`has_pre_post = false`** — captures a **single value** (entered during
  execution). Example: "Did you clean the item thoroughly?"

Some fields are marked **required** (`is_required = true`). These fields must be
filled before the WO can be completed.

**When pre and post values are captured:**

- **Pre-maintenance values** are filled when the WO transitions to `in_progress`
  (after the Technician starts work). Only fields with `has_pre_post = true`
  have a pre-value input.
- **Post-maintenance values** are filled at completion time (before transitioning
  to `completed`). For `has_pre_post = true` fields, a post-value input appears
  alongside the already-filled pre value. For `has_pre_post = false` fields, the
  single value is entered here.

**Who can fill the form:**

| Role                | Can fill pre/post values? |
| ------------------- | ------------------------- |
| Administrator       | Yes — any WO              |
| Maintenance Manager | Yes — any WO              |
| Technician          | Yes — assigned WO only    |
| Logistics           | No                        |
| Requester           | No                        |

**Sync-to-latest:**

If an Administrator updates the FormTemplate after the WO's form was snapshotted,
the WO detail screen displays a **"Sync to latest"** banner. You can accept (the
WO's form is merged with the latest template: matching fields keep their values,
new fields appear empty, removed fields are dropped) or defer (the banner stays;
you can act later).

**Completion gate:**

The WO **cannot** transition from `in_progress` to `completed` unless all
required form fields are filled:

- For `has_pre_post = true` fields: both pre and post values must be present.
- For `has_pre_post = false` fields: the single (post) value must be present.

Optional fields (`is_required = false`) may be left empty. The gate applies only
when the WO has an attached form — WOs without a form are unaffected.

After the WO transitions to `completed`, all form fields become read-only.

### 8.8 Work Order Status Summary

| Status        | Meaning                                                           | Editable By                                                                               | Terminal? |
| ------------- | ----------------------------------------------------------------- | ----------------------------------------------------------------------------------------- | --------- |
| `open`        | Created from approved MR. May be unassigned.                      | Manager (assign, edit exec details), Technician (after assignment)                        | No        |
| `in_progress` | Work has started. Must be assigned to an active Technician.       | Assigned Technician (exec details, parts, readings, status), Admin/Manager (exec details) | No        |
| `completed`   | Technician has submitted all completion info. Awaiting closure.   | Admin/Manager (edit exec details, close, cancel)                                          | No        |
| `closed`      | Reviewed and finalized by Admin or Manager. PM baselines updated. | No one — permanently immutable                                                            | Yes       |
| `cancelled`   | Cancelled by Admin or Manager with required reason.               | No one — terminal and read-only                                                           | Yes       |

### 8.9 Work Order Tabs

| Tab                 | Visible To                 | Content                                                               |
| ------------------- | -------------------------- | --------------------------------------------------------------------- |
| **My Work Orders**  | Technician only            | WOs assigned to the current Technician.                               |
| **All Work Orders** | Admin, Manager             | Every WO regardless of status or assignment, with search and filters. |
| **Active**          | Admin, Manager, Technician | WOs with status `open` or `in_progress`.                              |
| **Completed**       | Admin, Manager, Technician | WOs with status `completed` (awaiting closure).                       |
| **Closed**          | Admin, Manager, Technician | WOs with status `closed` (read-only).                                 |

### 8.10 Work Order Detail (Drill-Down)

Clicking a WO row opens the full-page Work Order Detail screen. Sections:

- **Overview** — WO number, related MR, asset, status badge, priority, dates.
- **Assignment** — current assignee, assignment history.
- **Work Notes** — Technician's execution details and findings.
- **Parts Used** — part lines with part code, name, quantity. Add/Remove actions
  (while WO is non-terminal, role-permitted).
- **Attachments** — completion photos, repair evidence.
- **Readings** — updated meter readings recorded during the WO.
- **Asset Status** — final asset operational status set through the WO.
- **Component cross-check** (for parent assets) — child component PM status
  indicators (🟢 OK / 🟡 Soon / 🔴 Due).

**Actions (role and status dependent):**

- **Assign** — visible on `open` WOs for Admin/Manager.
- **Start Work** — visible to assigned Technician on `open` WOs.
- **Complete** — visible to assigned Technician on `in_progress` WOs.
- **Close** — visible to Admin/Manager on `completed` WOs.
- **Cancel** — visible to Admin/Manager on non-closed WOs.
- **Add Part** — visible to assigned Technician (or Admin/Manager) on
  non-terminal WOs.
- **Set Asset Status** — visible to assigned Technician (or Admin/Manager) on
  non-terminal WOs.

### 8.11 Design Rationale for the WO Workflow

**Why must a WO be assigned before it can start?**
Assignment creates clear accountability — one named Technician is responsible
for the work. This prevents WOs from being worked on without anyone taking
ownership, and allows the Manager to track who is doing what.

**Why can't Technicians close Work Orders?**
Closing is a review and finalization step. Requiring a Manager or Admin to close
ensures a second set of eyes validates that the work was completed properly,
parts were recorded, and the asset status is correct. The Technician completes
the work; the Manager closes it.

**Why are closed WOs permanently immutable?**
Closure finalizes maintenance history and updates PM baselines. Allowing a
closed WO to be reopened would create cascading inconsistencies in PM
scheduling, maintenance history, and audit trails. If work was done incorrectly,
a new Corrective MR should be raised — the original WO remains as a permanent
record of what happened.

**Why can WOs be cancelled from `completed` status?**
A WO might be marked complete by a Technician, but the Manager may review it and
determine the work should not have been done, was done on the wrong asset, or is
otherwise invalid. Cancellation from `completed` provides an escape path before
final closure, with a required audit reason.

**Why does closing a WO reset the asset to `active`?**
The act of closing a WO means the maintenance work is finished and reviewed. The
default assumption is that the asset is now operational. If the asset should
remain `down` (e.g. the WO was for a partial repair and more work is needed), a
new Corrective MR should be created — this keeps the audit trail clear: one WO
closed it, another MR documents the remaining fault.

**Why does a corrective MR approval set the asset to `down`?**
When someone reports a fault and a Manager approves the resulting MR, the system
assumes the fault is real. Setting the asset to `down` signals that it needs
attention. This is skipped for preventive MRs — a scheduled service doesn't mean
the asset was broken.
---

## 9. Asset Management

The Asset Management section is the asset registry and operations hub.

**Sidebar:** Tabbed Group — visible to Admin, Manager, Technician, Logistics.

**Route:** `/assets`

### 9.1 All Assets Tab

The "All Assets" tab displays the full asset registry with search and filters.

**Visible to:** Admin, Manager, Technician, Logistics.

**Columns:** asset tag, name, category, maintenance status badge ("In maintenance
program" / "Withdrawn" with sub-status), current location, latest confirmed usage reading, PM status
indicator, asset kind badge (Asset / Package / Component), parent asset reference
(for components).

**Row actions:**

- **Add Asset** — Admin/Manager only. Opens create form (side sheet). Required
  fields: name, description, category, serial number, model, manufacturer,
  operational status, asset maintenance status, asset tag (suggested, editable).
- **Edit Asset** — Admin/Manager only. Opens edit form per row.

**For Requesters:** When a Requester views the Assets section (through the
sidebar, though the current role visibility matrix shows this section as hidden
for Requesters), they see a reduced view for the purpose of creating Corrective
Maintenance Requests — basic active-asset information without maintenance
history, location history, attachments, or raw ERP details.

### 9.2 Asset Detail (Drill-Down)

Clicking an asset row opens the full-page Asset Detail screen.

**Route:** `/assets/:assetId`

**Sections (implemented as tabs or sections within the detail page):**

- **Overview** — name, tag, description, category, serial, model, manufacturer,
  asset kind, parent asset (if component), maintenance status and sub-status,
  operational status.
- **ERP Reference Data** — mapped ERP fields (read-only reference). Raw ERP
  payload visible to Administrator only.
- **Current Physical Location** — read from AM tables.
- **Usage & Meter Readings** (`/assets/:assetId/readings`) — capture and view
  readings. Fields: reading type, value, date/time, entered by, confirmed by
  (when confirmed). Unconfirmed readings show an "Unverified" indicator.
- **Location History** (`/assets/:assetId/location-history`) — timeline of past
  physical location changes.
- **Maintenance History** (`/assets/:assetId/maintenance-history`) — assembled
  read-model of all MRs, WOs, parts used, readings, and closure notes for this
  asset.
- **PM Assignments** — PM rules assigned to this asset with per-asset PM status
  (🟢🟡🔴), schedule, last triggered, next due, and per-row actions (evaluate,
  deactivate/reactivate). "Assign Rule" opens a picker of active templates.
- **Attachments** (`/assets/:assetId/attachments`) — upload, view, download
  asset attachments.
- **Assembly** (`/assets/:assetId/assembly`) — for packages: child component
  list, install/remove/swap actions, component PM status indicators. For
  components: parent reference, installation history, accumulated runtime.

### 9.3 Asset Assembly Tab

**Visible to:** Admin, Manager, Technician, Logistics.

**Content:**

- Component list with PM status indicators (green 🟢 / yellow 🟡 / red 🔴).
- **Install Component** action — side sheet to search and select a spare
  component (must be enrolled with sub-status `ready`, `parent_asset_id IS NULL`).
- **Remove Component** action — dialog with reason field and post-removal
  disposition (`ready`, or a Withdrawn sub-status: `dbr`, `disposed`, `scrapped`).
- **Swap Component** action — remove old + install new in one atomic operation.
- **"Create MR for Component"** action — available for yellow/red components on
  parent WO screen (Admin/Manager only).
- **Assembly history timeline** for the selected component — every
  install/removal event with dates, users, and accumulated runtime.

**Who can perform assembly operations:**

| Operation                     | Admin | Manager | Technician       | Others |
| ----------------------------- | ----- | ------- | ---------------- | ------ |
| Install component             | Yes   | Yes     | Assigned WO only | No     |
| Remove component              | Yes   | Yes     | Assigned WO only | No     |
| Swap component                | Yes   | Yes     | Assigned WO only | No     |
| Create MR for child component | Yes   | Yes     | No               | No     |

### 9.4 Meter Readings

Meter readings track asset usage — operating hours, kilometers, depth, or any
other measurable value that accumulates over the asset's life. These readings
are the foundation of reading-based Preventive Maintenance: without confirmed
readings, the PM engine cannot determine when a usage-triggered service is due.

#### The Meter Reading Data Model

Meter readings are stored in the `asset_meter_readings` table:

| Field | Type | Purpose |
|---|---|---|
| `asset_id` | FK → assets | The asset this reading belongs to. |
| `usage_reading_type_id` | FK → `usage_reading_types` | What kind of reading this is (hours, kilometers, etc.). |
| `reading_value` | decimal(12,2) | The numeric reading value. |
| `reading_at` | datetime | When the reading was taken (the timestamp on the meter). |
| `source` | string | Always `user` (manually entered) or `manual`. |
| `entered_by_user_id` | FK → users | Who recorded the reading. |
| `confirmed_by_user_id` | FK → users, nullable | Who confirmed the reading. `NULL` = unverified. |
| `confirmed_at` | datetime, nullable | When the reading was confirmed. `NULL` = unverified. |
| `notes` | text, nullable | Optional commentary. |
| `maintenance_request_id` | FK, nullable | Links to the originating MR, if the reading was submitted during MR creation. |

#### Reading Types

Reading types are configurable via the Admin → Lists & Dropdowns section. They
are stored in the `usage_reading_types` table, not as a code enum — this allows
operators to define custom meter types without code changes. Seed data includes:

| Name | Unit | Typical Use |
|---|---|---|
| Operating Hours | hours | Tracks running time for PM intervals (e.g. "every 500 hours") |
| Kilometer Driven | kilometer | Tracks distance for vehicle fleet PM |
| Depth | meter | Tracks drilling depth for downhole equipment |

#### The Reading Lifecycle — Two Phases

Every meter reading goes through two distinct phases:

**Phase 1: Recorded (Unverified)**

When a reading is first submitted (`POST /api/assets/{asset}/meter-readings`):
- `entered_by_user_id` is set to the submitter
- `confirmed_by_user_id` and `confirmed_at` remain `NULL`
- The reading is marked **"Unverified"** in the UI
- **Who can record:** Administrator, Maintenance Manager, Technician, Requester
- Unverified readings can be edited or soft-deleted by Admin, Manager, or
  Technician (Requesters cannot edit or delete)
- Unverified readings do **not** update the asset's current meter value
- Unverified readings are **not** used by PM evaluation

**Phase 2: Confirmed**

When a reading is confirmed (`POST /api/assets/{asset}/meter-readings/{id}/confirm`):
- `confirmed_by_user_id` is set to the confirmer
- `confirmed_at` is set to the current timestamp
- The reading becomes **immutable** — it can no longer be edited or deleted
- **Who can confirm:** Administrator, Maintenance Manager, Technician (NOT
  Requester, NOT Logistics)
- Confirmed readings update the asset's current meter value
- Confirmed readings feed into PM evaluation and due-date calculation

**Why two phases?** The unverified → confirmed flow allows Requesters to submit
supporting readings when creating MRs without those readings immediately
affecting PM schedules. A Technician or Manager must review and confirm the
reading before it becomes "official." This prevents accidental or incorrect
readings from corrupting PM schedules.

#### Monotonic Enforcement — Why Readings Can Only Go Up

When confirming a reading, the system enforces two constraints against the
**latest confirmed reading** for the same asset and reading type:

1. **Value constraint:** `new_reading_value >= latest_confirmed_value`
2. **Date constraint:** `new_reading_at >= latest_confirmed_reading_at`

If the new reading is lower than the latest confirmed value, the system rejects
it with a `DomainException`. If the new reading's timestamp is earlier than the
latest confirmed timestamp, it is also rejected.

**Why this constraint exists:** PM evaluation calculates due-ness by comparing
the current reading against the baseline: `latest_confirmed_reading >=
last_triggered_reading + interval_reading`. If readings could decrease, the PM
engine would never fire again after a rollback — the reading would appear to be
below the threshold. Monotonic enforcement guarantees that PM calculations are
always forward-moving.

**What if a reading was entered incorrectly?** You cannot edit or delete a
confirmed reading. Instead:
1. Record a **new** reading with the correct (higher) value.
2. Add a note explaining the correction.
3. An Administrator may add an audit note for documentation.
4. The incorrect reading remains in the history — the chain of readings is
   preserved for audit.

#### Recording a Reading

1. Navigate to an asset's detail page and open the Usage & Meter Readings
   section (or enter a reading while creating a Corrective MR).
2. Select the reading type (operating hours, kilometers, etc.).
3. Enter the reading value and reading date/time.
4. Submit. The reading is created in **unverified** state.

#### Confirming a Reading

1. Open the asset's meter readings list. Unverified readings show an
   "Unverified" indicator.
2. Click "Confirm" on the unverified reading.
3. The system validates the monotonic constraints.
4. On success, the reading becomes confirmed and feeds into PM evaluation.

#### How Meter Readings Feed PM Evaluation

The PM evaluation engine (`PmDueCalculator`) uses only **confirmed** readings
when checking if a reading-triggered PM rule is due. Specifically:

- For `reading` and `date_or_reading` trigger types, the calculator queries the
  latest confirmed reading where `confirmed_at IS NOT NULL` for the asset's
  matching reading type.
- If no confirmed reading exists, the PM rule will **never** fire — the system
  cannot determine due-ness without a usage baseline.
- This is why it's critical to regularly record and confirm readings for assets
  with reading-based PM rules.

#### Key Rules Summary

- Confirmed readings are append-only and monotonically non-decreasing per asset
  and reading type.
- Unverified readings can be edited or soft-deleted; confirmed readings are
  permanently immutable.
- Only confirmed readings affect PM evaluation and asset current meter values.
- Corrections to confirmed readings require a new valid (higher) reading with
  explanatory notes.
- Readings submitted during Corrective MR creation are unverified by default
  (even from Admin/Manager/Technician — the MR submission endpoint creates
  unverified readings; confirmation is a separate step).
- Soft-deleted readings are preserved in the database with `deleted_at` set but
  excluded from all queries and lists.
### 9.5 Asset Location History

Asset current location and location history are owned by the AM subsystem. ATMS
displays the current location and a timeline of past location changes on the
asset detail screen.

An "Update Location" action is available (Logistics, Manager, Admin) from the
Asset Detail screen or the dedicated Locations section.

### 9.6 Asset Attachments

Attachments can be uploaded against assets from the asset detail screen. Types
include:

- User manuals
- Maintenance instructions
- Safety instructions
- Calibration certificates
- Warranty documents
- Datasheets
- Photos

**Upload rules:**

- Maximum file size: 20 MB per file.
- Allowed document types: PDF, Microsoft Word, Microsoft Excel.
- Allowed image types: JPEG, PNG, WebP.
- Executables, scripts, disk images, and archive formats are rejected.
- Each attachment stores: original filename, stored path, MIME type, file size,
  category, description, uploader, and timestamp.

**Download and deletion:**

- Downloads go through authorized backend routes (not direct file paths).
- Attachments are soft-deleted — metadata is retained with `deleted_by_user_id`
  and `deleted_at`. Deleted attachments are excluded from normal lists and
  download access is rejected.
- No UI for restoring deleted attachments in MVP.

---

## 10. Parts Management

The Parts Management section provides a view of the SM (Store Management) parts
catalogue. Parts displayed in ATMS originate from the ERP system and are synced
into SM tables. ATMS reads this data for display and Work Order part-request
forms but does **not** manage inventory, stock levels, or purchasing.

**Sidebar:** Tabbed Group — visible to Admin, Manager, Technician.

**Route:** `/parts`

### 10.0 Parts Data Ownership — ERP vs. Local Fields

Parts in ATMS have two categories of fields with different ownership:

**ERP-Owned Fields (read-only in ATMS, managed by the ERP sync process):**

| Field | Purpose |
|---|---|
| `erp_part_id` | The part's unique identifier in the ERP system. |
| `erp_part_code` | The human-readable ERP part number displayed in the UI. |
| `erp_status` | The part's status as recorded in the ERP (e.g. "Active", "Obsolete"). Reference only. |
| `erp_raw_data` | The complete, unmodified ERP record as JSON. Only visible to Administrators. |
| `erp_last_synced_at` | Timestamp of the last successful sync for this part. |

**Local Fields (editable in ATMS by Admin/Manager):**

| Field | Purpose |
|---|---|
| `name` | Human-readable part name (may be updated locally for operational clarity). |
| `description` | Additional notes or usage instructions. |
| `unit_of_measure` | The unit used when recording quantities (e.g. "each", "meter", "liter"). |
| `category` | User-defined grouping for filtering and search. |
| `is_active` | Whether the part is available for selection in WO part-request forms. Inactive parts are hidden from pickers. |

This split reflects the design principle: **ERP data is reference only.** Users
should not feel they are editing official ERP master records. The ERP sync
process is the single source of truth for ERP-owned fields; ATMS may only
augment with operational context.

### 10.1 All Parts Tab

Displays the SM parts catalogue with search and filters.

**Visible to:** Admin, Manager, Technician.

**Columns:** ERP part code, part name, unit of measure, ERP status, category.
Row action: link to part detail.

Inventory quantities, stock valuation, and warehouse operations are not visible
in ATMS — those belong to the SM subsystem.

### 10.2 Part Request Tab

A convenience link into the SM (Store Management) subsystem's "New Request"
flow. Allows users to order parts from the SM catalogue without leaving the ATMS
system.

**Visible to:** Admin, Manager, Technician.

This is a cross-subsystem integration point. The actual part-request form and
ordering workflow are owned by SM.

### 10.3 Part Detail (Drill-Down)

**Route:** `/parts/:partId`

**Sections:**

- ERP reference data (part code, status — read only).
- Local fields (name, description, unit, category) — editable by Admin/Manager.
- ERP raw data — visible to Administrator only.
- Attachments — datasheets, fitting instructions, safety sheets, compatibility
  notes, usage instructions.

### 10.4 ERP Parts Sync — How Parts Enter ATMS

Parts are not created in ATMS. They flow from the ERP into the SM subsystem via
a scheduled or manually triggered sync process:

**Scheduled Sync:**
- **Frequency:** Weekly, every Monday at 03:00 Africa/Tripoli timezone.
- **Scope:** All parts in the ERP catalogue are synced into SM tables.
- **Behavior:** New parts are inserted. Existing parts (matched on `erp_part_id`)
  have their ERP-owned fields updated. Local fields (`name`, `description`,
  `unit_of_measure`, `category`) are **never** overwritten by the ERP sync — they
  are preserved as edited in ATMS.
- **Concurrency:** Overlap prevention ensures only one sync runs at a time.

**Manual Sync:**
- Triggered by Administrator or Maintenance Manager from Admin → System &
  Integration tab.
- Runs the same sync logic as the scheduled job.
- Useful when new parts have been added to the ERP and you need them immediately
  (e.g., for an urgent Work Order).

**Sync Job Statuses:**

| Status | Meaning |
|---|---|
| `running` | Sync job is currently executing. |
| `success` | Sync completed with no errors. All parts processed. |
| `partial` | Sync completed but some items had errors (e.g., malformed ERP data). Affected items are skipped; others are synced. |
| `failed` | Sync could not complete (e.g., ERP connection failure, authentication error). No parts were updated. |

**Sync History:** Viewable by Administrator in Admin → System & Integration tab.
Each sync run shows start/end timestamps, status, and error details (if any).

**Design rationale — why local fields survive ERP sync:** The ERP is the
authority for part identity (code, status). But operational staff in ATMS may
need to rename a part for clarity or add usage notes. Overwriting local edits on
every sync would erase this operational context. The sync updates ERP-owned
fields only, preserving local enrichments.

## 11. Locations

The Locations section provides a dedicated workspace for asset location
management.

**Sidebar:** Tabbed Group — visible to Admin, Manager, Logistics.

**Route:** `/locations`

### 11.1 Asset Location Update Tab

Search and select an active asset, view its current location and location
history, and update its physical location.

**Visible to:** Admin, Manager, Logistics.

**How it works:**

1. The list displays all active assets with their current location.
2. Each row shows: asset tag, name, category, current location, latest usage
   reading, maintenance status badge.
3. Click "Update Location" on any row to open the `UpdateLocationSheet` (side
   sheet).
4. The side sheet shows:
   - **Asset** — pre-populated, read-only (tag + name).
   - **Current Location** — read-only context.
   - **New Location** — select from the active locations list (required).
   - **Effective Date** — datetime, defaults to now (required).
   - **Reason** — text (optional).
   - **Notes** — textarea (optional).
5. Submit → confirmation dialog → the system executes the location update.
6. On success, the location history is updated and the list refreshes.
7. If the asset was booked, booking auto-clears on location change.
8. A "View Location History" link per row navigates to the asset's location
   history drill-down.

**Important:** This is a direct location update — no approval chain. The formal
AM movement workflow (submit → approve → confirm arrival) takes place in the AM
frontend. The "Asset Location Update" in ATMS is a direct administrative update
for scenarios where the full movement workflow is not needed.

### 11.2 Manage Locations Tab

Full CRUD for location definitions.

**Visible to:** Admin only.

**Location list columns:** name, type, code, parent location, active status,
created date.

**Create Location** (side sheet): name (required), type (required), code
(optional), parent location (optional, self-referencing hierarchy for nested
locations), description (optional).

**Edit Location** (side sheet): same fields, all optional.

**Activate / Deactivate:** Toggle `is_active`. Deactivated locations remain in
history but are excluded from the "Asset Location Update" location picker.

Uses the existing admin locations endpoints. The same location definitions
appear in the Admin → Lists & Dropdowns tab.

---
## 12. Preventive Maintenance Rules

Preventive Maintenance (PM) is the system's mechanism for automatically
scheduling recurring maintenance work based on time or usage. Instead of relying
on someone to remember that an asset is due for service, you configure a PM rule
once and the system monitors the asset continuously, generating a Maintenance
Request when service is needed.

PM rules are the only path to system-generated maintenance — every Preventive
Maintenance Request in ATMS originates from a configured PM rule. Understanding
how rules, assignments, baselines, and evaluation work together is essential to
running an effective maintenance program.

### 12.1 How Preventive Maintenance Works — The Full Lifecycle

A PM rule goes through these stages from configuration to completion:

```
1. CREATE TEMPLATE       Admin defines a reusable schedule (e.g. "every 500 hrs")
         │
2. ASSIGN TO ASSET       Admin/Manager links the template to a specific asset,
         │               setting the baseline (starting point for the interval)
         │
3. DAILY EVALUATION      06:00 Africa/Tripoli each day, the system checks all
         │               active assignments. Is the asset past its due threshold?
         │
4. GENERATE MR           If due and no active maintenance chain exists,
         │               the system creates a Preventive Maintenance Request
         │
5. MANAGER REVIEW        MR enters `pending_review`. Manager approves (creates
         │               a WO), rejects (creates a suppression record), or cancels
         │
6. WORK ORDER            Approved MR → WO created. Technician executes the work,
         │               records parts, readings, and completion details
         │
7. CLOSURE & RESET       Manager closes the WO. The PM baseline resets — the
                         interval restarts from the closure date/reading.
                         Lower-level PMs on the same asset also reset.
```

Each stage is explained in detail in the sections below.

### 12.2 PM Rule Templates

A **PM Rule template** is an asset-agnostic schedule definition stored in the
`pm_rules` table. Think of it as a reusable recipe — you define it once and
assign it to as many assets as you need. Changing the template does not
automatically change existing assignments, but new assignments will use the
updated schedule.

Templates are managed exclusively by **Administrators** from the Admin → PM Rules
tab. Maintenance Managers can assign templates to assets and manage assignments,
but cannot create or edit the templates themselves.

#### Template Fields

| Field | Type | Description |
|---|---|---|
| **Name** | string (required) | A descriptive label, e.g. "Motor 500-hr Inspection" |
| **Description** | text (optional) | Notes about the purpose and scope of this PM |
| **Trigger Type** | enum (`date`, `reading`, `date_or_reading`) | How the system decides this PM is due (see Section 12.3) |
| **Interval Days** | integer | For `date` and `date_or_reading` triggers: the calendar interval in days |
| **Interval Reading** | decimal | For `reading` and `date_or_reading` triggers: the usage increment (e.g. 500 hours) |
| **Reading Type** | FK → `usage_reading_types` | For `reading` and `date_or_reading` triggers: which meter to watch (e.g. "Operating Hours") |
| **Maintenance Level** | string (optional) | One of `L1`, `L2`, `L3`, `L4` (standard), or a free-text custom level. See Section 12.8 |
| **Active** | boolean | Whether the template is currently in use. Inactive templates stop generating PM work for all assignments |

#### Template Lifecycle

Templates are never deleted — the "no deletion" principle applies. Instead:

- **Create:** `POST /api/pm-rules` — Administrator-only. Define the schedule.
- **Edit:** `PATCH /api/pm-rules/{id}` — Administrator-only. Existing assignments keep their current baselines regardless of template edits.
- **Deactivate:** `POST /api/pm-rules/{id}/deactivate` — Stops generating new PM work. Existing assignments remain on record but are no longer evaluated. Assignment status is preserved — if the template is later reactivated, assignments resume from where they left off.
- **Reactivate:** `POST /api/pm-rules/{id}/reactivate` — Resumes evaluation for all assignments.

**Guard:** A template cannot be deactivated if any of its assignments has an
active maintenance chain (a `pending_review` MR or an open/in-progress/completed
WO). This prevents deactivating a template while maintenance work is in flight.

### 12.3 PM Trigger Types — How the System Decides "It's Time"

The trigger type is the core logic of a PM rule. It determines when the system
considers the asset due for service. There are three types, defined by the
`PmTriggerType` enum.

#### Date-Based (`date`)

The PM is due when a calendar interval elapses since the last service.

**How it works:** The system compares today's date against
`last_triggered_date + interval_days`. If that date has passed, the PM is due.

**Example:** A "Motor Quarterly Inspection" with `interval_days = 90`. If the
motor was last serviced on January 1st, it becomes due on April 1st. If
evaluation runs on April 2nd, the system generates an MR.

**When to use:** Maintenance that depends on calendar time — regulatory
inspections, seasonal servicing, certification renewals.

#### Reading-Based (`reading`)

The PM is due when the asset accumulates a certain amount of usage.

**How it works:** The system compares the asset's latest **confirmed** meter
reading against `last_triggered_reading + interval_reading`. If the reading has
crossed that threshold, the PM is due.

**Example:** A "Motor 500-hr Service" with `interval_reading = 500.00`. If the
motor was last serviced at 1,000 hours, it becomes due when the confirmed
hour-meter reading reaches or exceeds 1,500 hours.

**Important:** Reading-based triggers only work if the asset has confirmed meter
readings. If no confirmed reading exists for the specified reading type, the PM
will **never** fire — the calculator returns "not due." This is intentional: the
system cannot determine due-ness without a usage baseline. Ensure Technicians
regularly record and confirm meter readings for assets with reading-based PM
rules.

**When to use:** Maintenance that depends on actual wear — oil changes by
mileage, component replacement by operating hours, filter changes by cycle count.

#### Date-or-Reading (`date_or_reading`)

The PM is due when **either** the date threshold or the reading threshold is
crossed — whichever comes first. Each dimension is evaluated independently, and
if either one fires, the PM is due.

**Example:** A "Motor 500-hr OR 6-month Service" with `interval_days = 180` and
`interval_reading = 500.00`. If the motor reaches 500 hours after only 4 months,
the PM fires based on reading. If the motor sits idle for 6 months without
reaching 500 hours, the PM fires based on date.

**When to use:** The most common trigger type for operational equipment. Ensures
service happens at a reasonable interval regardless of whether the asset is used
heavily (reading triggers first) or sits idle (date triggers first).

#### No "First Free" Interval

When a rule is first assigned to an asset, the baseline is set to today's date
and the current meter reading. This means the asset gets **one full interval**
before the first PM fires. The system does not immediately trigger "everything is
overdue" when you assign rules to existing assets.

### 12.4 PM Assignments — Linking Rules to Assets

A **PM Assignment** (stored in the `asset_pm_assignments` table) connects a
template to a specific asset. This is where the abstract template becomes an
actionable schedule for a real piece of equipment.

Assignments are managed by **Administrators and Maintenance Managers** from:
- **Asset Detail → PM Assignments** (per-asset view)
- **Admin → PM Rules** (per-template view)

#### Creating an Assignment

When you assign a template to an asset:

1. The system creates an `AssetPmAssignment` record linking the rule and asset.
2. The **baseline** is initialized:
   - `last_triggered_date` = today's date (the full interval starts now)
   - `last_triggered_reading` = the asset's latest confirmed reading for the
     rule's reading type (only for reading-based triggers)

This baseline is the starting point from which all future "is it due?"
calculations are measured.

#### The Baseline Explained

The baseline is the single most important concept in PM evaluation. It answers
the question: "When was this asset last serviced under this rule?"

The baseline is stored as two values on the assignment:

| Baseline Field | Meaning |
|---|---|
| `last_triggered_date` | The date from which the interval is measured. Stored as a calendar date. |
| `last_triggered_reading` | The meter reading from which the usage increment is measured. Stored as a decimal. |

**The system asks:**
- (Date trigger) Is `today >= last_triggered_date + interval_days`?
- (Reading trigger) Is `latest_confirmed_reading >= last_triggered_reading + interval_reading`?

The baseline is updated (reset) whenever a PM Work Order is closed — the interval
restarts from the closure date and reading. See Section 12.8 for cumulative
maintenance rules that also reset lower-level baselines.

#### Assignment Lifecycle

- **Assign:** `POST /api/assets/{asset}/pm-assignments` — Creates the assignment and initializes the baseline.
- **Deactivate:** `POST /api/assets/{asset}/pm-assignments/{id}/deactivate` — Stops evaluation. Clears any stale suppression records to prevent blocking future reactivation.
- **Reactivate:** `POST /api/assets/{asset}/pm-assignments/{id}/reactivate` — Resumes evaluation. The baseline is preserved from before deactivation.
- **Evaluate (single):** `POST /api/assets/{asset}/pm-assignments/{id}/evaluate` — Manually triggers a PM check for this one assignment.
- **Evaluate (all):** `POST /api/pm-rules/evaluate-all` — Triggers evaluation against every active assignment across all assets.

#### Assignment Status Indicators

Each assignment displays a **PM status indicator** in the UI, calculated from how
close the asset is to its next due threshold:

| Indicator | Label | Meaning |
|---|---|---|
| 🟢 | **OK** | Well within interval. Progress < 60% toward the due threshold. |
| 🟡 | **Soon** | Approaching the due threshold. Progress is between 60% and 80%. |
| 🔴 | **Due** | At or past the due threshold. Progress ≥ 80% or `PmDueCalculator::isDue()` returns true. |

Progress is calculated separately for the date dimension and reading dimension,
and the higher of the two is used. For example, if the date is 90% elapsed but
the reading is only 10% used, the indicator shows 🔴 (based on the date).

### 12.5 PM Evaluation — How the System Checks for Due PMs

Evaluation is the automated process that checks every active assignment and
generates Preventive Maintenance Requests where needed.

#### Scheduled Evaluation (Daily Job)

The system runs evaluation automatically via a scheduled job:

- **Frequency:** Daily at 06:00 Africa/Tripoli timezone
- **Job class:** `App\Jobs\EvaluatePmRulesJob`
- **Scope:** All active assignments where both the assignment and its parent
  template are active, and the asset's `maintenance_status = enrolled`
  (Withdrawn assets are skipped — they are not in active maintenance service)
- **Concurrency protection:** The job uses Laravel's `ShouldBeUnique` with a
  5-minute lock window, so overlapping runs are prevented
- **Retries:** 3 attempts with exponential backoff (60s, 300s, 900s) if the job
  fails

#### Manual Evaluation

You can trigger evaluation manually at any time:

- **Single assignment:** From Asset Detail → PM Assignments, click "Evaluate" on
  a specific assignment. This checks only that one assignment.
- **Bulk (all assignments):** From Admin → PM Rules tab, click "Evaluate All."
  This runs the same evaluation logic against every active assignment across all
  assets.

Manual evaluation is useful when:
- You just assigned a new rule and want to confirm it would fire correctly
- A Technician just recorded a large meter reading and you want the PM to trigger
  immediately
- You're testing a PM configuration during setup

#### The Evaluation Algorithm — Step by Step

For each active assignment, the `EvaluatePmRule` action performs these checks in
order:

1. **Guard: Active?** Both the assignment and its parent template must be active.
   If either is inactive, skip.

2. **Guard: Already in progress?** Checks for an **active maintenance chain** —
   does this asset+rule combination already have:
   - A `pending_review` Preventive MR, OR
   - An `open`, `in_progress`, or `completed` Work Order linked to a Preventive MR?

   If so, skip. The system does not generate duplicate MRs while maintenance is
   already in progress.

3. **Check: Is it due?** Delegates to `PmDueCalculator::isDue()`, which:
   - For `date` triggers: `today >= last_triggered_date + interval_days` AND no
     active date suppression record exists
   - For `reading` triggers: `latest_confirmed_reading >= last_triggered_reading
     + interval_reading` AND no active reading suppression record exists
   - For `date_or_reading` triggers: either dimension is due (independently
     evaluated). Only the due dimension needs to pass suppression checks.

4. **Generate MR:** If all guards pass and the assignment is due:
   - A new `MaintenanceRequest` is created with:
     - `type = preventive`, `status = pending_review`
     - `priority = medium` (default for auto-generated PMs)
     - `is_preventive = true`, `pm_rule_id = {rule_id}`
     - `triggered_by_date` / `triggered_by_reading` flags capturing which
       dimension(s) actually triggered the MR
   - The MR number is generated from the `BusinessNumberSequence` (`MR-XXXXX`)
   - An audit log entry is recorded

5. **Result:** The MR enters `pending_review` and follows the standard MR → WO
   workflow (see Section 7).

### 12.6 Suppression — Why Rejected PMs Don't Immediately Regenerate

When a Maintenance Manager rejects or cancels a Preventive MR, the system creates
a **suppression record** (`pm_occurrence_suppressions` table). This is critical:
without suppression, the next daily evaluation would immediately detect that the
asset is still due and generate another identical MR.

#### How Suppression Works

A suppression record captures:

| Field | Purpose |
|---|---|
| `pm_rule_id` + `asset_id` | Links to the specific rule+asset combination |
| `maintenance_request_id` | The MR that was decided upon |
| `trigger_type` | Snapshot of the rule's trigger type at decision time |
| `decision_type` | What happened (e.g. `rejected`) |
| `triggered_by_date` / `triggered_by_reading` | Which dimension(s) caused the MR |
| `suppressed_until_date` | **The suppression window for date:** don't fire again until on or after this date |
| `suppressed_until_reading` | **The suppression window for reading:** don't fire again until this reading or higher |

The `PmDueCalculator` checks suppression records during evaluation. If an active
suppression covers the current trigger condition, the PM is **not** due —
effectively deferred until the suppression window expires.

#### Suppression Windows by Trigger Type

| Trigger Type | What's Required |
|---|---|
| `date` only | `suppressed_until_date` must be set |
| `reading` only | `suppressed_until_reading` must be set |
| `date_or_reading` — only date fired | Only `suppressed_until_date` needed |
| `date_or_reading` — only reading fired | Only `suppressed_until_reading` needed |
| `date_or_reading` — both fired | Both suppression boundaries required |

#### Design Rationale

Suppression is a **deferral**, not a permanent skip. The next occurrence will fire
when:
- The date passes `suppressed_until_date`, OR
- The reading reaches `suppressed_until_reading`

This means the Manager is saying: "I've reviewed this occurrence and decided not
to act now, but I still want the system to remind me when the asset reaches the
next threshold."

#### Stale Suppression Cleanup

When an assignment is deactivated, all still-effective suppression records for
that rule+asset pair are cleared (both `suppressed_until_date` and
`suppressed_until_reading` are set to null). This prevents a deactivated-then-
reactivated assignment from being blocked by old suppression windows.

### 12.7 Baseline Reset — What Happens When a PM Work Order Closes

When a Maintenance Manager closes a Preventive Maintenance Work Order, the system
resets the baseline for that assignment. This is how the interval restarts —
"you just did the 500-hr service, so the next one is due in another 500 hours."

#### Direct Reset (the Rule That Just Closed)

The assignment that generated the PM receives:
- `last_triggered_date` = today (the WO closure date)
- `last_triggered_reading` = the asset's latest confirmed reading at closure time
  (for reading-based triggers only)

The interval restarts from this point. If the rule was date-based with a 90-day
interval, the next PM is due in 90 days from closure, not from the original
trigger date.

### 12.8 Cumulative Maintenance — Level Hierarchy & Cascade Reset

Cumulative maintenance is a concept that prevents redundant PM requests when a
higher-level service covers the work of lower-level services. It applies to PM
rules configured with standard maintenance levels: `L1`, `L2`, `L3`, `L4`.

#### Maintenance Levels

| Level | Typical Scope | Example |
|---|---|---|
| **L1** | Minor inspection / basic service | Visual check, fluid top-up |
| **L2** | Intermediate service | Filter changes, adjustments |
| **L3** | Major service / overhaul | Disassembly, component replacement |
| **L4** | Complete rebuild | Full strip-down, every component inspected/replaced |

Custom free-text levels (anything not matching the `L1`-`L4` pattern) are
**independent** — they do not participate in cascade resets.

#### How Cascade Reset Works

When a standard-level PM Work Order is closed, the system runs a cascade:

1. Parse the maintenance level of the rule that just closed (e.g. `L3`).
2. Find all **other** active assignments on the **same asset** whose level is
   **lower** (e.g. `L1` and `L2` for an `L3` closure).
3. Reset their baselines:
   - `last_triggered_date` = today
   - `last_triggered_reading` = latest confirmed reading

**Example:** A motor has three PM assignments:
- L1 — "Visual inspection" every 30 days
- L2 — "Filter change" every 90 days
- L3 — "Full teardown" every 360 days

The L3 fires at 360 days. A Work Order is created and executed. When the Manager
closes the L3 WO:
- The L3 baseline resets (next L3 due in 360 days from closure)
- The L1 baseline resets (next L1 due in 30 days from closure — you don't need
  an L1 inspection the day after a full teardown)
- The L2 baseline resets (next L2 due in 90 days from closure)

**Without cascade reset:** Closing the L3 WO would leave the L1 at 360 days since
last L1 — immediately overdue. The system would generate an L1 MR the next day,
even though the L3 teardown already covered everything an L1 would check.

### 12.9 Who Can Do What — PM Role Permissions

| Action | Administrator | Maintenance Manager | Technician | Logistics | Requester |
|---|---|---|---|---|---|
| Create PM rule templates | Yes | No | No | No | No |
| Edit PM rule templates | Yes | No | No | No | No |
| Deactivate / reactivate templates | Yes | No | No | No | No |
| Assign template to asset | Yes | Yes | No | No | No |
| Deactivate / reactivate assignment | Yes | Yes | No | No | No |
| Evaluate single assignment | Yes | Yes | No | No | No |
| Evaluate all assignments | Yes | No | No | No | No |
| View PM rules & assignments | Yes | Yes | No | No | No |
| Approve / reject Preventive MR | Yes | Yes | No | No | No |

### 12.10 Complete Walkthrough — Setting Up and Running a PM Rule

This example follows a Mud Motor through its first PM cycle.

#### Phase 1: Administrator Creates the Template

1. Navigate to **Admin → PM Rules** tab.
2. Click **Create Rule**.
3. Fill in the form:
   - **Name:** "Motor 500-hr PM"
   - **Description:** "Full inspection and fluid change at 500 operating hours"
   - **Trigger Type:** `date_or_reading`
   - **Interval Days:** 180 (6 months — as a safety net if the motor sits idle)
   - **Interval Reading:** 500.00 (operating hours)
   - **Reading Type:** "Operating Hours"
   - **Maintenance Level:** L2
4. Save. The template is now active and available for assignment.

#### Phase 2: Maintenance Manager Assigns to an Asset

1. Navigate to **Asset Management → All Assets**.
2. Find Motor MTR-001 and click to open its detail page.
3. Go to the **PM Assignments** section.
4. Click **Assign Rule**.
5. Select "Motor 500-hr PM" from the template picker.
6. Confirm.

**What happens behind the scenes:**
- A new `AssetPmAssignment` is created for MTR-001 + "Motor 500-hr PM"
- `last_triggered_date` = today (let's say June 1, 2026)
- `last_triggered_reading` = MTR-001's latest confirmed reading (let's say 1,200
  hours)
- The next due threshold: July 1 for date (June 1 + 30 days), or 1,700 hours
  (1,200 + 500) for reading

#### Phase 3: Daily Evaluation — Nothing Happens (Yet)

Every day at 06:00, the evaluation job runs. For the first few months:
- Date check: today < June 1 + 180 days → NOT due
- Reading check: latest confirmed reading < 1,700 hours → NOT due
- Result: No MR generated. The assignment shows 🟢 OK.

#### Phase 4: Reading Crosses the Threshold

On August 15, a Technician records a confirmed meter reading of 1,720 hours on
MTR-001. The next day at 06:00:
- Date check: August 16 < December 1 (180 days) → NOT due
- Reading check: 1,720 >= 1,700 → **DUE**
- Active chain check: no existing MR or WO → proceed
- The system creates MR-00452: "Auto-generated PM: Motor 500-hr PM"

#### Phase 5: Manager Reviews and Approves

1. Manager sees MR-00452 in the **Pending Approval** tab.
2. Reviews the request: MTR-001 is due for its 500-hr PM.
3. Clicks **Approve & Create Work Order**.

**What happens:**
- MR-00452 status → `converted` (terminal)
- WO-00217 created with status `open`
- WO is assigned to Technician Tariq

#### Phase 6: Technician Executes the Work

1. Tariq opens WO-00217, starts work (status → `in_progress`).
2. Records work notes, adds parts used (filters, fluids), uploads completion
   photos.
3. Records a post-maintenance meter reading: 1,725 hours.
4. Marks the WO as complete (status → `completed`).

#### Phase 7: Manager Closes — Baseline Resets

1. Manager reviews WO-00217, verifies work is complete.
2. Clicks **Close Work Order**. Confirms.

**What happens behind the scenes:**
- WO-00217 status → `closed` (terminal, permanently immutable)
- The "Motor 500-hr PM" assignment baseline resets:
  - `last_triggered_date` = August 20 (closure date)
  - `last_triggered_reading` = 1,725 hours (latest confirmed at closure)
- **Next due thresholds:**
  - Date: February 16, 2027 (August 20 + 180 days)
  - Reading: 2,225 hours (1,725 + 500)
- If MTR-001 also had an L1 assignment, its baseline also resets (cascade)

The cycle is complete. The system is now watching for the next interval.

### 12.11 Important Rules and Edge Cases

#### Asset Must Be Enrolled

PM evaluation only runs against assets with `maintenance_status = enrolled`.
Withdrawn assets do not generate PM requests. If you withdraw an asset, all its
PM assignments stop evaluating (they remain on record; they are just ignored
until the asset is re-enrolled).

#### No Confirmed Reading = No Reading-Based PM

For reading-based triggers, the `PmDueCalculator` requires a confirmed meter
reading. If an asset has never had a reading of the required type confirmed, the
PM will **never** fire. This protects against false triggers on assets with
unknown usage. Ensure baseline readings are recorded when assigning reading-based
rules.

#### One Active Chain Per Rule+Asset

The system never generates a second Preventive MR for the same asset+rule while
a previous one is still in progress (`pending_review` MR or open/in-progress/
completed WO). This prevents duplicate work orders for the same PM event.

#### Suppression Is Per-Occurrence, Not Permanent

A suppression record defers the specific occurrence that triggered it. It does
not permanently disable the rule for that asset. The next occurrence will fire
when the next threshold is crossed.

#### Manual Evaluation Respects All Guards

Manually triggering "Evaluate" on an assignment runs the same algorithm as the
daily job — all guards (active chain check, suppression check, due calculation)
still apply. You cannot force-generate a Preventive MR by clicking "Evaluate"
if the asset is not actually due.

#### Templates Are Immutable for Existing Assignments

Editing a PM rule template does not retroactively change existing assignments.
Assignments snapshot the template's ID and use the template's current intervals
at evaluation time. If you change a template from 500 hours to 300 hours, all
assignments using that template will immediately use the new 300-hour interval
on their next evaluation. The baseline (when the interval started) is stored on
the assignment, not the template.
---

## 13. Administration

The Admin section is the Administrator's control panel for user management,
system configuration, and master data.

**Sidebar:** Tabbed Group — visible to Administrator only.

**Route:** `/admin`

### 13.1 Users & Access Tab

**Visible to:** Admin only.

**Employee Directory:**

- **Import Employees:** Administrator imports employees from the client's
  SharePoint List into a local employee directory. This is a CSV import
  operation.
- **View Employees:** List of imported employees with their details.
- **Provision User:** Select an imported employee → assign one fixed role →
  system sends an activation link. Provisioning creates a user account linked to
  the employee record. Importing an employee does not automatically grant
  application access — provisioning is the explicit step that creates a user.

**User Management:**

- **List users:** All user accounts with name, email, role, active status,
  activation date.
- **Edit user:** Update name, email, and role assignment. Admin cannot edit
  their own account through admin endpoints.
- **Activate / Deactivate:** Toggle user account active status. Deactivated
  users cannot authenticate but their historical ownership and audit references
  remain intact. Deactivation is reversible.
- **Reset Password (Admin):** Force a password reset for a user. Sends a reset
  token through Power Automate. Admin does not set or view the new password.
- **Role assignment:** Each user has exactly one fixed role. Roles are immutable
  system data — Admin cannot create, rename, or delete roles.

### 13.2 Lists & Dropdowns Tab

**Visible to:** Admin only.

Manage all configurable dropdown values used across the system:

- Location definitions (also accessible via the dedicated Locations sidebar
  item).
- Asset operational statuses.
- Asset maintenance sub-statuses.
- Maintenance priorities.
- Usage reading types.
- Work Order statuses.
- Maintenance categories.
- Other master data and lookup values.

Each list supports full CRUD through side sheets. Values are never physically
deleted — they are deactivated when no longer needed.

### 13.3 WO Forms Tab

**Visible to:** Admin only.

Manage Work Order execution form templates per FA subclass. Templates define
the form fields that Technicians fill during WO execution.

**Template list:** Shows all form templates with name, FA subclass, field count,
and active/inactive status. Create, edit, and deactivate/reactivate actions are
available.

**Creating a template:**

1. Select "Create Template".
2. Enter a template name (e.g., "Mud Motor Inspection").
3. Select the FA subclass (e.g., MTR for Mud Motor) — only subclasses without an
   existing active template are shown.
4. Add fields to the template:
   - **Label** — what the Technician sees (e.g., "Hours reading").
   - **Type** — boolean, numeric, or text.
   - **Unit** — for numeric fields only (e.g., "hours", "PSI"). Display only.
   - **Pre/Post** — toggle whether the field captures both pre and post values.
   - **Required** — toggle whether the field must be filled before WO completion.
   - **Order** — display order in the form.
5. Save the template.

**Editing a template:**

- Add, edit, remove, or reorder fields at any time.
- Existing Work Orders keep their snapshotted version. The "Sync to latest"
  prompt allows Technicians to update individual WO forms on demand.

**Deactivating a template:**

- Deactivate to prevent new WOs from snapshotting the template.
- Inactive templates remain visible and can be reactivated.
- Deactivation does not affect existing WO forms already snapshotted.

### 13.4 PM Rules Tab

**Visible to:** Admin only.

Full template management as described in Section 12. Template creation, editing,
deactivation, reactivation, and bulk evaluation.

### 13.5 Design Rationale for Administration

**Why are roles immutable system data?**
The five human roles (plus Service) represent the client's agreed organizational
structure. Allowing runtime creation or modification of roles would introduce
complexity (permission matrices, UI configuration) disproportionate to the
system's scope. Fixed roles ensure predictable, testable authorization
behaviour.

**Why can't Administrators edit their own accounts?**
This is a security best practice. An attacker who compromises an Admin account
should not be able to lock out the legitimate Admin by changing their email or
password through normal application endpoints. Self-modification requires
out-of-band action.

**Why are users deactivated rather than deleted?**
User accounts are referenced throughout the system — as MR creators, WO
assignees, meter reading submitters, attachment uploaders, and audit log actors.
Deleting a user would break referential integrity across all these records.
Deactivation preserves history while preventing access.

---

## 14. System Settings

The Settings section provides system-wide configuration.

**Sidebar:** Tabbed Group — visible to Administrator only.

**Route:** `/settings`

### 14.1 System & Integration Tab

**Visible to:** Admin only.

**Configuration items:**

- **ERP Sync Settings:** ERP connection configuration, credentials, adapter
  selection, and synchronization schedule (SM-owned).
- **Sync History:** List of past ERP sync runs with timestamps, status
  (`running`, `success`, `partial`, `failed`), and error details.
- **Manual Sync Trigger:** "Run ERP Sync" button. Admin or Maintenance Manager
  can trigger a manual sync.
- **Company Timezone:** Display timezone for the application. Default:
  Africa/Tripoli.
- **Power Automate Email Integration:** Configuration for the email delivery
  channel used for activation and password-reset emails.

**Important:** Only Administrator can manage ERP connection, credentials, and
schedule settings. Maintenance Manager can trigger manual sync runs but cannot
change the sync configuration.

### 14.2 Activity Logs Tab

**Visible to:** Admin only.

A read-only, append-only technical audit trail. Every security-sensitive and
workflow action is recorded here.

**Audited events include:**

- Login and logout.
- User activation and deactivation.
- Maintenance Request creation, update, approval, rejection, and cancellation.
- Work Order assignment, reassignment, start, completion, closure, and
  cancellation.
- Work Order execution detail edits (with redacted before/after context).
- Asset creation, update, location changes, status changes.
- Asset booking and unbooking.
- Meter reading submission and confirmation.
- PM rule template creation, editing, deactivation, and reactivation.
- PM assignment creation, deactivation, reactivation, and evaluation.
- Manual ERP sync trigger and sync job outcomes.
- Attachment upload and soft deletion.
- Component install, remove, and swap operations.
- API token operations (creation, revocation, deletion).

**What is never stored in the audit log:**

- Passwords.
- Session cookies.
- API keys.
- Attachment file contents.

**Filtering:** Filterable by event type, user, and date range. Entries cannot
be edited or deleted through the application.

---

## 15. Attachments

Attachments can be uploaded against four parent types:

1. **Assets** — user manuals, maintenance instructions, safety sheets,
   calibration certificates, warranty documents, photos.
2. **Parts** — datasheets, fitting instructions, safety sheets, compatibility
   notes.
3. **Maintenance Requests** — fault photos, supporting documents.
4. **Work Orders** — completion photos, repair evidence, supporting documents.

**Upload rules:**

- Maximum file size: 20 MB per file.
- Allowed document types: PDF, DOC, DOCX, XLS, XLSX.
- Allowed image types: JPEG, PNG, WebP.
- Rejected: executables, scripts, disk images, archives.
- File content is validated using server-detected MIME type (client-provided
  MIME type is not trusted).

**Access control:**

- Users can only access attachments for records they are authorised to view.
- Downloads go through authenticated backend routes — not direct file paths.
- Soft-deleted attachments are excluded from lists and download access is
  rejected.

**Deletion:**

- Attachments are soft-deleted (not physically removed).
- Metadata is retained with `deleted_by_user_id` and `deleted_at`.
- No restore UI in MVP.
- Authorization for deletion follows the parent record's policy.

---

## Appendix A: Status Reference

### Maintenance Request Statuses

| Status           | Description                                                    | Terminal |
| ---------------- | -------------------------------------------------------------- | -------- |
| `pending_review` | Awaiting Manager review. Editable by creator or Admin/Manager. | No       |
| `converted`      | Approved and atomically converted to a Work Order.             | Yes      |
| `rejected`       | Declined by Manager with required reason.                      | Yes      |
| `cancelled`      | Withdrawn before approval/conversion.                          | Yes      |

### Maintenance Request Priorities

| Value | Display Label | Typical Use |
|---|---|---|
| `low` | **Low** | Minor issues, cosmetic defects, non-urgent improvements. |
| `medium` | **Medium** | Standard maintenance. Default for auto-generated PM requests. |
| `high` | **High** | Significant performance issue, impending failure. |
| `critical` | **Critical** | Immediate safety hazard, production stopped, mission-critical failure. |


### Work Order Statuses

| Status        | Description                                              | Terminal |
| ------------- | -------------------------------------------------------- | -------- |
| `open`        | Created from approved MR. May be unassigned.             | No       |
| `in_progress` | Work started. Must be assigned to active Technician.     | No       |
| `completed`   | Technician submitted all work. Awaiting Manager closure. | No       |
| `closed`      | Reviewed and finalized. Permanently immutable.           | Yes      |
| `cancelled`   | Cancelled by Admin/Manager with required reason.         | Yes      |

### Asset Maintenance Status

| Parent State (`value`)                               | Sub-Status  | Applies To                            | PM Eligible |
| ---------------------------------------------------- | ----------- | ------------------------------------- | ----------- |
| **Enrolled** (`enrolled`) — "In maintenance program" | _(none)_    | `asset_kind = asset` (standalone)     | Yes         |
| **Enrolled** (`enrolled`)                            | `installed` | `asset_kind = component` or `package` | Yes         |
| **Enrolled** (`enrolled`)                            | `ready`     | `asset_kind = component` or `package` | Yes         |
| **Withdrawn** (`withdrawn`)                          | `lih`       | Any                                   | No          |
| **Withdrawn** (`withdrawn`)                          | `dbr`       | Any                                   | No          |
| **Withdrawn** (`withdrawn`)                          | `disposed`  | Any                                   | No          |
| **Withdrawn** (`withdrawn`)                          | `scrapped`  | Any                                   | No          |
| **Withdrawn** (`withdrawn`)                          | `other`     | Any                                   | No          |

### Asset Operational Status

The `operational_status` field describes whether the asset is currently
functional. It is driven by Work Order lifecycle events. See Section 5.9.

| DB Value | Display Label | Meaning |
|---|---|---|
| `active` | **Active** | Fully operational and available for normal use. |
| `under_maintenance` | **Under Maintenance** | Currently in the workshop being serviced. Work is in progress. |
| `down` | **Down** | Has a known fault or failure. Not operational. Awaiting repair. |
| `inactive` | **Inactive** | Permanently retired, decommissioned, or removed from the operational pool. |

### Maintenance Sub-Statuses

Sub-statuses provide finer granularity within the maintenance program. Some
sub-statuses are only available for specific asset kinds.

| DB Value | Display Label | Applies To | Meaning |
|---|---|---|---|
| `installed` | **Installed** | `asset_kind = component` or `package` | Currently installed inside a parent. `parent_asset_id` must be set. |
| `ready` | **Ready** | `asset_kind = component` or `package` | Spare. Fully maintained and available for installation. `parent_asset_id` must be `NULL`. |
| `lih` | **Lost in Hole** | Any (withdrawn only) | Physically inaccessible (e.g., downhole equipment that cannot be retrieved). |
| `dbr` | **Damaged Beyond Repair** | Any (withdrawn only) | Repair is not economically or technically feasible. |
| `disposed` | **Disposed** | Any (withdrawn only) | Formally disposed per organizational policy (independent of ERP disposal accounting). |
| `scrapped` | **Scrapped** | Any (withdrawn only) | Dismantled, sold for scrap, or otherwise removed from the operational pool. |
| `other` | **Other** | Any (withdrawn only) | Any other reason, with a free-text note for context. |

> Sub-statuses carry no business logic — "Lost in Hole" does not block PM
> evaluation (the Withdrawn parent state already does that). They are purely
> informational labels for categorization and reporting.


### Asset Kinds

Each asset in ATMS carries an `asset_kind` that determines its role in the
assembly hierarchy and which maintenance sub-statuses are available.

| Kind          | DB Value      | Can Have Parent? | Can Have Children? | Enrolled Sub-Statuses     | Typical Example                    |
| ------------- | ------------- | ---------------- | ------------------ | ------------------------- | ---------------------------------- |
| **Asset**     | `asset`       | No               | No                 | *(none)*                  | Standalone pump, generator         |
| **Package**   | `package`     | Yes              | Yes                | `installed`, `ready`      | Motor, Power Section               |
| **Component** | `component`   | Yes              | No                 | `installed`, `ready`      | Radial Bearing, Sensor             |

> See Section 5.2 for the full definition of each asset kind, including
> `parent_asset_id` consistency rules, decision guidance for choosing the right
> kind, and the access control policy (only Administrator and Maintenance Manager
> may change an asset's kind).

### PM Trigger Types

| Trigger           | Behaviour                                                              |
| ----------------- | ---------------------------------------------------------------------- |
| `date`            | Generates MR when calendar interval elapses since last baseline.       |
| `reading`         | Generates MR when usage reading crosses threshold since last baseline. |
| `date_or_reading` | Generates MR when either condition is met — whichever comes first.     |

### ERP Sync Job Statuses

| Status    | Meaning                                   |
| --------- | ----------------------------------------- |
| `running` | Sync job is currently executing.          |
| `success` | Sync completed with no errors.            |
| `partial` | Sync completed but some items had errors. |
| `failed`  | Sync could not complete.                  |

---

## Appendix B: Role Permission Quick Reference

| Permission                        | Admin | Manager | Technician        | Logistics | Requester       |
| --------------------------------- | ----- | ------- | ----------------- | --------- | --------------- |
| View dashboard                    | Yes   | Yes     | Yes               | Yes       | Yes             |
| Create corrective MR              | Yes   | Yes     | Yes               | Yes       | Yes             |
| Update own pending MR             | Yes   | Yes     | Yes               | Yes       | Yes             |
| Update any pending MR             | Yes   | Yes     | No                | No        | No              |
| Cancel own pending CM MR          | Yes   | Yes     | Yes               | Yes       | Yes             |
| Cancel any pending MR             | Yes   | Yes     | No                | No        | No              |
| Approve / reject MR               | Yes   | Yes     | No                | No        | No              |
| View Work Orders                  | Yes   | Yes     | Yes (assigned)    | No        | No              |
| Assign WO                         | Yes   | Yes     | No                | No        | No              |
| Start WO                          | Yes   | Yes     | Yes (assigned)    | No        | No              |
| Edit WO execution details         | Yes   | Yes     | Yes (assigned)    | No        | No              |
| Complete WO                       | Yes   | Yes     | Yes (assigned)    | No        | No              |
| Close WO                          | Yes   | Yes     | No                | No        | No              |
| Cancel WO                         | Yes   | Yes     | No                | No        | No              |
| Create / edit asset               | Yes   | Yes     | No                | No        | No              |
| Change asset kind                 | Yes   | Yes     | No                | No        | No              |
| Set parent_asset_id (outside WO)  | Yes   | Yes     | No                | No        | No              |
| Change asset maintenance status   | Yes   | Yes     | No                | No        | No              |
| Book / unbook asset               | Yes   | Yes     | No                | No        | No              |
| Install / remove / swap component | Yes   | Yes     | Yes (assigned WO) | No        | No              |
| Create MR for child component     | Yes   | Yes     | No                | No        | No              |
| Record meter reading              | Yes   | Yes     | Yes               | No        | Unverified only |
| Confirm meter reading             | Yes   | Yes     | Yes               | No        | No              |
| View parts catalogue              | Yes   | Yes     | Yes               | No        | No              |
| Update local part fields          | Yes   | Yes     | No                | No        | No              |
| View raw ERP payload              | Yes   | No      | No                | No        | No              |
| Create / edit PM rule templates   | Yes   | No      | No                | No        | No              |
| Assign PM template to asset       | Yes   | Yes     | No                | No        | No              |
| Evaluate PM assignment            | Yes   | Yes     | No                | No        | No              |
| View PM templates                 | Yes   | Yes     | No                | No        | No              |
| Trigger manual ERP sync           | Yes   | Yes     | No                | No        | No              |
| Manage ERP sync settings          | Yes   | No      | No                | No        | No              |
| Manage users                      | Yes   | No      | No                | No        | No              |
| Import employees                  | Yes   | No      | No                | No        | No              |
| Provision employees as users      | Yes   | No      | No                | No        | No              |
| Manage locations / master data    | Yes   | No      | No                | No        | No              |
| Manage company settings           | Yes   | No      | No                | No        | No              |
| View technical audit logs         | Yes   | No      | No                | No        | No              |
| Manage API clients                | Yes   | No      | No                | No        | No              |
| Update asset location             | Yes   | Yes     | No                | Yes       | No              |

---

## Appendix C: Glossary

**Enrolled (asset maintenance status, `enrolled`):** Displayed as "In maintenance
program". The asset is in operational use and eligible for maintenance workflows;
PM rules evaluate against enrolled assets; CMs and WOs can be created. (Renamed
from the former "Active" to avoid confusion with the separate operational status.)

**Active (Work Order status):** A tab filter in the WO list showing WOs with
status `open` or `in_progress`.

**AM (Asset Movement):** The subsystem that owns asset physical location and
movement workflows.

**Asset:** A physical item tracked by ATMS for maintenance purposes.

**Asset kind:** A lifecycle field on every asset that declares its role in the
assembly hierarchy. Defined by the `App\Enums\AssetKind` PHP enum with three
values: `asset` (standalone, indivisible leaf — no parent, no children),
`package` (can both contain children and be installed in a parent), and
`component` (can be installed in a parent but cannot have children). The kind
also controls which enrolled maintenance sub-statuses are available: standalone
assets have none, while packages and components use `installed` / `ready`. Only
Administrator and Maintenance Manager may change an asset's kind. For full
details, see Section 5.2.

**Asset tag:** A unique, human-readable physical label code in the format
`L-BBB-CCC-XXXX` designed for printing and future QR encoding.

**Assembly:** The parent-child hierarchy of assets. A parent package contains
child components, each of which is a full ATMS asset with its own maintenance
lifecycle.

**Avg MR Duration:** Average elapsed time from Maintenance Request creation to
its final resolution (conversion, rejection, or cancellation). One of the six
Process Efficiency KPIs displayed on the Dashboard, measured in hours over the
rolling 90-day window.

**Avg WO Duration:** Average elapsed time from Work Order creation to closure for
Work Orders closed during the rolling 90-day window. One of the six Process
Efficiency KPIs displayed on the Dashboard, measured in hours.

**Booking:** An availability marker (`is_booked`) used by Operations to reserve
an asset for a Job/Project. Independent of maintenance and operational status.
Auto-clears on location change or inactivation.

**CM (Corrective Maintenance):** User-initiated maintenance for faulty, damaged,
or underperforming assets. A CM Request is created manually by a user.

**Component:** An asset that can be installed inside a parent. Defined by
`asset_kind = component` or `package`.

**Confirmed reading:** A meter reading that has been verified by Admin, Manager,
or Technician. Only confirmed readings update current meter values and
participate in PM calculations.

**Cumulative maintenance:** When a higher-level PM WO closes, the baselines of
lower-level PM assignments on the same asset are reset. Applies to standard
L1-L4 levels.

**ERP (Enterprise Resource Planning):** The external corporate system that owns
financial asset management, procurement, and parts master data. ATMS reads parts
from ERP (via SM) but does not write back.

**Failure Rate:** Total number of corrective maintenance events (closed
corrective Work Orders) during the rolling 90-day window, displayed as a count
and a per-day average. One of the six reliability KPIs on the Dashboard.

**Withdrawn (asset maintenance status, `withdrawn`):** The asset is not in active
maintenance service. PM evaluation, CM creation, and WO creation are blocked.
(Renamed from the former "Inactive".)

**Installed (`installed`):** A sub-status indicating a component is currently
installed in a parent (`parent_asset_id` is set).

**KPI (Key Performance Indicator):** A quantifiable metric that measures
operational performance. The Dashboard displays six KPIs in two groups:
Reliability Performance (MTBF, MTTR, Failure Rate) and Process Efficiency
(PM Compliance, Avg MR Duration, Avg WO Duration).

**Maintenance Control Center:** The title of the Dashboard page. A two-column
layout providing reliability analytics, action-required alerts, activity
tracking, quick-action shortcuts, and live operational status tiles.

**Maintenance history:** A read-model view assembled from an asset's MRs, WOs,
parts used, readings, and location changes. Not stored in a duplicate table —
derived from authoritative source records.

**MR (Maintenance Request):** A request for maintenance work, either Corrective
(user-initiated) or Preventive (system-generated). All MRs must be reviewed by a
Maintenance Manager before becoming a Work Order.

**MTBF (Mean Time Between Failures):** The average interval between corrective
maintenance events during the rolling 90-day window. Calculated on a calendar
basis: window days divided by the number of corrective failures. Displayed in
days on the Dashboard. A higher MTBF indicates better asset reliability.

**MTTR (Mean Time To Repair):** The average clock time from Work Order creation
through closure for corrective Work Orders completed during the rolling 90-day
window. Displayed in hours on the Dashboard. A lower MTTR indicates faster
maintenance response and repair.

**Package:** An asset that can contain child assets. Defined by `asset_kind =
package`.

**PM (Preventive Maintenance):** System-initiated maintenance based on configured
rules (date intervals, operating hours, kilometers, or other readings).

**PM assignment:** The connection between a PM rule template and a specific
asset. Defines the asset's baseline and determines when preventive MRs are due.

**PM Compliance:** The percentage of Preventive Maintenance assignments completed
on time (WO closed by or before the PM due date) during the rolling 90-day
window. Displayed as a percentage on the Dashboard with the ratio of compliant
assignments to total assignments evaluated.

**PM rule template:** An asset-agnostic schedule definition (trigger type,
interval, maintenance level). Reusable — one template can be assigned to many
assets.

**PM suppression:** A record that prevents the scheduler from regenerating a PM
request that was rejected or cancelled. Defines `suppressed_until_date` and/or
`suppressed_until_reading` boundaries.

**Power Automate:** Microsoft Power Automate, used as the email transport for
account activation and password-reset emails.

**Ready (`ready`):** A sub-status indicating a component is fully maintained and
available for installation (`parent_asset_id` is null). A spare.

**Root:** A package with no parent — sits at the top of an assembly tree.

**SM (Store Management):** The subsystem that owns parts catalogue, inventory,
stock movement, and parts ordering workflow.

**Unverified reading:** A meter reading submitted by a Requester or Logistics
user that has not yet been confirmed by an authorized role.

**WO (Work Order):** The execution phase of maintenance. Created from an approved
MR. Follows the lifecycle: open → in_progress → completed → closed (or
cancelled).
