<script setup lang="ts">
import { computed, onMounted } from 'vue'
import AppLayout from '@/components/app/AppLayout.vue'
import { Progress } from '@/components/ui/progress'
import { SegmentedBar } from '@/components/ui/segmented-bar'
import { Button } from '@/components/ui/button'
import { useDashboard } from '@/composables/useDashboard'
import { useDashboardKpis } from '@/composables/useDashboardKpis'
import { useAssetDistributionReport } from '@/composables/useAssetDistributionReport'
import { useQuickActions } from '@/composables/useQuickActions'
import { fmtDate, fmtKpiDays, fmtKpiHours, fmtKpiPercent } from '@/lib/displayHelpers'

const {
  data: dashData,
  loading: dashLoading,
  error: dashError,
  showPendingMr,
  showOpenWo,
  showOverduePm,
  pendingMrItems,
  openWoItems,
} = useDashboard()

const {
  loading: kpiLoading,
  error: kpiError,
  kpis,
  relocated,
  windowLabel,
  utilisation,
  utilisationSegments,
  utilisationBasis,
  readinessMetrics,
  operationalStatusRows,
  bookedRow,
} = useDashboardKpis()

const { data: locationData, load: loadLocations } = useAssetDistributionReport()

// Role-gated: a Requester sees only New MR, since Update Location is
// Admin/Manager/Logistics. The composable owns that gating.
const { actions: quickActions } = useQuickActions(['New MR', 'Update Location'])

onMounted(() => loadLocations({ group_by: ['location'] }))

const initialLoading = computed(() => dashLoading.value && kpiLoading.value)

const failureCount = computed(() => kpis.value?.asset_health.by_status.failure ?? 0)
const overduePmCount = computed(() => dashData.value?.summary.overdue_pm_assignments ?? 0)
const pendingMrCount = computed(() => dashData.value?.summary.pending_maintenance_requests ?? 0)

/** Newest first, capped at five so both closing columns hold the same height. */
const workboardItems = computed(() =>
  [
    ...pendingMrItems.value.map((mr) => ({
      id: `mr-${mr.id}`,
      tag: 'Request',
      to: `/maintenance/requests/${mr.id}`,
      main: `${mr.number} — ${mr.asset?.name ?? 'Unknown asset'}`,
      when: mr.created_at,
    })),
    ...openWoItems.value.map((wo) => ({
      id: `wo-${wo.id}`,
      tag: 'Work order',
      to: `/work-orders/${wo.id}`,
      main: `${wo.number} — ${wo.asset?.name ?? 'Unknown asset'}`,
      when: wo.created_at,
    })),
  ]
    .sort((a, b) => (a.when < b.when ? 1 : -1))
    .slice(0, 5),
)

const recentMoves = computed(() => relocated.value.slice(0, 5))

