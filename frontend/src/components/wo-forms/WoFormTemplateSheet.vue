<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from '@/components/ui/sheet'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import MaintenanceCategoryPicker from '@/components/app/MaintenanceCategoryPicker.vue'
import { useClaimedCategories } from '@/composables/useClaimedCategories'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { ChevronUpIcon, ChevronDownIcon, Trash2 } from '@lucide/vue'
import { woFormFieldTypeLabel } from '@/lib/displayHelpers'
import type {
  WoFormTemplate,
  WoFormTemplateField,
  WoFormFieldType,
  MaintenanceCategoryOption,
} from '@/types'
import type { WoFormTemplatePayload, WoFormFieldPayload } from '@/composables/useWoForms'

const props = defineProps<{
  open: boolean
  /** null = create mode; a template = edit mode. */
  editing: WoFormTemplate | null
  /** Fully-loaded template (with fields) that backs the fields section. */
  template: WoFormTemplate | null
  templateLoading: boolean
  /** True when the last `loadTemplate` failed — drives the fields error state. */
  templateLoadFailed: boolean
  maintenanceCategories: MaintenanceCategoryOption[]
  templates: WoFormTemplate[]
  saving: boolean
  validationErrors: Record<string, string[]> | null
  saveError: string | null
  fieldSaving: boolean
  fieldErrors: Record<string, string[]> | null
  /** Bumped by the parent only when an add-field call actually succeeds — lets
   * the add-field draft reset on confirmed success without also clearing it
   * (and the user's in-progress input) on an unrelated update/delete/reorder,
   * or on a failed add. */
  addedTick: number
}>()

const emit = defineEmits<{
  close: []
  saveMetadata: [payload: WoFormTemplatePayload]
  addField: [payload: WoFormFieldPayload]
  updateField: [fieldId: number, payload: WoFormFieldPayload]
  deleteField: [fieldId: number]
  reorderFields: [fieldIds: number[]]
}>()

const isEdit = computed(() => props.editing !== null)

const title = computed(() => (isEdit.value ? 'Edit WO Form Template' : 'Create WO Form Template'))

// ── Metadata (name + categories) ─────────────────────────────────────────────
const name = ref('')
const selectedCategoryIds = ref<number[]>([])
const localError = ref('')

/**
 * Categories already claimed by another active template (see
 * `useClaimedCategories`), so the picker can show them as "taken by X" rather
 * than silently hiding them.
 */
const claimedBy = useClaimedCategories(
  computed(() => props.templates),
  computed(() => props.editing?.id ?? null),
)

function handleSave() {
  localError.value = ''

  if (!name.value.trim()) {
    localError.value = 'Template name is required.'
    return
  }

  if (selectedCategoryIds.value.length === 0) {
    localError.value = 'Select at least one maintenance category.'
    return
  }

  emit('saveMetadata', {
    name: name.value.trim(),
    maintenance_category_ids: [...selectedCategoryIds.value],
  })
}

// ── Fields (edit mode) — local editable copy, re-synced on parent reload ─────
const FIELD_TYPES: WoFormFieldType[] = ['boolean', 'numeric', 'text']

interface FieldDraft {
  id: number
  label: string
  field_type: WoFormFieldType
  unit: string
  has_pre_post: boolean
  is_required: boolean
}

const fieldErrorMessage = computed(() => Object.values(props.fieldErrors ?? {})[0]?.[0] ?? null)

const rows = ref<FieldDraft[]>([])

function draftsFrom(fields: WoFormTemplateField[] | undefined): FieldDraft[] {
  return (fields ?? [])
    .slice()
    .sort((a, b) => a.sort_order - b.sort_order)
    .map((f) => ({
      id: f.id,
      label: f.label,
      field_type: f.field_type,
      unit: f.unit ?? '',
      has_pre_post: f.has_pre_post,
      is_required: f.is_required,
    }))
}

