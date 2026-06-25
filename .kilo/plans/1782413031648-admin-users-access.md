# Admin — Users & Access Implementation Plan

**Goal:** Build the Admin section as a tabbed container at `/admin/*` with a fully functional Users & Access tab. Lists and PM Rules tabs keep their existing stubs (routes updated only).

**Backend Status:** ✅ Fully ready. No backend work needed.

**Design Rule:** Follow existing page conventions exactly. Reference pages below are the source of truth for layout, styling, and interaction patterns. The implementer has freedom on component decomposition, field arrangement, and internal naming — but must match the look and feel of existing pages.

---

## Pattern References (follow these)

| Pattern | Reference file | What to mirror |
|---|---|---|
| Tabbed page container | `views/locations/LocationsView.vue` | Tab nav via `view-tabs`/`view-tab-active`/`view-tab-normal`, active tab from route, `AppLayout` wrapper, `page-header` |
| Data table with row actions | `views/locations/AssetLocationUpdateView.vue` | `AppDataTable` with `#cell` and `#row-actions` slots, `data-card` sections stacked vertically |
| Side sheet form (edit/create) | `views/assets/AssetDetailView.vue` (edit sheet) | `Sheet :modal="false"`, `create-sheet*` CSS classes, `form-field`/`form-grid`, inline `form-error` |
| Confirm dialog before mutation | `views/locations/UpdateLocationSheet.vue` | `Dialog` with `confirmation-summary`/`confirmation-warning`, disable while submitting |
| Composable with CRUD + cache | `composables/useLocations.ts` | `fetchList` for cursor-walking, `loaded` cache flag, `ApiError` handling, `validationErrors` ref |
| Role/status badge helpers | `lib/displayHelpers.ts` | `*Class()` + `*Label()` function pairs, map object + fallback |

---

## Route Changes

Move admin routes from `/settings/*` to `/admin/*`. Keep legacy redirects for backwards compatibility.

| New route | Maps to | Meta |
|---|---|---|
| `/admin` | redirect → `/admin/users` | — |
| `/admin/users` | `AdminView.vue` | `requiresAdmin` |
| `/admin/lists` | `AdminView.vue` | `requiresAdmin` |
| `/admin/pm-rules` | `AdminView.vue` | `requiresAdminOrManager` |
| `/admin/pm-rules/:ruleId` | existing `PmRuleDetailView.vue` | `requiresAdminOrManager` |

Legacy redirects (add these so old bookmarks work):
- `/settings/users` → `/admin/users`
- `/settings/lists` → `/admin/lists`
- `/settings/pm-rules` → `/admin/pm-rules`
- `/settings/pm-rules/:ruleId` → `/admin/pm-rules/:ruleId`

**Sidebar change:** Admin item `to` → `/admin/users`, `isActiveFor` → matches `/admin/users`, `/admin/lists`, `/admin/pm-rules*`. (Same logic, just `/settings/` → `/admin/`.)

Settings routes (`/settings/system`, `/settings/audit-logs`) stay unchanged.

**Routing model:** Path-based tabs (not `?tab=` query params). All three admin paths point to the same `AdminView.vue`, which derives the active tab from `route.path` and renders the matching sub-view. This matches the user's explicit request for `/admin/users`.

---

## Architecture

### `AdminView.vue` — tab container

- Wraps in `AppLayout` (like all pages)
- Renders `page-header` ("Admin" title + subtitle)
- Renders `view-tabs` nav with 3 RouterLinks (Users & Access / Lists & Dropdowns / PM Rules)
- Active tab derived from `route.path`
- Conditionally renders the matching sub-view: `UsersView`, existing `ListsView` stub, existing `PmRulesView` stub
- **Constraint:** `ListsView` and `PmRulesView` are NOT wrapped in their own `AppLayout` — they render content only (the `AdminView` provides the layout + tabs). This means their existing stub code (which includes `AppLayout`) must be refactored to content-only, OR `AdminView` renders them via slot/conditional without duplication.

### `UsersView.vue` — the deliverable

Two stacked `data-card` sections on one page (no internal sub-tabs):

