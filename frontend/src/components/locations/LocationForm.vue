<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from '@/components/ui/sheet'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import type { Location } from '@/types'

const props = defineProps<{
  open: boolean
  editing: Location | null
  locations: Location[]
  validationErrors?: Record<string, string[]> | null
}>()

const emit = defineEmits<{
  close: []
  save: [
    payload: {
      name: string
      type: string
      code: string | null
      parent_id: number | null
      description: string | null
      is_active: boolean
    },
  ]
}>()

// ── Form state ────────────────────────────────────────────────────────────────
const name = ref('')
const locationType = ref('building')
const code = ref('')
const parentId = ref<string>('__none__')
const description = ref('')
const errorMessage = ref<string | null>(null)
const isEdit = ref(false)

// Mirrors App\Enums\LocationType. `workshop_yard` was offered here but is not a
// case, so locations created with it fell out of every utilisation figure and
// would fail the work-order start guard. Its label survives in displayHelpers
// so any legacy row still renders.
const typeOptions = ['workshop', 'yard', 'well_site', 'rig', 'building']

function resetForm() {
  name.value = ''
  locationType.value = 'building'
  code.value = ''
  parentId.value = '__none__'
  description.value = ''
  errorMessage.value = null
}

watch(
  () => props.open,
  (nowOpen) => {
    if (!nowOpen) return
    if (props.editing) {
      isEdit.value = true
      name.value = props.editing.name
      locationType.value = props.editing.type
      code.value = props.editing.code ?? ''
      parentId.value = props.editing.parent_id ? String(props.editing.parent_id) : '__none__'
      description.value = props.editing.description ?? ''
    } else {
      isEdit.value = false
      resetForm()
    }
  },
)

const parentOptions = computed(() => props.locations.filter((l) => l.id !== props.editing?.id))

const title = computed(() => (isEdit.value ? 'Edit Location' : 'Create Location'))

// Server-side validation feedback (e.g. a code taken by a concurrent create).
const backendError = computed(() => {
  if (!props.validationErrors) return null
  return Object.values(props.validationErrors)[0]?.[0] ?? null
})

// ── Submit ────────────────────────────────────────────────────────────────────
function handleSave() {
  if (!name.value.trim()) {
    errorMessage.value = 'Location name is required.'
    return
  }
  if (!locationType.value) {
    errorMessage.value = 'Location type is required.'
    return
  }
  const trimmedCode = code.value.trim()
  if (!trimmedCode) {
    errorMessage.value = 'Location code is required.'
    return
  }
  if (trimmedCode.length < 2 || trimmedCode.length > 3) {
    errorMessage.value = 'Location code must be 2 or 3 characters.'
    return
  }
  const codeTaken = props.locations.some(
    (l) => l.code === trimmedCode && l.id !== props.editing?.id,
  )
  if (codeTaken) {
    errorMessage.value = 'This location code is already in use.'
    return
  }
  errorMessage.value = null
  emit('save', {
    name: name.value.trim(),
    type: locationType.value,
    code: trimmedCode,
    parent_id: parentId.value === '__none__' ? null : Number(parentId.value),
    description: description.value.trim() || null,
    is_active: props.editing ? props.editing.is_active : true,
  })
}
</script>

<template>
  <Sheet :open="open" :modal="false" @update:open="(v) => !v && emit('close')">
    <SheetContent side="right" class="create-sheet">
      <div class="create-sheet-header">
        <SheetHeader>
          <SheetTitle>{{ title }}</SheetTitle>
          <SheetDescription>Define a physical location for asset tracking.</SheetDescription>
        </SheetHeader>
      </div>

      <div class="create-sheet-body">
        <div v-if="errorMessage || backendError" class="error-state" role="alert">
          {{ errorMessage || backendError }}
        </div>

        <div class="sheet-form">
          <div class="form-field">
            <Label for="loc-name">Name <span class="field-required">*</span></Label>
            <Input id="loc-name" v-model="name" placeholder="E.g. Warehouse, Rig…" />
          </div>

          <div class="form-field">
            <Label for="loc-type">Type <span class="field-required">*</span></Label>
            <Select v-model="locationType">
              <SelectTrigger id="loc-type"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem v-for="t in typeOptions" :key="t" :value="t">
                  {{ t.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase()) }}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div class="form-field">
            <Label for="loc-code">Code <span class="field-required">*</span></Label>
            <Input id="loc-code" v-model="code" maxlength="3" placeholder="E.g. WH, RG…" />
          </div>

          <div class="form-field">
            <Label for="loc-parent">
              Parent Location <span class="field-optional">— optional</span>
            </Label>
            <Select v-model="parentId">
              <SelectTrigger id="loc-parent">
                <SelectValue placeholder="No parent (top-level)" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__none__">No parent (top-level)</SelectItem>
                <SelectItem v-for="loc in parentOptions" :key="loc.id" :value="String(loc.id)">{{
                  loc.name
                }}</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div class="form-field form-field-full">
            <Label for="loc-desc">
              Description <span class="field-optional">— optional</span>
            </Label>
            <Textarea
              id="loc-desc"
              v-model="description"
              :rows="3"
              placeholder="Describe the location…"
            />
          </div>
        </div>
      </div>

      <div class="create-sheet-footer">
        <Button variant="outline" @click="emit('close')">Cancel</Button>
        <Button @click="handleSave">{{ isEdit ? 'Save Changes' : 'Create Location' }}</Button>
      </div>
    </SheetContent>
  </Sheet>
</template>
