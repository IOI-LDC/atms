# Work Order Execution Forms (WO Forms)

> **Status:** Specified (pending implementation).

## 1. Concept & Purpose

During Work Order execution, the assigned Technician fills a structured,
configurable **Form** rather than a free-text completion note. The form captures
pre-maintenance values (entered when work starts) and post-maintenance values
(entered at completion) for each configured field.

Different assets get different forms depending on their type. Each form is mapped
to the asset's **`fa_subclass_code`** (from the `fa_subclass_type_codes`
master-data table seeded with 18 ERP codes: MUD MOTOR→MTR, SHOCK SUBS→SHK,
GENERATOR→GEN, etc.).

**Why forms exist:** The client requires structured pre/post-maintenance data
capture per asset type during WO execution. This replaces simple free-text
completion notes with typed, validated fields (boolean, numeric with units, text)
that can be consistently queried, reported on, and synced across work orders for
the same asset type.

## 2. Mapping to Asset Type

A **FormTemplate** maps **1:1** to an active `fa_subclass_code`. There is exactly
one active form template per FA subclass. An asset's type is determined by its
`fa_subclass_code` column referencing the `fa_subclass_type_codes` master-data
table. No new "AssetType" entity is created — the existing ERP subclass codes are
reused directly.

When a Work Order is created for an asset:

- If the asset's `fa_subclass_code` has an **active** FormTemplate, the template
  is snapshotted (copied) into the WO as a `WorkOrderForm`.
- If the asset's `fa_subclass_code` has **no** active FormTemplate, the WO simply
  has no form. Work Order execution proceeds normally without form fields.

## 3. Data Model

Four tables define the WO Forms system. This is the single authoritative data
model — other documents reference it rather than duplicating it.

| Table | Purpose |
|---|---|
| `form_templates` | One active template per `fa_subclass_code` |
| `form_template_fields` | Fields belonging to a template (with a stable `uuid`) |
| `work_order_forms` | Snapshotted instance attached to exactly one Work Order |
| `work_order_form_fields` | Values filled by the Technician during execution |

### `form_templates`

| Column | Type | Description |
|---|---|---|
| `id` | int (PK) | Auto-increment primary key |
| `name` | string | Display name for the template (e.g., "Mud Motor Inspection") |
| `fa_subclass_code` | string | FK to `fa_subclass_type_codes`. Unique among active templates (one active form per subclass). |
| `is_active` | boolean | `true` = available for new WO snapshots; `false` = retired |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

### `form_template_fields`

| Column | Type | Description |
|---|---|---|
| `id` | int (PK) | Auto-increment primary key |
| `form_template_id` | int (FK) | Belongs to `form_templates` |
| `uuid` | string | Stable identifier across template versions — used for sync-to-latest field matching |
| `label` | string | Display label shown to the Technician (e.g., "Hours reading") |
| `field_type` | enum | `boolean`, `numeric`, `text` |
| `has_pre_post` | boolean | `true` = captures both pre-maintenance and post-maintenance values; `false` = single value |
| `unit` | string? | Display-only unit for numeric fields (e.g., "PSI", "°C", "hours"). Null for boolean/text. |
| `is_required` | boolean | Whether the field must be filled before the WO can be completed |
| `sort_order` | int | Display order of fields in the form |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

### `work_order_forms`

| Column | Type | Description |
|---|---|---|
| `id` | int (PK) | Auto-increment primary key |
| `work_order_id` | int (FK, unique) | Attached to exactly one Work Order |
| `form_template_id` | int (FK) | The template this form was snapshotted from |
| `snapshotted_at` | timestamp | When the template was copied into this WO |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

### `work_order_form_fields`

| Column | Type | Description |
|---|---|---|
| `id` | int (PK) | Auto-increment primary key |
| `work_order_form_id` | int (FK) | Belongs to `work_order_forms` |
| `form_template_field_id` | int (FK) | The template field this was created from |
| `pre_value` | string? | Pre-maintenance value (filled when work starts). Used when `has_pre_post = true`. |
| `post_value` | string? | Post-maintenance value (filled at completion). Used for both single-value and pre/post fields. |
| `notes` | string? | Optional free-text notes for this field's value |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

## 4. Field Model

### Field Types

| Type | Storage | Example |
|---|---|---|
| `boolean` | `post_value` ("1"/"0" or true/false) | "Did you clean the item thoroughly?" |
| `numeric` | `pre_value` / `post_value` as decimal strings | "Mud motor hours reading" (with unit "hours") |
| `text` | `pre_value` / `post_value` as strings | "General observations" |