**Section 1: Employee Directory**
- Title: "Employee Directory" with subtitle
- Action area: "Import from SharePoint" button — **disabled** (no SP token). Tooltip/note: "SharePoint integration pending — employees seeded manually."
- `AppDataTable` of employees with columns: Name, Employee ID, Email, Department, Job Title
- Per-row action: "Provision as User" button (shown for unprovisioned employees) OR "Provisioned" badge (shown for already-provisioned ones)
- Data source: `GET /admin/employees` (cursor-paginated — use `fetchList` to walk all pages)

**Section 2: System Users**
- Title: "System Users" with subtitle
- `AppDataTable` of users with columns: Name, Email, Role (badge), Status (badge)
- Per-row actions: Edit · Reset PW · Activate/Deactivate
- Self-action guard: all actions disabled for the current authenticated admin's own row (show "(you)" next to name)
- Data source: `GET /admin/users` (NOT paginated — returns full collection as `{ data: User[] }`)

**Cross-referencing:** The backend's `GET /admin/employees` does NOT eager-load the `user` relation. To determine which employees are already provisioned, cross-reference by `emp_id`: build a `Set` of `user.emp_id` values from the users list, then check each employee's `emp_id` against it.

### `useUsers.ts` — composable

Follows `useLocations.ts` pattern. Provides:

| State | Source |
|---|---|
| `roles` (filtered, excludes `service`) | `GET /admin/roles` |
| `employees` | `GET /admin/employees` via `fetchList` |
| `users` | `GET /admin/users` (plain `api.get`, no pagination) |
| `provisioning` flag + `provisionUser(employee, roleId)` | `POST /admin/employees/{id}/provision-user` |
| `saving` + `validationErrors` + `updateUser(id, payload)` | `PATCH /admin/users/{id}` |
| `deactivateUser(id)` / `reactivateUser(id)` | `POST /admin/users/{id}/deactivate` and `/reactivate` |
| `resettingPassword` + `resetPassword(id, password)` | `POST /admin/users/{id}/reset-password` |

After every successful mutation, force-refresh both `employees` and `users` (provisioning changes both lists; user edits change users list).

---

## Component Decomposition (suggested, not mandatory)

The implementer may split or merge these as they see fit, as long as all flows work:

- **Provision dialog** — role picker (Select), confirm button. Calls `provisionUser`. Shows a warning that an activation email will be sent (24h expiry).
- **Edit user sheet** — side sheet with Name, Email, Role fields. Calls `updateUser`. Inline validation errors.
- **Reset password dialog** — password + confirmation fields, min 8 chars, match check. Calls `resetPassword`. Warning that all sessions are invalidated.
- **Activate/Deactivate dialog** — simple confirm with context-specific message. Calls `deactivateUser` or `reactivateUser`.

---

## API Contracts (the authoritative reference)

### Roles
```
GET /admin/roles → { data: Role[] }
  Role: { id, code, name, description }
  code ∈ { administrator, maintenance_manager, technician, logistics, requester, service }
  FILTER OUT 'service' in the UI — not assignable to humans.
```

### Employees
```
GET /admin/employees?sort=name:asc → cursor-paginated { data, links, meta }
  Employee fields returned: id, name, emp_id, email, department, job_title,
    sharepoint_item_id, source_is_active, source_raw_data, last_synced_at, ...
  NOTE: 'user' relation is NOT included. Cross-reference by emp_id.

POST /admin/employees/{employee}/provision-user
  Body: { role_id: number }
  → 200 { message: "User provisioned and activation email queued.", data: User }
  → 409 { message: "Employee is already provisioned as a user." }
```

