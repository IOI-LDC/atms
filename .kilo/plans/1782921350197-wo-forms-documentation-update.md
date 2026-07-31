# Plan — WO Forms: Documentation Update

> **Scope of this plan:** Documentation changes ONLY. No source code, migrations, routes, or UI edits. One new doc file + updates to existing authoritative docs so the **WO Forms** feature is correctly specified, scoped, and removed from the "out of scope" exclusions where it currently lives.

---

## 1. Background & Agreed Design (context for the doc author)

A new **WO Form** feature is being introduced (client-requested, so it overrides the prior "Advanced Checklist Management = out of scope" decision).

**Feature summary (already agreed with the user):**

- During Work Order execution, the technician fills a structured **Form** (not just a free-text completion note). Different assets get different forms.
- "Asset type" = the asset's existing **`fa_subclass_code`** (referencing the `fa_subclass_type_codes` master-data table seeded with 18 ERP codes: MUD MOTOR→MTR, SHOCK SUBS→SHK, GENERATOR→GEN, etc.). **No new "AssetType" entity is created.**
- A **FormTemplate** maps **1:1** to an `fa_subclass_code` (one active form per subclass). A WO for an asset whose subclass has no active template simply has no form.
- Fields support three types: **boolean**, **numeric** (optional display `unit`, e.g. PSI/°C/hours), **text**.
- Each field has a `has_pre_post` flag:
  - `true` → captures a **pre-maintenance** value (entered when work starts) AND a **post-maintenance** value (entered at completion). Example: "Mud motor hours reading".
  - `false` → captures a **single** value (entered once during execution). Example: "Did you clean the item thoroughly?".
- **Lifecycle:** on WO creation the current form template is **snapshotted (copied)** into the WO. If the template later changes, the WO detail screen offers a **"Sync to latest"** button with an accept/defer prompt (new fields appended empty; removed fields dropped; unchanged fields keep their values; matched by a stable per-field UUID).
- **Completion gate:** a WO cannot transition `in_progress → completed` unless its attached form instance (if any) has all **required** fields filled — for `has_pre_post` fields both pre & post; for single fields the single value. Gate applies **only when a form instance exists**.
- **Permissions:** only **Administrator** manages form templates (create/edit/deactivate). Filling pre/post values is done by the **assigned Technician**, **Maintenance Manager**, or **Administrator**.
- **Admin UI:** a new **"WO Forms" tab** is added to the existing Admin tab shell (`AdminView.vue`), route `/admin/wo-forms`, Admin-only. (Documented here; implemented separately.)

**Data model (documented in `WO_FORMS.md`):**

```
form_templates            (id, name, fa_subclass_code [unique among active], is_active, timestamps)
form_template_fields      (id, form_template_id, uuid [stable id across versions], label, field_type enum, has_pre_post bool, unit nullable, is_required bool, sort_order, timestamps)
work_order_forms          (id, work_order_id [unique], form_template_id, snapshotted_at, timestamps)
work_order_form_fields    (id, work_order_form_id, form_template_field_id, pre_value nullable, post_value nullable, notes nullable)
```

Value semantics: `has_pre_post=true` uses both `pre_value` & `post_value`; `has_pre_post=false` uses only `post_value`.

---

## 2. Authoring conventions

- Match the tone, heading style, and cross-reference link pattern of sibling feature specs (`ASSET_BOOKING.md`, `ASSET_TAG.md`, `ASSET_ASSEMBLY.md`).
- Use relative Markdown links to other `docs/` files.
- Keep the existing UK/EN spelling already used across the docs.
- Do NOT duplicate the full data model in every file — define it once in `WO_FORMS.md` and reference it.
- Preserve all existing content; only add/adjust the specific items listed below.

---

## 3. Tasks (ordered)

