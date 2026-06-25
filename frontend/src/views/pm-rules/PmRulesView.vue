<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { toast } from 'vue-sonner'
import AppDataTable from '@/components/app/AppDataTable.vue'
import PmRuleForm from '@/components/pm-rules/PmRuleForm.vue'
import { Button } from '@/components/ui/button'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { usePmRules } from '@/composables/usePmRules'
import { useAuthStore } from '@/stores/auth.store'
import { pmStatusClass, pmStatusLabel, pmLevelClass, fmtDate } from '@/lib/displayHelpers'
import { pmScheduleText } from '@/lib/pmSchedule'
import { Pencil, ToggleLeft, ToggleRight, Play } from '@lucide/vue'
import type { AppColumnDef } from '@/lib/appTable'
import type { PmRule } from '@/types'
import type { PmRulePayload } from '@/composables/usePmRules'

const auth = useAuthStore()
const canConfigure = computed(() => auth.isAdmin)

const {
  rules, rulesLoading, rulesError, loadRules,
  readingTypes, loadReadingTypes,
  saving, validationErrors, createRule, createRulesBatch, updateRule,
  acting, deactivateRule, reactivateRule,
  evaluating, evaluateRule, evaluateAll,
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
  { field: 'name',            header: 'Rule Name',      sortable: true, minWidth: 180 },
  { field: 'asset',           header: 'Asset',          sortable: false, minWidth: 160 },
  { field: 'maintenance_level', header: 'Level',        sortable: false, align: 'center' },
  { field: 'schedule',        header: 'Schedule',       sortable: false, minWidth: 220 },
  { field: 'pm_status',       header: 'Status',         sortable: false, align: 'center' },
  { field: 'last_triggered',  header: 'Last Triggered', sortable: false, align: 'center' },
  { field: 'is_active',       header: 'Active',         sortable: true, align: 'center' },
  { field: 'actions',         header: '',               sortable: false, align: 'center', minWidth: 120 },
]

onMounted(() => {
  loadRules()
  if (canConfigure.value) loadReadingTypes()
})

function lastTriggered(rule: PmRule): string {
  if (rule.trigger_type === 'reading') {
    return rule.last_triggered_reading != null ? String(rule.last_triggered_reading) : '—'
  }
  return fmtDate(rule.last_triggered_date)
}

// ── Create / Edit form ────────────────────────────────────────────────────────
const formOpen = ref(false)
const editing = ref<PmRule | null>(null)
const batchResults = ref<{ index: number; ok: boolean; errors?: Record<string, string[]>; message?: string }[] | null>(null)

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
    toast.success(editing.value ? 'PM rule updated.' : 'PM rule created.')
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
    toast.success(`Created ${created} PM rules.`)
    closeForm()
  } else {
    toast.warning(`Created ${created}, ${failed} failed. Review the highlighted rows.`)
  }
}

// ── Evaluate (single) ─────────────────────────────────────────────────────────
async function onEvaluate(rule: PmRule) {
  const res = await evaluateRule(rule.id)
  if (!res.ok) { toast.error(res.message ?? 'Evaluation failed.'); return }
  if (res.data) {
    toast.success(`PM request generated for ${rule.name}.`)
    await loadRules(true)
  } else {
    toast.info(res.message ?? 'PM rule is not due.')
  }
}

// ── Evaluate all ──────────────────────────────────────────────────────────────
const evaluateAllOpen = ref(false)

async function confirmEvaluateAll() {
  const res = await evaluateAll()
  if (res.ok) {
    toast.success(res.message ?? 'Evaluation complete.')
    await loadRules(true)
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
    toast.success(t.is_active ? 'PM rule deactivated.' : 'PM rule reactivated.')
    await loadRules(true)
    toggleOpen.value = false
    toggleTarget.value = null
  } else {
    // 409 — active MR/WO chain blocks deactivation. Keep dialog open to show why.
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
        <Button variant="outline" :disabled="evaluating" @click="evaluateAllOpen = true">
          {{ evaluating ? 'Evaluating…' : 'Evaluate All' }}
        </Button>
        <Button v-if="canConfigure" @click="openCreate">Create Rule</Button>
      </div>
    </div>

    <div v-if="rulesError" class="error-state" role="alert">{{ rulesError }}</div>

    <AppDataTable
        :key="statusFilter"
        :rows="filteredRules"
        :columns="columns"
        empty-text="No PM rules defined."
        label="PM Rules"
        :loading="rulesLoading"
      >
        <template #cell="{ column, row }">
          <RouterLink
            v-if="column.field === 'name'"
            :to="`/admin/pm-rules/${row.id}`"
            class="table-link"
          >{{ row.name }}</RouterLink>

          <RouterLink
            v-else-if="column.field === 'asset'"
            :to="`/assets/${row.asset.id}`"
            class="table-link"
          >{{ row.asset.name }}</RouterLink>

          <span v-else-if="column.field === 'maintenance_level'">
            <span v-if="row.maintenance_level" :class="pmLevelClass(row.maintenance_level)">
              {{ row.maintenance_level }}
            </span>
            <span v-else class="detail-field-muted">—</span>
          </span>

          <span v-else-if="column.field === 'schedule'" class="pm-schedule-cell">
            {{ pmScheduleText(row) }}
          </span>

          <span v-else-if="column.field === 'pm_status'" :class="pmStatusClass(row.pm_status)">
            {{ pmStatusLabel(row.pm_status) }}
          </span>

          <span v-else-if="column.field === 'last_triggered'" class="table-cell-secondary">
            {{ lastTriggered(row) }}
          </span>

          <span
            v-else-if="column.field === 'is_active'"
            :class="row.is_active ? 'status-badge status-active' : 'status-badge status-inactive'"
          >{{ row.is_active ? 'Active' : 'Inactive' }}</span>

          <div v-else-if="column.field === 'actions'" class="table-row-actions">
            <Button
              variant="ghost"
              size="icon-sm"
              :disabled="evaluating || !row.is_active"
              :aria-label="`Evaluate ${row.name} now`"
              @click="onEvaluate(row)"
            >
              <Play />
            </Button>
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
          <DialogTitle>Evaluate All PM Rules</DialogTitle>
          <DialogDescription>
            Run every active PM rule now. Due rules will generate preventive maintenance requests immediately.
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
          <DialogTitle>{{ toggleTarget?.is_active ? 'Deactivate PM Rule' : 'Reactivate PM Rule' }}</DialogTitle>
          <DialogDescription v-if="toggleTarget">
            {{ toggleTarget.is_active
              ? `Deactivate "${toggleTarget.name}"? It will stop generating maintenance requests. Blocked if an active request or work order from this rule still exists.`
              : `Reactivate "${toggleTarget.name}"? It will resume generating maintenance requests when due.` }}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" @click="toggleOpen = false">Cancel</Button>
          <Button
            :variant="toggleTarget?.is_active ? 'destructive' : 'default'"
            :disabled="acting"
            @click="confirmToggle"
          >
            {{ acting ? 'Working…' : (toggleTarget?.is_active ? 'Deactivate' : 'Reactivate') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
