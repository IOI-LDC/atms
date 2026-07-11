<script setup lang="ts">
import AppLayout from '@/components/app/AppLayout.vue'
import { Button } from '@/components/ui/button'
import { computed } from 'vue'
import { CalendarClock, ClipboardList, RefreshCw, Wrench } from '@lucide/vue'
import { useDashboard } from '@/composables/useDashboard'
import { useDashboardKpis } from '@/composables/useDashboardKpis'
import { useQuickActions } from '@/composables/useQuickActions'
import {
  woStatusClass,
  woStatusLabel,
  priorityClass,
  priorityLabel,
  pmStatusClass,
  pmStatusLabel,
  pmDueLabel,
  fmtDate,
  fmtDateTime,
  fmtKpiDays,
  fmtKpiHours,
  fmtKpiPercent,
} from '@/lib/displayHelpers'

const {
  data: dashData,
  loading: dashLoading,
  error: dashError,
  reload: reloadDash,
  showPendingMr,
  showOpenWo,
  showOverduePm,
  showRecentlyClosed,
  pendingMrItems,
  openWoItems,
  overduePmItems,
  closedWoItems,
  hasActionRequired,
} = useDashboard()

const {
  loading: kpisLoading,
  error: kpisError,
  reload: reloadKpis,
  kpis,
  relocated,
  windowDays,
  windowLabel,
  windowRange,
} = useDashboardKpis()

const { actions } = useQuickActions()

const initialLoading = computed(
  () => (kpisLoading.value && !kpis.value) || (dashLoading.value && !dashData.value),
)
const refreshing = computed(() => kpisLoading.value || dashLoading.value)

function refreshAll() {
  reloadDash()
  reloadKpis()
}
</script>

