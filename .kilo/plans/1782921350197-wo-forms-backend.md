# Plan — WO Forms: Backend Implementation (v2.1, spec-aligned)

> Implementation-ready plan for the Laravel 13 / PHP 8.4 / PostgreSQL backend at `backend/`.
> **Reconciled to the authoritative spec** `docs/atms/01-product/WO_FORMS.md` and `docs/atms/04-technical/BACKEND_API_REFERENCE.md` (WO Forms sections, written 2026-07-01). Where this plan and the earlier draft differ, **the spec wins**.
>
> Design decisions resolved during review:
> - **#9 Snapshot is self-contained** (copy field metadata into `work_order_form_fields`) — requires amending the spec §3 table (see Doc Dependency).
> - **#5 Sync-to-latest is IN SCOPE** for v1 (both endpoints).
> - **#8 Templates are Admin-only** (no Manager access).
>
> v2.1 adds (per second review): dedicated `GET /work-orders/{id}/form` endpoint + `viewForm` policy; `template_is_stale` on `WorkOrderFormResource`; explicit create-uniqueness validation (clean 422); `FormTemplate*` naming to match the `PmRule→PmRules` convention.

---

## Context (agreed design)

- A Work Order may carry one **WO Form** (structured pre/post-maintenance form). Which form applies = the asset's **`fa_subclass_code`** (plain string on `Asset`, referencing `fa_subclass_type_codes`). **One active template per subclass.**
- Field types: `boolean`, `numeric` (optional display `unit`), `text`.
- `has_pre_post`: `true` → pre (captured during `in_progress`) + post (at completion); `false` → single value (stored in `post_value`).
- On WO creation the matching active template is **snapshotted (immutable, self-contained copy)**. Template edits never affect in-flight WOs.
- **Completion gate:** `in_progress → completed` blocked unless all **required** fields are filled → returns **422** with the list of missing fields. Applies **only when a form instance exists**.
- **Sync-to-latest:** a manual accept/defer that re-snapshots the latest template, matching fields by `uuid` (keep filled values, append new empty, drop removed).

### Key codebase conventions (verified, match these)
- WO creation hook: `app/Actions/MaintenanceRequests/ApproveMaintenanceRequestAndCreateWorkOrder.php` — insert snapshot inside the existing `DB::transaction`, right after `WorkOrder::create([...])` (lines 44–51), before the optional `AssignWorkOrder` (line 57).
- Completion hook: `app/Actions/WorkOrders/CompleteWorkOrder.php` — insert gate after the assignee guard (line 30) and before `$locked->update([...])` (line 33).
- **No state machine** — guards live in each Action.
- Admin-managed templates mirror `PmRule` (model / policy / resource / thin controller / `app/Actions/Pm/*` / routes). **Model is `FormTemplate` → directory/class names use `FormTemplate` (not a `Wo` prefix)** to match `PmRule→PmRules`.
- **Inline `$request->validate([...])`** in controllers (NOT Form Requests). **No factories** — tests build records with `Model::create([...])`.
- Enums: pure string-backed, SCREAMING_SNAKE → snake_case values; DB column is plain `string`.
- Per-endpoint `catch (\DomainException $e) { …409 }` (no global handler) — order catches carefully.
- DB driver: **pgsql** in prod AND tests (phpunit.xml forces `DB_CONNECTION=testing` → pgsql). Partial unique indexes are valid here.
- `WorkOrderController::show()` eager-loads a list at line 40 — add `workOrderForm.fields` and `workOrderForm.template`.
- `WorkOrderPolicy::updateExecution($user, $wo)` = non-terminal (not CLOSED/CANCELLED) AND (Admin OR Manager OR assigned Technician) — **reuse this** for value/sync/defer; do not invent a new policy method.
- Latest migration anchor: **`2026_06_28_163600_seed_baseline_real_data.php`** — new migration must sort after `2026_06_28_163600`.

---

## Decisions (locked)

