# RBAC Design

## Roles

- Administrator
- Maintenance Manager
- Technician
- Logistics
- Requester

The legacy **Viewer** role has been merged into **Requester**. All users are
Requesters at minimum. Each user has exactly one role. Authorization must be
implemented through Laravel policies. A granular permission package, multiple
roles per user, and runtime-configurable permission assignment are excluded from
MVP.

The five human roles are seeded, immutable system data. Administrators may assign
these roles to users but may not create, rename, or delete roles.

The **service** role (`service@atms.internal`) is a sixth, non-human role for
machine-to-machine (M2M) API token authentication. It is never assigned to
human users, never logs in via SPA, and is seeded as an immutable system user.
Service tokens use ability-based access control (read/write) via the
`EnsureTokenAbilities` middleware. Frontend
navigation, record queries, and actions must reflect the same backend policy
rules.

## Permission Matrix Draft

| Capability | Administrator | Maintenance Manager | Technician | Logistics | Requester |
|---|---:|---:|---:|---:|---:|
| View dashboard | Yes | Yes | Yes | Limited | Yes |
| View assets | Yes | Yes | Yes | Yes | Yes |
| View asset maintenance history | Yes | Yes | Yes | No | Yes |
| View location history | Yes | Yes | Yes | Yes | Yes |
| View asset attachments | Yes | Yes | Yes | No | Yes |
| View mapped ERP reference fields | Yes | Yes | Yes | Yes | Yes |
| View raw ERP payload | Yes | No | No | No | No |
| Update asset location | Yes | Yes | No | Yes | No |
| Book / unbook asset | Yes | Yes | No | Yes | No |
| Add meter readings | Yes | Yes | Yes | No | Unverified only |
| Confirm meter readings | Yes | Yes | Yes | No | No |
| View parts reference | Yes | Yes | Yes | No | Yes |
| Create corrective MR | Yes | Yes | Yes | No | Yes |
| Create asset | Yes | Yes | No | No | No |
| Install component | Yes | Yes | Assigned WO only | No | No |
| Remove component | Yes | Yes | Assigned WO only | No | No |
| Swap component | Yes | Yes | Assigned WO only | No | No |
| Change asset_kind | Yes | Yes | No | No | No |
| View assembly history | Yes | Yes | Yes | Yes | Yes |
| Create MR for child component from parent WO | Yes | Yes | No | No | No |
| Update asset | Yes | Yes | No | No | No |
| Update part | Yes | Yes | No | No | No |
| Update own pending MR | Yes | Yes | Yes | No | Yes |
| Update any pending MR | Yes | Yes | No | No | No |
| Review/approve MR | Yes | Yes | No | No | No |
| Cancel own pending corrective MR | Yes | Yes | No | No | Yes |
| Cancel any pending MR | Yes | Yes | No | No | No |
| Create WO directly | No | No | No | No | No |
| Update assigned WO | Yes | Yes | Yes | No | No |
| Edit non-terminal WO execution details | Yes | Yes | Assigned only | No | No |
| Set asset operational status via WO | Yes | Yes | Assigned only | No | No |
| Assign/reassign WO | Yes | Yes | No | No | No |
| Mark assigned WO completed | Yes | Yes | Assigned only | No | No |
| Close completed WO | Yes | Yes | No | No | No |
| Cancel non-closed WO | Yes | Yes | No | No | No |
| Manage WO Form templates (create/edit/deactivate/reactivate) | Yes | No | No | No | No |
| Fill WO Form pre/post values (own/any WO) | Yes | Yes | Assigned only | No | No |
| Sync WO Form to latest template version | Yes | Yes | Assigned only | No | No |
| Manage PM templates (create/edit/deactivate/reactivate) | Yes | No | No | No | No |
| View PM templates | Yes | Yes | No | No | No |
| Assign PM templates to assets; deactivate/reactivate/evaluate assignments | Yes | Yes | No | No | No |
| Manage users | Yes | No | No | No | No |
| View user list (for WO assignment) | Yes | Yes | No | No | No |
| Import SharePoint employees | Yes | No | No | No | No |
| Provision employee as user | Yes | No | No | No | No |
| Manage locations/master data | Yes | No | No | No | No |
| Run ERP parts sync | Yes | Yes | No | No | No |
| Manage ERP sync settings | Yes | No | No | No | No |
| Update user details | Yes | No | No | No | No |
| Reset user password (admin) | Yes | No | No | No | No |
| View technical audit logs | Yes | No | No | No | No |