### Task 1 — CREATE `docs/atms/01-product/WO_FORMS.md`
New authoritative feature spec. Sections:
1. **Concept & purpose** — configurable pre/post-maintenance form filled during WO execution; mapped to the asset's `fa_subclass_code`.
2. **Mapping to asset type** — FormTemplate 1:1 with an active `fa_subclass_code`; references the `fa_subclass_type_codes` master-data table. WOs for assets with no mapped template have no form.
3. **Data model** — the four tables above with columns and relationships (mirror the table style used in `STATUS_MODEL.md` Asset Booking section).
4. **Field model** — field types (boolean/numeric/text), `unit` (display only), `has_pre_post` flag, `is_required`, `sort_order`, stable per-field `uuid`.
5. **Pre/Post value semantics** — when pre values are captured (at execution start / `in_progress`) vs post values (at completion); single-value fields.
6. **Snapshot + sync-to-latest** — copy-on-WO-create; `snapshotted_at`; "Sync to latest" banner/button with accept/defer; merge rules (match by field `uuid`; preserve filled values; drop removed; append new empty).
7. **Completion gate** — `in_progress → completed` blocked until all required fields filled; conditional on form instance existing; define what "required" means per `has_pre_post`.
8. **Role permissions** — Admin manages templates; assigned Technician / Manager / Admin fill values.
9. **Out-of-scope sub-items** — explicit list of what this feature is NOT: mandatory photo checklists, pass/fail scoring, checklist approvals, checklist-based defect generation, multi-form-per-type (locked to 1:1 active per subclass).
10. **Cross-references** — link to PRD, IN_SCOPE, OUT_OF_SCOPE, STATUS_MODEL, RBAC, FORM_REQUIREMENTS, NAVIGATION, BACKEND_API_REFERENCE.

### Task 2 — UPDATE `docs/atms/01-product/OUT_OF_SCOPE.md` (§10, lines 55–59)
Reword §10 "Advanced Checklist Management" so it reflects the client-requested reversal:
- State that a **configurable Work Order execution form (WO Form)** is now **in scope** (client-requested) — boolean/numeric/text fields with pre/post capture, mapped by FA subclass, with snapshot + sync. Link to `WO_FORMS.md`.
- Keep as **still excluded**: mandatory photo checklists, pass/fail scoring, checklist versioning approvals, checklist-based defect generation, and any form engine beyond the documented WO Form scope.
- Keep the note that a simple WO completion note remains available.

### Task 3 — UPDATE `docs/atms/01-product/PRD.md`
- **Out-of-Scope Summary** (line 114, `- Advanced checklist management`): remove/replace with a note pointing to the reworded `OUT_OF_SCOPE.md` §10 (i.e., the configurable WO Form is now in scope; only the advanced sub-items remain excluded).
- **ATMS owns** list (lines 43–55): add a bullet `- Work Order execution forms (configurable pre/post-maintenance forms mapped by FA subclass)`.
- **In-Scope Summary** (lines 82–100): add `- Work Order execution forms (configurable pre/post-maintenance forms, mapped by FA subclass)`.

### Task 4 — UPDATE `docs/atms/01-product/IN_SCOPE.md`
- Add a new numbered section (e.g., **§21. Work Order Execution Forms**) describing: configurable forms per `fa_subclass_code`, boolean/numeric/text, pre/post capture, snapshot+sync, completion gate. Link to `WO_FORMS.md`.
- In **§10 Work Order Management** and **§12 Work Order Closure**: add a one-line note that completion requires the attached WO Form (if any) to be fully filled.

### Task 5 — UPDATE `docs/00-project-rules/authoritative-sources.md`
- After line 8's existing checklist clause, add a **locked boundary** for WO Forms: the configurable WO Form is in scope but limited to boolean/numeric/text field types with optional unit, a single active form per FA subclass, pre/post capture, and snapshot+sync; mandatory photo checklists, scoring, approvals, and defect generation remain out of scope. (This keeps the "unless present in the written PRD" logic accurate — it now IS in the PRD.)

### Task 6 — UPDATE `docs/00-project-rules/SCOPE_CHANGE.md`
- Add a row to **§2.2 Added Modules** for **WO Execution Forms** (description + rationale: client needs structured pre/post-maintenance data capture per asset type during WO execution; overrides the prior checklist exclusion).
- Optionally add a note in **§1 High-Level Changes** table.

