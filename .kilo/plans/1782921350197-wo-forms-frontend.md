# Plan — WO Forms: Frontend Implementation (v2, spec-aligned)

> Implementation-ready plan for the Vue 3.5 + TypeScript + Vite + Tailwind + shadcn-vue + Pinia frontend at `frontend/`.
> **Reconciled to the authoritative spec** `docs/atms/01-product/WO_FORMS.md` and `docs/atms/04-technical/BACKEND_API_REFERENCE.md` (WO Forms sections). Consumes the reconciled backend plan `…wo-forms-backend.md` (v2).
>
> Review-driven deltas vs the earlier draft: **templates under `/api/admin/wo-forms`**; **4 separate field CRUD endpoints** (per-field, immediate); **per-field PATCH value submission**; **completion gate = 422** (with `missing` list); **sync-to-latest is IN SCOPE**; **Admin-only** templates; **self-contained** snapshot fields; no `description` column.

---

## Context (agreed design)

- Admins manage **WO Form templates** (one active per FA subclass) in a new **Admin → "WO Forms"** tab. Template metadata = `name` + `fa_subclass_code` only. Fields are managed **per-field** (add/update/delete/reorder) with immediate API calls.
- On a **Work Order detail** screen, if the WO has an attached form instance, a **"WO Form"** data-card lets the assigned Technician / Admin / Manager capture **pre** values (during `in_progress`) and **post** values (at completion). Single-value fields capture one value (stored in `post_value`).
- Values are **saved per-field** (PATCH one field at a time, e.g. on blur).
- The form is **read-only** once the WO is terminal (`completed`/`closed`/`cancelled`).
- **Sync-to-latest**: when the template changed after the WO's snapshot, a banner offers Accept (re-sync) / Defer (dismiss for this session). Defer also calls the backend defer endpoint.
- The Complete action surfaces the backend **422** completion gate — toast the message + highlight the missing fields — so the user finishes the form first.

### Key codebase conventions (verified, match these)
- **Admin tab shell** = `src/views/admin/AdminView.vue` (path-driven `activeTab`, a `tabs[]` array, and a `v-if`/`v-else-if` chain rendering the child view inline — NOT nested `<RouterView>`). Child tab views render only page content (no `<AppLayout>`).
- **PM Rules reference**: `src/views/pm-rules/PmRulesView.vue`, `src/components/pm-rules/PmRuleForm.vue`, `src/composables/usePmRules.ts`.
- **Frontend UX authority**: `docs/atms/04-frontend/FORM_REQUIREMENTS.md` §"WO Form Builder" and §"WO Form Execution (Pre/Post Values)" (lines 237–308) — toggle for required, read-only after completion, 422 gate with field-level details. Align all UX to these sections.
- **API layer**: `src/lib/api.ts` (`api.get/post/patch/delete`), lists via `fetchList<T>()`, `ApiError.validationErrors` (422 only). 409/403/422 surface via `ApiError` (status + `message`; `validationErrors` on 422).
- **Data table**: `src/components/app/AppDataTable.vue` + `AppColumnDef<T>`, `#cell` slot per column.
- **Side sheet pattern**: shadcn `Sheet` right-side `:modal="false"`; props `open/editing/saving/validationErrors`; `watch(open)` reset; `emit('save', payload)`; per-field `<p class="form-error">{{ validationErrors.x?.[0] }}</p>`.
- **Confirm dialogs**: local `<Dialog>` + `*Open` ref + `confirmX()`; toasts via `import { toast } from 'vue-sonner'`.
- **WO detail** = `src/views/work-orders/WorkOrderDetailView.vue` + `src/composables/useWorkOrderDetail.ts`. Execution actions follow an `openX()`/`doX()` pair pattern; sections are stacked `<div class="data-card">` blocks.
- **Auth store**: `isAdmin`, `isAdminOrManager`, `isTechnician`. Route guards use `meta.requiresAdmin`.
- **⚠️ Sidebar**: `AppSidebar.vue` Admin `isActiveFor` is an explicit path list — `/admin/wo-forms` will NOT auto-highlight Admin; extend the predicate.

---

## Decisions (locked)

