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

Select the sign-out action at the bottom of the sidebar. The system invalidates
your current session. You will need to log in again to access the application.

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

### 4.1 Sidebar Structure

The application uses a flat single-level sidebar with eight primary items. There
are no nested dropdown menus. Each sidebar item is shown or hidden based on your
role.

| # | Sidebar Item | Type | Visible To |
|---|---|---|---|
| 1 | Dashboard | Direct Link | Everyone |
| 2 | Maintenance Requests | Tabbed Group | Everyone |
| 3 | Work Orders | Tabbed Group | Admin, Manager, Technician |
| 4 | Asset Management | Tabbed Group | Admin, Manager, Technician, Logistics |
| 5 | Parts Management | Tabbed Group | Admin, Manager, Technician |
| 6 | Locations | Tabbed Group | Admin, Manager, Logistics |
| 7 | Admin | Tabbed Group | Admin only |
| 8 | Settings | Tabbed Group | Admin only |

The sidebar can be collapsed to icon-only mode on desktop using the sidebar
toggle. On mobile, the sidebar becomes a slide-in sheet triggered by a menu
button. The active sidebar item is highlighted with a background accent. For
tabbed groups, the item stays highlighted while you are on any of its tabs.

The footer of the sidebar shows your avatar, name, role label, and a sign-out
action.

### 4.2 Tabbed Content Areas

When you click a primary sidebar item that contains sub-items (a "Tabbed
Group"), the sidebar remains static and the main content area header displays a
horizontal row of tabs. Each tab filters the content below it. Individual tabs
are shown or hidden based on your role.

Tab state is driven by URL query parameters (`?tab=active`), which means browser
back and forward buttons work correctly and you can bookmark or share links to
specific tabs.

### 4.3 Drill-Down Navigation

Detail pages for specific records — asset detail, work order detail, maintenance
request review, part detail — are accessed by clicking on a row in their
respective list screens. These drill-down pages do not appear in the sidebar.
They open as full-page views with their own sections and actions.

Screens without sidebar entries:

| Screen | Accessed From |
|---|---|
| Asset Detail | All Assets list, Asset Location Update list |
| Usage & Meter Readings | Asset Detail |
| Location History | Asset Detail, Asset Location Update list |
| Maintenance History | Asset Detail |
| Attachments | Asset Detail, Part Detail |
| Review Maintenance Request | Pending Approval / All Requests lists |
| Work Order Detail | Active / Completed / Closed WO lists |
| Part Detail | All Parts list |
| Asset Assembly History | Asset Assembly tab |

### 4.4 Role Visibility Summary by Section

| Section | Requester | Technician | Logistics | Manager | Admin |
|---|---|---|---|---|---|
| Dashboard | Yes | Yes | Yes | Yes | Yes |
| Maintenance Requests | Yes | Yes | Yes | Yes | Yes |
| Work Orders | — | Yes | — | Yes | Yes |
| Asset Management | — | Yes | Yes | Yes | Yes |
| Parts Management | — | Yes | — | Yes | Yes |
| Locations | — | — | Yes | Yes | Yes |
| Admin | — | — | — | — | Yes |
| Settings | — | — | — | — | Yes |

---

## 5. Core Concepts

### 5.1 What Is an Asset?

An asset is any physical item that ATMS tracks for maintenance purposes. Assets
are managed fully within ATMS — there is no ERP asset source for this
deployment. Each asset carries:

- **Operational data:** name, description, category, serial number, model,
  manufacturer.
- **Maintenance status:** Enrolled ("In maintenance program") or Withdrawn, with optional sub-statuses.
- **Usage readings:** operating hours, kilometers, or other meter values.
- **Physical location:** current location (owned by AM, displayed by ATMS).
- **Attachments:** user manuals, datasheets, certificates, photos.
- **Maintenance history:** a read-model assembled from the asset's MRs, WOs,
  readings, and location changes.

Assets are never deleted. They are soft-deactivated (`is_active = false`), which
preserves their entire history while removing them from active lists and
preventing new maintenance actions against them.

### 5.2 Asset Kinds

Every asset has an `asset_kind` that declares its role in the assembly hierarchy:

| Kind | Can Have Parent? | Can Have Children? | Typical Example |
|---|---|---|---|
| **asset** | No | No | Rotor, Stator (indivisible leaf unit) |
| **package** | Yes | Yes | Motor, Power Section (can be both parent and child) |
| **component** | Yes | No | Radial Bearing (designed to be installed, has no children) |

Only Administrator and Maintenance Manager may change an asset's `asset_kind`.

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
> maintenance status is never confused with an asset's separate *operational*
> status, which has its own "active" value.)

