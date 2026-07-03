<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftIcon } from '@lucide/vue'
import AppLayout from '@/components/app/AppLayout.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import PmRuleForm from '@/components/pm-rules/PmRuleForm.vue'
import { toast } from 'vue-sonner'
import { usePmRules } from '@/composables/usePmRules'
import { useAuthStore } from '@/stores/auth.store'
import {
  pmLevelClass,
  pmTriggerLabel,
  pmStatusClass,
  pmStatusLabel,
  fmtDate,
  mrStatusClass,
  mrStatusLabel,
  priorityClass,
  priorityLabel,
} from '@/lib/displayHelpers'
import { pmScheduleText } from '@/lib/pmSchedule'
import type { PmRulePayload } from '@/composables/usePmRules'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const canConfigure = computed(() => auth.isAdmin)
const id = computed(() => Number(route.params.ruleId))

const {
  rule,
  ruleLoading,
  ruleError,
  notFound,
  forbidden,
  loadRule,
  mrHistory,
  mrHistoryLoading,
  loadMrHistory,
  readingTypes,
  loadReadingTypes,
  saving,
  validationErrors,
  updateRule,
  acting,
  deactivateRule,
  reactivateRule,
} = usePmRules()

watch(
  id,
  async (newId) => {
    if (!newId) return
    await loadRule(newId)
    void loadMrHistory(newId)
    if (canConfigure.value) void loadReadingTypes()
  },
  { immediate: true },
)

function goBack() {
  router.back()
}

// ── Edit ──────────────────────────────────────────────────────────────────────
const formOpen = ref(false)
function openEdit() {
  validationErrors.value = null
  formOpen.value = true
}
function closeForm() {
  formOpen.value = false
  validationErrors.value = null
}

async function onSaveSingle(payload: PmRulePayload) {
  if (!rule.value) return
  const result = await updateRule(rule.value.id, payload)
  if (result) {
    toast.success('PM template updated.')
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
    toast.success(wasActive ? 'PM template deactivated.' : 'PM template reactivated.')
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

      <div v-if="ruleLoading" class="loading-state">Loading PM template…</div>
      <div v-else-if="notFound" class="empty-state">PM template not found.</div>
      <div v-else-if="forbidden" class="permission-state">
        You don't have permission to view this PM template.
      </div>
      <div v-else-if="ruleError" class="error-state" role="alert">{{ ruleError }}</div>

      <template v-else-if="rule">
        <!-- ── Command bar ─────────────────────────────────────────────────── -->
        <div class="detail-command-bar">
          <div class="detail-command-top">
            <div class="detail-command-identity">
              <div class="detail-command-heading">
                <h1 class="detail-command-number">{{ rule.name }}</h1>
                <span v-if="rule.maintenance_level" :class="pmLevelClass(rule.maintenance_level)">
                  {{ rule.maintenance_level }}
                </span>
                <span
                  :class="
                    rule.is_active ? 'status-badge status-active' : 'status-badge status-inactive'
                  "
                >
                  {{ rule.is_active ? 'Active' : 'Inactive' }}
                </span>
              </div>
              <p class="detail-command-subtitle">
                {{ pmTriggerLabel(rule.trigger_type) }} schedule
              </p>
            </div>

            <div v-if="canConfigure" class="detail-command-actions">
              <Button variant="outline" @click="openEdit">Edit</Button>
              <Button
                :variant="rule.is_active ? 'destructive' : 'default'"
                :disabled="acting"
                @click="toggleOpen = true"
                >{{ rule.is_active ? 'Deactivate' : 'Reactivate' }}</Button
              >
            </div>
          </div>
        </div>

        <!-- ── Main (schedule + coverage) + reference rail ───────────────────── -->
        <div class="detail-layout">
          <div class="detail-main">
            <!-- ── Schedule ──────────────────────────────────────────────────── -->
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
                      <span class="detail-field-muted">{{
                        rule.usage_reading_type?.unit ?? ''
                      }}</span>
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

            <!-- ── Assigned Assets (coverage) ───────────────────────────────────── -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">
                  Assigned Assets ({{ rule.assignments?.length ?? 0 }})
                </h2>
              </div>
              <div class="data-card-content">
                <div v-if="(rule.assignments?.length ?? 0) === 0" class="empty-state">
                  This template is not assigned to any asset yet.
                </div>
                <table v-else class="detail-table">
                  <thead class="detail-table-head">
                    <tr>
                      <th>Asset</th>
                      <th>Status</th>
                      <th>Last Triggered</th>
                      <th>Assignment</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="a in rule.assignments" :key="a.id" class="detail-table-row">
                      <td class="detail-table-cell">
                        <RouterLink :to="`/assets/${a.asset_id}`" class="table-link">
                          {{ a.asset?.name ?? `Asset #${a.asset_id}` }}
                        </RouterLink>
                      </td>
                      <td class="detail-table-cell">
                        <span :class="pmStatusClass(a.pm_status)">{{
                          pmStatusLabel(a.pm_status)
                        }}</span>
                      </td>
                      <td class="detail-table-cell">{{ fmtDate(a.last_triggered_date) }}</td>
                      <td class="detail-table-cell">
                        <span
                          :class="
                            a.is_active
                              ? 'status-badge status-active'
                              : 'status-badge status-inactive'
                          "
                        >
                          {{ a.is_active ? 'Active' : 'Inactive' }}
                        </span>
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
                  No maintenance requests generated by this template yet.
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
                        <span :class="mrStatusClass(mr.status)">{{
                          mrStatusLabel(mr.status)
                        }}</span>
                      </td>
                      <td class="detail-table-cell">
                        <span :class="priorityClass(mr.priority)">{{
                          priorityLabel(mr.priority)
                        }}</span>
                      </td>
                      <td class="detail-table-cell">{{ fmtDate(mr.created_at) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <aside class="detail-rail">
            <!-- ── Template Info ─────────────────────────────────────────────── -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Template Info</h2>
              </div>
              <div class="detail-card-content">
                <div class="detail-grid detail-rail-grid">
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
          </aside>
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
          <DialogTitle>{{
            rule?.is_active ? 'Deactivate PM Template' : 'Reactivate PM Template'
          }}</DialogTitle>
          <DialogDescription v-if="rule">
            {{
              rule.is_active
                ? `Deactivate "${rule.name}"? It stops generating maintenance requests for all its assignments (assignments stay on record). Blocked if any assignment has an active request or work order.`
                : `Reactivate "${rule.name}"? Its active assignments resume generating requests when due.`
            }}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" @click="toggleOpen = false">Cancel</Button>
          <Button
            :variant="rule?.is_active ? 'destructive' : 'default'"
            :disabled="acting"
            @click="confirmToggle"
          >
            {{ acting ? 'Working…' : rule?.is_active ? 'Deactivate' : 'Reactivate' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </AppLayout>
</template>
