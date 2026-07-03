<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import AppDataTable from '@/components/app/AppDataTable.vue'
import PmRuleForm from '@/components/pm-rules/PmRuleForm.vue'
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
import { usePmRules } from '@/composables/usePmRules'
import { useAuthStore } from '@/stores/auth.store'
import { pmLevelClass } from '@/lib/displayHelpers'
import { pmScheduleText } from '@/lib/pmSchedule'
import { Pencil, ToggleLeft, ToggleRight } from '@lucide/vue'
import type { AppColumnDef } from '@/lib/appTable'
import type { PmRule } from '@/types'
import type { PmRulePayload } from '@/composables/usePmRules'

const auth = useAuthStore()
const canConfigure = computed(() => auth.isAdmin)

const {
  rules,
  rulesLoading,
  rulesError,
  loadRules,
  readingTypes,
  loadReadingTypes,
  saving,
  validationErrors,
  createRule,
  createRulesBatch,
  updateRule,
  acting,
  deactivateRule,
  reactivateRule,
  evaluating,
  evaluateAll,
} = usePmRules()

// ── Status filter ─────────────────────────────────────────────────────────────
const statusFilter = ref<'active' | 'inactive' | 'all'>('active')

const filteredRules = computed<PmRule[]>(() => {
  if (statusFilter.value === 'all') return rules.value
  const wantActive = statusFilter.value === 'active'
  return rules.value.filter((r) => r.is_active === wantActive)
})

// ── Columns ───────────────────────────────────────────────────────────────────
const columns: AppColumnDef<PmRule>[] = [
  { field: 'name', header: 'Template', sortable: true, minWidth: 180 },
  { field: 'maintenance_level', header: 'Level', sortable: false, align: 'center' },
  { field: 'schedule', header: 'Schedule', sortable: false, minWidth: 220 },
  { field: 'assignments_count', header: 'Assets', sortable: false, align: 'center' },
  { field: 'is_active', header: 'Active', sortable: true, align: 'center' },
  { field: 'actions', header: '', sortable: false, align: 'center', minWidth: 120 },
]

onMounted(() => {
  loadRules()
  if (canConfigure.value) loadReadingTypes()
})

// ── Create / Edit form ────────────────────────────────────────────────────────
const formOpen = ref(false)
const editing = ref<PmRule | null>(null)
const batchResults = ref<
  { index: number; ok: boolean; errors?: Record<string, string[]>; message?: string }[] | null
>(null)

function openCreate() {
  editing.value = null
  validationErrors.value = null
  batchResults.value = null
  formOpen.value = true
}

function openEdit(rule: PmRule) {
  editing.value = rule
  validationErrors.value = null
  batchResults.value = null
  formOpen.value = true
}

function closeForm() {
  formOpen.value = false
  editing.value = null
  validationErrors.value = null
  batchResults.value = null
}

async function onSaveSingle(payload: PmRulePayload) {
  const result = editing.value
    ? await updateRule(editing.value.id, payload)
    : await createRule(payload)
  if (result) {
    toast.success(editing.value ? 'PM template updated.' : 'PM template created.')
    await loadRules(true)
    closeForm()
  }
}

async function onSaveMulti(payloads: PmRulePayload[]) {
  const results = await createRulesBatch(payloads)
  batchResults.value = results
  const created = results.filter((r) => r.ok).length
  const failed = results.length - created
  await loadRules(true)
  if (failed === 0) {
    toast.success(`Created ${created} PM templates.`)
    closeForm()
  } else {
    toast.warning(`Created ${created}, ${failed} failed. Review the highlighted rows.`)
  }
}

// ── Evaluate all ──────────────────────────────────────────────────────────────
const evaluateAllOpen = ref(false)

async function confirmEvaluateAll() {
  const res = await evaluateAll()
  if (res.ok && res.result) {
    toast.success(
      `Evaluated ${res.result.evaluated} assignment${res.result.evaluated === 1 ? '' : 's'} — generated ${res.result.generated} request${res.result.generated === 1 ? '' : 's'}.`,
    )
  } else {
    toast.error(res.message ?? 'Evaluation failed.')
  }
  evaluateAllOpen.value = false
}