### Users
```
GET /admin/users → { data: User[] }    ← NOT paginated, plain array
  User: { id, name, email, is_active, activated_at, email_verified_at,
          emp_id, employee_id, role: { id, code, name, description },
          created_at, updated_at }

PATCH /admin/users/{user}
  Body (all optional): { name?, email?, role_id?, is_active? }
  → 200 { data: User }
  → 422 if email not unique, or if target is self (message: "Cannot update your own account...")
  → 422 validation errors

POST /admin/users/{user}/deactivate
  No body → 200 { message: "User deactivated.", data: User }
  → 422 if target is self

POST /admin/users/{user}/reactivate
  No body → 200 { message: "User reactivated.", data: User }
  (No self-guard — admin can reactivate self if somehow inactive)

POST /admin/users/{user}/reset-password
  Body: { password: string (min 8), password_confirmation: string }
  → 200 { message: "Password reset successful." }
  → 422 if password too short, or if target is self
  Side effect: deletes all sessions + Sanctum tokens for that user.
```

### Activation flow (context only — no frontend work needed here)
Provisioning creates an inactive user (`is_active=false`, `activated_at=null`) and emails a one-time activation link. The user clicks the link, sets a password via `POST /auth/activate`, and becomes active. **There is no resend-activation endpoint.** If the 24h link expires, there's currently no recovery path — flag for future backend work.

---

## Hard Constraints

1. **Semantic CSS only in feature files.** No Tailwind utilities. All visual styling via the shared CSS classes (`data-card*`, `page-header`, `view-tabs`, `form-field`, `create-sheet*`, `status-badge`, etc.).

2. **shadcn-vue components only** for interactive controls. No raw `<button>`, `<input>`, `<select>`. Use `Button`, `Input`, `Label`, `Select/SelectContent/SelectItem`, `Textarea`, `Dialog/*`, `Sheet/*`.

3. **Confirm before every mutation.** Every provisioning, edit, password reset, activate/deactivate must go through a confirm dialog before the API call. Disable repeat submission while in-flight. Toast on success/failure.

4. **Self-action guard.** Edit, Reset Password, and Deactivate must be disabled (not just hidden) for the currently authenticated admin's own user row. Backend enforces this with 422, but the UI must prevent the attempt.

5. **Filter `service` role.** Never show the `service` role in any role picker (provision dialog, edit sheet). It's for M2M API tokens only.

6. **Role badges and status badges.** Add `roleClass()` / `roleLabel()` helpers to `displayHelpers.ts` (follow the existing `locationTypeClass` pattern). Add CSS classes for each role color. Status uses: Active (green `status-active`), Inactive (gray `status-inactive`), Pending Activation (amber — add `status-pending` class, derived from `activated_at === null`).

7. **Type fixes required:**
   - `RoleCode` union: remove `viewer`, add `service`
   - `Role` interface: add `description?: string`
   - `User` interface: add `email_verified_at`, `created_at`, `updated_at`

8. **Provisioning cross-reference.** Don't depend on `employee.user` from the API (it's not returned). Cross-reference `emp_id` between employees and users lists.

9. **CSRF before first POST.** The `api.ts` client handles this automatically via `initCsrf()` on the first mutating request.

---

## Employee Seeder

Create `EmployeeSeeder.php` seeding ~8 employees into the `employees` table. Each needs:
- `emp_id` (unique), `name`, `email`, `department`, `job_title`
- `sharepoint_item_id` (unique — use UUID), `source_is_active` (true), `last_synced_at` (now)

Use placeholder Libyan employee names across departments (Operations, Maintenance, Field, Warehouse, Logistics). The user will replace with real SharePoint data when the token is available. Register in `DatabaseSeeder` after `LocationSeeder`.

Idempotent via `firstOrCreate` on `emp_id`.

---

## Files

### Create
| File | Purpose |
|---|---|
| `backend/database/seeders/EmployeeSeeder.php` | 8 test employees |
| `frontend/src/views/admin/AdminView.vue` | Tab container (Users / Lists / PM Rules) |
| `frontend/src/views/admin/UsersView.vue` | Full Users & Access (replaces 19-line stub) |
| `frontend/src/composables/useUsers.ts` | User + employee + role data management |
| `frontend/src/components/admin/ProvisionUserDialog.vue` | Role-picker for provisioning (suggested) |
| `frontend/src/components/admin/EditUserSheet.vue` | Edit user side sheet (suggested) |
| `frontend/src/components/admin/ResetPasswordDialog.vue` | Password reset (suggested) |

