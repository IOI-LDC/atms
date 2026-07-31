---
name: atms-api-contracts
description: Endpoint-level contracts for the ATMS API endpoints added since 2026-06-23 — roles, validation rules, error codes, locking, and audit behaviour that the route definitions alone do not reveal. Use when implementing, changing, or consuming /locations, /assets, /parts, /maintenance-requests, /admin/users, /list-options, /dashboard/kpis, /auth/change-password, or the work-order form-field routes.
---

# ATMS API contracts (added 2026-06-23 onwards)

These rows carry the parts of each contract that `php artisan route:list` and the
controller signatures do **not** show: role gates, error-code semantics, locking
order, and audit-log side effects. Full request/response shapes live in
`docs/API.md`.

| Method | Path | Roles | Notes |
|---|---|---|---|
| `GET` | `/locations` | Admin, Manager, Logistics | List active locations only (`is_active = true`), sorted by name. Distinct from Admin-only `/admin/locations` (all statuses). Backs the Locations sidebar location picker. |
| `POST` | `/assets` | Admin, Manager | Create asset; location at creation starts location history |
| `PATCH` | `/assets/{asset}` | Admin, Manager | Update asset; location change delegates to `UpdateAssetLocation` Action |
| `PATCH` | `/parts/{part}` | Admin, Manager | Update local fields only; ERP columns are excluded from validation |
| `PATCH` | `/maintenance-requests/{mr}` | Admin, Manager, Tech/Requester (own corrective only) | Edit MR while `pending_review`; immutable once converted/rejected/cancelled |
| `PATCH` | `/admin/users/{user}` | Admin | Update user details; self-update rejected with 422 |
| `POST` | `/admin/users/{user}/reset-password` | Admin | Force-reset password, invalidates all sessions/tokens; self-reset rejected |
| `GET` | `/list-options/{group}` | Everyone (auth-only, not Admin-gated) | Active-only dropdown options for `maintenance_priorities`, `usage_reading_types`, `fa_subclass_type_codes`. Unknown group → 404. Added 2026-07-02 to unblock non-Admin consumers (MR priority pickers, Assets FA-subclass filter) without exposing the Admin-gated `/admin/master-data/*` CRUD endpoints. See `.kilo/plans/1783001396791-admin-lists-dropdowns-cleanup.md`. |
| `GET` | `/dashboard/kpis` | Everyone (auth-only; full payload to all roles) | Rolling 90-day aggregate KPIs (MTBF / MTTR / Failure Rate / PM Compliance / Avg MR & WO Duration) + "Recently Relocated Assets" widget. Complements role-adaptive `GET /dashboard` (Row 1 counts stay there). Failure = `is_failure=true` (a corrective MR a manager classified as a real failure; was `is_preventive=false` until the 2026-07-05 `is_failure` flag landed). MTBF calendar basis; MTTR `assigned→closed`; PM compliance date-triggered only, on-time `wo.closed_at::date ≤ mr.trigger_date`. `DashboardKpiResource` (`$wrap=null`). Handover: `docs/_archive/2026-07-13/legacy/atms/04-technical/DASHBOARD_KPI_HANDOFF.md`. Added 2026-07-03. |
| `POST` | `/auth/change-password` | Auth (any role) | Self-service password change; current password not required (session is trusted). Invalidates all sessions and revokes all tokens → client must re-login. Body: `password`, `password_confirmation`. Audited as `user.password_changed`. Added 2026-07-04. |
| `PATCH` | `/work-orders/{workOrder}/form/fields` | Same gate as the single-field route (`updateExecution`): Admin/Manager until closed/cancelled, assigned Technician until completed | Atomic bulk save of WO form field values — the checklist's Save button. Body `{ fields: [{ id, pre_value?, post_value?, notes? }] }`, max 200. **Partial in both directions**: send only changed fields, and an absent slot key keeps its stored value (same semantics as the singular PATCH). Any validation failure or a duplicate `id` → `422`, nothing written; an `id` not belonging to this form (typically dropped by a template sync since open) → `409`, nothing written. Takes the same `work_order_forms` parent-row lock as `SyncWorkOrderFormToLatest`, so a sync cannot interleave. Returns the full `WorkOrderFormResource` so the client refreshes in one round trip. **One** audit entry per save (`work_order_form.field_values_updated`, before/after keyed by field id, changed fields only; a no-op save writes none) — a new event string the Audit Logs viewer needs a label for. The single-field `PATCH .../form/fields/{field}` is retained. Added 2026-07-28. |
