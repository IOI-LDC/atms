# Frontend Routes

Tab state is driven by `?tab=` query parameters. Routes use Vue Router with
nested child routes where appropriate for drill-down detail views.

---

## Dashboard

```text
/dashboard
```

Direct link. Operational overview.

---

## Maintenance Requests

```text
/maintenance
/maintenance/requests/:requestId
```

| Tab (`?tab=`)       | Visible To       |
|----------------------|------------------|
| `new-request`        | Everyone         |
| `my-requests`        | Everyone         |
| `pending-approval`   | Admin, Manager   |
| `all-requests`       | Admin, Manager   |

`/maintenance/requests/:requestId` — Full-page Review Maintenance Request
screen (drill-down).

---

## Work Orders

```text
/work-orders
/work-orders/:workOrderId
```

| Tab (`?tab=`)        | Visible To                |
|-----------------------|---------------------------|
| `my`                  | Technician only           |
| `all`                 | Admin, Manager            |
| `active`              | Admin, Manager, Technician|
| `completed`           | Admin, Manager, Technician|
| `closed`              | Admin, Manager, Technician|

`/work-orders/:workOrderId` — Full-page Work Order Detail (drill-down).

---

## Asset Management

```text
/assets
/assets/:assetId
/assets/:assetId/readings
/assets/:assetId/location-history
/assets/:assetId/maintenance-history
/assets/:assetId/attachments
/assets/:assetId/assembly
/assets/:assetId/assembly/history
```

| Tab (`?tab=`) | Visible To                         |
|---------------|------------------------------------|
| `all`         | Admin, Manager, Technician, Logistics |
| `assembly`    | Admin, Manager, Technician, Logistics |

Asset detail sub-pages (readings, location history, maintenance history,
attachments, assembly) are implemented as tabs or sections within
`/assets/:assetId` rather than separate full pages.

---

## Parts Management

```text
/parts
/parts/:partId
```

| Tab (`?tab=`)    | Visible To                |
|-------------------|---------------------------|
| `all`             | Admin, Manager, Technician|
| `part-request`    | Admin, Manager, Technician|

`/parts/:partId` — Part Detail (drill-down). The "Part Request" tab is a
cross-subsystem link into the SM (Store Management) subsystem's ordering flow.

---

## Locations

```text
/locations
```

| Tab (`?tab=`)              | Visible To                    |
|-----------------------------|-------------------------------|
| `asset-location-update`     | Admin, Manager, Logistics     |
| `manage-locations`          | Admin only                    |

- **`asset-location-update`** — Search and select an active asset, view its
  current location and location history, and update its physical location.
  Opens `UpdateLocationSheet` (side sheet) per asset row. Calls
  `POST /api/assets/{asset}/location`. See
  `SCREEN_INVENTORY.md` §6a for full workflow spec.
- **`manage-locations`** — CRUD for location definitions (name, type, code,
  description, parent location, active status). Uses existing
  `GET/POST/PATCH /api/admin/locations` endpoints. Admin only.

**Note:** Location history for a specific asset is still accessed via the
existing drill-down route `/assets/:assetId/location-history`. The "Asset
Location Update" tab links to this drill-down from each asset row.

---

## Admin

```text
/admin
```

| Tab (`?tab=`)     | Visible To |
|--------------------|------------|
| `users`            | Admin only |
| `lists`            | Admin only |
| `wo-forms`         | Admin only |
| `pm-rules`         | Admin only |

- **`users`** — Employee directory import, user provisioning, fixed-role
  assignment, activation/deactivation, password resets.
- **`lists`** — Genuinely configurable dropdown vocab only: Maintenance
  Priorities (master data), Usage Reading Types, and FA Subclass Type Codes
  (ERP reference). Asset/WO/sub-statuses are **not** here — they're Enum-backed
  state machines (`OperationalStatus`, `WorkOrderStatus`, `MaintenanceSubStatus`)
  that drive workflow transitions, not configurable vocab; free-text CRUD there
  would break the state machines. Locations live under the dedicated Locations
  sidebar item, not this tab. See
  `.kilo/plans/1783001396791-admin-lists-dropdowns-cleanup.md`.
- **`wo-forms`** — WO Form template management: create, edit, deactivate, and reactivate form templates per FA subclass. Field builder within each template (label, type, unit, has_pre_post, required, sort order). Admin only.
- **`pm-rules`** — PM **template** management (Admin): create/edit/deactivate/
  reactivate reusable maintenance schedule templates. Templates are asset-agnostic;
  they are assigned to assets from the Asset Detail screen (PM Rules section).
  Template creation and editing are side-sheet operations within this tab.
  within this tab, not separate routes.

PM Rules is no longer a top-level route (`/pm-rules`).

---

## Settings

```text
/settings
```

| Tab (`?tab=`)      | Visible To |
|---------------------|------------|
| `system`            | Admin only |
| `audit-logs`        | Admin only |

- **`system`** — ERP sync settings, sync history, company timezone, Microsoft
  Graph email integration, and other system-level configuration.
- **`audit-logs`** — Read-only, append-only technical audit trail. Filterable
  by event (partial match), actor, subject type/id, and date range; cursor-paginated.

---

## Authentication (standalone pages, no sidebar)

```text
/login
/activate
/reset-password
```

These are full-page views outside the app layout. Not sidebar items.