function commitUpdate(row: FieldDraft) {
  emit('updateField', row.id, {
    label: row.label.trim(),
    field_type: row.field_type,
    unit: row.field_type === 'numeric' ? row.unit.trim() || null : null,
    has_pre_post: row.has_pre_post,
    is_required: row.is_required,
  })
}

function setFieldType(row: FieldDraft, value: string) {
  row.field_type = value as WoFormFieldType
  commitUpdate(row)
}

function toggleFlag(
  row: FieldDraft,
  key: 'has_pre_post' | 'is_required',
  value: boolean | 'indeterminate',
) {
  row[key] = value === true
  commitUpdate(row)
}

function moveUp(i: number) {
  if (i === 0) return
  const next = rows.value.slice()
  ;[next[i - 1], next[i]] = [next[i]!, next[i - 1]!]
  rows.value = next
  emit(
    'reorderFields',
    next.map((r) => r.id),
  )
}

function moveDown(i: number) {
  if (i === rows.value.length - 1) return
  const next = rows.value.slice()
  ;[next[i], next[i + 1]] = [next[i + 1]!, next[i]!]
  rows.value = next
  emit(
    'reorderFields',
    next.map((r) => r.id),
  )
}

// ── Add field ────────────────────────────────────────────────────────────────
const newLabel = ref('')
const newType = ref<WoFormFieldType>('text')
const newUnit = ref('')
const newHasPrePost = ref(false)
const newIsRequired = ref(false)
const addError = ref('')

function resetAddDraft() {
  newLabel.value = ''
  newType.value = 'text'
  newUnit.value = ''
  newHasPrePost.value = false
  newIsRequired.value = false
  addError.value = ''
}

function handleAdd() {
  addError.value = ''
  if (!newLabel.value.trim()) {
    addError.value = 'Label is required.'
    return
  }
  emit('addField', {
    label: newLabel.value.trim(),
    field_type: newType.value,
    unit: newType.value === 'numeric' ? newUnit.value.trim() || null : null,
    has_pre_post: newHasPrePost.value,
    is_required: newIsRequired.value,
    sort_order: rows.value.length,
  })
  // Draft resets only when `addedTick` bumps (confirmed success) — see the
  // prop doc comment. A failed add keeps the user's input in place.
}

// ── Fields load state ────────────────────────────────────────────────────────
/**
 * The loaded `template` matches the one being edited. Field ops reload the
 * template in the background but keep the previous object until the response
 * lands, so this stays true across a reload — which is what lets the list stay
 * mounted (no "Loading fields…" flash, no scroll jump) on every field commit.
 */
const loadedTemplateMatches = computed(
  () => props.template != null && props.editing != null && props.template.id === props.editing.id,
)

/** A background reload of the already-shown template is in flight. */
const syncing = computed(() => loadedTemplateMatches.value && props.templateLoading)

// ── Inline delete confirm ────────────────────────────────────────────────────
// Replaces the old nested confirm Dialog. A second overlay was the source of
// the "two modals" flicker, so the confirm now renders inline within the row.
const confirmDeleteId = ref<number | null>(null)

function openDelete(row: FieldDraft, event: Event) {
  confirmDeleteId.value = row.id
  // The trash button is replaced by the confirm buttons, so without this focus
  // drops to <body>. Re-anchor it to the destructive action once it mounts —
  // queried from the row rather than a template ref, which is not reliably
  // bound on the same tick the v-if swap creates it.
  const rowEl = (event.currentTarget as HTMLElement | null)?.closest('.wo-form-field-row')
  nextTick(() => {
    rowEl?.querySelector<HTMLElement>('.wo-form-field-confirm-delete button')?.focus()
  })
}

function cancelDelete() {
  confirmDeleteId.value = null
}

function confirmDelete(row: FieldDraft) {
  emit('deleteField', row.id)
  confirmDeleteId.value = null
}

