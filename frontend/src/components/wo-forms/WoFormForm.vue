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
import { faSubclassLabel } from '@/lib/displayHelpers'
import type { WoFormTemplate, FaSubclassTypeCode } from '@/types'
import type { WoFormTemplatePayload } from '@/composables/useWoForms'

const props = defineProps<{
  open: boolean
  editing: WoFormTemplate | null
  faSubclasses: FaSubclassTypeCode[]
  templates: WoFormTemplate[]
  saving: boolean
  validationErrors: Record<string, string[]> | null
}>()

const emit = defineEmits<{
  close: []
  save: [payload: WoFormTemplatePayload]
}>()

const isEdit = computed(() => props.editing !== null)

const name = ref('')
const faSubclassCode = ref('')
const localError = ref('')

// On create, exclude subclasses that already have an active template — the
// backend 422 remains the backstop for races between two admins.
const availableSubclasses = computed(() => {
  if (isEdit.value) return props.faSubclasses
  const takenCodes = new Set(
    props.templates.filter((t) => t.is_active).map((t) => t.fa_subclass_code),
  )
  return props.faSubclasses.filter((s) => !takenCodes.has(s.fa_subclass_code))
})

watch(
  () => props.open,
  (nowOpen) => {
    if (!nowOpen) return
    localError.value = ''
    const e = props.editing
    if (e) {
      name.value = e.name
      faSubclassCode.value = e.fa_subclass_code
    } else {
      name.value = ''
      faSubclassCode.value = ''
    }
  },
)

function handleSave() {
  localError.value = ''
  if (!name.value.trim()) {
    localError.value = 'Template name is required.'
    return
  }

  if (isEdit.value) {
    emit('save', { name: name.value.trim() })
    return
  }

  if (!faSubclassCode.value.trim()) {
    localError.value = 'Asset class is required.'
    return
  }
  emit('save', { name: name.value.trim(), fa_subclass_code: faSubclassCode.value.trim() })
}

const title = computed(() => (isEdit.value ? 'Edit WO Form Template' : 'Create WO Form Template'))
</script>

<template>
  <Sheet :open="open" :modal="false" @update:open="(v) => !v && emit('close')">
    <SheetContent side="right" class="create-sheet">
      <div class="create-sheet-header">
        <SheetHeader>
          <SheetTitle>{{ title }}</SheetTitle>
          <SheetDescription>
            Define the WO Form for one asset class. Manage its fields from the "Manage Fields"
            action after saving.
          </SheetDescription>
        </SheetHeader>
      </div>

      <div class="create-sheet-body">
        <div v-if="localError" class="error-state" role="alert">{{ localError }}</div>

        <div class="sheet-form">
          <div class="form-field">
            <Label for="wo-form-name">Template Name <span class="field-required">*</span></Label>
            <Input id="wo-form-name" v-model="name" placeholder="E.g. Mud Motor Inspection" />
            <p v-if="validationErrors?.name" class="form-error">{{ validationErrors.name[0] }}</p>
          </div>

          <div class="form-field">
            <Label for="wo-form-subclass">Asset Class <span class="field-required">*</span></Label>
            <p v-if="isEdit" class="detail-field-value">{{ faSubclassLabel(faSubclassCode) }}</p>
            <Select v-else v-model="faSubclassCode">
              <SelectTrigger id="wo-form-subclass"
                ><SelectValue placeholder="Select an asset class…"
              /></SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="s in availableSubclasses"
                  :key="s.fa_subclass_code"
                  :value="s.fa_subclass_code"
                >
                  {{ faSubclassLabel(s.fa_subclass_code) }}
                </SelectItem>
              </SelectContent>
            </Select>
            <p v-if="isEdit" class="form-help">The asset class cannot be changed after creation.</p>
            <p v-else-if="availableSubclasses.length === 0" class="form-help">
              Every asset class already has an active template.
            </p>
            <p v-if="validationErrors?.fa_subclass_code" class="form-error">
              {{ validationErrors.fa_subclass_code[0] }}
            </p>
          </div>
        </div>
      </div>

      <div class="create-sheet-footer">
        <Button variant="outline" :disabled="saving" @click="emit('close')">Cancel</Button>
        <Button :disabled="saving" @click="handleSave">
          {{ saving ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Template' }}
        </Button>
      </div>
    </SheetContent>
  </Sheet>
</template>
