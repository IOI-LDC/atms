# Screen Inventory

Screens are organised by the seven sidebar navigation groups. Drill-down pages
(asset detail, part detail, work order detail, review MR) are accessed from
their respective list screens and do not appear in the sidebar.

---

## 1. Dashboard

**Sidebar:** Direct link — visible to everyone.

**Route:** `/dashboard`

Displays the operational overview.

Required elements:

- KPI summary cards
- Pending MR list
- Open WO list
- Overdue PM list
- Recently closed WO list
- Recently updated assets

---

## 2. Maintenance Requests

**Sidebar:** Tabbed group — visible to everyone.

**Route:** `/maintenance`

Tabs:

### 2a. New Request
- **Visible to:** Everyone.
- Side sheet form for creating a Corrective Maintenance Request.
- Fields: asset selector, issue description, priority, location, supporting
  notes.
- Requester-submitted readings (unverified) may be included.

### 2b. My Requests
- **Visible to:** Everyone.
- List of MRs created by the current user.
- Columns: MR number, type (PM/CM), asset, priority, requested date, status.

### 2c. Pending Approval
- **Visible to:** Admin, Manager.
- List of MRs with status `pending_review` awaiting Maintenance Manager review.
- Columns: MR number, type, asset, priority, requested/generated date, status.
- Row actions: Approve / Reject.

### 2d. All Requests
- **Visible to:** Admin, Manager.
- Every MR regardless of status, with search and filters.
- Columns: MR number, type, asset, priority, requested date, status.

### Drill-down: Review Maintenance Request
- Full-page view used by the Maintenance Manager.
- While `pending_review`, the creator (or Admin/Manager) may edit description,
  priority, and asset. Edits do not change MR status.
- Actions: Approve & Create Work Order, Reject Request, Edit.
- Displays: asset details, request description, origin (user/system), PM rule
  trigger details where applicable, attachments.

---

## 3. Work Orders

**Sidebar:** Tabbed group — visible to Admin, Manager, Technician.

**Route:** `/work-orders`

Tabs:

### 3a. My Work Orders
- **Visible to:** Technician only.
- WOs assigned to the current technician.

### 3b. All Work Orders
- **Visible to:** Admin, Manager.
- Every WO regardless of status or assignment, with search and filters.

### 3c. Active
- **Visible to:** Admin, Manager, Technician.
- WOs with status `open` or `in_progress`.
- Columns: WO number, related MR, asset, status, assigned user, priority,
  created date.

### 3d. Completed
- **Visible to:** Admin, Manager, Technician.
- WOs with status `completed` (awaiting closure).

### 3e. Closed
- **Visible to:** Admin, Manager, Technician.
- WOs with status `closed` (terminal, read-only).
- Columns: WO number, asset, type (PM/CM), closed date, final status, parts
  used summary, link to details.

### Drill-down: Work Order Detail
- Full-page view for execution and closure.
- Sections: overview, related MR, work notes, parts used, attachments, updated
  readings, final asset status/condition.
- Close action available when all requirements are met.

---

## 4. Asset Management

**Sidebar:** Tabbed group — visible to Admin, Manager, Technician, Logistics.

**Route:** `/assets`

Tabs:

### 4a. All Assets
- **Visible to:** Admin, Manager, Technician, Logistics.
- Full asset registry with search and filters.
- Columns: asset tag, name, category, status badge, current location, latest
  usage reading, PM status, asset kind (Asset / Package / Component badge),
  parent asset (for components).
- Row actions: "Add Asset" (Admin/Manager only), "Edit Asset" per row
  (Admin/Manager only).
- For Requesters, this screen is a limited active-asset lookup for creating
  Corrective Maintenance Requests. Hide maintenance history, location history,
  attachments, and ERP raw/reference details.

### 4b. Asset Assembly
- **Visible to:** Admin, Manager, Technician, Logistics.
- Assembly management for packages and components.
- Required elements:
  - Component list with PM status indicators (green 🟢 / yellow 🟡 / red 🔴).
  - Install Component action (side sheet to search and select a spare).
  - Remove Component action (dialog with reason field and post-removal disposition).
  - Swap Component action (remove old + install new in one operation).
  - "Create MR for Component" action for yellow/red items on parent WO screen
    (Admin/Manager only).
  - Assembly history timeline for the selected component.

### Drill-down: Asset Detail
- Full operational profile of one asset.
- Sections: overview, ERP reference data, current physical location, usage
  readings, location history, maintenance history, attachments.
- Requester view is reduced to basic active-asset information for submitting
  a Corrective Maintenance Request.

### Drill-down: Usage & Meter Readings
- Displays and captures readings.
- Fields: reading type, reading value, reading date/time, entered by, confirmed
  by (when confirmed).