- **Templates under the `/admin` route prefix** (NOT top-level): `/api/admin/wo-forms/templates[...]`.
- **Dedicated `GET /api/work-orders/{workOrder}/form`** endpoint (in addition to the form being embedded in `WorkOrderResource` via `show()`). Has its own auth + `404` when no form. (Embedded form in `show()` stays as a convenience.)
- **Field management = 4 separate endpoints** (add/update/delete/reorder), not a nested sync array.
- **Value submission = per-field PATCH**, not a batch POST.
- **Completion gate returns 422** via a custom `WorkOrderFormIncompleteException` (extends `\DomainException`, carries `array $missing`), thrown inside `CompleteWorkOrder` (authoritative) and caught **before** the generic 409 catch.
- **Templates are Admin-only** for every capability (including view).
- **Self-contained snapshot:** `work_order_form_fields` copies `uuid, label, field_type, has_pre_post, unit, is_required, sort_order` at snapshot time; `form_template_field_id` is a nullable soft FK (`nullOnDelete`). This survives template-field deletion (which the DELETE-field endpoint allows) without data loss.
- **No `description`, no `deactivated_by_user_id`/`deactivated_at`** on `form_templates` (conform to spec). `fa_subclass_code` is set at creation and immutable thereafter.
- **Boolean "required" = answered** (any non-null; stored `'0'`/`'1'`), not necessarily `true`.
- Reactivation must respect 1:1 active-per-subclass (explicit check → 409 if another active template shares the subclass).
- `snapshotted_at` + per-field `uuid` enable sync.

---

## Data model

```
form_templates
  id, name (string), fa_subclass_code (string NOT NULL), is_active (bool default true), timestamps
  + PARTIAL UNIQUE INDEX on (fa_subclass_code) WHERE is_active = true   [raw DB::statement on pgsql]

form_template_fields
  id, form_template_id (FK → form_templates cascadeOnDelete),
  uuid (uuid UNIQUE) ← stable across edits; used for sync matching,
  label (string), field_type (string), has_pre_post (bool default false),
  unit (string NULL), is_required (bool default false), sort_order (unsignedInteger default 0),
  timestamps

work_order_forms                       (1:1 with work_orders)
  id, work_order_id (FK → work_orders UNIQUE cascadeOnDelete),
  form_template_id (FK → form_templates NULL nullOnDelete) ← soft ref,
  snapshotted_at (datetime), sync_dismissed_at (datetime NULL), timestamps

work_order_form_fields                 (self-contained snapshot + captured values)
  id, work_order_form_id (FK → work_order_forms cascadeOnDelete),
  form_template_field_id (FK → form_template_fields NULL nullOnDelete) ← soft ref,
  uuid (uuid), label (string), field_type (string), has_pre_post (bool),
  unit (string NULL), is_required (bool), sort_order (unsignedInteger),
  pre_value (string NULL), post_value (string NULL), notes (text NULL),
  timestamps
  + UNIQUE (work_order_form_id, uuid)
```

> **Doc Dependency (out of plan mode):** amend `docs/atms/01-product/WO_FORMS.md` §3 `work_order_form_fields` table to add the copied metadata columns (`uuid, label, field_type, has_pre_post, unit, is_required, sort_order`), document the soft `form_template_field_id` FK, and add `sync_dismissed_at` to `work_order_forms`. Keeps spec & code in agreement per decision #9. (`template_is_stale` is a computed response field, not a column — no §3 change needed for it.)

---

## Ordered tasks

1. **Enum** — `app/Enums/FormFieldType.php`: `BOOLEAN='boolean'`, `NUMERIC='numeric'`, `TEXT='text'`.
2. **Exception** — `app/Exceptions/WorkOrderFormIncompleteException.php`: `extends \DomainException`; constructor takes `array $missing` (list of `{uuid,label,missing:['pre'|'post']}`) + message.
3. **Migration** — one file (timestamp after `2026_06_28_163600`, e.g. `2026_07_01_200000`). Four `Schema::create` blocks as above. Add the partial unique index via `DB::statement('CREATE UNIQUE INDEX form_templates_active_subclass_unique ON form_templates (fa_subclass_code) WHERE is_active = true')` in `up()`; drop it in `down()`.
4. **Models**
   - `FormTemplate` — `$fillable` (name, fa_subclass_code, is_active), `$casts` (is_active bool), `fields()` HasMany, `static activeForSubclass(string $code): ?self`.
   - `FormTemplateField` — `$fillable` incl `uuid`, `$casts`.
   - `WorkOrderForm` — `$fillable` (work_order_id, form_template_id, snapshotted_at, sync_dismissed_at), `$casts`, `fields()` HasMany, `workOrder()` BelongsTo, `template()` BelongsTo.
   - `WorkOrderFormField` — `$fillable` (all self-contained cols + soft FK), `$casts`.
   - `WorkOrder`: add `workOrderForm()` → `hasOne(WorkOrderForm::class)` and `isFormComplete(): bool`.