#### Enrolled — displayed as "In maintenance program"

The asset is in operational use and eligible for maintenance workflows:

- PM rules evaluate against enrolled assets.
- Corrective MRs can be created for enrolled assets.
- Work Orders can be created against enrolled assets.

**Enrolled sub-statuses** (for components and packages only):

| Sub-status | Meaning |
|---|---|
| *(none)* | Default for standalone assets. Normal operation. |
| **Installed** (`installed`) | Component is currently installed inside a parent. `parent_asset_id` is set. |
| **Ready** (`ready`) | Component is fully maintained and available for installation. Not currently installed (`parent_asset_id` is null). A spare. |

#### Withdrawn — displayed as "Withdrawn"

The asset is not in active maintenance service. PM rules do not evaluate against
withdrawn assets. CM and WO creation are blocked. The asset remains viewable in
the registry and its full maintenance history is preserved.

**Withdrawn sub-statuses** (purely informational — no workflow triggers):

| Sub-status | Meaning |
|---|---|
| **Lost in Hole** (`lih`) | Physically inaccessible (e.g., downhole equipment that cannot be retrieved). |
| **Damaged Beyond Repair** (`dbr`) | Repair is not economically or technically feasible. |
| **Disposed** (`disposed`) | Formally disposed per organizational policy (independent of ERP disposal accounting). |
| **Scrapped** (`scrapped`) | Dismantled, sold for scrap, or otherwise removed from the operational pool. |
| **Other** (`other`) | Any other reason, with a free-text note for context. |

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

| Role | Book | Unbook |
|---|---|---|
| Administrator | Yes | Yes |
| Maintenance Manager | Yes | Yes |
| Logistics | Yes | Yes |
| Technician | No | No |
| Requester | No | No |

### 5.6 Asset Assembly (Package / Component)

Some assets are composed of other assets. For example, a mud motor contains a
power section, which itself contains a rotor and stator. Each component in the
assembly is a **full ATMS asset** with its own MRs, WOs, readings, and history.
This is not a passive parts list — it is a hierarchy of independently
maintainable assets.

**Key concepts:**

| Term | Definition |
|---|---|
| **Asset** | A single indivisible unit. Cannot contain sub-assets. Leaf node only. |
| **Package** | An asset that can contain child assets. A package may also be installed as a component inside a larger package. |
| **Component** | An asset that can be installed inside a parent. |
| **Root** | A package with no parent — sits at the top of an assembly tree. |

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

---

## 6. Dashboard

The Dashboard is the landing page for all authenticated users. It provides an
operational overview at a glance.

**Visible to:** Everyone.

**Route:** `/dashboard`

**Elements displayed:**

- **KPI summary cards** providing counts of:
  - Pending Maintenance Requests (awaiting review).
  - Open Work Orders.
  - Overdue Preventive Maintenance items.
  - Recently closed Work Orders.
- **Pending MR list** — a short list of MRs currently in `pending_review`
  status.
- **Open WO list** — Work Orders that are `open` or `in_progress`.
- **Overdue PM list** — Preventive Maintenance assignments that are past their
  due date or reading threshold.
- **Recently closed WO list** — Work Orders that were recently finalised.
- **Recently updated assets** — assets with recent activity.

Dashboard data is role-filtered. Each role sees counts and lists relevant to
their responsibilities. For example, a Technician sees WOs assigned to them,
while a Maintenance Manager sees all pending MRs requiring their review.

---

## 7. Maintenance Requests

The Maintenance Requests section is the entry point to the maintenance workflow.
It manages both Corrective (user-initiated) and Preventive (system-generated)
requests.

**Sidebar:** Tabbed Group — visible to everyone.

**Route:** `/maintenance`

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
   - **Priority** — how urgent the request is.
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
     becomes `converted`. A new WO is created with status `open`.
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
assignments. Users do not manually create PM requests.

**How PM requests are generated:**

1. A **PM Rule template** is configured by an Administrator (schedule
   definition, asset-agnostic).
