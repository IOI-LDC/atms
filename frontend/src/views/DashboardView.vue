<script setup lang="ts">
import AppLayout from '@/components/app/AppLayout.vue'
import KpiTile from '@/components/app/KpiTile.vue'
import { Button } from '@/components/ui/button'
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import {
  RefreshCw, ClipboardList, Wrench, CalendarClock,
  Activity, Timer, TriangleAlert, ShieldCheck, FileClock, ClipboardCheck,
  ChevronRight,
} from '@lucide/vue'
import { useDashboard } from '@/composables/useDashboard'
import { useDashboardKpis } from '@/composables/useDashboardKpis'
import { useQuickActions } from '@/composables/useQuickActions'
import {
  woStatusClass, woStatusLabel,
  priorityClass, priorityLabel, pmStatusClass, pmStatusLabel,
  pmDueLabel, fmtDate, fmtDateTime, fmtKpiDays, fmtKpiHours, fmtKpiPercent,
} from '@/lib/displayHelpers'

const router = useRouter()

const {
  data: dashData, loading: dashLoading, error: dashError, reload: reloadDash,
  showPendingMr, showOpenWo, showOverduePm, showRecentlyClosed,
  pendingMrItems, openWoItems, overduePmItems, closedWoItems,
  hasActionRequired,
} = useDashboard()

const {
  loading: kpisLoading, error: kpisError, reload: reloadKpis,
  kpis, relocated, windowDays, windowLabel, windowRange,
} = useDashboardKpis()

const { actions } = useQuickActions()

