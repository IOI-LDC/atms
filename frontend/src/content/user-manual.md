# ATMS User Manual

ATMS (Asset Maintenance Tracking System) manages the operational maintenance lifecycle for LDC equipment: from identifying a fault or due service, through Maintenance Manager review and Work Order execution, to final closure, operational reporting, and permanent maintenance history.

Within the broader product architecture, ATMS shares its database and backend foundation with planned **SM (Store Management)** and **AM (Asset Movement)** modules. Today, ATMS operates as a single web application covering assets, maintenance requests, work orders, preventive maintenance rules, direct asset location updates, bookings, parts reference and CSV stock reconciliation, and operational reports. Formal warehouse purchasing/inventory execution (SM), multi-step physical movement approvals (AM), and live ERP integration are planned for future phases.

---

## 1. Introduction

### 1.1 What ATMS Does

ATMS provides one clear workflow for all operational maintenance:

**Maintenance Request → Review and Approval → Work Order → Execution → Closure → Maintenance History**

There are two paths into this workflow:

- **Corrective Maintenance (Repair):** A user identifies a fault, damage, or performance issue with an asset and manually creates a Corrective Maintenance Request.
- **Preventive Maintenance (Service):** The system automatically generates a Preventive Maintenance Request when a configured schedule becomes due for an asset.

Both paths converge at the Maintenance Manager review step and follow the same Work Order lifecycle thereafter.

### 1.2 What ATMS Does Not Do

ATMS is intentionally focused on operational maintenance tracking. The following remain outside ATMS or in other systems:

- **Financial asset management** (capitalisation, depreciation, disposal accounting) — remains in the ERP.
- **Procurement, purchasing, and parts costing** — parts consumption is tracked by quantity only, never financial value.
- **Full warehouse execution and inventory ledger** — ATMS tracks consumption-adjusted quantities and supports manual CSV stock reconciliation, not warehouse receipts, transfers, or valuation.
- **Labor tracking and timesheets** — technician hours and performance appraisal are not tracked.
- **Technician personal stock / wallets** — parts held personally by technicians are not tracked.
- **Multi-step physical movement approvals** — ATMS provides direct location updates (Section 11), not formal movement requests, gate passes, shipments, or arrival approval chains.
- **Shift and crew handovers** — excluded.
- **Mandatory photo checklists or automated defect generation** — configurable **Work Order Execution Forms** (WO Forms) with Yes/No, numeric, and text fields, pre/post capture per **Maintenance Category**, snapshots, and sync-to-latest are included, while scoring and automatic defect generation are excluded.
- **Full document management system** — basic file attachments are supported, without versioning or document approval workflows.
- **Native mobile application and offline mode** — ATMS is a responsive web application requiring a network connection.
- **QR / barcode scanning UI** — asset tags are formatted for future QR encoding and an API tag lookup endpoint exists, but camera scanning is not part of the web UI.
- **IoT / automatic meter telemetry** — all meter readings are entered manually.
- **Predictive / AI maintenance** — PM is schedule-based.
- **Multi-level MR approval chains** — a single Maintenance Manager (or Administrator) review step converts an MR to a Work Order.
- **Custom BI report builder** — 20 live operational reports and Dashboard KPIs are provided (Sections 6 and 16), without an ad-hoc query builder.
- **ERP write-back** — ATMS never writes maintenance, stock, or asset changes back to the ERP.
- **Multi-tenant SaaS** — deployed for a single LDC environment.

### 1.3 Core Design Principles

1. **Operational Simplicity:** Screens use operational labels (**Repair** / **Service**, **Ready for Field**, **In ATMS**), avoid financial accounting language, and keep the main workflow clear.
2. **One Main Workflow:** Every Work Order originates from an approved Maintenance Request — there is no path to create a Work Order directly.
3. **External Reference Data Is Protected:** ERP-sourced identifiers (such as `erp_asset_code`, `fa_subclass_code`, and `erp_part_code`) are read-only in the web UI so operational edits do not corrupt external correlation keys.
4. **Statuses Are Always Visible:** Every asset, maintenance request, work order, and PM rule shows clear status badges.
5. **Confirmation Before Persistent Changes:** Create, edit, transition, upload, and deactivation actions use confirmation dialogs to prevent accidental updates.
6. **Role-Based Access Throughout:** Navigation, tabs, lists, and actions adapt to each user's assigned role, backed by server-side authorization policies.
7. **History Preservation Over Hard Deletion:** Core records (assets, users, locations, lists, PM rules, form templates) are deactivated rather than deleted, and attachments and meter readings are soft-deleted (`deleted_at`). Only draft/line-level items — such as removing a part line before a Work Order completes or removing a field from a WO Form template — are physically removed.

---

## 2. Getting Started

### 2.1 How Accounts Are Created

ATMS does not support self-registration. Administrators create all human accounts directly from **Admin → Users & Access**:

1. The Administrator enters the user's **Name**, **Email**, and **Role** (Administrator, Maintenance Manager, Technician, Logistics, or Requester). The email must belong to the `@ldc.com.ly` domain (case-insensitive; configurable via allowlist).
2. The account is created in **Pending Activation** (`is_active = false`) with a random password.
3. ATMS emails a one-time activation link through Microsoft Graph.
4. The user opens the activation link, sets their own password (minimum 8 characters), and activates the account.

There is no external directory import or sync — opening the activation email verifies mailbox ownership.

### 2.2 Logging In

1. Open ATMS in your browser (`/login`).
2. Enter your work email address and password.
3. Optionally tick **Remember me for 30 days** to keep your session signed in across browser restarts.
4. Select **Sign in**. On success, you are directed to the Dashboard — or back to the specific page you originally opened if you arrived via a deep link (`?redirect=`).
5. If your account is not yet activated or has been deactivated, login returns **"Account is not active."** Contact your Administrator if you need a fresh account or reactivation.

**Rate limiting:** Login is limited to 5 requests per minute per IP address, and 5 failed attempts within 60 seconds for the same IP + email combination.

### 2.3 Activating Your Account

1. Open the activation link from your welcome email (`/activate?token=...`). The token is read automatically from the link — do not strip the query string.
2. Enter your **Password** (at least 8 characters) and **Confirm password**.
3. Submit the form. On success, ATMS automatically redirects you to `/login` after 2 seconds so you can sign in.

Activation tokens are single-use and expire after **24 hours**. Activation attempts are rate-limited to 5 requests per minute.

### 2.4 Resetting a Forgotten Password

1. On the login page, select **Forgot password?** (`/forgot-password`).
2. Enter your work email address and submit. ATMS sends a reset link through Microsoft Graph if a matching account exists (the confirmation message is generic so email existence is not disclosed).
3. Open the reset link from the email (`/reset-password?token=...`).
4. Enter your **New password** (at least 8 characters) and **Confirm new password**, then submit.
5. All existing sessions and API tokens for your account are invalidated, and ATMS redirects you to `/login` after 2 seconds.

Password reset tokens are single-use and expire after **1 hour**. Both forgot-password and reset-password endpoints are rate-limited to 5 requests per minute.

**Admin-assisted password reset:** If a user cannot receive a reset email, an Administrator can reset their password directly from **Admin → Users & Access** by setting a new password (minimum 8 characters). This immediately signs the user out of all active sessions and revokes API tokens; the Administrator then communicates the temporary password to the user, who should change it immediately.

### 2.5 Changing Your Password

Any signed-in user (including Administrators) can change their own password in-app:

1. Open the **User menu** in the top-right corner of the header bar and select **Change password**.
2. Enter your **New password** (at least 8 characters) and **Confirm new password**, then confirm.
3. All active sessions and tokens are invalidated and you are redirected to `/login` to sign in with your new password.

### 2.6 My Profile

Every signed-in user can view their own account details by opening the **User menu → My Profile** (`/profile`).

The profile page has two cards:

- **Account:** Displays your Name, Email, Email verification badge, Employee ID (if recorded), Role, Account status, Member since date, and Last updated date.
- **Role & Access:** Summarizes what your assigned role can do in ATMS and provides quick-action links relevant to your role.

### 2.7 Logging Out

Open the **User menu** in the top-right corner of the header bar and select **Sign out**. Your current session is invalidated and you are returned to `/login`.

### 2.8 Machine-to-Machine (M2M) API Access

A sixth, non-human **Service** role (`service`, backing `service@atms.internal`) exists for programmatic integrations. It cannot be assigned to human users and never logs in through the web UI.

- Administrators manage API clients through backend endpoints (`GET /api/admin/api-clients`, `POST /api/admin/api-clients`, `DELETE /api/admin/api-clients/{client}`). There is currently **no web UI** for API client management.
- When an API client is created, its `client_secret` is returned once and must be stored securely.
- Clients exchange `client_id` and `client_secret` at `POST /api/auth/token` (rate-limited to 5 requests/minute) for a Sanctum bearer token.
- Tokens carry explicit abilities — default `read` (blocks `POST`/`PUT`/`PATCH`/`DELETE` requests with `403`), or `write` when granted.

---

## 3. Your Role & What You Can Do

ATMS uses five fixed human roles (plus the non-human Service role). Every user has exactly one role. Roles are seeded system data and cannot be created, renamed, or deleted.

### 3.1 Administrator

The Administrator has full operational and configuration access across ATMS:

- **Users & Access:** Create users (name, email, role), edit user name/email/role, activate/deactivate accounts (deactivation revokes all sessions and tokens), and set a new password via Admin Reset Password. Administrators cannot edit, deactivate, or reset their own account through `/admin/users` (use **User menu → Change password** for your own password).
- **Asset Management:** View active and inactive assets, edit asset fields, suggest/set an initial asset tag, change asset kind and maintenance status, change Condition (via Edit Asset or the inline command-bar selector), manage bookings (create, edit, override conflicts, cancel), and upload/view/delete attachments. *(Note: the **Add Asset** UI button is currently disabled — Phase 2; assets enter via workbook CLI imports or API.)*
- **Maintenance Requests:** Create corrective MRs, view all MRs, edit any pending MR, approve MRs (with optional atomic assignee selection and optional location move), reject or cancel any pending MR, and upload/delete MR attachments.
- **Work Orders:** View all WOs, assign/reassign WOs to active Technicians or Maintenance Managers, start/complete/close/cancel WOs, edit work notes and Completion checklists, record/remove parts, print Part Requests, record/edit/delete unconfirmed meter readings, stage/override/suppress PM level marks, update asset operational status, and upload/delete WO attachments (before closure/cancellation).
- **Parts Management:** View active and inactive parts, edit local UI fields (`name`, `available_quantity`, `maintenance_category_id`, `size_inches`, `is_active`), upload/view/delete part attachments, view Raw ERP Data on Part Detail, and **Download CSV / Upload corrected quantities** (Administrator-only).
- **Locations:** Move active enrolled assets via **Locations → Asset Location Update**, and create, edit, activate, and deactivate locations in **Locations → Manage Locations**.
- **PM Rules (Service):** Create single or multi-level PM rule templates, edit/deactivate/reactivate templates, configure category coverage, run **Evaluate All**, and assign/evaluate/deactivate/reactivate assignments on Asset Detail.
- **Administration & Audit:** Manage **Lists & Dropdowns** (Maintenance Priorities, Asset Conditions, Maintenance Categories, Usage Reading Types), **WO Forms** templates and fields, **Audit Logs** (`/settings/audit-logs`), backend API clients, and server CLI import commands.
- **Dashboard & Reports:** View the Dashboard and all 20 Reports (including Technician and PM Rule filter dropdowns and CSV exports).

### 3.2 Maintenance Manager

The Maintenance Manager oversees maintenance approvals, Work Order execution, asset readiness, and PM assignments:

- **Asset Management:** View active and inactive assets, edit asset fields, suggest/set an initial asset tag, change asset kind and maintenance status, change Condition (via Edit Asset or inline on Asset Detail), manage bookings, move assets via **Locations → Asset Location Update** or **Edit Asset**, and upload/view/delete attachments.
- **Maintenance Requests:** Create corrective MRs, view all MRs, edit any pending MR, approve MRs (including failure classification for repairs, optional assignee, and optional location move), reject or cancel any pending MR, and upload/delete attachments.
- **Work Orders:** View all WOs, assign/reassign WOs, start/complete/close/cancel WOs, edit work notes and checklists, add/remove parts, print Part Requests, record/edit/delete unconfirmed meter readings, stage/override/suppress PM marks, update asset operational status, and upload/delete attachments (before closure/cancellation).
- **Parts Management:** View active and inactive parts, edit the 5 local UI fields on Part Detail, view ERP Status and Last ERP Sync, and upload/view/delete part attachments. Cannot view Raw ERP Data or run bulk CSV quantity uploads.
- **PM Rules (Service):** Assign active templates to assets, evaluate single assignments, and deactivate/reactivate assignments from **Asset Detail → PM Rules (Service)**. Can open a specific PM Rule Detail page (`/admin/pm-rules/:ruleId`) via deep link from Asset Detail, but cannot access **Admin → PM Rules (Service)** or create/edit/deactivate templates.
- **Dashboard & Reports:** View the Dashboard and all 20 Reports (including Technician and PM Rule filters and CSV exports).

**What a Maintenance Manager cannot do:** Manage users, manage location definitions, manage Lists & Dropdowns or WO Form templates, create/edit PM rule templates, run bulk Parts CSV quantity uploads, view Raw ERP Data, view Audit Logs, or manage API clients.

### 3.3 Technician

The Technician executes assigned Work Orders and submits repair requests:

- **Maintenance Requests:** Create corrective MRs, view their own submitted MRs (`My Requests`), edit their own pending corrective MRs (`priority` and `description` in the UI), and upload attachments (can delete own attachments only while the MR is `pending_review`).
- **Work Orders:** View Work Orders assigned to them (`My Work Orders`, `Open`, `In Progress`, `Completed`, `Closed`), start assigned WOs (supplying a workshop/yard location if needed), edit work notes and fill the **Completion checklist** while `open` or `in_progress`, add/remove parts while `open` or `in_progress`, print Part Requests, record/edit/delete unconfirmed meter readings, stage/clear PM level marks while `open` or `in_progress`, update asset operational status (Ready for Field, Under Maintenance, or Failure) on assigned non-terminal WOs, upload attachments through `completed`, and mark assigned `in_progress` WOs as `completed`.
- **Assets & Parts:** View **active** assets (including maintenance history, location history, meter readings, and attachments; can upload asset attachments) and view **active** parts (can upload part attachments).
- **Dashboard & Reports:** View the Dashboard (workboard scoped to assigned WOs) and all 20 Reports (without Admin/Manager-only Technician and PM Rule filter dropdowns).

**What a Technician cannot do:** Approve, reject, or cancel Maintenance Requests; assign, reassign, close, or cancel Work Orders; view inactive assets or inactive parts; edit asset or part records; manage bookings; move assets from the Locations section; delete attachments on assets, parts, or Work Orders; manage PM rules/assignments; or access Admin/Settings.

### 3.4 Logistics

The Logistics role focuses on physical asset locations and bookings:

- **Locations:** Access **Locations → Asset Location Update** to search assets by location or name/code and move eligible assets (Ready for Field or At the Field).
- **Asset Management:** View **active** assets, view location history, and create, edit, override conflicts on, and cancel **Bookings** on Asset Detail. The `ERP Reference` card is hidden in the UI for Logistics.
- **Dashboard & Reports:** View the Dashboard (Needs Attention shows `asset(s) down` only; Active Workboard is hidden) and all 20 Reports.
- **Maintenance Requests:** Can access **Maintenance Requests → My Requests** in the sidebar. *(Note: backend authorization currently restricts creating, editing, and cancelling MRs to Admin, Manager, Technician, and Requester — Logistics cannot submit new MRs.)*

**What a Logistics user cannot do:** Create, edit, approve, reject, or cancel Maintenance Requests; record meter readings; view or execute Work Orders; view Parts Management; manage location definitions; view asset maintenance history; view/download asset or part attachments; manage PM rules; or access Admin/Settings.

### 3.5 Requester

The Requester is the base operational role for reporting faults and tracking submitted requests:

- **Maintenance Requests:** Create corrective MRs (including attachments), view their own submitted MRs (`My Requests`), edit their own pending corrective MRs (`priority` and `description` in the UI), cancel their own pending corrective MRs (with required reason), upload attachments to their own MRs, and delete their own attachments while `pending_review`.
- **Dashboard & Reports:** View the Dashboard and all 20 Reports.
- **Profile & Password:** View **My Profile** and change their own password.

**What a Requester cannot do:** Approve or reject Maintenance Requests; cancel preventive MRs or other users' MRs; view or execute Work Orders (sidebar hidden); access Asset Management, Parts Management, Locations, Admin, or Settings in the sidebar; edit assets or parts; manage bookings; record meter readings in the web UI (no UI form is exposed to Requesters); or manage PM rules.

---

## 4. Navigating the System

### 4.1 Application Shell

ATMS uses a three-part layout:

- **Left sidebar:** Flat, single-level navigation with the ATMS logo at the top and up to eight primary links adapted to your role.
- **Header bar:** Runs across the top of the workspace:
  - **Left side — Sidebar toggle** (menu icon): Collapses the sidebar to icon-only mode on desktop, or opens it as a slide-in drawer on mobile.
  - **Right side — User Manual button** (book icon): Opens this in-app User Manual (`/user-manual`) with sidebar search and a **Print** button. Available to everyone.
  - **Right side — User menu** (avatar initials, name, role, chevron): Opens a dropdown with your email address, **My Profile** (`/profile`), **Change password**, **Settings** submenu (Administrator only → **System** and **Audit Logs**), and **Sign out**.
- **Main content area:** Displays lists, tabs, forms, detail views, and reports.

### 4.2 Sidebar Structure

| # | Sidebar Item | Layout | Route | Visible To |
| --- | --- | --- | --- | --- |
| 1 | **Dashboard** | Direct Link | `/dashboard` | Everyone |
| 2 | **Maintenance Requests** | Tabbed Page | `/maintenance` | Everyone |
| 3 | **Work Orders** | Tabbed Page | `/work-orders` | Admin, Manager, Technician |
| 4 | **Asset Management** | Tabbed Page | `/assets` | Admin, Manager, Technician, Logistics |
| 5 | **Parts Management** | Direct Link (no tabs) | `/parts` | Admin, Manager, Technician |
| 6 | **Locations** | Tabbed Page | `/locations` | Admin, Manager, Logistics |
| 7 | **Reports** | Direct Link | `/reports` | Everyone |
| 8 | **Admin** | Tabbed Page | `/admin` | Admin only |

**Settings** (`System` and `Audit Logs`) is reached from **User menu → Settings** (Administrator only), not the sidebar.

### 4.3 Tabbed Content Areas

Sections with multiple views display horizontal tabs across the top of the page, filtered by role:

- **Query-parameter tabs (`?tab=...`):** Used by **Maintenance Requests** (`my-requests`, `pending-approval`, `all-requests`), **Work Orders** (`my-work-orders`, `all`, `open`, `in-progress`, `completed`, `closed`), **Asset Management** (`all-assets`, `assembly`), and **Locations** (`asset-location-update`, `manage-locations`).
- **Path-based tabs:** Used by **Admin** (`/admin/lists`, `/admin/pm-rules`, `/admin/wo-forms`, `/admin/users`). Navigating to `/admin` redirects to `/admin/lists`.

### 4.4 Drill-Down & Non-Sidebar Screens

Detail screens and utility pages open without sidebar entries:

| Screen | Route | Accessed From |
| --- | --- | --- |
| **Maintenance Request Detail** | `/maintenance/requests/:requestId` | Clicking a row in Maintenance Requests or Dashboard Active Workboard |
| **Work Order Detail** | `/work-orders/:workOrderId` | Clicking a row in Work Orders, Dashboard Active Workboard, or MR Detail link |
| **Printable Part Request** | `/work-orders/:workOrderId/part-request` | **Print…** button in the `Part Request` card on Work Order Detail |
| **Asset Detail** | `/assets/:assetId` | Clicking a row in All Assets (displays Overview, Maintenance History, Meter Readings, PM Rules, Bookings, ERP Reference, Location History, and Attachments as inline cards — there are no separate sub-routes) |
| **Part Detail** | `/parts/:partId` | Clicking a row in Parts Reference (displays Part Details, ERP Reference, and Attachments as inline cards) |
| **PM Rule Detail** | `/admin/pm-rules/:ruleId` | Clicking a template in Admin → PM Rules (Service) (Admin), or clicking a rule link on Asset Detail (Admin and Manager) |
| **Individual Report Pages** | `/reports/:slug` | Clicking any of the 20 cards in the Reports catalogue |
| **My Profile** | `/profile` | User menu → **My Profile** (all roles) |
| **User Manual** | `/user-manual` | Header book icon (all roles) |
| **System Settings** | `/settings/system` | User menu → **Settings → System** (Admin only; currently Coming soon) |
| **Audit Logs** | `/settings/audit-logs` | User menu → **Settings → Audit Logs** (Admin only) |

### 4.5 Role Visibility Summary by Sidebar Section

| Section | Requester | Technician | Logistics | Manager | Admin |
| --- | --- | --- | --- | --- | --- |
| Dashboard | Yes | Yes | Yes | Yes | Yes |
| Maintenance Requests | Yes | Yes | Yes | Yes | Yes |
| Work Orders | — | Yes | — | Yes | Yes |
| Asset Management | — | Yes | Yes | Yes | Yes |
| Parts Management | — | Yes | — | Yes | Yes |
| Locations | — | — | Yes | Yes | Yes |
| Reports | Yes | Yes | Yes | Yes | Yes |
| Admin | — | — | — | — | Yes |

---

## 5. Core Concepts

### 5.1 What Is an Asset?

An asset is any physical item tracked in ATMS for maintenance. Every maintenance request, work order, meter reading, booking, location move, and PM schedule revolves around an asset.

There is currently **no live ERP asset sync**. Assets enter ATMS through client-provided CSV/Excel workbook imports executed via Artisan CLI commands (Section 13.5) or through the backend API, and are then maintained operationally inside ATMS.

#### The Asset Data Model

Key fields stored in the `assets` table:

**Identification & Classification:**

| Field | Type | Purpose |
| --- | --- | --- |
| `erp_asset_code` | string (unique) | Links the asset to its ERP fixed-asset record. Required on creation. Returned by the API to **Administrators only**. |
| `name` | string (required) | Human-readable asset name (e.g. `Mud Motor 6 3/4" Lobe`). |
| `description` | text (nullable) | Free-text operational notes. |
| `serial_number` | string (nullable) | Manufacturer serial number. Used in asset tag generation and search. |
| `model` | string (nullable) | Manufacturer model designation. |
| `manufacturer` | string (nullable) | Manufacturer name or vendor code. |
| `size_inches` | decimal(9,5) (nullable) | Nominal size in inches, formatted in O&G fractional notation (e.g. `6 3/4"`). Used in display, search, reports, and part compatibility matching. |
| `maintenance_category_id` | FK → `maintenance_categories` (required) | ATMS-owned Maintenance Category (defaults to **Unclassified**). Routes WO Execution Form templates, PM category coverage, part compatibility, and report grouping. |
| `fa_subclass_code` | string (nullable, max 20) | ERP Fixed Asset subclass code (e.g. `MUD MOTOR`, `JARS`). Read-only ERP reference metadata used **only** to look up the 3-letter type code during asset tag generation — never for WO Forms or reports. |

**Status Dimensions:**

Every asset carries four orthogonal operational dimensions plus an active-registry flag (`is_active`):

| Field | Type | Question It Answers | Values |
| --- | --- | --- | --- |
| `operational_status` | `OperationalStatus` enum | **Is the asset working right now?** | `ready_for_field`, `under_maintenance`, `failure`, `at_the_field` |
| `maintenance_status` | `MaintenanceStatus` enum | **Is the asset in the maintenance program?** | `enrolled` (**In ATMS**), `withdrawn` (**Withdrawn**) |
| `condition_status` | string (vocabulary) | **What condition is it in between jobs?** | **Normal** (default), **Need Assembly**, **Missing Parts**, **Need Inspection** (Admin-configurable, Section 5.10) |
| `is_booked` | boolean (derived) | **Is it reserved today?** | Derived from the `bookings` table (`true` if an active booking covers today) |
| `is_active` | boolean | **Is the asset in the active fleet?** | `true` = active; `false` = deactivated (permanently out of service) |

**Hierarchy, Tag, Location & ERP Reference:**

| Field | Type | Purpose |
| --- | --- | --- |
| `asset_kind` | `AssetKind` enum | Role in assembly hierarchy: `asset`, `package`, or `component` (Section 5.2). |
| `parent_asset_id` | FK → `assets.id` (nullable) | Schema link for future Phase 2 parent-child assembly relationships. |
| `asset_tag` | string (max 15, unique, nullable) | Physical label code (`L-BBB-CCC-XXXX`). Immutable in the UI once saved (Section 5.3). |
| `asset_tag_generated_at` / `asset_tag_override_reason` | timestamp / text (nullable) | Tracks tag generation and API-level Admin overrides. |
| `current_location_id` | FK → `locations.id` (nullable) | Current physical location (Section 11). |
| `erp_raw_data` | JSON (nullable) | Unmodified ERP import record. Returned by the API to **Administrators only** (not currently rendered in the Asset Detail UI). |
| `erp_last_synced_at` | timestamp (nullable) | Timestamp of last ERP/workbook refresh. |

#### Asset Activation vs. Deactivation

Assets are never physically deleted. Taking an asset permanently out of service is done by setting `is_active = false` (reversible by Admin or Manager):

- **Visibility:** Technicians, Logistics, and Requesters only see **active** assets (`is_active = true`). Administrators and Maintenance Managers see both active and deactivated assets in **All Assets** by default.
- **Operational blocks:** Deactivated assets are blocked from new MRs, MR approval, WO assignment and start, all PM evaluation paths, bookings, meter readings, and manual location updates.
- **Auto-release of bookings:** Deactivating an asset automatically releases any active bookings (`status = released`).
- **History preserved:** Past MRs, WOs, meter readings, location history, and attachments remain intact.

#### ERP Asset Code & FA Subclass Code

`erp_asset_code` links an ATMS asset to its ERP fixed-asset record. Three CLI import commands use it (Section 13.5):

- `php artisan atms:import-assets` validates and updates **existing** assets matched on `erp_asset_code` (with optional `--dry-run` and `--prune`), and records any unseen `fa_subclass_code` in `fa_subclass_type_codes` with type code `UNK`.
- `php artisan atms:import-erp-assets` is the legacy initial-load command that truncates and imports an ERP CSV dump (it does not upsert).
- `php artisan atms:sync-asset-names` updates only `name` and `size_inches` from the workbook CSV.

`fa_subclass_code` is ERP reference data stored for asset tag type-code lookup only — it does **not** route WO Forms or reports. Seeded examples in `fa_subclass_type_codes`:

| `fa_subclass_code` (ERP Value) | Tag Type Code | Meaning |
| --- | --- | --- |
| `MUD MOTOR` | `MTR` | Mud Motor |
| `MWD/LWD` | `MWD` | MWD / LWD Tools |
| `DHT` | `DHT` | Downhole Tools |
| `JARS` | `JAR` | Jars |
| `MACHEQ` | `MEQ` | Machinery / Equipment |

### 5.2 Asset Kinds

Every asset carries an `asset_kind` (`App\Enums\AssetKind`) defining its intended role in the planned Phase 2 assembly hierarchy:

| Kind | DB Value | Intended Role | Typical Example |
| --- | --- | --- | --- |
| **Asset** | `asset` | Standalone unit (default). No parent, no children. | Standalone pump, generator |
| **Package** | `package` | Can contain child assets and be installed inside a parent. | Mud motor, power section |
| **Component** | `component` | Installable inside a parent; cannot contain children. | Radial bearing, sensor |

**Who can change Asset Kind:** Only **Administrator** and **Maintenance Manager** can change `asset_kind` (classified as a lifecycle field alongside `maintenance_status`) via the **Edit Asset** sheet. *(Note: `POST /api/assets` does not currently persist `asset_kind` or `maintenance_status` — newly created assets start with the database defaults `asset` and `enrolled`, and no child-count guard is currently enforced when changing `asset_kind`.)*

### 5.3 Asset Tags

Each asset can carry a unique, human-readable **asset tag** formatted for physical labelling and future QR encoding:

```
L - BBB - CCC - XXXX
│    │      │      └─ Serial suffix: last 4 digits of serial number (or fallback from erp_asset_code)
│    │      └──────── Size code: encoded feet (FT) or inch measurement, or 000 if none found
│    └─────────────── Type code: 3-letter abbreviation (ROTOR→RTR, STATOR→STR, or fa_subclass_type_codes)
└──────────────────── Ownership: L (LDC-owned — currently hardcoded to L)
```

**Segment rules (implemented in `AssetTagService`):**

- **Ownership (1 char):** Always `L` (LDC-owned).
- **Type code (3 chars):** Checks the asset description for keywords `ROTOR` (`RTR`) and `STATOR` (`STR`) first; otherwise looks up `fa_subclass_code` in `fa_subclass_type_codes` (unknown codes default to `UNK`).
- **Size code (3 chars):** Parses foot measurements (`FT`) first, then inch measurements (converting decimals/fractions to a 3-digit code, e.g. `6 3/4"` → `634`, `8"` → `800`), or `000` if no size is parsed.
- **Serial suffix (4 chars):** Extracts **digits only** from `serial_number` (last 4 digits, left-padded with zeros). If `serial_number` is blank or contains no digits, falls back to the last 4 characters of `erp_asset_code`.

**How tags are assigned in the UI:**

- When an asset has no tag yet (`asset_tag` is blank), an Administrator or Maintenance Manager can click **Suggest Tag** inside the **Edit Asset** sheet (`POST /api/assets/{asset}/suggest-tag`), review or adjust the suggested value, and save.
- Once saved, `asset_tag` is displayed **read-only** in the UI (`immutable once saved`). Overriding an existing tag with a mandatory `asset_tag_override_reason` is supported for Administrators via the backend API only (`PATCH /api/assets/{asset}`).
- A lookup endpoint (`GET /api/assets/by-tag?tag=...`) exists on the backend for future QR/barcode integration.

### 5.4 Asset Maintenance Status

**Asset Maintenance Status** (`maintenance_status`) controls whether an asset participates in the maintenance program, independent of ERP accounting:

- **Enrolled (`enrolled`) — displayed as "In ATMS"** on badges, cards, and reports (and as `"In maintenance program — eligible for maintenance workflows"` inside the Edit Asset dropdown). Eligible for PM rules, corrective MRs, WO assignment/start, bookings, and location moves.
- **Withdrawn (`withdrawn`) — displayed as "Withdrawn"**. Hard gate that blocks PM evaluation on every path, blocks new corrective MRs, blocks approving pending MRs, blocks assigning/starting WOs, blocks manual location updates (`409`), blocks new bookings, and automatically **releases** active bookings. All historical records remain viewable.

Only **Administrator** and **Maintenance Manager** can change maintenance status via **Edit Asset**. Re-enrolling a withdrawn asset restores eligibility immediately and triggers background category PM reconciliation; existing PM baselines remain intact.

### 5.5 Asset Booking

Booking reserves an active, enrolled asset for a job or project over a date range (`bookings` table), preserving full booking history:

| Field | Purpose |
| --- | --- |
| `booked_from` / `booked_until` | Reservation date range (required). |
| `booking_reference` | Optional job/project reference (e.g. `JOB-2026-014`). |
| `notes` | Optional free-text notes. |
| `booked_by` | User who created the booking. |
| `status` | `active`, `cancelled`, or `released`. |
| `cancelled_at` | When the booking was cancelled or released. |

**Key rules:**

- **Derived flag:** `is_booked` is true when an `active` booking covers **today** (`booked_from <= today <= booked_until`).
- **Workflow independence:** Booking never blocks MRs, WOs, PM evaluation, or location moves, and bookings **survive** maintenance events and location changes.
- **Overlap detection on create:** When creating a new booking that overlaps an existing active booking on the same asset, ATMS warns and lists the conflicting bookings with a **Book Anyway** force-override option. *(Note: overlap detection runs on booking creation, not when editing an existing booking.)*
- **Auto-release & manual cancel:** Deactivating (`is_active = false`) or withdrawing (`maintenance_status = withdrawn`) an asset automatically sets active bookings to `released`. Authorized users can manually cancel any active booking (`cancelled`).
- **Who can manage bookings:** **Administrator**, **Maintenance Manager**, and **Logistics** can create, edit, override conflicts on, and cancel bookings from the **Bookings** card in the Asset Detail right rail.

### 5.6 Asset Assembly (Phase 2 — Not Yet Available in UI)

> **Phase 2 status:** Full assembly operations — installing, removing, and swapping components, assembly history timelines, derived component operating hours, cycle enforcement, and parent-WO child PM cross-checks — are **not built yet**. The **Asset Management → Asset Assembly** tab displays a `"Coming in Phase 2"` placeholder.

What exists today in the database and UI:

- `asset_kind` (`asset`, `package`, `component`) can be set on assets via **Edit Asset** (Admin/Manager) so the register is prepared for Phase 2.
- The `assets.parent_asset_id` column exists in the schema, though there is no UI to attach or view child components yet.
- **Cumulative maintenance cascade** (unrelated to parent-child assemblies) **is live today** on individual assets: closing a Work Order for any numbered PM level (`L1`, `L2`, `L3`, `L4`, `L5`, etc.) automatically resets the baselines of all active lower-numbered `L<n>` PM assignments on that same asset (Section 12.8).

### 5.7 Parts Data Ownership

Today, ATMS holds the **Parts Reference** catalogue locally without a live ERP feed:

- **How parts enter and update:** Full catalogue loads and updates come from the cleaned LDC Excel/CSV workbook via `php artisan atms:import-parts` (Section 10.4). Bulk stock-quantity corrections are uploaded by Administrators through the **Download CSV / Upload quantities** round-trip in the Parts UI (Section 10.4a). Single-part local fields are editable on Part Detail by Admin/Manager.
- **Work Order consumption:** Recording a part line on an `open` or `in_progress` Work Order immediately decrements `available_quantity`; removing that line before completion restores it.
- **Read-only ERP identifiers:** `erp_part_code` (displayed as **Part Number**), `erp_status`, `erp_last_synced_at`, and `erp_raw_data` are read-only in the web UI.
- **Planned Phase 3 ERP sync:** Live ERP integration is deferred to Phase 3. See Section 10.4 for full details.

### 5.8 Asset Location Data Ownership

ATMS directly manages both the active locations list (**Locations → Manage Locations**, Admin-only) and physical asset moves (**Locations → Asset Location Update** for Admin/Manager/Logistics, plus inline location updates in **Edit Asset** for Admin/Manager and optional location moves at MR approval / WO start). Multi-step movement approval workflows (AM) are planned for a future phase.

### 5.9 Asset Operational Status

`operational_status` (`App\Enums\OperationalStatus`) answers: **is the asset working right now?**

| DB Value | Display Label | Meaning |
| --- | --- | --- |
| `ready_for_field` | **Ready for Field** | Serviceable and at base, available for deployment. |
| `under_maintenance` | **Under Maintenance** | Actively being serviced in the workshop. |
| `failure` | **Failure** | Has a confirmed fault and requires repair. |
| `at_the_field` | **At the Field** | Deployed at a `rig` or `well_site` location. **Derived from location — never manually selectable.** |

#### Automated Work Order Transitions

`ApplyWorkOrderAssetStatusTransition` runs on active assets (deactivated assets are skipped):

| Event | Target Status | Logic |
| --- | --- | --- |
| **Corrective MR approved** → WO created | `failure` | Applied unless the asset is already `under_maintenance`. Preventive MRs do not trigger this. |
| **WO started** (`open` → `in_progress`) | `under_maintenance` | Always applied when work starts. |
| **WO closed** (`completed` → `closed`) | `ready_for_field` | Always applied (no status choice at close). Closure also resets Condition to the default (`Normal`). |
| **WO cancelled** (from `open`, `in_progress`, or `completed`) | Caller-chosen (`failure` or `ready_for_field`) | The Cancel dialog asks whether the asset is still faulty (**Failure**) or usable (**Ready for Field**). |

#### Location-Driven Transitions & Move Gating

- **Moving to a `rig` or `well_site`:** Automatically sets `operational_status` to **At the Field** (`at_the_field`). Creating an asset directly at a rig or well site via API also sets `at_the_field`.
- **Moving back from a rig/well site to a `yard` or `building` (manual move):** Automatically flips `operational_status` back to **Ready for Field** and sets Condition to **Need Inspection**.
- **Maintenance moves exempt:** Location moves performed during **MR Approval** or **WO Start** (`applyStatusRules: false`) do **not** trigger the `Need Inspection` / `At the Field` transition rules.
- **Manual move gating:**
  - **Ready for Field** (or `null` status) — can be moved to any active location.
  - **At the Field** — can only be moved back to a `yard` or `building` (cannot be moved manually to a `workshop` or directly to another `rig`/`well_site`).
  - **Failure** and **Under Maintenance** — blocked from manual moves (`409` conflict); they move through maintenance workflows instead.
  - **Deactivated or Withdrawn** — blocked from manual moves (`409` conflict).

#### Manual Status Updates

- **From a Work Order (`Update status…` button):** Available on `open`, `in_progress`, and `completed` WOs to **Administrator**, **Maintenance Manager**, and the **assigned Technician** (`POST /api/work-orders/{wo}/asset-status`). Offers **Ready for Field**, **Under Maintenance**, and **Failure** (never `At the Field`). Blocked once closed or cancelled.
- **From Edit Asset:** Available to **Administrator** and **Maintenance Manager** (and `Service` via API), offering the same three selectable values. The Operational Status dropdown is **disabled** while the asset is `at_the_field`. If a location change in the same save changes `operational_status`, the location rule takes precedence and any conflicting status payload is discarded and audited (`asset.status_payload_discarded`).

#### Taking an Asset Out of Service

There is no scrapped operational status — permanently retiring an asset is done by **deactivating it** (`is_active = false`, Section 5.1).

### 5.10 Asset Condition Labels

**Condition** (`condition_status`) is an informational label describing an asset's physical readiness between jobs — it never blocks any workflow:

| Value | Label | Meaning |
| --- | --- | --- |
| `normal` | **Normal** | System default (`is_default = true`). Nothing outstanding. |
| `need_assembly` | **Need Assembly** | Needs assembly work before its next job. |
| `missing_parts` | **Missing Parts** | Missing parts that need to be sourced. |
| `need_inspection` | **Need Inspection** | Needs inspection before its next job. Set automatically when an asset returns from a rig or well site. |

**Key rules:**

- **How it is changed:** Administrators and Maintenance Managers can update Condition directly from the **inline Condition selector** in the Asset Detail command bar or via **Edit Asset** (also writable by `Service` via API).
- **Reset on WO closure:** Closing a Work Order resets Condition to the default (`Normal`). If the previous Condition was **Need Inspection** and **no PM level was recorded** on that closure, ATMS shows a non-blocking reminder note. *(If the asset is deactivated, Condition reset still runs if it differs from default.)*
- **Admin-configurable:** Administrators manage Condition labels under **Admin → Lists & Dropdowns → Asset Conditions**. The default item (`normal`) cannot be deactivated, though its label can be renamed.

---

## 6. Dashboard

The **Dashboard** (`/dashboard`) is the landing page for all authenticated users, providing real-time operational health and trailing 90-day performance metrics.

**Visible to:** Everyone.

### 6.1 Needs Attention

Up to three clickable summary tiles appear at the top of the page:

| Tile | Shows | Links To | Visible To |
| --- | --- | --- | --- |
| **asset down / assets down** | Count of active assets with `operational_status = failure`. | `/assets` | Everyone |
| **PM overdue** | Count of overdue active PM assignments (currently capped at **5** by the dashboard widget query; see note below). | `/reports/overdue-pm` | Admin, Manager, Requester |
| **Requests pending review** | Count of MRs in `pending_review` (scoped to own MRs for Requester; all MRs for Admin/Manager). | `/maintenance` | Admin, Manager, Requester |

Tile visibility is strictly **role-based** — Admin, Manager, and Requester always see all three tiles (even when counts are `0`), while Technician and Logistics see only `asset(s) down`.

> **Note on PM overdue count vs. Overdue PM report:** The Dashboard tile evaluates overdue active PM assignments (capped at 5), whereas `/reports/overdue-pm` lists overdue **date-triggered PM Maintenance Requests**, so the two figures can differ.

### 6.2 Utilisation

Measures deployment across the **active, enrolled** fleet (`is_active = true` and `maintenance_status = enrolled`, point-in-time):

- **Headline percentage:** Share of **eligible** assets (excluding assets in `failure`, `under_maintenance`, no location, or unclassified location) currently at a `rig` or `well_site` location (`locations.type`).
- **Segmented bar & legend:** Breaks all active enrolled assets by location bucket: **Deployed** (`rig` / `well_site` — note: includes assets at those locations even if in `failure`/`under_maintenance`), **Idle** (`yard` / `building`), **Maintenance** (`workshop` location type — not `operational_status = under_maintenance`), **No location**, and **Unclassified location**. Zero-count segments are hidden.
- **Committed for upcoming jobs:** Count of active, enrolled assets with an active booking covering **today** (`booked_from <= today <= booked_until`).

### 6.3 Reliability & Process Performance

Two cards show six KPIs over a rolling **90-day window** (labelled **Last 90 days**). Durations display in hours under 24 hours (`Xh`) and switch to days + hours (`Xd Yh`) at 24 hours or more. Empty metrics show explanatory states (e.g. `"No failures yet"`, `"None closed"`, `"None recorded"`) rather than zero.

#### Equipment reliability

| KPI | Formula / Basis | Display |
| --- | --- | --- |
| **MTBF** | `window_days ÷ classified_failures` for corrective MRs with `is_failure = true` created in the last 90 days. | days |
| **MTTR** | Average clock time from **`assigned_at` to `closed_at`** for **closed** corrective Work Orders closed in the last 90 days (excluding WOs without `assigned_at`). | `Xh` / `Xd Yh` |
| **Failures** | Count of corrective MRs with `is_failure = true` created in the last 90 days (`None recorded` when 0). | count |

#### Process performance

| KPI | Formula / Basis | Display |
| --- | --- | --- |
| **PM compliance** | Percentage of **date-triggered** Preventive Maintenance Requests (`triggered_by_date = true`) due in the last 90 days whose WO closed on or before `trigger_date`. Reading-triggered PMs are excluded. | `%` |
| **Avg request** | Average elapsed time from MR creation to resolution (`converted`, `rejected`, or `cancelled`) in the last 90 days. | `Xh` / `Xd Yh` |
| **Avg work order** | Average elapsed time from WO creation to closure for WOs closed in the last 90 days. | `Xh` / `Xd Yh` |

### 6.4 Asset Status & By Location

- **Asset status** (point-in-time): Counts all **active** assets (`is_active = true`, including active withdrawn assets — so its total can differ from Utilisation and Programme Readiness) across **Ready for Field**, **Under Maintenance**, **Failure**, and **At the Field**, plus **Booked** shown below a separator as an independent axis.
- **By location** (point-in-time): Shows the **top 6** locations by asset count with proportional bars, alongside the total number of location groups.

### 6.5 Programme Readiness

Three current-state progress metrics over **active, enrolled** assets:

| Metric | Meaning |
| --- | --- |
| **PM coverage** | Active enrolled assets with at least one active PM assignment. |
| **Location recorded** | Active enrolled assets with a `current_location_id`. |
| **Baseline reading** | Active enrolled assets with at least one non-deleted meter reading recorded (includes unconfirmed readings). |

### 6.6 Active Workboard & Recent Moves

- **Active workboard:** Merges up to 5 pending MRs and up to 5 `open`/`in_progress` WOs, displaying the **5 newest items** overall with links to their detail pages. Hidden completely for **Logistics**.
- **Recent asset moves:** Up to **5** most recent physical relocations within the **last 90 days** (asset, destination location, effective date).

### 6.7 Quick Actions

- **New MR** (all human roles) — opens the New Maintenance Request sheet directly (`/maintenance?action=new`, on `all-requests` for Admin/Manager or `my-requests` for other roles).
- **Update Location** (Admin, Manager, Logistics) — navigates to `/locations?tab=asset-location-update`.

### 6.8 Role Filtering

- **Administrator / Maintenance Manager:** Workboard shows all pending MRs and all `open`/`in_progress` WOs org-wide; all three Needs Attention tiles visible.
- **Technician:** Workboard shows their own assigned `open`/`in_progress` WOs; Needs Attention shows `asset(s) down` only.
- **Requester:** Workboard shows their own pending MRs plus org-wide `open`/`in_progress` WOs; Needs Attention shows all three tiles.
- **Logistics:** Active Workboard is hidden; Needs Attention shows `asset(s) down` only.
- **Shared cards:** Reliability/Process KPIs and Recent Moves cover the trailing 90 days; Utilisation, Asset Status, By Location, and Programme Readiness reflect current point-in-time state identically for all roles.

---

## 7. Maintenance Requests

Maintenance Requests are the sole entry point into maintenance work. Every Work Order originates from an approved MR.

**Sidebar:** Maintenance Requests — visible to everyone.

**Routes:** `/maintenance` (list) and `/maintenance/requests/:requestId` (detail).

### 7.0 The Maintenance Request Data Model

Stored in `maintenance_requests`:

**Core Fields:**

| Field | Type | Purpose |
| --- | --- | --- |
| `number` | string | Auto-generated unique sequence: `MR-XXXXXX`. |
| `is_preventive` | boolean | Stored discriminator: `false` = Corrective (**Repair**), `true` = Preventive (**Service**). |
| `type` | string (derived) | Derived in API responses as `corrective` or `preventive` (displayed in UI as **Repair** or **Service**). |
| `asset_id` | FK → `assets` | Required. Asset must be active and enrolled (`is_active = true`, `maintenance_status = enrolled`). |
| `description` | text (nullable) | Optional description of fault, symptoms, or context. |
| `priority` | string | Configurable priority value (`low`, `medium`, `high`, `critical`). |
| `status` | `MaintenanceRequestStatus` | `pending_review`, `converted`, `rejected`, or `cancelled`. |
| `created_by` | FK → `users` | Submitter (or `system@atms.internal` for PM MRs). |

**PM-Specific Fields (populated for preventive MRs):**

| Field | Type | Purpose |
| --- | --- | --- |
| `pm_rule_id` | FK → `pm_rules` | Template that generated this MR. |
| `triggered_by_date` / `triggered_by_reading` | boolean | Which trigger dimension(s) crossed their threshold. |
| `trigger_date` | date (nullable) | Trigger date snapshot. |
| `trigger_reading_value` / `trigger_reading_type_id` | decimal / FK (nullable) | Trigger reading value and reading type snapshot. |

**Decision Fields:**

| Field | Type | Purpose |
| --- | --- | --- |
| `reviewed_by` / `reviewed_at` | FK / timestamp | Manager or Admin who approved or rejected the MR. |
| `rejection_reason` | text (nullable) | Required when `status = rejected`. |
| `is_failure` | boolean (nullable) | **Corrective only.** Manager classification of real failure (`true`/`false`) set at approval and optionally revised at WO closure. Drives MTBF and Failures KPIs. |
| `cancelled_by` / `cancelled_at` / `cancellation_reason` | FK / timestamp / text | Recorded when cancelled. `cancellation_reason` is **required for all cancellations** (CM and PM). |

### 7.1 Corrective Maintenance (Repair) Workflow

**Who can create:** **Administrator**, **Maintenance Manager**, **Technician**, and **Requester** (`Logistics` is excluded by backend policy).

**Step-by-step:**

1. Click the **New Request** button in the top-right header of `/maintenance` (or **New MR** on the Dashboard) to open the **New Maintenance Request (Repair)** side sheet.
2. Fill in the form:
   - **Asset (required):** Search and pick an active, **enrolled** asset (`AssetCombobox`).
   - **Priority (required):** Choose from active Maintenance Priorities (e.g. Low, Medium, High, Critical).
   - **Description (optional):** Symptoms, fault details, or context.
   - **Attachments (optional):** Attach fault photos or supporting documents (PDF, images including GIF, Word, Excel — max 20 MB each).
3. Submit and confirm in the confirmation dialog. The MR is created in `pending_review`, and active Maintenance Managers receive an email notification.
4. **Editing while `pending_review`:** The creator (Admin, Manager, Technician, or Requester) or any Admin/Manager can open the MR Detail screen and edit **Priority** and **Description** (Asset is read-only in the UI, though `asset_id` is accepted by the backend API).
5. **Manager review (Admin or Maintenance Manager):** Open the MR Detail screen (`/maintenance/requests/:requestId`) and choose:
   - **Approve & Create Work Order:** Atomically converts the MR to `converted` and creates an `open` Work Order in one transaction. For corrective MRs, the Manager must classify **Failure (`is_failure`: Yes / No)**. For **both corrective and preventive** MRs, the Approve dialog also offers an **optional Technician/Manager assignment** (atomic with approval) and an **optional location move** to any active location (exempt from field-return condition rules). For corrective MRs, the asset's `operational_status` automatically changes to **Failure** (unless already `under_maintenance`). The MR creator (and optional assignee) receive email notifications.
   - **Reject:** Moves the MR to `rejected` (terminal) with a required reason and emails the creator.
   - **Cancel:** Moves the MR to `cancelled` (terminal) with a required reason.

**Who can cancel a pending Corrective MR:** **Administrator**, **Maintenance Manager**, or a **Requester** cancelling their own pending corrective MR. Technicians cannot cancel MRs (backend policy restricts cancellation to Admin, Manager, and Requester).

### 7.2 Preventive Maintenance (Service) Workflow

Preventive MRs are generated automatically by PM rules (Section 12) with `is_preventive = true`, `pm_rule_id` set, and `priority = medium`. They enter `pending_review` and follow the same Manager approval workflow (without failure classification).

- **PM suppression on reject/cancel:** Rejecting or cancelling a pending preventive MR creates an occurrence suppression record (`pm_occurrence_suppressions`) so the scheduler does not immediately regenerate the same request. At the backend API level, `suppressed_until_date` and/or `suppressed_until_reading` are required according to which trigger dimension fired. *(Note: the current Reject and Cancel UI dialogs only capture a `reason` text field without suppression boundary inputs.)*
- **Cancelling a PM Work Order instead:** If a preventive MR was already approved and the resulting WO is later cancelled, **no suppression is created** — the PM assignment continues to be evaluated normally.
- Only **Administrator** and **Maintenance Manager** can reject or cancel preventive MRs.

### 7.3 Maintenance Request Statuses & Priorities