2. An Administrator or Maintenance Manager **assigns** the template to a
   specific ATMS-managed asset. This seeds the asset's own baseline — the
   starting point from which intervals are measured.
3. The system runs **daily evaluation** (scheduled job) of all active PM
   assignments. An assignment is evaluated only if **both** the assignment and
   its parent template are active.
4. When criteria are met (date threshold reached, reading threshold reached, or
   whichever comes first for `date_or_reading` rules), the system checks for an
   **active maintenance chain**. An active chain exists when:
   - A `pending_review` MR already exists for the same asset + template, or
   - A converted WO in `open`, `in_progress`, or `completed` status exists.
5. If no active chain exists, the system creates one Preventive Maintenance
   Request. This MR follows the same Manager review and WO lifecycle as a
   corrective MR.
6. The Maintenance Manager reviews the PM request the same as any other MR. They
   may approve (creating a WO), reject, or cancel.

**PM rejection and cancellation — suppression rules:**

- Both rejecting and cancelling a preventive MR create an **occurrence
  suppression record**. This prevents the system from immediately regenerating
  the same PM request.
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

### 7.3 Maintenance Request Statuses

| Status | Meaning | Is Terminal? |
|---|---|---|
| `pending_review` | Submitted/generated and awaiting Manager review. Creator or Admin/Manager may edit description, priority, and asset. | No |
| `converted` | Approved and atomically converted into exactly one Work Order. There is no separate stored "approved" status. | Yes |
| `rejected` | Declined by the Maintenance Manager with a required reason. | Yes |
| `cancelled` | Withdrawn while awaiting review, before approval and conversion. Once approved and converted, the MR cannot be cancelled. | Yes |

### 7.4 Maintenance Request Tabs

The Maintenance Requests page has four tabs, shown/hidden by role:

| Tab | Visible To | Content |
|---|---|---|
| **New Request** | Everyone | Side-sheet form for creating a new Corrective MR. |
| **My Requests** | Everyone | All MRs created by the current user. |
| **Pending Approval** | Admin, Manager | All MRs with status `pending_review`. Row actions: Approve, Reject, Cancel, Edit. |
| **All Requests** | Admin, Manager | Every MR regardless of status, with search and filters. |

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
  Manager. Requires a reason.
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

---

## 8. Work Orders

Work Orders are the execution phase of the maintenance workflow. Every Work
Order is created from an approved Maintenance Request. There is no path to
create a Work Order directly.

**Sidebar:** Tabbed Group — visible to Admin, Manager, Technician.

**Route:** `/work-orders`

### 8.1 Work Order Lifecycle

The standard lifecycle path is:

```
open → in_progress → completed → closed
```

**Each status in detail:**

#### `open`

The Work Order has been created from an approved MR. It may be initially
unassigned. It is visible to Admin, Manager, and the assigned Technician (once
assigned). It awaits assignment and work commencement.

#### `in_progress`

Work has started. A WO can only transition to `in_progress` if it has been
assigned to an active user with the Technician role. Assignment is a prerequisite
for this transition — an unassigned WO cannot be started. The assigned Technician
can now record work progress, add parts used, and update readings.

#### `completed`

The assigned Technician has submitted all required completion information. The WO
now awaits final review by a Maintenance Manager or Administrator. At this point:

- Technician execution fields are locked.
- Parts used are locked.
- Readings and attachments are locked.
- The WO cannot be edited by the Technician.

#### `closed`

The completed WO has been reviewed and finalized by a Maintenance Manager or
Administrator. This is the **terminal state** — a closed WO is permanently
immutable and cannot be reopened, edited, cancelled, or transitioned to any
other status.

**What happens at closure:**

- Maintenance history is finalized.
- Applicable PM baselines are updated (the assignment's baseline is reset using
  the closure date and/or latest reading).
- For standard L1-L4 PM levels, cumulative maintenance resets occur (closing L3
  resets L1 and L2 baselines on the same asset).

#### `cancelled`

The WO has been cancelled by a Maintenance Manager or Administrator with a
required reason. Cancellation is allowed from `open`, `in_progress`, or
`completed`. A cancelled WO is terminal and read-only.

```
open → cancelled
in_progress → cancelled
completed → cancelled
```

### 8.2 Work Order Assignment

- Only Administrators and Maintenance Managers can assign or reassign Work
  Orders.
