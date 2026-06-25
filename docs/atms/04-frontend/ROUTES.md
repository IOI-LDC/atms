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

## Admin

```text
/admin
```

| Tab (`?tab=`)     | Visible To |
|--------------------|------------|
| `users`            | Admin only |
| `lists`            | Admin only |
| `pm-rules`         | Admin only |

- **`users`** — Employee directory import, user provisioning, fixed-role
  assignment, activation/deactivation, password resets.
- **`lists`** — All configurable dropdown values including locations, asset
  statuses, maintenance priorities, usage reading types, work order statuses,
  asset maintenance sub-statuses, and other master-data items.
- **`pm-rules`** — Preventive maintenance rule configuration per individual
  asset. PM rule creation and editing are side-sheet or inline operations
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

- **`system`** — ERP sync settings, sync history, company timezone, Power
  Automate email integration, and other system-level configuration.
- **`audit-logs`** — Read-only, append-only technical audit trail. Filterable
  by event type, user, and date range.

---

## Authentication (standalone pages, no sidebar)

```text
/login
/activate
/reset-password
```

These are full-page views outside the app layout. Not sidebar items.