| Display Label | DB Value | Meaning | Terminal? |
| --- | --- | --- | --- |
| **Pending Review** | `pending_review` | Awaiting Manager review. Editable by creator (Admin/Manager/Technician/Requester) or any Admin/Manager. | No |
| **Converted to Work Order** | `converted` | Approved and atomically converted into one Work Order. | Yes |
| **Rejected** | `rejected` | Declined by Admin/Manager with required reason. | Yes |
| **Cancelled** | `cancelled` | Withdrawn before approval with required reason. | Yes |

**Priorities** (backed by **Admin → Lists & Dropdowns → Maintenance Priorities**):

| Value | Default Display Label | Typical Use |
| --- | --- | --- |
| `low` | **Low** | Minor or non-urgent issues. |
| `medium` | **Medium** | Standard maintenance (default for auto-generated PM requests). |
| `high` | **High** | Significant performance issue or urgent repair. |
| `critical` | **Critical** *(picker shows "Critical — immediate attention required")* | Immediate safety hazard or mission-critical stoppage. |

Priority is copied to the Work Order on approval and is informational/sortable (no automated SLA escalation).

### 7.4 Maintenance Request Tabs & Filters

The `/maintenance` page has **three tabs** plus the **New Request** button in the page header:

| Tab | Visible To | Content |
| --- | --- | --- |
| **My Requests** (`?tab=my-requests`) | Everyone | MRs created by the signed-in user. |
| **Pending Approval** (`?tab=pending-approval`) | Admin, Manager | All MRs in `pending_review`. Click any row to open the detail screen for Approve/Reject/Cancel/Edit actions (there are no inline row buttons). |
| **All Requests** (`?tab=all-requests`) | Admin, Manager | Every MR regardless of status. |

**Table columns:** `Request` (MR number), `Asset` (stacked identity with tag, serial, size, and category), `Priority` (filterable & sortable), `Status` (filterable & sortable), `Type` (**Repair** or **Service** — filterable only, not sortable), `Created` (sortable), and `Description`.

**Toolbar filters:** `IdentityFilters` lets you filter by **Serial Number**, **Size**, and **Maintenance Category**.

### 7.5 Maintenance Request Detail (`/maintenance/requests/:requestId`)

Clicking any row opens the full-page Maintenance Request Detail view:

- **Command bar:** MR number, **Repair / Service** type badge, status badge, priority badge, failure classification badge (`Not yet classified` / `Failure` / `No failure` on corrective MRs), and role/status-gated actions (**Approve**, **Reject**, **Cancel**, **Edit**).
- **Details card:** Asset identity (name, tag, serial, size, maintenance category), Priority, Description, Created by, Approved by, PM trigger details (for Service MRs), rejection or cancellation reason callouts, and a clickable link to the **Resulting work order** once converted.
- **Attachments card:** Fault photos and supporting files. Even after an MR reaches a terminal status (`converted`, `rejected`, `cancelled`), its details lock **but uploading attachments remains available** to Admin, Manager, and the MR creator. Admin and Manager can delete attachments at any stage; the creator can delete their own uploaded attachment only while `pending_review`.

### 7.6 MR Email Notifications

ATMS automatically sends Microsoft Graph emails for MR lifecycle events:

- **MR Submitted:** Sent to all active **Maintenance Managers**.
- **MR Approved:** Sent to the **MR creator**, plus the **assigned user** if someone was assigned during approval.
- **MR Rejected:** Sent to the **MR creator** (including the rejection reason).

---

## 8. Work Orders

Work Orders represent the execution phase of maintenance. Every Work Order is created atomically when a Maintenance Request is approved.

**Sidebar:** Work Orders — visible to Administrator, Maintenance Manager, and Technician.

**Routes:** `/work-orders` (list), `/work-orders/:workOrderId` (detail), and `/work-orders/:workOrderId/part-request` (printable Part Request).

### 8.0 The Work Order Data Model

Stored in `work_orders` (plus related tables `work_order_parts`, `work_order_forms`, `work_order_form_fields`, `work_order_pm_marks`, and `work_order_meter_snapshots`):

| Field | Type | Purpose |
| --- | --- | --- |
| `number` | string | Auto-generated unique sequence: `WO-XXXXXX`. |
| `maintenance_request_id` | FK → `maintenance_requests` | Immutable 1:1 link to the originating MR. |
| `asset_id` | FK → `assets` | Asset being serviced (copied from MR). |
| `status` | `WorkOrderStatus` | `open`, `in_progress`, `completed`, `closed`, or `cancelled`. |
| `priority` | string | Copied from MR (`low`, `medium`, `high`, `critical`). |
| `description` | text (nullable) | Work notes / job description, editable via `PATCH /work-orders/{wo}`. |
| `completion_notes` | text (nullable) | Optional summary notes captured when marking the WO complete. |
| `assigned_to_user_id` / `assigned_by_user_id` / `assigned_at` | FK / FK / timestamp | Current assignee (active Technician or Manager), assigner, and timestamp. |
| `started_at` | timestamp (nullable) | When work moved `open → in_progress`. |
| `completed_by_user_id` / `completed_at` | FK / timestamp | Who marked the WO `completed` and when. |
| `closed_by_user_id` / `closed_at` | FK / timestamp | Who finalized (`closed`) the WO and when. |
| `cancelled_by_user_id` / `cancelled_at` / `cancellation_reason` | FK / timestamp / text | Cancellation audit fields (`cancellation_reason` required). |

### 8.1 Work Order Lifecycle

```
┌──────┐   assign (stays open)   ┌──────┐   start    ┌─────────────┐   complete   ┌───────────┐   close    ┌────────┐
│ open │ ──────────────────────► │ open │ ─────────► │ in_progress │ ───────────► │ completed │ ─────────► │ closed │
└──┬───┘                         └──┬───┘            └──────┬──────┘              └─────┬─────┘            └────────┘
   │ cancel                         │ cancel                │ cancel                    │ cancel          (PERMANENT)
   ▼                                ▼                       ▼                           ▼
┌─────────────────────────────────────────────────────────────────────────────────────────────────┐
│                                     cancelled (terminal)                                        │
└─────────────────────────────────────────────────────────────────────────────────────────────────┘
```

**Transition rules:**

| Transition | Who Can Do It | Conditions & Effects |
| --- | --- | --- |
| Assign / Reassign | Admin, Manager | Assignee must be an active Technician or Maintenance Manager; asset must be active **and enrolled**. Does not change WO status. Allowed on `open` and `in_progress` in the UI (and `completed` via API). |
| `open → in_progress` (**Start** / **Start…**) | Assigned user, Admin, Manager | WO must be assigned first; asset must be active and enrolled. Forces asset `operational_status` to **Under Maintenance**. If the asset is not already at a `workshop` or `yard`, the Start dialog requires choosing a workshop/yard work location and records a real location move (`Started work order WO-XXXXXX`). Closing later does **not** move the asset back. |
| `in_progress → completed` (**Complete…**) | Assigned user, Admin, Manager | All required **Completion checklist** (WO Form) fields must be filled. Optional `completion_notes`. Asset stays `under_maintenance`. |
| `completed → closed` (**Close…**) | Admin, Manager | Runs all closure side effects atomically (Section 8.5). Permanently immutable. |
| `open / in_progress / completed → cancelled` (**Cancel…**) | Admin, Manager | `cancellation_reason` required; user chooses resulting asset `operational_status` (`failure` or `ready_for_field`) on **all** cancellations. Asset Condition does **not** reset on cancel, and recorded part lines are **not** removed or restored. |

### 8.2 Work Order Execution & What Locks at Completion

While a Work Order is **`open`** (after assignment) or **`in_progress`**, the assigned Technician, Manager, or Administrator can:

- Edit **Work notes** (`description`).
- Fill and save the **Completion checklist** (WO Form, Section 8.7).
- Add or remove **Part Request** lines (Section 8.3).
- Record, edit, or delete unconfirmed **meter readings** using delta entry (Section 9.4).
- Mark or clear **Preventive maintenance performed** (Section 8.5).
- Update **Asset operational status** (`Update status…` → Ready for Field, Under Maintenance, or Failure).
- Upload **Attachments** (photos, executed job sheets, PDFs, Office docs — max 20 MB each).

**Once the WO is marked `completed` (awaiting Manager closure):**

- **Locked in the UI:** Work notes, Completion checklist, Part Request add/remove (also blocked by backend with `409`), Assign/Reassign button, and the inline PM performed picker are locked. *(On the backend API, Admin/Manager retain `PATCH /work-orders/{wo}`, `PUT/DELETE /pm-mark`, form edits, and reassignment on `completed` WOs.)*
- **Still open on `completed`:**
  - **Uploading attachments** stays open to both the assigned Technician and Admin/Manager so paperwork can be attached after finishing the physical job.
  - **Updating asset operational status** (`Update status…`) stays open to the assigned Technician and Admin/Manager.
  - **Editing or deleting unconfirmed meter readings** stays open to Admin, Manager, and Technician.
  - **Overriding or suppressing a staged PM mark** is available to Admin/Manager inside the **Close** dialog.
- **Once `closed` or `cancelled`:** Everything — including attachments — is permanently locked.

### 8.3 Work Order Part Recording

Parts are recorded inside the **Part Request** card on Work Order Detail while the WO is **`open`** or **`in_progress`** (blocked on `completed`, `closed`, and `cancelled`):

1. Click **Add Part** in the **Part Request** card.
2. Search the part picker (`PartCombobox`). The picker automatically filters to **active parts compatible with the WO's asset** (matching the asset's Maintenance Category and/or Size, where blank fields act as wildcards), sorted most-specific and in-stock first. You can search by **Part Number** (`erp_part_code`), name, supplier part number, **size**, or **maintenance category**. Out-of-stock parts are disabled.
3. Enter the quantity used and optional per-line **Notes**. *(Backend accepts up to two decimal places from `0.01`; the current UI number input starts at `1`.)*
4. Submitting immediately **decrements** `available_quantity` on the part (lines that would push stock below zero or reference inactive/incompatible parts are rejected).
5. Removing a line while the WO is `open` or `in_progress` **restores** the consumed quantity immediately. Cancelling a WO does **not** automatically remove recorded part lines or restore stock.

### 8.4 Printable Part Request (`/work-orders/:workOrderId/part-request`)

Whenever a Work Order has at least one part line, the **Part Request** card displays a **Print…** button. Clicking it opens a standalone, print-ready A4 Part Request form outside the main app shell (used as the interim paper warehouse issue form):

- Header with LDC branding, WO number, MR number, print date, asset identity (name, tag, serial, size, category), fixed Department (`Maintenance`), and Reason (`Maintenance`).
- Five-column table (`#`, `PART DESCRIPTION` with per-line notes, `SIZE`, `QTY`, `PART NUMBER` showing `erp_part_code`), plus one blank handwritten line.
- Five signature boxes: `REQUESTOR` (pre-filled with current user name and date), `REVIEWER`, `APPROVER`, `ISSUED BY — WAREHOUSE`, and `RECEIVED BY`.

### 8.5 Recording Preventive Maintenance During a Job & Work Order Closure

#### Recording Preventive Maintenance During a Job

If the team performs a scheduled service (e.g., L1, L2, L3) during a Work Order — such as servicing an asset while it is already stripped down for a repair — the assigned Technician, Manager, or Administrator can record it in the **Preventive maintenance performed** picker while the WO is `open` or `in_progress`:

- Pick the **single highest PM level performed** (the numbered ladder `L1`, `L2`, `L3`, `L4`, … is cumulative, so picking `L3` covers `L2` and `L1`).
- You can change the selection or click **Clear** before completion. Setting the same level again is a no-op.
- **Nothing resets until the Work Order closes.** If the WO is cancelled, the staged mark is discarded with no effect on schedules.
- When an Admin or Manager opens the **Close** dialog, **"A service was also performed"** is pre-ticked with the staged level. The closer can confirm it, change it to a different level (closer's choice wins and is audited), or **untick the checkbox to suppress the staged mark** (no baseline reset occurs; audited as `work_order_pm_mark.suppressed`).

#### What Happens Atomically When a Work Order Closes

Only **Administrator** and **Maintenance Manager** can close a `completed` Work Order. All of the following execute inside one database transaction:

1. **Attachments are optional:** A WO can close with or without attachments. If no files are attached, the Close dialog displays a friendly reminder encouraging paperwork upload when available, without blocking closure. Once closed, attachments are permanently locked.
2. **WO finalized:** Status becomes `closed` (permanently immutable), `closed_at` and `closed_by_user_id` are stamped.
3. **Meter readings confirmed (oldest first):** Every unverified reading recorded on this Work Order is confirmed with the closer as confirmer. Any reading failing monotonic value/date constraints is **skipped and audited** — closure still succeeds.
4. **Meter position snapshotted:** Current confirmed meter values per reading type are saved immutably in `work_order_meter_snapshots` so ATMS preserves the exact odometer position at closure.
5. **Asset Operational Status & Condition updated:**
   - `operational_status` is **always** set to **Ready for Field** (for active assets; deactivated assets are skipped). There is no status picker in the Close dialog — if the asset is still faulty, **cancel** the WO instead of closing it.
   - `condition_status` resets to the system default (**Normal**). If the asset's prior Condition was **Need Inspection** and **no PM level was recorded** on this closure, a non-blocking warning note is returned.
6. **Preventive Maintenance baseline reset & cascade:** Applies if the WO originated from a Preventive MR, or if a PM level was staged/declared on any WO whose asset has active PM assignments:
   - The serviced assignment's `last_triggered_date` resets to today and `last_triggered_reading` resets to the latest confirmed reading (including readings just confirmed in step 3).
   - If the serviced rule has a numbered level (`L<number>`, e.g., `L2`, `L3`, `L4`, `L5`), all active lower-numbered `L<n>` assignments on the same asset also reset their baselines. Custom non-`L<n>` levels reset only themselves.
   - If a service was declared on a repair (or staged), any open `pending_review` PM MR for that **declared assignment** is retired with `decision_type = performed_under_repair` (citing the WO). *(Note: lower cascaded assignments have their baselines reset without cancelling any separate pending PM MRs.)*
   - If a staged PM schedule was deactivated before closure, it is skipped and reported in the close warnings.
7. **Failure classification review (corrective WOs only):** The Close dialog pre-populates the MR's `is_failure` decision so the closer can confirm or revise it (e.g., flipping a no-fault-found job to `No`), updating MTBF and Failures KPIs.
8. **Audit & non-blocking warnings:** Closure is audited and any non-blocking notes (Need Inspection without PM, deactivated staged schedule, or missing default condition) are displayed in a **"Closed, with notes:"** banner on WO Detail.

### 8.6 Work Order Cancellation

Available to **Administrator** and **Maintenance Manager** from `open`, `in_progress`, or `completed` (never `closed`):

- Requires a **cancellation reason** and an **asset status** selection (**Failure** if the fault remains, or **Ready for Field** if it was a false alarm).
- Moves the WO to `cancelled` (terminal, read-only, attachments locked).
- Does **not** reset asset Condition, does **not** remove recorded parts or restore stock, does **not** confirm meter readings, and does **not** create a PM suppression record.

### 8.7 Work Order Execution Form (Completion Checklist)

When a Work Order is created, ATMS checks for an active **WO Form template** covering the asset's **Maintenance Category** and snapshots it onto the WO. On WO Detail, this appears as the **Completion checklist** card (showing progress bar, `X / Y required`, and up to 3 missing required field names):

- **Opening & filling:** Clicking the card opens a right-hand slide-over sheet (`WoChecklistSheet`) available while the WO is `open` or `in_progress` to Admin, Manager, and the assigned Technician.
- **Fields & columns:** Supports **Yes/No** (3-state toggle: unanswered / Yes / No), **Numeric** (with optional unit), and **Text** fields, plus an optional **Notes** column on every row. For `Pre + post values` fields, both **Pre** and **Post / Value** columns appear side-by-side immediately from `open` through `in_progress` (there is no phased reveal).
- **Saving:** Edits are held in a local draft with an explicit **Save** button (atomic bulk update) and an unsaved-changes **"Discard changes?"** prompt.
- **Completion gate:** Marking a WO `completed` requires all required fields to be filled (both Pre and Post for `Pre + post` fields). If any are missing, the server returns `422` and the UI automatically opens the checklist sheet highlighting the missing rows.
- **Sync to latest:** If an Admin edits the underlying template after the WO was created, a banner lets you **Sync to latest** (merges fields while preserving matching values) or **Dismiss** (hides the banner for the current browser session; reappears on reload).

### 8.8 Work Order Tabs, Columns & Detail View

**Tabs on `/work-orders`:**

| Tab | Visible To | Content |
| --- | --- | --- |
| **My Work Orders** (`?tab=my-work-orders`) | Technician | All WOs assigned to the Technician (including cancelled). |
| **All** (`?tab=all`) | Admin, Manager | Every WO regardless of status (including cancelled). |
| **Open** (`?tab=open`) | Admin, Manager, Technician (own) | WOs in `open` status. |
| **In Progress** (`?tab=in-progress`) | Admin, Manager, Technician (own) | WOs in `in_progress` status. |
| **Completed** (`?tab=completed`) | Admin, Manager, Technician (own) | WOs in `completed` status awaiting closure. |
| **Closed** (`?tab=closed`) | Admin, Manager, Technician (own) | WOs in `closed` status (read-only). |

*(There is no combined `Active` tab, and no dedicated `Cancelled` tab — cancelled WOs appear in **All** or **My Work Orders**.)*

**Table columns & filters:** `Work Order` (number), `Asset` (stacked identity), `Priority` (filter & sort), `Status` (filter & sort), `Assigned To`, `Started` (sort), `Created` (sort), plus the `IdentityFilters` toolbar (Serial Number, Size, Maintenance Category).

**Work Order Detail (`/work-orders/:workOrderId`) cards & actions:**

- **Sticky command bar:** WO number, status badge, lifecycle stepper (`Open → In progress → Completed → Closed` or `Cancelled`), failure classification badge (for repairs), and role/status-gated buttons (**Assign**, **Start** / **Start…**, **Complete…**, **Close…**, **Cancel…**). Note: **Start** appears only once a user is assigned.
- **Main column:** **Work notes** (including Completion notes once completed), **Completion checklist** (if a template applies), **Part Request** (Add/Remove + **Print…**), **Updated readings** (`Meter at close` snapshot once closed, `Recorded on this work order`, and collapsible `Asset reading history`), and **Preventive maintenance performed** picker.
- **Right rail:** **Related Maintenance Request** link, **Assignment** (`Assigned to`, `Assigned by`), **Asset status** (operational status + current location + `Update status…`), and **Attachments**.

### 8.9 WO Email Notifications

- **WO Assigned:** Sent to the **assigned user**.
- **WO Started:** Sent to all active **Maintenance Managers**.
- **WO Completed:** Sent to all active **Maintenance Managers**.
- **WO Closed:** Sent to the **assigned user**, with all active **Maintenance Managers** in **CC**.
- **WO Cancelled:** Sent to the **assigned user** (if assigned; includes cancellation reason).

---

## 9. Asset Management

The Asset Management section is the asset register and asset-level operations workspace.

**Sidebar:** Asset Management — visible to Administrator, Maintenance Manager, Technician, and Logistics.

**Routes:** `/assets` (tabs: `All Assets`, `Asset Assembly`) and `/assets/:assetId` (detail).

### 9.1 All Assets Tab

Displays the asset register with search and multi-dimensional filtering:

- **Visibility:** Administrators and Maintenance Managers see **both active and deactivated** assets by default. Technicians and Logistics see **active** assets (`is_active = true`) only.
- **Columns:** `Asset Tag`, `Name` (with stacked serial number, size, maintenance category, and **Booked** badge when booked), `Maintenance Category` (filterable), `Kind` (`Asset` / `Package` / `Component`, filterable), `Operational Status` (filterable), `Condition` (filterable & sortable), and `Location`.
- **Toolbar filters:** **Serial Number** search, **Size** dropdown, **Maintenance Category** dropdown, and (for Admin, Manager, and Logistics) **Location** dropdown.
- **Actions:** Click any row to open **Asset Detail** (`/assets/:assetId`) — there are no per-row action buttons. *(The **Add Asset** header button is currently disabled as a Phase 2 placeholder; new assets are imported via CLI workbooks or API.)*

### 9.2 Asset Detail (`/assets/:assetId`)

All asset sections are rendered as inline cards inside `/assets/:assetId` (main column + right rail) — there are no separate sub-routes:

- **Sticky command bar:** Asset name, Maintenance Status badge (**In ATMS** or **Withdrawn**), Operational Status badge, active/inactive badge, **inline Condition badge-selector** (Admin and Manager can change Condition directly without opening the Edit sheet), and **Edit Asset** button (Admin and Manager).
- **Asset Details (Overview card):** Asset Tag, ERP Asset Code (visible to Admin; non-admins see `—`), Serial Number, Model, Manufacturer, Size, Maintenance Category, Asset Kind, Maintenance Status, Operational Status, Condition, Current Location, and Description.
- **Edit Asset side sheet (Admin and Manager):** Edits Name, Description, Serial Number, Model, Manufacturer, Size (controlled dropdown), Maintenance Category, Asset Kind, Maintenance Status, Operational Status (disabled while `at_the_field`), Condition, Active/Inactive status, **Suggest Tag** (when `asset_tag` is blank), and **Current Location + Location Change Notes** (inline move with confirmation dialog `Save asset changes?`).
- **Maintenance History card:** Displays **closed Work Orders** for this asset (date, type, WO number, description, priority). Hidden/restricted for Logistics (`403` on backend).
- **Meter Readings card (read-only on Asset Detail):** Lists recorded readings (`Read At`, `Value`, `Source`, `Confirmed` / `Unverified` badge). New readings are recorded on **Work Order Detail**, not on Asset Detail (Section 9.4).
- **PM Rules (Service) card (Admin and Manager only):** Lists PM assignments on this asset (`Rule`, `Status` badge `OK`/`Soon`/`Due`, `Schedule`, `Since Service`, `Next Due`, `By category` badge for category-origin schedules), a **Show Inactive** / **Show Active Only** toggle, **Assign Rule** button, per-row **Evaluate** (`▶` play icon), and **Deactivate / Reactivate** actions.
- **Bookings card (right rail):** Lists active and historical bookings. Admin, Manager, and Logistics can **Book**, **Edit**, override conflicts (**Book Anyway**), and **Cancel** bookings.
- **ERP Reference card (right rail — hidden for Logistics):** Shows ERP Asset Code (Admin only) and Last ERP Sync timestamp. *(Raw ERP JSON is returned by the API to Administrators only and is not rendered in the UI.)*
- **Location History card (right rail):** Chronological timeline of physical relocations.
- **Attachments card (right rail):** Upload, preview in a new tab, and (for Admin/Manager) delete files attached to the asset (Section 9.6 & Section 15).

### 9.3 Asset Assembly Tab (Phase 2 Placeholder)

The **Asset Assembly** tab (`?tab=assembly`, badged **Phase 2**) currently renders a static **"Coming in Phase 2"** placeholder. Component install, remove, swap, assembly history timelines, derived component operating hours, and parent-WO child PM cross-checks are not yet built.

### 9.4 Meter Readings

Meter readings track cumulative asset usage (operating hours, kilometers, depth, or custom reading types configured under **Admin → Lists & Dropdowns → Usage Reading Types**).

#### The Meter Reading Data Model (`asset_meter_readings`)

| Field | Type | Purpose |
| --- | --- | --- |
| `asset_id` | FK → `assets` | Asset the reading belongs to. |
| `usage_reading_type_id` | FK → `usage_reading_types` | Meter type (e.g., Operating Hours, Kilometer Driven, Depth). |
| `reading_value` | decimal(12,2) | Absolute cumulative meter total. |
| `entered_delta` | decimal(12,2) (nullable) | Usage delta typed on the Work Order form (preserved alongside the calculated total). |
| `reading_at` | datetime | Timestamp of the reading. |
| `source` | string | Source (`user` / `manual`). |
| `entered_by_user_id` | FK → `users` | User who recorded the reading. |
| `confirmed_by_user_id` / `confirmed_at` | FK / datetime (nullable) | Closer and timestamp once confirmed (`NULL` = unverified). |
| `work_order_id` | FK → `work_orders` (nullable) | Work Order on which the reading was recorded. |
| `maintenance_request_id` | FK → `maintenance_requests` (nullable) | Optional MR link (API-only). |
| `notes` | text (nullable) | Optional notes. |
| `deleted_at` | timestamp (nullable) | Soft-delete timestamp. |

*(There is no stored "current meter value" column on the `assets` table — latest confirmed values are queried dynamically from `asset_meter_readings` and snapshotted onto each Work Order at closure in `work_order_meter_snapshots`.)*

#### Recording a Reading in the UI (Delta Entry on Work Orders)

In the web UI, meter readings are recorded exclusively on **Work Order Detail** (inside the **Updated readings** card) by an Administrator, Maintenance Manager, or assigned Technician — **Asset Detail is read-only**, and the Create MR sheet does not have a meter-reading input.

1. On an `open` or `in_progress` Work Order, click **Record reading** in the **Updated readings** card.
2. Choose the **Reading type**. The form displays the **last recorded reading** (date and confirmed/unconfirmed marker) as the base.
3. Enter **Used since last reading** (the delta, e.g. `50` hours). The UI blocks negative deltas and automatically computes and previews the read-only **Total** (`last reading + delta`).
4. Submit. Both the absolute `reading_value` and `entered_delta` are saved in **unverified** state (`confirmed_at = NULL`).

> **First reading for an asset:** When no earlier reading exists for that reading type, the number you enter becomes the meter's absolute total (e.g., enter `3200` if the meter already reads 3,200 hours).

- **Editing & deleting unverified readings:** Admin, Manager, and Technician can edit or soft-delete unverified readings directly from the Work Order page (even while the WO is `completed`, until it closes). Editing a delta-entered reading re-opens in delta mode (falling back to absolute total if it is the very first reading).
- **Reading confirmation at WO closure:** In the web UI there is no manual **Confirm** button — every unverified reading linked to a Work Order (`work_order_id`) is automatically confirmed **oldest first** when an Admin or Manager **closes** that Work Order. *(The backend still exposes `POST /api/assets/{asset}/meter-readings/{reading}/confirm` for Admin/Manager/Technician, though the UI does not expose it.)*
- **Monotonic enforcement:** During confirmation, ATMS enforces `new_reading_value >= latest_confirmed_value` and `new_reading_at >= latest_confirmed_reading_at` for the same asset and reading type. Any reading that violates these constraints at closure is **skipped and audited** — the WO still closes and the skipped reading stays unverified so it can be corrected or deleted.
- **Unconfirmed readings that never close:** Readings on a **cancelled** Work Order, or readings submitted via the API on a Corrective MR (`work_order_id = null`), are never confirmed by WO closure and remain unverified.
- **Once confirmed:** A reading is permanently immutable; corrections require recording a new valid (higher) reading with explanatory notes.

### 9.5 Asset Location History

The **Location History** card on Asset Detail shows a read-only timeline of past relocations. To change an asset's location, Administrators and Maintenance Managers can update Location inside the **Edit Asset** sheet, or any Administrator, Maintenance Manager, or Logistics user can move the asset from **Locations → Asset Location Update** (Section 11). There is no standalone `"Update Location"` button on Asset Detail.

### 9.6 Asset Attachments

Files can be attached on Asset Detail (max 20 MB per file; PDF, DOC, DOCX, XLS, XLSX, JPEG, PNG, **GIF**, WebP; server-side MIME sniffing + SHA-256 hash; multi-file upload supported; opens in a new tab via authenticated preview). Attachments do not carry a `category` field, and the UI upload dialog does not include a description input. See **Section 15** for the complete role permission matrix.

---

## 10. Parts Management

The **Parts Management** section provides a single **Parts Reference** catalogue for viewing spare parts, editing local operational details, managing part attachments, and reconciling stock quantities via Excel/CSV. ATMS tracks consumption-adjusted quantities for Work Orders — it does **not** perform inventory valuation, purchasing, or warehouse accounting.

**Sidebar:** Parts Management — Direct Link (no tabs) — visible to Administrator, Maintenance Manager, and Technician.

**Routes:** `/parts` (catalogue) and `/parts/:partId` (detail).

### 10.0 How Parts Enter and Are Updated Today (Excel / CSV vs. Planned ERP Sync)

Live ERP parts integration is **not active today** (deferred to Phase 3). Instead, parts are maintained through three live workflows:

1. **Full Catalogue Load & Updates (Excel Workbook → CLI Import):** New parts, ERP part codes, names, units, statuses, categories, and sizes are loaded and updated from LDC's cleaned spare parts workbook (`SPARE PARTS (ERP).xlsx` → `parts-migration-source.csv`) using `php artisan atms:import-parts` (Section 10.4).
2. **Bulk Stock Quantity Reconciliation (Web UI — Administrator Only):** Administrators download the parts list as CSV, VLOOKUP/update quantities in Excel against the ERP export, and upload the edited CSV back into ATMS (Section 10.4a).
3. **Single-Part Local Edits (Web UI — Admin & Manager) & Work Order Consumption:** Admin and Manager can edit local fields on individual parts, and recording/removing parts on open/in-progress Work Orders automatically decrements/restores `available_quantity` (Section 8.3).

### 10.1 Parts Data Ownership — Read-Only Reference vs. Editable Local Fields

**Reference / External Fields (read-only in the web UI):**

| Field | Visibility & Purpose |
| --- | --- |
| `erp_part_code` | Displayed as **Part Number** (visible to all roles). The code quoted on Work Orders and printed Part Requests. Read-only in ATMS; updated via workbook import (or future ERP sync). |
| `erp_part_id` | Internal ERP system identifier. **Included in the CSV export** for Excel VLOOKUP against ERP exports (not returned in normal Part Detail JSON). |
| `erp_status` | ERP item status (e.g. `active`, `inactive`). Visible to **Admin and Manager only** in the `ERP Reference` card. |
| `erp_last_synced_at` | Last sync/refresh timestamp. Visible to **Admin and Manager only** in the `ERP Reference` card. |
| `erp_raw_data` | Unmodified ERP JSON record. Visible to **Administrator only** in the `ERP Reference` card. |

**Local Operational Fields:**

| Field | Editable in UI (Admin/Manager)? | Purpose |
| --- | --- | --- |
| `name` | **Yes** | Human-readable part name. |
| `available_quantity` | **Yes** (single part, Admin/Manager; bulk CSV, Admin only) | Current stock on hand (`numeric(14,3)`). Decremented on WO part recording and restored on removal. |
| `maintenance_category_id` | **Yes** | Maintenance Category used for filtering and Work Order part compatibility matching. |
| `size_inches` | **Yes** | Nominal size in inches (O&G fractional display, e.g. `6 3/4"`), used for filtering and WO part compatibility matching. |
| `is_active` | **Yes** | Controls whether the part is active and selectable on Work Order part pickers. |
| `description` | API-only (`PATCH /api/parts/{part}`) | Additional part notes (displayed on Part Detail if present; no input in the current Edit Part sheet). |
| `unit_of_measure` | API-only (`PATCH /api/parts/{part}`) | Unit of measure (e.g. `EA`, `meter`, `liter`; displayed in lists/detail; no input in the current Edit Part sheet). |

### 10.2 Parts Reference List (`/parts`)

A single searchable list (no tabs):

- **Role scoping:** Administrators and Maintenance Managers see **both active and inactive** parts. Technicians see **active parts only** (and receive `403` if opening an inactive part's detail URL).
- **Columns:**
  - **Part Number** (`erp_part_code`) — sortable and searchable.
  - **Name** — renders part name along with Part Number, Size, Maintenance Category, and an **Out of stock** badge when quantity is `<= 0`. *(Supplier `part_number` was retired from the UI display, though still searchable via API.)*
  - **Unit** — unit of measure.
  - **Qty** — available quantity (shows **Out of stock** badge at `<= 0`).
  - **Status** — Active / Inactive.
- **Toolbar filters:** **Part Number** (free text), **Size** (dropdown), and **Maintenance Category** (dropdown).
- **Page header actions (Administrator only):** **Download CSV** and **Upload quantities** (Section 10.4a).

### 10.3 Part Detail (`/parts/:partId`) & Printable Part Requests

- **Part Details card:** Displays Part Number (`erp_part_code`), Size, Maintenance Category, Unit of Measure, Available Quantity, Status, Created date, and Description (if present).
- **Edit Part sheet (Admin and Manager):** Edits **Name**, **Available Qty**, **Maintenance Category**, **Size**, and **Status** (`Part Number` is shown read-only for copying). Confirmed via dialog before saving.
- **ERP Reference card (Admin and Manager only; hidden for Technicians):** Shows `ERP Status`, `Last ERP Sync`, and (for **Administrator only**) `Raw ERP Data`.
- **Attachments card:** Upload datasheets, fitting instructions, or safety sheets (Admin, Manager, Technician, Logistics can upload; Admin and Manager can delete; max 20 MB per file).
- **Part Requests:** There is no separate Parts tab for part requests — printable **Part Request** forms are opened from Work Order Detail (`/work-orders/:workOrderId/part-request`, Section 8.4).

### 10.4 Catalogue Import (CLI) & Planned Live ERP Sync (Phase 3)

#### Current Process: Excel Workbook → CLI Import (`atms:import-parts`)

Full catalogue refreshes (adding new parts, renaming codes/names, updating categories, sizes, statuses, and quantities) are executed by an Administrator/operator on the server from the cleaned CSV exported from LDC's spare parts Excel workbook:

```
php artisan atms:import-parts --dry-run
php artisan atms:import-parts /path/to/parts.csv
```

- Matches existing parts on `erp_system_id` (updating `erp_part_code` if renamed in the ERP) or falls back to `erp_part_code` for parts without an ERP ID; inserts unknown items as new parts; **never deletes parts**.
- Syncs `MaintenanceCategory` rows automatically, validates all rows before opening a database transaction (all-or-nothing), and reports up to 40 line-numbered errors if anything fails.

#### Planned Phase 3: Live ERP Sync (Not Active Today)

Backend scaffolding for an automated ERP parts feed exists (`App\Jobs\SyncErpPartsJob` scheduled Mondays at 03:00 `Africa/Tripoli` via `App\Actions\Erp\SyncParts` and `LdcErpHttpSource`), **but it is inactive today**:

- When `LDC_ERP_PARTS_API` is not configured, `LdcErpHttpSource` logs a warning and returns empty results as a safe no-op.
- There is currently **no web UI** for triggering manual ERP syncs or viewing ERP sync history (`/settings/system` is a `"Coming soon"` placeholder).
- **Note for when Phase 3 is enabled:** `SyncParts` performs incremental fetches using `updated_since` and **overwrites** `erp_part_code`, `name`, `description`, `unit_of_measure`, `available_quantity`, `erp_status`, `is_active`, `erp_raw_data`, and `erp_last_synced_at` from the ERP payload — only `maintenance_category_id`, `size_inches`, and `part_number` survive a live ERP sync.

### 10.4a Manual Bulk Stock Corrections — CSV Download & Quantity Upload

Administrators can reconcile stock quantities in bulk against an ERP Excel export directly from `/parts`:

1. Click **Download CSV** (`GET /api/parts/export-csv`). The file includes **all parts** (active and inactive) with columns in exact order: `part_id`, `erp_part_id`, `erp_part_code`, `name`, `unit_of_measure`, `erp_status`, `is_active`, `available_quantity` (written at stored `numeric(14,3)` precision).
2. Open the CSV in Excel, match ERP quantities onto it (e.g., via `VLOOKUP` on `erp_part_id`), and update the `available_quantity` column. Leave `part_id` and `erp_part_code` intact. Extra helper columns and blank rows are ignored; UTF-8 BOMs from Excel are tolerated.
3. Click **Upload quantities**, choose the edited `.csv` file, review the overwrite notice, and click **Apply quantities** (`POST /api/parts/import-quantities`).

**Matching, validation, and safeguards:**

- **Keyed on `part_id`, cross-checked on `erp_part_code`:** Rows match existing parts strictly on `part_id` (never inserting new parts). If `erp_part_code` is filled in the CSV, ATMS checks it case-insensitively against the database (automatically unguarding any leading apostrophe `'` added by CSV formula-injection protection) to catch shifted VLOOKUP rows. A blank `erp_part_code` cell skips the cross-check, while a non-blank mismatch rejects the file.
- **All-or-nothing:** Every row is validated before any write. One invalid row rejects the entire file (`422`), reporting up to the **first 40** line-numbered errors plus the total error count. Nothing is modified on rejection.
- **Quantity format & limits:** Quantities must be `0` or greater with up to 3 decimal places (`/^\d{1,11}(\.\d{1,3})?$/`; negatives are rejected). Maximum file size is **5 MB** and maximum **25,000 rows**.
- **Idempotent & concurrency-safe:** Re-uploading an unmodified export reports all rows `unchanged`. Rows are locked in ascending `part_id` order (`lockForUpdate`) to serialize safely with Work Order part consumption. Inactive parts accept quantity corrections.
- **Audit trail:** Logs one summary event (`parts.quantity_upload.completed`) with filename, SHA-256 file hash, total rows, updated count, unchanged count, and uploader.

---

## 11. Locations

The **Locations** section manages physical asset moves and (for Administrators) the master list of locations.

**Sidebar:** Locations — visible to Administrator, Maintenance Manager, and Logistics.

**Route:** `/locations` (tabs: `Asset Location Update`, `Manage Locations`; legacy `/settings/locations` redirects to `/locations?tab=manage-locations`).

### 11.1 Asset Location Update Tab (`?tab=asset-location-update`)

**Visible to:** Administrator, Maintenance Manager, Logistics.

This tab is a search-first **"Find & Move"** workspace (not an all-assets table):

1. **Find an asset:** Either pick an active location from the **Location** dropdown to list assets currently at that site, or search directly by asset name/code in the **Find asset** combobox (`AssetMoveLocator`).
2. **Select an asset:** Matching assets render as cards showing `asset_tag`, `name`, `serial_number` (if present), `asset_kind` badge, an optional warning when the asset has child components (*"Has N component(s) — move them separately for now"*), and a **Move to new location** button.
3. **Move the asset (`UpdateLocationSheet`):** Selecting an asset opens a side sheet displaying:
   - Asset metadata strip (tag, name, serial number, class, kind) and **Current Location** (read-only).
   - **New Location (required):** Active locations grouped by location type, excluding the asset's current location.
   - **Reason (optional)** and **Notes (optional)**. *(There is no `Effective Date` input — moves are always stamped with the current timestamp `now()`.)*
   - **Recent Location History:** Compact inline timeline showing up to 5 recent moves for the selected asset.
4. Submit and confirm. Active **bookings survive** location moves.
5. **Recently moved feed:** Below the workspace, a feed lists up to **5** recent relocations from the past 90 days.

**Location types & deployment mapping (`LocationType`):**

| Location Type | Deployment Bucket | Operational Status Effect on Manual Move |
| --- | --- | --- |
| `rig` | Deployed | Sets `operational_status` → **At the Field** |
| `well_site` | Deployed | Sets `operational_status` → **At the Field** |
| `yard` | Idle | Returning from field sets **Ready for Field** + Condition **Need Inspection** |
| `building` | Idle | Returning from field sets **Ready for Field** + Condition **Need Inspection** |
| `workshop` | Maintenance | Available to **Ready for Field** (or `null` status) assets; blocked for `at_the_field` assets |

**Status gating on manual moves:**

- **Ready for Field** (or `null` status): Can move to any active location.
- **At the Field**: Can only move back to a `yard` or `building` (flips status to **Ready for Field** and sets Condition to **Need Inspection**; if `need_inspection` is missing/inactive, the move still succeeds and audits `asset.condition_flag_skipped`).
- **Failure**, **Under Maintenance**, **Deactivated**, or **Withdrawn**: Refused (`409`).

### 11.2 Manage Locations Tab (`?tab=manage-locations`)

**Visible to:** Administrator only.

- **Table columns:** `Name`, `Type`, `Code`, `Status` (Active / Inactive), plus **Edit** and **Activate / Deactivate** icon actions.
- **Create / Edit Location side sheet:** **Name** (required), **Type** (required — `rig`, `well_site`, `yard`, `workshop`, `building`), **Code** (required, 2–3 characters, unique), **Parent Location** (optional), and **Description** (optional). Name, Type, and Code are required on both Create and Edit.
- **Activate / Deactivate:** Deactivated locations stay in history and reports but are excluded from active location pickers. *(Locations are managed exclusively here — they do not appear in Admin → Lists & Dropdowns.)*

---

## 12. Preventive Maintenance Rules

Preventive Maintenance (PM) schedules recurring servicing automatically, generating Preventive Maintenance Requests (**Service** MRs) when configured intervals come due.

### 12.1 How Preventive Maintenance Works

```
1. CREATE TEMPLATE     Admin creates a reusable template (single or Multi-Level Setup L1/L2/L3)
         │
2. ASSIGN TO ASSETS    By Maintenance Category on the rule (Admin), or per asset on Asset Detail (Admin/Manager)
         │             Each assignment receives its own baseline
         │
3. DAILY EVALUATION    06:00 Africa/Tripoli daily (EvaluatePmRulesJob → chunks of 200)
         │
4. GENERATE MR         If due, eligible, unsuppressed, and no active chain exists → creates Service MR
         │
5. MANAGER REVIEW      Approve (creates WO) or Reject/Cancel (creates suppression record)
         │
6. WORK ORDER          Technician executes work, fills checklist, records parts & readings, completes WO
         │
7. CLOSURE & RESET     Manager closes WO → baseline resets; lower numbered L<n> schedules cascade-reset
```

### 12.2 PM Rule Templates

Templates live in `pm_rules` and are managed exclusively by **Administrators** under **Admin → PM Rules (Service)** (`/admin/pm-rules`).

**Template fields:**

| Field | Type | Notes |
| --- | --- | --- |
| **Name** | string (required) | Descriptive label (e.g. `Motor 180-Day Service`). |
| **Description** | text (optional) | Scope and purpose notes. |
| **Trigger Type** | `date`, `reading`, `date_or_reading` | Only **Calendar (`date`)** can be chosen in the UI today; reading-based triggers are parked (Section 12.3). Immutable after creation. |
| **Interval Days** | integer | Calendar interval in days (`date` and `date_or_reading`). |
| **Interval Reading** / **Reading Type** | decimal / FK | For legacy `reading` / `date_or_reading` rules. `usage_reading_type_id` is immutable on backend updates. |
| **Maintenance Level** | string (optional, max 10 chars) | Numbered level (`L1`, `L2`, `L3`, `L4`, …) for cumulative cascade resets, or a custom independent label (e.g. `SEASONAL`). |
| **Applies To (Categories)** | Maintenance Category IDs | Optional automatic category coverage (Section 12.4). |
| **Active** | boolean | Controls whether the template is active. |

**Single Template vs. Multi-Level Setup:** When clicking **Create Template**, Administrators can toggle between **Single Template** and **Multi-Level Setup** (pre-filled with `L1`, `L2`, and `L3` rows sharing one **Applies To** category picker, created sequentially with independent Created/Failed feedback per row).

**Template editing & deactivation behavior:**

- **Editing intervals immediately affects existing assignments:** `PmDueCalculator` reads `interval_days` (and `interval_reading`) dynamically from the template on every evaluation and display, while each assignment keeps its own `last_triggered_*` baseline. Changing **Applies To** categories dispatches `ReconcilePmCategoryAssignmentsJob` in the background to add/withdraw category-origin assignments.
- **Deactivating a template (`POST /api/pm-rules/{id}/deactivate`):** Blocked (`409`) if any assignment has an active maintenance chain (`pending_review` MR or `open`/`in_progress`/`completed` WO). On deactivation, `MANUAL`-origin assignments keep `is_active = true` (paused while template is inactive), whereas `CATEGORY`-origin assignments are withdrawn (`is_active = false`) and automatically restored if the template is later reactivated (excluding any assignment manually deactivated by a user).
- **PM Rules list & detail views:** `/admin/pm-rules` filters by **Status** (`Active` default, `Inactive`, `All`) and displays `Template`, `Level`, human-readable `Schedule` (e.g. `90 days` → `Every 3 Months`), `Assets`, and `Active` status. Clicking a row opens **PM Rule Detail** (`/admin/pm-rules/:ruleId` — reachable by Admin and via deep link from Asset Detail by Manager), displaying Schedule, a **read-only** `Assigned Assets` table, `Generated Maintenance Requests`, and `Template Info`.

### 12.3 PM Trigger Types (Reading Triggers Parked)

> **Only Calendar (date-based) triggers are selectable in the UI today.** Reading-based (`reading`) and hybrid (`date_or_reading`) triggers are **parked** in the UI because meter readings currently enter ATMS only via Work Orders (too sparse for assets that rarely break). Existing legacy rules retain their stored trigger type and continue evaluating. *(Note: parking is enforced in the frontend form; the backend API still accepts all three enum values.)*

- **Calendar (`date`):** Due when `today >= last_triggered_date + interval_days` (and not suppressed). If `last_triggered_date` is null, treated as immediately due.
- **Usage (`reading`) *(parked)*:** Due when `latest_confirmed_reading >= last_triggered_reading + interval_reading` (and not suppressed). Requires at least one confirmed reading of the rule's reading type; null baseline defaults to `0`.
- **Calendar or Usage (`date_or_reading`) *(parked)*:** Due when either dimension crosses its threshold first.

### 12.4 PM Assignments — Linking Rules to Assets

Assignments (`asset_pm_assignments`) connect a template to a specific asset and store that asset's baseline (`last_triggered_date`, `last_triggered_reading`).

- **Where assignments are managed:** Per-asset assignments (assign, evaluate, deactivate, reactivate) are managed exclusively from **Asset Detail → PM Rules (Service)** by **Administrators and Maintenance Managers**. Bulk category coverage is managed on the template itself by **Administrators** under **Admin → PM Rules (Service)**. *(The `Assigned Assets` table on PM Rule Detail is read-only.)*
- **No "first free" overdue spike:** Assigning a template initializes `last_triggered_date = today` (and `last_triggered_reading` to the latest confirmed reading for reading rules), granting one full interval before first due.
- **Uniqueness & guards:** Only one assignment per rule+asset pair is allowed (`409` if duplicated; already-assigned rules appear disabled in the picker). Deactivating an assignment is blocked (`409`) if an active MR/WO chain exists, and clears stale suppression windows when allowed.
- **Category coverage (`By category` badge):** Assigning Maintenance Categories on a template automatically expands assignments in the background to all active, enrolled assets in those categories, keeps in sync when an asset's category/status changes, never overrides manual assignments or manual deactivations, and never interrupts an active MR/WO chain.
- **Asset Detail controls & indicators:** Includes `Since Service` and `Next Due` columns, a **Show Inactive** toggle, and styled text status badges (**OK** < 60% elapsed, **Soon** 60%–80%, **Due** ≥ 80% or due).

### 12.5 PM Evaluation — Scheduled & Manual

- **Daily schedule:** Runs at **06:00 `Africa/Tripoli`** via `EvaluatePmRulesJob` (unique lock + 3 retries with `60s / 300s / 900s` backoff), fanning out evaluable assignments into `EvaluatePmAssignmentsJob` batches of **200**.
- **Evaluable scope:** Active assignment + active template + asset is **active (`is_active = true`)** and **enrolled (`maintenance_status = enrolled`)**.
- **Manual evaluation:**
  - **Single assignment:** Click the **`▶` (Play)** icon button on an assignment row in **Asset Detail → PM Rules (Service)** (Admin or Manager) and confirm **Evaluate Now**.
  - **All assignments:** Click **Evaluate All** on **Admin → PM Rules (Service)** (Admin in the UI; backend policy `AssetPmAssignmentPolicy::evaluateAll` permits both Admin and Manager).
- **Algorithm per assignment (`EvaluatePmRule`):**
  1. Verify assignment, template, and asset eligibility (`is_active = true`, `maintenance_status = enrolled`).
  2. Check for an **active maintenance chain** (`pending_review` PM MR or `open`/`in_progress`/`completed` PM WO for the same asset + rule). Skip if one exists.
  3. Call `PmDueCalculator::isDue()`:
     - `date`: `today >= last_triggered_date + interval_days` and no active date suppression.
     - `reading`: `latest_confirmed_reading >= last_triggered_reading + interval_reading` and no active reading suppression.
     - `date_or_reading`: either dimension is due and unsuppressed.
  4. If due, generate one `MaintenanceRequest` with `is_preventive = true`, `pm_rule_id`, `status = pending_review`, `priority = medium`, `created_by = system@atms.internal`, description `"Auto-generated PM: {rule.name}"`, and trigger snapshots (`triggered_by_date`, `triggered_by_reading`, `trigger_date`, `trigger_reading_value`, `trigger_reading_type_id`).

### 12.6 Suppression — Preventing Immediate Regeneration

Rejecting or cancelling a Preventive MR records a suppression in `pm_occurrence_suppressions`:

- **Inclusive suppression boundaries:** `PmDueCalculator` suppresses while `suppressed_until_date >= today` (for date triggers) and `suppressed_until_reading >= latest_confirmed_reading` (for reading triggers). The rule does **not** fire again until strictly **after** `suppressed_until_date` (`today > suppressed_until_date`) or strictly **above** `suppressed_until_reading` (`latest_confirmed_reading > suppressed_until_reading`).
- **Decision types:** `rejected` (Manager declined MR), `cancelled` (Manager cancelled MR), and `performed_under_repair` (retired automatically when a closing WO declared that service, so PM compliance does not penalize it).
- **Stale cleanup:** Deactivating an assignment clears active suppression windows on that rule+asset pair.

### 12.7 Baseline Reset

When a PM Work Order closes — or when any Work Order closes with a PM level staged/declared (Section 8.5) — the assignment's `last_triggered_date` resets to today and `last_triggered_reading` resets to the latest confirmed reading.

### 12.8 Cumulative Maintenance Cascade

If the serviced rule's `maintenance_level` matches any numbered level **`L<number>`** (`/^L(\d+)$/`, e.g. `L1`, `L2`, `L3`, `L4`, `L5`, …), ATMS automatically resets the baselines of **all active lower-numbered `L<n>` assignments on that same asset**. For example, closing an `L3` service resets `L2` and `L1` as well. Custom non-`L<n>` labels (such as `SEASONAL`) are independent and reset only themselves.

### 12.9 PM Role Permissions

| Action | Admin | Manager | Technician / Logistics / Requester |
| --- | --- | --- | --- |
| Create / edit / deactivate / reactivate PM templates | Yes | No | No |
| Access Admin → PM Rules (Service) list | Yes | No | No |
| View PM Rule Detail (`/admin/pm-rules/:ruleId`) | Yes | Yes (deep link from Asset Detail) | No |
| Assign / deactivate / reactivate / evaluate single assignment | Yes | Yes | No |
| Evaluate all assignments | Yes (UI & API) | API only (UI is on Admin page) | No |
| Approve / reject / cancel Preventive MR | Yes | Yes | No |
| Stage PM level performed on an assigned WO | Yes | Yes | Technician only (assigned WO) |

### 12.10 Complete Walkthrough — Calendar PM Lifecycle

This example follows a Mud Motor (`MTR-001`) through a calendar-based **L2** service cycle:

1. **Administrator creates the template:** Open **Admin → PM Rules (Service)**, click **Create Template**, enter Name `"Motor 180-Day L2 Service"`, Trigger Type **Calendar (date-based)**, Interval Days `180`, Maintenance Level `L2`, and save. (Alternatively, use **Multi-Level Setup** to create `L1`, `L2`, and `L3` together for a Maintenance Category.)
2. **Maintenance Manager assigns it to `MTR-001`:** Open `MTR-001` in **Asset Management → All Assets**, scroll to **PM Rules (Service)**, click **Assign Rule**, select `"Motor 180-Day L2 Service"`, and confirm.
   - `last_triggered_date` initializes to **June 1, 2026**.
   - Next due date is **November 28, 2026** (June 1 + 180 days).
3. **Daily evaluation before due date:** At 06:00 each morning, `today < Nov 28` → not due. Badge progresses from **OK** (< 60%) to **Soon** (60%–80%) to **Due** (≥ 80%).
4. **Due date arrives (November 28, 2026):** At 06:00, `today >= Nov 28`, no active chain exists, and no suppression covers today → ATMS creates `MR-00452` (`is_preventive = true`, priority `medium`, `"Auto-generated PM: Motor 180-Day L2 Service"`).
5. **Manager reviews and approves:** Opens `MR-00452` from **Maintenance Requests → Pending Approval**, clicks **Approve & Create Work Order**, optionally assigns Technician Tariq and selects a workshop location, and confirms → `MR-00452` becomes **Converted to Work Order** and `WO-00217` opens.
6. **Technician executes the job:** Tariq clicks **Start** (`WO-00217` → `in_progress`, asset → **Under Maintenance**), fills the **Completion checklist**, records parts and delta meter readings, attaches the job sheet, and clicks **Complete…** (`WO-00217` → `completed`).
7. **Manager closes the Work Order (December 1, 2026):** Opens `WO-00217`, reviews work notes, readings, and attachments, and clicks **Close…** → `WO-00217` becomes `closed`, readings confirm, asset returns to **Ready for Field** with Condition **Normal**, `L2` baseline resets to **December 1, 2026** (next due **May 30, 2027**), and any active `L1` assignment on `MTR-001` automatically cascade-resets to December 1 as well.

*(When reading-based triggers are unparked in a future release, `date_or_reading` templates will evaluate both the calendar interval and confirmed meter readings in parallel, firing on whichever threshold is reached first.)*

---

## 13. Administration

The **Admin** section is the Administrator's configuration workspace. Navigating to `/admin` redirects to `/admin/lists`.

**Sidebar:** Admin — visible to **Administrator only**.

**Tabs (in display order, path-routed):**

1. **Lists & Dropdowns** (`/admin/lists`)
2. **PM Rules (Service)** (`/admin/pm-rules`)
3. **WO Forms** (`/admin/wo-forms`)
4. **Users & Access** (`/admin/users`)

### 13.1 Lists & Dropdowns (`/admin/lists`)

Manage configurable reference vocabularies organized in a left rail with two groups:

- **Master Data:**
  - **Maintenance Priorities:** Values available on Maintenance Requests (`value` matches `/^[a-z][a-z0-9_]*$/`, max 64 chars, immutable after creation; optional `sort_order`).
  - **Asset Conditions:** Condition labels assets carry between jobs (Section 5.10). `value` is immutable after creation; optional `sort_order`. The `is_default` item (**Normal**) cannot be deactivated or reassigned as default.
  - **Maintenance Categories:** ATMS-owned asset/part categories routing WO Forms, PM rules, part compatibility, and reports. `code` is auto-generated from `name` (max 50 chars) and immutable after creation. The **Unclassified** category is protected and cannot be deactivated.
- **Reading Types:**
  - **Usage Reading Types:** Meter types (e.g. Operating Hours, Kilometer Driven, Depth) used for meter readings and usage reports.

*(Locations are managed under **Locations → Manage Locations**, not here.)*

### 13.2 PM Rules (Service) (`/admin/pm-rules`)

Administrator workspace for PM rule templates (see Section 12 for full rules):

- Status filter: **Active** (default), **Inactive**, or **All**.
- **Create Template** supports both **Single Template** and **Multi-Level Setup** (batch creation of `L1`/`L2`/`L3` rows with per-row success/failure feedback).
- **Evaluate All** manually triggers evaluation across all active eligible assignments.
- Clicking a template opens **PM Rule Detail** (`/admin/pm-rules/:ruleId`, also reachable by Maintenance Managers via deep link from Asset Detail).

### 13.3 WO Forms (`/admin/wo-forms`)

Manage **Work Order Execution Form** templates per **Maintenance Category**:

- **Status filter:** **Active** (default), **Inactive**, or **All**.
- **Two-step creation workflow:**
  1. Click **Create Form**, enter the **Template Name**, tick the **Maintenance Categories** it covers (selected categories pin to the top; categories already covered by another active template appear greyed out naming that template), and click **Create Template**.
  2. Once created, the sheet opens in edit mode so you can add, edit, reorder (**Move Up / Move Down** buttons), or remove fields individually (with inline removal confirmation and an unsaved-changes discard prompt for template metadata).
- **Field properties:** **Label**, **Type** (**Yes/No**, **Numeric**, or **Text**), **Unit** (numeric only, display), **Pre + post values** checkbox, and **Required** checkbox.
- **Deactivation & reactivation:** Deactivating a template releases its categories for other templates and leaves already-snapshotted WO forms intact. Reactivation is refused if another active template now holds one of its categories or if it has no categories assigned.

### 13.4 Users & Access (`/admin/users`)

- **No external employee directory:** Accounts are created directly by Administrators; mailbox ownership is proven when the user opens their activation email.
- **Table columns:** `Name`, `Email`, `Role`, `Status` (`Active`, `Pending Activation`, or `Inactive`), plus row actions. Your own row displays a **`you`** badge, and the non-human `service` role is hidden from assignable roles.
- **Add User:** Opens the **Create User** dialog (Name, Email on `@ldc.com.ly`, Role). Creates an inactive account and emails a single-use activation link valid for **24 hours**.
- **Edit User:** Update Name, Email, and Role. Administrators cannot edit, deactivate, or reset their own account through `/admin/users` (use **User menu → Change password** for your own password).
- **Activate / Deactivate:** Reversible toggle. Deactivating a user immediately invalidates all active sessions and revokes all Sanctum tokens while preserving historical ownership and audit references.
- **Reset Password (Admin):** Opens a dialog where the Administrator **directly enters and sets a new password** (minimum 8 characters + confirmation) for the user, immediately signing them out of all sessions and revoking all tokens. **No reset email or token is sent.**

### 13.5 Operations & Server CLI Commands (Administrator / DevOps)

Several bulk data and operational workflows run on the backend server via Artisan CLI or API endpoints rather than the web UI:

| Command / Endpoint | Purpose |
| --- | --- |
| `php artisan atms:make-admin` | Creates the initial active Administrator account on a fresh deployment (blocked once an active activated Admin exists). |
| `php artisan atms:import-parts [file] [--dry-run]` | Validates and imports LDC's cleaned spare parts CSV (from `SPARE PARTS (ERP).xlsx`), updating existing parts, inserting new parts, and syncing Maintenance Categories. |
| `php artisan atms:import-assets [file] [--dry-run] [--prune]` | Validates and updates existing assets from the cleaned LDC assets CSV matched on `erp_asset_code`, maps legacy `down → failure`, records unseen `fa_subclass_code` as `UNK`, and reconciles PM category assignments. |
| `php artisan atms:sync-asset-names [file] [--dry-run]` | Updates only `name` and `size_inches` on existing assets from the workbook CSV (audited as `asset.workbook_sync`). |
| `php artisan atms:import-erp-assets [file] [--dry-run] [--force]` | Legacy initial-load command that truncates asset tables and loads an ERP CSV dump (not an upsert). |
| `GET /api/health/live` & `GET /api/health/ready` | Liveness and readiness probes for deployment monitoring. |
| `GET/POST/DELETE /api/admin/api-clients` & `POST /api/auth/token` | Backend API endpoints for managing M2M API clients and exchanging credentials for Sanctum bearer tokens (no web UI). |

---

## 14. System Settings, Audit Logs & Notifications

**Access:** Open **User menu** (top-right avatar) → **Settings** submenu (**Administrator only**). It contains two standalone pages (not tabs): **System** (`/settings/system`) and **Audit Logs** (`/settings/audit-logs`).

### 14.1 System (`/settings/system`)

> **Status: Coming soon.** The `/settings/system` screen is currently a placeholder (*"Company settings, ERP connection, sync schedule, and manual sync controls are not built yet."*).

- **Company Timezone:** Defaults to `Africa/Tripoli` (the frontend currently formats dates in `Africa/Tripoli`; backend `GET/PATCH /api/admin/company-settings` exists for `timezone`, without a UI yet).
- **ERP Connection & Sync Schedule:** Managed via server environment configuration (`config/erp.php`, `routes/console.php`); live ERP sync is deferred to Phase 3 (Section 10.4).
- **Microsoft Graph Email:** Configured via server environment variables; powers account emails and MR/WO workflow notifications (Section 14.3).

### 14.2 Audit Logs (`/settings/audit-logs`)

A read-only, append-only technical audit trail viewable by **Administrators only**:

- **Filters (6 UI filters):** Grouped **Event** dropdown (categories + individual events), **Event contains…** free-text filter (overrides the dropdown), **Subject type** dropdown, **Actor** dropdown, **From** date, and **To** date (capped at today).
- **Table columns:** `Timestamp`, `Actor` (or `System`), `Event`, `Subject` (`Type #id`), and `IP Address`.
- **Audit Entry detail side sheet:** Clicking any row opens a detail sheet showing `Timestamp`, `Event`, `Actor` (with email), `Subject`, `IP Address`, `Request ID`, `User Agent`, formatted **Before / After JSON state diff**, and **Metadata JSON**.
- **Pagination:** Cursor-based **Load more** pagination (50 entries per page; API cap 500). No CSV export exists for Audit Logs.
- **Audited events include:** Authentication (`login`, `login_failed`, `logout`), user lifecycle & passwords (`user.created`, `user.updated`, `user.password_changed`, `user.password_reset`, `user.password_reset_by_admin`, activation/deactivation), MRs & WOs (all transitions, execution edits, part add/remove, WO form edits/sync/defer, PM marks & declared service), assets (updates, workbook sync, location moves, status changes, condition resets, bookings), meter readings (record, update, delete, confirm/skip), parts (updates & `parts.quantity_upload.completed` with SHA-256 hash), PM rules/assignments/suppressions, form templates & fields, attachments (upload & soft delete), and API clients/tokens (`api_client_created`, `api_client_revoked`, `api_token_issued`, `api_token_issuance_failed`).
- **Never stored in audit logs:** Passwords, session cookies, API secrets, and attachment file contents.

### 14.3 Automated Email Notifications

ATMS sends automated emails via Microsoft Graph (`sendMail`) for account security and maintenance workflow transitions (there are no per-user notification preference toggles):

| Trigger | Recipients |
| --- | --- |
| **Account created** | New user (24-hour activation link) |
| **Forgot password requested** | User email (1-hour reset link) |
| **Corrective MR submitted** | All active Maintenance Managers |
| **MR approved** | MR creator + assigned user (if assigned at approval) |
| **MR rejected** | MR creator (includes rejection reason) |
| **WO assigned / reassigned** | Assigned user |
| **WO started** | All active Maintenance Managers |
| **WO completed** | All active Maintenance Managers |
| **WO closed** | Assigned user (with all active Maintenance Managers in **CC**) |
| **WO cancelled** | Assigned user, if assigned (includes cancellation reason) |

---

## 15. Attachments

Files can be attached to four record types: **Assets**, **Parts**, **Maintenance Requests**, and **Work Orders**.

**Upload rules & behavior:**

- **Max size:** 20 MB per file. Multiple files can be selected in one upload dialog.
- **Allowed documents:** PDF, DOC, DOCX, XLS, XLSX.
- **Allowed images:** JPEG, PNG, **GIF**, WebP.
- **Security:** Executables, scripts, disk images, and archives are rejected. Server-side MIME sniffing (`finfo_file`) validates file content against extension whitelists, and each file's SHA-256 hash is stored.
- **Preview & download:** Files open in a new tab via authenticated backend blob download (never direct public file paths). Attachments have no `category` field, and the UI upload dialog has no `description` input (API accepts optional `description`).
- **Soft deletion:** Deleted attachments are soft-deleted (`deleted_at`, `deleted_by_user_id`), excluded from lists and downloads, with no restore UI.

**Attachment permission matrix (`AttachmentPolicy`):**

| Record Type | Who Can Upload | Who Can View / Download | Who Can Delete |
| --- | --- | --- | --- |
| **Asset** | Admin, Manager, Technician, Logistics | Admin, Manager, Technician, Requester *(Logistics excluded by policy)* | Admin, Manager |
| **Part** | Admin, Manager, Technician, Logistics | Admin, Manager, Technician, Requester *(Logistics excluded by policy)* | Admin, Manager |
| **Maintenance Request** | Admin, Manager, or MR creator (**any status**, including terminal) | Admin, Manager, Technician, Logistics, Requester | Admin, Manager (any status); creator only for **own upload while `pending_review`** |
| **Work Order** | Admin, Manager, or assigned Technician (while `open`, `in_progress`, or `completed`; locked on `closed`/`cancelled`) | Admin, Manager, Technician, Logistics, Requester | Admin, Manager (while not `closed`/`cancelled`); **nobody** (not even Admin) on `closed` or `cancelled` WOs |

---

## 16. Reports

The **Reports** section (`/reports`) provides **20 live, read-only operational reports** running directly against live ATMS data.

**Sidebar:** Reports — Direct Link — visible to **everyone**.

### 16.1 The Reports Catalogue

| Theme | Reports |
| --- | --- |
| **Reliability & Availability** | R-3 MTBF / Failure Rate, R-4 MTTR, R-6 Bad-Actor / Breakdown Analysis |
| **PM Management** | R-1 Upcoming PM Schedule, R-7 PM Compliance, R-8 Overdue PM, R-9 PM Coverage / Gaps |
| **Asset Status & Fleet** | R-2 Asset Distribution, R-1B Assets Status Report, R-10A Operational Status Distribution, R-22 Most-Used Assets, R-13 Asset Booking / Availability |
| **Workload & Backlog** | R-14 WO Backlog / Aging, R-15 Workload by Technician, R-16 MR / WO Throughput |
| **Parts & Movement** | R-17 Parts Consumption, R-18 Asset Movement Log |
| **Inspection, Readings & PM Audit** | R-19 Work Order Form Results, R-20 Meter Reading Progression, R-21 PM Suppression Register |

### 16.2 What Each Report Answers

| ID | Report | What It Shows & Key Filters |
| --- | --- | --- |
| **R-1** | Upcoming PM Schedule | Active, enrolled assets with a **date-triggered** PM due within a selectable horizon (**7, 14, 30 [default], 60, or 90 days**; excludes never-triggered assignments). Shows PM chain status; filters by location and PM rule. |
| **R-1B** | Assets Status Report | Filterable, exportable listing of active, **enrolled** assets (default **50 rows/page**): tag, name, type, operational status, Condition, location, current open/in-progress WO assignee, and dates (`updated_at` or `created_at`). **CSV export.** |
| **R-2** | Asset Distribution | Active assets grouped by **1 to 4 ordered dimensions** via multi-select `group_by` (**Location**, **Maintenance Category**, **Size**, and **Condition**), broken down per row by Operational Status, Asset Kind, and Booked. **CSV export.** |
| **R-3** | MTBF / Failure Rate | Classified failures (`is_failure = true`) grouped by `asset`, `maintenance_category`, `size`, or `location`. |
| **R-4** | MTTR | Average repair turnaround (`assigned_at → closed_at`) on closed corrective WOs, grouped by `asset`, `maintenance_category`, `size`, or `technician`. |
| **R-6** | Bad-Actor / Breakdown Analysis | Top-N assets, maintenance categories, sizes, or locations with the most confirmed failures (UI `Show` selector: Top 10, Top 25 [default], Top 50). |
| **R-7** | PM Compliance | On-time completion % for **date-triggered** PMs, groupable by `rule`, `asset`, or `location`. |
| **R-8** | Overdue PM | Past-due **date-triggered** PM MRs not yet closed, bucketed into `0–7`, `8–30`, `31–90`, and `91+` days. |
| **R-9** | PM Coverage / Gaps | Active assets with no active PM assignment. |
| **R-10A** | Operational Status Distribution | Fleet split across Ready for Field, Under Maintenance, Failure, and At the Field, with an **"Include deactivated assets"** checkbox (off by default). |
| **R-13** | Asset Booking / Availability | Booked vs. freely available active assets by location. |
| **R-14** | WO Backlog / Aging | Open and in-progress WOs by age bucket and priority. |
| **R-15** | Workload by Technician | Open, In Progress, Completed, Closed, Cancelled, Backlog count, average backlog age (days), and average execution duration (`started_at → completed_at`) per technician (operational workload only). |
| **R-16** | MR / WO Throughput | Counts by status over the period plus average MR conversion time. |
| **R-17** | Parts Consumption | Quantities consumed on `completed`/`closed` WOs grouped by **Part + Asset Maintenance Category + Asset Size** (no location filter/grouping; no financial costing). Summary **Total qty** displays when filtered to a single Part. |
| **R-18** | Asset Movement Log | Physical relocations in the period by `from → to` route. |
| **R-19** | Work Order Form Results | **Numeric comparisons** summary table (Pairs, Avg pre, Avg post, Avg change) and Yes/No counts above individual field responses. UI filters: From, To, **Maintenance Category**, and **Asset** (`field_uuid` is API-only). |
| **R-20** | Meter Reading Progression | Confirmed meter reading history over time by asset and reading type. |
| **R-21** | PM Suppression Register | Suppressed/retired PM occurrences filterable by decision type (`rejected`, `cancelled`), PM rule, Asset, and date range (`decided_at`). |
| **R-22** | Most-Used Assets | Active assets ranked by accumulated confirmed usage delta (default top 25; falls back to lowest confirmed reading in window if no prior baseline), groupable by `asset`, `maintenance_category`, or `size`. **CSV export.** |

### 16.3 Report Pages, Grouping, Filtering & Role-Gated Dropdowns

- **On-screen filters (not URL-synced):** Every report renders a filter bar, summary strip (computed over the full result set), and table with cursor-based **Load more** pagination (default 25/page, R-1B 50/page, API cap 500). Date-windowed reports default to the **trailing 90 days**. Filter selections stay in page memory and **reset on reload** (filters are not written to the URL).
- **ATMS-owned dimensions:** Reports filter and group by **Maintenance Category**, **Size**, **Location**, **Condition**, **Asset**, **PM Rule**, or **Technician** — never `fa_subclass_code` or legacy `asset_class`. Unclassified assets appear under the real **Unclassified** category.
- **Role-gated filter dropdowns:** While all 20 reports are open to every role, the **Technician / Assigned-to** dropdown (R-4, R-14, R-15) and **PM Rule** dropdown (R-1, R-7, R-8, R-21) require Admin or Maintenance Manager authorization to populate user/rule lists.

### 16.4 CSV Export

Three reports provide an **Export CSV** button using the same report endpoint with `?format=csv` and your currently applied filters:

- **R-1B Assets Status Report** (full unpaginated result set)
- **R-2 Asset Distribution** (full unpaginated result set)
- **R-22 Most-Used Assets** (exports the capped top-N result set — default **25 rows**)

Exports include a UTF-8 BOM (for Excel compatibility with Arabic text), use `Africa/Tripoli` timestamps, download as `{slug}-{YYYY-MM-DD}.csv`, and guard cells starting with `=`, `+`, non-numeric `-`, `@`, tab, or CR with a leading apostrophe to prevent spreadsheet formula injection.

### 16.5 Fleet Population Rules (Deactivated & Withdrawn Assets)

- **Deactivated assets (`is_active = false`):** Excluded by default across fleet reports. **R-10A Operational Status Distribution** includes an **"Include deactivated assets"** checkbox (and R-2 supports `include_inactive` via API).
- **Withdrawn assets (`maintenance_status = withdrawn`):** **R-1B Assets Status Report** and **R-1 Upcoming PM Schedule** exclude withdrawn assets (enrolled fleet only). Other active-fleet reports — **R-2**, **R-9 PM Coverage**, **R-10A**, **R-13**, and **R-22** — filter on `is_active = true` and **include** active withdrawn assets. Because Dashboard Programme Readiness filters to active **enrolled** assets while R-9 PM Coverage counts all active assets, their totals can differ whenever active assets are withdrawn.

---

## Appendix A: Status & Enum Reference

### Maintenance Request Statuses (`MaintenanceRequestStatus`)

| DB Value | Display Label | Terminal |
| --- | --- | --- |
| `pending_review` | **Pending Review** | No |
| `converted` | **Converted to Work Order** | Yes |
| `rejected` | **Rejected** | Yes |
| `cancelled` | **Cancelled** | Yes |

### Maintenance Request Type & Failure Classification

| Field | Values & Display Labels |
| --- | --- |
| **Type** (`is_preventive`) | `false` (`corrective`) → **Repair**; `true` (`preventive`) → **Service** |
| **Failure Classification** (`is_failure`) | `true` → **Failure**; `false` → **No failure**; `null` → **Not yet classified** |

### Maintenance Request Priorities

| Value | Display Label |
| --- | --- |
| `low` | **Low** |
| `medium` | **Medium** *(default for auto-generated PM MRs)* |
| `high` | **High** |
| `critical` | **Critical** *(picker: "Critical — immediate attention required")* |

### Work Order Statuses (`WorkOrderStatus`)

| DB Value | Display Label | Terminal |
| --- | --- | --- |
| `open` | **Open** | No |
| `in_progress` | **In Progress** | No |
| `completed` | **Completed** | No |
| `closed` | **Closed** *(permanently immutable)* | Yes |
| `cancelled` | **Cancelled** | Yes |

### Asset Maintenance Status (`MaintenanceStatus`)

| DB Value | Display Label (Badges/Cards) | PM & Workflow Eligible |
| --- | --- | --- |
| `enrolled` | **In ATMS** *(Edit dropdown: "In maintenance program — eligible for maintenance workflows")* | Yes |
| `withdrawn` | **Withdrawn** | No |

### Asset Operational Status (`OperationalStatus`)

| DB Value | Display Label | Manually Selectable? |
| --- | --- | --- |
| `ready_for_field` | **Ready for Field** | Yes |
| `under_maintenance` | **Under Maintenance** | Yes |
| `failure` | **Failure** | Yes |
| `at_the_field` | **At the Field** | No (derived from `rig`/`well_site` location) |

### Asset Condition (`condition_status`, Admin-configurable)

| DB Value | Default Label | Notes |
| --- | --- | --- |
| `normal` | **Normal** | System default (`is_default = true`); cannot be deactivated; WO close resets to this |
| `need_assembly` | **Need Assembly** | Informational |
| `missing_parts` | **Missing Parts** | Informational |
| `need_inspection` | **Need Inspection** | Set automatically on manual return from a rig or well site |

### Asset Kinds (`AssetKind`)

| DB Value | Display Label | Role |
| --- | --- | --- |
| `asset` | **Asset** | Standalone unit (default) |
| `package` | **Package** | Can contain children and be installed in a parent (Phase 2 assembly UI) |
| `component` | **Component** | Installable leaf component (Phase 2 assembly UI) |

### Booking Statuses (`BookingStatus`)

| DB Value | Meaning |
| --- | --- |
| `active` | Active reservation (sets `is_booked = true` when covering today) |
| `cancelled` | Manually cancelled by Admin, Manager, or Logistics |
| `released` | Automatically released when asset is deactivated or withdrawn |

### Location Types (`LocationType`) & Deployment Buckets (`AssetDeployment`)

| Location Type | Deployment Bucket |
| --- | --- |
| `rig`, `well_site` | `deployed` |
| `yard`, `building` | `idle` |
| `workshop` | `maintenance` |

### PM Trigger Types, Origins, Due Statuses & Chain Statuses

- **Trigger Types (`PmTriggerType`):** `date` (**Calendar**), `reading` (**Usage** — parked), `date_or_reading` (**Calendar or Usage** — parked).
- **Assignment Origin (`PmAssignmentOrigin`):** `manual` (per-asset), `category` (**By category**).
- **Due Status Badges:** **OK** (< 60%), **Soon** (60%–80%), **Due** (≥ 80% or due).
- **PM Chain Status (Reports):** `not_yet_generated`, `generated_mr_pending`, `wo_open`, `wo_completed`.

### WO Form Field Types (`FormFieldType`), User Statuses & Roles (`RoleCode`)

- **Form Field Types:** `boolean` (**Yes/No**), `numeric` (**Numeric**), `text` (**Text**).
- **User Account Statuses:** **Active**, **Pending Activation**, **Inactive**.
- **Roles (`RoleCode`):** `administrator`, `maintenance_manager`, `technician`, `logistics`, `requester`, `service` (non-human M2M).
- **ERP Sync Job Statuses (backend scaffolding, Phase 3):** `running`, `success`, `partial`, `failed`.

---

## Appendix B: Role Permission Quick Reference

| Permission | Admin | Manager | Technician | Logistics | Requester |
| --- | --- | --- | --- | --- | --- |
| View Dashboard & 20 Reports | Yes | Yes | Yes | Yes | Yes |
| View My Profile & Change Own Password | Yes | Yes | Yes | Yes | Yes |
| Create corrective MR | Yes | Yes | Yes | No (403) | Yes |
| Edit own pending corrective MR | Yes | Yes | Yes | No (403) | Yes |
| Edit any pending MR | Yes | Yes | No | No | No |
| Cancel own pending corrective MR | Yes | Yes | No (403) | No (403) | Yes |
| Cancel any pending MR / Approve / Reject MR | Yes | Yes | No | No | No |
| View Work Orders (sidebar & list) | Yes (all) | Yes (all) | Yes (assigned) | Hidden | Hidden |
| Assign / reassign WO | Yes | Yes | No | No | No |
| Start / Complete WO | Yes | Yes | Yes (assigned) | No | No |
| Edit WO notes, checklist, parts, PM mark | Yes | Yes | Yes (assigned, open/in-progress) | No | No |
| Update asset status via WO | Yes | Yes | Yes (assigned, non-terminal) | No | No |
| Close / Cancel WO | Yes | Yes | No | No | No |
| View Assets | Yes (all) | Yes (all) | Yes (active only) | Yes (active only) | Hidden in sidebar |
| Edit Asset, Kind, Maintenance Status, Condition | Yes | Yes | No | No | No |
| Suggest / set initial Asset Tag | Yes | Yes | No | No | No |
| Book / Edit / Override / Cancel Asset Booking | Yes | Yes | No | Yes | No |
| Move Asset Location (Locations tab) | Yes | Yes | No | Yes | No |
| Record / edit / delete unconfirmed meter reading | Yes | Yes | Yes (via WO) | No | API-only (no UI) |
| Confirm meter reading † | At WO close | At WO close | No (UI) | No | No |
| View Parts Reference | Yes (all) | Yes (all) | Yes (active only) | Hidden | Hidden |
| Edit local Part fields (5 UI fields) | Yes | Yes | No | No | No |
| Parts CSV Export & Bulk Quantity Upload | Yes | No | No | No | No |
| View Raw ERP Data (Part Detail) | Yes | No | No | No | No |
| Upload Asset / Part attachments | Yes | Yes | Yes | Yes | No |
| View / Download Asset / Part attachments | Yes | Yes | Yes | No (403) | Yes (API) |
| Delete attachments (Assets, Parts, non-terminal WOs) | Yes | Yes | No | No | Own pending MR only |
| Manage PM rule templates (`/admin/pm-rules`) | Yes | No | No | No | No |
| View PM Rule Detail (`/admin/pm-rules/:ruleId`) | Yes | Yes (deep link) | No | No | No |
| Manage PM assignments on Asset Detail | Yes | Yes | No | No | No |
| Evaluate all PM assignments | Yes | API only | No | No | No |
| Manage Locations, Lists, Categories, WO Forms | Yes | No | No | No | No |
| Manage Users & Admin Reset Password | Yes | No | No | No | No |
| View Audit Logs (`/settings/audit-logs`) | Yes | No | No | No | No |
| Manage API Clients (backend API only) | Yes | No | No | No | No |

† In the web UI, meter readings are confirmed automatically when an Administrator or Maintenance Manager closes the Work Order they were recorded on. *(Phase 2 features — Asset Assembly UI, Add Asset UI, and `/settings/system` controls — are not yet built.)*

---

## Appendix C: Glossary

- **Asset:** A physical item tracked in ATMS for maintenance, locations, bookings, readings, and history.
- **Asset kind (`asset_kind`):** Declares an asset's intended assembly role: `asset` (standalone), `package` (container & installable), or `component` (installable leaf). Assembly operations UI is planned for Phase 2.
- **Asset tag:** Unique physical label code (`L-BBB-CCC-XXXX`) suggested via **Edit Asset** and formatted for future QR labelling.
- **At the Field (`at_the_field`):** Operational status derived automatically when an asset moves to a `rig` or `well_site` location. Never manually selectable.
- **Avg request / Avg work order:** Trailing 90-day Dashboard Process Performance KPIs measuring average MR resolution time and average WO creation-to-closure time (displayed as `Xh` or `Xd Yh`).
- **Booking:** A date-range reservation in `bookings` (Admin, Manager, Logistics). Sets derived `is_booked = true` when covering today; survives maintenance and moves; auto-releases on deactivation or withdrawal.
- **CM (Corrective Maintenance / Repair):** User-initiated maintenance request for a fault or defect (`is_preventive = false`).
- **Completion checklist (WO Execution Form):** Snapshot of an active FormTemplate matched by the asset's Maintenance Category, filled on `open`/`in_progress` WOs before completion.
- **Component (`component`):** An `AssetKind` value for an installable leaf asset (cannot contain children).
- **Condition (`condition_status`):** Informational asset state between jobs (`Normal`, `Need Assembly`, `Missing Parts`, `Need Inspection`). Never blocks workflows; resets to default on WO close.
- **Confirmed reading:** A meter reading confirmed automatically on Work Order closure (immutable; feeds PM evaluation and usage reports).
- **Cumulative maintenance:** Closing a WO for any numbered `L<number>` PM level resets the baselines of all active lower-numbered `L<n>` PM assignments on the same asset.
- **Enrolled (`enrolled`):** Asset Maintenance Status displayed as **In ATMS** (eligible for maintenance workflows and PM evaluation).
- **ERP (Enterprise Resource Planning):** Corporate financial/inventory system. Currently interacts with ATMS via offline Excel/CSV exports and CLI/UI imports; live ERP sync is planned for Phase 3.
- **Failure (`failure`):** Operational status indicating a confirmed fault. Set automatically on corrective MR approval (unless `under_maintenance`), and manually selectable by Admin, Manager, and assigned Technician (via WO).
- **Failures (Dashboard KPI):** Count of classified failures (corrective MRs with `is_failure = true`) created in the last 90 days.
- **KPI (Key Performance Indicator):** Six trailing 90-day Dashboard metrics grouped into **Equipment reliability** (`MTBF`, `MTTR`, `Failures`) and **Process performance** (`PM compliance`, `Avg request`, `Avg work order`).
- **Maintenance Category:** ATMS-owned classification required on every asset (defaulting to **Unclassified**) and optional on parts; routes WO Forms, PM category coverage, part compatibility, and reports.
- **Maintenance History:** Closed Work Orders history card on Asset Detail.
- **Microsoft Graph `sendMail`:** Email transport used for account activation, password reset, and all 8 MR/WO workflow notifications.
- **MTBF (Mean Time Between Failures):** Calendar days in the 90-day window divided by classified failures (`is_failure = true`), displayed in days.
- **MTTR (Mean Time To Repair):** Average elapsed time from **`assigned_at` to `closed_at`** for **closed** corrective Work Orders closed in the 90-day window (`Xh` or `Xd Yh`).
- **Package (`package`):** An `AssetKind` value for an asset that can contain child assets and be installed inside a parent.
- **Parts CSV Round-Trip:** Administrator-only workflow in `/parts` to download the full parts catalogue as CSV, reconcile quantities against an ERP Excel export, and upload corrected quantities all-or-nothing keyed on `part_id`.
- **PM (Preventive Maintenance / Service):** System-generated recurring maintenance (`is_preventive = true`). Currently Calendar (`date`) in the UI; reading triggers are parked.
- **PM Compliance:** Percentage of **date-triggered** PM requests due in the 90-day window whose WO closed on or before `trigger_date`.
- **PM Level Marking:** Staging the highest PM level performed during an `open` or `in_progress` WO so baselines reset automatically on closure (can be overridden or suppressed by the closer).
- **PM Suppression:** Occurrence deferral record created when a Preventive MR is rejected or cancelled (or retired as `performed_under_repair`). Suppressed inclusively through `suppressed_until_date` / `suppressed_until_reading`.
- **Ready for Field (`ready_for_field`):** Operational status for a serviceable asset at base. Every closed Work Order returns active assets to this status.
- **Under Maintenance (`under_maintenance`):** Operational status for an asset being serviced. Set automatically when a WO starts, and also manually selectable by Admin, Manager, or assigned Technician.
- **Unverified reading:** Any newly recorded meter reading before its Work Order closes (all new readings start unconfirmed regardless of submitter role). Editable/deletable until confirmed; excluded from PM evaluation.
- **Withdrawn (`withdrawn`):** Asset Maintenance Status removing an asset from maintenance workflows, PM evaluation, bookings, and manual moves without deactivating the record.
- **WO (Work Order):** Execution record created atomically from an approved MR (`open → in_progress → completed → closed`, or `cancelled`).