- A WO may be assigned only to an active user with the Technician role.
- Assignment is required before the WO can transition to `in_progress`.
- Reassignment may occur while the WO is `open` or `in_progress`.

### 8.3 Work Order Execution

During `in_progress`, the assigned Technician can:

- **Update execution details** — work notes, findings, actions taken.
- **Add parts used** — select parts from the SM catalogue and record quantities.
  This submits a part-request into SM's ordering workflow.
- **Remove parts** — delete part lines that were added in error.
- **Record readings** — submit and confirm meter readings against the asset.
- **Update asset operational status** — set the asset's `operational_status`
  through the WO-scoped endpoint.
- **Perform assembly operations** — install, remove, or swap components as part
  of the WO.
- **Upload attachments** — completion photos, repair evidence, supporting
  documents.

**After completion** (`completed` status):

- Technician fields, parts, readings, and attachments are locked.
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

### 8.5 Work Order Closure

Closure is the final review step:

1. The WO must be in `completed` status — the Technician has submitted all work.
2. A Maintenance Manager or Administrator opens the WO.
3. They review: work notes, parts used, readings updated, final asset status.
4. They select "Close Work Order" and confirm.
5. The WO becomes `closed` — permanently immutable.

Closure is the step that finalizes maintenance history and updates applicable PM
baselines. Only Administrators and Maintenance Managers can close WOs.
Technicians complete the work but cannot close — this separation ensures a
second set of eyes reviews the work before it is finalized.

### 8.6 Work Order Cancellation

Cancellation is a terminal decision available to Administrator and Maintenance
Manager only. A required reason must be provided. Cancellation is available from
`open`, `in_progress`, or `completed` status — but not from `closed`.

Technicians cannot cancel Work Orders.

If the originating MR was a preventive request, cancelling the WO does not
automatically suppress future PM occurrences — the PM assignment continues to be
evaluated normally. To suppress PM, the MR must be rejected or cancelled before
WO creation, creating a suppression record.

### 8.6a Work Order Execution Form (WO Form)

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

| Role | Can fill pre/post values? |
|---|---|
| Administrator | Yes — any WO |
| Maintenance Manager | Yes — any WO |
| Technician | Yes — assigned WO only |
| Logistics | No |
| Requester | No |

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

### 8.7 Work Order Status Summary

| Status | Meaning | Editable By | Terminal? |
|---|---|---|---|
| `open` | Created from approved MR. May be unassigned. | Manager (assign, edit exec details), Technician (after assignment) | No |
| `in_progress` | Work has started. Must be assigned to an active Technician. | Assigned Technician (exec details, parts, readings, status), Admin/Manager (exec details) | No |
| `completed` | Technician has submitted all completion info. Awaiting closure. | Admin/Manager (edit exec details, close, cancel) | No |
| `closed` | Reviewed and finalized by Admin or Manager. PM baselines updated. | No one — permanently immutable | Yes |
| `cancelled` | Cancelled by Admin or Manager with required reason. | No one — terminal and read-only | Yes |

### 8.8 Work Order Tabs

| Tab | Visible To | Content |
|---|---|---|
| **My Work Orders** | Technician only | WOs assigned to the current Technician. |
| **All Work Orders** | Admin, Manager | Every WO regardless of status or assignment, with search and filters. |
| **Active** | Admin, Manager, Technician | WOs with status `open` or `in_progress`. |
| **Completed** | Admin, Manager, Technician | WOs with status `completed` (awaiting closure). |
| **Closed** | Admin, Manager, Technician | WOs with status `closed` (read-only). |

### 8.9 Work Order Detail (Drill-Down)

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

### 8.10 Design Rationale for the WO Workflow

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

| Operation | Admin | Manager | Technician | Others |
|---|---|---|---|---|
| Install component | Yes | Yes | Assigned WO only | No |
| Remove component | Yes | Yes | Assigned WO only | No |
| Swap component | Yes | Yes | Assigned WO only | No |
| Create MR for child component | Yes | Yes | No | No |

### 9.4 Meter Readings

Meter readings track asset usage — operating hours, kilometers, or other meter
values. These readings feed into PM evaluation and maintenance history.

**Recording a reading:**

1. Navigate to an asset's detail page and open the Usage & Meter Readings
   section (or enter a reading while creating a Corrective MR).
2. Select the reading type (operating hours, kilometers, etc.).
3. Enter the reading value and reading date/time.
4. Submit.