// ── Activate / Deactivate ─────────────────────────────────────────────────────
const toggleOpen = ref(false)
const toggleTarget = ref<PmRule | null>(null)

function openToggle(rule: PmRule) {
  toggleTarget.value = rule
  toggleOpen.value = true
}

async function confirmToggle() {
  if (!toggleTarget.value) return
  const t = toggleTarget.value
  const res = t.is_active ? await deactivateRule(t.id) : await reactivateRule(t.id)
  if (res.ok) {
    toast.success(t.is_active ? 'PM template deactivated.' : 'PM template reactivated.')
    await loadRules(true)
    toggleOpen.value = false
    toggleTarget.value = null
  } else {
    // 409 — an assignment for this template has an active MR/WO chain.
    toast.error(res.message ?? 'Action failed.')
  }
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
        <Button
          v-if="canConfigure"
          variant="outline"
          :disabled="evaluating"
          @click="evaluateAllOpen = true"
        >
          {{ evaluating ? 'Evaluating…' : 'Evaluate All' }}
        </Button>
        <Button v-if="canConfigure" @click="openCreate">Create Template</Button>
      </div>
    </div>

    <div v-if="rulesError" class="error-state" role="alert">{{ rulesError }}</div>

    <AppDataTable
      :key="statusFilter"
      :rows="filteredRules"
      :columns="columns"
      empty-text="No PM templates defined."
      label="PM Templates"
      :loading="rulesLoading"
    >
      <template #cell="{ column, row }">
        <RouterLink
          v-if="column.field === 'name'"
          :to="`/admin/pm-rules/${row.id}`"
          class="table-link"
          >{{ row.name }}</RouterLink
        >

        <span v-else-if="column.field === 'maintenance_level'">
          <span v-if="row.maintenance_level" :class="pmLevelClass(row.maintenance_level)">
            {{ row.maintenance_level }}
          </span>
          <span v-else class="detail-field-muted">—</span>
        </span>

        <span v-else-if="column.field === 'schedule'" class="pm-schedule-cell">
          {{ pmScheduleText(row) }}
        </span>

        <span v-else-if="column.field === 'assignments_count'" class="table-cell-secondary">
          {{ row.assignments_count ?? 0 }}
        </span>

        <span
          v-else-if="column.field === 'is_active'"
          :class="row.is_active ? 'status-badge status-active' : 'status-badge status-inactive'"
          >{{ row.is_active ? 'Active' : 'Inactive' }}</span
        >

        <div v-else-if="column.field === 'actions'" class="table-row-actions">
          <Button
            v-if="canConfigure"
            variant="outline"
            size="icon-sm"
            :aria-label="`Edit ${row.name}`"
            @click="openEdit(row)"
          >
            <Pencil />
          </Button>
          <Button
            v-if="canConfigure"
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

    <!-- Create / Edit sheet -->
    <PmRuleForm
      :open="formOpen"
      :editing="editing"
      :reading-types="readingTypes"
      :saving="saving"
      :validation-errors="validationErrors"
      :batch-results="batchResults"
      @close="closeForm"
      @save-single="onSaveSingle"
      @save-multi="onSaveMulti"
    />

    <!-- Evaluate-all confirm -->
    <Dialog v-model:open="evaluateAllOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Evaluate All PM Assignments</DialogTitle>
          <DialogDescription>
            Run every active assignment now. Due assignments will generate preventive maintenance
            requests immediately.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" @click="evaluateAllOpen = false">Cancel</Button>
          <Button :disabled="evaluating" @click="confirmEvaluateAll">
            {{ evaluating ? 'Evaluating…' : 'Evaluate All' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Activate / Deactivate confirm -->
    <Dialog v-model:open="toggleOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{{
            toggleTarget?.is_active ? 'Deactivate PM Template' : 'Reactivate PM Template'
          }}</DialogTitle>
          <DialogDescription v-if="toggleTarget">
            {{
              toggleTarget.is_active
                ? `Deactivate "${toggleTarget.name}"? It will stop generating maintenance requests for all its assignments. Existing assignments stay on record. Blocked if any assignment has an active request or work order.`
                : `Reactivate "${toggleTarget.name}"? Its active assignments will resume generating maintenance requests when due.`
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