### Task 7 — UPDATE `docs/atms/01-product/WORKFLOWS.md`
- **Corrective Maintenance Workflow** (steps 7–10): insert steps — after work starts, capture **pre-maintenance form values**; at completion, capture **post-maintenance form values**; note the completion gate (cannot complete until required form fields filled). Apply to the asset's mapped WO Form only.
- **Preventive Maintenance Workflow** (steps 9–12): apply the same insertions.

### Task 8 — UPDATE `docs/03-backend/STATUS_MODEL.md`
- **`completed`** Work Order status section (lines 46–50): add that, when a Work Order has an attached WO Form instance, the form must be fully filled (all required fields, incl. pre & post per `has_pre_post`) before the transition to `completed` is allowed.

### Task 9 — UPDATE `docs/03-backend/RBAC.md`
- **Permission Matrix** (lines 30–79): add rows:
  - `Manage WO Form templates (create/edit/deactivate/reactivate)` — Administrator only.
  - `Fill WO Form pre/post values (own/any WO)` — Manager/Admin Yes; Technician "Assigned only"; others No.
  - `Sync WO Form to latest template version` — Manager/Admin Yes; Technician "Assigned only".
- **Important Rules**: add a bullet stating the completion gate (form fully filled → `completed`) and that template management is Admin-only while filling is assigned-technician/admin/manager.

### Task 10 — UPDATE `docs/atms/01-product/ROLES_AND_PERMISSIONS.md`
- Under **Administrator**: add "Manages Work Order execution form templates (per FA subclass)."
- Under **Maintenance Manager** & **Technician**: note they fill pre/post values on WO forms (Technician: assigned WO only).
- Under **Permission Principles**: add bullets matching Task 9 (completion gate; template mgmt = Admin; filling = assigned tech/admin/manager).

### Task 11 — UPDATE `docs/atms/02-design/NAVIGATION.md`
- **§7 Admin** tabs table (lines 153–157): add a `WO Forms` row (Admin only).
- Add a bullet describing the "WO Forms" tab (create/edit/deactivate form templates per FA subclass, manage fields/field types/pre-post flags).

### Task 12 — UPDATE `docs/atms/02-design/SCREEN_INVENTORY.md`
- **§7 Admin**: add a new subsection (e.g., **§7d. WO Forms**) — list of form templates; columns (name, FA subclass, field count, active/inactive); create/edit via side sheet; field builder (label, type, unit, has_pre_post, required, order); deactivate (not delete). Admin only.
- **§3 Drill-down: Work Order Detail** (lines 101–105): add a "WO Form" section to the listed sections (pre/post values, sync-to-latest banner when template changed).

### Task 13 — UPDATE `docs/atms/04-frontend/ROUTES.md`
- **Admin** tab table (lines 129–133): add a `wo-forms` tab (Admin only) with a one-line description.

### Task 14 — UPDATE `docs/atms/04-frontend/FORM_REQUIREMENTS.md`
- Add **"WO Form Builder (Admin)"** section — fields/controls for template + field definition (name, FA subclass select, field rows: label, type [boolean/numeric/text], unit, has_pre_post toggle, required toggle, order).
- Add **"WO Form Execution (pre/post values)"** section — how the technician fills pre values during execution and post values at completion; sync-to-latest prompt flow; read-only after completion.
- Update the existing **"Work Order Completion Form"** section (lines 42–59): add a note that, if the WO has an attached WO Form, completion also requires the form to be fully filled (the gate).

### Task 15 — UPDATE `docs/atms/04-technical/BACKEND_API_REFERENCE.md`
- **Table of Contents** (lines 7–33): add `- [Admin: WO Forms](#admin-wo-forms)` and `- [Work Order Forms](#work-order-forms)`.
- Add a **"Work Order Forms"** section: endpoints to fetch the WO's form instance and to submit/update pre & post field values (assigned tech/admin/manager).
- Add a **"Sync WO Form to latest template version"** endpoint (accept/defer).
- Add an **"Admin: WO Forms"** section: template CRUD (create, list, show, update, deactivate/reactivate), field management (add/edit/reorder/remove), all Admin-only.
- Annotate the existing **Work Order complete** endpoint with the form completion-gate condition (422 if required form fields are unfilled).