**Reading confirmation rules:**

- Readings submitted by Administrator, Maintenance Manager, or Technician may be
  confirmed immediately.
- Readings submitted by Requester or Logistics remain **unverified** until
  confirmed by Admin, Manager, or Technician.
- Confirmation requires the new reading to be greater than or equal to the
  latest confirmed reading for the same asset and reading type. A reading lower
  than the current confirmed value is rejected.
- Only confirmed readings update the asset's current meter value.
- PM evaluation uses only the latest confirmed readings.

**Key rules:**

- Confirmed readings are append-only and monotonically non-decreasing per asset
  and reading type.
- MVP has no decreasing-reading override or edit-in-place path.
- Corrections require a new valid reading and an Administrator audit note.

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

The Parts Management section provides a read-only view of the SM parts catalogue
and a link into SM's part-request workflow.

**Sidebar:** Tabbed Group — visible to Admin, Manager, Technician.

**Route:** `/parts`

### 10.1 All Parts Tab

Displays the SM parts catalogue with search and filters.

**Visible to:** Admin, Manager, Technician.

**Columns:** ERP part code, part name, unit of measure, ERP status, category.
Row action: link to part detail.

**Important:** Parts reference data is owned by SM. ERP-owned fields (`erp_part_id`,
`erp_part_code`, `erp_status`, `erp_raw_data`, `erp_last_synced_at`) are
read-only and managed exclusively by the ERP sync process. Only local fields
(`name`, `description`, `unit_of_measure`, `category`, `is_active`) may be
edited by Admin/Manager through the update endpoint.

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

### 10.4 ERP Parts Sync

Parts are synchronised from the ERP into SM tables:

- **Scheduled:** Weekly, every Monday at 03:00 Africa/Tripoli timezone.
- **Manual:** Administrator or Maintenance Manager can trigger a manual sync
  from the Admin → System & Integration tab.
- Sync jobs are tracked with status: `running`, `success`, `partial`, `failed`.
- Sync history is viewable by Administrator.
- Manual and scheduled syncs use overlap prevention — concurrent sync runs are
  not permitted.

---

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

PM Rules define maintenance schedules that the system uses to automatically
generate Preventive Maintenance Requests.

### 12.1 PM Rule Templates

A PM Rule template is an asset-agnostic schedule definition. It is reusable — one
template can be assigned to many assets.

**Template configuration (Administrator only):**

- **Name** — descriptive label.
- **Trigger Type** — one of:
  - `date` — calendar-based (every N days/weeks/months).
  - `reading` — usage-based (every N operating hours, kilometers, etc.).
  - `date_or_reading` — whichever comes first.
- **Schedule parameters** — interval values matching the trigger type.
- **Maintenance Level** — L1, L2, L3, L4 (standard levels) or a custom free-text
  level. Standard levels participate in cumulative maintenance; custom levels are
  independent.

**Template lifecycle:**

- **Create:** Administrator creates a new template from the Admin → PM Rules tab.
- **Edit:** Administrator edits an existing template.
- **Deactivate:** Administrator deactivates a template (`is_active = false`). A
  deactivated template stops generating PM work for all its assignments, but the
  assignments themselves remain on record. Deactivation is reversible.
- **Reactivate:** Administrator reactivates a previously deactivated template.

Templates are never deleted — they are deactivated instead.

### 12.2 PM Assignments

A PM assignment connects a template to a specific asset. This is where PM
configuration becomes actionable.

**Who can manage assignments:**

- **Assign a template to an asset:** Administrator, Maintenance Manager.
- **Deactivate an assignment:** Administrator, Maintenance Manager.
- **Reactivate an assignment:** Administrator, Maintenance Manager.
- **Evaluate an assignment** (manually trigger PM check): Administrator,
  Maintenance Manager.

**Where assignments are managed:**

- **Asset Detail → PM Assignments section:** Lists all templates assigned to
  this asset. Shows per-asset PM status (🟢🟡🔴), schedule, last triggered, next
  due. Actions: Assign Rule, Evaluate, Deactivate, Reactivate.
- **PM Rules tab (Admin → PM Rules):** Shows a list of all templates with their
  assignment counts.

**Assignment evaluation — how it works:**

1. The daily scheduled job (or a manual "Evaluate All" trigger) checks all
   active assignments where both the assignment and its parent template are
   active.