// Single loader until both parallel calls have first resolved; afterwards each
// section renders progressively and a manual refresh keeps stale data visible.
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
    <div class="page-section">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Maintenance Control Center</h1>
          <p class="page-subtitle">Central hub for scheduling, operations status, and asset reliability statistics.</p>
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

      <div v-if="initialLoading" class="loading-state">Loading dashboard…</div>

      <template v-else>
        <!-- Split Command-Center Layout (Workspace Left, Command Panel Right) -->
        <div class="dashboard-split-layout">
          <!-- Left Panel: Main Workspace -->
          <div class="dashboard-workspace-main">
            <!-- ── Analytics & Reliability KPIs Row ────────────────── -->
            <div v-if="kpisError && !kpis" class="error-state" role="alert">{{ kpisError }}</div>

            <section v-else-if="kpis" class="kpi-section">
              <!-- Reliability KPIs group -->
              <div class="kpi-group">
                <p class="kpi-group-label kpi-group-label-reliability">Reliability Performance</p>
                <div class="kpi-grid-3 kpi-accent-reliability">
                  <KpiTile :icon="Activity" title="MTBF" :value="fmtKpiDays(kpis.mtbf.days)" subtitle="Mean time between failures" />
                  <KpiTile :icon="Timer" title="MTTR" :value="fmtKpiHours(kpis.mttr.hours)" subtitle="Mean time to repair" />
                  <KpiTile :icon="TriangleAlert" title="Failure Rate" :value="kpis.failure_rate.failures" :subtitle="`${kpis.failure_rate.per_day.toFixed(3)}/day`" />
                </div>
              </div>

              <!-- Process Performance KPIs group -->
              <div class="kpi-group">
                <p class="kpi-group-label kpi-group-label-process">Process Efficiency</p>
                <div class="kpi-grid-3 kpi-accent-process">
                  <KpiTile :icon="ShieldCheck" title="PM Compliance" :value="fmtKpiPercent(kpis.pm_compliance.percentage)" :subtitle="`${kpis.pm_compliance.compliant} / ${kpis.pm_compliance.total} on time`" />
                  <KpiTile :icon="FileClock" title="Avg MR Duration" :value="fmtKpiHours(kpis.avg_mr_duration.hours)" subtitle="Created → resolved" />
                  <KpiTile :icon="ClipboardCheck" title="Avg WO Duration" :value="fmtKpiHours(kpis.avg_wo_duration.hours)" subtitle="Created → closed" />
                </div>
              </div>
            </section>

            <!-- ── Detailed Data Lists Grid ─────────────────────────── -->
            <div v-if="dashError && !dashData" class="error-state" role="alert">{{ dashError }}</div>

            <div
              v-else-if="dashData && (hasActionRequired || showRecentlyClosed || relocated.length > 0)"
              :class="[hasActionRequired ? 'dashboard-grid-redesigned' : 'dashboard-grid-stacked']"
            >
              <!-- Column 1: Action Required -->
              <div v-if="hasActionRequired" class="dashboard-column-action">
                <!-- Pending Maintenance Requests -->
                <div v-if="showPendingMr" class="data-card">
                  <div class="data-card-header">
                    <h2 class="data-card-title">Pending Maintenance Requests</h2>
                    <span class="data-card-count">{{ dashData.summary.pending_maintenance_requests ?? 0 }}</span>
                  </div>
                  <div class="data-card-content">
                    <div v-if="pendingMrItems.length === 0" class="widget-empty">No pending requests.</div>
                    <div v-else class="widget-list">
                      <RouterLink
                        v-for="mr in pendingMrItems"
                        :key="mr.id"
                        :to="`/maintenance/requests/${mr.id}`"
                        :class="['widget-list-item', 'widget-item-pending-mr']"
                      >
                        <span class="widget-item-main">
                          <span class="widget-item-primary">{{ mr.number }} — {{ mr.asset.name }}</span>
                          <span class="widget-item-secondary">{{ mr.created_by?.name ?? '—' }} · {{ fmtDate(mr.created_at) }}</span>
                        </span>
                        <span class="widget-item-meta">
                          <span :class="priorityClass(mr.priority)">{{ priorityLabel(mr.priority) }}</span>
                        </span>
                      </RouterLink>
                    </div>
                  </div>
                </div>

                <!-- Open Work Orders -->
                <div v-if="showOpenWo" class="data-card">
                  <div class="data-card-header">
                    <h2 class="data-card-title">Open Work Orders</h2>
                    <span class="data-card-count">{{ dashData.summary.open_work_orders ?? 0 }}</span>
                  </div>
                  <div class="data-card-content">
                    <div v-if="openWoItems.length === 0" class="widget-empty">No open work orders.</div>
                    <div v-else class="widget-list">
                      <RouterLink
                        v-for="wo in openWoItems"
                        :key="wo.id"
                        :to="`/work-orders/${wo.id}`"
                        :class="['widget-list-item', 'widget-item-open-wo']"
                      >
                        <span class="widget-item-main">
                          <span class="widget-item-primary">{{ wo.number }} — {{ wo.asset.name }}</span>
                          <span class="widget-item-secondary">{{ wo.assigned_to?.name ?? 'Unassigned' }}</span>
                        </span>
                        <span class="widget-item-meta">
                          <span :class="woStatusClass(wo.status)">{{ woStatusLabel(wo.status) }}</span>
                        </span>
                      </RouterLink>
                    </div>
                  </div>
                </div>

                <!-- Overdue PM Assignments -->
                <div v-if="showOverduePm" class="data-card">
                  <div class="data-card-header">
                    <h2 class="data-card-title">Overdue PM Assignments</h2>
                    <span class="data-card-count">{{ dashData.summary.overdue_pm_assignments ?? 0 }}</span>
                  </div>
                  <div class="data-card-content">
                    <div v-if="overduePmItems.length === 0" class="widget-empty">No overdue PM assignments.</div>
                    <div v-else class="widget-list">
                      <RouterLink
                        v-for="a in overduePmItems"
                        :key="a.id"
                        :to="`/assets/${a.asset?.id}`"
                        :class="['widget-list-item', 'widget-item-overdue-pm']"
                      >
                        <span class="widget-item-main">
                          <span class="widget-item-primary">{{ a.asset?.name }}</span>
                          <span class="widget-item-secondary">{{ a.rule.name }} · Due {{ pmDueLabel(a) }}</span>
                        </span>
                        <span class="widget-item-meta">
                          <span :class="pmStatusClass(a.pm_status)">{{ pmStatusLabel(a.pm_status) }}</span>
                        </span>
                      </RouterLink>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Column 2: Activity & Tracking -->
              <div class="dashboard-column-tracking">
                <!-- Recently Relocated Assets -->
                <div v-if="kpis" class="data-card">
                  <div class="data-card-header">
                    <h2 class="data-card-title">Recently Relocated Assets</h2>
                    <span class="data-card-count">{{ relocated.length }}</span>
                  </div>
                  <div class="data-card-content">
                    <div v-if="relocated.length === 0" class="widget-empty">
                      No asset relocations in the last {{ windowDays }} days.
                    </div>
                    <div v-else class="widget-list">
                      <RouterLink
                        v-for="item in relocated"
                        :key="item.id"
                        :to="`/assets/${item.asset_id}`"
                        :class="['widget-list-item', 'widget-item-relocated']"
                      >
                        <span class="widget-item-main">
                          <span class="widget-item-primary">{{ item.asset.name }}</span>
                          <span class="widget-item-secondary">
                            {{ item.from_location?.name ?? '—' }}
                            <span class="relocation-path-arrow">→</span>
                            {{ item.to_location?.name ?? '—' }}
                          </span>
                        </span>
                        <span class="widget-item-meta">
                          <span class="widget-item-secondary">{{ fmtDateTime(item.effective_at) }}</span>
                        </span>
                      </RouterLink>
                    </div>
                  </div>
                </div>

                <!-- Recently Closed Work Orders -->
                <div v-if="showRecentlyClosed" class="data-card">
                  <div class="data-card-header">
                    <h2 class="data-card-title">Recently Closed Work Orders</h2>
                    <span class="data-card-count">{{ dashData.summary.recently_closed_work_orders ?? 0 }}</span>
                  </div>
                  <div class="data-card-content">
                    <div v-if="closedWoItems.length === 0" class="widget-empty">No work orders closed in the last 30 days.</div>
                    <div v-else class="widget-list">
                      <RouterLink
                        v-for="wo in closedWoItems"
                        :key="wo.id"
                        :to="`/work-orders/${wo.id}`"
                        :class="['widget-list-item', 'widget-item-closed-wo']"
                      >
                        <span class="widget-item-main">
                          <span class="widget-item-primary">{{ wo.number }} — {{ wo.asset.name }}</span>
                          <span class="widget-item-secondary">{{ wo.assigned_to?.name ?? 'Unassigned' }}</span>
                        </span>
                        <span class="widget-item-meta">
                          <span class="widget-item-secondary">Closed {{ fmtDate(wo.closed_at) }}</span>
                        </span>
                      </RouterLink>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Right Panel: Sidebar Command Center -->
          <aside class="dashboard-sidebar-command">
            <!-- CTA Action Section -->
            <div v-if="actions.length > 0" class="dashboard-sidebar-section">
              <h3 class="dashboard-sidebar-title">Actions Hub</h3>
              <div class="dashboard-sidebar-section">
                <button
                  v-for="a in actions"
                  :key="a.label"
                  class="dashboard-cta-btn"
                  @click="router.push(a.to)"
                >
                  <span class="dashboard-cta-left">
                    <component :is="a.icon" />
                    {{ a.label }}
                  </span>
                  <ChevronRight class="dashboard-cta-chevron" />
                </button>
              </div>
            </div>

            <!-- Live Status indicators (Operational KPIs stacked vertically) -->
            <div v-if="hasActionRequired" class="dashboard-sidebar-section">
              <h3 class="dashboard-sidebar-title">Operational Status</h3>
              <div class="dashboard-sidebar-section kpi-accent-operational">
                <KpiTile
                  v-if="showPendingMr"
                  :icon="ClipboardList"
                  title="Pending MR"
                  :value="dashData?.summary.pending_maintenance_requests ?? 0"
                  to="/maintenance?tab=pending-approval"
                />
                <KpiTile
                  v-if="showOpenWo"
                  :icon="Wrench"
                  title="Open Work Orders"
                  :value="dashData?.summary.open_work_orders ?? 0"
                  to="/work-orders?tab=open"
                />
                <KpiTile
                  v-if="showOverduePm"
                  :icon="CalendarClock"
                  title="Overdue PM Rules"
                  :value="dashData?.summary.overdue_pm_assignments ?? 0"
                  to="/admin/pm-rules"
                />
              </div>
            </div>
          </aside>
        </div>
      </template>
    </div>
  </AppLayout>
</template>
