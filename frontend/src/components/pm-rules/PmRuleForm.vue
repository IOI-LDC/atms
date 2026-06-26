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
import { pmTriggerLabel } from '@/lib/displayHelpers'
import type { PmRule, UsageReadingType } from '@/types'
import type { PmRulePayload } from '@/composables/usePmRules'

interface BatchResult { index: number; ok: boolean; errors?: Record<string, string[]>; message?: string }

const props = defineProps<{
  open: boolean
  editing: PmRule | null
  readingTypes: UsageReadingType[]
  saving: boolean
  validationErrors: Record<string, string[]> | null
  batchResults: BatchResult[] | null
}>()

const emit = defineEmits<{
  close: []
  saveSingle: [payload: PmRulePayload]
  saveMulti: [payloads: PmRulePayload[]]
}>()

const isEdit = computed(() => props.editing !== null)

const TRIGGER_OPTIONS = [
  { value: 'date',            label: 'Calendar (date-based)' },
  { value: 'reading',         label: 'Usage (reading-based)' },
  { value: 'date_or_reading', label: 'Whichever comes first' },
]
const LEVEL_OPTIONS = ['L1', 'L2', 'L3', 'L4']

// ── Mode (create only) ────────────────────────────────────────────────────────
const mode = ref<'single' | 'multi'>('single')

// ── Single-template form state ────────────────────────────────────────────────
const name = ref('')
const description = ref('')
const levelChoice = ref('__none__')   // '__none__' | 'L1'..'L4' | '__custom__'
const levelCustom = ref('')
const triggerType = ref('date')
const intervalDays = ref('')
const intervalReading = ref('')
const readingTypeId = ref('')
const localError = ref('')

const showDays = computed(() => triggerType.value === 'date' || triggerType.value === 'date_or_reading')
const showReading = computed(() => triggerType.value === 'reading' || triggerType.value === 'date_or_reading')

function resolvedLevel(choice: string, custom: string): string | null {
  if (choice === '__none__') return null
  if (choice === '__custom__') return custom.trim() || null
  return choice
}

// ── Multi-level rows ──────────────────────────────────────────────────────────
interface LevelRow {
  level: string
  name: string
  triggerType: string
  intervalDays: string
  intervalReading: string
  readingTypeId: string
}
function freshRows(): LevelRow[] {
  return ['L1', 'L2', 'L3'].map((lvl) => ({
    level: lvl,
    name: `${lvl} Maintenance`,
    triggerType: 'date',
    intervalDays: '',
    intervalReading: '',
    readingTypeId: '',
  }))
}
const rows = ref<LevelRow[]>(freshRows())

function resultFor(index: number): BatchResult | undefined {
  return props.batchResults?.find((r) => r.index === index)
}

// ── Reset on open ─────────────────────────────────────────────────────────────
watch(() => props.open, (nowOpen) => {
  if (!nowOpen) return
  localError.value = ''
  mode.value = 'single'
  const e = props.editing
  if (e) {
    name.value = e.name
    description.value = e.description ?? ''
    triggerType.value = e.trigger_type
    intervalDays.value = e.interval_days != null ? String(e.interval_days) : ''
    intervalReading.value = e.interval_reading != null ? String(e.interval_reading) : ''
    readingTypeId.value = e.usage_reading_type?.id != null ? String(e.usage_reading_type.id) : ''
    if (!e.maintenance_level) {
      levelChoice.value = '__none__'
      levelCustom.value = ''
    } else if (LEVEL_OPTIONS.includes(e.maintenance_level)) {
      levelChoice.value = e.maintenance_level
      levelCustom.value = ''
    } else {
      levelChoice.value = '__custom__'
      levelCustom.value = e.maintenance_level
    }
  } else {
    name.value = ''
    description.value = ''
    levelChoice.value = '__none__'
    levelCustom.value = ''
    triggerType.value = 'date'
    intervalDays.value = ''
    intervalReading.value = ''
    readingTypeId.value = ''
    rows.value = freshRows()
  }
})

// ── Submit ────────────────────────────────────────────────────────────────────
function buildTriggerFields(tt: string, days: string, reading: string, readType: string) {
  const payload: Partial<PmRulePayload> = {}
  if (tt === 'date' || tt === 'date_or_reading') {
    payload.interval_days = days !== '' ? Number(days) : null
  }
  if (tt === 'reading' || tt === 'date_or_reading') {
    payload.interval_reading = reading !== '' ? Number(reading) : null
    payload.usage_reading_type_id = readType !== '' ? Number(readType) : null
  }
  return payload
}

