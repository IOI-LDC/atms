<script setup lang="ts">
import { ref, watch } from 'vue'
import { toast } from 'vue-sonner'
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
import { pmStatusClass, pmStatusLabel, pmLevelClass, fmtDate } from '@/lib/displayHelpers'
import { pmScheduleText } from '@/lib/pmSchedule'
import { Play, ToggleLeft, ToggleRight } from '@lucide/vue'
import type { AssetPmAssignment, PmRule } from '@/types'

const props = defineProps<{
  assetId: number
  canManage: boolean
}>()

const {
  assignments,
  assignmentsLoading,
  assignmentsError,
  loadAssignments,
  saving,
  validationErrors,
  assignRule,
  acting,
  deactivateAssignment,
  reactivateAssignment,
  evaluating,
  evaluateAssignment,
  loadActiveTemplates,
} = usePmRules()

const showInactive = ref(false)

async function reload() {
  await loadAssignments(props.assetId, { showInactive: showInactive.value })
}

watch(
  () => props.assetId,
  () => {
    void reload()
  },
  { immediate: true },
)

function toggleInactive() {
  showInactive.value = !showInactive.value
  void reload()
}

// ── Assign Rule ───────────────────────────────────────────────────────────────
const assignOpen = ref(false)
const templates = ref<PmRule[]>([])
const selectedTemplate = ref<string>('')
const assignError = ref('')

async function openAssign() {
  selectedTemplate.value = ''
  assignError.value = ''
  validationErrors.value = null
  templates.value = await loadActiveTemplates()
  assignOpen.value = true
}

async function confirmAssign() {
  if (!selectedTemplate.value) {
    assignError.value = 'Select a template to assign.'
    return
  }
  const result = await assignRule(props.assetId, Number(selectedTemplate.value))
  if (result) {
    toast.success('PM template assigned.')
    assignOpen.value = false
    await reload()
  } else if (validationErrors.value) {
    assignError.value = Object.values(validationErrors.value)[0]?.[0] ?? 'Failed to assign.'
  } else {
    assignError.value = 'Failed to assign template.'
  }
}

// ── Evaluate ──────────────────────────────────────────────────────────────────
const evalOpen = ref(false)
const evalTarget = ref<AssetPmAssignment | null>(null)

function openEvaluate(a: AssetPmAssignment) {
  evalTarget.value = a
  evalOpen.value = true
}

async function confirmEvaluate() {
  if (!evalTarget.value) return
  const res = await evaluateAssignment(props.assetId, evalTarget.value.id)
  if (!res.ok) {
    toast.error(res.message ?? 'Evaluation failed.')
    return
  }
  if (res.data) {
    toast.success('PM request generated.')
    await reload()
  } else {
    toast.info(res.message ?? 'Assignment is not due.')
  }
  evalOpen.value = false
}

// ── Deactivate / Reactivate ───────────────────────────────────────────────────
const toggleOpen = ref(false)
const toggleTarget = ref<AssetPmAssignment | null>(null)

function openToggle(a: AssetPmAssignment) {
  toggleTarget.value = a
  toggleOpen.value = true
}

async function confirmToggle() {
  if (!toggleTarget.value) return
  const t = toggleTarget.value
  const res = t.is_active
    ? await deactivateAssignment(props.assetId, t.id)
    : await reactivateAssignment(props.assetId, t.id)
  if (res.ok) {
    toast.success(t.is_active ? 'Assignment deactivated.' : 'Assignment reactivated.')
    await reload()
    toggleOpen.value = false
  } else {
    toast.error(res.message ?? 'Action failed.')
  }
}
</script>