5. **`WorkOrder::isFormComplete(): bool`** — return `true` if `workOrderForm` is null. Else, for each loaded field where `is_required`: if `has_pre_post` → both `pre_value` and `post_value` non-null/non-empty; else `post_value` non-null/non-empty. (Booleans: non-null = answered.)
6. **Snapshot Action** — `app/Actions/WorkOrders/SnapshotFormTemplateIntoWorkOrder.php` `execute(WorkOrder $wo): void`. Look up `FormTemplate::activeForSubclass($wo->asset->fa_subclass_code)`; if none, return (no form). Else create `WorkOrderForm` (`snapshotted_at = $template->updated_at`) and a self-contained `WorkOrderFormField` per `FormTemplateField` (copy metadata; `form_template_field_id` set; pre/post null). **No throw if no template.**
7. **Wire snapshot into WO creation** — in `ApproveMaintenanceRequestAndCreateWorkOrder::execute`, immediately after `$workOrder = WorkOrder::create([...])`, call `app(SnapshotFormTemplateIntoWorkOrder::class)->execute($workOrder)` (same transaction).
8. **Completion gate** — in `CompleteWorkOrder::execute`, after the assignee guard and before `$locked->update([...])`:
   ```
   $locked->load('workOrderForm.fields');
   if ($locked->workOrderForm && ! $locked->isFormComplete()) {
       throw new WorkOrderFormIncompleteException($locked->missingRequiredFields());
   }
   ```
   (Add `WorkOrder::missingRequiredFields(): array` returning `{uuid,label,missing:[...]}` per unfilled slot.) The controller catches this **before** `\DomainException`.
9. **Template Actions** in `app/Actions/FormTemplates/`:
   - `CreateFormTemplate::execute(array $data, int $userId): FormTemplate` — create template (name + fa_subclass_code); audit `form_template.created`. (Fields are added via separate endpoints.)
   - `UpdateFormTemplate::execute(FormTemplate $t, array $data, int $userId)` — `lockForUpdate`; update `name` only (fa_subclass_code immutable); audit `form_template.updated`.
   - `DeactivateFormTemplate::execute(FormTemplate $t, int $userId)` — guard `is_active`; set `is_active=false`; audit `form_template.deactivated`.
   - `ReactivateFormTemplate::execute(FormTemplate $t, int $userId)` — guard `!is_active`; **throw `DomainException` if another active template shares `fa_subclass_code`**; set `is_active=true`; audit `form_template.reactivated`.
10. **Field Actions** in `app/Actions/FormTemplates/`:
    - `AddFormField::execute(FormTemplate $t, array $data, int $userId): FormTemplateField` — create one field with a fresh `uuid` (`(string) Str::orderedUuid()`); audit `form_template.field_added`.
    - `UpdateFormField::execute(FormTemplateField $f, array $data, int $userId)` — update label/type/unit/has_pre_post/is_required; audit `form_template.field_updated`.
    - `DeleteFormField::execute(FormTemplateField $f, int $userId)` — delete the field. (Safe: WO snapshots are self-contained — no cascade into captured values.)
    - `ReorderFormFields::execute(FormTemplate $t, array $fieldIds, int $userId)` — set `sort_order` from the incoming `{field_ids: int[]}` order; audit `form_template.fields_reordered`.
