# Navigation Model

## Design Concept: Flat Sidebar with Internal Tabs

The navigation uses a **flat sidebar + tabbed content area** pattern to reduce
clutter and cognitive load. There are no nested dropdown menus.

- **Sidebar:** A single-level vertical list of primary navigation links. Hover
  states provide visual feedback; a clear active state (background highlight
  or bold text) shows the user's current location.
- **Tabs:** When the user clicks a primary category that contains sub-items,
  the sidebar remains static and the main content area header displays a
  horizontal row of tabs. Each tab filters the content below it.
- **Role-based visibility:** The sidebar dynamically hides items the user's
  role cannot access. Within tabbed groups, individual tabs are also shown or
  hidden based on role.

## Sidebar Structure

Eight primary sidebar items.

| # | Label | Type | Visible To |
|---|---|---|---|
| 1 | **Dashboard** | Direct Link | Everyone |
| 2 | **Maintenance Requests** | Tabbed Group | Everyone |
| 3 | **Work Orders** | Tabbed Group | Admin, Manager, Technician |
| 4 | **Asset Management** | Tabbed Group | Admin, Manager, Technician, Logistics |
| 5 | **Parts Management** | Tabbed Group | Admin, Manager, Technician |
| 6 | **Locations** | Tabbed Group | Admin, Manager, Logistics |
| 7 | **Admin** | Tabbed Group | Admin only |
| 8 | **Settings** | Tabbed Group | Admin only |

## Tab Definitions

### 1. Dashboard

- **Type:** Direct link.
- **Route:** `/dashboard`
- **Content:** Two-column "Maintenance Control Center" layout. Left workspace
  contains six analytical KPIs (MTBF, MTTR, Failure Rate, PM Compliance,
  Avg MR Duration, Avg WO Duration) over a rolling 90-day window, plus data
  cards for pending MRs, open WOs, overdue PM assignments, recently relocated
  assets, and recently closed WOs. Right sidebar contains role-driven quick
  actions (Assets, New MR, Locations, Work Orders) and live operational status
  KPI tiles with click-through links to filtered views.

### 2. Maintenance Requests

- **Type:** Tabbed group.
- **Route:** `/maintenance`
- **Tabs:**

| Tab | Visible To |
|---|---|
| New Request | Everyone |
| My Requests | Everyone |
| Pending Approval | Admin, Manager |
| All Requests | Admin, Manager |

- **"New Request" tab** opens the corrective maintenance request form (side
  sheet). Preventively generated MRs appear automatically in the other tabs.
- **"My Requests" tab** shows requests created by the current user.
- **"Pending Approval" tab** shows all MRs with status `pending_review`.
- **"All Requests" tab** shows every MR regardless of status.

### 3. Work Orders

- **Type:** Tabbed group.
- **Route:** `/work-orders`
- **Tabs:**

| Tab | Visible To |
|---|---|
| My Work Orders | Technician only |
| All Work Orders | Admin, Manager |
| Active | Admin, Manager, Technician |
| Completed | Admin, Manager, Technician |
| Closed | Admin, Manager, Technician |

- **"My Work Orders" tab** shows WOs assigned to the current technician.
- **"All Work Orders" tab** shows every WO regardless of status or assignment.
- **"Active" tab** shows WOs with status `open` or `in_progress`.
- **"Completed" tab** shows WOs with status `completed` (awaiting closure).
- **"Closed" tab** shows WOs with status `closed` (terminal, read-only).

### 4. Asset Management

- **Type:** Tabbed group.
- **Route:** `/assets`
- **Tabs:**

| Tab | Visible To |
|---|---|
| All Assets | Admin, Manager, Technician, Logistics |
| Asset Assembly | Admin, Manager, Technician, Logistics |

- **"All Assets" tab** shows the full asset registry with search, filters,
  status badges, current location, and links to asset detail.
- **"Asset Assembly" tab** shows asset assembly management: package/component
  hierarchy, install/remove/swap operations, assembly history, and component
  PM status indicators.

### 5. Parts Management

- **Type:** Tabbed group.
- **Route:** `/parts`
- **Tabs:**

| Tab | Visible To |
|---|---|
| All Parts | Admin, Manager, Technician |
| Part Request | Admin, Manager, Technician |

- **"All Parts" tab** shows the read-only SM parts catalogue with search,
  filters, ERP reference fields, and links to part detail.
- **"Part Request" tab** allows users to submit a part request into SM's
  order/stock workflow. Used for requesting parts for work orders or general
  maintenance needs.

### 6. Locations

- **Type:** Tabbed group.
- **Route:** `/locations`
- **Tabs:**

| Tab | Visible To |
|---|---|
| Asset Location Update | Admin, Manager, Logistics |
| Manage Locations | Admin only |

- **"Asset Location Update" tab** — Search and select an active asset, view its
  current location and location history, and update the asset's physical
  location. Uses a side-sheet form (`UpdateLocationSheet`) containing: target
  location (select from active location list), effective date, optional reason,
  and optional notes. Submitting creates a location history record via the
  backend `UpdateAssetLocation` Action. No approval chain — a direct update
  per the Phase 1 scope.
