<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftIcon } from '@lucide/vue'
import AppLayout from '@/components/app/AppLayout.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import PmRuleForm from '@/components/pm-rules/PmRuleForm.vue'
import { Progress } from '@/components/ui/progress'
import { toast } from 'vue-sonner'
import { usePmRules } from '@/composables/usePmRules'
import { useAuthStore } from '@/stores/auth.store'
import {
  pmStatusClass, pmStatusLabel, pmLevelClass, pmTriggerLabel,
  mrStatusClass, mrStatusLabel, priorityClass, priorityLabel, fmtDate,
} from '@/lib/displayHelpers'
import { pmScheduleText } from '@/lib/pmSchedule'
import type { PmRulePayload } from '@/composables/usePmRules'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const canConfigure = computed(() => auth.isAdmin)
const id = computed(() => Number(route.params.ruleId))

const {
  rule, ruleLoading, ruleError, notFound, forbidden, loadRule,
  mrHistory, mrHistoryLoading, loadMrHistory,
  readingTypes, loadReadingTypes,
  saving, validationErrors, updateRule,
  acting, deactivateRule, reactivateRule,
  evaluating, evaluateRule,
} = usePmRules()

watch(id, async (newId) => {
  if (!newId) return
  await loadRule(newId)
  void loadMrHistory(newId)
  if (canConfigure.value) void loadReadingTypes()
}, { immediate: true })

function goBack() { router.back() }

const progressVariant = computed<'default' | 'soon' | 'due'>(() => {
  const s = rule.value?.pm_status
  if (s === 'due') return 'due'
  if (s === 'soon') return 'soon'
  return 'default'
})

// ── Evaluate ──────────────────────────────────────────────────────────────────
async function onEvaluate() {
  if (!rule.value) return
  const res = await evaluateRule(rule.value.id)
  if (!res.ok) { toast.error(res.message ?? 'Evaluation failed.'); return }
  if (res.data) {
    toast.success('PM request generated.')
    await loadRule(id.value)
    void loadMrHistory(id.value)
  } else {
    toast.info(res.message ?? 'PM rule is not due.')
  }
}

// ── Edit ──────────────────────────────────────────────────────────────────────
const formOpen = ref(false)
function openEdit() { validationErrors.value = null; formOpen.value = true }
function closeForm() { formOpen.value = false; validationErrors.value = null }

async function onSaveSingle(payload: PmRulePayload) {
  if (!rule.value) return
  const result = await updateRule(rule.value.id, payload)
  if (result) {
    toast.success('PM rule updated.')
    await loadRule(id.value)
    closeForm()
  }
}

// ── Activate / Deactivate ─────────────────────────────────────────────────────
const toggleOpen = ref(false)