## Important Rules

- Work Orders are created only from approved Maintenance Requests.
- Maintenance Manager approval is required before WO creation.
- ERP-sourced part fields (`erp_part_id`, `erp_part_code`, `erp_status`, `erp_raw_data`, `erp_last_synced_at`) are read-only and managed by ERP parts sync (owned by SM). Local part fields (`name`, `description`, `unit_of_measure`, `category`, `is_active`) may be updated by Admin/Manager.
- Local operational fields may be editable according to permissions.
- Requesters may view their own submitted Maintenance Requests.
- Requesters may cancel only their own user-created corrective Maintenance Requests while `pending_review`.
- Maintenance Manager and Administrator may cancel any `pending_review` Maintenance Request.
- System-generated preventive Maintenance Requests may be cancelled only by Maintenance Manager or Administrator.
- A pending_review Maintenance Request may be updated by its creator or an Admin/Manager. Editable fields: description, priority, and asset_id. The update does not change the MR status.
- Technicians may update their own pending corrective Maintenance Requests.
- Admin/Manager may update any pending Maintenance Request.
- Requesters may search active assets and view asset fields to create a Corrective Maintenance Request.
- Requesters may view asset maintenance history, location history, and attachments (merged-in Viewer capabilities).
- Raw ERP payloads are Administrator-only.
- Other roles with asset or part access receive mapped ERP reference fields only.
- Technicians may update only Work Orders assigned to them unless a final approved policy states otherwise.
- Only Administrator or Maintenance Manager may assign or reassign Work Orders.
- Work Orders may be assigned only to active Technician users.
- Assignment is required before transition to `in_progress`.
- Administrator and Maintenance Manager may edit execution details on non-closed, non-cancelled Work Orders for operational recovery.
- Technician execution edits remain limited to assigned Work Orders before completion.
- Every execution-detail change by any role must be written to the technical audit log with redacted before/after context.
- Technicians may mark eligible assigned Work Orders as completed.
- Only Maintenance Managers and Administrators may close completed Work Orders.
- Completed Work Orders are locked against Technician execution edits.
- **WO Form completion gate:** When a Work Order has an attached WO Form instance, the WO cannot transition `in_progress → completed` unless all required form fields are fully filled: for `has_pre_post = true` fields both pre and post values must be present; for single-value fields (`has_pre_post = false`) the post value must be present. Only fields with `is_required = true` are enforced. Template management (create/edit/deactivate/reactivate) is Administrator-only. Filling pre/post values is the role of the assigned Technician, Maintenance Manager, or Administrator.
- Closed Work Orders are permanently immutable and cannot be reopened.
- Administrator and Maintenance Manager may cancel `open`, `in_progress`, or `completed` Work Orders with a required reason.
- Technicians cannot cancel Work Orders.
- Logistics, Maintenance Manager, and Administrator may update asset physical location (via AM subsystem).
- Administrator, Maintenance Manager, and Logistics may toggle an asset's booking state (`is_booked`). Booking is an availability marker used by Operations to reserve an asset for a specific Job/Project. It auto-clears when the asset's location changes or the asset is deactivated/inactivated. Booking does not gate any maintenance workflow (MR/WO/PM).
- Admin/Manager may create asset records manually and update asset operational fields at any time.
- Only Administrator or Maintenance Manager may change an asset's asset_kind.
- Only Administrator or Maintenance Manager may directly set parent_asset_id outside of a Work Order.
- The assigned Technician may execute component install, remove, or swap as part of an assigned, non-terminal Work Order.
- A component must be Active/Ready (parent_asset_id IS NULL) before it can be installed.
- Installing a component sets its sub-status to Installed; removing it sets it to Ready (or an Inactive sub-status if decommissioned).
- "Create MR for Component" from the parent WO screen is available to Administrator and Maintenance Manager only.
- Assembly history is viewable by any authenticated user who has view access to the component asset.
- The assigned Technician (or Admin/Manager) may update an asset's `operational_status` through the Work Order-scoped endpoint while the Work Order is non-terminal; all other asset fields remain Admin/Manager-only.
- Updating an asset's `current_location_id` records a location history entry in AM tables automatically.
- Admin may update user details (name, email, role, active status) and reset user passwords.
- Admin cannot update or reset their own account through the admin endpoints.
- Logistics has no maintenance approval, Work Order execution, PM Rule, or administration permissions.
- Logistics has no Parts Reference access in MVP (parts belong to SM).
- Logistics authority is limited to asset physical location updates and location history (owned by AM).
- It does not include gate passes, shipments, transport documents, delivery notes, handovers, custody workflows, transfer approvals, chain-of-custody workflows, or other logistics modules.
- Requester-submitted meter readings remain unverified.
- Only Administrator, Maintenance Manager, or Technician may confirm a meter reading.
- Only confirmed meter readings update current meter values or participate in PM calculations.
- PM rules are reusable schedule **templates** (M:N). Template lifecycle (create, edit, deactivate, reactivate) is Administrator-only (`PmRulePolicy`). Assigning a template to an asset and evaluating/deactivating/reactivating an assignment (`AssetPmAssignmentPolicy`) is Administrator **+ Maintenance Manager**. A retired template (`is_active = false`) stops all PM evaluation for its assignments without deactivating the assignments themselves.
- Cumulative maintenance: when a higher-level PM work order (L2/L3/L4) closes, the baselines of all active lower-level PM **assignments** (L1, etc.) on the same asset are reset. This applies only to the standard L1-L4 level scheme; custom free-text levels are independent.

