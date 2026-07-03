<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import AppDataTable from '@/components/app/AppDataTable.vue'
import WoFormForm from '@/components/wo-forms/WoFormForm.vue'
import WoFormFieldsSheet from '@/components/wo-forms/WoFormFieldsSheet.vue'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import { useWoForms } from '@/composables/useWoForms'
import { faSubclassLabel } from '@/lib/displayHelpers'
import { Pencil, ToggleLeft, ToggleRight } from '@lucide/vue'
import type { AppColumnDef } from '@/lib/appTable'
import type { WoFormTemplate } from '@/types'
import type { WoFormTemplatePayload, WoFormFieldPayload } from '@/composables/useWoForms'

const {
  templates,
  templatesLoading,
  templatesError,
  loadTemplates,
  template,
  templateLoading,
  loadTemplate,
  faSubclasses,
  loadFaSubclasses,
  saving,
  validationErrors,
  createTemplate,
  updateTemplate,
  acting,
  deactivateTemplate,
  reactivateTemplate,
  fieldSaving,
  fieldErrors,
  addField,
  updateField,
  deleteField,
  reorderFields,
} = useWoForms()

// ── Status filter ─────────────────────────────────────────────────────────────
const statusFilter = ref<'active' | 'inactive' | 'all'>('active')

const filteredTemplates = computed<WoFormTemplate[]>(() => {
  if (statusFilter.value === 'all') return templates.value
  const wantActive = statusFilter.value === 'active'
  return templates.value.filter((t) => t.is_active === wantActive)
})

// ── Columns ───────────────────────────────────────────────────────────────────
const columns: AppColumnDef<WoFormTemplate>[] = [
  { field: 'name', header: 'Template', sortable: true, minWidth: 200 },
  { field: 'fa_subclass_code', header: 'Asset Class', sortable: true },
  { field: 'fields_count', header: 'Fields', sortable: false, align: 'center' },
  { field: 'is_active', header: 'Active', sortable: true, align: 'center' },
  { field: 'actions', header: '', sortable: false, align: 'center', minWidth: 180 },
]

onMounted(() => {
  loadTemplates()
  loadFaSubclasses()
})

// ── Create / Edit metadata sheet ──────────────────────────────────────────────
const formOpen = ref(false)
const editing = ref<WoFormTemplate | null>(null)

function openCreate() {
  editing.value = null
  validationErrors.value = null
  formOpen.value = true
}

function openEdit(t: WoFormTemplate) {
  editing.value = t
  validationErrors.value = null
  formOpen.value = true
}

function closeForm() {
  formOpen.value = false
  editing.value = null
  validationErrors.value = null
}

async function onSave(payload: WoFormTemplatePayload) {
  const result = editing.value
    ? await updateTemplate(editing.value.id, payload)
    : await createTemplate(payload)
  if (result) {
    toast.success(editing.value ? 'Template updated.' : 'Template created.')
    await loadTemplates(true)
    closeForm()
  } else if (!validationErrors.value) {
    toast.error('Failed to save template.')
  }
}

// ── Activate / Deactivate ─────────────────────────────────────────────────────
const toggleOpen = ref(false)
const toggleTarget = ref<WoFormTemplate | null>(null)

function openToggle(t: WoFormTemplate) {
  toggleTarget.value = t
  toggleOpen.value = true
}

async function confirmToggle() {
  if (!toggleTarget.value) return
  const t = toggleTarget.value
  const res = t.is_active ? await deactivateTemplate(t.id) : await reactivateTemplate(t.id)
  if (res.ok) {
    toast.success(t.is_active ? 'Template deactivated.' : 'Template reactivated.')
    await loadTemplates(true)
    toggleOpen.value = false
    toggleTarget.value = null
  } else {
    toast.error(res.message ?? 'Action failed.')
  }
}

// ── Manage fields ──────────────────────────────────────────────────────────────
const fieldsOpen = ref(false)
const fieldAddedTick = ref(0)

async function openManageFields(t: WoFormTemplate) {
  await loadTemplate(t.id)
  fieldsOpen.value = true
}

function closeFieldsSheet() {
  fieldsOpen.value = false
}

async function onAddField(payload: WoFormFieldPayload) {
  if (!template.value) return
  const result = await addField(template.value.id, payload)
  if (result) {
    toast.success('Field added.')
    await loadTemplate(template.value.id)
    await loadTemplates(true)
    fieldAddedTick.value++
  } else {
    toast.error('Failed to add field.')
  }
}

async function onUpdateField(fieldId: number, payload: WoFormFieldPayload) {
  if (!template.value) return
  const result = await updateField(template.value.id, fieldId, payload)
  // Reload on both outcomes — the fields sheet mutates its local draft
  // optimistically, so a failure must resync it back to server truth too.
  await loadTemplate(template.value.id)
  if (!result) toast.error('Failed to update field.')
}