2. For each assignment, it determines if the asset has reached its due threshold
   (date passed, reading exceeded, or whichever came first).
3. It checks for an active maintenance chain (pending MR or open/in-progress/
   completed WO for the same asset + template).
4. If no active chain exists and the threshold is met, a Preventive MR is
   generated.
5. The MR enters `pending_review` and follows the standard Manager review and WO
   workflow.

**Manual evaluation:**

- "Evaluate" on a single assignment — triggers PM check for one assignment only.
- "Evaluate All" (from PM Rules tab) — runs evaluation against every active
  assignment across all assets.

### 12.3 Cumulative Maintenance

When a standard-level PM Work Order closes, cumulative maintenance rules apply:

- Closing a higher-level WO (L2, L3, L4) resets the baselines of all active
  lower-level assignments on the same asset.
- Example: closing an L3 WO resets L1 and L2 baselines. The asset's L1 interval
  restarts from the L3 closure date — you don't need a separate L1 service
  immediately after a more comprehensive L3 service.
- This applies only to the standard L1-L4 level scheme; custom free-text levels
  are independent and do not cascade.

**Rationale:** When a major service (L3) is performed, it typically encompasses
the work of minor services (L1 and L2). Resetting lower-level baselines prevents
the system from immediately generating a redundant L1 PM request right after an
L3 overhaul.

### 12.4 PM Rule Tabs (Admin Area)

The PM Rules tab lives under the Admin sidebar item:

| Element | Visible To | Content |
|---|---|---|
| Template list | Admin only | All PM rule templates with name, level, trigger type, schedule, assignment count, active status. |
| Create/Edit | Admin only | Side-sheet form for template creation and editing. |
| Deactivate/Reactivate | Admin only | Toggle for individual templates. |
| Evaluate All | Admin only | Runs evaluation against every active assignment. |

**Note:** Creating and editing PM rule *templates* is Administrator-only by
design. Maintenance Managers do not manage the template library; instead they
assign templates to assets and manage each asset's PM *assignments* (assign,
evaluate, deactivate, reactivate) directly from the **Asset Detail → PM Rules**
section.

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

| Status | Description | Terminal |
|---|---|---|
| `pending_review` | Awaiting Manager review. Editable by creator or Admin/Manager. | No |
| `converted` | Approved and atomically converted to a Work Order. | Yes |
| `rejected` | Declined by Manager with required reason. | Yes |
| `cancelled` | Withdrawn before approval/conversion. | Yes |

### Work Order Statuses

| Status | Description | Terminal |
|---|---|---|
| `open` | Created from approved MR. May be unassigned. | No |
| `in_progress` | Work started. Must be assigned to active Technician. | No |
| `completed` | Technician submitted all work. Awaiting Manager closure. | No |
| `closed` | Reviewed and finalized. Permanently immutable. | Yes |
| `cancelled` | Cancelled by Admin/Manager with required reason. | Yes |

### Asset Maintenance Status

| Parent State (`value`) | Sub-Status | Applies To | PM Eligible |
|---|---|---|---|
| **Enrolled** (`enrolled`) — "In maintenance program" | *(none)* | `asset_kind = asset` (standalone) | Yes |
| **Enrolled** (`enrolled`) | `installed` | `asset_kind = component` or `package` | Yes |
| **Enrolled** (`enrolled`) | `ready` | `asset_kind = component` or `package` | Yes |
| **Withdrawn** (`withdrawn`) | `lih` | Any | No |
| **Withdrawn** (`withdrawn`) | `dbr` | Any | No |
| **Withdrawn** (`withdrawn`) | `disposed` | Any | No |
| **Withdrawn** (`withdrawn`) | `scrapped` | Any | No |
| **Withdrawn** (`withdrawn`) | `other` | Any | No |

### Asset Kinds

| Kind | Can Have Parent? | Can Have Children? |
|---|---|---|
| `asset` | No | No |
| `package` | Yes | Yes |
| `component` | Yes | No |

### PM Trigger Types

| Trigger | Behaviour |
|---|---|
| `date` | Generates MR when calendar interval elapses since last baseline. |
| `reading` | Generates MR when usage reading crosses threshold since last baseline. |
| `date_or_reading` | Generates MR when either condition is met — whichever comes first. |

### ERP Sync Job Statuses