11. **Value/sync Actions** in `app/Actions/WorkOrders/`:
    - `UpdateWorkOrderFormFieldValue::execute(WorkOrder $wo, int $fieldId, array $data, int $userId): WorkOrderFormField` — guard non-terminal (policy enforced at controller); map `pre_value?/post_value?/notes?` onto the matching `WorkOrderFormField`; audit `work_order_form.field_value_updated`.
    - `SyncWorkOrderFormToLatest::execute(WorkOrder $wo, int $userId): WorkOrderForm` — within a transaction: load latest `FormTemplate::activeForSubclass`; **match by `uuid`**: keep+update metadata of matched fields (preserve `pre_value/post_value/notes`), append new fields (empty values), delete WO fields whose `uuid` is no longer in the template; set `snapshotted_at = template.updated_at`, clear `sync_dismissed_at`; audit `work_order_form.synced`.
    - `DeferWorkOrderFormSync::execute(WorkOrder $wo, int $userId): WorkOrderForm` — set `sync_dismissed_at = now()`; audit `work_order_form.sync_deferred`.
12. **Policy** — `app/Policies/FormTemplatePolicy.php`: **every** method (`viewAny, view, create, update, deactivate, reactivate, addField, updateField, deleteField, reorderFields`) returns `$user->hasRole(RoleCode::ADMINISTRATOR)`. For value/sync/defer, **reuse** `Gate::authorize('updateExecution', $workOrder)` in the controller. Add one new method to `WorkOrderPolicy`: `viewForm(User, WorkOrder): bool` = Admin OR Manager OR (Technician AND `assigned_to_user_id === $user->id`) — used by GET `/work-orders/{wo}/form` (reading is allowed even on terminal WOs, unlike `updateExecution`).
13. **Resources** — `FormTemplateResource`, `FormTemplateFieldResource`, `WorkOrderFormFieldResource`, and `WorkOrderFormResource` returning: `id, form_template_id, snapshotted_at, sync_dismissed_at`, **`template_is_stale`** = computed `$this->template && $this->snapshotted_at?->lt($this->template->updated_at)` (false if the template was deleted), and `fields` via `whenLoaded('fields', …)`. The form's `template` relation must be loaded for `template_is_stale` to compute. Extend `WorkOrderResource`:
    - Add `canSeeForm = $isAdmin || $isManager || $isTech;` and, inside that block, `'form' => $this->whenLoaded('workOrderForm', fn () => new WorkOrderFormResource($this->workOrderForm))`. (Use **single-level** `whenLoaded('workOrderForm', …)` — never dotted.)
14. **Controllers**
    - `app/Http/Controllers/Admin/FormTemplateController.php` (thin, mirror `PmRuleController`): `index` (delegate to `FormTemplateIndexQuery`), `store`, `show`, `update`, `deactivate`, `reactivate`, `addField`, `updateField`, `deleteField`, `reorderFields`. Inline `$request->validate`; method-inject Action; return Resource (201 on store/field-add). Catch `\DomainException` → 409 on toggle/reactivate. Validate each `{field}` belongs to the `{template}` (404 otherwise). Each field method authorizes its own ability: `Gate::authorize('addField'|'updateField'|'deleteField'|'reorderFields', $template)` (all Admin-only).
    - **`store()` validation** (returns a clean **422** for a duplicate active subclass *before* the partial unique index can raise a 500): `name` `['required','string','max:255']`; `fa_subclass_code` `['required','string','exists:fa_subclass_type_codes,fa_subclass_code', Rule::unique('form_templates','fa_subclass_code')->where(fn ($q) => $q->where('is_active', true))]`. Add `use Illuminate\Validation\Rule;`.
    - In `WorkOrderController`: add `showForm(Request, WorkOrder)` (GET `/work-orders/{workOrder}/form` — `Gate::authorize('viewForm', $workOrder)`; load `workOrderForm.template.fields`; **404 if no `workOrderForm`**; else `WorkOrderFormResource` carrying `template_is_stale`), `updateFormField(Request, WorkOrder, int $field, UpdateWorkOrderFormFieldValue)`, `syncForm(WorkOrder, SyncWorkOrderFormToLatest)`, `deferFormSync(WorkOrder, DeferWorkOrderFormSync)`. Each value/sync/defer method: `Gate::authorize('updateExecution', $workOrder)`; validate; catch `\DomainException` → 409.
    - **`complete()`**: add a `catch (WorkOrderFormIncompleteException $e)` **before** the existing `catch (\DomainException $e)` → `response()->json(['message' => 'Required WO Form fields are unfilled.', 'missing' => $e->missing], 422)`.
    - **`show()`**: add `'workOrderForm.fields'` and `'workOrderForm.template'` to the `->load([...])` array (line 40) so the embedded form carries `template_is_stale`.
