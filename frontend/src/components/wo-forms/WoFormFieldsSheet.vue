<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
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
import { woFormFieldTypeLabel, faSubclassLabel } from '@/lib/displayHelpers'
import type { WoFormTemplate, WoFormTemplateField, WoFormFieldType } from '@/types'
import type { WoFormFieldPayload } from '@/composables/useWoForms'

const props = defineProps<{
  open: boolean
  template: WoFormTemplate | null
  loading: boolean
  saving: boolean
  errors: Record<string, string[]> | null
  /** Bumped by the parent only when an add-field call actually succeeds — lets
   * the add-field draft reset on confirmed success without also clearing it
   * (and the user's in-progress input) on an unrelated update/delete/reorder,
   * or on a failed add. */
  addedTick: number
}>()

const emit = defineEmits<{
  close: []
  add: [payload: WoFormFieldPayload]
  update: [fieldId: number, payload: WoFormFieldPayload]
  delete: [fieldId: number]
  reorder: [fieldIds: number[]]
}>()

const FIELD_TYPES: WoFormFieldType[] = ['boolean', 'numeric', 'text']

interface FieldDraft {
  id: number
  label: string
  field_type: WoFormFieldType
  unit: string
  has_pre_post: boolean
  is_required: boolean
}

const errorMessage = computed(() => Object.values(props.errors ?? {})[0]?.[0] ?? null)

// ── Rows — local editable copy, re-synced whenever the parent reloads the template ──
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

watch(
  () => props.template,
  (t) => {
    rows.value = draftsFrom(t?.fields)
  },
  { immediate: true },
)

function commitUpdate(row: FieldDraft) {
  emit('update', row.id, {
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
    'reorder',
    next.map((r) => r.id),
  )
}

function moveDown(i: number) {
  if (i === rows.value.length - 1) return
  const next = rows.value.slice()
  ;[next[i], next[i + 1]] = [next[i + 1]!, next[i]!]
  rows.value = next
  emit(
    'reorder',
    next.map((r) => r.id),
  )
}

// ── Add field ─────────────────────────────────────────────────────────────────
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

watch(
  () => props.open,
  (nowOpen) => {
    if (nowOpen) resetAddDraft()
  },
)
watch(
  () => props.addedTick,
  () => resetAddDraft(),
)

function handleAdd() {
  addError.value = ''
  if (!newLabel.value.trim()) {
    addError.value = 'Label is required.'
    return
  }
  emit('add', {
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

// ── Delete confirm ───────────────────────────────────────────────────────────
const deleteOpen = ref(false)
const deleteTarget = ref<FieldDraft | null>(null)

function openDelete(row: FieldDraft) {
  deleteTarget.value = row
  deleteOpen.value = true
}

function confirmDelete() {
  if (!deleteTarget.value) return
  emit('delete', deleteTarget.value.id)
  deleteOpen.value = false
  deleteTarget.value = null
}
</script>

<template>
  <Dialog :open="open" @update:open="(v) => !v && emit('close')">
    <DialogContent class="wo-form-fields-dialog">
      <DialogHeader>
        <DialogTitle>Manage Fields — {{ template?.name }}</DialogTitle>
        <DialogDescription
          >{{ template ? faSubclassLabel(template.fa_subclass_code) : '' }} · fields shown in
          display order</DialogDescription
        >
      </DialogHeader>

      <div v-if="loading" class="loading-state">Loading fields…</div>

      <template v-else-if="template">
        <div v-if="errorMessage" class="error-state" role="alert">{{ errorMessage }}</div>

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
                  :disabled="i === 0"
                  :aria-label="`Move ${row.label} up`"
                  @click="moveUp(i)"
                >
                  <ChevronUpIcon />
                </Button>
                <Button
                  variant="ghost"
                  size="icon-sm"
                  :disabled="i === rows.length - 1"
                  :aria-label="`Move ${row.label} down`"
                  @click="moveDown(i)"
                >
                  <ChevronDownIcon />
                </Button>
              </div>
              <Button
                variant="ghost"
                size="icon-sm"
                :aria-label="`Remove ${row.label}`"
                @click="openDelete(row)"
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
              <Label for="wo-new-field-label">Label <span class="field-required">*</span></Label>
              <Input id="wo-new-field-label" v-model="newLabel" placeholder="E.g. Hours reading" />
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
          <Button class="wo-form-add-field-btn" :disabled="saving" @click="handleAdd">
            {{ saving ? 'Adding…' : 'Add Field' }}
          </Button>
        </div>
      </template>

      <DialogFooter>
        <Button variant="outline" @click="emit('close')">Close</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <!-- Delete confirm -->
  <Dialog v-model:open="deleteOpen">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Remove field?</DialogTitle>
        <DialogDescription v-if="deleteTarget">
          Remove "{{ deleteTarget.label }}" from this template? Existing work order snapshots keep
          their captured values — only new snapshots stop including this field.
        </DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button variant="outline" @click="deleteOpen = false">Cancel</Button>
        <Button variant="destructive" :disabled="saving" @click="confirmDelete">
          {{ saving ? 'Removing…' : 'Remove Field' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