const topLocations = computed(() => (locationData.value?.items ?? []).slice(0, 6))
const locationMax = computed(() =>
  Math.max(1, ...topLocations.value.map((row) => row.asset_count ?? 0)),
)
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Dashboard</h1>
          <p class="page-subtitle">
            Current state of the asset register and maintenance programme.
          </p>
        </div>
        <div v-if="quickActions.length > 0" class="page-actions">
          <Button
            v-for="action in quickActions"
            :key="action.label"
            as-child
            :variant="action.label === 'New MR' ? 'default' : 'outline'"
          >
            <RouterLink :to="action.to">
              <component :is="action.icon" />
              {{ action.label }}
            </RouterLink>
          </Button>
        </div>
      </div>

      <div v-if="initialLoading" class="loading-state">Loading dashboard…</div>

      <template v-else>
        <div v-if="dashError && kpiError" class="error-state" role="alert">{{ kpiError }}</div>

        <!-- ── Needs attention ──────────────────────────────────────── -->
        <div class="dash-grid">
          <div class="dash-card dash-attn dash-span-4">
            <span class="dash-dot" :class="{ 'dash-dot-critical': failureCount > 0 }"></span>
            <span class="dash-attn-body">
              <span class="dash-attn-value">{{ failureCount }}</span>
              <span class="dash-attn-label">
                {{ failureCount === 1 ? 'asset down' : 'assets down' }}
              </span>
            </span>
            <RouterLink class="dash-attn-link" to="/assets">View</RouterLink>
          </div>

          <div v-if="showOverduePm" class="dash-card dash-attn dash-span-4">
            <span class="dash-dot" :class="{ 'dash-dot-warning': overduePmCount > 0 }"></span>
            <span class="dash-attn-body">
              <span class="dash-attn-value">{{ overduePmCount }}</span>
              <span class="dash-attn-label">PM overdue</span>
            </span>
            <RouterLink class="dash-attn-link" to="/reports/overdue-pm">View</RouterLink>
          </div>

          <div v-if="showPendingMr" class="dash-card dash-attn dash-span-4">
            <span class="dash-dot" :class="{ 'dash-dot-warning': pendingMrCount > 0 }"></span>
            <span class="dash-attn-body">
              <span class="dash-attn-value">{{ pendingMrCount }}</span>
              <span class="dash-attn-label">
                {{ pendingMrCount === 1 ? 'request pending review' : 'requests pending review' }}
              </span>
            </span>
            <RouterLink class="dash-attn-link" to="/maintenance">Review</RouterLink>
          </div>
        </div>

        <!-- ── Utilisation ──────────────────────────────────────────── -->
        <div class="dash-grid">
          <div class="dash-card dash-span-12">
            <div class="dash-card-head">
              <p class="dash-label">Utilisation</p>
              <span class="dash-note">
                Deployed = rig &amp; well site · excludes assets down or under maintenance
              </span>
            </div>
            <div class="dash-util">
              <div class="dash-util-figure">
                <span class="dash-util-value">{{ fmtKpiPercent(utilisation?.percentage) }}</span>
                <span class="dash-util-basis">{{ utilisationBasis }}</span>
              </div>
              <div class="dash-util-right">
                <SegmentedBar
                  :segments="utilisationSegments"
                  ariaLabel="Assets by deployment state"
                />
                <div class="dash-legend">
                  <span
                    v-for="segment in utilisationSegments"
                    :key="segment.key"
                    class="dash-legend-item"
                  >
                    <span class="dash-swatch" :class="`dash-swatch-${segment.key}`"></span>
                    {{ segment.label }} <strong>{{ segment.count }}</strong>
                  </span>
                </div>
                <div class="dash-legend">
                  <span class="dash-legend-item">
                    Committed for upcoming jobs
                    <strong>{{ utilisation?.booked ?? 0 }}</strong> of
                    {{ utilisation?.total ?? 0 }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Reliability | Process performance ─────────────────────── -->
        <div class="dash-grid">
          <div class="dash-card dash-span-6">
            <div class="dash-card-head">
              <p class="dash-label">Equipment reliability</p>
              <span class="dash-note">{{ windowLabel }}</span>
            </div>
            <div class="dash-metric-grid">
              <div class="dash-metric">
                <p class="dash-label">MTBF</p>
                <span v-if="kpis?.mtbf.days != null" class="dash-metric-value">
                  {{ fmtKpiDays(kpis.mtbf.days) }}
                </span>
                <span v-else class="dash-empty">No failures yet</span>
              </div>
              <div class="dash-metric">
                <p class="dash-label">MTTR</p>
                <span v-if="kpis?.mttr.hours != null" class="dash-metric-value">
                  {{ fmtKpiHours(kpis.mttr.hours) }}
                </span>
                <span v-else class="dash-empty">No repairs closed</span>
              </div>
              <div class="dash-metric">
                <p class="dash-label">Failures</p>
                <span v-if="kpis && kpis.failure_rate.failures > 0" class="dash-metric-value">
                  {{ kpis.failure_rate.failures }}
                  <span class="dash-metric-unit">recorded</span>
                </span>
                <span v-else class="dash-empty">None recorded</span>
              </div>
            </div>
          </div>

          <div class="dash-card dash-span-6">
            <div class="dash-card-head">
              <p class="dash-label">Process performance</p>
              <span class="dash-note">{{ windowLabel }}</span>
            </div>
            <div class="dash-metric-grid">
              <div class="dash-metric">
                <p class="dash-label">PM compliance</p>
                <span v-if="kpis?.pm_compliance.percentage != null" class="dash-metric-value">
                  {{ fmtKpiPercent(kpis.pm_compliance.percentage) }}
                </span>
                <span v-else class="dash-empty">None due yet</span>
              </div>
              <div class="dash-metric">
                <p class="dash-label">Avg request</p>
                <span v-if="kpis?.avg_mr_duration.hours != null" class="dash-metric-value">
                  {{ fmtKpiHours(kpis.avg_mr_duration.hours) }}
                </span>
                <span v-else class="dash-empty">None resolved</span>
              </div>
              <div class="dash-metric">
                <p class="dash-label">Avg work order</p>
                <span v-if="kpis?.avg_wo_duration.hours != null" class="dash-metric-value">
                  {{ fmtKpiHours(kpis.avg_wo_duration.hours) }}
                </span>
                <span v-else class="dash-empty">None closed</span>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Asset status | By location ────────────────────────────── -->
        <div class="dash-grid">
          <div class="dash-card dash-span-6">
            <div class="dash-card-head">
              <p class="dash-label">Asset status</p>
              <span class="dash-note">{{ kpis?.asset_health.total ?? 0 }} assets in ATMS</span>
            </div>
            <div class="dash-status-list">
              <div v-for="row in operationalStatusRows" :key="row.key" class="dash-status-row">
                <span class="dash-status-name">
                  <span class="dash-dot" :class="`dash-dot-${row.tone}`"></span>
                  {{ row.label }}
                </span>
                <span class="dash-status-count">{{ row.count }}</span>
              </div>
            </div>
            <hr class="dash-divider" />
            <div v-if="bookedRow" class="dash-status-row">
              <span class="dash-status-name">
                <span class="dash-dot" :class="`dash-dot-${bookedRow.tone}`"></span>
                {{ bookedRow.label }}
              </span>
              <span class="dash-status-count">{{ bookedRow.count }}</span>
            </div>
          </div>

          <div class="dash-card dash-span-6">
            <div class="dash-card-head">
              <p class="dash-label">By location</p>
              <span class="dash-note">
                {{ locationData?.summary.total_groups ?? 0 }} locations
              </span>
            </div>
            <div v-if="topLocations.length === 0" class="dash-empty">
              No locations recorded yet.
            </div>
            <div v-else class="dash-loc-group">
              <div
                v-for="row in topLocations"
                :key="row.groups[0]?.key ?? 'unassigned'"
                class="dash-loc-row"
              >
                <span class="dash-loc-label">
                  <span class="dash-loc-name">{{ row.groups[0]?.label ?? 'Unassigned' }}</span>
                  <Progress :value="((row.asset_count ?? 0) / locationMax) * 100" />
                </span>
                <span class="dash-loc-count">{{ row.asset_count ?? 0 }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Programme readiness ───────────────────────────────────── -->
        <div class="dash-grid">
          <div v-for="metric in readinessMetrics" :key="metric.key" class="dash-card dash-span-4">
            <p class="dash-label">{{ metric.label }}</p>
            <div class="dash-ready-figure">
              <span class="dash-ready-value">{{ metric.covered }}</span>
              <span class="dash-ready-of">of {{ metric.total }} assets</span>
            </div>
            <Progress :value="metric.percentage ?? 0" variant="soon" />
          </div>
        </div>

        <!-- ── Active workboard | Recent moves ───────────────────────── -->
        <div class="dash-grid">
          <div v-if="showPendingMr || showOpenWo" class="dash-card dash-span-6">
            <div class="dash-card-head">
              <p class="dash-label">Active workboard</p>
              <span class="dash-note">Latest 5</span>
            </div>
            <div v-if="workboardItems.length === 0" class="dash-empty">Nothing needs action.</div>
            <div v-else class="dash-list">
              <RouterLink
                v-for="item in workboardItems"
                :key="item.id"
                :to="item.to"
                class="dash-row"
              >
                <span class="dash-tag">{{ item.tag }}</span>
                <span class="dash-row-main">{{ item.main }}</span>
                <span class="dash-row-when">{{ fmtDate(item.when) }}</span>
              </RouterLink>
            </div>
          </div>

          <div class="dash-card dash-span-6">
            <div class="dash-card-head">
              <p class="dash-label">Recent asset moves</p>
              <span class="dash-note">Latest 5</span>
            </div>
            <div v-if="recentMoves.length === 0" class="dash-empty">No moves recorded yet.</div>
            <div v-else class="dash-list">
              <div v-for="move in recentMoves" :key="move.id" class="dash-row">
                <span class="dash-tag">Move</span>
                <span class="dash-row-main">
                  <strong>{{ move.asset?.name ?? 'Unknown asset' }}</strong>
                  moved to {{ move.to_location?.name ?? 'Unknown' }}
                </span>
                <span class="dash-row-when">{{ fmtDate(move.effective_at) }}</span>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>
  </AppLayout>
</template>