### Task 16 — UPDATE `docs/atms/04-technical/BACKEND_API_HANDOFF.md`
- Add a short subsection on the WO Forms data flow (template snapshot at WO create; pre/post submit; sync-to-latest; completion gate) consistent with the patterns already documented (validate → confirm → submit → toast, §7.2).

### Task 17 — UPDATE `frontend/src/content/user-manual.md`
- **§1.2 What ATMS Does Not Do** (lines 64–65): reword "Advanced checklist management" — note that configurable **WO Forms** are now included (pre/post-maintenance capture per asset type); keep excluded the advanced items (photos, scoring).
- **§8 Work Orders**: add a **§8.x Work Order Execution Form** subsection — what it is, when pre/post values are captured, the completion gate, and the sync-to-latest prompt.
- Add an **Admin: managing WO Forms** subsection (under the Admin area) describing template + field management per FA subclass.

### Task 18 (optional/lower priority)
- `docs/atms/02-design/SCREEN_COPY_GUIDE.md` — add terminology: "Form", "Pre-maintenance value", "Post-maintenance value", "FA subclass", "Snapshot", "Sync to latest".
- `docs/README.md` — add `WO_FORMS.md` to the "Key Documents" table if it enumerates ATMS feature specs.
- `docs/05-delivery/IMPLEMENTATION_PLAN.md` & `docs/05-delivery/MILESTONES.md` — add a delivery milestone/entry for "WO Execution Forms".
- **Leave `docs/PHASE_1_GAP_ANALYSIS.md` as-is** (historical point-in-time record).

---

## 4. Risks / consistency checks

- **Reversal consistency:** every place that currently lists "checklist/form" as out of scope must be reconciled (Tasks 2, 3, 5, 17). After edits, grep `checklist` across `docs/` + `frontend/src/content/user-manual.md` to confirm no stale "out of scope" contradiction remains (note: benign usages like "setup checklist" / "scope checklist" in deployment/notifications docs are unrelated and must NOT be changed).
- **One source of truth:** the data model lives only in `WO_FORMS.md`; other files reference it.
- **Gate is conditional:** always state the gate applies only when a form instance exists (asset's `fa_subclass_code` has an active template).
- **1:1 active form per subclass:** state the unique-active constraint consistently.

---

## 5. Validation (how to verify the doc update is complete & correct)

1. `grep -ri "checklist" docs/ frontend/src/content/user-manual.md` — confirm all WO-related checklist exclusions are reconciled; only benign setup/scope-checklist usages remain.
2. `grep -ri "out of scope\|out-of-scope" docs/atms/01-product/PRD.md docs/atms/01-product/OUT_OF_SCOPE.md` — confirm WO Form is no longer contradictory.
3. Cross-link check: every new mention of `WO_FORMS.md` resolves to the created file; relative links valid.
4. Internal consistency: completion-gate wording identical across `STATUS_MODEL.md`, `WORKFLOWS.md`, `RBAC.md`, `ROLES_AND_PERMISSIONS.md`, `FORM_REQUIREMENTS.md`, `BACKEND_API_REFERENCE.md`, and `user-manual.md`.
5. Permission matrix in `RBAC.md` matches prose in `ROLES_AND_PERMISSIONS.md` for WO Forms.
6. `ROUTES.md`, `NAVIGATION.md`, `SCREEN_INVENTORY.md` all list the `WO Forms` admin tab consistently (label + Admin-only + route/`?tab=` key).

---

## 6. Out of scope for this plan

- Any backend (models, migrations, controllers, routes, policies, enums, tests) or frontend (Vue components, router, stores) implementation.
- Changes to the `fa_subclass_type_codes` table or the Asset model.
- ErpSync, notifications, or Power Automate changes.