| Status | Meaning |
|---|---|
| `running` | Sync job is currently executing. |
| `success` | Sync completed with no errors. |
| `partial` | Sync completed but some items had errors. |
| `failed` | Sync could not complete. |

---

## Appendix B: Role Permission Quick Reference

| Permission | Admin | Manager | Technician | Logistics | Requester |
|---|---|---|---|---|---|
| View dashboard | Yes | Yes | Yes | Yes | Yes |
| Create corrective MR | Yes | Yes | Yes | Yes | Yes |
| Update own pending MR | Yes | Yes | Yes | Yes | Yes |
| Update any pending MR | Yes | Yes | No | No | No |
| Cancel own pending CM MR | Yes | Yes | Yes | Yes | Yes |
| Cancel any pending MR | Yes | Yes | No | No | No |
| Approve / reject MR | Yes | Yes | No | No | No |
| View Work Orders | Yes | Yes | Yes (assigned) | No | No |
| Assign WO | Yes | Yes | No | No | No |
| Start WO | Yes | Yes | Yes (assigned) | No | No |
| Edit WO execution details | Yes | Yes | Yes (assigned) | No | No |
| Complete WO | Yes | Yes | Yes (assigned) | No | No |
| Close WO | Yes | Yes | No | No | No |
| Cancel WO | Yes | Yes | No | No | No |
| Create / edit asset | Yes | Yes | No | No | No |
| Change asset kind | Yes | Yes | No | No | No |
| Set parent_asset_id (outside WO) | Yes | Yes | No | No | No |
| Change asset maintenance status | Yes | Yes | No | No | No |
| Book / unbook asset | Yes | Yes | No | No | No |
| Install / remove / swap component | Yes | Yes | Yes (assigned WO) | No | No |
| Create MR for child component | Yes | Yes | No | No | No |
| Record meter reading | Yes | Yes | Yes | No | Unverified only |
| Confirm meter reading | Yes | Yes | Yes | No | No |
| View parts catalogue | Yes | Yes | Yes | No | No |
| Update local part fields | Yes | Yes | No | No | No |
| View raw ERP payload | Yes | No | No | No | No |
| Create / edit PM rule templates | Yes | No | No | No | No |
| Assign PM template to asset | Yes | Yes | No | No | No |
| Evaluate PM assignment | Yes | Yes | No | No | No |
| View PM templates | Yes | Yes | No | No | No |
| Trigger manual ERP sync | Yes | Yes | No | No | No |
| Manage ERP sync settings | Yes | No | No | No | No |
| Manage users | Yes | No | No | No | No |
| Import employees | Yes | No | No | No | No |
| Provision employees as users | Yes | No | No | No | No |
| Manage locations / master data | Yes | No | No | No | No |
| Manage company settings | Yes | No | No | No | No |
| View technical audit logs | Yes | No | No | No | No |
| Manage API clients | Yes | No | No | No | No |
| Update asset location | Yes | Yes | No | Yes | No |

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

**Asset kind:** Enum declaring what role an asset can play in the assembly
hierarchy: `asset` (leaf), `package` (can contain children), `component` (can be
installed in a parent).

**Asset tag:** A unique, human-readable physical label code in the format
`L-BBB-CCC-XXXX` designed for printing and future QR encoding.

**Assembly:** The parent-child hierarchy of assets. A parent package contains
child components, each of which is a full ATMS asset with its own maintenance
lifecycle.

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

**Withdrawn (asset maintenance status, `withdrawn`):** The asset is not in active
maintenance service. PM evaluation, CM creation, and WO creation are blocked.
(Renamed from the former "Inactive".)

**Installed (`installed`):** A sub-status indicating a component is currently
installed in a parent (`parent_asset_id` is set).

**Maintenance history:** A read-model view assembled from an asset's MRs, WOs,
parts used, readings, and location changes. Not stored in a duplicate table —
derived from authoritative source records.

**MR (Maintenance Request):** A request for maintenance work, either Corrective
(user-initiated) or Preventive (system-generated). All MRs must be reviewed by a
Maintenance Manager before becoming a Work Order.

**Package:** An asset that can contain child assets. Defined by `asset_kind =
package`.

**PM (Preventive Maintenance):** System-initiated maintenance based on configured
rules (date intervals, operating hours, kilometers, or other readings).

**PM assignment:** The connection between a PM rule template and a specific
asset. Defines the asset's baseline and determines when preventive MRs are due.

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
