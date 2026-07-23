<script setup lang="ts">
import AppLayout from '@/components/app/AppLayout.vue'
import { Button } from '@/components/ui/button'
import { computed } from 'vue'
import { ArrowRight, CalendarClock, ClipboardList, RefreshCw, Wrench } from '@lucide/vue'
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

const { actions } = useQuickActions(['New MR', 'Locations'])

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
    <div class="page-section dashboard-mosaic">
      <!-- Page Header -->
      <header class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Dashboard</h1>
          <p class="page-subtitle">Maintenance operations at a glance.</p>
        </div>
        <div class="page-actions">
          <span v-if="windowLabel" class="dashboard-mosaic-window">
            {{ windowLabel }}
            <span v-if="windowRange" class="dashboard-mosaic-window-range">{{ windowRange }}</span>
          </span>
          <Button
            v-for="action in actions"
            :key="action.label"
            variant="outline"
            size="sm"
            as-child
          >
            <RouterLink :to="action.to">
              <component :is="action.icon" />
              {{ action.label }}
            </RouterLink>
          </Button>
          <Button variant="outline" size="sm" :disabled="refreshing" @click="refreshAll">
            <RefreshCw />
            Refresh
          </Button>
        </div>
      </header>

      <!-- Loading State -->
      <div v-if="initialLoading" class="loading-state">Loading dashboard…</div>

      <template v-else>
        <!-- Status Cards -->
        <section
          v-if="dashData && hasActionRequired"
          class="dashboard-mosaic-status"
          aria-label="Operational status"
        >
          <RouterLink
            v-if="showPendingMr"
            to="/maintenance?tab=pending-approval"
            class="data-card dashboard-mosaic-status-card"
          >
            <span class="dashboard-mosaic-status-icon dashboard-mosaic-status-icon--mr">
              <ClipboardList aria-hidden="true" />
            </span>
            <span class="dashboard-mosaic-status-body">
              <strong class="dashboard-mosaic-status-count">
                {{ dashData.summary.pending_maintenance_requests ?? 0 }}
              </strong>
              <span class="dashboard-mosaic-status-label">Pending Requests</span>
            </span>
            <span class="dashboard-mosaic-status-cta">
              Review <ArrowRight aria-hidden="true" />
            </span>
          </RouterLink>

          <RouterLink
            v-if="showOpenWo"
            to="/work-orders?tab=open"
            class="data-card dashboard-mosaic-status-card"
          >
            <span class="dashboard-mosaic-status-icon dashboard-mosaic-status-icon--wo">
              <Wrench aria-hidden="true" />
            </span>
            <span class="dashboard-mosaic-status-body">
              <strong class="dashboard-mosaic-status-count">
                {{ dashData.summary.open_work_orders ?? 0 }}
              </strong>
              <span class="dashboard-mosaic-status-label">Open Work Orders</span>
            </span>
            <span class="dashboard-mosaic-status-cta">
              View all <ArrowRight aria-hidden="true" />
            </span>
          </RouterLink>

          <RouterLink
            v-if="showOverduePm"
            to="/admin/pm-rules"
            class="data-card dashboard-mosaic-status-card"
          >
            <span class="dashboard-mosaic-status-icon dashboard-mosaic-status-icon--pm">
              <CalendarClock aria-hidden="true" />
            </span>
            <span class="dashboard-mosaic-status-body">
              <strong class="dashboard-mosaic-status-count">
                {{ dashData.summary.overdue_pm_assignments ?? 0 }}
              </strong>
              <span class="dashboard-mosaic-status-label">Overdue PM</span>
            </span>
            <span class="dashboard-mosaic-status-cta">
              Manage <ArrowRight aria-hidden="true" />
            </span>
          </RouterLink>
        </section>

        <!-- KPI Panels -->
        <div v-if="kpisError && !kpis" class="error-state" role="alert">{{ kpisError }}</div>

        <section v-else-if="kpis" class="dashboard-mosaic-kpis" aria-label="Key performance metrics">
          <div class="data-card dashboard-mosaic-kpi-panel">
            <div class="data-card-header">
              <h2 class="data-card-title">Equipment Reliability</h2>
              <span class="dashboard-mosaic-kpi-badge">{{ windowDays }}-day pulse</span>
            </div>
            <div class="data-card-content dashboard-mosaic-kpi-stats">
              <div class="dashboard-mosaic-kpi-stat">
                <span class="dashboard-mosaic-kpi-stat-label">MTBF</span>
                <strong class="dashboard-mosaic-kpi-stat-value">{{ fmtKpiDays(kpis.mtbf.days) }}</strong>
                <small class="dashboard-mosaic-kpi-stat-caption">Between failures</small>
              </div>
              <div class="dashboard-mosaic-kpi-stat">
                <span class="dashboard-mosaic-kpi-stat-label">MTTR</span>
                <strong class="dashboard-mosaic-kpi-stat-value">{{ fmtKpiHours(kpis.mttr.hours) }}</strong>
                <small class="dashboard-mosaic-kpi-stat-caption">To repair</small>
              </div>
              <div class="dashboard-mosaic-kpi-stat">
                <span class="dashboard-mosaic-kpi-stat-label">Failure rate</span>
                <strong class="dashboard-mosaic-kpi-stat-value">{{ kpis.failure_rate.failures }}</strong>
                <small class="dashboard-mosaic-kpi-stat-caption">{{ kpis.failure_rate.per_day.toFixed(3) }}/day</small>
              </div>
            </div>
          </div>

          <div class="data-card dashboard-mosaic-kpi-panel">
            <div class="data-card-header">
              <h2 class="data-card-title">Process Performance</h2>
              <span class="dashboard-mosaic-kpi-badge">{{ windowDays }}-day pulse</span>
            </div>
            <div class="data-card-content dashboard-mosaic-kpi-stats">
              <div class="dashboard-mosaic-kpi-stat">
                <span class="dashboard-mosaic-kpi-stat-label">PM compliance</span>
                <strong class="dashboard-mosaic-kpi-stat-value">{{ fmtKpiPercent(kpis.pm_compliance.percentage) }}</strong>
                <small class="dashboard-mosaic-kpi-stat-caption">{{ kpis.pm_compliance.compliant }} / {{ kpis.pm_compliance.total }} on time</small>
              </div>
              <div class="dashboard-mosaic-kpi-stat">
                <span class="dashboard-mosaic-kpi-stat-label">Avg MR</span>
                <strong class="dashboard-mosaic-kpi-stat-value">{{ fmtKpiHours(kpis.avg_mr_duration.hours) }}</strong>
                <small class="dashboard-mosaic-kpi-stat-caption">To resolve</small>
              </div>
              <div class="dashboard-mosaic-kpi-stat">
                <span class="dashboard-mosaic-kpi-stat-label">Avg WO</span>
                <strong class="dashboard-mosaic-kpi-stat-value">{{ fmtKpiHours(kpis.avg_wo_duration.hours) }}</strong>
                <small class="dashboard-mosaic-kpi-stat-caption">To close</small>
              </div>
            </div>
          </div>

          <!-- Asset Health & Availability (live — GET /dashboard/kpis asset_health) -->
          <div class="data-card dashboard-mosaic-kpi-panel">
            <div class="data-card-header">
              <h2 class="data-card-title">Asset Health &amp; Availability</h2>
              <span class="dashboard-mosaic-kpi-badge">Current</span>
            </div>
            <div class="data-card-content dashboard-mosaic-kpi-stats">
              <div class="dashboard-mosaic-kpi-stat">
                <span class="dashboard-mosaic-kpi-stat-label">Availability</span>
                <strong class="dashboard-mosaic-kpi-stat-value">{{ fmtKpiPercent(kpis.asset_health.availability.percentage) }}</strong>
                <small class="dashboard-mosaic-kpi-stat-caption">{{ kpis.asset_health.by_status.inactive }} inactive</small>
              </div>
              <div class="dashboard-mosaic-kpi-stat">
                <span class="dashboard-mosaic-kpi-stat-label">Down</span>
                <strong class="dashboard-mosaic-kpi-stat-value">{{ kpis.asset_health.by_status.down }}</strong>
                <small class="dashboard-mosaic-kpi-stat-caption">Out of service</small>
              </div>
              <div class="dashboard-mosaic-kpi-stat">
                <span class="dashboard-mosaic-kpi-stat-label">Under maintenance</span>
                <strong class="dashboard-mosaic-kpi-stat-value">{{ kpis.asset_health.by_status.under_maintenance }}</strong>
                <small class="dashboard-mosaic-kpi-stat-caption">In workshop</small>
              </div>
            </div>
          </div>

          <!-- Workforce & Backlog (live — GET /dashboard/kpis workforce) -->
          <div class="data-card dashboard-mosaic-kpi-panel">
            <div class="data-card-header">
              <h2 class="data-card-title">Workforce &amp; Backlog</h2>
              <span class="dashboard-mosaic-kpi-badge">{{ windowDays }}-day pulse</span>
            </div>
            <div class="data-card-content dashboard-mosaic-kpi-stats">
              <div class="dashboard-mosaic-kpi-stat">
                <span class="dashboard-mosaic-kpi-stat-label">WO Backlog</span>
                <strong class="dashboard-mosaic-kpi-stat-value">{{ kpis.workforce.wo_backlog.total }}</strong>
                <small class="dashboard-mosaic-kpi-stat-caption">{{ fmtKpiPercent(kpis.workforce.wo_backlog.trend_pct) }} vs prior</small>
              </div>
              <div class="dashboard-mosaic-kpi-stat">
                <span class="dashboard-mosaic-kpi-stat-label">Completion rate</span>
                <strong class="dashboard-mosaic-kpi-stat-value">{{ fmtKpiPercent(kpis.workforce.completion_rate.percentage) }}</strong>
                <small class="dashboard-mosaic-kpi-stat-caption">{{ kpis.workforce.completion_rate.closed }} / {{ kpis.workforce.completion_rate.created }} closed</small>
              </div>
              <div class="dashboard-mosaic-kpi-stat">
                <span class="dashboard-mosaic-kpi-stat-label">Avg turnaround</span>
                <strong class="dashboard-mosaic-kpi-stat-value">{{ fmtKpiHours(kpis.avg_wo_duration.hours) }}</strong>
                <small class="dashboard-mosaic-kpi-stat-caption">Created to close</small>
              </div>
            </div>
          </div>
        </section>

        <!-- Workboard + Activity -->
        <div v-if="dashError && !dashData" class="error-state" role="alert">{{ dashError }}</div>

        <section
          v-else-if="dashData && (hasActionRequired || showRecentlyClosed || relocated.length > 0)"
          class="dashboard-mosaic-workboard"
          aria-label="Work items and recent activity"
        >
          <!-- Active Workboard -->
          <div v-if="hasActionRequired" class="data-card dashboard-mosaic-work-card">
            <div class="data-card-header">
              <h2 class="data-card-title">Active Workboard</h2>
              <span class="dashboard-mosaic-card-subtitle">What needs attention</span>
            </div>
            <div class="data-card-content dashboard-mosaic-queues">
              <div v-if="showPendingMr" class="dashboard-mosaic-queue">
                <div class="dashboard-mosaic-queue-heading">
                  <h3>Pending Maintenance Requests</h3>
                  <span class="dashboard-mosaic-queue-count">{{ dashData.summary.pending_maintenance_requests ?? 0 }}</span>
                </div>
                <div v-if="pendingMrItems.length === 0" class="dashboard-mosaic-empty">
                  No pending requests.
                </div>
                <div v-else class="dashboard-mosaic-rows">
                  <RouterLink
                    v-for="mr in pendingMrItems"
                    :key="mr.id"
                    :to="`/maintenance/requests/${mr.id}`"
                    class="dashboard-mosaic-row dashboard-mosaic-row--mr"
                  >
                    <span class="dashboard-mosaic-row-main">
                      <strong>{{ mr.number }} — {{ mr.asset.name }}</strong>
                      <small>{{ mr.created_by?.name ?? '—' }} · {{ fmtDate(mr.created_at) }}</small>
                    </span>
                    <span :class="priorityClass(mr.priority)">{{ priorityLabel(mr.priority) }}</span>
                  </RouterLink>
                </div>
              </div>

              <div v-if="showOpenWo" class="dashboard-mosaic-queue">
                <div class="dashboard-mosaic-queue-heading">
                  <h3>Open Work Orders</h3>
                  <span class="dashboard-mosaic-queue-count">{{ dashData.summary.open_work_orders ?? 0 }}</span>
                </div>
                <div v-if="openWoItems.length === 0" class="dashboard-mosaic-empty">
                  No open work orders.
                </div>
                <div v-else class="dashboard-mosaic-rows">
                  <RouterLink
                    v-for="wo in openWoItems"
                    :key="wo.id"
                    :to="`/work-orders/${wo.id}`"
                    class="dashboard-mosaic-row dashboard-mosaic-row--wo"
                  >
                    <span class="dashboard-mosaic-row-main">
                      <strong>{{ wo.number }} — {{ wo.asset.name }}</strong>
                      <small>{{ wo.assigned_to?.name ?? 'Unassigned' }}</small>
                    </span>
                    <span :class="woStatusClass(wo.status)">{{ woStatusLabel(wo.status) }}</span>
                  </RouterLink>
                </div>
              </div>

              <div v-if="showOverduePm" class="dashboard-mosaic-queue">
                <div class="dashboard-mosaic-queue-heading">
                  <h3>Overdue PM Assignments</h3>
                  <span class="dashboard-mosaic-queue-count">{{ dashData.summary.overdue_pm_assignments ?? 0 }}</span>
                </div>
                <div v-if="overduePmItems.length === 0" class="dashboard-mosaic-empty">
                  No overdue PM assignments.
                </div>
                <div v-else class="dashboard-mosaic-rows">
                  <RouterLink
                    v-for="assignment in overduePmItems"
                    :key="assignment.id"
                    :to="`/assets/${assignment.asset?.id}`"
                    class="dashboard-mosaic-row dashboard-mosaic-row--pm"
                  >
                    <span class="dashboard-mosaic-row-main">
                      <strong>{{ assignment.asset?.name }}</strong>
                      <small>{{ assignment.rule.name }} · Due {{ pmDueLabel(assignment) }}</small>
                    </span>
                    <span :class="pmStatusClass(assignment.pm_status)">{{ pmStatusLabel(assignment.pm_status) }}</span>
                  </RouterLink>
                </div>
              </div>
            </div>
          </div>

          <!-- Recent Activity -->
          <div class="data-card dashboard-mosaic-activity-card">
            <div class="data-card-header">
              <h2 class="data-card-title">Recent Activity</h2>
              <span class="dashboard-mosaic-card-subtitle">Latest movements</span>
            </div>
            <div class="data-card-content dashboard-mosaic-queues">
              <div v-if="kpis" class="dashboard-mosaic-queue">
                <div class="dashboard-mosaic-queue-heading">
                  <h3>Asset Relocations</h3>
                  <span class="dashboard-mosaic-queue-count">{{ relocated.length }}</span>
                </div>
                <div v-if="relocated.length === 0" class="dashboard-mosaic-empty">
                  No relocations in the last {{ windowDays }} days.
                </div>
                <div v-else class="dashboard-mosaic-rows">
                  <RouterLink
                    v-for="item in relocated"
                    :key="item.id"
                    :to="`/assets/${item.asset_id}`"
                    class="dashboard-mosaic-row dashboard-mosaic-row--activity"
                  >
                    <span class="dashboard-mosaic-row-main">
                      <strong>{{ item.asset.name }}</strong>
                      <small>{{ item.from_location?.name ?? '—' }} → {{ item.to_location?.name ?? '—' }}</small>
                    </span>
                    <small class="dashboard-mosaic-row-date">{{ fmtDateTime(item.effective_at) }}</small>
                  </RouterLink>
                </div>
              </div>

              <div v-if="showRecentlyClosed" class="dashboard-mosaic-queue">
                <div class="dashboard-mosaic-queue-heading">
                  <h3>Recently Closed</h3>
                  <span class="dashboard-mosaic-queue-count">{{ dashData.summary.recently_closed_work_orders ?? 0 }}</span>
                </div>
                <div v-if="closedWoItems.length === 0" class="dashboard-mosaic-empty">
                  No work orders closed in the last 30 days.
                </div>
                <div v-else class="dashboard-mosaic-rows">
                  <RouterLink
                    v-for="wo in closedWoItems"
                    :key="wo.id"
                    :to="`/work-orders/${wo.id}`"
                    class="dashboard-mosaic-row dashboard-mosaic-row--activity"
                  >
                    <span class="dashboard-mosaic-row-main">
                      <strong>{{ wo.number }} — {{ wo.asset.name }}</strong>
                      <small>{{ wo.assigned_to?.name ?? 'Unassigned' }}</small>
                    </span>
                    <small class="dashboard-mosaic-row-date">{{ fmtDate(wo.closed_at) }}</small>
                  </RouterLink>
                </div>
              </div>
            </div>
          </div>
        </section>
      </template>
    </div>
  </AppLayout>
</template>