- Uses a simple "Unverified" indicator for unconfirmed readings.

### Drill-down: Location History
- Current location and past physical location changes.
- Update action (Logistics, Manager, Admin only): current location, new
  location, effective date, reason/notes.

---

## 5. Parts Management

**Sidebar:** Tabbed group — visible to Admin, Manager, Technician.

**Route:** `/parts`

Tabs:

### 5a. All Parts
- **Visible to:** Admin, Manager, Technician.
- Read-only SM parts catalogue with search and filters.
- Columns: ERP part code, part name, unit of measure, status.
- Link to part detail per row.

### 5b. Part Request
- **Visible to:** Admin, Manager, Technician.
- Convenience link into the SM (Store Management) subsystem's "New Request"
  flow. Allows users to order parts from the SM catalogue without leaving the
  ATMS system.
- This is a cross-subsystem integration point — the actual part-request form
  and workflow are owned by SM.
- Parts used on a Work Order are also submitted through this flow.

### Drill-down: Part Detail
- Sections: ERP reference data, attachments (manuals/datasheets), related
  work order usage (optional).

---

## 6. Admin

**Sidebar:** Tabbed group — visible to Administrator only.

**Route:** `/admin`

Tabs:

### 6a. Users & Access
- **Visible to:** Admin only.
- Employee Directory Import: shows locally imported SharePoint employees.
- User provisioning: select imported employee → assign one fixed role → send
  activation link.
- User management: list, activate/deactivate, role reassignment, force password
  reset.
- Fixed role reference is shown for assignment; no custom role management.

### 6b. Lists & Dropdowns
- **Visible to:** Admin only.
- Manage all configurable dropdown values used across the system.
- Includes: **Locations**, asset statuses, asset maintenance sub-statuses,
  maintenance priorities, usage reading types, work order statuses, maintenance
  categories, and other master-data / lookup values.
- Location management is part of this tab (not a standalone screen).

### 6c. PM Rules
- **Visible to:** Admin only. (Maintenance Managers may view and trigger manual
  evaluation but configuration is Admin-only.)
- List of preventive maintenance rules per individual asset.
- Columns: rule name, asset, reading type, interval, last triggered, next due
  estimate, active/inactive status.
- Rule types: calendar interval, operating hours, kilometers, other usage
  readings, or whichever comes first.
- Each rule belongs to one asset. Category-level, asset-type-level, and
  template rules are excluded.
- Deactivation (not deletion) for retired rules. Inactive rules remain visible
  and can be reactivated.

---

## 7. Settings

**Sidebar:** Tabbed group — visible to Administrator only.

**Route:** `/settings`

Tabs:

### 7a. System & Integration
- **Visible to:** Admin only.
- ERP sync settings (SM-owned parts sync configuration).
- Sync history: list of past sync runs with timestamps and outcomes.
- Manual ERP sync trigger (Admin and Maintenance Manager).
- Company timezone and other system-level configuration.
- Power Automate email integration settings.

### 7b. Activity Logs
- **Visible to:** Admin only.
- Read-only, append-only technical audit trail.
- Filterable by event type, user, and date range.
- Entries cannot be edited or deleted through the application.
- Audit log events include: login/logout, user activation/deactivation, MR
  approval/rejection/cancellation, WO assignment/completion/closure/cancellation,
  asset location changes, meter reading submission/confirmation, PM rule
  changes, manual ERP sync runs, attachment upload/removal, component
  install/remove/swap, and API token operations.
- Passwords, session cookies, API keys, and attachment contents are never
  stored in the audit log.

---

## Screens Without Sidebar Entries

These are accessed through drill-down navigation from the list screens above:

| Screen | Accessed From |
|---|---|
| Asset Detail | All Assets list |
| Usage & Meter Readings | Asset Detail |
| Location History | Asset Detail |
| Maintenance History | Asset Detail |
| Attachments | Asset Detail, Part Detail |
| Review Maintenance Request | Pending Approval / All Requests lists |
| Work Order Detail | Active / Completed / Closed WO lists |
| Part Detail | All Parts list |
| Asset Assembly History | Asset Assembly tab |

---

## Role Visibility Summary

| Section | Requester | Technician | Logistics | Manager | Admin |
|---|---|---|---|---|---|
| Dashboard | ✓ | ✓ | ✓ | ✓ | ✓ |
| Maintenance Requests | ✓ | ✓ | ✓ | ✓ | ✓ |
| Work Orders | — | ✓ | — | ✓ | ✓ |
| Asset Management | — | ✓ | ✓ | ✓ | ✓ |
| Parts Management | — | ✓ | — | ✓ | ✓ |
| Admin | — | — | — | — | ✓ |
| Settings | — | — | — | — | ✓ |