// ── Sync watchers ────────────────────────────────────────────────────────────
// Re-hydrate the fields draft whenever the parent reloads the template.
watch(
  () => props.template,
  (t) => {
    rows.value = draftsFrom(t?.fields)
  },
  { immediate: true },
)

// Reset the add-field draft on confirmed add success.
watch(
  () => props.addedTick,
  () => resetAddDraft(),
)

/** Which template's values `name`/`selectedCategoryIds` currently hold. */
const initialisedFor = ref<number | null>(null)

function initialiseFromEditing() {
  localError.value = ''
  const editing = props.editing
  name.value = editing?.name ?? ''
  selectedCategoryIds.value = (editing?.maintenance_categories ?? []).map((c) => c.id)
  resetAddDraft()
  confirmDeleteId.value = null
  initialisedFor.value = editing?.id ?? null
}

watch(
  () => props.open,
  (nowOpen) => {
    if (nowOpen) initialiseFromEditing()
  },
)

/**
 * This sheet is deliberately non-modal, so the table behind it stays clickable
 * and "Edit" on another row can swap the template underneath an open sheet.
 * Without this the form would keep the previous template's name and categories
 * and write them over the newly selected one on save — which is how a form ends
 * up covering a category nobody assigned to it.
 *
 * The create→edit flip is the one identity change that must NOT re-initialise:
 * there `editing` goes from null to the template just created, and the values on
 * screen are already the saved ones.
 */
watch(
  () => props.editing?.id ?? null,
  (id) => {
    if (!props.open || id === initialisedFor.value) return

    if (initialisedFor.value === null) {
      initialisedFor.value = id
      return
    }

    initialiseFromEditing()
  },
)

// ── Unsaved-changes guard ────────────────────────────────────────────────────
/**
 * Whether the metadata inputs differ from what is saved. Compared against the
 * `editing` prop rather than a snapshot: the parent replaces `editing` with the
 * saved template on a successful metadata save, which clears this automatically
 * — and a failed save leaves `editing` stale, so the guard stays armed.
 * Field ops save immediately and never touch these inputs, so they cannot make
 * the form dirty. Only meaningful in edit mode; in create mode there is no
 * saved baseline and a brand-new form has nothing to lose.
 */
function sameCategories(a: number[], b: number[]): boolean {
  if (a.length !== b.length) return false
  const as = [...a].sort((x, y) => x - y)
  const bs = [...b].sort((x, y) => x - y)
  return as.every((id, i) => id === bs[i])
}

const isMetadataDirty = computed(() => {
  if (!isEdit.value || props.editing == null) return false
  if (name.value.trim() !== (props.editing.name ?? '')) return true
  const saved = (props.editing.maintenance_categories ?? []).map((c) => c.id)
  return !sameCategories(selectedCategoryIds.value, saved)
})

// Mirrors WoChecklistSheet: the sheet is non-modal, so every close route —
// Cancel button, Escape, outside click — funnels through requestClose() and a
// dirty form prompts before its name/category edits are thrown away.
const discardOpen = ref(false)

function requestClose() {
  if (isMetadataDirty.value) {
    discardOpen.value = true
    return
  }
  emit('close')
}

function confirmDiscard() {
  discardOpen.value = false
  emit('close')
}
</script>