- **"Manage Locations" tab** — CRUD for location definitions. Administrator
  can create, edit, activate, and deactivate location records (name, type,
  code, description, parent location for hierarchy). Uses `POST/PATCH
  /api/admin/locations`. Deactivated locations are excluded from the "Asset
  Location Update" location picker but remain in history records.

### 7. Admin

- **Type:** Tabbed group.
- **Route:** `/admin`
- **Visible to:** Administrator only.

> **Known gap (decided, pending implementation):** PM Rules is a tab here, so
> the Manager's template-view permission is currently unreachable in the UI
> (assignment management, however, is reachable via Asset Detail). Agreed
> direction: open the full Admin area to the Maintenance Manager. See
> `docs/03-backend/RBAC.md` (Known gap) for details.
- **Tabs:**

| Tab | Visible To |
|---|---|
| Users & Access | Admin only |
| Lists & Dropdowns | Admin only |
| WO Forms | Admin only |
| PM Rules | Admin only |

- **"Users & Access" tab** — Employee directory import, user provisioning,
  fixed-role assignment, activation/deactivation, password resets.
- **"Lists & Dropdowns" tab** — Manage genuinely-configurable dropdown vocab
  only: Maintenance Priorities (master data), Usage Reading Types, and FA
  Subclass Type Codes (ERP reference). Asset/WO/sub-statuses are Enum-backed
  state machines, not configurable vocab. Locations live under the dedicated
  Locations sidebar item (§6). **Note:** Locations formerly lived
  exclusively here; they now also have a dedicated sidebar item (§6) with a
  "Manage Locations" tab that provides the same CRUD capability in a
  location-focused context.
- **"WO Forms" tab** — Manage Work Order execution form templates per FA subclass (create, edit, deactivate). Field builder: add/edit/reorder fields with label, type (boolean/numeric/text), unit, `has_pre_post` toggle, required toggle, sort order. See [WO_FORMS.md](../01-product/WO_FORMS.md).
- **"PM Rules" tab** — Manage reusable PM **templates** (schedule definitions,
  asset-agnostic). Rule types: calendar interval, operating hours, kilometers, or
  other usage readings. Templates are assigned to assets from the Asset Detail PM
  Rules section (Admin + Manager). Deactivation (not deletion) for retired
  templates; a retired template stops generating PM work but keeps its
  assignments on record.

### 8. Settings

- **Type:** Tabbed group.
- **Route:** `/settings`
- **Visible to:** Administrator only.
- **Tabs:**

| Tab | Visible To |
|---|---|
| System & Integration | Admin only |
| Activity Logs | Admin only |

- **"System & Integration" tab** — ERP sync settings, sync history, company
  timezone, and other system-level configuration.
- **"Activity Logs" tab** — Read-only append-only audit trail. Filterable by
  event type, user, date range. Entries cannot be edited or deleted.

## Sidebar Behavior

- **Collapsible:** Icon-only mode on desktop via the sidebar toggle. Full
  labels when expanded.
- **Mobile:** Slide-in sheet triggered by a hamburger/menu button.
- **Active state:** The active sidebar item is highlighted with a background
  accent. For tabbed groups, the item stays highlighted while the user is on
  any of its tabs.
- **Hover:** Subtle background change on hover for all items.
- **Header bar:** The top bar contains the sidebar toggle (collapses sidebar
  to icon-only mode), the User Manual button (opens the in-app manual), and the
  user menu (avatar initials, name, role label, and email, with a Sign out
  action). The sidebar has no footer — user identity and logout are accessed
  exclusively through the header bar.

## Role Visibility Summary

| Sidebar Item | Requester | Technician | Logistics | Manager | Admin |
|---|---|---|---|---|---|
| Dashboard | ✓ | ✓ | ✓ | ✓ | ✓ |
| Maintenance Requests | ✓ | ✓ | ✓ | ✓ | ✓ |
| Work Orders | — | ✓ | — | ✓ | ✓ |
| Asset Management | — | ✓ | ✓ | ✓ | ✓ |
| Parts Management | — | ✓ | — | ✓ | ✓ |
| **Locations** | — | — | ✓ | ✓ | ✓ |
| Admin | — | — | — | — | ✓ |
| Settings | — | — | — | — | ✓ |

## Implementation Notes

- The sidebar is implemented in `AppSidebar.vue` using shadcn-vue `Sidebar`
  components.
- Tab state is driven by URL query parameters (e.g., `?tab=active`) so that
  deep-linking and browser back/forward work correctly.
- The `AppSidebar.vue` file is the authoritative source for the nav tree and
  role-visibility logic. Any changes to the sidebar structure must be reflected
  there.
- Backend authorization remains authoritative. Hiding sidebar items or tabs
  does not replace server-side policy enforcement.