<template>
  <div class="data-card">
    <div class="data-card-header">
      <h2 class="data-card-title">PM Rules</h2>
      <div class="filter-actions">
        <Button variant="outline" size="sm" @click="toggleInactive">
          {{ showInactive ? 'Show Active Only' : 'Show Inactive' }}
        </Button>
        <Button v-if="canManage" size="sm" @click="openAssign">Assign Rule</Button>
      </div>
    </div>

    <div class="data-card-content">
      <div v-if="assignmentsError" class="error-state" role="alert">{{ assignmentsError }}</div>
      <div v-else-if="assignmentsLoading" class="loading-state">Loading PM assignments…</div>
      <div v-else-if="assignments.length === 0" class="empty-state">
        {{
          showInactive
            ? 'No PM assignments (active or inactive).'
            : 'No PM rules assigned to this asset.'
        }}
      </div>
      <table v-else class="detail-table">
        <thead class="detail-table-head">
          <tr>
            <th>Template</th>
            <th>Schedule</th>
            <th>Status</th>
            <th>Last Triggered</th>
            <th>Next Due</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in assignments" :key="a.id" class="detail-table-row">
            <td class="detail-table-cell">
              <RouterLink :to="`/admin/pm-rules/${a.pm_rule_id}`" class="table-link">
                {{ a.rule.name }}
              </RouterLink>
              <span v-if="a.rule.maintenance_level" :class="pmLevelClass(a.rule.maintenance_level)">
                {{ a.rule.maintenance_level }}
              </span>
            </td>
            <td class="detail-table-cell pm-schedule-cell">{{ pmScheduleText(a) }}</td>
            <td class="detail-table-cell">
              <span :class="pmStatusClass(a.pm_status)">{{ pmStatusLabel(a.pm_status) }}</span>
            </td>
            <td class="detail-table-cell">
              {{
                a.last_triggered_reading != null
                  ? String(a.last_triggered_reading)
                  : fmtDate(a.last_triggered_date)
              }}
            </td>
            <td class="detail-table-cell">
              {{
                a.next_due_reading != null ? String(a.next_due_reading) : fmtDate(a.next_due_date)
              }}
            </td>
            <td class="detail-table-cell">
              <div class="table-row-actions">
                <Button
                  v-if="canManage && a.is_active"
                  variant="ghost"
                  size="icon-sm"
                  :disabled="evaluating"
                  :aria-label="`Evaluate ${a.rule.name}`"
                  @click="openEvaluate(a)"
                >
                  <Play />
                </Button>
                <Button
                  v-if="canManage"
                  variant="ghost"
                  size="icon-sm"
                  :aria-label="`${a.is_active ? 'Deactivate' : 'Reactivate'} ${a.rule.name}`"
                  @click="openToggle(a)"
                >
                  <ToggleRight v-if="a.is_active" />
                  <ToggleLeft v-else />
                </Button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Assign Rule -->
    <Dialog v-model:open="assignOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Assign PM Rule</DialogTitle>
          <DialogDescription>
            Assign a maintenance schedule template to this asset. It starts with one full grace
            interval before its first PM is due.
          </DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Select v-model="selectedTemplate">
            <SelectTrigger><SelectValue placeholder="Select a template…" /></SelectTrigger>
            <SelectContent disable-portal>
              <SelectItem v-for="t in templates" :key="t.id" :value="String(t.id)">
                {{ t.name }}
              </SelectItem>
            </SelectContent>
          </Select>
          <p v-if="templates.length === 0" class="form-help">
            No active templates available. Create one under Admin → PM Rules.
          </p>
          <p v-if="assignError" class="form-error">{{ assignError }}</p>
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="saving" @click="assignOpen = false">Cancel</Button>
          <Button :disabled="saving" @click="confirmAssign">{{
            saving ? 'Assigning…' : 'Assign'
          }}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Evaluate confirm -->
    <Dialog v-model:open="evalOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Evaluate Now</DialogTitle>
          <DialogDescription>
            Evaluate "{{ evalTarget?.rule.name }}" for this asset now. A due assignment generates a
            preventive maintenance request immediately.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" :disabled="evaluating" @click="evalOpen = false">Cancel</Button>
          <Button :disabled="evaluating" @click="confirmEvaluate">
            {{ evaluating ? 'Evaluating…' : 'Evaluate' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Deactivate / Reactivate confirm -->
    <Dialog v-model:open="toggleOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{{
            toggleTarget?.is_active ? 'Deactivate Assignment' : 'Reactivate Assignment'
          }}</DialogTitle>
          <DialogDescription v-if="toggleTarget">
            {{
              toggleTarget.is_active
                ? `Deactivate "${toggleTarget.rule.name}" on this asset? It stops generating requests. Blocked if an active request or work order exists.`
                : `Reactivate "${toggleTarget.rule.name}" on this asset? It resumes generating requests when due.`
            }}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" :disabled="acting" @click="toggleOpen = false">Cancel</Button>
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
