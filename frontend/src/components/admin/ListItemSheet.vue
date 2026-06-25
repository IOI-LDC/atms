<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import {
  Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription,
} from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Checkbox } from '@/components/ui/checkbox'
import type { ListGroup, ListItem } from '@/composables/useLists'
import type { MasterDataItem, UsageReadingType, FaSubclassTypeCode } from '@/types'

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
const faSubclassCode = ref('')
const typeCode = ref('')
const description = ref('')
const hasNoPhysicalSize = ref(false)
const localError = ref('')

function reset() {
  label.value = ''
  value.value = ''
  sortOrder.value = ''
  name.value = ''
  unit.value = ''
  faSubclassCode.value = ''
  typeCode.value = ''
  description.value = ''
  hasNoPhysicalSize.value = false
  localError.value = ''
}

watch(() => props.open, (nowOpen) => {
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
    const f = e as FaSubclassTypeCode
    faSubclassCode.value = f.fa_subclass_code
    typeCode.value = f.type_code
    description.value = f.description ?? ''
    hasNoPhysicalSize.value = f.has_no_physical_size
  }
})

const title = computed(() => {
  const verb = isEdit.value ? 'Edit' : 'New'
  const noun = props.group.label.replace(/s$/, '')
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
    if (!isEdit.value && !faSubclassCode.value.trim()) {
      localError.value = 'FA subclass code is required.'
      return
    }
    if (!typeCode.value.trim()) {
      localError.value = 'Type code is required.'
      return
    }
    const payload: Record<string, unknown> = {
      type_code: typeCode.value.trim(),
      description: description.value.trim() || null,
      has_no_physical_size: hasNoPhysicalSize.value,
    }
    // fa_subclass_code is immutable once created (it is the route key).
    if (!isEdit.value) payload.fa_subclass_code = faSubclassCode.value.trim()
    emit('save', payload)
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
              <p v-if="validationErrors?.label" class="form-error">{{ validationErrors.label[0] }}</p>
            </div>
            <div class="form-field">
              <Label for="list-value">Value <span class="field-required">*</span></Label>
              <Input id="list-value" v-model="value" placeholder="Stored value (e.g. high)" />
              <p class="form-help">The internal code stored on records. Lowercase, no spaces.</p>
              <p v-if="validationErrors?.value" class="form-error">{{ validationErrors.value[0] }}</p>
            </div>
            <div class="form-field">
              <Label for="list-sort">Sort Order <span class="field-optional">— optional</span></Label>
              <Input id="list-sort" v-model="sortOrder" type="number" placeholder="0" />
              <p v-if="validationErrors?.sort_order" class="form-error">{{ validationErrors.sort_order[0] }}</p>
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

          <!-- FA subclass type code fields -->
          <template v-else>
            <div class="form-field">
              <Label for="list-fa-code">FA Subclass Code <span class="field-required">*</span></Label>
              <Input
                id="list-fa-code"
                v-model="faSubclassCode"
                placeholder="E.g. MUD MOTOR"
                :disabled="isEdit"
              />
              <p v-if="isEdit" class="form-help">The subclass code cannot be changed after creation.</p>
              <p v-if="validationErrors?.fa_subclass_code" class="form-error">
                {{ validationErrors.fa_subclass_code[0] }}
              </p>
            </div>
            <div class="form-field">
              <Label for="list-type-code">Type Code <span class="field-required">*</span></Label>
              <Input id="list-type-code" v-model="typeCode" maxlength="3" placeholder="Max 3 chars" />
              <p v-if="validationErrors?.type_code" class="form-error">{{ validationErrors.type_code[0] }}</p>
            </div>
            <div class="form-field form-field-full">
              <Label for="list-desc">Description <span class="field-optional">— optional</span></Label>
              <Textarea id="list-desc" v-model="description" :rows="2" placeholder="Describe this subclass…" />
            </div>
            <div class="form-field">
              <Label class="checkbox-field">
                <Checkbox v-model="hasNoPhysicalSize" />
                <span>Has no physical size</span>
              </Label>
            </div>
          </template>
        </div>
      </div>

      <div class="create-sheet-footer">
        <Button variant="outline" :disabled="saving" @click="emit('close')">Cancel</Button>
        <Button :disabled="saving" @click="handleSave">
          {{ saving ? 'Saving…' : (isEdit ? 'Save Changes' : 'Create') }}
        </Button>
      </div>
    </SheetContent>
  </Sheet>
</template>
