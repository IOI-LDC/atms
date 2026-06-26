# ATMS Backend API Reference

> **For the frontend team.** This document covers every endpoint, request body, response shape, role-based access, status codes, and conventions.

---

## Table of Contents

- [Authentication](#authentication)
- [Conventions](#conventions)
- [Roles & Permissions](#roles-and-permissions)
- [Health](#health)
- [Dashboard](#dashboard)
- [Assets](#assets)
- [Parts](#parts)
- [Meter Readings](#meter-readings)
- [Asset Location](#asset-location)
- [Maintenance Requests](#maintenance-requests)
- [Work Orders](#work-orders)
- [PM Rules](#pm-rules)
- [Attachments](#attachments)
- [Admin: Users](#admin-users)
- [Admin: Roles](#admin-roles)
- [Admin: Employees](#admin-employees)
- [Admin: ERP Sync](#admin-erp-sync)
- [Admin: Audit Logs](#admin-audit-logs)
- [Admin: Company Settings](#admin-company-settings)
- [Admin: Locations](#admin-locations)
- [Admin: Master Data](#admin-master-data)
- [Admin: Usage Reading Types](#admin-usage-reading-types)
- [Enums Reference](#enums-reference)
- [Error Responses](#error-responses)

---

## Authentication

ATMS uses **Sanctum SPA cookie authentication**. The frontend and backend must share the same top-level domain. The frontend sends credentials, Laravel sets a session cookie, and subsequent requests include the cookie automatically.

### POST `/api/auth/login`

Log in. Sets a session cookie.

**Auth:** None (public)

**Request Body:**

| Field | Type | Rules |-------|------|-------| `email` | string | required, valid email | `password` | string | required |

**Response `200`:**
```json
{
  "user": {
    "id": 1,
    "name": "Admin",
    "email": "admin@example.com",
    "is_active": true,
    "activated_at": "2026-06-07T12:00:00Z",
    "emp_id": null,
    "employee_id": null,
    "role": { "id": 1, "code": "administrator", "name": "Administrator" }
  }
}
```

**Error `401`:** `{"message": "Invalid credentials."}` or `{"message": "Account is not active."}`
**Error `429`:** `{"message": "Too many login attempts."}` — 5 attempts per minute per IP+email.

---

### POST `/api/auth/logout`

Invalidate the current session.

**Auth:** Required

**Response `204`:** No content.

---

### GET `/api/auth/me`

Get the currently authenticated user.

**Auth:** Required

**Response `200`:** User object (same shape as login response).

**Error `401`:** Unauthenticated.

---

### POST `/api/auth/activate`

Activate a new account using a one-time token (sent via email during provisioning).

**Auth:** None (public)
**Rate limit:** 5 requests per minute.

**Request Body:**

| Field | Type | Rules |-------|------|-------| `token` | string | required | `password` | string | required, confirmed, min 8 chars (Laravel Password defaults) | `password_confirmation` | string | required with `password` |

**Response `200`:** `{"message": "Account activated."}`
**Error `422`:** Validation error (expired/invalid token).

---

### POST `/api/auth/forgot-password`

Request a password reset link.

**Auth:** None (public)
**Rate limit:** 5 requests per minute.

**Request Body:**

| Field | Type | Rules |-------|------|-------| `email` | string | required, valid email |

**Response `200`:** `{"message": "If the email exists, a reset link has been sent."}`

Always returns 200 to prevent email enumeration.

---

### POST `/api/auth/reset-password`

Reset password using a token from the reset email.

**Auth:** None (public)
**Rate limit:** 5 requests per minute.

**Request Body:**

| Field | Type | Rules |-------|------|-------| `token` | string | required | `password` | string | required, confirmed, min 8 chars | `password_confirmation` | string | required with `password` |

**Response `200`:** `{"message": "Password reset successful."}`
**Error `422`:** Invalid/expired token.

---

## Conventions

### Base URL

All endpoints are prefixed with `/api/`.

### Authentication

All endpoints except auth and health require an authenticated session (Sanctum SPA cookie). The frontend must call `GET /sanctum/csrf-cookie` before the first POST to get a CSRF token.

### Pagination

List endpoints use **cursor pagination**. The response includes:

```json
{
  "data": [...],
  "links": {
    "first": "?cursor=...",
    "last": null,
    "prev": null,
    "next": "?cursor=..."
  },
  "meta": {
    "path": "/api/assets",
    "per_page": 25,
    "next_cursor": "eyJpZCI6MjUsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0",
    "prev_cursor": null
  }
}
```

**Query Parameters:**

| Parameter | Type | Default | Max | Description |-----------|------|---------|-----|-------------| `per_page` | int | 25 | 100 | Items per page | `cursor` | string | null | — | Cursor from `meta.next_cursor` or `meta.prev_cursor` |

### Sorting

List endpoints accept a `sort` query parameter in `field:direction` format:

| Parameter | Example | Description |-----------|---------|-------------| `sort` | `created_at:desc` | Sort field and direction (`asc` or `desc`) |

Each endpoint documents its sortable fields.

### Filtering

List endpoints accept filter query parameters. Each endpoint documents its available filters.

### Timestamps

All timestamps are **ISO 8601** strings (e.g., `"2026-06-09T14:30:00Z"`). Date-only fields use `"2026-06-09"` format.

### CSRF

Sanctum requires a CSRF cookie for all POST/PUT/PATCH/DELETE requests. Fetch it with:

```
GET /sanctum/csrf-cookie
```

This sets the `XSRF-TOKEN` cookie. Include it as `X-XSRF-TOKEN` header in subsequent requests (most HTTP clients handle this automatically with `withCredentials: true`).

---

## Roles and Permissions

### Role Codes

| Code | Label | Description |------|-------|-------------| `administrator` | Administrator | Full access to everything | `maintenance_manager` | Maintenance Manager | Manage work orders, MRs, PM rules, assign technicians | `technician` | Technician | Assigned work orders only, upload attachments to WOs | `logistics` | Logistics | View assets/parts (basic fields), no maintenance access | `requester` | Requester | Create/view own maintenance requests only | `viewer` | ~~Viewer~~ (removed — merged into Requester) |

### Role-Based Field Visibility

API responses vary by role. Each endpoint section documents what fields each role sees. Key rules:

- **ERP fields** (`erp_status`, `erp_last_synced_at`): visible to all except Requester
- **ERP raw data** (`erp_raw_data`): Administrator only
- **`is_active`** on assets/parts: Administrator and Maintenance Manager only
- **Inactive records**: Only Administrator and Maintenance Manager see inactive assets/parts in lists
- **Created-by email**: Administrator and Maintenance Manager only
- **Assignee email**: Administrator and Maintenance Manager only

---

## Health

### GET `/api/health/live`

Liveness probe. No auth required.

**Response `200`:**
```json
{ "status": "alive" }
```

### GET `/api/health/ready`

Readiness probe. No auth required. Checks database and attachment storage. Failures are logged to the Laravel log channel for observability.

**Response `200`:**
```json
{ "status": "ready", "database": "ok", "attachments": "ok" }
```

**Response `503`:**
```json
{ "status": "degraded", "database": "ok", "attachments": "unreachable" }
```

---

## Dashboard

### GET `/api/dashboard`

Role-adaptive dashboard with summary counts and widget previews.

**Auth:** Required (any role)

**Widgets by Role:**

| Widget | Admin | Manager | Technician | Requester | Logistics |--------|-------|---------|------------|-----------|--------|-----------| `pending_maintenance_requests` | All | All | — | Own only | All | — | `open_work_orders` | All | All | Assigned only | — | All | — | `overdue_pm_assignments` | All | All | — | — | All | — | `recently_closed_work_orders` | All | All | — | — | All | — |

**Response `200`:**
```json
{
  "summary": {
    "pending_maintenance_requests": 12,
    "open_work_orders": 5,
    "overdue_pm_assignments": 2,
    "recently_closed_work_orders": 8
  },
  "pending_maintenance_requests": [ /* max 5 MaintenanceRequestResource items */ ],
  "open_work_orders": [ /* max 5 WorkOrderResource items */ ],
  "overdue_pm_assignments": [ /* max 5 AssetPmAssignmentResource items */ ],
  "recently_closed_work_orders": [ /* max 5 WorkOrderResource items */ ]
}
```

Only widgets the user can see are included. Widgets are arrays of the same resource shapes documented below.

---

## Assets

### GET `/api/assets`

List assets with cursor pagination, filtering, and sorting.

**Auth:** Required (any role)
**Role scoping:** Non-admin/manager only see active assets.

**Query Parameters:**

| Parameter | Type | Description |-----------|------|-------------| `search` | string | Search by name or ERP asset code | `is_active` | boolean | Filter by active status (admin/manager only) | `operational_status` | string | Filter by operational status | `category` | string | Filter by category | `location_id` | int | Filter by current location ID | `sort` | string | `name`, `erp_asset_code`, `category`, `operational_status`, `created_at` (default: `created_at:desc`) | `per_page` | int | Default 25, max 100 | `cursor` | string | Pagination cursor |

**Response `200`:** Cursor-paginated list of `AssetResource`.

### AssetResource

Fields vary by role:

| Field | Type | Admin | Manager | Technician | Logistics | Requester |-------|------|-------|---------|------------|-----------|-----------|--------| `id` | int | Y | Y | Y | Y | `erp_asset_code` | string | Y | Y | Y | Y | `name` | string | Y | Y | Y | Y | `description` | string? | Y | Y | Y | Y | `category` | string? | Y | Y | Y | Y | `serial_number` | string? | Y | Y | Y | Y | `model` | string? | Y | Y | Y | Y | `manufacturer` | string? | Y | Y | Y | Y | `operational_status` | string? | Y | Y | Y | Y | `current_location` | object? | Y | Y | Y | Y | `current_location.id` | int | Y | Y | Y | Y | `current_location.name` | string | Y | Y | Y | Y | `erp_status` | string? | Y | Y | Y | — | Y | `erp_last_synced_at` | string? | Y | Y | Y | — | Y | `is_active` | bool | Y | — | — | — | — | `erp_raw_data` | object? | Y | — | — | — | — | — | `asset_tag` | string | Y | Y | Y | Y | `parent_asset_id` | int? | Y | Y | Y | Y | `asset_kind` | string | Y | Y | Y | Y | `maintenance_status` | string | Y | Y | Y | Y | `maintenance_sub_status` | string? | Y | Y | Y | Y | `created_at` | string | Y | Y | Y | Y | `updated_at` | string | Y | Y | Y | Y |

---

### GET `/api/assets/{asset}`

Show a single asset.

**Auth:** Required (any role)

**Response `200`:** `AssetResource` (same field rules as above, includes `current_location`).

---

### POST `/api/assets`

Create a new asset manually. Assets are not sourced from ERP for this client.

**Auth:** Required (Administrator or Maintenance Manager)

**Request Body:**

| Field | Type | Rules |-------|------|-------| `name` | string | required, max:255 | `description` | string? | nullable | `category` | string? | nullable, max:255 | `serial_number` | string? | nullable, max:255 | `model` | string? | nullable, max:255 | `manufacturer` | string? | nullable, max:255 | `operational_status` | string? | nullable, in:active,under_maintenance,down,inactive. Default: `active`. | `current_location_id` | int? | nullable, exists:locations,id | `asset_tag` | string? | nullable. Format `L-BBB-CCC-XXXX`. Auto-suggested if not provided; unique, immutable after save. | `asset_kind` | string? | nullable. One of: `asset`, `package`, `component`. Default: `asset`. | `parent_asset_id` | int? | nullable, exists:assets,id. Only allowed if asset_kind is `component` or `package`. | `maintenance_sub_status` | string? | nullable. If parent_asset_id is set, must be `installed`. If NULL, must be `ready` (for component/package) or NULL (for asset). |

**Response `201`:** `{ "data": { /* AssetResource */ } }`

---

### PATCH `/api/assets/{asset}`

Update asset operational fields and location.

**Auth:** Required (Administrator or Maintenance Manager)

**Request Body:**

| Field | Type | Rules |-------|------|-------| `name` | string? | nullable, max:255 | `description` | string? | nullable | `category` | string? | nullable, max:255 | `serial_number` | string? | nullable, max:255 | `model` | string? | nullable, max:255 | `manufacturer` | string? | nullable, max:255 | `operational_status` | string? | nullable, in:active,under_maintenance,down,inactive | `is_active` | boolean? | nullable | `current_location_id` | int? | nullable, exists:locations,id | `location_notes` | string? | nullable. Recorded in location history if location changes. | `asset_kind` | string? | nullable. One of: `asset`, `package`, `component`. | `parent_asset_id` | int? | nullable, exists:assets,id. Admin/Manager only outside WO context. | `maintenance_sub_status` | string? | nullable. Must be consistent with parent_asset_id. |

**Side effect:** If `current_location_id` differs from the current value, a location history record is created automatically (same as the dedicated location endpoint).

**Response `200`:** `{ "data": { /* AssetResource */ } }`

---

### GET `/api/assets/{asset}/meter-readings`

List meter readings for an asset.

**Auth:** Required (any role)

**Response `200`:**
```json
{
  "data": [
    {
      "id": 1,
      "usage_reading_type_id": 1,
      "reading_value": "1500.00",
      "reading_at": "2026-06-09T10:00:00Z",
      "source": "user",
      "entered_by_user_id": 2,
      "confirmed_by_user_id": 1,
      "confirmed_at": "2026-06-09T11:00:00Z",
      "notes": null
    }
  ]
}
```

Ordered by `reading_at` descending.

---

### GET `/api/assets/{asset}/location-history`

List location change history for an asset.

**Auth:** Required (any role)

**Response `200`:**
```json
{
  "data": [
    {
      "id": 1,
      "asset_id": 1,
      "from_location_id": null,
      "to_location_id": 2,
      "effective_at": "2026-06-07T10:00:00Z",
      "reason": "Initial placement",
      "notes": null,
      "changed_by_user_id": 1
    }
  ]
}
```

Ordered by `effective_at` descending.

---

### GET `/api/assets/{asset}/maintenance-history`

List closed work orders for an asset (maintenance history). Cursor-paginated.

**Auth:** Required (any role except Logistics, who gets 403)
**Role scoping:** Requester only sees WOs from their own MRs.

**Query Parameters:**

| Parameter | Type | Description |-----------|------|-------------| `per_page` | int | Default 25, max 100 | `cursor` | string | Pagination cursor |

**Response `200`:** Cursor-paginated list of `MaintenanceHistoryResource`:

```json
{
  "data": [
    {
      "date": "2026-06-07",
      "type": "corrective",
      "work_order_number": "WO-0001",
      "maintenance_request_number": "MR-0001",
      "description": "Fix generator",
      "priority": "high",
      "parts_used": [
        { "part_name": "Air Filter", "quantity": 2.0 }
      ],
      "closed_at": "2026-06-07T14:00:00Z"
    }
  ]
}
```

---

## Parts

### GET `/api/parts`

List parts with cursor pagination, filtering, and sorting.

**Auth:** Required (any role)
**Role scoping:** Non-admin/manager only see active parts.

**Query Parameters:**

| Parameter | Type | Description |-----------|------|-------------| `search` | string | Search by name or ERP part code | `sort` | string | `name`, `erp_part_code` (default: `name:asc`) | `per_page` | int | Default 25, max 100 | `cursor` | string | Pagination cursor |

### PartResource

| Field | Type | Admin | Manager | Other roles |-------|------|-------|---------|-------------| `id` | int | Y | Y | `erp_part_code` | string | Y | Y | `name` | string | Y | Y | `description` | string? | Y | Y | `unit_of_measure` | string? | Y | Y | `category` | string? | Y | Y | `is_active` | bool | Y | Y | `erp_status` | string? | Y | — | `erp_last_synced_at` | string? | Y | — | `erp_raw_data` | object? | Y | — | — | `created_at` | string | Y | Y |

---

### GET `/api/parts/{part}`

Show a single part. Non-admin/manager users receive `403 Forbidden` for inactive parts.

**Auth:** Required (any authenticated user via PartPolicy)

**Response `200`:** `PartResource`.
**Error `403`:** Non-admin/manager attempting to view an inactive part.

---

### PATCH `/api/parts/{part}`

Update local operational fields on a part. ERP-owned fields cannot be changed.

**Auth:** Required (Administrator or Maintenance Manager)

**Request Body:**

| Field | Type | Rules |-------|------|-------| `name` | string? | nullable, max:255 | `description` | string? | nullable | `unit_of_measure` | string? | nullable, max:50 | `category` | string? | nullable, max:255 | `is_active` | boolean? | nullable |

**Blocked fields:** `erp_part_id`, `erp_part_code`, `erp_status`, `erp_raw_data`, `erp_last_synced_at` — returning 422 if any are present.

**Response `200`:** `{ "data": { /* PartResource */ } }`

---

## Meter Readings

### POST `/api/assets/{asset}/meter-readings`

Record a new meter reading.

**Auth:** Required (any role)
**Validation:** Asset must be active. Reading type must be active.

**Request Body:**

| Field | Type | Rules |-------|------|-------| `usage_reading_type_id` | int | required, exists in `usage_reading_types` | `reading_value` | numeric | required | `reading_at` | datetime string | required, valid date | `source` | string | required, one of: `user`, `manual` | `notes` | string? | nullable |

**Response `201`:**
```json
{
  "message": "Meter reading recorded.",
  "data": {
    "id": 1,
    "asset_id": 1,
    "usage_reading_type_id": 1,
    "reading_value": "1500.00",
    "reading_at": "2026-06-09T10:00:00Z",
    "source": "user",
    "entered_by_user_id": 2,
    "confirmed_by_user_id": null,
    "confirmed_at": null,
    "notes": null
  }
}
```

---

### POST `/api/assets/{asset}/meter-readings/{reading}/confirm`

Confirm an unverified meter reading. Idempotent — confirming an already-confirmed reading returns it unchanged.

**Auth:** Required (Maintenance Manager or Administrator)

**Response `200`:**
```json
{
  "message": "Meter reading confirmed.",
  "data": { /* reading with confirmed_by_user_id and confirmed_at set */ }
}
```

**Error `409`:** Domain error (e.g., reading value decreased).

---

## Asset Location

### POST `/api/assets/{asset}/location`

Update an asset's current location. Creates a location history record.

**Auth:** Required (Administrator, Maintenance Manager, or Logistics)
**Validation:** Asset must be active. Target location must be active.

**Request Body:**

| Field | Type | Rules |-------|------|-------| `location_id` | int | required, exists in `locations` | `reason` | string? | nullable | `notes` | string? | nullable |

**Response `200`:**
```json
{
  "message": "Asset location updated.",
  "data": { /* updated asset */ }
}
```


### GET `/api/locations`

List active location definitions. Used by the "Asset Location Update" screen to
populate the location picker dropdown for non-Admin roles (Manager, Logistics).

**Auth:** Required (Administrator, Maintenance Manager, or Logistics)

**Response `200`:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Workshop",
      "type": "workshop",
      "code": "WS",
      "description": "Main workshop facility",
      "is_active": true
    }
  ]
}
```

**Note:** Returns only active locations (`is_active = true`). This is distinct from
`GET /api/admin/locations` which is Admin-only and returns all locations regardless
of active status.

---


## Asset Assembly

### GET `/api/assets/{asset}/children`

List direct child assets for a package. Each child includes a PM status indicator.

**Auth:** Required (any role with view access to the parent)
**Response `200`:** Cursor-paginated list of AssetResource with `pm_status: 'ok' | 'soon' | 'due'`.

### GET `/api/assets/{asset}/assembly-history`

List install/removal history for a component.

**Auth:** Required (any role with view access to the asset)
**Response `200`:** Cursor-paginated assembly history records.

### POST `/api/assets/{asset}/assembly/install`

Install a component into a parent.

**Auth:** Admin, Manager, or assigned Technician on non-terminal WO
**Request Body:**

| Field | Type | Rules |---|---|---| `parent_id` | int | required, exists:assets,id. Parent must have asset_kind `package` or `component`. | `notes` | string? | nullable |

**Response `200`:** `{ "message": "Component installed.", "data": { /* AssetResource */ } }`
**Error `422`:** Component not spare (parent_asset_id must be NULL, Active/Ready).
**Error `422`:** Cycle detected (parent cannot be the component or its descendant).

### POST `/api/assets/{asset}/assembly/remove`

Remove a component from its parent.

**Auth:** Admin, Manager, or assigned Technician on non-terminal WO
**Request Body:**

| Field | Type | Rules |---|---|---| `reason` | string | required | `post_removal_sub_status` | string | required, one of: `ready`, `dbr`, `disposed`, `scrapped` | `notes` | string? | nullable |

**Response `200`:** `{ "message": "Component removed.", "data": { /* AssetResource */ } }`

### POST `/api/assets/{parent}/assembly/swap`

Atomically remove old component and install replacement.

**Auth:** Admin, Manager, or assigned Technician on non-terminal WO
**Request Body:**

| Field | Type | Rules |---|---|---| `old_component_id` | int | required, exists:assets,id. Must be installed in this parent. | `new_component_id` | int | required, exists:assets,id. Must be spare. | `reason` | string | required | `post_removal_sub_status` | string | required, one of: `ready`, `dbr`, `disposed`, `scrapped` | `notes` | string? | nullable |

**Response `200`:** `{ "message": "Component swapped.", "data": { "removed": { /* Asset */ }, "installed": { /* Asset */ } } }`

## Maintenance Requests

### GET `/api/maintenance-requests`

List maintenance requests with cursor pagination.

**Auth:** Required (any role)
**Role scoping:** Requester sees only their own (`created_by = user.id`).

**Query Parameters:**

| Parameter | Type | Description |-----------|------|-------------| `status` | string | `pending_review`, `rejected`, `converted`, `cancelled` | `asset_id` | int | Filter by asset | `priority` | string | `low`, `medium`, `high`, `critical` | `type` | string | `corrective`, `preventive` | `created_by` | int | Filter by creator user ID | `pm_rule_id` | int | Filter by originating PM rule (backs the PM Rule detail MR-history view) | `sort` | string | `created_at`, `priority`, `status` (default: `created_at:desc`) | `per_page` | int | Default 25, max 100 | `cursor` | string | Pagination cursor |

### MaintenanceRequestResource

| Field | Type | Visible to |-------|------|------------| `id` | int | All | `number` | string | All (e.g., `"MR-0001"`) | `type` | string | All (`corrective` or `preventive`) | `status` | string | All (`pending_review`, `rejected`, `converted`, `cancelled`) | `priority` | string | All (`low`, `medium`, `high`, `critical`) | `description` | string? | All | `created_at` | string | All | `asset` | object | All (includes `id`, `name`, `erp_asset_code`) | `created_by` | object? | Admin, Manager, Technician, Requester (own only). Includes `id`, `name`. `email` for Admin/Manager only. | `reviewed_by` | object? | Admin, Manager (includes `id`, `name`) | `rejection_reason` | string? | All except Logistics | `cancellation_reason` | string? | All except Logistics | `is_preventive` | bool? | Admin, Manager | `triggered_by_date` | bool? | Admin, Manager | `triggered_by_reading` | bool? | Admin, Manager | `trigger_date` | string? | Admin, Manager | `trigger_reading_value` | string? | Admin, Manager | `work_order` | object? | Admin, Manager, Technician, Requester (own only). Includes `id`, `number`, `status`. | `has_attachments` | int | Admin, Manager, Technician, Requester (own only). Count of attachments. |

---

### GET `/api/maintenance-requests/{maintenanceRequest}`

Show a single maintenance request with all relations loaded.

**Auth:** Required (view policy applies)

**Response `200`:** `MaintenanceRequestResource` with all visible fields populated.

---

### POST `/api/maintenance-requests/corrective`

Create a corrective maintenance request.

**Auth:** Required (Administrator, Maintenance Manager, Technician, or Requester)

**Request Body:**

| Field | Type | Rules |-------|------|-------| `asset_id` | int | required, exists in `assets` | `description` | string? | nullable | `priority` | string | required, one of: `low`, `medium`, `high`, `critical` | `meter_reading` | object? | nullable, if present must include all 3 fields below | `meter_reading.usage_reading_type_id` | int | required with `meter_reading`, exists in `usage_reading_types` | `meter_reading.reading_value` | numeric | required with `meter_reading` | `meter_reading.reading_at` | datetime | required with `meter_reading` |

**Response `201`:**
```json
{
  "data": {
    "id": 1,
    "number": "MR-0001",
    "type": "corrective",
    "status": "pending_review",
    ...
  }
}
```

---

### POST `/api/maintenance-requests/{maintenanceRequest}/approve`

Approve a pending review MR. Atomically creates a Work Order and sets MR status to `converted`.

**Auth:** Required (Administrator or Maintenance Manager)
**Precondition:** MR must be in `pending_review` status.

**Response `200`:**
```json
{
  "message": "Maintenance request approved and work order created.",
  "data": {
    /* MaintenanceRequest with work_order relation loaded */
  }
}
```

**Error `409`:** MR is not in `pending_review` status.

---

### POST `/api/maintenance-requests/{maintenanceRequest}/reject`

Reject a pending review MR.

**Auth:** Required (Administrator or Maintenance Manager)
**Precondition:** MR must be in `pending_review` status.

**Request Body:**

| Field | Type | Rules |-------|------|-------| `reason` | string | required | `suppressed_until_date` | string? | nullable, date. **Required** if MR is preventive and triggered by date. | `suppressed_until_reading` | numeric? | nullable. **Required** if MR is preventive and triggered by reading. |

**Response `200`:**
```json
{
  "message": "Maintenance request rejected.",
  "data": { /* updated MR */ }
}
```

**Error `409`:** MR is not in `pending_review` status.

---

### POST `/api/maintenance-requests/{maintenanceRequest}/cancel`

Cancel a pending review MR. Requester can cancel their own; Manager/Admin can cancel any.

**Auth:** Required (Requester owns it, or Admin/Manager)
**Precondition:** MR must be in `pending_review` status.

**Request Body:** Same as reject (reason is required, suppression fields required for preventive MRs).

**Response `200`:**
```json
{
  "message": "Maintenance request cancelled.",
  "data": { /* updated MR */ }
}
```

---

### PATCH `/api/maintenance-requests/{maintenanceRequest}`

Update a pending maintenance request. Only the creator or Admin/Manager may update. Corrective MRs only — preventive MRs are system-generated and not editable.

**Auth:** Required (creator of a corrective MR, or Admin/Manager)
**Precondition:** MR must be `pending_review`

**Request Body:**

| Field | Type | Rules |-------|------|-------| `description` | string? | nullable | `priority` | string? | nullable, in:low,medium,high,critical | `asset_id` | int? | nullable, exists:assets,id |

**Response `200`:** `{ "data": { /* MaintenanceRequestResource */ } }`

**Error `409`:** MR is not in `pending_review` status.
**Error `403`:** Unauthorized (not creator, not Admin/Manager, or MR is preventive).

---

## Work Orders

### Work Order Status Lifecycle

```
open → in_progress → completed → closed
open → cancelled
in_progress → cancelled
```

- `open`: Created (from MR approval). Can assign, cancel.
- `in_progress`: Started. Can edit execution details, add/remove parts, complete, cancel.
- `completed`: Technician finished. Manager can close. No more edits by technician.
- `closed`: Terminal. No mutations allowed.
- `cancelled`: Terminal. Requires reason.

---

### GET `/api/work-orders`

List work orders with cursor pagination.

**Auth:** Required (any role)
**Role scoping:** Technician sees only assigned WOs.

**Query Parameters:**

| Parameter | Type | Description |-----------|------|-------------| `status` | string | `open`, `in_progress`, `completed`, `closed`, `cancelled` | `assigned_to` | int | Filter by assigned user ID | `asset_id` | int | Filter by asset | `priority` | string | `low`, `medium`, `high`, `critical` | `from` | datetime | Filter created_at >= | `to` | datetime | Filter created_at <= | `sort` | string | `created_at`, `priority`, `status`, `started_at`, `closed_at` (default: `created_at:desc`) | `per_page` | int | Default 25, max 100 | `cursor` | string | Pagination cursor |

### WorkOrderResource

| Field | Type | Admin | Manager | Technician | Logistics | Requester |-------|------|-------|---------|------------|--------|-----------|-----------| `id` | int | Y | Y | Y | Y | `number` | string | Y | Y | Y | Y | `status` | string | Y | Y | Y | Y | `priority` | string | Y | Y | Y | Y | `description` | string? | Y | Y | Y | Y | `asset` | object | Y | Y | Y | Y | `created_at` | string | Y | Y | Y | Y | `assigned_to` | object? | Y | Y | Y | — | — | `assigned_to.id` | int | Y | Y | Y | — | — | `assigned_to.name` | string | Y | Y | Y | — | — | `assigned_to.email` | string | Y | — | — | — | — | `assigned_by` | object? | Y | — | — | — | — | `parts` | array? | Y | Y | Y | — | — | `started_at` | string? | Y | Y | Y | — | — | `completed_at` | string? | Y | Y | Y | — | — | `completion_notes` | string? | Y | Y | Y | — | — | `closed_at` | string? | Y | Y | Y | — | — | `cancelled_at` | string? | Y | Y | Y | — | — | `cancellation_reason` | string? | Y | Y | Y | — | — | `has_attachments` | int | Y | Y | — | — | — |

**Parts array** (each item is `WorkOrderPartResource`):

```json
{
  "id": 1,
  "part": {
    "id": 1,
    "name": "Air Filter",
    "erp_part_code": "PRT-001",
    "unit_of_measure": "EA"
  },
  "quantity": 2.0,
  "notes": null
}
```

---

### GET `/api/work-orders/{workOrder}`

Show a single work order with all relations loaded (asset, assignedTo, maintenanceRequest, assignedBy, parts.part, attachments).

**Auth:** Required (view policy)

**Response `200`:** `WorkOrderResource` with all visible fields.

---

### PATCH `/api/work-orders/{workOrder}`

Update execution details (description).

**Auth:** Required (assigned Technician, or Manager/Admin on non-terminal WOs)

**Request Body:**

| Field | Type | Rules |-------|------|-------| `description` | string? | nullable |

**Response `200`:**
```json
{ "message": "Work order updated.", "data": { /* WO */ } }
```

**Error `409`:** WO is in a terminal state (completed/closed/cancelled).

---

### POST `/api/work-orders/{workOrder}/assign`

Assign a technician to the work order. Requires WO in `open` status.

**Auth:** Required (Administrator or Maintenance Manager)

**Request Body:**

| Field | Type | Rules |-------|------|-------| `user_id` | int | required, exists in `users`. Must be an active technician. |

**Response `200`:**
```json
{ "message": "Work order assigned.", "data": { /* WO */ } }
```

**Error `409`:** WO is not in `open` status, or user is not an active technician.

---

### POST `/api/work-orders/{workOrder}/start`

Start the work order. Transitions from `open` to `in_progress`. Requires assignment first.

**Auth:** Required (assigned Technician, Administrator, or Maintenance Manager)

**Response `200`:**
```json
{ "message": "Work order started.", "data": { /* WO */ } }
```

**Error `409`:** WO is not `open`, or no technician assigned.

---

### POST `/api/work-orders/{workOrder}/complete`

Mark the work order as completed. Only the assigned technician, a Maintenance Manager, or an Administrator can complete. Double-completing a `completed` WO returns `409`.

**Auth:** Required (assigned Technician, Maintenance Manager, or Administrator)
**Precondition:** WO must be `in_progress`.
**Error `409`:** WO is not in `in_progress` status.
**Error `403`:** Non-assigned technician attempting to complete (when no manager override applies).

**Request Body:**

| Field | Type | Rules |-------|------|-------| `completion_notes` | string? | nullable |

**Response `200`:**
```json
{ "message": "Work order completed.", "data": { /* WO */ } }
```

---

### POST `/api/work-orders/{workOrder}/close`

Close a completed work order. Manager action only. For PM-linked work orders, closing updates the PM rule's `last_triggered_date` (and `last_triggered_reading` for reading-triggered rules) to establish the new baseline.

**Auth:** Required (Administrator or Maintenance Manager)
**Precondition:** WO must be `completed`.

**Response `200`:**
```json
{ "message": "Work order closed.", "data": { /* WO */ } }
```
**Error `409`:** WO is not in `completed` status.

---

### POST `/api/work-orders/{workOrder}/cancel`

Cancel a work order (open or in_progress).

**Auth:** Required (Administrator or Maintenance Manager)

**Request Body:**

| Field | Type | Rules |-------|------|-------| `reason` | string | required |

**Response `200`:**
```json
{ "message": "Work order cancelled.", "data": { /* WO */ } }
```

---

### POST `/api/work-orders/{workOrder}/parts`

Add a part to a non-terminal work order.

**Auth:** Required (assigned Technician, Administrator, or Maintenance Manager)

**Request Body:**

| Field | Type | Rules |-------|------|-------| `part_id` | int | required, exists in `parts` | `quantity` | numeric | required, min 0.01 | `notes` | string? | nullable |

**Response `201`:**
```json
{
  "message": "Part added to work order.",
  "data": { /* WorkOrderPart with part relation loaded */ }
}
```

---

### DELETE `/api/work-orders/{workOrder}/parts/{partLine}`

Remove a part from a non-terminal work order.

**Auth:** Required (assigned Technician, Administrator, or Maintenance Manager)

**Response `200`:**
```json
{ "message": "Part removed from work order." }
```

---

## PM Rules

PM rules follow a **M:N template model**. A **PM Rule** is a reusable schedule template (no `asset_id`, no compliance state). An **Assignment** links a template to a specific asset and carries that asset's own compliance baseline.

**Template lifecycle** (create/edit/deactivate/reactivate) is Administrator-only (`PmRulePolicy`). **Assignment lifecycle** (assign/evaluate/deactivate/reactivate) is Administrator + Maintenance Manager (`AssetPmAssignmentPolicy`). A **retired template** (`is_active = false`) stops all PM evaluation (daily job, calculator, overdue query) for its assignments but does **not** cascade-deactivate the assignments themselves.

### GET `/api/pm-rules`

List PM templates with cursor pagination.

**Auth:** Required (Administrator or Maintenance Manager)

**Query Parameters:**

| Parameter | Type | Description |-----------|------|-------------| `is_active` | boolean | Filter by active status | `trigger_type` | string | `date`, `reading`, `date_or_reading` | `sort` | string | `name`, `created_at`, `is_active` (default: `created_at:desc`) | `per_page` | int | Default 25, max 100 | `cursor` | string | Pagination cursor |

### PmRuleResource (template shape)

| Field | Type | Admin/Manager | Notes |
|-------|------|---------------|-------|
| `id` | int | Y | |
| `name` | string | Y | |
| `maintenance_level` | string? | Y | `L1`-`L4` or custom, nullable |
| `description` | string? | Y | |
| `trigger_type` | string | Y | |
| `is_active` | bool | Y | |
| `interval_days` | int? | Y | |
| `interval_reading` | float? | Y | |
| `assignments_count` | int | Y | active assignment count (`withCount` of active assignments) |
| `usage_reading_type` | object? | Y | id, name, unit (`show` only — eager-loaded) |
| `assignments` | array | Y | `AssetPmAssignmentResource[]` (`show` only — eager-loaded) |
| `created_by` | object? | Y (Admin/Manager only) | id, name |
| `created_at` / `updated_at` | string | Y | |

> The template resource has **no** computed `pm_status`/`next_due_*`/`progress_*`/`last_triggered_*` fields — those live on the assignment (per asset), not the template.

---

### POST `/api/pm-rules`

Create a PM template.

**Auth:** Required (Administrator only)

**Request Body:**

| Field | Type | Rules |
|-------|------|-------|
| `name` | string | required, max 255 |
| `maintenance_level` | string? | nullable, max 10 (`L1`-`L4` or custom free-text) |
| `description` | string? | nullable |
| `trigger_type` | string | required, one of: `date`, `reading`, `date_or_reading` |
| `interval_days` | int? | nullable, min 1. Required if trigger_type is `date` or `date_or_reading`. |
| `interval_reading` | numeric? | nullable, min 0.01. Required if trigger_type is `reading` or `date_or_reading`. |
| `usage_reading_type_id` | int? | nullable, exists in `usage_reading_types`. Required if trigger_type is `reading` or `date_or_reading`. |

**Response `201`:** `{ "data": { /* PmRule */ } }`

---

### GET `/api/pm-rules/{pmRule}`

Show a single template, with its `usage_reading_type`, `created_by`, and `assignments` (coverage view) eager-loaded.

**Auth:** Required (Administrator or Maintenance Manager)

**Response `200`:** `PmRuleResource` (includes `assignments`).

---

### GET `/api/pm-rules/{pmRule}/assignments`

List all assignments for a template (Admin coverage view: "which assets use this template?").

**Auth:** Required (Administrator or Maintenance Manager)

**Response `200`:** `AssetPmAssignmentResource[]`.

---

### PATCH `/api/pm-rules/{pmRule}`

Update a PM template.

**Auth:** Required (Administrator only)

**Request Body:** same optional fields as POST (`name`, `maintenance_level`, `description`, `interval_days`, `interval_reading`). Cannot nullify an interval required by the template's trigger type.

**Response `200`:** `{ "data": { /* PmRule */ } }`

---

### POST `/api/pm-rules/{pmRule}/deactivate`

Deactivate (retire) a template. Blocked (`409`) if **any** active assignment for the template has an active MR/WO chain (`hasAnyActiveChain`). Deactivation sets the template `is_active = false`; it does **not** deactivate the assignments — a retired template simply stops generating PM work at the evaluation layer.

**Auth:** Required (Administrator only)

**Response `200`:** `{ "message": "PM rule deactivated.", "data": { /* PmRule */ } }`
**Error `409`:** An active chain exists on an assignment.

---

### POST `/api/pm-rules/{pmRule}/reactivate`

Reactivate an inactive template.

**Auth:** Required (Administrator only)

**Response `200`:** `{ "message": "PM rule reactivated.", "data": { /* PmRule */ } }`
**Error `409`:** Template is already active.

---

### POST `/api/pm-rules/evaluate-all`

Manually evaluate all active assignments (whose template is also active).

**Auth:** Required (Administrator or Maintenance Manager)

**Response `200`:**
```json
{ "evaluated": 5, "generated": 2 }
```

---

## PM Assignments

Assignments are managed per-asset under `/api/assets/{asset}/pm-assignments`. `{assignment}` is scoped to the asset in the URL — cross-asset access returns `404`.

### AssetPmAssignmentResource

| Field | Type | Notes |
|-------|------|-------|
| `id` | int | |
| `asset_id` | int | |
| `pm_rule_id` | int | the template id |
| `is_active` | bool | |
| `last_triggered_date` | string? | this asset's date baseline |
| `last_triggered_reading` | float? | this asset's reading baseline |
| `next_due_date` | string? | computed: `last_triggered_date + interval_days`; null if not date-based or no baseline |
| `next_due_reading` | float? | computed: `last_triggered_reading + interval_reading`; null if not reading-based or no baseline |
| `progress_percentage` | float? | 0-100, max of date/reading progress; null if no baseline or no confirmed reading |
| `pm_status` | string | `ok` < 60%, `soon` 60-80%, `due` >= 80% or assignment is due per calculator |
| `rule` | object | nested template: id, name, maintenance_level, trigger_type, interval_days, interval_reading, usage_reading_type |
| `assigned_by` | object | id, name |
| `assigned_at` | string | pivot `created_at` |
| `suppressions` | array | `show` only — eager-loaded; each item carries `decision_type`, `triggered_by_date`/`triggered_by_reading`, `trigger_date`, `trigger_reading_value`, `trigger_reading_type_id`, `suppressed_until_date`, `suppressed_until_reading`, `source_mr_id` |

---

### GET `/api/assets/{asset}/pm-assignments`

List an asset's assignments.

**Auth:** Required (Administrator or Maintenance Manager)

**Query Parameters:** `is_active` (boolean; default `1` — pass `0` to list deactivated assignments so they remain reachable for reactivation).

**Response `200`:** `AssetPmAssignmentResource[]`.

---

### POST `/api/assets/{asset}/pm-assignments`

Assign a template to an asset. The template must be active and not already assigned to this asset (unique `asset_id`+`pm_rule_id`). On create, the assignment's initial baseline is seeded: `last_triggered_date = today`, and `last_triggered_reading` = the asset's latest confirmed reading for the template's reading type (if any) — giving one full grace interval before the first PM is due.

**Auth:** Required (Administrator or Maintenance Manager)

**Request Body:**

| Field | Type | Rules |
|-------|------|-------|
| `pm_rule_id` | int | required, exists in `pm_rules`, template must be active |

**Response `201`:** `AssetPmAssignmentResource` (includes the seeded baselines).
**Error `422`:** Template is inactive.
**Error `409`:** Template already assigned to this asset.

---

### GET `/api/assets/{asset}/pm-assignments/{assignment}`

Show a single assignment (includes `suppressions`).

**Auth:** Required (Administrator or Maintenance Manager)

**Response `200`:** `AssetPmAssignmentResource`.

---

### POST `/api/assets/{asset}/pm-assignments/{assignment}/deactivate`

Deactivate an assignment. Blocked (`409`) if it has an active MR/WO chain. On success, clears any still-effective suppression windows (date and reading) for this asset/template pair so a later reactivation is not blocked by pre-deactivation windows.

**Auth:** Required (Administrator or Maintenance Manager)

**Response `200`:** `{ "message": "PM assignment deactivated.", "data": { /* assignment */ } }`
**Error `409`:** Active chain exists, or assignment already inactive.

---

### POST `/api/assets/{asset}/pm-assignments/{assignment}/reactivate`

Reactivate an inactive assignment.

**Auth:** Required (Administrator or Maintenance Manager)

**Response `200`:** `{ "message": "PM assignment reactivated.", "data": { /* assignment */ } }`
**Error `409`:** Assignment is already active.

---

### POST `/api/assets/{asset}/pm-assignments/{assignment}/evaluate`

Evaluate a single assignment. If due, generates a preventive Maintenance Request. For reading-triggered assignments, the MR includes `trigger_reading_value` (latest confirmed reading) and `trigger_reading_type_id`; for date-triggered, the MR includes `trigger_date`. The MR records the originating **template** id in `pm_rule_id` plus the `asset_id`.

**Auth:** Required (Administrator or Maintenance Manager)

**Response `200`:** `{ "message": "PM assignment is not due." }`
**Response `201`:** `{ "message": "PM request generated.", "data": { /* MaintenanceRequest */ } }`
**Error `409`:** Assignment/template inactive, or an active chain already exists.

---

## Attachments

### Allowed File Types

| Extension | MIME Type |-----------|-----------| `pdf` | `application/pdf` | `jpg`, `jpeg` | `image/jpeg` | `png` | `image/png` | `gif` | `image/gif` | `webp` | `image/webp` | `doc` | `application/msword` | `docx` | `application/vnd.openxmlformats-officedocument.wordprocessingml.document` | `xls` | `application/vnd.ms-excel` | `xlsx` | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` |

**Max size:** 20 MB
**MIME validation:** Server detects actual MIME type using `finfo` and rejects mismatches.

### Upload Endpoints

All follow the same pattern. Replace `{parent}` with `assets`, `parts`, `maintenance-requests`, or `work-orders`.

**POST `/api/{parent}/{id}/attachments`**

**Auth:** Varies by parent (see Access Control section below)
**Content-Type:** `multipart/form-data`

**Request Body:**

| Field | Type | Rules |-------|------|-------| `file` | file | required, max 20 MB, allowed extensions/MIME types only | `description` | string? | nullable |

**Response `201`:**
```json
{ "data": { /* AttachmentResource */ } }
```

**Error `422`:** File type not allowed, MIME mismatch, or file too large.

### Upload Access Control

| Parent | Can Upload |--------|-----------| Asset | Admin, Manager, Technician, Logistics | Part | Admin, Manager, Technician, Logistics | Maintenance Request | Requester (own only), Admin, Manager | Work Order | Assigned Technician, Admin, Manager |

### List Endpoints

**GET `/api/{parent}/{id}/attachments`**

**Auth:** Varies by parent.

| Parent | Can List |--------|----------| Asset | Admin, Manager, Technician | Part | Admin, Manager, Technician, Logistics | Maintenance Request | Requester (own only), Admin, Manager, Technician | Work Order | Admin, Manager, Technician |

### AttachmentResource

| Field | Type | Admin/Manager | Technician/Logistics/Requester |-------|------|---------------|-------------------------------| `id` | int | Y | Y | Y | `file_name` | string | Y | Y | Y | `mime_type` | string | Y | Y | Y | `size_bytes` | int | Y | Y | Y | `description` | string? | Y | Y | Y | `created_at` | string | Y | Y | Y | `download_url` | string | Y | Y | — | `uploaded_by` | object? | Y (id, name) | — | — |

---

### GET `/api/attachments/{attachment}/download`

Download an attachment as a binary stream.

**Auth:** Required (download policy applies — same as list access for the parent entity)
**Response `200`:** Binary stream with `Content-Disposition: attachment; filename="original_name.ext"`.
**Error `404`:** Attachment was soft-deleted or doesn't exist.

**Note:** Use a regular `GET` request (not JSON). The response is a file download, not JSON.

---

### DELETE `/api/attachments/{attachment}`

Soft-delete an attachment. File remains on disk; attachment is hidden.

**Auth:** Required (Administrator or Maintenance Manager)
**Error `404`:** Already deleted.
**Error `409`:** Domain error.

**Response `200`:** `{ "message": "Attachment deleted." }`

---

## Admin: Users

### GET `/api/admin/users`

List all users with their role.

**Auth:** Administrator only

**Response `200`:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Admin",
      "email": "admin@example.com",
      "is_active": true,
      "activated_at": "2026-06-07T12:00:00Z",
      "emp_id": null,
      "employee_id": null,
      "role": { "id": 1, "code": "administrator", "name": "Administrator" }
    }
  ]
}
```

### GET `/api/admin/users/{user}`

Show a single user with role.

**Auth:** Administrator only

### POST `/api/admin/users/{user}/deactivate`

Deactivate a user. Atomically (within a DB transaction) sets `is_active=false`, deletes all sessions, and revokes all API tokens. Cannot deactivate self.

**Auth:** Administrator only

**Response `200`:** `{ "message": "User deactivated.", "data": { /* user */ } }`
**Error `422`:** Trying to deactivate yourself.

### POST `/api/admin/users/{user}/reactivate`

Reactivate a previously deactivated user.

**Auth:** Administrator only

**Response `200`:** `{ "message": "User reactivated.", "data": { /* user */ } }`

### PATCH `/api/admin/users/{user}`

Update a user's details. Admin only. Cannot update own account through this endpoint.

**Auth:** Administrator only

**Request Body:**

| Field | Type | Rules |-------|------|-------| `name` | string? | nullable, max:255 | `email` | string? | nullable, email, unique:users,email,{id} | `role_id` | int? | nullable, exists:roles,id | `is_active` | boolean? | nullable |

**Response `200`:** `{ "data": { /* User object with role */ } }`
**Error `422`:** Attempting to update yourself, or validation failure.

---

### POST `/api/admin/users/{user}/reset-password`

Admin-initiated password reset. Destroys all sessions and tokens for the user in the same transaction. Admin cannot reset their own password.

**Auth:** Administrator only

**Request Body:**

| Field | Type | Rules |-------|------|-------| `password` | string | required, min:8, confirmed | `password_confirmation` | string | required with `password` |

**Response `200`:** `{ "message": "Password reset successful." }`
**Error `422`:** Attempting to reset your own password.

---

## Admin: Roles

### GET `/api/admin/roles`

List all roles. Roles are immutable (fixed set).

**Auth:** Administrator only

**Response `200`:**
```json
{
  "data": [
    { "id": 1, "code": "administrator", "name": "Administrator" },
    { "id": 2, "code": "maintenance_manager", "name": "Maintenance Manager" },
    { "id": 3, "code": "technician", "name": "Technician" },
    { "id": 4, "code": "logistics", "name": "Logistics" },
    { "id": 5, "code": "requester", "name": "Requester" },
    // { "id": 6, "code": "viewer", "name": "Viewer" } — removed in Phase 1
  ]
}
```

---

## Admin: Employees

### GET `/api/admin/employees`

List employees from the SharePoint-synced directory. Cursor-paginated.

**Auth:** Administrator only

**Query Parameters:**

| Parameter | Type | Description |-----------|------|-------------| `search` | string | Search by name or emp_id | `sort` | string | `name`, `emp_id` (default: `name:asc`) | `per_page` | int | Default 25, max 100 | `cursor` | string | Pagination cursor |

**Response `200`:** Cursor-paginated employee list.

### POST `/api/admin/employees/import`

Trigger employee import from SharePoint.

**Auth:** Administrator only

**Response `200`:** `{ "message": "Imported 42 employees." }`

### POST `/api/admin/employees/{employee}/provision-user`

Provision a user account for an employee. Sends an activation email.

**Auth:** Administrator only

**Request Body:**

| Field | Type | Rules |-------|------|-------| `role_id` | int | required, exists in `roles` |

**Response `200`:**
```json
{
  "message": "User provisioned and activation email queued.",
  "data": { /* new User */ }
}
```

**Error `409`:** Employee already has a user account.

---

## Admin: ERP Sync

### GET `/api/admin/erp/sync-jobs`

List ERP sync job history.

**Auth:** Administrator or Maintenance Manager

**Response `200`:**
```json
{
  "data": [
    {
      "id": 1,
      "sync_type": "assets",
      "status": "success",
      "total_records": 42,
      "created_count": 10,
      "updated_count": 30,
      "failed_count": 2,
      "error_message": null,
      "started_at": "2026-06-09T12:00:00Z",
      "completed_at": "2026-06-09T12:00:05Z"
    }
  ]
}
```

**ErpSyncJob statuses:** `running`, `success`, `partial`, `failed`.

### POST `/api/admin/erp/sync-assets`

Dispatch background job to sync assets from ERP. The job handles top-level ERP failures (e.g., HTTP 500) by marking the job as `failed` with an error message, and row-level errors by marking the job as `partial`. Empty results (0 records) produce a `success` status.

**Auth:** Administrator or Maintenance Manager

**Response `200`:** `{ "message": "ERP Asset synchronization started." }`

### POST `/api/admin/erp/sync-parts`

Dispatch background job to sync parts from ERP.

**Auth:** Administrator or Maintenance Manager

**Response `200`:** `{ "message": "ERP Part synchronization started." }`

---

## Admin: Audit Logs

### GET `/api/admin/audit-logs`

List audit log entries. Cursor-paginated (50 per page).

**Auth:** Administrator only

**Query Parameters:**

| Parameter | Type | Description |-----------|------|-------------| `event` | string | Filter by event name (e.g., `auth.login`, `attachment.uploaded`) | `user_id` | int | Filter by actor user ID | `subject_type` | string | Filter by subject type (e.g., `Asset`, `WorkOrder`) | `subject_id` | int | Filter by subject ID |

**Response `200`:** Cursor-paginated list with `actor` relation loaded.

---

## Admin: Company Settings

### GET `/api/admin/company-settings`

Get company settings.

**Auth:** Administrator only

**Response `200`:**
```json
{ "timezone": "Africa/Tripoli" }
```

### PATCH `/api/admin/company-settings`

Update company settings.

**Auth:** Administrator only

**Request Body:**

| Field | Type | Rules |-------|------|-------| `timezone` | string | required, valid PHP timezone identifier |

**Response `200`:** `{ "timezone": "Africa/Tripoli" }`

---

## Admin: Locations

### GET `/api/admin/locations`

List all locations.

**Auth:** Administrator only

**Response `200`:**
```json
{
  "data": [
    {
      "id": 1,
      "parent_id": null,
      "name": "Main Building",
      "type": "building",
      "code": "BLD-01",
      "description": null,
      "is_active": true
    }
  ]
}
```

### POST `/api/admin/locations`

Create a location.

**Auth:** Administrator only

**Request Body:**

| Field | Type | Rules |-------|------|-------| `parent_id` | int? | nullable, exists in `locations` | `name` | string | required | `type` | string | required | `code` | string? | nullable | `description` | string? | nullable | `is_active` | bool? | defaults to true |

**Response `201`:** `{ "data": { /* Location */ } }`

### PATCH `/api/admin/locations/{location}`

Update a location.

**Auth:** Administrator only

**Request Body:** Same fields as create, all optional.

**Response `200`:** `{ "data": { /* Location */ } }`

---

## Admin: Master Data

Master data items are generic key-value pairs grouped by a `group_key`. Used for dropdowns like categories, priorities, etc.

### GET `/api/admin/master-data/{groupKey}`

List master data items for a group.

**Auth:** Administrator only

**Response `200`:**
```json
{
  "data": [
    { "id": 1, "group_key": "asset_categories", "value": "electrical", "label": "Electrical", "sort_order": 1, "is_active": true }
  ]
}
```

### POST `/api/admin/master-data/{groupKey}`

Create a master data item.

**Auth:** Administrator only

**Request Body:**

| Field | Type | Rules |-------|------|-------| `value` | string | required | `label` | string | required | `sort_order` | int? | nullable | `is_active` | bool? | defaults to true |

**Response `201`:** `{ "data": { /* MasterDataItem */ } }`

### PATCH `/api/admin/master-data/items/{item}`

Update a master data item.

**Auth:** Administrator only

**Request Body:** Same as create, all optional.

---

## Admin: Usage Reading Types

### GET `/api/admin/usage-reading-types`

List all usage reading types (e.g., "Hours", "Kilometers").

**Auth:** Administrator only

**Response `200`:**
```json
{
  "data": [
    { "id": 1, "name": "Hours", "unit": "h", "is_active": true }
  ]
}
```

### POST `/api/admin/usage-reading-types`

Create a reading type.

**Auth:** Administrator only

**Request Body:**

| Field | Type | Rules |-------|------|-------| `name` | string | required | `unit` | string | required | `is_active` | bool? | defaults to true |

**Response `201`:** `{ "data": { /* UsageReadingType */ } }`

### PATCH `/api/admin/usage-reading-types/{type}`

Update a reading type.

**Auth:** Administrator only

**Request Body:** Same as create, all optional.

---

## Enums Reference

### MaintenanceRequestStatus

| Value | Description |-------|-------------| `pending_review` | Awaiting manager action | `rejected` | Rejected by manager (terminal) | `converted` | Approved and converted to work order (terminal) | `cancelled` | Cancelled by requester or manager (terminal) |

### WorkOrderStatus

| Value | Description |-------|-------------| `open` | Created, awaiting assignment | `in_progress` | Technician working | `completed` | Technician finished, awaiting manager review | `closed` | Manager verified (terminal) | `cancelled` | Cancelled (terminal) |

### PmTriggerType

| Value | Description |-------|-------------| `date` | Triggers based on calendar interval | `reading` | Triggers based on meter reading interval | `date_or_reading` | Triggers when either interval is met |

### Priority Values

Used in maintenance requests and work orders: `low`, `medium`, `high`, `critical`.

### Meter Reading Source Values

`user`, `manual`

### AssetKind

| Value | Description |---|---| `asset` | Leaf asset. Cannot have children or a parent. | `package` | Can contain child assets. Can also be a component of another package. | `component` | Can be installed in a parent. Cannot have children. |

### AssetMaintenanceStatus

| Value | Description |---|---| `active` | Asset in operational use. PM/CM/WO active. | `inactive` | Asset not in service. All maintenance flows blocked. |

### AssetMaintenanceSubStatus (Active)

| Value | Applies to | Description |---|---|---| *(none)* | `asset` | Default for standalone assets | `installed` | `component`, `package` | Installed in a parent | `ready` | `component`, `package` | Spare, available |

### AssetMaintenanceSubStatus (Inactive)

| Value | Description |---|---| `lih` | Lost in Hole | `dbr` | Damaged Beyond Repair | `disposed` | Formally disposed | `scrapped` | Sold for scrap / removed | `other` | Other reason (free-text note) |

---

## Error Responses

### Validation Error (422)

```json
{
  "message": "The asset id field is required.",
  "errors": {
    "asset_id": ["The asset id field is required."]
  }
}
```

### Authentication Error (401)

```json
{ "message": "Unauthenticated." }
```

### Authorization Error (403)

```json
{ "message": "This action is unauthorized." }
```

### Not Found (404)

```json
{ "message": "No query results for model [App\\Models\\Asset]." }
```

### Conflict (409)

Returned when a domain precondition fails (e.g., approving an already-approved MR, starting a WO with no assignee).

```json
{ "message": "Maintenance request is not in pending_review status." }
```

### Rate Limited (429)

```json
{ "message": "Too many login attempts." }
```

### Domain Validation (422)

Returned by attachment upload and some actions:

```json
{ "message": "File content does not match any allowed MIME type." }
```

```json
{ "message": "File exceeds the maximum allowed size of 20 MB." }
```

```json
{ "message": "File extension is not allowed." }
```

```json
{ "message": "PM rules can only target ATMS-managed assets." }
```

```json
{ "message": "Cannot update location for an inactive asset." }
```

```json
{ "message": "Cannot assign an inactive location." }
```

```json
{ "message": "Cannot record readings for an inactive asset." }
```

```json
{ "message": "Cannot use an inactive reading type." }
```