<template>
  <AppLayout>
    <div class="page-section dashboard-briefing">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Maintenance Control Center</h1>
          <p class="page-subtitle">Today’s work, maintenance risk, and equipment reliability.</p>
        </div>
        <div class="page-actions">
          <span v-if="windowLabel" class="dashboard-window-note">
            {{ windowLabel }}
            <span v-if="windowRange" class="dashboard-window-range">{{ windowRange }}</span>
          </span>
          <Button variant="outline" size="sm" :disabled="refreshing" @click="refreshAll">
            <RefreshCw />
            Refresh
          </Button>
        </div>
      </div>

      <nav v-if="actions.length > 0" class="dashboard-briefing-quick" aria-label="Quick actions">
        <span class="dashboard-briefing-quick-label">Quick Actions</span>
        <div class="dashboard-briefing-quick-links">
          <Button
            v-for="action in actions"
            :key="action.label"
            variant="outline"
            size="sm"
            class="dashboard-briefing-quick-link"
            as-child
          >
            <RouterLink :to="action.to">
              <component :is="action.icon" />
              {{ action.label }}
            </RouterLink>
          </Button>
        </div>
      </nav>

      <div v-if="initialLoading" class="loading-state">Loading dashboard…</div>

      <template v-else>
        <section
          v-if="dashData && hasActionRequired"
          class="dashboard-briefing-status"
          aria-label="Operational status"
        >
          <RouterLink
            v-if="showPendingMr"
            to="/maintenance?tab=pending-approval"
            class="dashboard-briefing-status-item"
          >
            <ClipboardList />
            <span class="dashboard-briefing-status-label">Pending MR</span>
            <strong>{{ dashData.summary.pending_maintenance_requests ?? 0 }}</strong>
          </RouterLink>
          <RouterLink
            v-if="showOpenWo"
            to="/work-orders?tab=open"
            class="dashboard-briefing-status-item"
          >
            <Wrench />
            <span class="dashboard-briefing-status-label">Open Work Orders</span>
            <strong>{{ dashData.summary.open_work_orders ?? 0 }}</strong>
          </RouterLink>
          <RouterLink
            v-if="showOverduePm"
            to="/admin/pm-rules"
            class="dashboard-briefing-status-item"
          >
            <CalendarClock />
            <span class="dashboard-briefing-status-label">Overdue PM</span>
            <strong>{{ dashData.summary.overdue_pm_assignments ?? 0 }}</strong>
          </RouterLink>
        </section>

        <div v-if="kpisError && !kpis" class="error-state" role="alert">{{ kpisError }}</div>

        <div v-else-if="kpis" class="dashboard-briefing-metrics">
          <section class="dashboard-briefing-metric-section dashboard-briefing-reliability">
            <div class="dashboard-briefing-section-heading">
              <h2>Equipment Reliability</h2>
              <span>90-day pulse</span>
            </div>
            <div class="dashboard-briefing-metric-grid">
              <div class="dashboard-briefing-metric">
                <span>MTBF</span>
                <strong>{{ fmtKpiDays(kpis.mtbf.days) }}</strong>
                <small>Between failures</small>
              </div>
              <div class="dashboard-briefing-metric">
                <span>MTTR</span>
                <strong>{{ fmtKpiHours(kpis.mttr.hours) }}</strong>
                <small>To repair</small>
              </div>
              <div class="dashboard-briefing-metric">
                <span>Failure rate</span>
                <strong>{{ kpis.failure_rate.failures }}</strong>
                <small>{{ kpis.failure_rate.per_day.toFixed(3) }}/day</small>
              </div>
            </div>
          </section>

          <section class="dashboard-briefing-metric-section dashboard-briefing-process">
            <div class="dashboard-briefing-section-heading">
              <h2>Process Performance</h2>
              <span>90-day pulse</span>
            </div>
            <div class="dashboard-briefing-metric-grid">
              <div class="dashboard-briefing-metric">
                <span>PM compliance</span>
                <strong>{{ fmtKpiPercent(kpis.pm_compliance.percentage) }}</strong>
                <small
                  >{{ kpis.pm_compliance.compliant }} / {{ kpis.pm_compliance.total }} on
                  time</small
                >
              </div>
              <div class="dashboard-briefing-metric">
                <span>Avg MR</span>
                <strong>{{ fmtKpiHours(kpis.avg_mr_duration.hours) }}</strong>
                <small>To resolve</small>
              </div>
              <div class="dashboard-briefing-metric">
                <span>Avg WO</span>
                <strong>{{ fmtKpiHours(kpis.avg_wo_duration.hours) }}</strong>
                <small>To close</small>
              </div>
            </div>
          </section>
        </div>

        <div v-if="dashError && !dashData" class="error-state" role="alert">
          {{ dashError }}
        </div>

        <div
          v-else-if="dashData && (hasActionRequired || showRecentlyClosed || relocated.length > 0)"
          class="dashboard-briefing-workboard"
        >
          <section v-if="hasActionRequired" class="dashboard-briefing-work-section">
            <div class="dashboard-briefing-section-heading">
              <h2>Active Workboard</h2>
              <span>What needs attention</span>
            </div>

            <div v-if="showPendingMr" class="dashboard-briefing-queue">
              <div class="dashboard-briefing-queue-heading">
                <h3>Pending Maintenance Requests</h3>
                <span>{{ dashData.summary.pending_maintenance_requests ?? 0 }}</span>
              </div>
              <div v-if="pendingMrItems.length === 0" class="dashboard-briefing-empty">
                No pending requests.
              </div>
              <div v-else class="dashboard-briefing-rows">
                <RouterLink
                  v-for="mr in pendingMrItems"
                  :key="mr.id"
                  :to="`/maintenance/requests/${mr.id}`"
                  class="dashboard-briefing-row dashboard-briefing-row-request"
                >
                  <span class="dashboard-briefing-row-main">
                    <strong>{{ mr.number }} — {{ mr.asset.name }}</strong>
                    <small>{{ mr.created_by?.name ?? '—' }} · {{ fmtDate(mr.created_at) }}</small>
                  </span>
                  <span :class="priorityClass(mr.priority)">{{ priorityLabel(mr.priority) }}</span>
                </RouterLink>
              </div>
            </div>

            <div v-if="showOpenWo" class="dashboard-briefing-queue">
              <div class="dashboard-briefing-queue-heading">
                <h3>Open Work Orders</h3>
                <span>{{ dashData.summary.open_work_orders ?? 0 }}</span>
              </div>
              <div v-if="openWoItems.length === 0" class="dashboard-briefing-empty">
                No open work orders.
              </div>
              <div v-else class="dashboard-briefing-rows">
                <RouterLink
                  v-for="wo in openWoItems"
                  :key="wo.id"
                  :to="`/work-orders/${wo.id}`"
                  class="dashboard-briefing-row dashboard-briefing-row-work-order"
                >
                  <span class="dashboard-briefing-row-main">
                    <strong>{{ wo.number }} — {{ wo.asset.name }}</strong>
                    <small>{{ wo.assigned_to?.name ?? 'Unassigned' }}</small>
                  </span>
                  <span :class="woStatusClass(wo.status)">{{ woStatusLabel(wo.status) }}</span>
                </RouterLink>
              </div>
            </div>

            <div v-if="showOverduePm" class="dashboard-briefing-queue">
              <div class="dashboard-briefing-queue-heading">
                <h3>Overdue PM Assignments</h3>
                <span>{{ dashData.summary.overdue_pm_assignments ?? 0 }}</span>
              </div>
              <div v-if="overduePmItems.length === 0" class="dashboard-briefing-empty">
                No overdue PM assignments.
              </div>
              <div v-else class="dashboard-briefing-rows">
                <RouterLink
                  v-for="assignment in overduePmItems"
                  :key="assignment.id"
                  :to="`/assets/${assignment.asset?.id}`"
                  class="dashboard-briefing-row dashboard-briefing-row-overdue"
                >
                  <span class="dashboard-briefing-row-main">
                    <strong>{{ assignment.asset?.name }}</strong>
                    <small>{{ assignment.rule.name }} · Due {{ pmDueLabel(assignment) }}</small>
                  </span>
                  <span :class="pmStatusClass(assignment.pm_status)">{{
                    pmStatusLabel(assignment.pm_status)
                  }}</span>
                </RouterLink>
              </div>
            </div>
          </section>

          <section class="dashboard-briefing-activity-section">
            <div class="dashboard-briefing-section-heading">
              <h2>Recent Activity</h2>
              <span>Latest movements</span>
            </div>

            <div v-if="kpis" class="dashboard-briefing-queue">
              <div class="dashboard-briefing-queue-heading">
                <h3>Asset Relocations</h3>
                <span>{{ relocated.length }}</span>
              </div>
              <div v-if="relocated.length === 0" class="dashboard-briefing-empty">
                No asset relocations in the last {{ windowDays }} days.
              </div>
              <div v-else class="dashboard-briefing-rows">
                <RouterLink
                  v-for="item in relocated"
                  :key="item.id"
                  :to="`/assets/${item.asset_id}`"
                  class="dashboard-briefing-row dashboard-briefing-row-activity"
                >
                  <span class="dashboard-briefing-row-main">
                    <strong>{{ item.asset.name }}</strong>
                    <small
                      >{{ item.from_location?.name ?? '—' }} →
                      {{ item.to_location?.name ?? '—' }}</small
                    >
                  </span>
                  <small class="dashboard-briefing-row-date">{{
                    fmtDateTime(item.effective_at)
                  }}</small>
                </RouterLink>
              </div>
            </div>

            <div v-if="showRecentlyClosed" class="dashboard-briefing-queue">
              <div class="dashboard-briefing-queue-heading">
                <h3>Recently Closed</h3>
                <span>{{ dashData.summary.recently_closed_work_orders ?? 0 }}</span>
              </div>
              <div v-if="closedWoItems.length === 0" class="dashboard-briefing-empty">
                No work orders closed in the last 30 days.
              </div>
              <div v-else class="dashboard-briefing-rows">
                <RouterLink
                  v-for="wo in closedWoItems"
                  :key="wo.id"
                  :to="`/work-orders/${wo.id}`"
                  class="dashboard-briefing-row dashboard-briefing-row-activity"
                >
                  <span class="dashboard-briefing-row-main">
                    <strong>{{ wo.number }} — {{ wo.asset.name }}</strong>
                    <small>{{ wo.assigned_to?.name ?? 'Unassigned' }}</small>
                  </span>
                  <small class="dashboard-briefing-row-date">{{ fmtDate(wo.closed_at) }}</small>
                </RouterLink>
              </div>
            </div>
          </section>
        </div>
      </template>
    </div>
  </AppLayout>
</template>