async function onDeleteField(fieldId: number) {
  if (!template.value) return
  const result = await deleteField(template.value.id, fieldId)
  if (result.ok) {
    toast.success('Field removed.')
    await loadTemplate(template.value.id)
    await loadTemplates(true)
  } else {
    toast.error(result.message ?? 'Failed to remove field.')
  }
}

async function onReorderFields(fieldIds: number[]) {
  if (!template.value) return
  const result = await reorderFields(template.value.id, fieldIds)
  // Reload on both outcomes — the fields sheet swaps its local rows
  // optimistically, so a failure must resync it back to server order too.
  await loadTemplate(template.value.id)
  if (!result) toast.error('Failed to reorder fields.')
}
</script>

<template>
  <div class="page-content">
    <div class="filter-bar">
      <div class="filter-group">
        <span class="detail-field-muted">Status:</span>
        <Select v-model="statusFilter">
          <SelectTrigger class="asset-location-filter"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="active">Active</SelectItem>
            <SelectItem value="inactive">Inactive</SelectItem>
            <SelectItem value="all">All</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <div class="filter-actions">
        <Button @click="openCreate">Create Form</Button>
      </div>
    </div>

    <div v-if="templatesError" class="error-state" role="alert">{{ templatesError }}</div>

    <AppDataTable
      :key="statusFilter"
      :rows="filteredTemplates"
      :columns="columns"
      empty-text="No WO Form templates defined."
      label="WO Form Templates"
      :loading="templatesLoading"
    >
      <template #cell="{ column, row }">
        <span v-if="column.field === 'name'" class="table-cell-primary">{{ row.name }}</span>

        <span v-else-if="column.field === 'fa_subclass_code'" class="table-cell-secondary">
          {{ faSubclassLabel(row.fa_subclass_code) }}
        </span>

        <span v-else-if="column.field === 'fields_count'" class="table-cell-secondary">
          {{ row.fields_count ?? row.fields?.length ?? 0 }}
        </span>

        <span
          v-else-if="column.field === 'is_active'"
          :class="row.is_active ? 'status-badge status-active' : 'status-badge status-inactive'"
          >{{ row.is_active ? 'Active' : 'Inactive' }}</span
        >

        <div v-else-if="column.field === 'actions'" class="table-row-actions">
          <Button variant="outline" size="sm" @click="openManageFields(row)">Manage Fields</Button>
          <Button
            variant="outline"
            size="icon-sm"
            :aria-label="`Edit ${row.name}`"
            @click="openEdit(row)"
          >
            <Pencil />
          </Button>
          <Button
            variant="ghost"
            size="icon-sm"
            :aria-label="`${row.is_active ? 'Deactivate' : 'Reactivate'} ${row.name}`"
            @click="openToggle(row)"
          >
            <ToggleRight v-if="row.is_active" />
            <ToggleLeft v-else />
          </Button>
        </div>
      </template>
    </AppDataTable>

    <!-- Create / Edit metadata sheet -->
    <WoFormForm
      :open="formOpen"
      :editing="editing"
      :fa-subclasses="faSubclasses"
      :templates="templates"
      :saving="saving"
      :validation-errors="validationErrors"
      @close="closeForm"
      @save="onSave"
    />

    <!-- Manage fields -->
    <WoFormFieldsSheet
      :open="fieldsOpen"
      :template="template"
      :loading="templateLoading"
      :saving="fieldSaving"
      :errors="fieldErrors"
      :added-tick="fieldAddedTick"
      @close="closeFieldsSheet"
      @add="onAddField"
      @update="onUpdateField"
      @delete="onDeleteField"
      @reorder="onReorderFields"
    />

    <!-- Activate / Deactivate confirm -->
    <Dialog v-model:open="toggleOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{{
            toggleTarget?.is_active ? 'Deactivate Form Template' : 'Reactivate Form Template'
          }}</DialogTitle>
          <DialogDescription v-if="toggleTarget">
            {{
              toggleTarget.is_active
                ? `Deactivate "${toggleTarget.name}"? New work orders for ${faSubclassLabel(toggleTarget.fa_subclass_code)} assets will no longer snapshot this form. Existing work order forms are unaffected.`
                : `Reactivate "${toggleTarget.name}"? It becomes available for new work order snapshots again. Blocked if another active template already covers ${faSubclassLabel(toggleTarget.fa_subclass_code)}.`
            }}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" @click="toggleOpen = false">Cancel</Button>
          <Button
            :variant="toggleTarget?.is_active ? 'destructive' : 'default'"
            :disabled="acting"
            @click="confirmToggle"
          >
            {{ acting ? 'Working…' : toggleTarget?.is_active ? 'Deactivate' : 'Reactivate' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