function handleSave() {
  localError.value = ''

  // Edit: trigger immutable; send editable fields only.
  if (isEdit.value && props.editing) {
    if (!name.value.trim()) { localError.value = 'Template name is required.'; return }
    const payload: PmRulePayload = {
      name: name.value.trim(),
      description: description.value.trim() || null,
      maintenance_level: resolvedLevel(levelChoice.value, levelCustom.value),
      ...buildTriggerFields(props.editing.trigger_type, intervalDays.value, intervalReading.value, readingTypeId.value),
    }
    emit('saveSingle', payload)
    return
  }

  // Create — single template
  if (mode.value === 'single') {
    if (!name.value.trim()) { localError.value = 'Template name is required.'; return }
    const tf = buildTriggerFields(triggerType.value, intervalDays.value, intervalReading.value, readingTypeId.value)
    if (showDays.value && !tf.interval_days) { localError.value = 'Calendar interval (days) is required.'; return }
    if (showReading.value && !tf.interval_reading) { localError.value = 'Usage interval is required.'; return }
    if (showReading.value && !tf.usage_reading_type_id) { localError.value = 'Reading type is required.'; return }
    emit('saveSingle', {
      name: name.value.trim(),
      description: description.value.trim() || null,
      maintenance_level: resolvedLevel(levelChoice.value, levelCustom.value),
      trigger_type: triggerType.value,
      ...tf,
    })
    return
  }

  // Create — multi-level templates
  const payloads: PmRulePayload[] = []
  for (const row of rows.value) {
    if (!row.name.trim()) { localError.value = `${row.level} name is required.`; return }
    const tf = buildTriggerFields(row.triggerType, row.intervalDays, row.intervalReading, row.readingTypeId)
    const needsDays = row.triggerType === 'date' || row.triggerType === 'date_or_reading'
    const needsReading = row.triggerType === 'reading' || row.triggerType === 'date_or_reading'
    if (needsDays && !tf.interval_days) { localError.value = `${row.level} calendar interval is required.`; return }
    if (needsReading && (!tf.interval_reading || !tf.usage_reading_type_id)) {
      localError.value = `${row.level} usage interval and reading type are required.`; return
    }
    payloads.push({
      name: row.name.trim(),
      maintenance_level: row.level,
      trigger_type: row.triggerType,
      ...tf,
    })
  }
  emit('saveMulti', payloads)
}

const title = computed(() => (isEdit.value ? 'Edit PM Template' : 'Create PM Template'))
</script>