### `has_pre_post` Flag

- **`true`:** Captures a pre-maintenance value and a post-maintenance value.
  - **Pre value** — entered when work starts (WO transitions to `in_progress`).
  - **Post value** — entered at completion (WO transitions to `completed`).
  - Example: "Mud motor hours reading" — the Technician records hours before
    starting work and again after finishing.
- **`false`:** Captures a single value. The value is stored in `post_value` and
  entered once during execution. Example: "Did you clean the item thoroughly?"

### `unit` (Display Only)

For numeric fields, an optional display unit is shown alongside the input (e.g.,
"PSI", "°C", "hours"). The unit is purely for display and carries no conversion
or validation logic.

### `is_required`

When `true`, the field must be filled before the Work Order can transition from
`in_progress` to `completed`. See [§7 Completion Gate](#7-completion-gate) for
the full enforcement rules.

### `sort_order`

Determines the vertical display order of fields in the form. Lower numbers appear
first.

### Stable Field `uuid`

Every `form_template_field` carries a `uuid` that remains constant across
template edits. When the template is edited (fields added, removed, or reordered)
and a WO's form is later synced to the latest version, the `uuid` is used to
match fields:

- Same `uuid` → exists in both old and new version → preserve filled values.
- `uuid` only in new → newly added field → append with empty values.
- `uuid` only in old → removed from template → drop from the WO's form.

## 5. Pre/Post Value Semantics

### When Pre Values Are Captured

Pre-maintenance values are captured when the Work Order is in `in_progress`
status — after the Technician starts work but before completion. The Technician
or an authorised user (Maintenance Manager, Administrator) fills the pre values
for all fields where `has_pre_post = true`.

### When Post Values Are Captured

Post-maintenance values are captured at completion time (before the WO
transitions to `completed`). For `has_pre_post = true` fields, the post value
is entered alongside the already-filled pre value. For `has_pre_post = false`
fields, the single value is entered at this point (stored in `post_value`).

### Single-Value Fields

Fields with `has_pre_post = false` capture only a single value. The Technician
enters this value once during execution. It is stored in `post_value`; `pre_value`
remains null.

## 6. Snapshot & Sync-to-Latest

### Snapshot on WO Creation

When a Work Order is created (from an approved Maintenance Request), the system
checks if the associated asset's `fa_subclass_code` has an active FormTemplate.
If so, the template and all its fields are **copied** into a new `WorkOrderForm`
record and associated `work_order_form_fields`. The `snapshotted_at` timestamp
records when this copy was made.

This snapshot decouples the WO's form from future template changes — the WO
retains the form exactly as it existed at creation time. If no active template
exists for the asset's subclass, the WO has no form.

### Sync-to-Latest

If the FormTemplate is later updated (fields added, removed, or modified) after a
WO's form was snapshotted, the Work Order detail screen displays a **"Sync to
latest"** banner or button. The user (Manager, Admin, or assigned Technician)
sees an accept/defer prompt:

- **Accept**: The WO's form is updated to match the current template version.
  - Fields matched by `uuid`: unchanged fields keep their filled values.
  - New fields (uuid not in the snapshot): appended as empty.
  - Removed fields (uuid not in the latest template): dropped from the WO form.
- **Defer**: The WO's form stays as-is. The banner remains visible and the option
  is available again later.

The sync is a manual human decision — it is never automatic. This ensures the
Technician's in-progress work is not unexpectedly altered by an admin's template
edit.

**Who can invoke sync-to-latest:**

| Role | Can Sync? |
|---|---|
| Administrator | Yes — any WO |
| Maintenance Manager | Yes — any WO |
| Technician | Yes — assigned WO only |
| Logistics | No |
| Requester | No |

## 7. Completion Gate

A Work Order that has an attached `WorkOrderForm` instance **cannot** transition
from `in_progress` to `completed` unless all **required** form fields are filled.
The gate applies **only** when a form instance exists — WOs for assets whose
subclass has no active template are unaffected.

### What "Required" Means

A field is considered "filled" based on its `has_pre_post` flag:

| `has_pre_post` | Required Values |
|---|---|
| `true` | Both `pre_value` AND `post_value` must be non-null |
| `false` | `post_value` must be non-null |

Only fields with `is_required = true` are checked. Optional fields
(`is_required = false`) may be left empty without blocking completion.

### Enforcement

When the `POST /api/work-orders/{wo}/complete` endpoint is called for a WO with
an attached form, the backend validates:

1. For each `work_order_form_field` where the source template field has
   `is_required = true`:
   - If `has_pre_post = true`: both `pre_value` and `post_value` must be non-null.
   - If `has_pre_post = false`: `post_value` must be non-null.
2. If any required field is unfilled, the endpoint returns `422` with a message
   indicating which fields must be completed. The WO remains `in_progress`.
3. If all required fields are filled, the transition proceeds normally.

## 8. Role Permissions

### Template Management

| Capability | Administrator | Maintenance Manager | Technician | Logistics | Requester |
|---|---|---|---|---|---|
| Create / edit form templates | Yes | No | No | No | No |
| Deactivate / reactivate form templates | Yes | No | No | No | No |
| Add / edit / reorder / remove fields | Yes | No | No | No | No |

Template management is exclusive to the Administrator. The Admin UI is accessed
via the **"WO Forms" tab** under the Admin sidebar item (route: `/admin?tab=wo-forms`).

### Filling Form Values

| Capability | Administrator | Maintenance Manager | Technician | Logistics | Requester |
|---|---|---|---|---|---|
| Fill pre values (own/any WO) | Yes | Yes | Assigned only | No | No |
| Fill post values (own/any WO) | Yes | Yes | Assigned only | No | No |

- The **assigned Technician** fills pre and post values for their assigned WOs.
- A Maintenance Manager or Administrator may fill values for any WO (e.g., when
  covering for a Technician or performing an operational review).
- Filling is done through the Work Order Detail screen's **WO Form** section.

### Sync-to-Latest

| Capability | Administrator | Maintenance Manager | Technician | Logistics | Requester |
|---|---|---|---|---|---|
| Sync WO Form to latest template | Yes | Yes | Assigned only | No | No |

## 9. Out-of-Scope Sub-Items

The following are **explicitly NOT** part of the WO Forms feature:

- **Mandatory photo checklists** — forms contain typed values only; no photo
  capture per field.
- **Pass/fail scoring** — boolean fields are simple true/false flags, not scored.
- **Checklist approvals** — there is no separate approval step for form values;
  the completion gate is the only enforcement.
- **Checklist-based defect generation** — form values do not automatically create
  corrective MRs or trigger workflows.
- **Multi-form-per-type** — locked to exactly one active FormTemplate per
  `fa_subclass_code`. Assets of the same type always get the same form.
- **Form versioning history** — only the latest template version matters; there
  is no audit trail of past template versions (only current + snapshot).
- **Conditional field logic** — no field visibility rules or skip logic based on
  other field values. All fields are always shown.
- **Validation rules beyond required/optional** — no min/max values, regex
  patterns, or cross-field validation.

A simple Work Order completion note remains available for all WOs regardless of
whether a form exists.

## 10. Cross-References

| Document | Relevance |
|---|---|
| [PRD](./PRD.md) | Product scope — WO Forms is listed in ATMS owns and In-Scope |
| [IN_SCOPE.md](./IN_SCOPE.md) | §21. Work Order Execution Forms — detailed in-scope description |
| [OUT_OF_SCOPE.md](./OUT_OF_SCOPE.md) | §10. Advanced Checklist Management — updated to reflect WO Forms reversal |
| [STATUS_MODEL.md](../../03-backend/STATUS_MODEL.md) | Work Order status transitions with completion gate |
| [RBAC.md](../../03-backend/RBAC.md) | Permission matrix entries for WO Forms |
| [ROLES_AND_PERMISSIONS.md](./ROLES_AND_PERMISSIONS.md) | Role-based prose permissions for WO Forms |
| [NAVIGATION.md](../02-design/NAVIGATION.md) | Admin tab: "WO Forms" |
| [SCREEN_INVENTORY.md](../02-design/SCREEN_INVENTORY.md) | Admin WO Forms screen + WO Detail form section |
| [FORM_REQUIREMENTS.md](../04-frontend/FORM_REQUIREMENTS.md) | Form builder + execution form requirements |
| [ROUTES.md](../04-frontend/ROUTES.md) | Admin `?tab=wo-forms` route entry |
| [BACKEND_API_REFERENCE.md](../04-technical/BACKEND_API_REFERENCE.md) | API endpoints for WO Forms |
| [BACKEND_API_HANDOFF.md](../04-technical/BACKEND_API_HANDOFF.md) | WO Forms data flow summary |