15. **Query** — `app/Queries/FormTemplates/FormTemplateIndexQuery.php` `build(Request): CursorPaginator` (mirror `PmRuleIndexQuery`).
16. **Routes** — in `routes/api.php`:
    - Inside `Route::prefix('admin')->group(...)`:
      ```
      Route::get('/wo-forms/templates', [FormTemplateController::class, 'index']);
      Route::post('/wo-forms/templates', [FormTemplateController::class, 'store']);
      Route::get('/wo-forms/templates/{template}', [FormTemplateController::class, 'show']);
      Route::patch('/wo-forms/templates/{template}', [FormTemplateController::class, 'update']);
      Route::post('/wo-forms/templates/{template}/deactivate', [FormTemplateController::class, 'deactivate']);
      Route::post('/wo-forms/templates/{template}/reactivate', [FormTemplateController::class, 'reactivate']);
      Route::post('/wo-forms/templates/{template}/fields', [FormTemplateController::class, 'addField']);
      Route::patch('/wo-forms/templates/{template}/fields/{field}', [FormTemplateController::class, 'updateField']);
      Route::delete('/wo-forms/templates/{template}/fields/{field}', [FormTemplateController::class, 'deleteField']);
      Route::post('/wo-forms/templates/{template}/fields/reorder', [FormTemplateController::class, 'reorderFields']);
      ```
    - In the work-orders block:
      ```
      Route::get('/work-orders/{workOrder}/form', [WorkOrderController::class, 'showForm']);
      Route::patch('/work-orders/{workOrder}/form/fields/{field}', [WorkOrderController::class, 'updateFormField']);
      Route::post('/work-orders/{workOrder}/form/sync', [WorkOrderController::class, 'syncForm']);
      Route::post('/work-orders/{workOrder}/form/defer-sync', [WorkOrderController::class, 'deferFormSync']);
      ```
    - Add `use App\Http\Controllers\Admin\FormTemplateController;`.
17. **Tests** (manual records, no factory; mirror `WorkOrderLifecycleTest` helpers):
    - `Feature/WorkOrders/FormSnapshotTest` — approve MR for asset with active template → `work_order_forms` + self-contained fields created; asset without template → no form.
    - `Feature/WorkOrders/WorkOrderFormCompletionGateTest` — required fields empty → `complete` returns **422** with `missing` array (NOT 409) and WO stays `in_progress`; fill required → 200; WO with no form → completion unaffected.
    - `Feature/FormTemplates/FormTemplatePolicyTest` — Admin CRUD OK; **Manager denied (403) even on view**; Technician/Logistics/Requester denied.
    - `Feature/FormTemplates/CreateFormTemplateConflictTest` — create with a subclass that already has an active template → **422** (not a 500 from the partial unique index).
    - `Feature/FormTemplates/ReactivateConflictTest` — reactivate when another active template shares the subclass → 409.
    - `Feature/FormTemplates/DeleteFormFieldSnapshotIntegrityTest` — delete a template field that is referenced by an existing WO snapshot → succeeds; the WO's captured values + label/type/required remain intact (proves #9 self-contained design).
    - `Feature/WorkOrders/UpdateWorkOrderFormFieldValueTest` — assigned tech/admin/manager submit per-field PATCH OK; non-assignee 403; terminal WO 403.
    - `Feature/WorkOrders/ShowWorkOrderFormTest` — GET `/work-orders/{wo}/form` returns the form with `template_is_stale`; **404 when no form**; non-allowed role 403; readable on terminal WOs by Admin/Manager.
    - `Feature/WorkOrders/SyncWorkOrderFormTest` — sync adds new fields (empty), drops removed, preserves filled matched-by-uuid values; defer-sync sets `sync_dismissed_at`; sync clears it.