<template>
  <Sheet :open="open" :modal="false" @update:open="(v) => !v && emit('close')">
    <SheetContent side="right" class="create-sheet">
      <div class="create-sheet-header">
        <SheetHeader>
          <SheetTitle>{{ title }}</SheetTitle>
          <SheetDescription>Define a reusable preventive maintenance schedule. Templates are assigned to assets from the Asset Detail screen.</SheetDescription>
        </SheetHeader>
      </div>

      <div class="create-sheet-body">
        <div v-if="localError" class="error-state" role="alert">{{ localError }}</div>

        <!-- Mode toggle (create only) -->
        <div v-if="!isEdit" class="pm-mode-toggle">
          <Button
            class="pm-mode-toggle-btn"
            :variant="mode === 'single' ? 'default' : 'ghost'"
            size="sm"
            @click="mode = 'single'"
          >Single Template</Button>
          <Button
            class="pm-mode-toggle-btn"
            :variant="mode === 'multi' ? 'default' : 'ghost'"
            size="sm"
            @click="mode = 'multi'"
          >Multi-Level Setup</Button>
        </div>

        <div class="sheet-form">
          <!-- ── Single / Edit fields ──────────────────────────────────────── -->
          <template v-if="isEdit || mode === 'single'">
            <div class="form-field">
              <Label for="pm-name">Template Name <span class="field-required">*</span></Label>
              <Input id="pm-name" v-model="name" placeholder="E.g. Quarterly inspection" />
              <p v-if="validationErrors?.name" class="form-error">{{ validationErrors.name[0] }}</p>
            </div>

            <div class="form-field">
              <Label for="pm-level">Maintenance Level <span class="field-optional">— optional</span></Label>
              <Select v-model="levelChoice">
                <SelectTrigger id="pm-level"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="__none__">None</SelectItem>
                  <SelectItem v-for="lvl in LEVEL_OPTIONS" :key="lvl" :value="lvl">{{ lvl }}</SelectItem>
                  <SelectItem value="__custom__">Custom…</SelectItem>
                </SelectContent>
              </Select>
              <Input
                v-if="levelChoice === '__custom__'"
                v-model="levelCustom"
                maxlength="10"
                placeholder="Custom level label (max 10 chars)"
              />
            </div>

            <div class="form-field">
              <Label for="pm-trigger">Trigger Type <span class="field-required">*</span></Label>
              <p v-if="isEdit" class="detail-field-value">{{ pmTriggerLabel(triggerType) }}</p>
              <Select v-else v-model="triggerType">
                <SelectTrigger id="pm-trigger"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="t in TRIGGER_OPTIONS" :key="t.value" :value="t.value">{{ t.label }}</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div v-if="showDays" class="form-field">
              <Label for="pm-days">Calendar Interval (days) <span class="field-required">*</span></Label>
              <Input id="pm-days" v-model="intervalDays" type="number" min="1" placeholder="E.g. 90" />
              <p v-if="validationErrors?.interval_days" class="form-error">{{ validationErrors.interval_days[0] }}</p>
            </div>

            <div v-if="showReading" class="form-field">
              <Label for="pm-reading">Usage Interval <span class="field-required">*</span></Label>
              <Input id="pm-reading" v-model="intervalReading" type="number" min="0.01" step="0.01" placeholder="E.g. 500" />
              <p v-if="validationErrors?.interval_reading" class="form-error">{{ validationErrors.interval_reading[0] }}</p>
            </div>

            <div v-if="showReading" class="form-field">
              <Label for="pm-reading-type">Reading Type <span class="field-required">*</span></Label>
              <Select v-model="readingTypeId">
                <SelectTrigger id="pm-reading-type"><SelectValue placeholder="Select a reading type…" /></SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="rt in readingTypes" :key="rt.id" :value="String(rt.id)">
                    {{ rt.name }} ({{ rt.unit }})
                  </SelectItem>
                </SelectContent>
              </Select>
              <p v-if="readingTypes.length === 0" class="form-help">No reading types defined — add them under Admin → Lists.</p>
            </div>

            <div class="form-field form-field-full">
              <Label for="pm-desc">Description <span class="field-optional">— optional</span></Label>
              <Textarea id="pm-desc" v-model="description" :rows="2" placeholder="Optional notes about this schedule…" />
            </div>
          </template>

          <!-- ── Multi-level rows ──────────────────────────────────────────── -->
          <template v-else>
            <p class="form-help">
              Creates one template per level. Each is independent — if one fails, the others still apply. Assign them to assets from the Asset Detail screen.
            </p>
            <div class="pm-level-rows">
              <div v-for="(row, i) in rows" :key="row.level" class="pm-level-row">
                <div class="pm-level-row-header">
                  <p class="pm-level-row-title">{{ row.level }}</p>
                  <span v-if="resultFor(i)?.ok" class="status-badge status-active">Created</span>
                  <span v-else-if="resultFor(i)" class="status-badge status-rejected">Failed</span>
                </div>

                <div class="form-field">
                  <Label :for="`pm-row-name-${i}`">Template Name <span class="field-required">*</span></Label>
                  <Input :id="`pm-row-name-${i}`" v-model="row.name" />
                  <p v-if="resultFor(i) && !resultFor(i)!.ok" class="form-error">
                    {{ resultFor(i)!.message ?? 'Failed to create.' }}
                  </p>
                </div>

                <div class="form-field">
                  <Label :for="`pm-row-trigger-${i}`">Trigger Type</Label>
                  <Select v-model="row.triggerType">
                    <SelectTrigger :id="`pm-row-trigger-${i}`"><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem v-for="t in TRIGGER_OPTIONS" :key="t.value" :value="t.value">{{ t.label }}</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div v-if="row.triggerType === 'date' || row.triggerType === 'date_or_reading'" class="form-field">
                  <Label :for="`pm-row-days-${i}`">Calendar Interval (days)</Label>
                  <Input :id="`pm-row-days-${i}`" v-model="row.intervalDays" type="number" min="1" placeholder="E.g. 90" />
                </div>

                <template v-if="row.triggerType === 'reading' || row.triggerType === 'date_or_reading'">
                  <div class="form-field">
                    <Label :for="`pm-row-reading-${i}`">Usage Interval</Label>
                    <Input :id="`pm-row-reading-${i}`" v-model="row.intervalReading" type="number" min="0.01" step="0.01" placeholder="E.g. 500" />
                  </div>
                  <div class="form-field">
                    <Label :for="`pm-row-rt-${i}`">Reading Type</Label>
                    <Select v-model="row.readingTypeId">
                      <SelectTrigger :id="`pm-row-rt-${i}`"><SelectValue placeholder="Select…" /></SelectTrigger>
                      <SelectContent>
                        <SelectItem v-for="rt in readingTypes" :key="rt.id" :value="String(rt.id)">
                          {{ rt.name }} ({{ rt.unit }})
                        </SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                </template>
              </div>
            </div>
          </template>
        </div>
      </div>

      <div class="create-sheet-footer">
        <Button variant="outline" :disabled="saving" @click="emit('close')">Cancel</Button>
        <Button :disabled="saving" @click="handleSave">
          {{ saving ? 'Saving…' : (isEdit ? 'Save Changes' : (mode === 'multi' ? 'Create Templates' : 'Create Template')) }}
        </Button>
      </div>
    </SheetContent>
  </Sheet>
</template>