<template>
  <Sheet :open="open" :modal="false" @update:open="(v) => !v && requestClose()">
    <SheetContent side="right" class="create-sheet">
      <div class="create-sheet-header">
        <SheetHeader>
          <SheetTitle>{{ title }}</SheetTitle>
          <SheetDescription>
            {{
              isEdit
                ? 'Update the name and maintenance categories, and manage this form’s fields below.'
                : 'Choose which maintenance categories this form covers. Work orders on an asset in one of them snapshot this form. Add its fields after creating.'
            }}
          </SheetDescription>
        </SheetHeader>
      </div>

      <div class="create-sheet-body">
        <div v-if="localError || saveError" class="error-state" role="alert">
          {{ localError || saveError }}
        </div>

        <!-- Metadata -->
        <div class="sheet-form">
          <div class="form-field">
            <Label for="wo-form-name">Template Name <span class="field-required">*</span></Label>
            <Input id="wo-form-name" v-model="name" placeholder="E.g. Mud Motor Inspection" />
            <p v-if="validationErrors?.name" class="form-error">{{ validationErrors.name[0] }}</p>
          </div>

          <div class="form-field">
            <Label for="wo-form-category-search">
              Maintenance Categories <span class="field-required">*</span>
            </Label>

            <MaintenanceCategoryPicker
              v-model="selectedCategoryIds"
              :categories="maintenanceCategories"
              :claimed-by="claimedBy"
              id-prefix="wo-form-category"
            />

            <p class="form-help">
              A category can have only one active form. Categories already covered are shown with
              the form holding them.
            </p>
            <p v-if="validationErrors?.maintenance_category_ids" class="form-error">
              {{ validationErrors.maintenance_category_ids[0] }}
            </p>
            <p v-if="validationErrors?.['maintenance_category_ids.0']" class="form-error">
              {{ validationErrors['maintenance_category_ids.0'][0] }}
            </p>
          </div>
        </div>

        <!-- Fields (edit mode only) -->
        <div v-if="isEdit" class="wo-form-fields-section">
          <div class="wo-form-fields-section-header">
            <p class="data-card-title">Fields</p>
            <p class="form-help">
              Shown in display order on work order forms.
              <span v-if="syncing" class="wo-form-fields-syncing">Syncing…</span>
            </p>
          </div>

          <!-- Failed load: nothing to show and no field-op error to blame. -->
          <div v-if="!loadedTemplateMatches && templateLoadFailed" class="error-state" role="alert">
            Failed to load fields. Close and reopen the sheet.
          </div>

          <!-- First load of this template (or the create→edit flip, where
               `template` is briefly null before the hydrate load kicks in). -->
          <div v-else-if="!loadedTemplateMatches" class="loading-state">Loading fields…</div>

          <template v-else>
            <div v-if="fieldErrorMessage" class="error-state" role="alert">
              {{ fieldErrorMessage }}
            </div>

            <div v-if="rows.length === 0" class="empty-state">
              No fields yet — add the first one below.
            </div>
            <div v-else class="wo-form-fields-list">
              <div v-for="(row, i) in rows" :key="row.id" class="wo-form-field-row">
                <div class="wo-form-field-row-grid">
                  <div class="form-field">
                    <Label :for="`wo-field-label-${row.id}`">Label</Label>
                    <Input
                      :id="`wo-field-label-${row.id}`"
                      v-model="row.label"
                      @blur="commitUpdate(row)"
                    />
                  </div>
                  <div class="form-field">
                    <Label :for="`wo-field-type-${row.id}`">Type</Label>
                    <Select
                      :model-value="row.field_type"
                      @update:model-value="(v) => setFieldType(row, String(v))"
                    >
                      <SelectTrigger :id="`wo-field-type-${row.id}`"><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem v-for="t in FIELD_TYPES" :key="t" :value="t">{{
                          woFormFieldTypeLabel(t)
                        }}</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div v-if="row.field_type === 'numeric'" class="form-field">
                    <Label :for="`wo-field-unit-${row.id}`">Unit</Label>
                    <Input
                      :id="`wo-field-unit-${row.id}`"
                      v-model="row.unit"
                      placeholder="E.g. PSI"
                      @blur="commitUpdate(row)"
                    />
                  </div>
                </div>

                <div class="wo-form-field-row-flags">
                  <Label class="checkbox-field">
                    <Checkbox
                      :model-value="row.has_pre_post"
                      @update:model-value="(v) => toggleFlag(row, 'has_pre_post', v)"
                    />
                    <span>Pre + post values</span>
                  </Label>
                  <Label class="checkbox-field">
                    <Checkbox
                      :model-value="row.is_required"
                      @update:model-value="(v) => toggleFlag(row, 'is_required', v)"
                    />
                    <span>Required</span>
                  </Label>
                </div>

                <div class="wo-form-field-row-footer">
                  <div class="wo-form-field-order-actions">
                    <Button
                      variant="ghost"
                      size="icon-sm"
                      :disabled="fieldSaving || i === 0"
                      :aria-label="`Move ${row.label} up`"
                      @click="moveUp(i)"
                    >
                      <ChevronUpIcon />
                    </Button>
                    <Button
                      variant="ghost"
                      size="icon-sm"
                      :disabled="fieldSaving || i === rows.length - 1"
                      :aria-label="`Move ${row.label} down`"
                      @click="moveDown(i)"
                    >
                      <ChevronDownIcon />
                    </Button>
                  </div>

                  <div v-if="confirmDeleteId === row.id" class="wo-form-field-confirm-delete">
                    <span class="wo-form-field-confirm-delete-text">Remove this field?</span>
                    <Button
                      variant="destructive"
                      size="sm"
                      :disabled="fieldSaving"
                      @click="confirmDelete(row)"
                    >
                      {{ fieldSaving ? 'Removing…' : 'Remove' }}
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      :disabled="fieldSaving"
                      @click="cancelDelete"
                    >
                      Cancel
                    </Button>
                  </div>
                  <Button
                    v-else
                    variant="ghost"
                    size="icon-sm"
                    :aria-label="`Remove ${row.label}`"
                    @click="openDelete(row, $event)"
                  >
                    <Trash2 />
                  </Button>
                </div>
              </div>
            </div>

            <!-- Add field -->
            <div class="wo-form-field-row">
              <p class="data-card-title">Add field</p>
              <div class="wo-form-field-row-grid">
                <div class="form-field">
                  <Label for="wo-new-field-label"
                    >Label <span class="field-required">*</span></Label
                  >
                  <Input
                    id="wo-new-field-label"
                    v-model="newLabel"
                    placeholder="E.g. Hours reading"
                  />
                </div>
                <div class="form-field">
                  <Label for="wo-new-field-type">Type</Label>
                  <Select v-model="newType">
                    <SelectTrigger id="wo-new-field-type"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem v-for="t in FIELD_TYPES" :key="t" :value="t">{{
                        woFormFieldTypeLabel(t)
                      }}</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div v-if="newType === 'numeric'" class="form-field">
                  <Label for="wo-new-field-unit">Unit</Label>
                  <Input id="wo-new-field-unit" v-model="newUnit" placeholder="E.g. PSI" />
                </div>
              </div>
              <div class="wo-form-field-row-flags">
                <Label class="checkbox-field">
                  <Checkbox v-model="newHasPrePost" />
                  <span>Pre + post values</span>
                </Label>
                <Label class="checkbox-field">
                  <Checkbox v-model="newIsRequired" />
                  <span>Required</span>
                </Label>
              </div>
              <p v-if="addError" class="form-error">{{ addError }}</p>
              <Button class="wo-form-add-field-btn" :disabled="fieldSaving" @click="handleAdd">
                {{ fieldSaving ? 'Adding…' : 'Add Field' }}
              </Button>
            </div>
          </template>
        </div>
      </div>

      <div class="create-sheet-footer">
        <Button variant="outline" :disabled="saving" @click="requestClose">Cancel</Button>
        <Button :disabled="saving" @click="handleSave">
          {{ saving ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Template' }}
        </Button>
      </div>
    </SheetContent>
  </Sheet>

  <!-- Discard unsaved name/category edits. Mirrors WoChecklistSheet; field edits
       save immediately so they are never at risk here. -->
  <Dialog v-model:open="discardOpen">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Discard changes?</DialogTitle>
        <DialogDescription>
          Your unsaved name and category edits will be lost. Fields already saved are not affected.
        </DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button variant="outline" @click="discardOpen = false">Keep editing</Button>
        <Button variant="destructive" @click="confirmDiscard">Discard</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