### Modify
| File | Change |
|---|---|
| `frontend/src/types/index.ts` | Fix RoleCode, Role, User types |
| `frontend/src/lib/displayHelpers.ts` | Add role/status helpers |
| `frontend/src/style.css` | Add role badge + status-pending CSS |
| `frontend/src/router/index.ts` | Move admin routes to `/admin/*`, add legacy redirects |
| `frontend/src/components/app/AppSidebar.vue` | Admin item: `/settings/` → `/admin/` |
| `backend/database/seeders/DatabaseSeeder.php` | Register EmployeeSeeder |

### Stub refactoring
| File | Change |
|---|---|
| `frontend/src/views/admin/ListsView.vue` | Remove `AppLayout` wrapper (content-only — AdminView provides layout). Keep "coming soon" placeholder. |
| `frontend/src/views/pm-rules/PmRulesView.vue` | Same — content-only. Keep "coming soon" placeholder. |

### Lists & Dropdowns — Future Context (out of scope for this plan)

The Lists tab is a stub, but the backend is fully ready. When this screen is later built, it will manage **8 configurable groups across 3 data sources**, all with existing CRUD APIs and zero items seeded:

| Data Source | Groups | API |
|---|---|---|
| **Master Data Items** (generic `master_data_items` table) | 6 groups: `asset_categories`, `maintenance_categories`, `maintenance_priorities`, `asset_statuses`, `asset_maintenance_sub_statuses`, `work_order_statuses` | `GET/POST/PATCH /api/admin/master-data/{groupKey}` — one call per group |
| **Usage Reading Types** (dedicated table) | 1 group | `GET/POST/PATCH /api/admin/usage-reading-types` |
| **FA Subclass Type Codes** (dedicated table) | 1 group (18 known ERP codes) | `GET/POST/PATCH/DELETE /api/admin/fa-subclass-type-codes` |

**Note:** Locations already has its own dedicated screen (`/locations?tab=manage-locations`) and is not part of Lists.

**Caution:** Several hardcoded validations (`priority` on MRs/WOs, `operational_status` on Assets, PHP enums for statuses/kinds/triggers) do NOT read from `master_data_items`. Migrating them to read from the master data table is a separate backend task, deferred. Until that migration happens, editing master data items for these groups has no effect on application behavior — it's prep work only.

---

## Testing Checklist

- `/admin` redirects to `/admin/users`
- `/settings/users` redirects to `/admin/users` (legacy)
- Sidebar "Admin" highlighted on all `/admin/*` paths
- Tabs switch content and update URL
- 8 employees seeded and visible in Employee Directory
- "Import from SharePoint" button is disabled
- Provision an employee → role dialog → confirm → toast → employee shows "Provisioned" badge → user appears in System Users
- Provision same employee again → 409 error handled gracefully
- Edit a user → sheet → change name/role → save → toast → table refreshes
- Current admin's own row: Edit / Reset PW / Deactivate all disabled
- Reset password → dialog → valid password → toast
- Deactivate → confirm → status changes to Inactive → Reactivate works
- Role badges render correct colors per role
- Status badges: Pending Activation (amber) for un-activated, Active (green), Inactive (gray)
- `npm run build` — no TS or CSS errors
- `php artisan test` — all backend tests still pass

---

## Risk Notes

1. **No resend activation.** If a 24h activation link expires, there's no recovery. Flag as future backend enhancement.

2. **`GET /admin/users` not paginated.** Returns the full collection. Fine for MVP with a small user base.

3. **Stub refactoring risk.** `ListsView` and `PmRulesView` currently include their own `AppLayout`. If `AdminView` wraps them inside its own `AppLayout`, you get double layout. Must refactor stubs to content-only (remove `AppLayout`, keep just the `data-card` placeholder).

4. **PM Rules auth mismatch.** `/admin/pm-rules` uses `requiresAdminOrManager` (Manager can view), but the Admin sidebar item is Admin-only. A Manager navigating directly to `/admin/pm-rules` sees the page but not the sidebar item. Acceptable for now.
