<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import {
  Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription,
} from '@/components/ui/sheet'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
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
}>()

const emit = defineEmits<{
  close: []
  save: [payload: {
    name: string
    type: string
    code: string | null
    parent_id: number | null
    description: string | null
    is_active: boolean
  }]
}>()

// ── Form state ────────────────────────────────────────────────────────────────
const name = ref('')
const locationType = ref('building')
const code = ref('')
const parentId = ref<string>('__none__')
const description = ref('')
const errorMessage = ref<string | null>(null)
const isEdit = ref(false)

const typeOptions = ['workshop', 'yard', 'workshop_yard', 'well_site', 'rig', 'building']

function resetForm() {
  name.value = ''
  locationType.value = 'building'
  code.value = ''
  parentId.value = '__none__'
  description.value = ''
  errorMessage.value = null
}

watch(() => props.open, (nowOpen) => {
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
})

const parentOptions = computed(() =>
  props.locations.filter((l) => l.id !== props.editing?.id),
)

const title = computed(() => isEdit.value ? 'Edit Location' : 'Create Location')

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
  errorMessage.value = null
  emit('save', {
    name: name.value.trim(),
    type: locationType.value,
    code: code.value.trim() || null,
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
        <div v-if="errorMessage" class="error-state" role="alert">{{ errorMessage }}</div>

        <div class="sheet-form">
          <div class="form-field">
            <Label for="loc-name">Name <span class="field-required">*</span></Label>
            <Input id="loc-name" v-model="name" placeholder="E.g. Workshop, Rig A…" />
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
            <Label for="loc-code">Code <span class="field-optional">— optional</span></Label>
            <Input id="loc-code" v-model="code" placeholder="E.g. WS, RA…" />
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
                <SelectItem
                  v-for="loc in parentOptions"
                  :key="loc.id"
                  :value="String(loc.id)"
                >{{ loc.name }}</SelectItem>
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