18. **Format** — `vendor/bin/pint --dirty --format agent` after editing PHP files.

---

## Risks / edge cases

- **Partial unique index** — valid on pgsql (prod + tests). `down()` must drop it. Verify seeders don't create two active templates for one subclass. The create-uniqueness **validation** (Task 14) returns a clean 422; the index is the backstop.
- **`whenLoaded` must be single-level** — use `whenLoaded('workOrderForm', …)`, not dotted `workOrderForm.fields`.
- **`template_is_stale` needs the `template` relation loaded** — both `show()` (embedded) and `showForm()` must eager-load `workOrderForm.template`; compute `false` if the template was deleted (soft FK null).
- **Reactivation conflict** — explicit check in the action (else raw DB 500 from the partial unique index).
- **Self-contained snapshot** — the `DeleteFormFieldSnapshotIntegrityTest` proves captured values survive template-field deletion; this is why the soft FK is `nullOnDelete` and metadata is copied.
- **`updateExecution` reuse** — Admin/Manager can edit/sync a **completed-but-not-closed** WO (returns true for them on COMPLETED); acceptable per RBAC "operational recovery" before closure. (Frontend is stricter and blocks `completed` edits — UI < API.) Reading via `viewForm` is allowed on terminal WOs.
- **defer-sync semantics (session-scoped)** — Defer dismisses the banner for the current browser session only; on reload it reappears (`template_is_stale` is still true). The backend `defer-sync` records `sync_dismissed_at` for audit but does **not** suppress the banner across sessions — banner display is frontend-driven (`template_is_stale && !syncDeferred`). `SyncWorkOrderFormTest` still verifies defer sets / sync clears the column.
- **Nested `{template}/{field}` binding** — bind `{field}` globally by id then verify `field->form_template_id === template->id` in the controller (404 on mismatch).
- **DomainException ordering** — the `WorkOrderFormIncompleteException` catch MUST precede the generic `\DomainException` catch in `complete()` (subclass is caught by the parent type otherwise).

## Validation

- `php artisan test --compact --filter=FormSnapshotTest`
- `php artisan test --compact --filter=WorkOrderFormCompletionGateTest`  (expects 422, not 409)
- `php artisan test --compact --filter=FormTemplatePolicyTest`
- `php artisan test --compact --filter=CreateFormTemplateConflictTest`
- `php artisan test --compact --filter=ReactivateConflictTest`
- `php artisan test --compact --filter=DeleteFormFieldSnapshotIntegrityTest`
- `php artisan test --compact --filter=UpdateWorkOrderFormFieldValueTest`
- `php artisan test --compact --filter=ShowWorkOrderFormTest`
- `php artisan test --compact --filter=SyncWorkOrderFormTest`
- `php artisan route:list --path=admin/wo-forms` and `--path=work-orders` to confirm new routes (incl. GET `/work-orders/{wo}/form`).
- After feature tests pass, ask the user before running `php artisan test --compact` (full suite).
- `vendor/bin/pint --dirty --format agent`.

## Doc Dependency (separate, out of plan mode)

- Amend `docs/atms/01-product/WO_FORMS.md` §3 `work_order_form_fields` table: add the copied metadata columns (`uuid, label, field_type, has_pre_post, unit, is_required, sort_order`), document the soft `form_template_field_id` FK, and add `sync_dismissed_at` to `work_order_forms`. Keeps spec and code in agreement per decision #9. (`template_is_stale` is computed, not a column.)
- The frontend plan `…wo-forms-frontend.md` is **reconciled to v2** (spec routes, per-field PATCH, 422 gate, in-scope sync, `template_is_stale`, Admin-only, no `switch` component) and aligned to this backend v2.1.

## Out of scope (v1)

- Global/default form (nullable `fa_subclass_code`).
- Multiple active forms per subclass.
- Photo/scoring/approval/defect-generation field types.