async function confirmToggle() {
  if (!rule.value) return
  const wasActive = rule.value.is_active
  const res = wasActive ? await deactivateRule(rule.value.id) : await reactivateRule(rule.value.id)
  if (res.ok) {
    toast.success(wasActive ? 'PM rule deactivated.' : 'PM rule reactivated.')
    await loadRule(id.value)
    toggleOpen.value = false
  } else {
    toast.error(res.message ?? 'Action failed.')
  }
}
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <Button variant="ghost" size="sm" class="detail-back" @click="goBack">
        <ArrowLeftIcon class="detail-back-icon" />
        Back
      </Button>

      <div v-if="ruleLoading" class="loading-state">Loading PM rule…</div>
      <div v-else-if="notFound" class="empty-state">PM rule not found.</div>
      <div v-else-if="forbidden" class="permission-state">
        You don't have permission to view this PM rule.
      </div>
      <div v-else-if="ruleError" class="error-state" role="alert">{{ ruleError }}</div>

      <template v-else-if="rule">
        <!-- ── Header ──────────────────────────────────────────────────────── -->
        <div class="page-header">
          <div class="page-heading">
            <h1 class="page-title">{{ rule.name }}</h1>
            <p class="page-subtitle">{{ pmTriggerLabel(rule.trigger_type) }} schedule</p>
          </div>
          <div class="page-actions">
            <span v-if="rule.maintenance_level" :class="pmLevelClass(rule.maintenance_level)">
              {{ rule.maintenance_level }}
            </span>
            <span :class="pmStatusClass(rule.pm_status)">{{ pmStatusLabel(rule.pm_status) }}</span>
            <span :class="rule.is_active ? 'status-badge status-active' : 'status-badge status-inactive'">
              {{ rule.is_active ? 'Active' : 'Inactive' }}
            </span>
          </div>
        </div>

        <!-- ── Schedule hero ───────────────────────────────────────────────── -->
        <div class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Schedule</h2>
          </div>
          <div class="detail-card-content">
            <p class="detail-field-value">{{ pmScheduleText(rule) }}</p>
            <div class="detail-grid">
              <div class="detail-field">
                <span class="detail-field-label">Trigger Type</span>
                <p class="detail-field-value">{{ pmTriggerLabel(rule.trigger_type) }}</p>
              </div>
              <div v-if="rule.interval_days != null" class="detail-field">
                <span class="detail-field-label">Calendar Interval</span>
                <p class="detail-field-value">{{ rule.interval_days }} days</p>
              </div>
              <div v-if="rule.interval_reading != null" class="detail-field">
                <span class="detail-field-label">Usage Interval</span>
                <p class="detail-field-value">
                  {{ rule.interval_reading }}
                  <span class="detail-field-muted">{{ rule.usage_reading_type?.unit ?? '' }}</span>
                </p>
              </div>
              <div v-if="rule.usage_reading_type" class="detail-field">
                <span class="detail-field-label">Reading Type</span>
                <p class="detail-field-value">{{ rule.usage_reading_type.name }}</p>
              </div>
              <div v-if="rule.description" class="detail-field detail-field-block">
                <span class="detail-field-label">Description</span>
                <p class="detail-field-value detail-field-prose">{{ rule.description }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Asset ───────────────────────────────────────────────────────── -->
        <div class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Asset</h2>
          </div>
          <div class="detail-card-content">
            <div class="detail-grid">
              <div class="detail-field">
                <span class="detail-field-label">Name</span>
                <p class="detail-field-value">
                  <RouterLink :to="`/assets/${rule.asset.id}`" class="table-link">{{ rule.asset.name }}</RouterLink>
                </p>
              </div>
              <div class="detail-field">
                <span class="detail-field-label">ERP Asset Code</span>
                <p class="detail-field-value"><span class="atms-erp-code">{{ rule.asset.erp_asset_code }}</span></p>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Next-Due & Baselines ────────────────────────────────────────── -->
        <div class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Next Due &amp; Baselines</h2>
          </div>
          <div class="detail-card-content">
            <div v-if="rule.progress_percentage != null" class="pm-progress">
              <Progress :value="rule.progress_percentage" :variant="progressVariant" />
              <span class="pm-progress-label">
                {{ Math.round(rule.progress_percentage) }}% of interval elapsed ·
                <span :class="pmStatusClass(rule.pm_status)">{{ pmStatusLabel(rule.pm_status) }}</span>
              </span>
            </div>
            <p v-else class="detail-field-muted">No readings yet — progress can't be determined.</p>

            <div class="detail-grid">
              <div v-if="rule.next_due_date" class="detail-field">
                <span class="detail-field-label">Next Due (Date)</span>
                <p class="detail-field-value">{{ fmtDate(rule.next_due_date) }}</p>
              </div>
              <div v-if="rule.next_due_reading != null" class="detail-field">
                <span class="detail-field-label">Next Due (Reading)</span>
                <p class="detail-field-value">
                  {{ rule.next_due_reading }}
                  <span class="detail-field-muted">{{ rule.usage_reading_type?.unit ?? '' }}</span>
                </p>
              </div>
              <div class="detail-field">
                <span class="detail-field-label">Last Triggered (Date)</span>
                <p class="detail-field-value">{{ fmtDate(rule.last_triggered_date) }}</p>
              </div>
              <div v-if="rule.last_triggered_reading != null" class="detail-field">
                <span class="detail-field-label">Last Triggered (Reading)</span>
                <p class="detail-field-value">{{ rule.last_triggered_reading }}</p>
              </div>
              <div v-if="rule.created_by" class="detail-field">
                <span class="detail-field-label">Created By</span>
                <p class="detail-field-value">{{ rule.created_by.name }}</p>
              </div>
              <div class="detail-field">
                <span class="detail-field-label">Created</span>
                <p class="detail-field-value">{{ fmtDate(rule.created_at) }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Suppressions ────────────────────────────────────────────────── -->
        <div v-if="rule.suppressions && rule.suppressions.length > 0" class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Suppressions</h2>
          </div>
          <div class="data-card-content">
            <table class="detail-table">
              <thead class="detail-table-head">
                <tr>
                  <th>Until Date</th>
                  <th>Until Reading</th>
                  <th>Source MR</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in rule.suppressions" :key="s.id" class="detail-table-row">
                  <td class="detail-table-cell">{{ fmtDate(s.suppressed_until_date) }}</td>
                  <td class="detail-table-cell">{{ s.suppressed_until_reading ?? '—' }}</td>
                  <td class="detail-table-cell">
                    <RouterLink
                      v-if="s.source_mr_id"
                      :to="`/maintenance/requests/${s.source_mr_id}`"
                      class="table-link"
                    >MR #{{ s.source_mr_id }}</RouterLink>
                    <span v-else>—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- ── MR History ──────────────────────────────────────────────────── -->
        <div class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Generated Maintenance Requests</h2>
          </div>
          <div class="data-card-content">
            <div v-if="mrHistoryLoading" class="loading-state">Loading history…</div>
            <div v-else-if="mrHistory.length === 0" class="empty-state">
              No maintenance requests generated by this rule yet.
            </div>
            <table v-else class="detail-table">
              <thead class="detail-table-head">
                <tr>
                  <th>Request</th>
                  <th>Status</th>
                  <th>Priority</th>
                  <th>Generated</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="mr in mrHistory" :key="mr.id" class="detail-table-row">
                  <td class="detail-table-cell">
                    <RouterLink :to="`/maintenance/requests/${mr.id}`" class="table-link">
                      <span class="atms-mr-number">{{ mr.number }}</span>
                    </RouterLink>
                  </td>
                  <td class="detail-table-cell">
                    <span :class="mrStatusClass(mr.status)">{{ mrStatusLabel(mr.status) }}</span>
                  </td>
                  <td class="detail-table-cell">
                    <span :class="priorityClass(mr.priority)">{{ priorityLabel(mr.priority) }}</span>
                  </td>
                  <td class="detail-table-cell">{{ fmtDate(mr.created_at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- ── Action bar ──────────────────────────────────────────────────── -->
        <div class="detail-actions">
          <Button variant="outline" :disabled="evaluating || !rule.is_active" @click="onEvaluate">
            {{ evaluating ? 'Evaluating…' : 'Evaluate Now' }}
          </Button>
          <Button v-if="canConfigure" variant="outline" @click="openEdit">Edit</Button>
          <Button
            v-if="canConfigure"
            :variant="rule.is_active ? 'destructive' : 'default'"
            :disabled="acting"
            @click="toggleOpen = true"
          >{{ rule.is_active ? 'Deactivate' : 'Reactivate' }}</Button>
        </div>
      </template>
    </div>

    <!-- Edit sheet -->
    <PmRuleForm
      v-if="rule"
      :open="formOpen"
      :editing="rule"
      :reading-types="readingTypes"
      :saving="saving"
      :validation-errors="validationErrors"
      :batch-results="null"
      @close="closeForm"
      @save-single="onSaveSingle"
      @save-multi="() => {}"
    />

    <!-- Activate / Deactivate confirm -->
    <Dialog v-model:open="toggleOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{{ rule?.is_active ? 'Deactivate PM Rule' : 'Reactivate PM Rule' }}</DialogTitle>
          <DialogDescription v-if="rule">
            {{ rule.is_active
              ? `Deactivate "${rule.name}"? Blocked if an active request or work order from this rule still exists.`
              : `Reactivate "${rule.name}"? It will resume generating requests when due.` }}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" @click="toggleOpen = false">Cancel</Button>
          <Button :variant="rule?.is_active ? 'destructive' : 'default'" :disabled="acting" @click="confirmToggle">
            {{ acting ? 'Working…' : (rule?.is_active ? 'Deactivate' : 'Reactivate') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </AppLayout>
</template>