> **Resolved — Maintenance Manager PM workflow is complete (verified 2026-06-27):**
> The Maintenance Manager performs all PM **assignment** operations (assign,
> evaluate, deactivate, reactivate) from the **Asset Detail → PM Rules section**
> (`AssetPmSection.vue`), gated by `:can-manage="auth.isAdminOrManager"`. The
> "Assign Rule" dialog lists all active templates via `loadActiveTemplates()`, so
> the Manager never needs to visit the template list page. Template names in the
> assignment table are deep-links to `/admin/pm-rules/:ruleId`, which is guarded
> `requiresAdminOrManager` — so the Manager can view individual template details
> (schedule, coverage, MR history).
>
> **Template creation and editing** (create/edit/deactivate/reactivate) remains
> **Administrator-only** by design (`PmRulePolicy::create/update/deactivate/
> reactivate`). This is the correct scope boundary: defining schedule templates is
> a system-configuration task owned by the Administrator; the Maintenance Manager
> consumes and assigns those templates per asset.
>
> No UI changes are required. The earlier "agreed direction" (grant Manager full
> Admin-area access) is **superseded** — the Manager's PM workflow is fully served
> from Asset Detail. See `docs/PHASE_1_GAP_ANALYSIS.md` §8 for the code evidence.
- Only Administrator may create, edit, activate, or deactivate location definitions and master-data values.
- Logistics and Maintenance Manager may select existing active locations when recording asset location changes.
- Administrator and Maintenance Manager may trigger manual ERP parts sync runs.
- Only Administrator may manage ERP connection, credentials, adapter selection, and synchronization schedule settings.
- Only Administrator may view technical audit logs in MVP.
- Only Administrator may import SharePoint employees or provision them as users.
- Employee import does not grant application access.
- Users set their own passwords through one-time activation/reset links; self-registration is disabled.
