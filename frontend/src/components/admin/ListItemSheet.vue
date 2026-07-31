<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import type { ListGroup, ListItem } from '@/composables/useLists'
import type { MasterDataItem, UsageReadingType, MaintenanceCategoryOption } from '@/types'

const props = defineProps<{
  open: boolean
  group: ListGroup
  editing: ListItem | null
  saving: boolean
  validationErrors: Record<string, string[]> | null
}>()

const emit = defineEmits<{
  close: []
  save: [payload: Record<string, unknown>]
}>()

const isEdit = computed(() => props.editing !== null)

// ── Form state (superset of all three kinds) ─────────────────────────────────
const label = ref('')
const value = ref('')
const sortOrder = ref('')
const name = ref('')
const unit = ref('')
/** Read-only code of the maintenance category being edited (empty on create). */
const categoryCode = ref('')
const localError = ref('')

function reset() {
  label.value = ''
  value.value = ''
  sortOrder.value = ''
  name.value = ''
  unit.value = ''
  categoryCode.value = ''
  localError.value = ''
}

watch(
  () => props.open,
  (nowOpen) => {
    if (!nowOpen) return
    reset()
    const e = props.editing
    if (!e) return
    if (props.group.kind === 'master_data') {
      const m = e as MasterDataItem
      label.value = m.label
      value.value = m.value
      sortOrder.value = m.sort_order != null ? String(m.sort_order) : ''
    } else if (props.group.kind === 'reading_types') {
      const r = e as UsageReadingType
      name.value = r.name
      unit.value = r.unit
    } else {
      const c = e as MaintenanceCategoryOption
      name.value = c.name
      categoryCode.value = c.code
    }
  },
)

const title = computed(() => {
  const verb = isEdit.value ? 'Edit' : 'New'
  const noun = props.group.label.replace(/ies$/, 'y').replace(/s$/, '')
  return `${verb} ${noun}`
})

function handleSave() {
  localError.value = ''
  if (props.group.kind === 'master_data') {
    if (!label.value.trim() || !value.value.trim()) {
      localError.value = 'Label and value are required.'
      return
    }
    const payload: Record<string, unknown> = {
      label: label.value.trim(),
      value: value.value.trim(),
    }
    if (sortOrder.value !== '') payload.sort_order = Number(sortOrder.value)
    emit('save', payload)
  } else if (props.group.kind === 'reading_types') {
    if (!name.value.trim() || !unit.value.trim()) {
      localError.value = 'Name and unit are required.'
      return
    }
    emit('save', { name: name.value.trim(), unit: unit.value.trim() })
  } else {
    if (!name.value.trim()) {
      localError.value = 'Name is required.'
      return
    }
    // The category code is generated from the name on create and is immutable
    // afterwards, so only the name is ever submitted.
    emit('save', { name: name.value.trim() })
  }
}
</script>

<template>
  <Sheet :open="open" :modal="false" @update:open="(v) => !v && emit('close')">
    <SheetContent side="right" class="create-sheet">
      <div class="create-sheet-header">
        <SheetHeader>
          <SheetTitle>{{ title }}</SheetTitle>
          <SheetDescription>{{ group.label }}</SheetDescription>
        </SheetHeader>
      </div>

      <div class="create-sheet-body">
        <div v-if="localError" class="error-state" role="alert">{{ localError }}</div>

        <div class="sheet-form">
          <!-- Master data fields -->
          <template v-if="group.kind === 'master_data'">
            <div class="form-field">
              <Label for="list-label">Label <span class="field-required">*</span></Label>
              <Input id="list-label" v-model="label" placeholder="Human-readable label" />
              <p v-if="validationErrors?.label" class="form-error">
                {{ validationErrors.label[0] }}
              </p>
            </div>
            <div class="form-field">
              <Label for="list-value">Value <span class="field-required">*</span></Label>
              <Input id="list-value" v-model="value" placeholder="Stored value (e.g. high)" />
              <p class="form-help">The internal code stored on records. Lowercase, no spaces.</p>
              <p v-if="validationErrors?.value" class="form-error">
                {{ validationErrors.value[0] }}
              </p>
            </div>
            <div class="form-field">
              <Label for="list-sort"
                >Sort Order <span class="field-optional">— optional</span></Label
              >
              <Input id="list-sort" v-model="sortOrder" type="number" placeholder="0" />
              <p v-if="validationErrors?.sort_order" class="form-error">
                {{ validationErrors.sort_order[0] }}
              </p>
            </div>
          </template>

          <!-- Usage reading type fields -->
          <template v-else-if="group.kind === 'reading_types'">
            <div class="form-field">
              <Label for="list-name">Name <span class="field-required">*</span></Label>
              <Input id="list-name" v-model="name" placeholder="E.g. Operating Hours" />
              <p v-if="validationErrors?.name" class="form-error">{{ validationErrors.name[0] }}</p>
            </div>
            <div class="form-field">
              <Label for="list-unit">Unit <span class="field-required">*</span></Label>
              <Input id="list-unit" v-model="unit" placeholder="E.g. hours, km" />
              <p v-if="validationErrors?.unit" class="form-error">{{ validationErrors.unit[0] }}</p>
            </div>
          </template>

          <!-- Maintenance category fields -->
          <template v-else>
            <div class="form-field">
              <Label for="list-cat-name">Name <span class="field-required">*</span></Label>
              <Input id="list-cat-name" v-model="name" placeholder="E.g. Mud Motor" />
              <p v-if="isEdit" class="form-help">
                The code <strong>{{ categoryCode }}</strong> cannot be changed after creation.
              </p>
              <p v-else class="form-help">A stable code is generated from the name.</p>
              <p v-if="validationErrors?.name" class="form-error">
                {{ validationErrors.name[0] }}
              </p>
            </div>
          </template>
        </div>
      </div>

      <div class="create-sheet-footer">
        <Button variant="outline" :disabled="saving" @click="emit('close')">Cancel</Button>
        <Button :disabled="saving" @click="handleSave">
          {{ saving ? 'Saving…' : isEdit ? 'Save Changes' : 'Create' }}
        </Button>
      </div>
    </SheetContent>
  </Sheet>
</template>