- **No separate detail route in v1** — template metadata edited via a side sheet; fields managed via a dedicated **"Manage Fields"** sheet opened from the list row (no `WoFormDetailView.vue`).
- **Field management = per-field, immediate** API calls (add/update/delete/reorder), NOT a nested-array save. Matches the spec's 4 field endpoints.
- **Value submission = per-field PATCH** (autosave on blur), not a batch save.
- **Completion gate UX** — on 422, toast the message and set a `missingFields` ref (set of uuids/slots) the form section uses to highlight missing inputs; keep the Complete dialog open. No frontend pre-flight duplicate of the gate (backend is authoritative).
- **Sync-in-scope** — banner with Accept (calls `/form/sync`, reload) / Defer (calls `/form/defer-sync`, hides banner for the session via a local ref).
- **Admin-only** — templates tab + all template/field management gated by `auth.isAdmin` (Managers cannot view templates; they won't see the tab).
- **Boolean rendering** — checkbox tri-state on the draft (`null` untouched vs `true`/`false` answered); only send the slot when changed so a `false` answer is preserved while an untouched required field correctly stays unfilled (→ gate 422).

---

## Ordered tasks

1. **Types** — in `src/types/index.ts` add:
   - `WoFormFieldType = 'boolean' | 'numeric' | 'text'`
   - `WoFormTemplateField { id: number; uuid: string; label: string; field_type: WoFormFieldType; has_pre_post: boolean; unit: string|null; is_required: boolean; sort_order: number }`
   - `WoFormTemplate { id; name; fa_subclass_code; is_active: boolean; fields?: WoFormTemplateField[]; created_at: string }`  *(no `description`)*
   - `WoFormFieldValue` (self-contained snapshot + captured values): `{ id; uuid; label; field_type: WoFormFieldType; has_pre_post: boolean; unit: string|null; is_required: boolean; sort_order: number; pre_value: string|null; post_value: string|null; notes: string|null }`
   - `WoFormInstance { id; form_template_id: number|null; snapshotted_at: string; template_is_stale?: boolean; sync_dismissed_at?: string|null; fields: WoFormFieldValue[] }`  (*`template_is_stale` matches the API ref field name; `true` = a newer template exists → show the sync banner*)
   - `MissingField { uuid: string; label: string; missing: ('pre'|'post')[] }` (422 gate payload).
   - Extend `WorkOrder` with `form?: WoFormInstance | null`.
2. **Composable** — `src/composables/useWoForms.ts` (factory, no Pinia) mirroring `usePmRules.ts`:
   - `templates/template` refs + `loading/error`, `saving`, `validationErrors`.
   - `loadTemplates(force=false)` via `fetchList<WoFormTemplate>('/admin/wo-forms/templates')`; `loadTemplate(id)` via `api.get<{data: WoFormTemplate}>('/admin/wo-forms/templates/${id}')` (include fields).
   - `createTemplate({name, fa_subclass_code})`, `updateTemplate(id, {name})` (fa_subclass_code immutable) → entity-or-null; populate `validationErrors` from `ApiError` on 422.
   - `deactivateTemplate(id)`, `reactivateTemplate(id)` → `ActionResult {ok, message?}` (409 → `ok:false` + message).
   - **Field ops** (all under `/admin/wo-forms/templates/${id}/fields`): `addField(payload)` POST, `updateField(fieldId, payload)` PATCH, `deleteField(fieldId)` DELETE, `reorderFields(orderMap)` POST `/reorder`. Each returns the updated field or `ActionResult`; optimistic UI + toast.
   - `loadFaSubclasses()` → `fetchList<FaSubclassTypeCode>('/admin/fa-subclass-type-codes')`, cached in a `faSubclasses` ref (cache-unless-force, like `loadReadingTypes` in `usePmRules`).
3. **Router** — in `src/router/index.ts` add (lazy, Admin-only):
   `{ path: '/admin/wo-forms', name: 'admin-wo-forms', component: () => import('@/views/admin/AdminView.vue'), meta: { requiresAdmin: true } }`
4. **Admin tab registration** — in `src/views/admin/AdminView.vue`: import `WoFormsView`; add branch `if (route.path.startsWith('/admin/wo-forms')) return 'wo-forms'`; add `{ key: 'wo-forms', label: 'WO Forms', to: '/admin/wo-forms' }`; add the `<template v-else-if="activeTab === 'wo-forms'"><WoFormsView /></template>`.
5. **Sidebar highlight** — in `src/components/app/AppSidebar.vue` extend the Admin `isActiveFor`: add `|| p.startsWith('/admin/wo-forms')`.
6. **List view** — `src/views/wo-forms/WoFormsView.vue` (inside AdminView; no `<AppLayout>`):
   - `AppDataTable` columns: `name`, `fa_subclass_code`, `fields_count` (from `fields.length`), `is_active` (status badge), `actions`.
   - "Create Form" button gated by `canConfigure = computed(() => auth.isAdmin)`.
   - Create/Edit metadata via `<WoFormForm>` sheet (`formOpen`/`editing`/`saving`/`validationErrors`).
   - Row actions: "Manage Fields" → opens `<WoFormFieldsSheet>`; "Edit"; Activate/Deactivate `<Dialog>` (toast on result).
7. **Template metadata sheet** — `src/components/wo-forms/WoFormForm.vue` (shadcn `Sheet`, `:modal="false"`, right side): `name` (Input, required), `fa_subclass_code` (Select of FA subclass options, required; **disabled on edit**; on create, **exclude subclasses that already have an active template** — the backend 422 remains the backstop). `watch(open)` reset/seed; `emit('save', {name, fa_subclass_code})`.
8. **Fields manager sheet** — `src/components/wo-forms/WoFormFieldsSheet.vue` (shadcn `Sheet` or wide `Dialog`):
   - Lists the template's `fields` (sorted by `sort_order`), each row editable inline: `label`, `field_type` (Select), `unit` (Input when `numeric`), `has_pre_post` (Checkbox), `is_required` (Checkbox). (No `switch` component exists in this codebase — use `checkbox`/`toggle`.)
   - **Add row** → creates via `addField` (optimistic append, toast).
   - **Edit** → on change/blur calls `updateField` (toast).
   - **Delete** → confirm `<Dialog>` then `deleteField` (toast).
   - **Reorder** → up/down (or drag) → `reorderFields` (debounced; sends `{ field_ids: number[] }` per API ref).
   - Per-field server errors surfaced inline.
9. **WO detail — composable** — in `src/composables/useWorkOrderDetail.ts` add:
   - `canEditWoForm = computed(() => !!record.value && !isTerminal.value && !isCompleted.value && (auth.isAdminOrManager || isAssignedToMe))` — mirrors the existing `canEdit` computed (`isTerminal` = closed/cancelled only); the form is read-only once `completed` too, per FORM_REQUIREMENTS.md §"WO Form Execution".
   - `updateFieldValue(fieldId, payload)` → `PATCH /work-orders/${id}/form/fields/${fieldId}` with `{pre_value?, post_value?, notes?}` → toast on error; reload record on success.
   - `syncForm()` → `POST /work-orders/${id}/form/sync` → toast.success → `load(id)`.
   - `deferFormSync()` → `POST /work-orders/${id}/form/defer-sync` → set a session ref `syncDeferred = true` (hides banner) → `load(id)`.
   - `missingFields = ref<Set<string>>(new Set())` (uuids/slots) cleared on successful edits.
   - In `doComplete()`: on `ApiError` **422**, read `e.data?.missing` (array of `{uuid,label,missing[]}`) → populate `missingFields`, `toast.error('Complete required form fields first.')`, keep the dialog open. Other errors as today.
10. **WO detail — view** — in `WorkOrderDetailView.vue` add a `<div class="data-card">` **"WO Form"** between Work notes and Related MR, rendered only when `record.form` exists:
    - **Sync banner** (`v-if="record.form?.template_is_stale && !syncDeferred && canEditWoForm"`): copy per FORM_REQUIREMENTS — "This form was snapshotted from an older template version." + "Sync to latest" (→ `syncForm`) / "Dismiss" (→ `deferFormSync`, hides for the session).
    - For each field: `label` + unit; if `has_pre_post` show Pre + Post inputs, else one input. Controls: Checkbox (boolean), number Input (numeric), text Input/Textarea (text), per-field `notes` Textarea.
    - **Per-field autosave** on blur (debounced) → `updateFieldValue`; disabled when `!canEditWoForm`.
    - **Highlight missing**: add an error class/`<p class="form-error">` on inputs whose uuid/slot is in `missingFields` (set by the 422 response).
11. **Permissions** — admin tab + all template/field management gated by `auth.isAdmin`; WO value editing gated by `canEditWoForm` (existing role/status logic). No new store.
12. **Reuse** — existing shadcn primitives (`sheet`, `dialog`, `input`, `textarea`, `label`, `select`, `checkbox`, `toggle`, `button`, `badge`) + `vue-sonner` toast. No new UI primitives. (There is no `switch` component — do not use it.)

---

## Risks / edge cases

- **Booleans vs "unanswered"** — the shadcn `Checkbox` is boolean-only, so model tri-state in the draft: `null` (untouched) vs `'1'`/`'0'` (answered). Render: `null` and `false` both show unchecked; track a per-field "touched" flag so a `false` answer is sent while an untouched required field stays unfilled (→ 422). First click → true, second → false. Required booleans = user must interact.
- **Numeric values** — send as strings (backend stores `string`); display parsed.
- **No batch save API** — only the per-field PATCH exists (despite FORM_REQUIREMENTS L283 "individually or as a batch"); the autosave-per-field approach is correct.
- **WO payload / form fetch** — the backend embeds `form` in `WorkOrderResource` (via `whenLoaded('workOrderForm')`); that embedded `WorkOrderFormResource` MUST include `template_is_stale` so the banner renders without a second call. Canonical/fallback source is the dedicated `GET /api/work-orders/{id}/form` endpoint (API ref L1560 — returns `template_is_stale` + `404` when no form).
- **Per-field autosave races** — debounce blur-saves; ignore stale responses; on 409/422 toast and reload canonical state.
- **Reorder payload shape** — API ref specifies `{ field_ids: int[] }`; the composable must send exactly that.
- **Sidebar highlight** — without Task 5 the Admin item won't highlight on the new tab.
- **`fetchList` cache** — `force=true` after create/update/deactivate/reorder to refresh (same as PM Rules).
- **Manager visibility** — Managers never see the tab (`requiresAdmin`); no read path needed.
- **Defer is session-scoped** (decision): the banner is hidden via a local `syncDeferred` ref for the current session only; on reload/navigation it **reappears** (since `template_is_stale` is still true). The backend `defer-sync` records `sync_dismissed_at` for audit but does **not** suppress the banner across sessions. (Amends the older wording in WO_FORMS §6 / FORM_REQUIREMENTS — see Doc Dependency.)
- **Cross-doc conflict (editing on `completed`)** — API ref L1600 allows PATCH on `in_progress` OR `completed`, but FORM_REQUIREMENTS says read-only after `completed`. The frontend is intentionally stricter (`canEditWoForm` excludes `completed`, matching existing `canEdit` + FORM_REQUIREMENTS). Flag for backend-doc reconciliation (`updateExecution` still permits Admin/Manager edits on completed).

## Validation

- `npm run typecheck` (incl. new types, `WorkOrder.form`, `WoFormInstance.template_is_stale`, `MissingField`).
- `npm run build` (or `npm run dev`) — verify the tab renders, metadata create/edit works, the Fields manager add/edit/delete/reorder persists, and the WO form section renders.
- Manual smoke (happy): create template for a subclass → manage fields → create MR on an asset of that subclass → approve → WO detail shows the form → fill pre values (autosave) → fill post values → Complete → succeeds.
- Manual smoke (gate): leave a required field empty → Complete → 422 → toast + highlighted missing inputs → fill them → Complete succeeds.
- Manual smoke (sync): after the WO exists, edit the template's fields → reopen WO detail → banner shows → Sync → fields update (values preserved where matched) → Defer hides banner for the session.
- Manual smoke (permissions): Manager/Technician cannot reach `/admin/wo-forms` (→ `/403`).

## Doc Dependency (separate, out of plan mode)

- Amend the defer wording in two product docs to match the session-dismiss decision: `docs/atms/01-product/WO_FORMS.md` §6 (L196–197) and `docs/atms/04-frontend/FORM_REQUIREMENTS.md` (L294) — change "banner remains visible" → "banner is dismissed for the current session and reappears on reload." (The WO_FORMS §3 self-contained-snapshot amendment is tracked on the backend plan.)

## Out of scope (v1)

- Separate template detail page (`WoFormDetailView.vue`) — deferred; v1 uses sheets.
- Global/default form and multiple forms per subclass.
- Photo/scoring/approval/defect field types.
