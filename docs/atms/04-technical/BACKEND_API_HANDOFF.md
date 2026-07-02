# ATMS Backend → Frontend API Handoff

> **Purpose:** The single document the frontend team needs to integrate with the
> backend. It covers the environment, authentication lifecycle, conventions,
> TypeScript types, end-to-end workflow sequences, and integration patterns.
>
> **You do not need to open the `backend/` folder or read any PHP.**
>
> For exhaustive per-endpoint detail (every field's per-role visibility, exact
> validation messages, etc.), see the companion
> [`BACKEND_API_REFERENCE.md`](./BACKEND_API_REFERENCE.md). This handoff
> summarises what you need day-to-day; the reference is the deep-dive.

**Status:** Verified against the implemented backend (Laravel 13 / PHP 8.4 /
PostgreSQL 17) as of the documented sprint. If the backend and this document ever
disagree, the backend wins — please report the discrepancy.

---

## Table of Contents

- [1. Getting Started](#1-getting-started)
- [2. Authentication Lifecycle](#2-authentication-lifecycle)
- [3. Global Conventions](#3-global-conventions)
- [4. Roles, Statuses & Enums](#4-roles-statuses--enums)
- [5. TypeScript Type Definitions](#5-typescript-type-definitions)
- [6. Workflow Sequences](#6-workflow-sequences)
- [7. Frontend Integration Patterns](#7-frontend-integration-patterns)
- [8. Endpoint Quick Reference](#8-endpoint-quick-reference)
- [9. Important Implementation Notes](#9-important-implementation-notes)

---

## 1. Getting Started

### Stack & Runtime

| Concern | Value |
|---|---|
| Backend | Laravel 13 API, PHP 8.4, PostgreSQL 17 |
| Auth | Laravel Sanctum **SPA cookie/session** (no bearer tokens) |
| API prefix | `/api/` |
| Background jobs | Laravel Queue (database driver), run in the `queue` container |
| Scheduler | `scheduler` container runs `php artisan schedule:work` |
| Attachments | Local disk on a persistent Docker volume |

### Local Environment & Base URL

In local development the frontend dev server (Vite, `http://localhost:5173`)
proxies `/api` and `/sanctum` to the backend nginx container
(`http://localhost:80`).

```
Browser ──▶ Vite (5173) ──proxy /api, /sanctum──▶ nginx (80)
                                                      │
                                                      ▼
                                              Laravel PHP-FPM (api) ──▶ PostgreSQL (5432)
```

Run the stack:

```bash
docker compose up -d                              # api, nginx, postgres, queue, scheduler
docker compose exec api php artisan migrate --seed # (re)initialize the database
```

The frontend `VITE_API_BASE_URL` should resolve to `/api` (proxied in dev, same
origin in production).

### How Auth Works (the 30-second version)

ATMS uses **cookie/session authentication**, not bearer tokens.

1. Before the first mutating request, call `GET /sanctum/csrf-cookie`.
2. Call `POST /api/auth/login` with `withCredentials: true`.
3. Laravel sets a session cookie. The browser sends it automatically thereafter.
4. Call `GET /api/auth/me` to hydrate the current user on app load.
5. On `401`, redirect to `/login`.

Full details and code in [§2](#2-authentication-lifecycle).

---

## 2. Authentication Lifecycle

### 2.1 The CSRF → Login sequence

Sanctum requires a CSRF cookie before any `POST`/`PATCH`/`DELETE`. The standard
flow on first login:

```
1. GET  /sanctum/csrf-cookie        → 204, sets XSRF-TOKEN cookie
2. POST /api/auth/login             → 200, sets session cookie, returns { user }
3. GET  /api/auth/me                → 200, { user }  (on app reload)
```

### 2.2 axios setup (reference)

```ts
import axios from 'axios'

export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,           // send cookies
  withXSRFToken: true,             // axios auto-sends X-XSRF-TOKEN from the XSRF-TOKEN cookie
  headers: { Accept: 'application/json' },
})

// Bootstrap CSRF once before the first mutation
export async function ensureCsrfCookie(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

// Centralised 401 → redirect to /login
api.interceptors.response.use(
  (res) => res,
  (error) => {
    if (error.response?.status === 401) {
      window.location.href = '/login'
    }
    return Promise.reject(error)
  },
)
```

### 2.3 Login

```
POST /api/auth/login
```

**Auth:** Public.
**Rate limit:** 5 attempts per minute per IP + email; 6th attempt returns `429`.

| Field | Type | Rules |
|---|---|---|
| `email` | string | required, valid email |
| `password` | string | required |

**`200`** — `{ "user": User }` (see [User type](#user)). Sets the session cookie.

**`401`** — `{"message":"Invalid credentials."}` or `{"message":"Account is not active."}`
**`429`** — `{"message":"Too many login attempts."}`

### 2.4 Current user (hydrate on load)

```
GET /api/auth/me   → { "user": User }
```

Call this once on app bootstrap (after the router's auth guard) to populate the
auth store. Returns `401` if the session has expired.

### 2.5 Logout

```
POST /api/auth/logout   → 204 No Content
```

Invalidates the session server-side. Clear local auth state on `204`.

### 2.6 Account activation (first-time password set)

New users are provisioned by an Administrator and receive a one-time activation
link by email. The token is consumed by:

```
POST /api/auth/activate     (throttle: 5/min)
```

| Field | Type | Rules |
|---|---|---|
| `token` | string | required |
| `password` | string | required, confirmed, min 8 |
| `password_confirmation` | string | required |

**`200`** — `{"message":"Account activated."}`
**`422`** — expired/invalid token.

### 2.7 Forgot / reset password

```
POST /api/auth/forgot-password     (throttle: 5/min)
POST /api/auth/reset-password      (throttle: 5/min)
```

`forgot-password` **always returns `200`** with the same message, regardless of
whether the email exists — this prevents email enumeration. The reset email is
queued (visible in the fake transport during dev).

`reset-password` body: `token`, `password`, `password_confirmation`.

---

## 3. Global Conventions

### 3.1 Request format

- All request bodies are JSON (`Content-Type: application/json`), except
  **attachment uploads**, which are `multipart/form-data`.
- All responses are JSON.
- Path parameters are `{id}` integers.

### 3.2 Pagination (cursor-based)

Every list endpoint returns **cursor pagination** — never offset/page numbers.

```jsonc
{
  "data": [ /* items */ ],
  "links": { "first": "?cursor=…", "last": null, "prev": null, "next": "?cursor=…" },
  "meta": {
    "path": "/api/assets",
    "per_page": 25,
    "next_cursor": "eyJpZCI6MjUs…",
    "prev_cursor": null
  }
}
```

| Query param | Type | Default | Max | Notes |
|---|---|---|---|---|
| `per_page` | int | 25 | 100 | Capped server-side |
| `cursor` | string | null | — | Pass `meta.next_cursor` to load the next page |

**"Load more" / infinite scroll:** store `meta.next_cursor`; when non-null, the
next request is `?cursor=<next_cursor>`. When `next_cursor` is `null`, you've
reached the end.

### 3.3 Sorting

List endpoints accept `sort=field:direction`.

```
GET /api/assets?sort=name:asc
GET /api/work-orders?sort=created_at:desc      ← default
```

Direction is `asc` or `desc` (invalid → `desc`). Each endpoint documents its
allowed sort fields (see [§8](#8-endpoint-quick-reference)).

### 3.4 Filtering

Filters are plain query params (e.g. `?status=open&priority=high`). They combine
with AND logic. Each endpoint documents its available filters.

### 3.5 Timestamps

- All timestamps are **ISO 8601 with `Z`** (UTC): `"2026-06-09T14:30:00Z"`.
- Date-only fields are `"2026-06-09"` (e.g. `trigger_date`, `last_triggered_date`).
- The company display timezone is configurable (`Africa/Tripoli` default); format
  for display client-side, but always send/store UTC.

### 3.6 Role-based field visibility

**Responses change shape depending on the caller's role.** The same endpoint
returns more fields to an Administrator than to a Requester. Key global rules:

| Data | Visible to |
|---|---|
| ERP status / `erp_last_synced_at` (asset & part) | All **except Requester** |
| ERP raw payload (`erp_raw_data`) | Administrator only |
| `is_active` on assets | Administrator & Manager only |
| Inactive assets/parts in lists | Administrator & Manager only |
| User `email` in `created_by` / `assigned_to` | Administrator & Manager only |
| `download_url` on attachments | All |
| Asset maintenance history | All **except Logistics** |

Per-resource visibility matrices are in the
[API reference](./BACKEND_API_REFERENCE.md). Build your TS types as the **union**
of all possible fields and treat extras as optional.

### 3.7 Error responses

Every error is JSON with at least `{ "message": "…" }`. Status-code semantics:

| Status | Meaning | Body | Frontend handling |
|---|---|---|---|
| `400` | Bad request (rare) | `{message}` | Toast error |
| `401` | Unauthenticated | `{message:"Unauthenticated."}` | Redirect to `/login` |
| `403` | Unauthorized (policy denies) | `{message}` | Show "not allowed" / hide UI |
| `404` | Not found | `{message}` | Show empty/404 state |
| `409` | Domain conflict | `{message}` | Show inline (e.g. "MR is not pending_review") |
| `422` | Validation failed | `{message, errors:{field:[…]}}` | Map `errors` to fields |
| `429` | Rate limited | `{message}` | Show "try again later" + backoff |
| `5xx` | Server error | `{message}` | Toast + retry option |

**Validation error (422) shape:**

```json
{
  "message": "The asset id field is required.",
  "errors": {
    "asset_id": ["The asset id field is required."]
  }
}
```

`errors` is an object keyed by field name → array of messages. Bind these to your
form fields.

**Conflict (409) shape:** `{ "message": "Maintenance request is not in pending_review status." }`
— a single human-readable reason. Show it inline near the action that failed.

---

## 4. Roles, Statuses & Enums

### 4.1 The five roles

| Code | Label | One-line scope |
|---|---|---|
| `administrator` | Administrator | Everything, plus all admin screens |
| `maintenance_manager` | Maintenance Manager | Review/approve MRs, manage WOs, assign/evaluate PM templates on assets, trigger ERP sync |
| `technician` | Technician | Assigned WOs only; create CM MRs; add readings |
| `logistics` | Logistics | Asset location updates + location history only (no MRs, WOs, parts, PM, admin) |
| `requester` | Requester | Create CM MRs, view own requests, basic active assets, parts |
| `viewer` | ~~Viewer~~ (removed — merged into Requester) |

Roles are immutable system data. A user has exactly one. Authorisation is enforced
server-side via policies — **the backend is authoritative**; hide UI defensively
but never rely on frontend-only guards.

### 4.2 Maintenance Request status

```
pending_review  ──approve──▶  converted   (terminal: became a Work Order)
pending_review  ──reject───▶  rejected    (terminal)
pending_review  ──cancel───▶  cancelled   (terminal)
```

| Value | Meaning |
|---|---|
| `pending_review` | Awaiting manager action |
| `rejected` | Rejected by manager (terminal) |
| `converted` | Approved & atomically converted to a Work Order (terminal) |
| `cancelled` | Cancelled while pending (terminal) |

There is **no `approved` status** — approval and WO creation happen atomically.

### 4.3 Work Order status

```
open  ──assign──▶  open  ──start──▶  in_progress  ──complete──▶  completed  ──close──▶  closed
open | in_progress | completed  ──cancel──▶  cancelled
```

| Value | Meaning | Terminal? |
|---|---|---|
| `open` | Created from approved MR; may be unassigned | No |
| `in_progress` | Technician working; requires assignment first | No |
| `completed` | Technician finished; awaiting manager close | No |
| `closed` | Manager finalised; **permanently immutable** | Yes |
| `cancelled` | Cancelled with a required reason | Yes |

- A WO cannot move to `in_progress` unless assigned to an active Technician.
- Completed WOs lock technician edits. Closed WOs lock everything.
- Sidebar filters: **Active** = `open` + `in_progress` + `completed`; **Closed** = `closed` + `cancelled`.

### 4.4 PM trigger type

| Value | Meaning |
|---|---|
| `date` | Calendar interval (`interval_days`) |
| `reading` | Meter-reading interval (`interval_reading` + `usage_reading_type_id`) |
| `date_or_reading` | Whichever dimension is due first |

### 4.5 Other enum-like values

| Context | Allowed values |
|---|---|
| Priority (MR & WO) | `low`, `medium`, `high`, `critical` |
| MR `type` | `corrective`, `preventive` |
| Meter reading `source` | `user`, `manual` |
| ERP sync job `status` | `running`, `success`, `partial`, `failed` |

---

## 5. TypeScript Type Definitions

Copy these into `atms/src/types/`. They model the **union** of all
role-visible fields; treat role-gated fields as optional.

```ts
// ---------- Enums / unions ----------

export type RoleCode =
  | 'administrator'
  | 'maintenance_manager'
  | 'technician'
  | 'logistics'
  | 'requester'
  | 'viewer'

export type MaintenanceRequestStatus = 'pending_review' | 'rejected' | 'converted' | 'cancelled'
export type WorkOrderStatus = 'open' | 'in_progress' | 'completed' | 'closed' | 'cancelled'
export type PmTriggerType = 'date' | 'reading' | 'date_or_reading'
export type Priority = 'low' | 'medium' | 'high' | 'critical'
export type MaintenanceRequestType = 'corrective' | 'preventive'
export type MeterReadingSource = 'user' | 'manual'
export type ErpSyncStatus = 'running' | 'success' | 'partial' | 'failed'

export type AssetKind = 'asset' | 'package' | 'component'
export type AssetMaintenanceStatus = 'active' | 'inactive'
export type AssetMaintenanceSubStatus = 'installed' | 'ready' | 'lih' | 'dbr' | 'disposed' | 'scrapped' | 'other' | null

// ---------- Shared fragments ----------

export interface Role { id: number; code: RoleCode; name: string }

/** Minimal asset reference embedded in other resources. */
export interface AssetRef { id: number; name: string; erp_asset_code: string }

/** Minimal user reference. `email` present only for Admin/Manager. */
export interface UserRef { id: number; name: string; email?: string }

export interface LocationRef { id: number; name: string }

// ---------- Core resources ----------

/** GET /api/auth/me  and  the value of  POST /api/auth/login's `user`. */
export interface User {
  id: number
  name: string
  email: string
  is_active: boolean
  activated_at: string | null     // ISO 8601
  emp_id: string | null
  employee_id: number | null
  role: Role
}

export interface Asset {
  id: number
  erp_asset_code: string
  name: string
  description: string | null
  category: string | null
  serial_number: string | null
  asset_tag: string                       // L-BBB-CCC-XXXX, unique, immutable after save
  model: string | null
  manufacturer: string | null
  operational_status: string | null
  current_location?: LocationRef | null   // present when loaded
  erp_status?: string | null              // not for Requester
  erp_last_synced_at?: string | null      // not for Requester
  is_active?: boolean                     // Admin/Manager only
  erp_raw_data?: Record<string, unknown>  // Admin only
  parent_asset_id: number | null
  asset_kind: AssetKind
  maintenance_status: AssetMaintenanceStatus
  maintenance_sub_status: AssetMaintenanceSubStatus
  children?: AssetRef[]
  parent?: AssetRef | null
  created_at: string
  updated_at: string
}

export interface Part {
  id: number
  erp_part_code: string
  name: string
  description: string | null
  unit_of_measure: string | null
  category: string | null
  is_active: boolean
  erp_status?: string | null        // Admin/Manager only
  erp_last_synced_at?: string | null // Admin/Manager only
  erp_raw_data?: Record<string, unknown> // Admin only
  created_at: string
}

/** POST /api/assets — create payload */
export interface AssetCreatePayload {
  name: string
  description?: string | null
  category?: string | null
  serial_number?: string | null
  model?: string | null
  manufacturer?: string | null
  operational_status?: string
  current_location_id?: number | null
}

/** PATCH /api/assets/{asset} — update payload */
export interface AssetUpdatePayload {
  name?: string
  description?: string | null
  category?: string | null
  serial_number?: string | null
  model?: string | null
  manufacturer?: string | null
  operational_status?: string
  is_active?: boolean
  current_location_id?: number | null
  location_notes?: string | null
}

/** PATCH /api/parts/{part} — update payload */
export interface PartUpdatePayload {
  name?: string
  description?: string | null
  unit_of_measure?: string | null
  category?: string | null
  is_active?: boolean
}

/** PATCH /api/maintenance-requests/{mr} — update payload */
export interface MaintenanceRequestUpdatePayload {
  description?: string | null
  priority?: Priority
  asset_id?: number
}

/** PATCH /api/admin/users/{user} — update payload */
export interface UserUpdatePayload {
  name?: string
  email?: string
  role_id?: number
  is_active?: boolean
}

/** POST /api/admin/users/{user}/reset-password — payload */
export interface AdminResetPasswordPayload {
  password: string
  password_confirmation: string
}

export interface MaintenanceRequest {
  id: number
  number: string                     // "MR-0001"
  type: MaintenanceRequestType
  status: MaintenanceRequestStatus
  priority: Priority
  description: string | null
  created_at: string
  asset?: AssetRef                   // when loaded
  created_by?: UserRef               // visibility per matrix
  reviewed_by?: UserRef              // Admin/Manager/
  rejection_reason?: string          // hidden from Logistics
  cancellation_reason?: string       // hidden from Logistics
  is_preventive?: boolean            // Admin/Manager/
  triggered_by_date?: boolean        // Admin/Manager/
  triggered_by_reading?: boolean     // Admin/Manager/
  trigger_date?: string | null       // "YYYY-MM-DD"
  trigger_reading_value?: string | null
  work_order?: { id: number; number: string; status: WorkOrderStatus } | null
  has_attachments?: number
}

export interface WorkOrderPart {
  id: number
  part: { id: number; name: string; erp_part_code: string; unit_of_measure: string | null }
  quantity: number
  notes: string | null
}

export interface WorkOrder {
  id: number
  number: string                     // "WO-0001"
  status: WorkOrderStatus
  priority: Priority
  description: string | null
  asset?: AssetRef
  created_at: string
  assigned_to?: UserRef | null       // Admin/Manager/Tech/
  assigned_by?: UserRef              // Admin/Manager only
  parts?: WorkOrderPart[]            // Admin/Manager/Tech/
  started_at?: string | null
  completed_at?: string | null
  completion_notes?: string | null
  closed_at?: string | null
  cancelled_at?: string | null
  cancellation_reason?: string | null
  has_attachments?: number           // Admin/Manager/Tech
}

export interface PmRule {
  id: number
  name: string
  maintenance_level: string | null       // "L1"–"L4" or custom
  description: string | null
  trigger_type: PmTriggerType
  is_active: boolean
  interval_days: number | null
  interval_reading: number | null
  last_triggered_date: string | null     // "YYYY-MM-DD"
  last_triggered_reading: number | null
  next_due_date: string | null           // computed: baseline + interval
  next_due_reading: number | null        // computed: baseline + interval
  progress_percentage: number | null     // 0–100, max of date/reading
  pm_status: 'ok' | 'soon' | 'due'      // computed
  created_at: string
  asset?: AssetRef
  usage_reading_type?: {                 // show endpoint only
    id: number
    name: string
    unit: string
  }
  suppressions?: Array<{                // show endpoint only
    id: number
    decision_type: string               // 'rejected' | 'cancelled'
    suppressed_until_date: string | null
    suppressed_until_reading: number | null
    source_mr_id: number
  }>
  created_by?: UserRef                   // Admin/Manager only
}

export interface Attachment {
  id: number
  file_name: string
  mime_type: string
  size_bytes: number
  description: string | null
  created_at: string
  download_url?: string                  // absent for Requester
  uploaded_by?: UserRef                  // Admin/Manager only
}

/** Maintenance history read-model (derived, no table). */
export interface MaintenanceHistoryItem {
  date: string | null                    // "YYYY-MM-DD"
  type: MaintenanceRequestType | null
  work_order_number: string
  maintenance_request_number: string | null
  description: string | null
  priority: Priority
  parts_used?: { part_name: string | null; quantity: number }[]
  closed_at: string | null
}

export interface AssetMeterReading {
  id: number
  asset_id: number
  usage_reading_type_id: number
  reading_value: string                  // decimal as string
  reading_at: string                     // ISO 8601
  source: MeterReadingSource
  entered_by_user_id: number
  confirmed_by_user_id: number | null
  confirmed_at: string | null
  notes: string | null
}

export interface AssetLocationHistoryItem {
  id: number
  asset_id: number
  from_location_id: number | null
  to_location_id: number
  effective_at: string
  reason: string | null
  notes: string | null
  changed_by_user_id: number
}

export interface AssetAssemblyHistoryItem {
  id: number
  component_id: number
  parent_id: number
  installed_at: string
  removed_at: string | null
  accumulated_hours: number | null
  installed_by: UserRef
  removed_by: UserRef | null
  reason: string | null
  created_at: string
}

// ---------- Admin resources ----------

export interface Employee {
  id: number
  name: string
  emp_id: string | null
  // + fields as returned; see API reference
}

export interface Location {
  id: number
  parent_id: number | null
  name: string
  type: string
  code: string | null
  description: string | null
  is_active: boolean
}

export interface MasterDataItem {
  id: number
  group_key: string
  value: string
  label: string
  sort_order: number | null
  is_active: boolean
}

export interface UsageReadingType {
  id: number
  name: string
  unit: string
  is_active: boolean
}

export interface ErpSyncJob {
  id: number
  sync_type: 'assets' | 'parts'
  status: ErpSyncStatus
  total_records: number
  created_count: number
  updated_count: number
  failed_count: number
  error_message: string | null
  started_at: string
  completed_at: string | null
}

export interface AuditLog {
  id: number
  event: string
  // + actor/subject fields; see API reference
}

export interface CompanySettings {
  timezone: string
}

// ---------- Pagination wrapper ----------

export interface CursorPaginated<T> {
  data: T[]
  links: { first: string | null; last: string | null; prev: string | null; next: string | null }
  meta: {
    path: string
    per_page: number
    next_cursor: string | null
    prev_cursor: string | null
  }
}

// ---------- Mutation response wrappers ----------

export interface MessageResponse { message: string }
export interface DataResponse<T> { message?: string; data: T }
```

---

## 6. Workflow Sequences

These map the product workflows (see `docs/01-product/WORKFLOWS.md`) to concrete
API call sequences. Build your screens to drive these in order.

### 6.1 Corrective Maintenance: create → approve → execute → close

```
① Requester creates a CM request (from Asset Detail screen)
   POST /api/assets/{asset}/meter-readings        (optional, supporting reading)
   POST /api/maintenance-requests/corrective
        body: { asset_id, priority, description?, meter_reading? }
        → 201 { data: MaintenanceRequest }   (status: pending_review)

①a Creator edits a pending MR (optional)
   PATCH /api/maintenance-requests/{mr}
        body: { description?, priority?, asset_id? }
        → 200 { data: MaintenanceRequest }   (status: still pending_review)

② Manager reviews & approves (or rejects)
   GET  /api/maintenance-requests?status=pending_review   (the approval queue)
   GET  /api/maintenance-requests/{mr}
   POST /api/maintenance-requests/{mr}/approve
        → 200 { message, data }   (MR.status: converted; WO created)
   — or —
   POST /api/maintenance-requests/{mr}/reject
        body: { reason, suppressed_until_date?, suppressed_until_reading? }
        → 200 { message, data }

③ Manager assigns a technician, then work starts
   GET  /api/admin/users               (to pick an active technician)   [Admin only list]
   POST /api/work-orders/{wo}/assign   body: { user_id }
   POST /api/work-orders/{wo}/start    (open → in_progress)

④ Technician executes & completes
   PATCH /api/work-orders/{wo}         body: { description }
   POST  /api/work-orders/{wo}/parts   body: { part_id, quantity, notes? }   (repeatable)
   POST  /api/work-orders/{wo}/attachments  (multipart)                      (repeatable)
   POST  /api/work-orders/{wo}/complete body: { completion_notes? }
        (in_progress → completed; technician edits now locked)

⑤ Manager closes (finalises history; updates PM baseline if PM-linked)
   POST /api/work-orders/{wo}/close    (completed → closed; permanently immutable)
```

**Cancellation paths:**

- Cancel a pending MR (owner, or Admin/Manager): `POST /api/maintenance-requests/{mr}/cancel`
- Cancel a non-closed WO (Admin/Manager): `POST /api/work-orders/{wo}/cancel` body `{ reason }`

### 6.2 Preventive Maintenance (system-initiated)

PM MRs are generated by the scheduler (`EvaluatePmRulesJob`, daily) or on
demand. PM rules follow a **M:N template model**: a rule is a reusable schedule
**template**; an **assignment** (`asset_pm_assignments`) links a template to an
asset and carries that asset's compliance baseline. **Template configuration
(create/edit/deactivate/reactivate) is Administrator-only.** Administrator and
Maintenance Manager may assign a template to an asset and
evaluate/deactivate/reactivate assignments.

```
① Admin creates a PM template
   POST /api/pm-rules   body: { name, maintenance_level?, trigger_type,
        interval_days?, interval_reading?, usage_reading_type_id? }

② Assign a template to an asset (Admin/Manager) — seeds the asset's baseline
   POST /api/assets/{asset}/pm-assignments   body: { pm_rule_id }
        → 201 AssetPmAssignmentResource (last_triggered_date = today)

③ Evaluation (automatic daily, or manual)
   POST /api/assets/{asset}/pm-assignments/{assignment}/evaluate  (single assignment – Admin/Manager)
   POST /api/pm-rules/evaluate-all                               (all active assignments – Admin/Manager)
        → 201 { message:"PM request generated.", data: MaintenanceRequest }  when due
        → 200 { message:"PM assignment is not due." }                        when not due

④ The generated MR follows the same approve → WO → close flow as §6.1 ②–⑤
④ On WO closure, the originating **assignment's** baseline (last_triggered_date
   / _reading) is updated. **Cumulative maintenance:** if the template has a
   standard L1–L4 level, closing a higher-level WO also resets all active
   lower-level **assignments** on the same asset (e.g. closing L3 resets L1 and
   L2 baselines). Custom free-text levels are independent.
```

**Template deactivate / reactivate** (Admin only; presented as `Deactivate`,
never `Delete`). A retired template stops all PM evaluation but does **not**
deactivate its assignments:

```
POST /api/pm-rules/{rule}/deactivate     (Admin only; blocked → 409 if ANY assignment has an active chain)
POST /api/pm-rules/{rule}/reactivate     (Admin only; 409 if already active)
```

**Assignment deactivate / reactivate** (Admin/Manager). Deactivating an
assignment also clears any still-effective suppression windows for that
asset/template pair:

```
POST /api/assets/{asset}/pm-assignments/{assignment}/deactivate   (Admin/Manager; 409 if active chain)
POST /api/assets/{asset}/pm-assignments/{assignment}/reactivate   (Admin/Manager)
```

### 6.3 Asset location update

#### Dedicated Locations screen (Phase 1)

```
① Logistics / Manager / Admin navigates to Locations sidebar → Asset Location Update tab
   GET /api/assets?is_active=true    (list active assets with current location)
   Select asset row → UpdateLocationSheet opens (side sheet)
   GET /api/locations                (pick an active target location — read-only,
                                      available to Admin, Manager, Logistics)
   POST /api/assets/{asset}/location 
        body: { location_id, reason?, notes? }
        → 200 { message, data: Asset }   (creates a location-history record)
```

#### Alternative: via Asset Edit (existing)

```
② Admin / Manager opens Asset Detail → Edit Asset
   PATCH /api/assets/{asset}         (includes current_location_id + location_notes)
   → 200 { data: AssetResource }     (calls UpdateAssetLocation Action internally)
```

#### View location history

```
③ Anyone with access views the history
   GET /api/assets/{asset}/location-history  → { data: AssetLocationHistoryItem[] }
```

#### Manage location definitions (Admin only)

```
④ Admin navigates to Locations sidebar → Manage Locations tab (or Admin → Lists)
   GET    /api/admin/locations            → { data: Location[] }
   POST   /api/admin/locations            → 201 { data: Location }
   PATCH  /api/admin/locations/{location} → 200 { data: Location }
```

### 6.4 Meter readings (with confirmation)

```
① Any role records a reading
   POST /api/assets/{asset}/meter-readings
        body: { usage_reading_type_id, reading_value, reading_at, source, notes? }
        → 201 { message, data }
   (Requester-submitted readings are unverified: confirmed_by/at = null)

② Admin / Manager / Technician confirms (only confirmed readings affect PM & current meter)
   POST /api/assets/{asset}/meter-readings/{reading}/confirm
        → 200 { message, data }      (idempotent)
        → 409 if value < latest confirmed reading (monotonic rule)

③ History
   GET /api/assets/{asset}/meter-readings   → { data: AssetMeterReading[] }  (newest first)
```

### 6.5 ERP sync (Manager or Admin)

```
GET  /api/admin/erp/sync-jobs      (history; status: running|success|partial|failed)
POST /api/admin/erp/sync-assets    → 200 { message }   (dispatches a background job)
POST /api/admin/erp/sync-parts     → 200 { message }
```

The POSTs return immediately; the job runs asynchronously. Poll
`GET /api/admin/erp/sync-jobs` (newest first) to observe the result. Empty ERP
results produce `success`; row-level errors produce `partial`; top-level failures
produce `failed`.

### 6.6 Assembly Operations

Component installation (within or outside a WO):
  POST /api/assets/{asset}/assembly/install
       body: { parent_id }
       → 200 { message, data: Asset }

Component removal:
  POST /api/assets/{asset}/assembly/remove
       body: { reason, post_removal_sub_status? }
       → 200 { message, data: Asset }

Component swap (atomic remove + install):
  POST /api/assets/{parent}/assembly/swap
       body: { old_component_id, new_component_id, reason, post_removal_sub_status? }
       → 200 { message, data: { removed: Asset, installed: Asset } }

Assembly history:
  GET  /api/assets/{asset}/assembly-history
       → { data: AssetAssemblyHistoryItem[] }

Parent WO component PM cross-check:
  GET  /api/assets/{asset}/children
       → { data: Asset[] }  (includes each child's PM status indicator)

### 6.7 WO Forms — Template Management & Execution

Template management (Admin only):
  GET    /api/admin/wo-forms/templates              → list templates
  POST   /api/admin/wo-forms/templates              → create template { name, fa_subclass_code }
  GET    /api/admin/wo-forms/templates/{template}   → show template with fields
  PATCH  /api/admin/wo-forms/templates/{template}   → update template name
  POST   /api/admin/wo-forms/templates/{template}/deactivate
  POST   /api/admin/wo-forms/templates/{template}/reactivate
  POST   /api/admin/wo-forms/templates/{template}/fields   → add field
  PATCH  /api/admin/wo-forms/templates/{template}/fields/{field}
  DELETE /api/admin/wo-forms/templates/{template}/fields/{field}
  POST   /api/admin/wo-forms/templates/{template}/fields/reorder  → { field_ids: [...] }

Snapshot happens automatically on WO creation (no separate endpoint).

WO Form execution (assigned Technician / Manager / Admin):
  GET    /api/work-orders/{wo}/form                    → form with fields + values
  PATCH  /api/work-orders/{wo}/form/fields/{field}     → update pre_value / post_value / notes

Sync-to-latest (assigned Technician / Manager / Admin):
  POST   /api/work-orders/{wo}/form/sync               → merge by field uuid
  POST   /api/work-orders/{wo}/form/defer-sync         → dismiss banner

Completion gate: POST /api/work-orders/{wo}/complete rejects with 422 if any
required form fields are unfilled (pre & post for has_pre_post fields, post for
single-value fields). The error includes field-level details.

---

## 7. Frontend Integration Patterns

### 7.1 Loading a cursor-paginated list

```ts
const { data } = await api.get<CursorPaginated<Asset>>('/assets', {
  params: { search: q, sort: 'name:asc', per_page: 25 },
})
rows.value = data.data
nextCursor.value = data.meta.next_cursor   // null ⇒ end reached

// "Load more":
const more = await api.get<CursorPaginated<Asset>>('/assets', {
  params: { cursor: nextCursor.value },
})
rows.value.push(...more.data.data)
nextCursor.value = more.data.meta.next_cursor
```

### 7.2 Submitting a form (validate → confirm → submit → toast)

Per the project's persistent-change policy, **every mutation requires a
confirmation dialog** before the request. Standard pattern:

```ts
// 1. open the form sheet
// 2. on "Save", run client validation, then open ConfirmDialog
// 3. on confirm:
try {
  const res = await api.post('/maintenance-requests/corrective', payload)
  toast.success('Maintenance request created.')
  // 4. refresh affected lists / navigate to detail
} catch (err) {
  if (err.response?.status === 422) {
    setFieldErrors(err.response.data.errors)   // { asset_id: ['…'] }
  } else if (err.response?.status === 409) {
    toast.error(err.response.data.message)      // domain conflict
  } else {
    toast.error('Something went wrong.')
  }
}
```

**Always call `ensureCsrfCookie()` before the first POST** (see [§2.2](#22-axios-setup-reference)).

### 7.3 Uploading an attachment

Use `multipart/form-data`; do not set `Content-Type` manually (the browser sets
the boundary).

```ts
const form = new FormData()
form.append('file', fileInput.files[0])
form.append('description', 'Datasheet')

await api.post(`/work-orders/${woId}/attachments`, form, {
  headers: { 'Content-Type': 'multipart/form-data' },
})
```

Allowed types: `pdf, jpg, jpeg, png, gif, webp, doc, docx, xls, xlsx`. Max 20 MB.
The server MIME-checks the actual file content (extension spoofing is rejected →
`422`).

### 7.4 Downloading an attachment

Use a regular GET (or anchor) so the browser handles the binary stream. Include
credentials.

```ts
window.open(`/api/attachments/${id}/download`, '_blank')
// or anchor with href; the response has Content-Disposition: attachment
```

### 7.5 Workflow action availability (mirror server policy)

Drive button visibility from role + status, but treat the server as authoritative
(expect possible `403`/`409`):

| Action | Show when |
|---|---|
| Approve / Reject MR | role ∈ {admin, manager} **and** MR.status = `pending_review` |
| Cancel MR | (owner **or** admin/manager) **and** status = `pending_review` |
| Edit MR | (creator of corrective MR **or** admin/manager) **and** status = `pending_review` |
| Create Asset | role ∈ {admin, manager} |
| Edit Asset | role ∈ {admin, manager} |
| Edit Part | role ∈ {admin, manager} |
| Update User | role = admin **and** target ≠ self |
| Reset User Password | role = admin **and** target ≠ self |
| Assign WO | role ∈ {admin, manager} **and** WO.status = `open` |
| Start WO | WO.status = `open` **and** assigned |
| Complete WO | (assignee **or** admin/manager) **and** status = `in_progress` |
| Close WO | role ∈ {admin, manager} **and** status = `completed` |
| Cancel WO | role ∈ {admin, manager} **and** status ∈ {open, in_progress, completed} |
| Confirm reading | role ∈ {admin, manager, technician} **and** reading unconfirmed |
| Install Component | role ∈ {admin, manager, technician(on assigned WO)} and component is spare (Ready, parent_asset_id IS NULL) |
| Remove Component | role ∈ {admin, manager, technician(on assigned WO)} and component is installed (parent_asset_id IS NOT NULL) |
| Swap Component | role ∈ {admin, manager, technician(on assigned WO)} and old installed, new spare |
| Create MR for Component | role ∈ {admin, manager} and component PM status is yellow/red and viewing parent WO |

### 7.6 ERP sync polling

Sync POSTs return immediately. To show progress/result, poll the job history:

```ts
// after dispatching:
await new Promise(r => setTimeout(r, 1500))
const { data } = await api.get<CursorPaginated<ErpSyncJob>>('/admin/erp/sync-jobs')
const latest = data.data[0]   // newest first
// latest.status ∈ running | success | partial | failed
```

---

## 8. Endpoint Quick Reference

Sort/filter columns list the allowed values. For full request/response detail,
see [`BACKEND_API_REFERENCE.md`](./BACKEND_API_REFERENCE.md).

### Auth & health

| Method | Endpoint | Role | Notes |
|---|---|---|---|
| POST | `/api/auth/login` | Public | rate-limited 5/min |
| POST | `/api/auth/logout` | Auth | 204 |
| GET | `/api/auth/me` | Auth | `{ user }` |
| POST | `/api/auth/activate` | Public | throttle 5/min |
| POST | `/api/auth/forgot-password` | Public | always 200 |
| POST | `/api/auth/reset-password` | Public | throttle 5/min |
| GET | `/api/health/live` | Public | `{status:"alive"}` |
| GET | `/api/health/ready` | Public | db + attachments check |

### Dashboard

| Method | Endpoint | Role |
|---|---|---|
| GET | `/api/dashboard` | Any role (widgets adapt by role) |

### Assets

| Method | Endpoint | Role | Sort fields | Filters |
|---|---|---|---|---|
| GET | `/api/assets` | Any | name, erp_asset_code, category, operational_status, created_at | search, is_active, operational_status, category, location_id |
| POST | `/api/assets` | Admin/Mgr | — | body: name, description?, category?, serial_number?, model?, manufacturer?, operational_status?, current_location_id? |
| GET | `/api/assets/{asset}` | Any | — | — |
| PATCH | `/api/assets/{asset}` | Admin/Mgr | — | body: any operational field + current_location_id?, location_notes? |
| GET | `/api/assets/{asset}/meter-readings` | Any | — | — |
| GET | `/api/assets/{asset}/location-history` | Any | — | — |
| GET | `/api/assets/{asset}/maintenance-history` | Any (not Logistics) | created_at | per_page, cursor |
| POST | `/api/assets/{asset}/location` | Admin/Mgr/Logistics | — | body: location_id, reason?, notes? |
| POST | `/api/assets/{asset}/meter-readings` | Any | — | body: usage_reading_type_id, reading_value, reading_at, source, notes? |
| POST | `/api/assets/{asset}/meter-readings/{reading}/confirm` | Admin/Mgr | — | idempotent |
| POST | `/api/assets/{asset}/assembly/install` | Admin/Mgr/Tech(on WO) | body: parent_id |
| POST | `/api/assets/{asset}/assembly/remove` | Admin/Mgr/Tech(on WO) | body: reason, post_removal_sub_status? |
| POST | `/api/assets/{parent}/assembly/swap` | Admin/Mgr/Tech(on WO) | body: old_component_id, new_component_id, reason |
| GET | `/api/assets/{asset}/assembly-history` | Any (with view access to component) | install/removal timeline |
| GET | `/api/assets/{asset}/children` | Any (with view access to parent) | direct children with PM status |

### Locations

| Method | Endpoint | Role | Sort | Filters |
|---|---|---|---|---|
| GET | `/api/locations` | Admin/Mgr/Logistics | — | active only |
| POST | `/api/assets/{asset}/location` | Admin/Mgr/Logistics | — | body: location_id, reason?, notes? |

### Parts

| Method | Endpoint | Role | Sort | Filters |
|---|---|---|---|---|
| GET | `/api/parts` | Any (not Logistics) | name, erp_part_code | search |
| GET | `/api/parts/{part}` | Any (not Logistics) | — | 403 for inactive to non-admin/mgr |
| PATCH | `/api/parts/{part}` | Admin/Mgr | — | body: name?, description?, unit_of_measure?, category?, is_active? |

### Maintenance Requests

| Method | Endpoint | Role | Sort | Filters |
|---|---|---|---|---|
| GET | `/api/maintenance-requests` | Any (Requester → own only) | created_at, priority, status | status, asset_id, priority, type, created_by |
| POST | `/api/maintenance-requests/corrective` | Admin/Mgr/Tech/Requester | — | body: asset_id, priority, description?, meter_reading? |
| GET | `/api/maintenance-requests/{mr}` | View policy | — | — |
| POST | `/api/maintenance-requests/{mr}/approve` | Admin/Mgr | — | atomic: creates WO |
| POST | `/api/maintenance-requests/{mr}/reject` | Admin/Mgr | — | body: reason, suppression fields for PM |
| POST | `/api/maintenance-requests/{mr}/cancel` | Owner/Admin/Mgr | — | body: reason, suppression fields for PM |
| PATCH | `/api/maintenance-requests/{mr}` | Creator/Admin/Mgr | — | body: description?, priority?, asset_id?; 409 if not pending_review |

### Work Orders

| Method | Endpoint | Role | Sort | Filters |
|---|---|---|---|---|
| GET | `/api/work-orders` | Any (Tech → assigned only) | created_at, priority, status, started_at, closed_at | status, assigned_to, asset_id, priority, from, to |
| GET | `/api/work-orders/{wo}` | View policy | — | — |
| PATCH | `/api/work-orders/{wo}` | Assignee/Admin/Mgr | — | body: description |
| POST | `/api/work-orders/{wo}/assign` | Admin/Mgr | — | body: user_id (active technician) |
| POST | `/api/work-orders/{wo}/start` | Assignee/Admin/Mgr | — | open → in_progress |
| POST | `/api/work-orders/{wo}/complete` | Assignee/Admin/Mgr | — | body: completion_notes? |
| POST | `/api/work-orders/{wo}/close` | Admin/Mgr | — | completed → closed |
| POST | `/api/work-orders/{wo}/cancel` | Admin/Mgr | — | body: reason |
| POST | `/api/work-orders/{wo}/parts` | Assignee/Admin/Mgr | — | body: part_id, quantity, notes? |
| DELETE | `/api/work-orders/{wo}/parts/{line}` | Assignee/Admin/Mgr | — | — |

### PM Rules

| Method | Endpoint | Role | Sort | Filters |
|---|---|---|---|---|
| GET | `/api/pm-rules` | Admin/Mgr | name, created_at, is_active | is_active, trigger_type |
| POST | `/api/pm-rules` | **Admin** | — | body: name, maintenance_level?, trigger_type, interval_days?, interval_reading?, usage_reading_type_id? |
| GET | `/api/pm-rules/{rule}` | Admin/Mgr | — | — |
| GET | `/api/pm-rules/{rule}/assignments` | Admin/Mgr | — | coverage: which assets use this template |
| PATCH | `/api/pm-rules/{rule}` | **Admin** | — | body: name?, maintenance_level?, description?, interval_days?, interval_reading? |
| POST | `/api/pm-rules/{rule}/deactivate` | **Admin** | — | 409 if ANY assignment has an active chain |
| POST | `/api/pm-rules/{rule}/reactivate` | **Admin** | — | 409 if already active |
| POST | `/api/pm-rules/evaluate-all` | Admin/Mgr | — | `{ evaluated, generated }` |
| GET | `/api/assets/{asset}/pm-assignments` | Admin/Mgr | — | is_active (default 1) |
| POST | `/api/assets/{asset}/pm-assignments` | Admin/Mgr | — | body: { pm_rule_id }; seeds baseline |
| GET | `/api/assets/{asset}/pm-assignments/{assignment}` | Admin/Mgr | — | scoped to asset (cross-asset → 404) |
| POST | `/api/assets/{asset}/pm-assignments/{assignment}/deactivate` | Admin/Mgr | — | 409 if active chain; clears suppression windows |
| POST | `/api/assets/{asset}/pm-assignments/{assignment}/reactivate` | Admin/Mgr | — | 409 if already active |
| POST | `/api/assets/{asset}/pm-assignments/{assignment}/evaluate` | Admin/Mgr | — | 201 if due, 200 if not |

### Attachments (`{parent}` = assets | parts | maintenance-requests | work-orders)

| Method | Endpoint | Upload role | List role |
|---|---|---|---|
| POST | `/api/{parent}/{id}/attachments` | varies (see ref) | varies |
| GET | `/api/{parent}/{id}/attachments` | — | varies |
| GET | `/api/attachments/{attachment}/download` | same as list for parent | binary stream |
| DELETE | `/api/attachments/{attachment}` | Admin/Mgr | soft-delete |

### WO Forms

| Method | Endpoint | Role | Notes |
|---|---|---|---|
| GET | `/api/work-orders/{wo}/form` | Tech(assigned)/Admin/Mgr | Form instance + field values |
| PATCH | `/api/work-orders/{wo}/form/fields/{field}` | Tech(assigned)/Admin/Mgr | Update pre/post value |
| POST | `/api/work-orders/{wo}/form/sync` | Tech(assigned)/Admin/Mgr | Sync to latest template |
| POST | `/api/work-orders/{wo}/form/defer-sync` | Tech(assigned)/Admin/Mgr | Defer sync prompt |

### Admin

| Method | Endpoint | Role |
|---|---|---|
| GET | `/api/admin/users` | Admin |
| GET | `/api/admin/users/{user}` | Admin |
| PATCH | `/api/admin/users/{user}` | Admin |
| POST | `/api/admin/users/{user}/deactivate` | Admin |
| POST | `/api/admin/users/{user}/reactivate` | Admin |
| POST | `/api/admin/users/{user}/reset-password` | Admin |
| GET | `/api/admin/roles` | Admin |
| GET | `/api/admin/employees` | Admin |
| POST | `/api/admin/employees/import` | Admin |
| POST | `/api/admin/employees/{emp}/provision-user` | Admin |
| GET | `/api/admin/erp/sync-jobs` | Admin/Mgr |
| POST | `/api/admin/erp/sync-assets` · `/sync-parts` | Admin/Mgr |
| GET | `/api/admin/audit-logs` | Admin |
| GET / PATCH | `/api/admin/company-settings` | Admin |
| GET / POST / PATCH | `/api/admin/wo-forms/templates…` | Admin |
| GET / POST / PATCH / DELETE | `/api/admin/wo-forms/templates/{t}/fields…` | Admin |
| GET / POST / PATCH | `/api/admin/locations…` | Admin |
| GET / POST | `/api/admin/master-data/{group}` · PATCH `…/items/{item}` | Admin |
| GET / POST / PATCH | `/api/admin/usage-reading-types…` | Admin |

---

## 9. Important Implementation Notes

1. **No `approved` MR status.** Approval and Work Order creation are atomic; the
   MR jumps straight to `converted`. Do not render an "approved" badge state.

2. **POST/PATCH create and update endpoints return the resource-shaped object
   (PmRuleResource, etc.).** After a successful create, you may use the response
   directly; however, if you need the fully resource-shaped/role-filtered object
   (e.g. to render `asset`, `created_by`, `download_url`), re-fetch via the GET
   show endpoint. Note: `POST /api/maintenance-requests/corrective` and
   attachment uploads return the raw model — re-fetch after create for these.

3. **Closed Work Orders are permanently immutable.** No endpoint reopens, edits,
   or re-cancels them. Render closed/cancelled WOs as read-only.

4. **Technicians only see assigned WOs.** The list endpoint auto-scopes to
   `assigned_to_user_id = me` for the technician role — you do not need to pass a
   filter.

5. **Requesters only see their own MRs.** The MR list auto-scopes to
   `created_by = me` for requesters.

6. **Inactive assets/parts are hidden** from everyone except Admin/Manager, both
   in lists and on show (403 for inactive part to non-admin/mgr).

7. **PM rules are templates; assets get them via assignments.** `POST /api/pm-rules`
   creates a template (no `asset_id`). Use `POST /api/assets/{asset}/pm-assignments`
   `{ pm_rule_id }` to assign a template to an asset — the template must be active.

8. **PM suppression fields are conditionally required.** When rejecting/cancelling
   a preventive MR: `suppressed_until_date` is required if `triggered_by_date`;
   `suppressed_until_reading` is required if `triggered_by_reading`. The backend
   enforces this dynamically — mirror the same conditional-required logic in your
   form.

9. **Meter readings are monotonically non-decreasing.** Confirming a reading lower
   than the latest confirmed value returns `409`. Corrections require a new valid
   reading + an admin audit note (no edit-in-place).

10. **ERP sync is asynchronous.** The sync POSTs return `200` immediately with a
    message; results appear in `GET /api/admin/erp/sync-jobs`. Poll that endpoint.

11. **Deactivation invalidates sessions.** `POST /api/admin/users/{user}/deactivate`
    atomically sets inactive, deletes sessions, and revokes tokens. The affected
    user's next request gets `401`. You cannot deactivate yourself (`422`).

12. **Attachment MIME is content-checked.** Renaming a `.exe` to `.pdf` is rejected
    (`422`). Validate client-side for UX, but expect server enforcement.

13. **Audit logs are append-only and Administrator-only.** No create/edit/delete
    endpoint exists; `GET /api/admin/audit-logs` is the only surface.

14. **Local dev uses a fake email transport.** Activation/reset emails are written
    to the log/fake transport — check the `api` container logs (or Laravel log)
    for the one-time links during development.

15. **Assets are synced from LDC ERP.** Assets may also be created and managed within ATMS via `POST /api/assets` and `PATCH /api/assets/{asset}`.
    Admin/Manager create assets via `POST /api/assets` and update via
    `PATCH /api/assets/{asset}`. Updating `current_location_id` automatically
    records a location history entry.

16. **Pending MRs are editable.** While an MR is `pending_review`, the creator (or
    Admin/Manager) may update description, priority, and asset via
    `PATCH /api/maintenance-requests/{mr}`. Preventive MRs cannot be edited.

17. **Admin user management.** Admin may update user name, email, role, and active
    status via `PATCH /api/admin/users/{user}`, and reset another user's password
    via `POST /api/admin/users/{user}/reset-password`. Self-targeting these
    endpoints returns 422.
